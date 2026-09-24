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
 * Where the importer gets a record's images from.
 */
interface ImageSource
{
    /**
     * @param string $url
     *
     * @return array{ok: bool, path?: string, hash?: string, error?: string}
     */
    public function fetch(string $url): array;
}
