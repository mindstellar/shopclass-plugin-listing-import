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

namespace mindstellar\listingimport\Admin;

use mindstellar\listingimport\Plugin;

/**
 * The plugin's place in the admin: one group under Plugins, and the title, help and header
 * actions core draws around each of its screens.
 */
final class Menu
{
    public const HELP_ROUTE = 'listing-import-help';

    /**
     * The group under Plugins: the admin_menu_init hook.
     *
     * @return void
     */
    public static function register(): void
    {
        osc_add_admin_submenu_divider('plugins', __('Listing import', 'listing-import'), 'listing-import', 'administrator');
        foreach (array(
            'listing-import-sources'  => array(__('Sources', 'listing-import'), osc_route_admin_url(Sources::ROUTE)),
            'listing-import-upload'   => array(__('Import a file', 'listing-import'), osc_route_admin_url(Upload::ROUTE)),
            'listing-import-settings' => array(__('Settings', 'listing-import'), osc_settings_page_url(Plugin::PAGE)),
            'listing-import-help'     => array(__('Help', 'listing-import'), osc_route_admin_url(self::HELP_ROUTE)),
        ) as $id => [$title, $url]) {
            osc_add_admin_submenu_page('plugins', $title, $url, $id, 'administrator');
        }
    }

    /**
     * Declare each screen's page header for core to draw.
     *
     * @return void
     */
    public static function pages(): void
    {
        $settings = array('icon' => 'bi-gear-fill', 'url' => osc_settings_page_url(Plugin::PAGE), 'title' => __('Settings', 'listing-import'));

        osc_admin_plugin_page(Sources::ROUTE, array(
            'title'   => __('Import sources', 'listing-import'),
            'help'    => self::help(__('A push source takes listings a partner sends with an API key. A pull source fetches a JSON, CSV or RSS feed on a schedule. Imported listings follow the site\'s own rules.', 'listing-import')),
            'actions' => array(
                array('icon' => 'bi-plus-circle-fill', 'url' => osc_route_admin_url(Sources::EDIT_ROUTE), 'title' => __('Add source', 'listing-import')),
                array('icon' => 'bi-upload', 'url' => osc_route_admin_url(Upload::ROUTE), 'title' => __('Import a file', 'listing-import')),
                $settings,
            ),
        ));
        osc_admin_plugin_page(Sources::EDIT_ROUTE, array(
            'title' => __('Import source', 'listing-import'),
            'help'  => self::help(__('Defaults fill in what a record leaves out. Rules decide whether new listings wait for an admin and count against the owner\'s listing limit.', 'listing-import')),
        ));
        osc_admin_plugin_page(Sources::PREVIEW_ROUTE, array(
            'title' => __('Feed preview', 'listing-import'),
            'help'  => self::help(__('A preview fetches the feed and shows what an import would do. It changes nothing.', 'listing-import')),
        ));
        osc_admin_plugin_page(Upload::ROUTE, array(
            'title' => __('Import a file', 'listing-import'),
            'help'  => self::help(__('Upload a file of records and see how each would be imported. Nothing changes until you import. Listings the file leaves out are not touched.', 'listing-import')),
        ));
        osc_admin_plugin_page(self::HELP_ROUTE, array(
            'title' => __('Listing import help', 'listing-import'),
        ));
    }

    /**
     * A screen's help box: its own line, and the way to the full guide.
     *
     * @param string $text
     *
     * @return callable
     */
    private static function help(string $text): callable
    {
        return static function () use ($text) {
            echo '<p>' . osc_esc_html($text) . '</p><p><a href="' . osc_esc_html(osc_route_admin_url(self::HELP_ROUTE)) . '">'
                . osc_esc_html(__('Read the full guide', 'listing-import')) . '</a></p>';
        };
    }
}
