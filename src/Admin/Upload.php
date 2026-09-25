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

namespace mindstellar\listingimport\Admin;

use mindstellar\listingimport\Feed\FeedReader;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Images\Fetcher;
use mindstellar\listingimport\Import\Source;
use mindstellar\listingimport\Plugin;
use Params;

/**
 * Import a file an admin uploads: it is kept for a preview, then queued as a run of the
 * chosen source. An uploaded file never deactivates the listings it leaves out.
 */
final class Upload
{
    public const ROUTE = 'listing-import-upload';

    /** Starts with the image prefix, so the hourly sweep removes a file nobody imported. */
    private const PREFIX = Fetcher::PREFIX . 'upload_';

    private const MAX_BYTES = 20971520;

    /** File name endings, and the format each one is read as. */
    private const EXTENSIONS = array(
        'json'   => 'json',
        'ndjson' => 'ndjson',
        'jsonl'  => 'ndjson',
        'csv'    => 'csv',
        'xml'    => 'shopclass-rss',
        'rss'    => 'shopclass-rss',
    );

    /**
     * Act on a post to the screen.
     *
     * @return void
     */
    public static function handlePost(): void
    {
        if (Params::getParamString('route') !== self::ROUTE
            || Params::getServerParam('REQUEST_METHOD') !== 'POST'
            || Params::getParamString('li_do') === ''
        ) {
            return;
        }
        osc_csrf_check();
        Guard::post(__('Only an administrator can import listings.', 'listing-import'));

        $token = Params::getParamString('token');
        switch (Params::getParamString('li_do')) {
            case 'upload':
                $error = self::store($token);
                if ($error !== null) {
                    osc_add_flash_error_message($error, 'admin');
                    $token = '';
                }
                osc_redirect_to(osc_route_admin_url(self::ROUTE, $token === '' ? array() : array('token' => $token)));

                return;
            case 'import':
                $file = self::read($token);
                if ($file === null || $file['error'] !== null) {
                    osc_add_flash_error_message(__('That file is gone or could not be read. Upload it again.', 'listing-import'), 'admin');
                    break;
                }
                $runId = Plugin::batch()->queue($file['source'], $file['records'], 'upload');
                self::forget($token);
                osc_add_flash_ok_message(sprintf(
                    __('%1$d records are queued as run %2$d. They import with the next background jobs.', 'listing-import'),
                    count($file['records']),
                    $runId
                ), 'admin');
                osc_redirect_to(osc_route_admin_url(Sources::ROUTE));

                return;
            case 'discard':
                self::forget($token);
                break;
        }
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /**
     * The uploaded file, read as its source reads records.
     *
     * @param string $token
     *
     * @return array{name: string, source: Source, format: string, records: array<int,array<string,mixed>>, error: ?string}|null
     */
    public static function read(string $token): ?array
    {
        $path = self::path($token);
        $meta = $path === null || !is_file($path) ? null : json_decode((string)@file_get_contents($path . '.json'), true);
        if (!is_array($meta)) {
            return null;
        }
        $source = (new DbStore())->source((int)($meta['source_id'] ?? 0));
        if ($source === null) {
            return null;
        }
        $format = (string)($meta['format'] ?? '');

        return array('name' => (string)($meta['name'] ?? ''), 'source' => $source, 'format' => $format)
            + FeedReader::read($path, $format, $source->mapping);
    }

    /**
     * The formats a file may be read as, for the form.
     *
     * @return array<string,string>
     */
    public static function formats(): array
    {
        return array(
            ''              => __('From the file name', 'listing-import'),
            'json'          => __('JSON: a list of records', 'listing-import'),
            'ndjson'        => __('NDJSON: one record per line', 'listing-import'),
            'csv'           => __('CSV: one record per row, a header row naming the fields', 'listing-import'),
            'shopclass-rss' => __('RSS from another Shopclass site', 'listing-import'),
        );
    }

    /**
     * Keep the posted file under a new token, with the source and format it was sent for.
     *
     * @param string $token set to the new token
     *
     * @return string|null why the file was refused
     */
    private static function store(string &$token): ?string
    {
        $file = Params::getFiles('file');
        if (!isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return __('Choose a file to import.', 'listing-import');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || (int)$file['size'] > self::MAX_BYTES) {
            return __('The file is larger than this site accepts.', 'listing-import');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            return __('The file did not arrive. Try again.', 'listing-import');
        }
        if ((new DbStore())->source(Params::getParamInt('source')) === null) {
            return __('Choose a source that is switched on.', 'listing-import');
        }
        $format = Params::getParamString('format');
        if ($format === '') {
            $format = self::EXTENSIONS[strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION))] ?? '';
        }
        if (!isset(self::formats()[$format]) || $format === '') {
            return __('Choose the file\'s format: its name does not say.', 'listing-import');
        }

        $token = bin2hex(random_bytes(16));
        $path  = (string)self::path($token);
        if (!@move_uploaded_file((string)$file['tmp_name'], $path)) {
            return __('The file could not be kept. Check that uploads/temp can be written.', 'listing-import');
        }
        file_put_contents($path . '.json', json_encode(array(
            'name'      => basename((string)$file['name']),
            'source_id' => Params::getParamInt('source'),
            'format'    => $format,
        )));

        return null;
    }

    /**
     * @param string $token
     *
     * @return void
     */
    private static function forget(string $token): void
    {
        $path = self::path($token);
        if ($path !== null) {
            @unlink($path);
            @unlink($path . '.json');
        }
    }

    /**
     * Where a token's file is kept; null for anything that is not a token.
     *
     * @param string $token
     *
     * @return string|null
     */
    private static function path(string $token): ?string
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? Plugin::tempDir() . self::PREFIX . $token : null;
    }
}
