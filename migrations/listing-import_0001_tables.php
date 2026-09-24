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

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * The five tables: where listings come from, the keys that may push them, which listing
 * each outside record became, and a row per run with its log. Plus one push source, "API".
 *
 * CREATE TABLE IF NOT EXISTS, so a run that stopped half way can simply run again.
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $p    = DB_TABLE_PREFIX;
        $tail = " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'";

        // One column per setting, so the admin's declared form writes the row directly.
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 't_listing_import_source ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_name VARCHAR(100) NOT NULL,'
            . " e_kind ENUM('push','pull') NOT NULL DEFAULT 'push',"
            . ' b_enabled TINYINT(1) NOT NULL DEFAULT 1,'
            // Pulled feeds: where, in what format, how to map it, how often.
            . " s_url VARCHAR(2048) NOT NULL DEFAULT '',"
            . " s_format VARCHAR(20) NOT NULL DEFAULT 'json',"
            . ' s_mapping TEXT NULL,'
            . ' i_interval_minutes INT UNSIGNED NOT NULL DEFAULT 360,'
            . ' dt_next_run DATETIME NULL DEFAULT NULL,'
            // What fills a record's gaps.
            // 0 is none: a declared form cannot write NULL.
            . ' fk_i_category_id INT UNSIGNED NOT NULL DEFAULT 0,'
            . " fk_c_country_code CHAR(2) NOT NULL DEFAULT '',"
            . " fk_c_currency_code CHAR(3) NOT NULL DEFAULT '',"
            . " fk_c_locale_code CHAR(5) NOT NULL DEFAULT '',"
            . ' fk_i_owner_id INT UNSIGNED NOT NULL DEFAULT 0,'
            . " s_contact_name VARCHAR(100) NOT NULL DEFAULT '',"
            . " s_contact_email VARCHAR(100) NOT NULL DEFAULT '',"
            // The rules for a new listing, and for one that leaves the feed.
            . " e_status ENUM('site','active','pending') NOT NULL DEFAULT 'site',"
            . ' b_respect_caps TINYINT(1) NOT NULL DEFAULT 1,'
            . ' b_owners_from_records TINYINT(1) NOT NULL DEFAULT 0,'
            . " e_missing ENUM('keep','deactivate') NOT NULL DEFAULT 'deactivate',"
            . ' dt_created DATETIME NOT NULL,'
            . ' dt_last_run DATETIME NULL DEFAULT NULL,'
            . " s_last_status VARCHAR(255) NOT NULL DEFAULT '',"
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_due (b_enabled, e_kind, dt_next_run)'
            . ')' . $tail
        );

        // Keys that name no source import into the first push source, so there is always one.
        if ((int)$conn->scalar('SELECT COUNT(*) FROM ' . $p . 't_listing_import_source') === 0) {
            $conn->execute(
                'INSERT INTO ' . $p . "t_listing_import_source (s_name, e_kind, dt_created) VALUES ('API', 'push', NOW())"
            );
        }

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 't_listing_import_key ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_source_id INT UNSIGNED NULL DEFAULT NULL,'
            . ' s_name VARCHAR(100) NOT NULL,'
            . ' s_key_id CHAR(16) NOT NULL,'
            . ' s_secret_hash CHAR(64) NOT NULL,'
            . ' s_scopes VARCHAR(255) NOT NULL,'
            . ' b_enabled TINYINT(1) NOT NULL DEFAULT 1,'
            . ' dt_expires DATETIME NULL DEFAULT NULL,'
            . ' dt_last_used DATETIME NULL DEFAULT NULL,'
            . " s_last_ip VARCHAR(45) NOT NULL DEFAULT '',"
            . ' dt_created DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uk_key_id (s_key_id),'
            . ' INDEX idx_source (fk_i_source_id)'
            . ')' . $tail
        );

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 't_listing_import_item ('
            . ' fk_i_source_id INT UNSIGNED NOT NULL,'
            . ' s_external_id VARCHAR(191) NOT NULL,'
            . ' fk_i_item_id INT UNSIGNED NULL DEFAULT NULL,'
            . ' s_hash CHAR(64) NOT NULL,'
            . ' s_image_hashes TEXT NULL,'
            . ' dt_first_seen DATETIME NOT NULL,'
            . ' dt_last_seen DATETIME NOT NULL,'
            . ' dt_synced DATETIME NULL DEFAULT NULL,'
            . " e_status ENUM('active','retired','failed') NOT NULL DEFAULT 'active',"
            . ' PRIMARY KEY (fk_i_source_id, s_external_id),'
            . ' INDEX idx_item (fk_i_item_id)'
            . ')' . $tail
        );

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 't_listing_import_run ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_source_id INT UNSIGNED NOT NULL,'
            . " s_trigger VARCHAR(10) NOT NULL DEFAULT 'push',"
            . ' b_dry_run TINYINT(1) NOT NULL DEFAULT 0,'
            // How many records the run holds, so a run of queued jobs knows when it is done.
            . ' i_total INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' dt_started DATETIME NOT NULL,'
            . ' dt_finished DATETIME NULL DEFAULT NULL,'
            . ' i_created INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_updated INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_unchanged INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_retired INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_failed INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' s_summary TEXT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_source_started (fk_i_source_id, dt_started)'
            . ')' . $tail
        );

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 't_listing_import_log ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_run_id INT UNSIGNED NULL DEFAULT NULL,'
            . ' fk_i_source_id INT UNSIGNED NOT NULL,'
            . " s_external_id VARCHAR(191) NOT NULL DEFAULT '',"
            . " e_level ENUM('info','warning','error') NOT NULL DEFAULT 'info',"
            . ' s_message VARCHAR(500) NOT NULL,'
            . ' s_context TEXT NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_run (fk_i_run_id),'
            . ' INDEX idx_date (dt_date)'
            . ')' . $tail
        );
    }
};
