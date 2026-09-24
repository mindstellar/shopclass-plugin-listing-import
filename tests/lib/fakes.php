<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Stand-ins for the parts of the API that need a database or a running site.
 */

use mindstellar\listingimport\Api;
use mindstellar\listingimport\Auth\FailureCounter;
use mindstellar\listingimport\Auth\KeyRepository;
use mindstellar\listingimport\Auth\KeyStore;

/** Keys in an array. */
final class MemoryKeyRepository implements KeyRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = array();

    public function findByKeyId(string $keyId): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['s_key_id'] === $keyId) {
                return $row;
            }
        }

        return null;
    }

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(array $row): int
    {
        $id                = count($this->rows) + 1;
        $this->rows[$id]   = $row + array('pk_i_id' => $id, 'dt_window' => null, 'i_window_count' => 0, 'dt_last_used' => null, 's_last_ip' => '');

        return $id;
    }

    public function update(int $id, array $fields): void
    {
        $this->rows[$id] = $fields + $this->rows[$id];
    }

    public function hit(int $id, string $minute): int
    {
        $row = &$this->rows[$id];
        $row['i_window_count'] = $row['dt_window'] === $minute ? $row['i_window_count'] + 1 : 1;
        $row['dt_window']      = $minute;

        return $row['i_window_count'];
    }

    public function all(): array
    {
        return array_reverse(array_values($this->rows));
    }
}

/** Failed checks counted in memory, with the same limit as the real one. */
final class MemoryFailureCounter extends FailureCounter
{
    public int $count = 0;

    public function exceeded(): bool
    {
        return $this->count >= self::MAX;
    }

    public function record(): void
    {
        $this->count++;
    }
}

/**
 * An API over in-memory keys, with a clock the test can move.
 *
 * @param int $perMinute
 *
 * @return Api
 */
function fake_api(int $perMinute = 60): Api
{
    $GLOBALS['__now']      = $GLOBALS['__now'] ?? 1790000000;
    $GLOBALS['__repo']     = new MemoryKeyRepository();
    $GLOBALS['__failures'] = new MemoryFailureCounter();
    $GLOBALS['__keys']     = new KeyStore($GLOBALS['__repo'], 'test-pepper', static fn () => $GLOBALS['__now']);

    return new Api($GLOBALS['__keys'], $GLOBALS['__failures'], $perMinute);
}
