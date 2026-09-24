<?php
/*
Plugin Name: Listing Import
Plugin URI: https://github.com/mindstellar/shopclass-plugin-listing-import
Description: Import listings from other systems through a keyed REST API or scheduled JSON, CSV and RSS feeds. Every listing goes through the same checks as one posted by hand.
Version: 0.1.0
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: listing-import
Requires Shopclass: 6.4.0
Tested up to: 6.4
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-listing-import/issues
*/

/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\listingimport\Admin\Keys;
use mindstellar\listingimport\Api;
use mindstellar\listingimport\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\listingimport\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

// An update ships new migrations; they run on the first request after it.
osc_add_hook('init', array(Plugin::class, 'upgrade'));

osc_add_route_hook(Plugin::ROUTE, 'api/v1/(.+)', 'api/v1/{path}');
osc_add_hook(Plugin::ROUTE, array(Api::class, 'handle'));

// The API keys screen.
osc_add_route(Keys::ROUTE, 'listing-import/keys', 'listing-import/keys', osc_plugin_folder(__FILE__) . 'admin/keys.php');
osc_add_hook('admin_menu_init', array(Keys::class, 'menu'));
osc_add_hook('init_admin', array(Keys::class, 'handlePost'));
