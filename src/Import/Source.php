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
        $this->policy   = $policy + array('status' => self::STATUS_SITE, 'respect_caps' => true);
    }

    /**
     * @param array<string,mixed> $row a t_listing_import_source row
     *
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['pk_i_id'],
            (string)$row['s_name'],
            json_decode((string)($row['s_defaults'] ?? ''), true) ?: array(),
            json_decode((string)($row['s_policy'] ?? ''), true) ?: array()
        );
    }
}
