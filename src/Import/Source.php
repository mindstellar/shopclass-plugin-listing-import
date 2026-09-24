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
 * Where listings come from, and the rules for them: the defaults that fill a record's gaps
 * and the policy that decides whether a new listing goes live.
 */
final class Source
{
    /** Go live, unless core itself holds the listing (spam, blocked words). */
    public const STATUS_ACTIVE = 'active';

    /** Always wait for an admin. */
    public const STATUS_PENDING = 'pending';

    /** Wait for an admin when the site holds new listings for moderation. */
    public const STATUS_SITE = 'site';

    public int $id;

    public string $name;

    /** @var array<string,mixed> */
    public array $defaults;

    /** @var array<string,mixed> */
    public array $policy;

    /**
     * @param int                 $id
     * @param string              $name
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $policy
     */
    public function __construct(int $id, string $name, array $defaults = array(), array $policy = array())
    {
        $this->id       = $id;
        $this->name     = $name;
        $this->defaults = $defaults;
        $this->policy   = $policy + array('status' => self::STATUS_SITE, 'respect_caps' => true, 'missing' => self::MISSING_DEACTIVATE);
    }

    /** Keep a listing that left its feed. */
    public const MISSING_KEEP = 'keep';

    /** Deactivate a listing that left its feed. Never deleted. */
    public const MISSING_DEACTIVATE = 'deactivate';

    public string $kind = 'push';

    public string $url = '';

    public string $format = 'json';

    public string $mapping = '';

    public int $interval = 360;

    /**
     * @param array<string,mixed> $row a t_listing_import_source row
     *
     * @return self
     */
    public static function fromRow(array $row): self
    {
        $source = new self(
            (int)$row['pk_i_id'],
            (string)$row['s_name'],
            array_filter(array(
                'category'      => (int)$row['fk_i_category_id'],
                'country'       => (string)$row['fk_c_country_code'],
                'currency'      => (string)$row['fk_c_currency_code'],
                'locale'        => (string)$row['fk_c_locale_code'],
                'owner_user_id' => (int)$row['fk_i_owner_id'],
                'contact_name'  => (string)$row['s_contact_name'],
                'contact_email' => (string)$row['s_contact_email'],
            ), static fn ($value) => $value !== null && $value !== '' && $value !== 0),
            array(
                'status'       => (string)$row['e_status'],
                'respect_caps' => (int)$row['b_respect_caps'] === 1,
                'missing'      => (string)$row['e_missing'],
            )
        );
        $source->kind     = (string)$row['e_kind'];
        $source->url      = (string)$row['s_url'];
        $source->format   = (string)$row['s_format'];
        $source->mapping  = (string)($row['s_mapping'] ?? '');
        $source->interval = max(60, (int)$row['i_interval_minutes']);

        return $source;
    }
}
