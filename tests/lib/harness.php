<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The pin/check micro-harness the core repository's tests use, trimmed to the four
 * functions these tests need. No dependency on a test framework, and none on Shopclass:
 * every test here runs from a bare `php tests/<file>.php`.
 */

$GLOBALS['okCount']    = $GLOBALS['okCount'] ?? 0;
$GLOBALS['failCount']  = $GLOBALS['failCount'] ?? 0;
$GLOBALS['failLabels'] = $GLOBALS['failLabels'] ?? array();

function describe($v): string
{
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return 'bool(' . ($v ? 'true' : 'false') . ')';
    }
    if (is_int($v)) {
        return 'int(' . $v . ')';
    }
    if (is_string($v)) {
        return 'string("' . $v . '")';
    }
    if (is_array($v)) {
        return 'array(' . count($v) . ') ' . json_encode($v);
    }

    return gettype($v);
}

function report(string $label, bool $ok, string $expected, string $actual): void
{
    if ($ok) {
        $GLOBALS['okCount']++;
        echo "PASS  $label\n";

        return;
    }

    $GLOBALS['failCount']++;
    $GLOBALS['failLabels'][] = $label;
    echo "FAIL  $label\n        expected: $expected\n        actual:   $actual\n";
}

function pin(string $label, $expected, $actual): void
{
    report($label, $expected === $actual, describe($expected), describe($actual));
}

function check(string $label, bool $ok, string $detail = ''): void
{
    report($label, $ok, 'true', $ok ? 'true' : ('false' . ($detail !== '' ? " ($detail)" : '')));
}

function harness_section(string $title): void
{
    echo "\n== $title ==\n";
}

function harness_result(): int
{
    echo "\n----------------------------------------\n";
    echo "RESULT: {$GLOBALS['okCount']} passed, {$GLOBALS['failCount']} failed\n";
    foreach ($GLOBALS['failLabels'] as $l) {
        echo "  FAILED: $l\n";
    }

    return $GLOBALS['failCount'] === 0 ? 0 : 1;
}

/**
 * Load the plugin's classes on demand, and the in-memory fakes they are tested with.
 *
 * @return void
 */
function harness_plugin(): void
{
    if (!defined('ABS_PATH')) {
        define('ABS_PATH', '/tmp/');
    }
    spl_autoload_register(static function (string $class): void {
        $prefix = 'mindstellar\\listingimport\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    require_once __DIR__ . '/fakes.php';
}

/**
 * Load core's API classes (Request, Response, Problem, Router, Kernel and the rest) from a
 * Shopclass checkout: $OSC_CORE, or ../osclass beside this repository. It must have the REST API.
 * Call it before harness_plugin(), so ABS_PATH points at core.
 *
 * @return void
 */
function harness_core(): void
{
    $core = rtrim((string)(getenv('OSC_CORE') ?: dirname(__DIR__, 3) . '/osclass'), '/') . '/';
    if (!is_file($core . 'oc-includes/osclass/classes/api/Kernel.php')) {
        fwrite(STDERR, "Core's REST API not found in $core. Set OSC_CORE to a Shopclass 7.0 checkout.\n");
        exit(2);
    }
    if (!defined('ABS_PATH')) {
        define('ABS_PATH', $core);
    }
    require_once $core . 'oc-includes/vendor/autoload.php';
    if (!function_exists('osc_plugins_path')) {
        function osc_plugins_path()
        {
            return ABS_PATH . 'oc-content/plugins/';
        }
    }
    require_once $core . 'oc-includes/osclass/helpers/hPlugins.php';
    require_once $core . 'oc-includes/osclass/helpers/hHttpCache.php';
    require_once $core . 'oc-includes/osclass/helpers/hApi.php';
}
