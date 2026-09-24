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

namespace mindstellar\listingimport\Import;

/**
 * The importer's records in its own tables.
 */
final class DbStore implements Store
{
    private function t(string $table): string
    {
        return DB_TABLE_PREFIX . 't_listing_import_' . $table;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function source(?int $id): ?Source
    {
        $row = $id === null
            ? osc_db_select_one('SELECT * FROM ' . $this->t('source') . " WHERE e_kind = 'push' AND b_enabled = 1 ORDER BY pk_i_id LIMIT 1")
            : osc_db_select_one('SELECT * FROM ' . $this->t('source') . ' WHERE pk_i_id = ? AND b_enabled = 1', array($id));

        return $row === null ? null : Source::fromRow($row);
    }

    public function mapped(int $sourceId, string $externalId): ?array
    {
        return osc_db_select_one(
            'SELECT * FROM ' . $this->t('item') . ' WHERE fk_i_source_id = ? AND s_external_id = ?',
            array($sourceId, $externalId)
        );
    }

    public function map(int $sourceId, string $externalId, int $itemId, string $hash, array $imageHashes = array()): void
    {
        $now     = $this->now();
        $images  = json_encode(array_values($imageHashes));
        $changed = osc_db_execute(
            'UPDATE ' . $this->t('item') . " SET fk_i_item_id = ?, s_hash = ?, s_image_hashes = ?, dt_last_seen = ?, dt_synced = ?, e_status = 'active'"
            . ' WHERE fk_i_source_id = ? AND s_external_id = ?',
            array($itemId, $hash, $images, $now, $now, $sourceId, $externalId)
        );
        if ($changed === 0 && $this->mapped($sourceId, $externalId) === null) {
            osc_db_execute(
                'INSERT INTO ' . $this->t('item')
                . ' (fk_i_source_id, s_external_id, fk_i_item_id, s_hash, s_image_hashes, dt_first_seen, dt_last_seen, dt_synced, e_status)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')",
                array($sourceId, $externalId, $itemId, $hash, $images, $now, $now, $now)
            );
        }
    }

    public function seen(int $sourceId, string $externalId): void
    {
        osc_db_execute(
            'UPDATE ' . $this->t('item') . ' SET dt_last_seen = ? WHERE fk_i_source_id = ? AND s_external_id = ?',
            array($this->now(), $sourceId, $externalId)
        );
    }

    /**
     * Forget a listing an admin deleted, so the record behind it is created again only when
     * it changes.
     *
     * @param int|string $itemId
     *
     * @return void
     */
    public static function forget($itemId): void
    {
        osc_db_execute(
            'UPDATE ' . DB_TABLE_PREFIX . 't_listing_import_item SET fk_i_item_id = NULL WHERE fk_i_item_id = ?',
            array((int)$itemId)
        );
    }

    public function startRun(int $sourceId, string $trigger, bool $dryRun, int $total = 1): int
    {
        return osc_db_insert_id(
            'INSERT INTO ' . $this->t('run') . ' (fk_i_source_id, s_trigger, b_dry_run, i_total, dt_started) VALUES (?, ?, ?, ?, ?)',
            array($sourceId, $trigger, $dryRun ? 1 : 0, $total, $this->now())
        );
    }

    public function finishRun(int $runId, array $counts, string $summary): void
    {
        osc_db_execute(
            'UPDATE ' . $this->t('run') . ' SET dt_finished = ?, i_created = ?, i_updated = ?, i_unchanged = ?,'
            . ' i_retired = ?, i_failed = ?, s_summary = ? WHERE pk_i_id = ?',
            array(
                $this->now(),
                $counts['created'] ?? 0,
                $counts['updated'] ?? 0,
                $counts['unchanged'] ?? 0,
                $counts['retired'] ?? 0,
                $counts['failed'] ?? 0,
                $summary,
                $runId,
            )
        );
    }

    public function log(int $runId, int $sourceId, string $externalId, string $level, string $message, array $context = array()): void
    {
        osc_db_execute(
            'INSERT INTO ' . $this->t('log')
            . ' (fk_i_run_id, fk_i_source_id, s_external_id, e_level, s_message, s_context, dt_date) VALUES (?, ?, ?, ?, ?, ?, ?)',
            array(
                $runId,
                $sourceId,
                mb_substr($externalId, 0, 191),
                $level,
                mb_substr($message, 0, 500),
                $context === array() ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                $this->now(),
            )
        );
    }

    public function addCounts(int $runId, array $counts): void
    {
        osc_db_execute(
            'UPDATE ' . $this->t('run') . ' SET i_created = i_created + ?, i_updated = i_updated + ?,'
            . ' i_unchanged = i_unchanged + ?, i_retired = i_retired + ?, i_failed = i_failed + ? WHERE pk_i_id = ?',
            array(
                $counts['created'] ?? 0,
                $counts['updated'] ?? 0,
                $counts['unchanged'] ?? 0,
                $counts['retired'] ?? 0,
                $counts['failed'] ?? 0,
                $runId,
            )
        );
    }

    public function closeRun(int $runId): void
    {
        osc_db_execute('UPDATE ' . $this->t('run') . ' SET dt_finished = ? WHERE pk_i_id = ?', array($this->now(), $runId));
    }

    public function run(int $runId): ?array
    {
        return osc_db_select_one('SELECT * FROM ' . $this->t('run') . ' WHERE pk_i_id = ?', array($runId));
    }

    public function recentRuns(int $limit): array
    {
        return osc_db_select(
            'SELECT r.*, s.s_name FROM ' . $this->t('run') . ' r LEFT JOIN ' . $this->t('source') . ' s ON s.pk_i_id = r.fk_i_source_id'
            . ' ORDER BY r.pk_i_id DESC LIMIT ' . max(1, $limit)
        );
    }

    public function runErrors(int $runId, int $limit): array
    {
        $errors = array();
        foreach (osc_db_select(
            'SELECT s_external_id, s_context FROM ' . $this->t('log') . " WHERE fk_i_run_id = ? AND e_level = 'error'"
            . ' ORDER BY pk_i_id LIMIT ' . max(1, $limit),
            array($runId)
        ) as $row) {
            $context  = json_decode((string)$row['s_context'], true);
            $errors[] = array('external_id' => (string)$row['s_external_id'], 'errors' => (array)($context['errors'] ?? array()));
        }

        return $errors;
    }

    public function closeIfDone(int $runId): void
    {
        osc_db_execute(
            'UPDATE ' . $this->t('run') . ' SET dt_finished = ? WHERE pk_i_id = ? AND dt_finished IS NULL'
            . ' AND i_created + i_updated + i_unchanged + i_retired + i_failed >= i_total',
            array($this->now(), $runId)
        );
    }

    public function pruneLogs(string $before): int
    {
        return osc_db_execute('DELETE FROM ' . $this->t('log') . ' WHERE dt_date < ?', array($before));
    }
}
