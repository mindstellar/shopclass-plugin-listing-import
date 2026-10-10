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

use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Import\Pull;
use Params;

/**
 * The import sources screens: the list, the declared editor, and a preview of a feed.
 * Only an administrator reaches them.
 */
final class Sources
{
    public const ROUTE = 'listing-import-sources';

    public const EDIT_ROUTE = 'listing-import-sources-edit';

    public const PREVIEW_ROUTE = 'listing-import-sources-preview';

    /** @var array<string,mixed>|null a refused save's values, for the editor to show again */
    public static ?array $retry = null;

    /**
     * Act on a post to one of the screens.
     *
     * @return void
     */
    public static function handlePost(): void
    {
        $route = Params::getParamString('route');
        if (!in_array($route, array(self::ROUTE, self::EDIT_ROUTE), true)
            || Params::getServerParam('REQUEST_METHOD') !== 'POST'
            || Params::getParamString('li_do') === ''
        ) {
            return;
        }
        osc_csrf_check();
        Guard::post(__('Only an administrator can manage import sources.', 'listing-import'));

        $id = Params::getParamInt('id') ?: null;
        switch (Params::getParamString('li_do')) {
            case 'save':
                self::save($id);

                return;
            case 'fetch':
                $source = $id === null ? null : (new DbStore())->source($id);
                if ($source === null || $source->kind !== 'pull') {
                    osc_add_flash_error_message(__('That source has no feed to fetch.', 'listing-import'), 'admin');
                    break;
                }
                osc_job_enqueue(Pull::JOB, array('source_id' => $source->id));
                osc_add_flash_ok_message(__('The fetch is queued. It runs with the next background jobs.', 'listing-import'), 'admin');
                break;
            case 'delete':
                self::delete((int)$id);
                break;
        }
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /**
     * Every source, for the list.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return osc_db_select(
            'SELECT s.*, (SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_listing_import_item i WHERE i.fk_i_source_id = s.pk_i_id'
            . " AND i.e_status = 'active' AND i.fk_i_item_id IS NOT NULL) AS i_listings,"
            . " (CASE WHEN s.s_key_ids = '' THEN 0 ELSE LENGTH(s.s_key_ids) - LENGTH(REPLACE(s.s_key_ids, ',', '')) + 1 END) AS i_keys"
            . ' FROM ' . DB_TABLE_PREFIX . 't_listing_import_source s ORDER BY s.pk_i_id'
        );
    }

    /**
     * @param int|null $id
     *
     * @return void
     */
    private static function save(?int $id): void
    {
        $result = osc_settings_save(SourceForm::register($id), $id);
        if ($result['errors'] !== array()) {
            foreach ($result['errors'] as $error) {
                osc_add_flash_error_message($error, 'admin');
            }
            // No redirect: the editor draws this request's values again.
            self::$retry = $result['values'];

            return;
        }
        osc_add_flash_ok_message(__('The source is saved.', 'listing-import'), 'admin');
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /**
     * Delete a source. Its listings stay; only the record of where they came from goes. The
     * last push source cannot be deleted.
     *
     * @param int $id
     *
     * @return void
     */
    private static function delete(int $id): void
    {
        $p      = DB_TABLE_PREFIX . 't_listing_import_';
        $kind   = osc_db_scalar('SELECT e_kind FROM ' . $p . 'source WHERE pk_i_id = ?', array($id));
        $pushes = (int)osc_db_scalar('SELECT COUNT(*) FROM ' . $p . "source WHERE e_kind = 'push'");
        if ($kind === 'push' && $pushes === 1) {
            osc_add_flash_error_message(__('The last push source cannot be deleted.', 'listing-import'), 'admin');

            return;
        }
        foreach (array('item', 'log', 'run') as $table) {
            osc_db_execute('DELETE FROM ' . $p . $table . ' WHERE fk_i_source_id = ?', array($id));
        }
        osc_db_execute('DELETE FROM ' . $p . 'source WHERE pk_i_id = ?', array($id));
        osc_add_flash_ok_message(__('The source is deleted. Its listings stay on the site.', 'listing-import'), 'admin');
    }
}
