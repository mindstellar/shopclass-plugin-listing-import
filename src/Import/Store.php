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
 * The importer's own records: sources, which listing each outside record became, and a row
 * per run with its log. The database in production; arrays in tests.
 */
interface Store
{
    /** A source by id, or the first push source when the id is null. */
    public function source(?int $id): ?Source;

    /**
     * @return array<string,mixed>|null the map row for one outside record
     */
    public function mapped(int $sourceId, string $externalId): ?array;

    /**
     * Remember (or update) which listing an outside record became, its content hash, and the
     * hashes of the images already on it.
     *
     * @param array<int,string> $imageHashes
     */
    public function map(int $sourceId, string $externalId, int $itemId, string $hash, array $imageHashes = array()): void;

    /** Mark an outside record as seen now, without changing it. */
    public function seen(int $sourceId, string $externalId): void;

    /** Start a run of $total records; returns its id. */
    public function startRun(int $sourceId, string $trigger, bool $dryRun, int $total = 1): int;

    /**
     * @param array<string,int> $counts created, updated, unchanged, retired, failed
     */
    public function finishRun(int $runId, array $counts, string $summary): void;

    /**
     * Add to a run's counts, for a run that finishes over several steps.
     *
     * @param array<string,int> $counts
     */
    public function addCounts(int $runId, array $counts): void;

    /** Mark a run finished. */
    public function closeRun(int $runId): void;

    /**
     * @return array<string,mixed>|null the run row
     */
    public function run(int $runId): ?array;

    /**
     * @return array<int,array<string,mixed>> the newest runs first
     */
    public function recentRuns(int $limit): array;

    /**
     * @return array<int,array{external_id: string, errors: array<string,string>}> a run's failed records
     */
    public function runErrors(int $runId, int $limit): array;

    /** Mark a run finished once every one of its records is counted; true when this call did. */
    public function closeIfDone(int $runId): bool;

    /**
     * Records of a source not seen since a time, whose listings are still live.
     *
     * @return array<int,array{external_id: string, item_id: int}>
     */
    public function unseen(int $sourceId, string $since): array;

    /** Forget an outside record entirely. */
    public function forgetRecord(int $sourceId, string $externalId): void;

    /** Mark an outside record as gone from its feed. */
    public function retire(int $sourceId, string $externalId): void;

    /**
     * Enabled pull sources due to be fetched.
     *
     * @return array<int,Source>
     */
    public function dueSources(string $now): array;

    /** When a source is next fetched, and how its last fetch went. */
    public function scheduleNext(int $sourceId, string $next, string $status): void;

    /** Delete log lines older than a date; returns how many. */
    public function pruneLogs(string $before): int;

    /**
     * @param array<string,mixed> $context
     */
    public function log(int $runId, int $sourceId, string $externalId, string $level, string $message, array $context = array()): void;
}
