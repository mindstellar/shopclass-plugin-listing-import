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

/**
 * The plugin's screens are for administrators only; moderators are turned away.
 */
final class Guard
{
    /**
     * For a screen: whether to draw it. When not, it says why instead.
     *
     * @param string $message
     *
     * @return bool
     */
    public static function view(string $message): bool
    {
        if (osc_is_moderator()) {
            osc_admin_empty(array('icon' => 'bi-shield-lock', 'title' => $message));

            return false;
        }

        return true;
    }

    /**
     * For a post: stop a moderator with a message.
     *
     * @param string $message
     *
     * @return void
     */
    public static function post(string $message): void
    {
        if (osc_is_moderator()) {
            osc_add_flash_error_message($message, 'admin');
            osc_redirect_to(osc_admin_base_url(true));
        }
    }
}
