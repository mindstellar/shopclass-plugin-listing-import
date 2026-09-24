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

namespace mindstellar\listingimport\Resolve;

/**
 * The site's own data the resolver matches a record against. The database in production;
 * arrays in tests.
 */
interface Lookups
{
    /** An enabled category by id. */
    public function categoryById(int $id): ?int;

    /** An enabled category by its slug in any language. */
    public function categoryBySlug(string $slug): ?int;

    /**
     * An enabled category by its names from the top down, e.g. array('Vehicles', 'Bikes').
     *
     * @param array<int,string> $names
     */
    public function categoryByPath(array $names): ?int;

    /** The one enabled category with this name; null when none or several have it. */
    public function categoryByName(string $name): ?int;

    /** Whether a currency code is enabled on the site. */
    public function currencyEnabled(string $code): bool;

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    public function userById(int $id): ?array;

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    public function userByEmail(string $email): ?array;

    /** A country's code, from its code or its name. */
    public function country(string $codeOrName): ?string;

    /**
     * @return array{id: int, name: string}|null
     */
    public function region(string $countryCode, string $name): ?array;

    /**
     * @return array{id: int, name: string, region_id: int}|null
     */
    public function city(string $countryCode, ?int $regionId, string $name): ?array;

    /** A custom field's id from its slug. */
    public function fieldBySlug(string $slug): ?int;
}
