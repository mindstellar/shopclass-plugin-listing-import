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
    $GLOBALS['__store']    = new MemoryStore();
    $GLOBALS['__listings'] = new MemoryListings();

    $GLOBALS['__jobs']     = array();
    $importer              = fake_importer();
    $batch                 = new \mindstellar\listingimport\Import\Batch(
        $importer,
        $GLOBALS['__store'],
        $GLOBALS['__listings'],
        static function (string $type, array $payload) {
            $GLOBALS['__jobs'][] = array($type, $payload);
        }
    );

    return new Api($GLOBALS['__keys'], $GLOBALS['__failures'], $perMinute, $importer, $GLOBALS['__store'], $batch, $GLOBALS['__listings']);
}

/**
 * An importer over the in-memory store and listings, on a two-language site.
 *
 * @return \mindstellar\listingimport\Import\Importer
 */
function fake_importer(?\mindstellar\listingimport\Images\ImageSource $images = null): \mindstellar\listingimport\Import\Importer
{
    $GLOBALS['__store']    = $GLOBALS['__store'] ?? new MemoryStore();
    $GLOBALS['__listings'] = $GLOBALS['__listings'] ?? new MemoryListings();

    return new \mindstellar\listingimport\Import\Importer(
        new \mindstellar\listingimport\Resolve\Resolver(
            new MemoryLookups(),
            new \mindstellar\listingimport\Resolve\Site(array('en_US', 'de_DE'), 'en_US', ',', 'site@example.com')
        ),
        $GLOBALS['__listings'],
        $GLOBALS['__store'],
        $images,
        3
    );
}

/** A small site: two categories under Vehicles, EUR, one user, Germany with Bayern and München. */
final class MemoryLookups implements \mindstellar\listingimport\Resolve\Lookups
{
    public function categoryById(int $id): ?int
    {
        return in_array($id, array(1, 2, 3), true) ? $id : null;
    }

    public function categoryBySlug(string $slug): ?int
    {
        return array('vehicles' => 1, 'bikes' => 2, 'cars' => 3)[$slug] ?? null;
    }

    public function categoryByPath(array $names): ?int
    {
        return array('vehicles' => 1, 'vehicles/bikes' => 2, 'vehicles/cars' => 3)[strtolower(implode('/', $names))] ?? null;
    }

    public function categoryByName(string $name): ?int
    {
        return array('vehicles' => 1, 'bikes' => 2, 'cars' => 3)[strtolower($name)] ?? null;
    }

    public function currencyEnabled(string $code): bool
    {
        return $code === 'EUR';
    }

    public function userById(int $id): ?array
    {
        return $id === 7 ? array('id' => 7, 'name' => 'Sam Seller', 'email' => 'sam@example.com') : null;
    }

    public function userByEmail(string $email): ?array
    {
        return $email === 'sam@example.com' ? $this->userById(7) : null;
    }

    public function country(string $codeOrName): ?string
    {
        return in_array(strtolower($codeOrName), array('de', 'germany', 'deutschland'), true) ? 'DE' : null;
    }

    public function region(string $countryCode, string $name): ?array
    {
        return $countryCode === 'DE' && strtolower($name) === 'bayern' ? array('id' => 11, 'name' => 'Bayern') : null;
    }

    public function city(string $countryCode, ?int $regionId, string $name): ?array
    {
        return $countryCode === 'DE' && in_array(strtolower($name), array('münchen', 'munchen'), true)
            ? array('id' => 111, 'name' => 'München', 'region_id' => 11) : null;
    }

    public function fieldBySlug(string $slug): ?int
    {
        return array('colour' => 5, 'gears' => 6)[$slug] ?? null;
    }
}

/** Sources, the record map, runs and log lines in arrays. Source 1 is the default push source. */
final class MemoryStore implements \mindstellar\listingimport\Import\Store
{
    /** @var array<int,\mindstellar\listingimport\Import\Source> */
    public array $sources = array();
    public array $map     = array();
    public array $runs    = array();
    public array $logs    = array();

    public function __construct()
    {
        $this->sources[1] = new \mindstellar\listingimport\Import\Source(1, 'API');
    }

    public function source(?int $id): ?\mindstellar\listingimport\Import\Source
    {
        return $this->sources[$id ?? 1] ?? null;
    }

    public function mapped(int $sourceId, string $externalId): ?array
    {
        return $this->map[$sourceId . '|' . $externalId] ?? null;
    }

    public function map(int $sourceId, string $externalId, int $itemId, string $hash, array $imageHashes = array()): void
    {
        $this->map[$sourceId . '|' . $externalId] = array(
            'fk_i_item_id' => $itemId, 's_hash' => $hash, 's_image_hashes' => json_encode($imageHashes), 'e_status' => 'active', 'seen_at' => $this->tick,
        );
    }

    public function seen(int $sourceId, string $externalId): void
    {
        $this->map[$sourceId . '|' . $externalId]['seen_at'] = $this->tick;
    }

    public function startRun(int $sourceId, string $trigger, bool $dryRun, int $total = 1): int
    {
        $this->runs[] = array(
            'pk_i_id' => count($this->runs) + 1, 'fk_i_source_id' => $sourceId, 's_trigger' => $trigger, 'b_dry_run' => $dryRun,
            'i_total' => $total, 'i_created' => 0, 'i_updated' => 0, 'i_unchanged' => 0, 'i_retired' => 0, 'i_failed' => 0,
            'dt_started' => (string)$this->tick, 'dt_finished' => null,
        );

        return count($this->runs);
    }

    public function finishRun(int $runId, array $counts, string $summary): void
    {
        foreach ($counts as $name => $n) {
            $this->runs[$runId - 1]['i_' . $name] = $n;
        }
        $this->runs[$runId - 1]['dt_finished'] = '2026-09-24 10:00:01';
    }

    public function addCounts(int $runId, array $counts): void
    {
        foreach ($counts as $name => $n) {
            $this->runs[$runId - 1]['i_' . $name] += $n;
        }
    }

    public function closeRun(int $runId): void
    {
        $this->runs[$runId - 1]['dt_finished'] = '2026-09-24 10:00:01';
    }

    public function closeIfDone(int $runId): bool
    {
        $r    = $this->runs[$runId - 1];
        $done = $r['i_created'] + $r['i_updated'] + $r['i_unchanged'] + $r['i_retired'] + $r['i_failed'];
        if ($r['dt_finished'] === null && $done >= $r['i_total']) {
            $this->closeRun($runId);

            return true;
        }

        return false;
    }

    /** The clock the map uses: a test moves it to tell one fetch from the next. */
    public int $tick = 0;

    public function unseen(int $sourceId, string $since): array
    {
        $out = array();
        foreach ($this->map as $key => $row) {
            [$source, $externalId] = explode('|', $key, 2);
            if ((int)$source === $sourceId && ($row['e_status'] ?? 'active') === 'active' && ($row['seen_at'] ?? 0) < (int)$since) {
                $out[] = array('external_id' => $externalId, 'item_id' => $row['fk_i_item_id']);
            }
        }

        return $out;
    }

    public function forgetRecord(int $sourceId, string $externalId): void
    {
        unset($this->map[$sourceId . '|' . $externalId]);
    }

    public function retire(int $sourceId, string $externalId): void
    {
        $this->map[$sourceId . '|' . $externalId]['e_status'] = 'retired';
    }

    public array $due = array();
    public array $scheduled = array();

    public function dueSources(string $now): array
    {
        return $this->due;
    }

    public function scheduleNext(int $sourceId, string $next, string $status): void
    {
        $this->scheduled[$sourceId] = $status;
    }

    public function run(int $runId): ?array
    {
        return $this->runs[$runId - 1] ?? null;
    }

    public function recentRuns(int $limit): array
    {
        return array_slice(array_reverse($this->runs), 0, $limit);
    }

    public function runErrors(int $runId, int $limit): array
    {
        $errors = array();
        foreach ($this->logs as [$run, $externalId, $level, $message, $context]) {
            if ($run === $runId && $level === 'error') {
                $errors[] = array('external_id' => $externalId, 'errors' => (array)($context['errors'] ?? array()));
            }
        }

        return array_slice($errors, 0, $limit);
    }

    public function pruneLogs(string $before): int
    {
        return 0;
    }

    public function log(int $runId, int $sourceId, string $externalId, string $level, string $message, array $context = array()): void
    {
        $this->logs[] = array($runId, $externalId, $level, $message, $context);
    }
}

/** Listings in an array; core's refusal can be forced for a title. */
final class MemoryListings implements \mindstellar\listingimport\Import\Listings
{
    public array $items     = array();
    public array $held      = array();
    public bool $moderates  = false;
    public array $limited   = array();
    public string $refuseTitle = 'REFUSE';

    public function exists(int $itemId): bool
    {
        return isset($this->items[$itemId]);
    }

    public function create(array $fields, array $meta, array $photos = array())
    {
        if (in_array($this->refuseTitle, $fields['title'], true)) {
            return 'Title too short.';
        }
        $id               = count($this->items) + 100;
        $this->items[$id] = array('fields' => $fields, 'meta' => $meta, 'edits' => 0, 'photos' => $this->take($photos));

        return $id;
    }

    public function update(int $itemId, array $fields, array $meta, array $photos = array())
    {
        $this->items[$itemId] = array(
            'fields' => $fields,
            'meta'   => $meta,
            'edits'  => $this->items[$itemId]['edits'] + 1,
            'photos' => array_merge($this->items[$itemId]['photos'], $this->take($photos)),
        );

        return true;
    }

    /** Take files over as core does: read them, then delete them. */
    private function take(array $paths): array
    {
        $contents = array();
        foreach ($paths as $path) {
            $contents[] = file_get_contents($path);
            unlink($path);
        }

        return $contents;
    }

    public function hold(int $itemId): void
    {
        $this->held[] = $itemId;
    }

    public array $inactive = array();

    public function delete(int $itemId): bool
    {
        unset($this->items[$itemId]);

        return true;
    }

    public function url(int $itemId): string
    {
        return 'https://site.example/item_i' . $itemId;
    }

    public function deactivate(int $itemId): void
    {
        $this->inactive[$itemId] = true;
    }

    public function activate(int $itemId): void
    {
        unset($this->inactive[$itemId]);
    }

    public function siteModerates(): bool
    {
        return $this->moderates;
    }

    public function canPublish(string $ownerEmail): bool
    {
        return !in_array($ownerEmail, $this->limited, true);
    }
}

/** Images keyed by address: a string is the body, an int is an HTTP status. */
final class FakeImages implements \mindstellar\listingimport\Images\ImageSource, \mindstellar\listingimport\Images\Downloader
{
    public function download(string $url): array
    {
        return $this->fetch($url);
    }

    public array $bodies = array();
    public array $asked  = array();

    public function fetch(string $url): array
    {
        $this->asked[] = $url;
        $body          = $this->bodies[$url] ?? 404;
        if (!is_string($body)) {
            return array('ok' => false, 'error' => 'The server answered ' . $body . '.');
        }
        $path = tempnam(sys_get_temp_dir(), 'import_');
        file_put_contents($path, $body);

        return array('ok' => true, 'path' => $path, 'hash' => hash('sha256', $body));
    }
}
