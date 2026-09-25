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

/*
 * The settings page. Core draws the form, checks CSRF and the admin's capability,
 * validates each field and stores it under the "listing-import" section.
 */

return array(
    'title'  => __('Listing import', 'listing-import'),
    'menu'   => '',
    'intro'  => __('Settings that apply to every import source.', 'listing-import'),
    'help'   => __('The request limit applies to each API key. Log lines older than the days you keep are deleted once a day.', 'listing-import'),
    'groups' => array(
        array(
            'title'  => __('Limits', 'listing-import'),
            'fields' => array(
                array(
                    'type'    => 'number',
                    'name'    => 'rate_limit',
                    'label'   => __('Requests per minute, per key', 'listing-import'),
                    'help'    => __('A key that sends more is told to wait and try again.', 'listing-import'),
                    'default' => 60,
                    'min'     => 1,
                    'max'     => 6000,
                ),
                array(
                    'type'    => 'number',
                    'name'    => 'retention_days',
                    'label'   => __('Keep import logs for (days)', 'listing-import'),
                    'help'    => __('Older log lines are deleted once a day.', 'listing-import'),
                    'default' => 30,
                    'min'     => 1,
                    'max'     => 3650,
                ),
            ),
        ),
    ),
);
