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
 * Where API keys are stored. The database in production; a plain array in tests.
 */
interface KeyRepository
{
    /**
     * @param string $keyId the public half of a token
     *
     * @return array<string,mixed>|null the key row
     */
    public function findByKeyId(string $keyId): ?array;

    /**
     * @param int $id
     *
     * @return array<string,mixed>|null the key row
     */
    public function find(int $id): ?array;

    /**
     * @param array<string,mixed> $row column => value
     *
     * @return int the new row's id
     */
    public function insert(array $row): int;

    /**
     * @param int                 $id
     * @param array<string,mixed> $fields column => value
     *
     * @return void
     */
    public function update(int $id, array $fields): void;

    /**
     * @return array<int,array<string,mixed>> every key, newest first
     */
    public function all(): array;
}
