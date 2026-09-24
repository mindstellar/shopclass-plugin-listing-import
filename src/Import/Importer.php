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

use mindstellar\listingimport\Images\ImageSource;
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

    private ?ImageSource $images;

    private int $maxImages;

    private int $imageSeconds;

    /**
     * @param Resolver     $resolver
     * @param Listings     $listings
     * @param Store        $store
     * @param ImageSource|null $images   null imports no images
     * @param int          $maxImages    images fetched per record
     * @param int          $imageSeconds time allowed for one record's images
     */
    public function __construct(
        Resolver $resolver,
        Listings $listings,
        Store $store,
        ?ImageSource $images = null,
        int $maxImages = 10,
        int $imageSeconds = 30
    ) {
        $this->resolver     = $resolver;
        $this->listings     = $listings;
        $this->store        = $store;
        $this->images       = $images;
        $this->maxImages    = $maxImages;
        $this->imageSeconds = $imageSeconds;
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

        if ($itemId === null && !empty($source->policy['respect_caps'])
            && !$this->listings->canPublish((string)$resolved['fields']['contactEmail'])
        ) {
            return $this->fail($source, $runId, $externalId, array('owner' => 'The owner has reached their listing limit.'), $warnings);
        }

        // Images the listing already has, by content, are not fetched into it again.
        $known  = $itemId !== null ? (json_decode((string)($mapped['s_image_hashes'] ?? ''), true) ?: array()) : array();
        $photos = $this->fetchImages((array)($record['images'] ?? array()), $known, $warnings);
        try {
            if ($itemId !== null) {
                $result = $this->listings->update($itemId, $resolved['fields'], $resolved['meta'], array_column($photos, 'path'));
                if ($result !== true) {
                    return $this->fail($source, $runId, $externalId, array('listing' => (string)$result), $warnings);
                }
                $this->store->map($source->id, $externalId, $itemId, $hash, array_merge($known, array_column($photos, 'hash')));
                $this->store->log($runId, $source->id, $externalId, 'info', 'Updated listing ' . $itemId . '.');

                return $this->done(self::UPDATED, $itemId, $externalId, $warnings);
            }

            $result = $this->listings->create($resolved['fields'], $resolved['meta'], array_column($photos, 'path'));
        } finally {
            // Core deletes the files it took; anything left is from a refused save.
            foreach ($photos as $photo) {
                if (is_file($photo['path'])) {
                    @unlink($photo['path']);
                }
            }
        }
        if (!is_int($result)) {
            return $this->fail($source, $runId, $externalId, array('listing' => trim((string)$result)), $warnings);
        }
        $status = $source->policy['status'] ?? Source::STATUS_SITE;
        if ($status === Source::STATUS_PENDING || ($status === Source::STATUS_SITE && $this->listings->siteModerates())) {
            $this->listings->hold($result);
        }
        $this->store->map($source->id, $externalId, $result, $hash, array_column($photos, 'hash'));
        $this->store->log($runId, $source->id, $externalId, 'info', 'Created listing ' . $result . '.');

        return $this->done(self::CREATED, $result, $externalId, $warnings);
    }

    /**
     * Fetch a record's images, skipping ones already known and ones fetched twice. A refused
     * image is a warning, never a failure: the listing is still worth having.
     *
     * @param array<int,string>    $urls
     * @param array<int,string>    $known    hashes of images the listing already has
     * @param array<string,string> $warnings
     *
     * @return array<int,array{path: string, hash: string}>
     */
    private function fetchImages(array $urls, array $known, array &$warnings): array
    {
        if ($urls === array()) {
            return array();
        }
        if ($this->images === null) {
            $warnings['images'] = 'Images are not imported here.';

            return array();
        }

        $photos   = array();
        $deadline = microtime(true) + $this->imageSeconds;
        foreach (array_values($urls) as $i => $url) {
            if ($i >= $this->maxImages) {
                $warnings['images'] = 'Only the first ' . $this->maxImages . ' images are imported.';
                break;
            }
            if (microtime(true) > $deadline) {
                $warnings['images'] = 'Images took too long; the rest were skipped.';
                break;
            }
            $got = $this->images->fetch((string)$url);
            if (!$got['ok']) {
                $warnings['images.' . $i] = $got['error'];
                continue;
            }
            if (in_array($got['hash'], $known, true) || in_array($got['hash'], array_column($photos, 'hash'), true)) {
                @unlink($got['path']);
                continue;
            }
            $photos[] = array('path' => $got['path'], 'hash' => $got['hash']);
        }

        return $photos;
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
