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

use mindstellar\database\Connection;
use mindstellar\migration\MigrationRunner;

/**
 * The plugin's tables, created by core's migration runner from this plugin's own folder.
 *
 * Every migration file is named `listing-import_NNNN_*.php`. Core records applied
 * migrations by file name in one shared ledger, so the prefix keeps ours apart from core's.
 */
final class Schema
{
    /** Bump with every new migration file. */
    public const VERSION = 2;

    /** The file-name prefix every migration carries. */
    public const PREFIX = 'listing-import_';

    /** Table names, without DB_TABLE_PREFIX. */
    public const TABLES = array(
        't_listing_import_log',
        't_listing_import_run',
        't_listing_import_item',
        't_listing_import_source',
    );

    /**
     * The migrations folder.
     *
     * @return string
     */
    public static function dir(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    /**
     * Apply every migration not yet in the ledger.
     *
     * @return array{ok: bool, applied: array<int,string>, failed: ?string, error: ?string}
     */
    public static function migrate(): array
    {
        $runner = new MigrationRunner(Connection::getInstance(), self::dir());
        $runner->ensureLedger();

        return $runner->run();
    }

    /**
     * Drop every table and forget the migrations, so a reinstall starts clean.
     *
     * @return void
     */
    public static function drop(): void
    {
        $conn = Connection::getInstance();
        foreach (self::TABLES as $table) {
            $conn->execute('DROP TABLE IF EXISTS ' . DB_TABLE_PREFIX . $table);
        }
        $conn->execute(
            'DELETE FROM ' . DB_TABLE_PREFIX . 't_migration WHERE s_migration LIKE ?',
            array(self::PREFIX . '%')
        );
    }
}
