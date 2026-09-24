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

use mindstellar\listingimport\Record\Validator;
use mindstellar\listingimport\Resolve\Resolver;

/**
 * Imports one record: checks it, places it on this site, and creates, updates or leaves the
 * listing it became.
 *
 * A record is known by its source and its external id. Sent again unchanged, it changes
 * nothing; sent changed, it updates the same listing. A listing an admin deleted is created
 * again only if the record changes.
 */
final class Importer
{
    public const CREATED   = 'created';
    public const UPDATED   = 'updated';
    public const UNCHANGED = 'unchanged';
    public const FAILED    = 'failed';

    private Resolver $resolver;

    private Listings $listings;

    private Store $store;

    /**
     * @param Resolver $resolver
     * @param Listings $listings
     * @param Store    $store
     */
    public function __construct(Resolver $resolver, Listings $listings, Store $store)
    {
        $this->resolver = $resolver;
        $this->listings = $listings;
        $this->store    = $store;
    }

    /**
     * @param Source              $source
     * @param array<string,mixed> $record
     * @param int                 $runId the run this record belongs to, for its log lines
     * @param bool                $dryRun check and place the record, but change nothing
     *
     * @return array{status: string, item_id: ?int, external_id: string, errors: array<string,string>, warnings: array<string,string>}
     */
    public function import(Source $source, array $record, int $runId, bool $dryRun = false): array
    {
        $externalId = (string)($record['external_id'] ?? '');
        $check      = Validator::check($record);
        $warnings   = $check['warnings'];
        if ($check['errors'] !== array()) {
            return $this->fail($source, $runId, $externalId, $check['errors'], $warnings);
        }

        $resolved = $this->resolver->resolve($record, $source->defaults);
        $warnings += $resolved['warnings'];
        if ($resolved['errors'] !== array()) {
            return $this->fail($source, $runId, $externalId, $resolved['errors'], $warnings);
        }

        $hash   = self::hash($record);
        $mapped = $this->store->mapped($source->id, $externalId);
        $itemId = $mapped !== null && $mapped['fk_i_item_id'] !== null ? (int)$mapped['fk_i_item_id'] : null;
        if ($itemId !== null && !$this->listings->exists($itemId)) {
            $itemId = null;
        }

        if ($itemId !== null && $mapped['s_hash'] === $hash) {
            if (!$dryRun) {
                $this->store->seen($source->id, $externalId);
            }

            return $this->done(self::UNCHANGED, $itemId, $externalId, $warnings);
        }

        if ($dryRun) {
            return $this->done($itemId === null ? self::CREATED : self::UPDATED, $itemId, $externalId, $warnings);
        }

        if ($itemId !== null) {
            $result = $this->listings->update($itemId, $resolved['fields'], $resolved['meta']);
            if ($result !== true) {
                return $this->fail($source, $runId, $externalId, array('listing' => (string)$result), $warnings);
            }
            $this->store->map($source->id, $externalId, $itemId, $hash);
            $this->store->log($runId, $source->id, $externalId, 'info', 'Updated listing ' . $itemId . '.');

            return $this->done(self::UPDATED, $itemId, $externalId, $warnings);
        }

        if (!empty($source->policy['respect_caps']) && !$this->listings->canPublish((string)$resolved['fields']['contactEmail'])) {
            return $this->fail($source, $runId, $externalId, array('owner' => 'The owner has reached their listing limit.'), $warnings);
        }
        $result = $this->listings->create($resolved['fields'], $resolved['meta']);
        if (!is_int($result)) {
            return $this->fail($source, $runId, $externalId, array('listing' => trim((string)$result)), $warnings);
        }
        $status = $source->policy['status'] ?? Source::STATUS_SITE;
        if ($status === Source::STATUS_PENDING || ($status === Source::STATUS_SITE && $this->listings->siteModerates())) {
            $this->listings->hold($result);
        }
        $this->store->map($source->id, $externalId, $result, $hash);
        $this->store->log($runId, $source->id, $externalId, 'info', 'Created listing ' . $result . '.');

        return $this->done(self::CREATED, $result, $externalId, $warnings);
    }

    /**
     * The content hash that decides whether a record changed. Key order does not matter.
     *
     * @param array<string,mixed> $record
     *
     * @return string
     */
    public static function hash(array $record): string
    {
        $sort = static function ($value) use (&$sort) {
            if (!is_array($value)) {
                return $value;
            }
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', (string)json_encode($sort($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,string> $warnings
     *
     * @return array{status: string, item_id: ?int, external_id: string, errors: array<string,string>, warnings: array<string,string>}
     */
    private function done(string $status, ?int $itemId, string $externalId, array $warnings): array
    {
        return array('status' => $status, 'item_id' => $itemId, 'external_id' => $externalId, 'errors' => array(), 'warnings' => $warnings);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $warnings
     *
     * @return array{status: string, item_id: ?int, external_id: string, errors: array<string,string>, warnings: array<string,string>}
     */
    private function fail(Source $source, int $runId, string $externalId, array $errors, array $warnings): array
    {
        $this->store->log($runId, $source->id, $externalId, 'error', 'Not imported.', array('errors' => $errors));

        return array('status' => self::FAILED, 'item_id' => null, 'external_id' => $externalId, 'errors' => $errors, 'warnings' => $warnings);
    }
}
