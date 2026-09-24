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

/**
 * The site's listings, as the importer changes them. Core's ItemActions in production; an
 * array in tests.
 */
interface Listings
{
    /** Whether a listing still exists. */
    public function exists(int $itemId): bool;

    /**
     * Create a listing from form fields.
     *
     * @param array<string,mixed> $fields the fields core's listing form posts
     * @param array<int,mixed>    $meta   custom field id => value
     * @param array<int,string>   $photos image files to attach; core takes them over
     *
     * @return int|string the new listing's id, or why core refused it
     */
    public function create(array $fields, array $meta, array $photos = array());

    /**
     * @param int                 $itemId
     * @param array<string,mixed> $fields
     * @param array<int,mixed>    $meta
     * @param array<int,string>   $photos image files to add to the listing
     *
     * @return true|string true, or why core refused it
     */
    public function update(int $itemId, array $fields, array $meta, array $photos = array());

    /** Hold a listing for an admin to review. */
    public function hold(int $itemId): void;

    /** Whether the site holds new listings for admin moderation. */
    public function siteModerates(): bool;

    /**
     * Whether the owner of this address may publish one more listing, when the site limits
     * listings. An address with no account is never limited.
     */
    public function canPublish(string $ownerEmail): bool;
}
