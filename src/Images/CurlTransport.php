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
 * An HTTP GET with cURL, pinned to one IP.
 *
 * CURLOPT_RESOLVE makes cURL connect to the approved IP whatever DNS says by the time it
 * connects. cURL follows no redirect itself: the Fetcher checks each one first.
 */
final class CurlTransport implements Transport
{
    private int $timeout;

    /**
     * @param int $timeout seconds for the whole request
     */
    public function __construct(int $timeout = 20)
    {
        $this->timeout = $timeout;
    }

    public function get(string $url, string $ip, string $file, int $maxBytes): array
    {
        $parts = parse_url($url);
        $host  = trim((string)($parts['host'] ?? ''), '[]');
        $port  = (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
        $out   = @fopen($file, 'wb');
        if ($out === false) {
            return array('error' => 'The temp folder is not writable.');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RESOLVE          => array($host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)),
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION   => false,
            CURLOPT_FILE             => $out,
            CURLOPT_CONNECTTIMEOUT   => 5,
            CURLOPT_TIMEOUT          => $this->timeout,
            CURLOPT_MAXFILESIZE      => $maxBytes,
            CURLOPT_NOPROGRESS       => false,
            // A server can send no length, or a false one; this stops the body at the cap anyway.
            CURLOPT_XFERINFOFUNCTION => static fn ($c, $total, $now) => $now > $maxBytes ? 1 : 0,
            CURLOPT_USERAGENT        => 'Shopclass-ListingImport/1.0',
            CURLOPT_HTTPHEADER       => array('Accept: */*'),
        ));
        $ok       = curl_exec($curl);
        $status   = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $location = (string)curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        $errno    = curl_errno($curl);
        $linked   = (float)curl_getinfo($curl, CURLINFO_CONNECT_TIME) > 0;
        fclose($out);

        if ($ok === false && !$linked && in_array($errno, array(CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT), true)) {
            return array('error' => 'The server could not be reached.', 'unreachable' => true);
        }
        if ($ok === false) {
            if (in_array($errno, array(CURLE_FILESIZE_EXCEEDED, CURLE_ABORTED_BY_CALLBACK), true)) {
                return array('error' => 'The file is larger than the limit.');
            }

            return array('error' => $errno === CURLE_OPERATION_TIMEDOUT
                ? 'The server did not answer in time.'
                : 'The file could not be downloaded.');
        }

        return array('status' => $status, 'location' => $location);
    }
}
