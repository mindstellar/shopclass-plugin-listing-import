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
 * Downloads any file through the address guard.
 */
interface Downloader
{
    /**
     * @param string $url
     *
     * @return array{ok: bool, path?: string, hash?: string, error?: string}
     */
    public function download(string $url): array;
}
