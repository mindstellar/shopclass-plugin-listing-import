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
 * One HTTP GET, connected to an address already checked. cURL in production.
 */
interface Transport
{
    /**
     * @param string $url      the address to request
     * @param string $ip       the IP the connection must use
     * @param string $file     where the body is written
     * @param int    $maxBytes stop past this many bytes
     *
     * @return array{status?: int, location?: string, error?: string, unreachable?: bool} unreachable when no
     *         connection was made, so another address of the host may be tried
     */
    public function get(string $url, string $ip, string $file, int $maxBytes): array;
}
