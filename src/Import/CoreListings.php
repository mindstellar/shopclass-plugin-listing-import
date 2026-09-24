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

use ItemActions;
use mindstellar\billing\Entitlements;
use Params;

/**
 * Listings created and changed through core's own ItemActions.
 *
 * The importer fills in the same fields core's listing form posts, then asks core to read
 * them with prepareData() and save them with add() or edit(). So an imported listing gets
 * exactly what a posted one gets: validation, spam checks, location rows, expiry from its
 * category, custom fields, stats and hooks. Nothing here writes a listing table itself.
 *
 * It acts as an admin, which is what skips core's per-address posting wait and the e-mails
 * a posted listing sends. The listing limit and moderation, which that also skips, are
 * applied by the importer instead.
 */
final class CoreListings implements Listings
{
    /** Every form field the importer sets, so each record starts from a clean request. */
    private const FIELDS = array(
        'title', 'description', 'catId', 'price', 'currency', 'contactName', 'contactEmail',
        'contactPhone', 'showEmail', 'countryId', 'country', 'regionId', 'region', 'cityId',
        'city', 'cityArea', 'address', 'zip', 'd_coord_lat', 'd_coord_long', 'dt_expiration',
        'id', 'secret', 'itemId', 'meta',
    );

    public function exists(int $itemId): bool
    {
        return osc_db_scalar('SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ?', array($itemId)) !== null;
    }

    public function create(array $fields, array $meta, array $photos = array())
    {
        $this->fill($fields);
        try {
            $actions = new ItemActions(true);
            $actions->prepareData(true);
            $actions->data['meta']   = $meta;
            $actions->data['photos'] = self::files($photos);
            $result = $actions->add();
            $itemId = Params::getParamInt('itemId');
        } finally {
            $this->clear();
        }

        return ($result === 1 || $result === 2) && $itemId > 0 ? $itemId : (string)$result;
    }

    public function update(int $itemId, array $fields, array $meta, array $photos = array())
    {
        $secret = osc_db_scalar('SELECT s_secret FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ?', array($itemId));
        $this->fill($fields + array('id' => $itemId, 'secret' => (string)$secret));
        try {
            $actions = new ItemActions(true);
            $actions->prepareData(false);
            $actions->data['meta']   = $meta;
            $actions->data['photos'] = self::files($photos);
            $result = $actions->edit();
        } finally {
            $this->clear();
        }

        return is_string($result) ? $result : ($result === false ? 'The listing could not be saved.' : true);
    }

    public function deactivate(int $itemId): void
    {
        (new ItemActions(true))->deactivate($itemId);
    }

    public function activate(int $itemId): void
    {
        (new ItemActions(true))->activate($itemId);
    }

    public function hold(int $itemId): void
    {
        (new ItemActions(true))->disable($itemId);
    }

    public function siteModerates(): bool
    {
        return (bool)osc_moderate_admin_post();
    }

    public function canPublish(string $ownerEmail): bool
    {
        if (!osc_billing_enabled()) {
            return true;
        }
        $userId = osc_db_scalar('SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . 't_user WHERE s_email = ?', array($ownerEmail));

        return $userId === null || Entitlements::canPublish((int)$userId);
    }

    /**
     * Files in the shape core reads an upload from. Core re-encodes each one, applies the
     * site's photo limit for the owner, makes its sizes and deletes the file.
     *
     * @param array<int,string> $paths
     *
     * @return array<string,array<int,mixed>>
     */
    private static function files(array $paths): array
    {
        $files = array('name' => array(), 'type' => array(), 'tmp_name' => array(), 'error' => array(), 'size' => array());
        foreach ($paths as $path) {
            $files['name'][]     = basename($path);
            $files['type'][]     = 'image/*';
            $files['tmp_name'][] = $path;
            $files['error'][]    = UPLOAD_ERR_OK;
            $files['size'][]     = (int)filesize($path);
        }

        return $files;
    }

    /**
     * @param array<string,mixed> $fields
     *
     * @return void
     */
    private function fill(array $fields): void
    {
        $this->clear();
        foreach ($fields as $name => $value) {
            Params::setParam($name, $value);
        }
    }

    /**
     * @return void
     */
    private function clear(): void
    {
        foreach (self::FIELDS as $name) {
            Params::unsetParam($name);
        }
    }
}
