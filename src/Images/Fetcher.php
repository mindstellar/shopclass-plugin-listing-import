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

namespace mindstellar\listingimport\Images;

/**
 * Downloads what a partner named -- an image or a feed -- safely.
 *
 * Every address on the way, redirects included, passes the AddressGuard, and the download
 * connects to the address the guard approved. The body stops at a size cap, and what arrives
 * must be a JPEG, PNG, GIF or WebP image. The file lands in the site's temp folder under an
 * `import_` name the hourly sweep removes if nothing else does.
 */
final class Fetcher implements ImageSource, Downloader
{
    public const PREFIX = 'import_';

    public const MAX_REDIRECTS = 3;

    private const TYPES = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP);

    private AddressGuard $guard;

    private Transport $transport;

    private string $dir;

    private int $maxBytes;

    /**
     * @param AddressGuard $guard
     * @param Transport    $transport
     * @param string       $dir      the temp folder, with a trailing slash
     * @param int          $maxBytes the largest image accepted
     */
    public function __construct(AddressGuard $guard, Transport $transport, string $dir, int $maxBytes = 8388608)
    {
        $this->guard     = $guard;
        $this->transport = $transport;
        $this->dir       = $dir;
        $this->maxBytes  = $maxBytes;
    }

    /**
     * Download an image and check it is one.
     *
     * @param string $url
     *
     * @return array{ok: bool, path?: string, hash?: string, error?: string}
     */
    public function fetch(string $url): array
    {
        $got = $this->download($url);
        if (!$got['ok']) {
            return $got;
        }
        $info = @getimagesize($got['path']);
        if ($info === false || !in_array($info[2], self::TYPES, true)) {
            @unlink($got['path']);

            return array('ok' => false, 'error' => 'Not a JPEG, PNG, GIF or WebP image.');
        }

        return $got;
    }

    /**
     * Download any file through the guard, following checked redirects. The caller decides
     * what the file must be, and deletes it.
     *
     * @param string $url
     *
     * @return array{ok: bool, path?: string, hash?: string, error?: string}
     */
    public function download(string $url): array
    {
        $file = $this->dir . self::PREFIX . bin2hex(random_bytes(12));
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $check = $this->guard->check($url);
            if (!$check['ok']) {
                return array('ok' => false, 'error' => $check['error']);
            }
            $got = $this->transport->get($url, $check['ip'], $file, $this->maxBytes);
            if (isset($got['error'])) {
                @unlink($file);

                return array('ok' => false, 'error' => $got['error']);
            }
            if ($got['status'] >= 300 && $got['status'] < 400 && ($got['location'] ?? '') !== '') {
                @unlink($file);
                $url = self::absolute($url, (string)$got['location']);
                continue;
            }
            if ($got['status'] !== 200) {
                @unlink($file);

                return array('ok' => false, 'error' => 'The server answered ' . $got['status'] . '.');
            }

            return array('ok' => true, 'path' => $file, 'hash' => (string)hash_file('sha256', $file));
        }

        return array('ok' => false, 'error' => 'Too many redirects.');
    }

    /**
     * A redirect's target, made absolute against the address that sent it.
     *
     * @param string $base
     * @param string $location
     *
     * @return string
     */
    public static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $p      = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strncmp($location, '//', 2) === 0) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (($location[0] ?? '') === '/') {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string)($p['path'] ?? '/'));

        return $origin . $dir . $location;
    }
}
