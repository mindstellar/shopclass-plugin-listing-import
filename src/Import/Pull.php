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

use mindstellar\listingimport\Feed\FeedReader;
use mindstellar\listingimport\Images\Downloader;

/**
 * Fetches a source's feed and queues its records.
 *
 * Each hour the scheduler queues a fetch job for every source that is due. The job downloads
 * the feed through the address guard, reads it, and queues one record job per record, as a
 * batch does. When the last record is counted the run finishes, and listings whose records
 * were not in the feed are deactivated.
 */
final class Pull
{
    /** The job type of one fetch. */
    public const JOB = 'listing_import.pull';

    private Downloader $downloader;

    private Batch $batch;

    private Store $store;

    /** @var callable(string, array<string,mixed>): mixed */
    private $enqueue;

    /**
     * @param Downloader    $downloader
     * @param Batch         $batch
     * @param Store         $store
     * @param callable|null $enqueue queues a job; osc_job_enqueue() by default
     */
    public function __construct(Downloader $downloader, Batch $batch, Store $store, ?callable $enqueue = null)
    {
        $this->downloader = $downloader;
        $this->batch      = $batch;
        $this->store      = $store;
        $this->enqueue    = $enqueue ?? 'osc_job_enqueue';
    }

    /**
     * Queue a fetch for every source that is due. Runs each hour.
     *
     * @return int how many were queued
     */
    public function schedule(): int
    {
        $queued = 0;
        foreach ($this->store->dueSources(date('Y-m-d H:i:s')) as $source) {
            ($this->enqueue)(self::JOB, array('source_id' => $source->id));
            $this->store->scheduleNext($source->id, self::next($source), 'Fetch queued.');
            $queued++;
        }

        return $queued;
    }

    /**
     * Fetch a source's feed now and queue its records.
     *
     * @param Source $source
     *
     * @return array{run_id: ?int, records: int, error: ?string}
     */
    public function fetch(Source $source): array
    {
        $read = $this->read($source);
        if ($read['error'] !== null) {
            $this->store->scheduleNext($source->id, self::next($source), 'Not fetched: ' . $read['error']);

            return array('run_id' => null, 'records' => 0, 'error' => $read['error']);
        }
        $runId = $this->batch->queue($source, $read['records'], 'pull');
        $count = count($read['records']);
        $this->store->scheduleNext($source->id, self::next($source), 'Fetched ' . $count . ' records into run ' . $runId . '.');

        return array('run_id' => $runId, 'records' => $count, 'error' => null);
    }

    /**
     * Download and read a source's feed without importing anything.
     *
     * @param Source $source
     *
     * @return array{records: array<int,array<string,mixed>>, error: ?string}
     */
    public function read(Source $source): array
    {
        if ($source->url === '') {
            return array('records' => array(), 'error' => 'The source has no feed address.');
        }
        $got = $this->downloader->download($source->url);
        if (!$got['ok']) {
            return array('records' => array(), 'error' => $got['error']);
        }
        try {
            return FeedReader::read($got['path'], $source->format, $source->mapping);
        } finally {
            @unlink($got['path']);
        }
    }

    /**
     * @param Source $source
     *
     * @return string
     */
    private static function next(Source $source): string
    {
        return date('Y-m-d H:i:s', time() + $source->interval * 60);
    }
}
