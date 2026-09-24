<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A batch becomes one core job per record, each carrying its record; the run counts them as
 * they finish and closes when all are counted. The run's page answers only the key's own
 * source. Record files come as a JSON list, {"records": [...]} or NDJSON.
 */

require __DIR__ . '/lib/harness.php';
define('ABS_PATH', '/tmp/');
spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\listingimport\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require __DIR__ . '/lib/fakes.php';

use mindstellar\listingimport\Auth\KeyStore;
use mindstellar\listingimport\Http\Request;
use mindstellar\listingimport\Import\Batch;
use mindstellar\listingimport\Import\Source;
use mindstellar\listingimport\Record\FileReader;

/** A record with an id. */
function rec(string $id, string $title = 'Blue bike'): array
{
    return array('external_id' => $id, 'title' => $title, 'description' => 'A bike.', 'category' => 'bikes');
}

/** Run every queued job, as `jobs:work` would. */
function drain(Batch $batch): void
{
    while ($job = array_shift($GLOBALS['__jobs'])) {
        $batch->work($job[1]);
    }
}

harness_section('queueing a batch');

$api   = fake_api();
$key   = $GLOBALS['__keys']->create('Partner', array(KeyStore::SCOPE_WRITE, KeyStore::SCOPE_RUNS), 1);
$body  = json_encode(array('records' => array(rec('B1'), rec('B2'), rec('B3', 'REFUSE'))));
$r     = $api->dispatch(new Request('POST', 'listings:batch', 'Bearer ' . $key['token'], '203.0.113.9', 'application/json', $body));
pin('answers 202 at once with the run', array(202, 'queued', 3), array($r->status, $r->body['data']['status'] ?? null, $r->body['data']['records'] ?? null));
$runId = $r->body['data']['run_id'];
pin('one core job per record', array_fill(0, 3, Batch::JOB), array_column($GLOBALS['__jobs'], 0));
pin('each job carries its record, not a row id', rec('B2'), $GLOBALS['__jobs'][1][1]['record']);
pin('nothing is imported yet', 0, count($GLOBALS['__listings']->items));

$show = static fn () => $api->dispatch(new Request('GET', 'runs/' . $runId, 'Bearer ' . $key['token'], '203.0.113.9'));
pin('the run says it is running', 'running', $show()->body['data']['status']);

harness_section('the jobs run');

$batch = new Batch(fake_importer(), $GLOBALS['__store'], $GLOBALS['__listings'], static function () {
});
$batch->work(array_shift($GLOBALS['__jobs'])[1]);
pin('after one job, one is counted and the run is still open', array(1, 'running'), array($show()->body['data']['counts']['created'], $show()->body['data']['status']));
drain($batch);
$data = $show()->body['data'];
pin('after all, every record is counted', array('created' => 2, 'updated' => 0, 'unchanged' => 0, 'retired' => 0, 'failed' => 1), $data['counts']);
pin('and the run is finished', 'finished', $data['status']);
pin('the failure is named by its record and field', array(array('external_id' => 'B3', 'errors' => array('listing' => 'Title too short.'))), $data['failures']);

harness_section('the run page');

$other = $GLOBALS['__keys']->create('Other', array(KeyStore::SCOPE_RUNS), 2);
$GLOBALS['__store']->sources[2] = new Source(2, 'Feed');
pin('a key of another source cannot see it', 404, $api->dispatch(new Request('GET', 'runs/' . $runId, 'Bearer ' . $other['token'], '203.0.113.9'))->status);
$writer = $GLOBALS['__keys']->create('Writer', array(KeyStore::SCOPE_WRITE), 1);
pin('a key without runs:read cannot either', 403, $api->dispatch(new Request('GET', 'runs/' . $runId, 'Bearer ' . $writer['token'], '203.0.113.9'))->status);
pin('an unknown run is 404', 404, $api->dispatch(new Request('GET', 'runs/999', 'Bearer ' . $key['token'], '203.0.113.9'))->status);
pin('a run id that is not a number is no endpoint', 404, $api->dispatch(new Request('GET', 'runs/abc', 'Bearer ' . $key['token'], '203.0.113.9'))->status);

harness_section('batches that are refused');

$post = static fn (string $body) => $api->dispatch(new Request('POST', 'listings:batch', 'Bearer ' . $key['token'], '203.0.113.9', 'application/json', $body))->status;
pin('no records list', 422, $post('{"items":[]}'));
pin('an empty list', 422, $post('{"records":[]}'));
pin('an object instead of a list', 422, $post('{"records":{"a":1}}'));
pin('more than 200 records', 413, $post(json_encode(array('records' => array_fill(0, 201, rec('X'))))));

harness_section('a record too large for a job');

$GLOBALS['__jobs'] = array();
$huge              = rec('BIG');
$huge['description'] = str_repeat('x', Batch::MAX_RECORD_BYTES);
$r = $api->dispatch(new Request('POST', 'listings:batch', 'Bearer ' . $key['token'], '203.0.113.9', 'application/json', json_encode(array('records' => array($huge, rec('SMALL'))))));
pin('is not queued; the other record is', 1, count($GLOBALS['__jobs']));
$run = $GLOBALS['__store']->run($r->body['data']['run_id']);
pin('and is counted as failed at once', 1, $run['i_failed']);

harness_section('the command line runs a batch at once');

$GLOBALS['__store']    = new MemoryStore();
$GLOBALS['__listings'] = new MemoryListings();
$batch                 = new Batch(fake_importer(), $GLOBALS['__store'], $GLOBALS['__listings']);
$runId                 = $batch->runNow(new Source(1, 'API'), array(rec('C1'), rec('C2')), 'cli');
$run                   = $GLOBALS['__store']->run($runId);
pin('every record, then the run is closed', array(2, 'cli', true), array($run['i_created'], $run['s_trigger'], $run['dt_finished'] !== null));
$runId = $batch->runNow(new Source(1, 'API'), array(rec('C3')), 'cli', true);
pin('a dry run counts but creates nothing', array(1, 2), array($GLOBALS['__store']->run($runId)['i_created'], count($GLOBALS['__listings']->items)));

harness_section('record files');

$dir   = sys_get_temp_dir() . '/li-files-' . getmypid() . '/';
@mkdir($dir);
$files = array(
    'list.json'   => json_encode(array(rec('F1'), rec('F2'))),
    'object.json' => json_encode(array('records' => array(rec('F1')))),
    'one.json'    => json_encode(rec('F1')),
    'wrapped.json' => json_encode(array('products' => array(rec('F1'), rec('F2')), 'total' => 2)),
    'two.json'    => json_encode(array('a' => array(rec('F1')), 'b' => array(rec('F2')))),
    'lines.ndjson' => "\xEF\xBB\xBF" . json_encode(rec('F1')) . "\n\n" . json_encode(rec('F2')) . "\r\n",
    'bad.ndjson'  => json_encode(rec('F1')) . "\n{not json\n",
    'empty.json'  => '',
);
foreach ($files as $name => $text) {
    file_put_contents($dir . $name, $text);
}
pin('a JSON list', array('F1', 'F2'), array_column(FileReader::read($dir . 'list.json')['records'], 'external_id'));
pin('an object with records', array('F1'), array_column(FileReader::read($dir . 'object.json')['records'], 'external_id'));
pin('an object wrapping one list, such as products', array('F1', 'F2'), array_column(FileReader::read($dir . 'wrapped.json')['records'], 'external_id'));
pin('an object wrapping two lists is not guessed', 'The file holds several lists; it must hold one list of records.', FileReader::read($dir . 'two.json')['error']);
pin('a single record', array('F1'), array_column(FileReader::read($dir . 'one.json')['records'], 'external_id'));
pin('NDJSON, with a byte-order mark and blank lines', array('F1', 'F2'), array_column(FileReader::read($dir . 'lines.ndjson')['records'], 'external_id'));
pin('a bad line is named', 'Line 2 is not a JSON record.', FileReader::read($dir . 'bad.ndjson')['error']);
pin('an empty file says so', 'The file holds no records.', FileReader::read($dir . 'empty.json')['error']);
pin('a missing file says so', 'Cannot read ' . $dir . 'none.json.', FileReader::read($dir . 'none.json')['error']);
array_map('unlink', glob($dir . '*'));
@rmdir($dir);

exit(harness_result());
