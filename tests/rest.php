<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * One listing by its external id: PUT creates or replaces it, GET says where it stands, DELETE
 * removes it. A key reaches only the source it is linked to.
 */

require __DIR__ . '/lib/harness.php';
harness_core();
harness_plugin();

use mindstellar\api\auth\Credential;
use mindstellar\listingimport\Api;
use mindstellar\listingimport\Import\Source;

$api    = fake_api();
$writer = fake_key(1, array(Api::WRITE));
$admin  = fake_key(1, array(Api::WRITE, Api::DELETE));
$put    = static fn (string $id, Credential $key, array $body) => api_call($api, 'putListing', $key, array('external_id' => $id), $body);
$get    = static fn (string $id, Credential $key) => api_call($api, 'showListing', $key, array('external_id' => $id));
$delete = static fn (string $id, Credential $key) => api_call($api, 'deleteListing', $key, array('external_id' => $id));
$record = array('title' => 'Blue bike', 'description' => 'A good bike.', 'category' => 'bikes');

harness_section('PUT');

$r = $put('P1', $writer, $record);
pin('creates the listing, the id taken from the address', array(201, 'created', 'P1'), array($r->status(), $r->body()['data']['status'] ?? null, $r->body()['data']['external_id'] ?? null));
$itemId = $r->body()['data']['item_id'];
$r      = $put('P1', $writer, array('title' => 'Red bike') + $record);
pin('the same id again replaces it', array(200, 'updated', $itemId), array($r->status(), $r->body()['data']['status'], $r->body()['data']['item_id']));
pin('the same record once more changes nothing', 'unchanged', $put('P1', $writer, array('title' => 'Red bike') + $record)->body()['data']['status']);
$r = $put('P1', $writer, array('external_id' => 'P2') + $record);
pin('a body naming another id is refused', array(422, 'validation_failed', '/external_id'), array($r->status(), api_code($r), $r->body()['errors'][0]['pointer'] ?? null));
pin('a body naming the same id is fine', 200, $put('P1', $writer, array('external_id' => 'P1') + $record)->status());
pin('one listing after all of that', 1, count($GLOBALS['__listings']->items));

harness_section('GET');

$r = $get('P1', $writer);
pin('says which listing and where', array(200, $itemId, 'active', 'https://site.example/item_i' . $itemId), array($r->status(), $r->body()['data']['item_id'], $r->body()['data']['status'], $r->body()['data']['url']));
pin('an id never imported is 404', array(404, 'not_found'), array($get('NOPE', $writer)->status(), api_code($get('NOPE', $writer))));

harness_section('DELETE');

$r = $delete('P1', $admin);
pin('the listing is deleted', array(200, true, false), array($r->status(), $r->body()['data']['deleted'], $GLOBALS['__listings']->exists($itemId)));
pin('and the id is forgotten', null, $GLOBALS['__store']->mapped(1, 'P1'));
pin('a second delete is 404', 404, $delete('P1', $admin)->status());
pin('a PUT after it makes a new listing', 'created', $put('P1', $writer, $record)->body()['data']['status']);

harness_section('a key stays on its own source');

$GLOBALS['__store']->sources[2] = new Source(2, 'Partner B');
$GLOBALS['__store']->keySources[7] = 2;
$other = fake_key(7);
pin('a key sees only its own source: P1 is not in source 2', 404, $get('P1', $other)->status());
pin('and imports into it', 'created', $put('P1', $other, $record)->body()['data']['status']);
pin('without touching the other source\'s listing', 2, count($GLOBALS['__listings']->items));
unset($GLOBALS['__store']->sources[2]);
$r = $put('P9', $other, $record);
pin('a key whose source is off or gone is refused, not moved to another source', array(409, 'conflict'), array($r->status(), api_code($r)));
$r = $get('P1', fake_key(99));
pin('so is a key linked to no source', array(409, 'conflict'), array($r->status(), api_code($r)));
pin('and nothing was imported by either', 2, count($GLOBALS['__listings']->items));

harness_section('what a refused record answers');

$r = api_call($api, 'createListing', $writer, array(), array('title' => 'No id'));
pin('a record with errors is 422, each field named by a pointer', array(422, 'not_imported', array('/external_id', '/description')), array($r->status(), api_code($r), array_column($r->body()['errors'] ?? array(), 'pointer')));
pin('the problem carries the code and the status', array(422, 'not_imported'), array($r->body()['status'], $r->body()['code']));
pin('broken JSON is core\'s 400', array(400, 'invalid_json'), (static function () use ($api, $writer) {
    $r = api_call($api, 'createListing', $writer, array(), null, '{"title":');

    return array($r->status(), api_code($r));
})());

exit(harness_result());
