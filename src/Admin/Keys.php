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

use mindstellar\listingimport\Auth\DbKeyRepository;
use mindstellar\listingimport\Auth\KeyStore;
use mindstellar\security\SigningKey;
use Params;
use Session;

/**
 * The API keys screen: its route, its menu entry, and what its three buttons do.
 *
 * Only an administrator reaches it; a moderator neither sees the menu entry nor gets a
 * post through.
 */
final class Keys
{
    public const ROUTE = 'listing-import-keys';

    /** Where a new token waits for the one page load that shows it. */
    private const NEW_TOKEN = 'listing_import_new_token';

    /**
     * @return void
     */
    public static function menu(): void
    {
        osc_admin_menu_plugins(
            __('Listing import: API keys', 'listing-import'),
            osc_route_admin_url(self::ROUTE),
            'listing-import-keys',
            'administrator'
        );
    }

    /**
     * The push source a new key imports into: the one chosen, or else the first. A key always
     * names its source, so it never drifts to another one when sources change.
     *
     * @param int $chosen
     *
     * @return int|null null when the site has no push source
     */
    public static function pushSource(int $chosen): ?int
    {
        $id = osc_db_scalar(
            'SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . "t_listing_import_source WHERE e_kind = 'push'"
            . ' ORDER BY pk_i_id = ? DESC, pk_i_id LIMIT 1',
            array($chosen)
        );

        return $id === null ? null : (int)$id;
    }

    /**
     * Act on a post to the screen, then send the admin back to it.
     *
     * @return void
     */
    public static function handlePost(): void
    {
        if (Params::getParamString('route') !== self::ROUTE
            || Params::getServerParam('REQUEST_METHOD') !== 'POST'
            || Params::getParamString('li_do') === ''
        ) {
            return;
        }
        osc_csrf_check();
        Guard::post(__('Only an administrator can manage API keys.', 'listing-import'));

        $store = self::store();
        switch (Params::getParamString('li_do')) {
            case 'create':
                $name   = trim(Params::getParamString('name'));
                $scopes = array_values(array_intersect(KeyStore::SCOPES, Params::getParamArray('scopes')));
                if ($name === '' || $scopes === array()) {
                    osc_add_flash_error_message(__('A key needs a name and at least one permission.', 'listing-import'), 'admin');
                    break;
                }
                $source = self::pushSource(Params::getParamInt('source'));
                if ($source === null) {
                    osc_add_flash_error_message(__('Add a push source first; a key imports into one.', 'listing-import'), 'admin');
                    break;
                }
                self::showOnce($store->create($name, $scopes, $source));
                break;
            case 'rotate':
                $made = $store->rotate(Params::getParamInt('id'));
                if ($made !== null) {
                    self::showOnce($made);
                }
                break;
            case 'revoke':
                $store->revoke(Params::getParamInt('id'));
                osc_add_flash_ok_message(__('The key was revoked. Requests using it are now refused.', 'listing-import'), 'admin');
                break;
        }

        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /**
     * The token made on the previous request, once. Reading it forgets it.
     *
     * @return string
     */
    public static function takeNewToken(): string
    {
        $token = (string)Session::newInstance()->_get(self::NEW_TOKEN);
        Session::newInstance()->_drop(self::NEW_TOKEN);

        return $token;
    }

    /**
     * @return KeyStore
     */
    public static function store(): KeyStore
    {
        return new KeyStore(new DbKeyRepository(), SigningKey::get());
    }

    /**
     * @param array{token: string} $made
     *
     * @return void
     */
    private static function showOnce(array $made): void
    {
        Session::newInstance()->_set(self::NEW_TOKEN, $made['token']);
    }
}
