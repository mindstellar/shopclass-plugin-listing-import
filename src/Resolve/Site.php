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
 * The few site settings the resolver needs, read once so the resolver itself stays pure.
 */
final class Site
{
    /** @var array<int,string> enabled locale codes */
    public array $locales;

    public string $defaultLocale;

    public string $decimalMark;

    public string $contactEmail;

    /**
     * @param array<int,string> $locales
     * @param string            $defaultLocale
     * @param string            $decimalMark
     * @param string            $contactEmail the address used when neither record nor source gives one
     */
    public function __construct(array $locales, string $defaultLocale, string $decimalMark, string $contactEmail)
    {
        $this->locales       = $locales;
        $this->defaultLocale = $defaultLocale;
        $this->decimalMark   = $decimalMark;
        $this->contactEmail  = $contactEmail;
    }

    /**
     * The running site.
     *
     * @return self
     */
    public static function current(): self
    {
        $locales = array();
        foreach (osc_get_locales() as $locale) {
            $locales[] = (string)$locale['pk_c_code'];
        }

        return new self($locales, (string)osc_language(), (string)osc_locale_dec_point(), (string)osc_contact_email());
    }
}
