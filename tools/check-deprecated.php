<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Fails when the plugin calls anything core has marked deprecated: a function, a method,
 * a class, a hook or a filter. Reads the deprecated-api.json a core checkout generates with
 * `node scripts/gen-deprecated-api.mjs`, and is also attached to each core release.
 *
 * Also refuses a raw Params::getParam(): new code reads input through the typed accessors.
 *
 * Usage:  php tools/check-deprecated.php /path/to/deprecated-api.json
 */

$list = $argv[1] ?? '';
$api  = is_file($list) ? json_decode((string)file_get_contents($list), true) : null;
if (!is_array($api)) {
    fwrite(STDERR, "Usage: php tools/check-deprecated.php /path/to/deprecated-api.json\n");
    exit(2);
}

$source = '';
$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = (string)$file;
    if (substr($path, -4) === '.php' && strpos($path, '/tests/') === false && strpos($path, '/tools/') === false
        && strpos($path, '/.git/') === false
    ) {
        $source .= file_get_contents($path) . "\n";
    }
}

$found = array();
foreach ($api['functions'] ?? array() as $entry) {
    $name = $entry['name'];
    if (strpos($name, '::') !== false) {
        [$class, $method] = explode('::', $name, 2);
        $used = preg_match('/\b' . preg_quote($class, '/') . '\b/', $source)
            && preg_match('/(::|->)' . preg_quote($method, '/') . '\s*\(/', $source);
    } else {
        $used = preg_match('/(?<![\w>:$])' . preg_quote($name, '/') . '\s*\(/', $source);
    }
    if ($used) {
        $found[] = $name . ' (deprecated since ' . $entry['since'] . ')';
    }
}
foreach ($api['classes'] ?? array() as $entry) {
    if (preg_match('/\b' . preg_quote($entry['name'], '/') . '\b/', $source)) {
        $found[] = 'class ' . $entry['name'] . ' (use ' . ($entry['replacement'] ?? 'its replacement') . ')';
    }
}
foreach (array_merge($api['hooks'] ?? array(), $api['filters'] ?? array()) as $entry) {
    $name = is_array($entry) ? $entry['name'] : $entry;
    if (preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"]/', $source)) {
        $found[] = 'hook ' . $name;
    }
}
if (preg_match_all('/Params::getParam\s*\(/', $source, $m)) {
    $found[] = count($m[0]) . ' raw Params::getParam() call(s); use getParamString/Int/Array';
}

if ($found === array()) {
    echo "No deprecated core API in use.\n";
    exit(0);
}
echo "Deprecated or discouraged API in use:\n  - " . implode("\n  - ", $found) . "\n";
exit(1);
