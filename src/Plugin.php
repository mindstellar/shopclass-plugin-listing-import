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

/**
 * The plugin's names and its install, upgrade and uninstall steps.
 */
final class Plugin
{
    /** Settings page id, and the preference section its values are stored under. */
    public const PAGE = 'listing-import';

    /** The route hook every API request arrives on. */
    public const ROUTE = 'listing-import-api';

    public const VERSION = '0.1.0';

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
