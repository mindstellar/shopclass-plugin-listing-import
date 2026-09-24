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

namespace mindstellar\listingimport\Auth;

/**
 * API keys in `t_listing_import_key`.
 */
final class DbKeyRepository implements KeyRepository
{
    /** Columns a caller may write. Anything else in a row is ignored. */
    private const COLUMNS = array(
        'fk_i_source_id', 's_name', 's_key_id', 's_secret_hash', 's_scopes', 'b_enabled',
        'dt_expires', 'dt_last_used', 's_last_ip', 'dt_created',
    );

    private function table(): string
    {
        return DB_TABLE_PREFIX . 't_listing_import_key';
    }

    public function findByKeyId(string $keyId): ?array
    {
        return osc_db_select_one('SELECT * FROM ' . $this->table() . ' WHERE s_key_id = ?', array($keyId));
    }

    public function find(int $id): ?array
    {
        return osc_db_select_one('SELECT * FROM ' . $this->table() . ' WHERE pk_i_id = ?', array($id));
    }

    public function insert(array $row): int
    {
        $row = array_intersect_key($row, array_flip(self::COLUMNS));

        return osc_db_insert_id(
            'INSERT INTO ' . $this->table() . ' (' . implode(', ', array_keys($row)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
    }

    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        if ($fields === array()) {
            return;
        }
        $set = implode(', ', array_map(static fn ($column) => $column . ' = ?', array_keys($fields)));
        osc_db_execute(
            'UPDATE ' . $this->table() . ' SET ' . $set . ' WHERE pk_i_id = ?',
            array_merge(array_values($fields), array($id))
        );
    }

    public function all(): array
    {
        return osc_db_select('SELECT * FROM ' . $this->table() . ' ORDER BY pk_i_id DESC');
    }
}
