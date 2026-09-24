<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * No request reaches the importer without a valid, enabled, unexpired key that holds the
 * endpoint's scope; a key is limited per minute; an address that keeps failing is shut out;
 * and a key can be rotated without a gap.
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

/** A POST to /listings with a token and a body. */
function post(string $token, string $body = '{}', string $type = 'application/json'): Request
{
    return new Request('POST', 'listings', $token === '' ? '' : 'Bearer ' . $token, '203.0.113.9', $type, $body);
}

/** 'ok' for any accepted import, so a limit test does not depend on created versus unchanged. */
function ok(int $status)
{
    return in_array($status, array(200, 201), true) ? 'ok' : $status;
}

$valid = '{"external_id":"A1","title":"Blue bike","description":"A bike.","category":"bikes"}';

harness_section('making a key');

$api  = fake_api();
$made = $GLOBALS['__keys']->create('Partner', array(KeyStore::SCOPE_WRITE, 'bogus:scope'));
$row  = $GLOBALS['__repo']->find($made['id']);
check('the token is the key id, a dot and a 64-character secret', (bool)preg_match('/^[0-9A-Za-z]{16}\.[0-9a-f]{64}$/', $made['token']));
check('the secret itself is not stored', strpos(json_encode($row), explode('.', $made['token'])[1]) === false);
pin('an unknown scope is dropped', KeyStore::SCOPE_WRITE, $row['s_scopes']);

harness_section('a valid key');

$r = $api->dispatch(post($made['token'], $valid));
pin('gets past the key check and imports', 201, $r->status);
pin('and its use is recorded', '203.0.113.9', $GLOBALS['__repo']->find($made['id'])['s_last_ip']);

harness_section('refused keys all answer the same');

$secretless = explode('.', $made['token'])[0] . '.' . str_repeat('0', 64);
foreach (array(
    'no token'          => '',
    'a malformed token' => 'not-a-token',
    'a wrong secret'    => $secretless,
    'an unknown key'    => 'ZZZZZZZZZZZZZZZZ.' . str_repeat('a', 64),
) as $label => $token) {
    $r = $api->dispatch(post($token, $valid));
    pin($label . ' is 401', array(401, 'unauthorized', 'Bearer'), array($r->status, $r->body['error']['code'] ?? null, $r->headers['WWW-Authenticate'] ?? null));
}

$api     = fake_api();
$revoked = $GLOBALS['__keys']->create('Old', array(KeyStore::SCOPE_WRITE));
$GLOBALS['__keys']->revoke($revoked['id']);
pin('a revoked key is 401', 401, $api->dispatch(post($revoked['token'], $valid))->status);

$expired = $GLOBALS['__keys']->create('Temp', array(KeyStore::SCOPE_WRITE), null, date('Y-m-d H:i:s', $GLOBALS['__now'] - 1));
pin('an expired key is 401', 401, $api->dispatch(post($expired['token'], $valid))->status);
$later = $GLOBALS['__keys']->create('Later', array(KeyStore::SCOPE_WRITE), null, date('Y-m-d H:i:s', $GLOBALS['__now'] + 60));
pin('a key that expires later still works', 'ok', ok($api->dispatch(post($later['token'], $valid))->status));

$readOnly = $GLOBALS['__keys']->create('Reader', array(KeyStore::SCOPE_RUNS));
$r        = $api->dispatch(post($readOnly['token'], $valid));
pin('a key without the scope is 403, not 401', array(403, 'forbidden'), array($r->status, $r->body['error']['code'] ?? null));

harness_section('limits');

$api  = fake_api(3);
$made = $GLOBALS['__keys']->create('Busy', array(KeyStore::SCOPE_WRITE));
$codes = array();
for ($i = 0; $i < 4; $i++) {
    $codes[] = ok($api->dispatch(post($made['token'], $valid))->status);
}
pin('three a minute are allowed, the fourth is 429', array('ok', 'ok', 'ok', 429), $codes);
$r = $api->dispatch(post($made['token'], $valid));
check('with a Retry-After of at most a minute', (int)($r->headers['Retry-After'] ?? 0) >= 1 && (int)$r->headers['Retry-After'] <= 60);
$GLOBALS['__now'] += 60;
pin('the next minute starts a new count', 'ok', ok($api->dispatch(post($made['token'], $valid))->status));

$api  = fake_api();
$good = $GLOBALS['__keys']->create('Good', array(KeyStore::SCOPE_WRITE));
for ($i = 0; $i < 20; $i++) {
    $api->dispatch(post('ZZZZZZZZZZZZZZZZ.' . str_repeat('a', 64), $valid));
}
$r = $api->dispatch(post($good['token'], $valid));
pin('after 20 failures an address is refused, even with a good key', array(429, 'rate_limited'), array($r->status, $r->body['error']['code'] ?? null));
pin('a 403 does not count as a failure', 20, $GLOBALS['__failures']->count);

harness_section('rotating a key');

$api     = fake_api();
$old     = $GLOBALS['__keys']->create('Partner', array(KeyStore::SCOPE_WRITE, KeyStore::SCOPE_DELETE));
$new     = $GLOBALS['__keys']->rotate($old['id']);
$newRow  = $GLOBALS['__repo']->find($new['id']);
check('gives a different token', $new['token'] !== $old['token']);
pin('with the same name and scopes', array('Partner', 'listings:write listings:delete'), array($newRow['s_name'], $newRow['s_scopes']));
pin('both work until the old one is revoked', array('ok', 'ok'), array(
    ok($api->dispatch(post($old['token'], $valid))->status),
    ok($api->dispatch(post($new['token'], $valid))->status),
));
$GLOBALS['__keys']->revoke($old['id']);
pin('then only the new one does', array(401, 'ok'), array(
    ok($api->dispatch(post($old['token'], $valid))->status),
    ok($api->dispatch(post($new['token'], $valid))->status),
));
pin('rotating a key that does not exist gives nothing', null, $GLOBALS['__keys']->rotate(999));

harness_section('the body');

$api  = fake_api();
$made = $GLOBALS['__keys']->create('Body', array(KeyStore::SCOPE_WRITE));
$r    = $api->dispatch(new Request('POST', 'listings', 'Bearer ' . $made['token'], '203.0.113.9', 'application/json', null));
pin('a body over 1 MB is 413', 413, $r->status);
pin('a body that is not JSON by type is 415', 415, $api->dispatch(post($made['token'], $valid, 'text/plain'))->status);
pin('the type may carry a charset', 'ok', ok($api->dispatch(post($made['token'], $valid, 'application/json; charset=utf-8'))->status));
pin('broken JSON is 400', array(400, 'invalid_json'), (static function () use ($api, $made) {
    $r = $api->dispatch(post($made['token'], '{"title":'));

    return array($r->status, $r->body['error']['code'] ?? null);
})());
pin('JSON that is not an object is 400', 400, $api->dispatch(post($made['token'], '"text"'))->status);
pin('JSON nested deeper than 32 is 400', 400, $api->dispatch(post($made['token'], str_repeat('[', 40) . str_repeat(']', 40)))->status);
$r = $api->dispatch(post($made['token'], '{"title":"No id"}'));
pin('a record with errors is 422, each named by its field', array(422, array('external_id', 'description', 'category')), array($r->status, array_keys($r->body['error']['fields'] ?? array())));

exit(harness_result());
