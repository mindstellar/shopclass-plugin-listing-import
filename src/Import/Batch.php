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
 * Many records, imported in the background through core's job queue.
 *
 * Each record is its own job and carries the record itself, so a job never depends on a row
 * that might be gone by the time it runs. Core retries a job that throws and shows the ones
 * that gave up under Tools → Background jobs. The run counts each record as its job finishes,
 * and is marked finished when every record is counted.
 */
final class Batch
{
    /** The job type. */
    public const JOB = 'listing_import.record';

    /** The most records one request may send. */
    public const MAX_RECORDS = 200;

    /** A job's payload is stored in a 64 KB column; a record must leave room for the rest. */
    public const MAX_RECORD_BYTES = 60000;

    private Importer $importer;

    private Store $store;

    /** @var callable(string, array<string,mixed>): mixed */
    private $enqueue;

    /**
     * @param Importer      $importer
     * @param Store         $store
     * @param callable|null $enqueue queues a job; osc_job_enqueue() by default
     */
    public function __construct(Importer $importer, Store $store, ?callable $enqueue = null)
    {
        $this->importer = $importer;
        $this->store    = $store;
        $this->enqueue  = $enqueue ?? 'osc_job_enqueue';
    }

    /**
     * Start a run and queue one job per record.
     *
     * @param Source                         $source
     * @param array<int,array<string,mixed>> $records
     * @param string                         $trigger push, cli or cron
     * @param bool                           $dryRun
     *
     * @return int the run's id
     */
    public function queue(Source $source, array $records, string $trigger, bool $dryRun = false): int
    {
        $runId = $this->store->startRun($source->id, $trigger, $dryRun, count($records));
        foreach ($records as $record) {
            $record = is_array($record) ? $record : array();
            if (strlen((string)json_encode($record)) > self::MAX_RECORD_BYTES) {
                $this->store->log($runId, $source->id, (string)($record['external_id'] ?? ''), 'error', 'Not imported.', array(
                    'errors' => array('record' => 'Larger than ' . self::MAX_RECORD_BYTES . ' bytes; send it on its own.'),
                ));
                $this->store->addCounts($runId, array(Importer::FAILED => 1));
                continue;
            }
            ($this->enqueue)(self::JOB, array(
                'run_id'    => $runId,
                'source_id' => $source->id,
                'dry_run'   => $dryRun,
                'record'    => $record,
            ));
        }
        $this->store->closeIfDone($runId);

        return $runId;
    }

    /**
     * Import one queued record: what a job does.
     *
     * @param array<string,mixed> $payload
     *
     * @return void
     */
    public function work(array $payload): void
    {
        $runId  = (int)($payload['run_id'] ?? 0);
        $source = $this->store->source((int)($payload['source_id'] ?? 0));
        $record = is_array($payload['record'] ?? null) ? $payload['record'] : array();
        if ($source === null) {
            $this->store->log($runId, (int)($payload['source_id'] ?? 0), (string)($record['external_id'] ?? ''), 'error', 'The source is missing or switched off.');
            $this->store->addCounts($runId, array(Importer::FAILED => 1));
        } else {
            $result = $this->importer->import($source, $record, $runId, !empty($payload['dry_run']));
            $this->store->addCounts($runId, array($result['status'] => 1));
        }
        $this->store->closeIfDone($runId);
    }

    /**
     * Import every record now, for the command line: no queue, no waiting for cron.
     *
     * @param Source                         $source
     * @param array<int,array<string,mixed>> $records
     * @param string                         $trigger
     * @param bool                           $dryRun
     *
     * @return int the run's id
     */
    public function runNow(Source $source, array $records, string $trigger, bool $dryRun = false): int
    {
        $runId = $this->store->startRun($source->id, $trigger, $dryRun, count($records));
        foreach ($records as $record) {
            $result = $this->importer->import($source, is_array($record) ? $record : array(), $runId, $dryRun);
            $this->store->addCounts($runId, array($result['status'] => 1));
        }
        $this->store->closeIfDone($runId);

        return $runId;
    }
}
