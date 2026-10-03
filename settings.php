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
    'help'   => __('Log lines older than the days you keep are deleted once a day. API keys and their request limits are under Settings > API.', 'listing-import'),
    'groups' => array(
        array(
            'title'  => __('Logs', 'listing-import'),
            'fields' => array(
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
