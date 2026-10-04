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

namespace mindstellar\listingimport;

use mindstellar\security\AddressGuard;
use mindstellar\listingimport\Images\CurlTransport;
use mindstellar\listingimport\Images\Fetcher;
use mindstellar\listingimport\Import\Batch;
use mindstellar\listingimport\Import\CoreListings;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Import\Importer;
use mindstellar\listingimport\Import\Pull;
use mindstellar\listingimport\Resolve\DbLookups;
use mindstellar\listingimport\Resolve\Resolver;
use mindstellar\listingimport\Resolve\Site;

/**
 * The plugin's names and its install, upgrade and uninstall steps.
 */
final class Plugin
{
    /** Settings page id, and the preference section its values are stored under. */
    public const PAGE = 'listing-import';

    /** The route hook every API request arrives on. */
    public const ROUTE = 'listing-import-api';

    public const VERSION = '0.2.1';

    /**
     * Create the tables.
     *
     * @return void
     */
    public static function install(): void
    {
        self::migrate();
    }

    /**
     * Run migrations an update added. A preference holds the schema version, so a request
     * with nothing to do costs one cached preference read.
     *
     * @return void
     */
    public static function upgrade(): void
    {
        if ((int)osc_get_preference('schema', self::PAGE) >= Schema::VERSION) {
            return;
        }
        self::migrate();
    }

    /**
     * Drop the tables and every setting. Uninstall means the data goes too.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        Schema::drop();
        foreach (array('schema', 'retention_days', 'rate_limit') as $name) {
            osc_delete_preference($name, self::PAGE);
        }
    }

    /**
     * The importer as the site runs it: its own tables, core's listing save, and images
     * fetched through the address guard.
     *
     * @return Importer
     */
    public static function importer(): Importer
    {
        return new Importer(
            new Resolver(new DbLookups(), Site::current()),
            new CoreListings(),
            new DbStore(),
            new Fetcher(new AddressGuard(), new CurlTransport(10), self::tempDir())
        );
    }

    /**
     * @return Batch
     */
    public static function batch(): Batch
    {
        return new Batch(self::importer(), new DbStore(), new CoreListings());
    }

    /**
     * Feeds are downloaded through the same guard as images, up to 20 MB.
     *
     * @return Pull
     */
    public static function pull(): Pull
    {
        return new Pull(
            new Fetcher(new AddressGuard(), new CurlTransport(30), self::tempDir(), 20971520),
            self::batch(),
            new DbStore()
        );
    }

    /**
     * Queue a fetch for every feed that is due. Runs each hour.
     *
     * @return void
     */
    public static function schedule(): void
    {
        self::pull()->schedule();
    }

    /**
     * Tell core's queue who runs a queued record. Registered on `register_jobs`, so the
     * handler exists in the cron request that runs the job.
     *
     * @return void
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(Batch::JOB, static function ($job): void {
            self::batch()->work($job->payload());
        });
        osc_job_register_handler(Pull::JOB, static function ($job): void {
            $source = (new DbStore())->source((int)$job->get('source_id'));
            if ($source !== null) {
                self::pull()->fetch($source);
            }
        });
    }

    /**
     * Delete log lines older than the retention setting. Runs once a day.
     *
     * @return void
     */
    public static function prune(): void
    {
        $days = max(1, (int)(osc_get_preference('retention_days', self::PAGE) ?: 30));
        (new DbStore())->pruneLogs(date('Y-m-d H:i:s', time() - $days * 86400));
    }

    /**
     * The site's temp folder, where downloaded images wait for core.
     *
     * @return string
     */
    public static function tempDir(): string
    {
        return osc_content_path() . 'uploads/temp/';
    }

    /**
     * Delete downloaded images nothing took, two hours on: the same window core gives its own
     * uploads.
     *
     * @return void
     */
    public static function sweep(): void
    {
        foreach (glob(self::tempDir() . \mindstellar\listingimport\Images\Fetcher::PREFIX . '*') ?: array() as $file) {
            if (is_file($file) && time() - (int)filemtime($file) > 7200) {
                @unlink($file);
            }
        }
    }

    /**
     * @return void
     */
    private static function migrate(): void
    {
        $result = Schema::migrate();
        if ($result['ok']) {
            osc_set_preference('schema', (string)Schema::VERSION, self::PAGE, 'INTEGER');

            return;
        }
        trigger_error(
            'Listing Import: migration ' . $result['failed'] . ' failed: ' . $result['error'],
            E_USER_WARNING
        );
    }
}
