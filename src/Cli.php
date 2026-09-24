<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listingimport;

use mindstellar\listingimport\Admin\Keys;
use mindstellar\listingimport\Auth\KeyStore;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Import\Store;
use mindstellar\listingimport\Record\FileReader;

/**
 * The plugin's oc-cli.php commands. Queued work runs with core's `jobs:work`, so there is no
 * worker command of its own.
 */
final class Cli
{
    /**
     * Add the commands to core's list: the `cli_commands` filter.
     *
     * @param array<string,mixed> $commands
     *
     * @return array<string,mixed>
     */
    public static function commands(array $commands): array
    {
        $commands['import:run'] = array(
            'summary'  => 'Import records from a JSON or NDJSON file now (--file= [--source=] [--dry-run])',
            'callback' => array(self::class, 'run'),
        );
        $commands['import:status'] = array(
            'summary'  => 'Show the latest import runs ([--limit=10])',
            'callback' => array(self::class, 'status'),
        );
        $commands['import:key:create'] = array(
            'summary'  => 'Make an API key and print it once (--name= [--scopes=listings:write,runs:read] [--source=])',
            'callback' => array(self::class, 'createKey'),
        );

        return $commands;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public static function run(array $args): int
    {
        $file = (string)($args['file'] ?? '');
        if ($file === '') {
            return self::fail("Name the file: --file=records.json\n");
        }
        $read = FileReader::read($file);
        if ($read['error'] !== null) {
            return self::fail($read['error'] . "\n");
        }
        $store  = new DbStore();
        $source = $store->source(isset($args['source']) ? (int)$args['source'] : null);
        if ($source === null) {
            return self::fail("No such source, or it is switched off.\n");
        }

        $dryRun = !empty($args['dry-run']);
        $runId  = Plugin::batch()->runNow($source, $read['records'], 'cli', $dryRun);
        self::summary($store, $runId, $dryRun);

        return (int)($store->run($runId)['i_failed'] ?? 0) > 0 ? 1 : 0;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public static function status(array $args): int
    {
        $runs = (new DbStore())->recentRuns(max(1, (int)($args['limit'] ?? 10)));
        if ($runs === array()) {
            echo "No import runs yet.\n";

            return 0;
        }
        printf("%-6s %-10s %-8s %-20s %8s %8s %8s %8s %6s\n", 'RUN', 'SOURCE', 'HOW', 'STARTED', 'CREATED', 'UPDATED', 'SAME', 'FAILED', 'DONE');
        foreach ($runs as $run) {
            printf(
                "%-6d %-10s %-8s %-20s %8d %8d %8d %8d %6s\n",
                $run['pk_i_id'],
                mb_substr((string)($run['s_name'] ?? '?'), 0, 10),
                $run['s_trigger'] . ((int)$run['b_dry_run'] === 1 ? '*' : ''),
                $run['dt_started'],
                $run['i_created'],
                $run['i_updated'],
                $run['i_unchanged'],
                $run['i_failed'],
                $run['dt_finished'] === null ? 'no' : 'yes'
            );
        }
        echo "* a dry run: nothing was changed.\n";

        return 0;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public static function createKey(array $args): int
    {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            return self::fail("Name the key: --name=\"Partner site\"\n");
        }
        $scopes = array_values(array_intersect(
            KeyStore::SCOPES,
            array_map('trim', explode(',', (string)($args['scopes'] ?? KeyStore::SCOPE_WRITE)))
        ));
        if ($scopes === array()) {
            return self::fail('Give at least one of: ' . implode(', ', KeyStore::SCOPES) . "\n");
        }
        $made = Keys::store()->create($name, $scopes, isset($args['source']) ? (int)$args['source'] : null);
        echo "Key made. It is shown once; keep it safe.\n\n  " . $made['token'] . "\n\n";
        echo 'Permissions: ' . implode(', ', $scopes) . "\n";

        return 0;
    }

    /**
     * @param Store $store
     * @param int   $runId
     * @param bool  $dryRun
     *
     * @return void
     */
    private static function summary(Store $store, int $runId, bool $dryRun): void
    {
        $run = (array)$store->run($runId);
        printf(
            "Run %d%s: %d created, %d updated, %d unchanged, %d failed.\n",
            $runId,
            $dryRun ? ' (dry run, nothing changed)' : '',
            $run['i_created'] ?? 0,
            $run['i_updated'] ?? 0,
            $run['i_unchanged'] ?? 0,
            $run['i_failed'] ?? 0
        );
        foreach ($store->runErrors($runId, 20) as $failure) {
            echo '  ' . ($failure['external_id'] === '' ? '(no id)' : $failure['external_id']) . ': ';
            echo implode('; ', array_map(
                static fn ($field, $message) => $field . ' ' . $message,
                array_keys($failure['errors']),
                $failure['errors']
            )) . "\n";
        }
    }

    /**
     * @param string $message
     *
     * @return int
     */
    private static function fail(string $message): int
    {
        fwrite(STDERR, $message);

        return 2;
    }
}
