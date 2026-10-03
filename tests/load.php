<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin loads without a database: it registers its install and uninstall steps, its
 * settings page, its API routes and scopes, and its migrations cannot collide with core's.
 */

require __DIR__ . '/lib/harness.php';

define('ABS_PATH', '/tmp/');
define('DB_TABLE_PREFIX', 'oc_');

$GLOBALS['__hooks']    = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__api_routes'] = array();
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
function osc_api_register_route($method, $path, $spec)
{
    $GLOBALS['__api_routes'][strtoupper($method) . ' ' . $path] = $spec;
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
pin('its fields', array('retention_days'), array_column($GLOBALS['__settings']['listing-import']['groups'][0]['fields'], 'name'));
check('its API routes are all below ext/listing-import/', array_reduce(array_keys($GLOBALS['__api_routes']), static fn ($ok, $key) => $ok && str_contains($key, ' ext/listing-import/'), true));
check('and it adds no route hook of its own (the stub would have stopped the load)', !function_exists('osc_add_route_hook'));
pin('six endpoints', array(
    'DELETE ext/listing-import/listings/{external_id}',
    'GET ext/listing-import/listings/{external_id}',
    'GET ext/listing-import/runs/{id}',
    'POST ext/listing-import/listings',
    'POST ext/listing-import/listings:batch',
    'PUT ext/listing-import/listings/{external_id}',
), (static function () {
    $keys = array_keys($GLOBALS['__api_routes']);
    sort($keys);

    return $keys;
})());
pin('its scopes come through api_scopes', array(array_keys(Api::scopes())), array(array_keys(call_user_func($GLOBALS['__hooks']['filter:api_scopes'][0], array()))));
pin('queued records have a job handler, and logs are pruned daily', array(
    array(array(Plugin::class, 'registerJobs')),
    array(array(Plugin::class, 'prune')),
), array($GLOBALS['__hooks']['register_jobs'] ?? null, $GLOBALS['__hooks']['cron_daily'] ?? null));
pin('the commands join oc-cli.php', array(array(\mindstellar\listingimport\Cli::class, 'commands')), $GLOBALS['__hooks']['filter:cli_commands'] ?? null);
pin('as import:run and import:status; keys are core\'s api:key:create', array('import:run', 'import:status'), array_keys(\mindstellar\listingimport\Cli::commands(array())));
pin('each hour, old downloaded images are swept and due feeds are queued', array(array(Plugin::class, 'sweep'), array(Plugin::class, 'schedule')), $GLOBALS['__hooks']['cron_hourly'] ?? null);
pin('a deleted listing is forgotten', array(array(\mindstellar\listingimport\Import\DbStore::class, 'forget')), $GLOBALS['__hooks']['before_delete_item'] ?? null);
pin('the sources screens are admin route files', array(
    'listing-import/admin/sources.php', 'listing-import/admin/source.php', 'listing-import/admin/preview.php',
), array_map(static fn ($r) => $GLOBALS['__routes'][$r][2] ?? null, array('listing-import-sources', 'listing-import-sources-edit', 'listing-import-sources-preview')));
pin('one menu group, a post handler per screen group, and a declared header per screen', array(
    array(array(\mindstellar\listingimport\Admin\Menu::class, 'register')),
    array(
        array(\mindstellar\listingimport\Admin\Sources::class, 'handlePost'),
        array(\mindstellar\listingimport\Admin\Upload::class, 'handlePost'),
        array(\mindstellar\listingimport\Admin\Menu::class, 'pages'),
    ),
), array($GLOBALS['__hooks']['admin_menu_init'] ?? null, $GLOBALS['__hooks']['init_admin'] ?? null));
pin('the help and upload screens are admin route files', array('listing-import/admin/help.php', 'listing-import/admin/upload.php'), array($GLOBALS['__routes'][\mindstellar\listingimport\Admin\Menu::HELP_ROUTE][2] ?? null, $GLOBALS['__routes'][\mindstellar\listingimport\Admin\Upload::ROUTE][2] ?? null));

harness_section('migrations');

$files = array_values(array_diff(scandir(Schema::dir()), array('.', '..')));
check('there is at least one', $files !== array());
pin(
    'every file carries the plugin prefix, so none can share a name with a core migration',
    array(),
    array_values(array_filter($files, static fn ($f) => strncmp($f, Schema::PREFIX, strlen(Schema::PREFIX)) !== 0))
);
pin('Schema::VERSION counts them', count($files), Schema::VERSION);
check('the plugin has no key table of its own any more', !in_array('t_listing_import_key', Schema::TABLES, true));

harness_section('the key ids on a source');

use mindstellar\listingimport\Admin\SourceForm;

pin('are stored as digits, once each, comma separated', '3,5,12', SourceForm::keyIds(' 3, 5 ;12 , 3 '));
pin('anything else is dropped', '', SourceForm::keyIds('abc, 0, -'));

exit(harness_result());
