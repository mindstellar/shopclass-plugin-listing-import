<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Feeds: their field names mapped to ours, CSV and Shopclass RSS read into records, an XML
 * entity attack refused before parsing, and a listing that leaves its feed deactivated --
 * never deleted, and back when its record returns -- unless the feed looks broken.
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

use mindstellar\listingimport\Feed\FeedReader;
use mindstellar\listingimport\Feed\Mapping;
use mindstellar\listingimport\Import\Batch;
use mindstellar\listingimport\Import\Pull;
use mindstellar\listingimport\Import\Source;

$dir = sys_get_temp_dir() . '/li-feeds-' . getmypid() . '/';
@mkdir($dir);
/** Write a feed file and hand back its path. */
function feed(string $name, string $text): string
{
    file_put_contents($GLOBALS['dir'] . $name, $text);

    return $GLOBALS['dir'] . $name;
}

harness_section('field names');

$map = new Mapping("Headline = title\nTown = location.city\n  Cost = price.amount \nnot a line\n");
pin(
    'their names become ours, dotted names nest, unknown names stay',
    array('title' => 'Bike', 'location' => array('city' => 'Munich'), 'price' => array('amount' => '10', 'currency' => 'EUR'), 'colour' => 'red'),
    $map->apply(array('Headline' => 'Bike', 'town' => 'Munich', 'Cost' => '10', 'price.currency' => 'EUR', 'colour' => 'red', 'empty' => ''))
);
pin('several image addresses in one field become a list', array('images' => array('https://a.example/1.jpg', 'https://a.example/2.jpg')), $map->apply(array('images' => 'https://a.example/1.jpg | https://a.example/2.jpg')));

harness_section('CSV');

$csv = "\xEF\xBB\xBFid;Headline;description;category;price.amount;price.currency\n"
    . "C1;Blue bike;\"A bike; with lights\";bikes;\"1.234,50\";EUR\n"
    . ";;;;;\n"
    . "C2;Red car;A car.;cars;900;EUR\n";
$read = FeedReader::read(feed('a.csv', $csv), 'csv', "id = external_id\nHeadline = title");
pin('reads each row, with the separator found from the header', array('C1', 'C2'), array_column($read['records'], 'external_id'));
pin('a quoted cell keeps its separator', 'A bike; with lights', $read['records'][0]['description']);
pin('and nests dotted headers', array('amount' => '1.234,50', 'currency' => 'EUR'), $read['records'][0]['price']);
pin('a header-only file says so', 'The feed holds no records.', FeedReader::read(feed('b.csv', "a,b\n"), 'csv')['error']);

harness_section('Shopclass RSS');

$rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Other site</title>'
    . '<item><title><![CDATA[Blue bike]]></title><link>https://other.example/bike_i7</link>'
    . '<guid isPermaLink="true">https://other.example/bike_i7</guid>'
    . '<enclosure url="https://other.example/oc-content/uploads/0/7.jpg" type="image/jpeg" length="0"/>'
    . '<description><![CDATA[<a href="https://other.example/bike_i7" title="Blue bike" rel="nofollow"><img style="float:left;border:0px;" src="https://other.example/t.jpg" alt="Blue bike"/> </a>A good bike.]]></description>'
    . '<country><![CDATA[Germany]]></country><region><![CDATA[Bavaria]]></region><city><![CDATA[Munich]]></city><cityArea><![CDATA[]]></cityArea>'
    . '<category><![CDATA[Bikes]]></category><pubDate>Thu, 24 Sep 2026 10:00:00 +0000</pubDate></item>'
    . '<item><title>No category</title><link>https://other.example/x_i8</link><guid>https://other.example/x_i8</guid><description>Text</description></item>'
    . '</channel></rss>';
$read = FeedReader::read(feed('a.xml', $rss), 'shopclass-rss');
pin('each item is a record keyed by its guid', array('https://other.example/bike_i7', 'https://other.example/x_i8'), array_column($read['records'], 'external_id'));
pin('with its text, the thumbnail link core adds taken off', array('Blue bike', 'A good bike.'), array($read['records'][0]['title'], $read['records'][0]['description']));
pin('its place, category and photo', array(
    array('country' => 'Germany', 'region' => 'Bavaria', 'city' => 'Munich'),
    array('label' => 'Bikes'),
    array('https://other.example/oc-content/uploads/0/7.jpg'),
), array($read['records'][0]['location'], $read['records'][0]['category'], $read['records'][0]['images']));
pin('an item with no category leaves it to the source default', false, isset($read['records'][1]['category']));

$xxe = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title><guid>1</guid></item></channel></rss>';
pin('a feed declaring an entity is refused before parsing', 'The feed declares a DOCTYPE or an entity; refused.', FeedReader::read(feed('x.xml', $xxe), 'shopclass-rss')['error']);
$padded = '<?xml version="1.0"?>' . "\n<!--" . str_repeat(' ', 5000) . "-->\n" . '<!DOCTYPE rss [<!ENTITY x "boom">]><rss><channel><item><title>&x;</title><guid>1</guid></item></channel></rss>';
pin('and so is one that hides it past the first 4 KB', 'The feed declares a DOCTYPE or an entity; refused.', FeedReader::read(feed('p.xml', $padded), 'shopclass-rss')['error']);
pin('a file that is not XML says so', 'The feed is not XML.', FeedReader::read(feed('y.xml', 'not xml at all'), 'shopclass-rss')['error']);

harness_section('a feed across three fetches');

/** A feed of the given ids, as a JSON list. */
function feedOf(array $ids): string
{
    return json_encode(array_map(static fn ($id) => array('external_id' => $id, 'title' => 'Item ' . $id, 'description' => 'Text.', 'category' => 'bikes'), $ids));
}

$GLOBALS['__store']    = new MemoryStore();
$GLOBALS['__listings'] = new MemoryListings();
$GLOBALS['__jobs']     = array();
$images                = new FakeImages();
$batch                 = new Batch(fake_importer($images), $GLOBALS['__store'], $GLOBALS['__listings'], static function (string $type, array $payload) {
    $GLOBALS['__jobs'][] = array($type, $payload);
});
$pull   = new Pull($images, $batch, $GLOBALS['__store'], static function (string $type, array $payload) {
    $GLOBALS['__jobs'][] = array($type, $payload);
});
$source = new Source(3, 'Partner feed');
$source->url = 'https://partner.example/feed.json';
$GLOBALS['__store']->sources[3] = $source;
$drain = static function () use ($batch) {
    while ($job = array_shift($GLOBALS['__jobs'])) {
        $batch->work($job[1]);
    }
};
$fetch = static function (array $ids) use ($pull, $source, $images, $drain) {
    $GLOBALS['__store']->tick++;
    $images->bodies[$source->url] = feedOf($ids);
    $result = $pull->fetch($source);
    $drain();

    return $result;
};

$first = $fetch(array('A', 'B'));
pin('the first fetch queues and imports both', array(2, 2), array($first['records'], count($GLOBALS['__listings']->items)));
$itemB = $GLOBALS['__store']->map['3|B']['fk_i_item_id'];

$second = $fetch(array('A'));
$run    = $GLOBALS['__store']->run($second['run_id']);
pin('a record gone from the feed deactivates its listing', array(true, 1), array(isset($GLOBALS['__listings']->inactive[$itemB]), $run['i_retired']));
pin('it is not deleted', true, isset($GLOBALS['__listings']->items[$itemB]));

$third = $fetch(array('A', 'B'));
pin('back in the feed, the same listing is back on the site', array(false, 1), array(isset($GLOBALS['__listings']->inactive[$itemB]), $GLOBALS['__store']->run($third['run_id'])['i_updated']));
pin('and still one listing per record', 2, count($GLOBALS['__listings']->items));

harness_section('when not to deactivate');

$source->policy['missing'] = Source::MISSING_KEEP;
$fetch(array('A'));
pin('a source set to keep leaves listings alone', false, isset($GLOBALS['__listings']->inactive[$itemB]));
$source->policy['missing'] = Source::MISSING_DEACTIVATE;

$GLOBALS['__store']->tick++;
$images->bodies[$source->url] = json_encode(array(
    array('external_id' => 'A', 'title' => 'x'),
    array('external_id' => 'C', 'title' => 'x'),
    array('external_id' => 'D', 'title' => 'x'),
));
$broken = $pull->fetch($source);
$drain();
pin('a feed where most records fail deactivates nothing', false, isset($GLOBALS['__listings']->inactive[$itemB]));
pin('and says why', 'Most records failed, so no listing was deactivated.', end($GLOBALS['__store']->logs)[3]);

$images->bodies[$source->url] = 404;
$failed = $pull->fetch($source);
pin('a feed that cannot be fetched starts no run and deactivates nothing', array(null, false), array($failed['run_id'], isset($GLOBALS['__listings']->inactive[$itemB])));
pin('its reason is kept on the source', 'Not fetched: The server answered 404.', $GLOBALS['__store']->scheduled[3]);

harness_section('the hourly schedule');

$GLOBALS['__jobs']            = array();
$GLOBALS['__store']->due      = array($source);
pin('queues one fetch job per due source', array(array(Pull::JOB, array('source_id' => 3))), ($pull->schedule() === 1) ? $GLOBALS['__jobs'] : null);

array_map('unlink', glob($dir . '*'));
@rmdir($dir);

exit(harness_result());
