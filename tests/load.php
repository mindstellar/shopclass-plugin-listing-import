<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin loads without a database: it registers its install and uninstall steps, its
 * settings page, its API route and handler, and its migrations cannot collide with core's.
 */

require __DIR__ . '/lib/harness.php';

define('ABS_PATH', '/tmp/');
define('DB_TABLE_PREFIX', 'oc_');

$GLOBALS['__hooks']    = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__settings'] = array();
$GLOBALS['__install']  = null;

function osc_plugin_path($file)
{
    return 'listing-import/index.php';
}
function osc_register_plugin($path, $fn)
{
    $GLOBALS['__install'] = array($path, $fn);
}
function osc_add_hook($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
function osc_add_filter($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks']['filter:' . $name][] = $fn;
}
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['__settings'][$id] = $spec;
}
function osc_add_route_hook($id, $regexp, $url)
{
    $GLOBALS['__routes'][$id] = array($regexp, $url);
}
function osc_add_route($id, $regexp, $url, $file)
{
    $GLOBALS['__routes'][$id] = array($regexp, $url, $file);
}
function osc_plugin_folder($file)
{
    return 'listing-import/';
}
function __($text, $domain = '')
{
    return $text;
}

require __DIR__ . '/../index.php';

use mindstellar\listingimport\Api;
use mindstellar\listingimport\Plugin;
use mindstellar\listingimport\Schema;

harness_section('what the plugin registers');

pin('install runs Plugin::install', array('listing-import/index.php', array(Plugin::class, 'install')), $GLOBALS['__install']);
pin('uninstall runs Plugin::uninstall', array(array(Plugin::class, 'uninstall')), $GLOBALS['__hooks']['listing-import/index.php_uninstall'] ?? null);
check('the configure link has a handler', isset($GLOBALS['__hooks']['listing-import/index.php_configure']));
pin('an update migrates on init', array(array(Plugin::class, 'upgrade')), $GLOBALS['__hooks']['init'] ?? null);
pin('the settings page is declared under the plugin id', array('listing-import'), array_keys($GLOBALS['__settings']));
pin('its fields', array('rate_limit', 'retention_days'), array_column($GLOBALS['__settings']['listing-import']['groups'][0]['fields'], 'name'));
pin('one API route, under /api/v1/', array('api/v1/(.+)', 'api/v1/{path}'), $GLOBALS['__routes'][Plugin::ROUTE] ?? null);
pin('the keys screen is an admin route file', array('listing-import/keys', 'listing-import/keys', 'listing-import/admin/keys.php'), $GLOBALS['__routes'][\mindstellar\listingimport\Admin\Keys::ROUTE] ?? null);
pin('queued records have a job handler, and logs are pruned daily', array(
    array(array(Plugin::class, 'registerJobs')),
    array(array(Plugin::class, 'prune')),
), array($GLOBALS['__hooks']['register_jobs'] ?? null, $GLOBALS['__hooks']['cron_daily'] ?? null));
pin('the commands join oc-cli.php', array(array(\mindstellar\listingimport\Cli::class, 'commands')), $GLOBALS['__hooks']['filter:cli_commands'] ?? null);
pin('as import:run, import:status and import:key:create', array('import:run', 'import:status', 'import:key:create'), array_keys(\mindstellar\listingimport\Cli::commands(array())));
pin('old downloaded images are swept hourly', array(array(Plugin::class, 'sweep')), $GLOBALS['__hooks']['cron_hourly'] ?? null);
pin('a deleted listing is forgotten', array(array(\mindstellar\listingimport\Import\DbStore::class, 'forget')), $GLOBALS['__hooks']['before_delete_item'] ?? null);
pin('with a menu entry and a post handler', array(
    array(array(\mindstellar\listingimport\Admin\Keys::class, 'menu')),
    array(array(\mindstellar\listingimport\Admin\Keys::class, 'handlePost')),
), array($GLOBALS['__hooks']['admin_menu_init'] ?? null, $GLOBALS['__hooks']['init_admin'] ?? null));
pin('the route hook answers with Api::handle', array(array(Api::class, 'handle')), $GLOBALS['__hooks'][Plugin::ROUTE] ?? null);

harness_section('migrations');

$files = array_values(array_diff(scandir(Schema::dir()), array('.', '..')));
check('there is at least one', $files !== array());
pin(
    'every file carries the plugin prefix, so none can share a name with a core migration',
    array(),
    array_values(array_filter($files, static fn ($f) => strncmp($f, Schema::PREFIX, strlen(Schema::PREFIX)) !== 0))
);
pin('Schema::VERSION counts them', count($files), Schema::VERSION);

harness_section('the ping endpoint');

require __DIR__ . '/lib/fakes.php';
$api = fake_api();
$r   = $api->dispatch(new \mindstellar\listingimport\Http\Request('GET', 'ping'));
pin('answers 200 with no key', 200, $r->status);
pin('with the plugin and its version', array('data' => array('plugin' => 'listing-import', 'version' => Plugin::VERSION)), $r->body);
$r = $api->dispatch(new \mindstellar\listingimport\Http\Request('POST', 'ping'));
pin('another method is 405', 405, $r->status);
pin('and says which is allowed', 'GET', $r->headers['Allow'] ?? null);
$r = $api->dispatch(new \mindstellar\listingimport\Http\Request('GET', 'nothing/here'));
pin('an unknown path is 404', 404, $r->status);
pin('with the error shape every error has', 'not_found', $r->body['error']['code'] ?? null);

exit(harness_result());
