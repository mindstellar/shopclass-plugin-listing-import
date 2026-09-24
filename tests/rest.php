<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * One listing by its external id: PUT creates or replaces it, GET says where it stands, DELETE
 * removes it. The OpenAPI file is served without a key and names every route the API has.
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

use mindstellar\listingimport\Api;
use mindstellar\listingimport\Auth\KeyStore;
use mindstellar\listingimport\Http\Request;

$api    = fake_api();
$writer = $GLOBALS['__keys']->create('Writer', array(KeyStore::SCOPE_WRITE));
$admin  = $GLOBALS['__keys']->create('Admin', array(KeyStore::SCOPE_WRITE, KeyStore::SCOPE_DELETE));
$call   = static fn (string $method, string $path, array $key, ?array $body = null) => $api->dispatch(new Request(
    $method,
    $path,
    'Bearer ' . $key['token'],
    '203.0.113.9',
    'application/json',
    $body === null ? '' : json_encode($body)
));
$record = array('title' => 'Blue bike', 'description' => 'A good bike.', 'category' => 'bikes');

harness_section('PUT');

$r = $call('PUT', 'listings/P1', $writer, $record);
pin('creates the listing, the id taken from the address', array(201, 'created', 'P1'), array($r->status, $r->body['data']['status'] ?? null, $r->body['data']['external_id'] ?? null));
$itemId = $r->body['data']['item_id'];
$r      = $call('PUT', 'listings/P1', $writer, array('title' => 'Red bike') + $record);
pin('the same id again replaces it', array(200, 'updated', $itemId), array($r->status, $r->body['data']['status'], $r->body['data']['item_id']));
pin('the same record once more changes nothing', 'unchanged', $call('PUT', 'listings/P1', $writer, array('title' => 'Red bike') + $record)->body['data']['status']);
$r = $call('PUT', 'listings/P1', $writer, array('external_id' => 'P2') + $record);
pin('a body naming another id is refused', array(422, 'id_mismatch'), array($r->status, $r->body['error']['code']));
pin('a body naming the same id is fine', 200, $call('PUT', 'listings/P1', $writer, array('external_id' => 'P1') + $record)->status);
pin('one listing after all of that', 1, count($GLOBALS['__listings']->items));

harness_section('GET');

$r = $call('GET', 'listings/P1', $writer);
pin('says which listing and where', array(200, $itemId, 'active', 'https://site.example/item_i' . $itemId), array($r->status, $r->body['data']['item_id'], $r->body['data']['status'], $r->body['data']['url']));
pin('an id never imported is 404', 404, $call('GET', 'listings/NOPE', $writer)->status);

harness_section('DELETE');

pin('needs the delete permission', 403, $call('DELETE', 'listings/P1', $writer)->status);
pin('and the listing is still there', true, $GLOBALS['__listings']->exists($itemId));
$r = $call('DELETE', 'listings/P1', $admin);
pin('with it, the listing is deleted', array(200, true, false), array($r->status, $r->body['data']['deleted'], $GLOBALS['__listings']->exists($itemId)));
pin('and the id is forgotten', null, $GLOBALS['__store']->mapped(1, 'P1'));
pin('a second delete is 404', 404, $call('DELETE', 'listings/P1', $admin)->status);
pin('a PUT after it makes a new listing', 'created', $call('PUT', 'listings/P1', $writer, $record)->body['data']['status']);

harness_section('the OpenAPI file');

$r    = $api->dispatch(new Request('GET', 'openapi.json', '', '203.0.113.9'));
pin('is served with no key', array(200, '3.1.0'), array($r->status, $r->body['openapi'] ?? null));
$documented = array();
foreach ($r->body['paths'] as $path => $methods) {
    foreach (array_keys($methods) as $method) {
        $documented[] = strtoupper($method) . ' ' . str_replace('{external_id}', '{ext}', ltrim($path, '/'));
    }
}
$routes = array_keys(Api::ROUTES);
sort($routes);
sort($documented);
pin('describes every route, and only those', $routes, $documented);
foreach (Api::ROUTES as $route => [$handler, $scope]) {
    [$method, $path] = explode(' ', $route, 2);
    $op = $r->body['paths']['/' . str_replace('{ext}', '{external_id}', $path)][strtolower($method)];
    pin($route . ' names its permission', $scope, $op['x-scope'] ?? null);
}

exit(harness_result());
