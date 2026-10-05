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
use mindstellar\billing\EntitlementStore;
use mindstellar\storage\UploadMimes;
use Params;

/**
 * Listings created and changed through core's own ItemActions.
 *
 * The importer hands core the same fields its listing form posts, through prepareDataFrom(),
 * and core saves them with add() or edit(). So an imported listing gets exactly what a posted
 * one gets: validation, spam checks, location rows, expiry from its category, custom fields,
 * stats and hooks. Nothing here writes a listing table itself.
 *
 * It acts as an admin, which skips core's per-address posting wait and the e-mails a posted
 * listing sends. Each source decides on the listing limit and moderation, so the importer
 * applies those itself rather than through core's import mode.
 */
final class CoreListings implements Listings
{
    public function exists(int $itemId): bool
    {
        return osc_db_scalar('SELECT 1 FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ?', array($itemId)) !== null;
    }

    public function create(array $fields, array $meta, array $photos = array())
    {
        $actions = new ItemActions(true);
        $actions->prepareDataFrom(array('meta' => $meta, 'photos' => $photos) + $fields, true);
        $result = $actions->add();
        $itemId = Params::getParamInt('itemId');

        return ($result === 1 || $result === 2) && $itemId > 0 ? $itemId : (string)$result;
    }

    public function update(int $itemId, array $fields, array $meta, array $photos = array())
    {
        $actions = new ItemActions(true);
        $actions->prepareDataFrom(array('id' => $itemId, 'meta' => $meta, 'photos' => $photos) + $fields, false);
        $result = $actions->edit();

        return is_string($result) ? $result : ($result === false ? 'The listing could not be saved.' : true);
    }

    public function delete(int $itemId): bool
    {
        $secret = osc_db_scalar('SELECT s_secret FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ?', array($itemId));
        if ($secret === null) {
            return false;
        }

        return (bool)(new ItemActions(true))->delete((string)$secret, $itemId);
    }

    public function url(int $itemId): string
    {
        return (string)osc_item_url_ns($itemId);
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

    public function canPublish(int $ownerId): bool
    {
        return !osc_billing_enabled() || $ownerId <= 0 || EntitlementStore::canPublish($ownerId);
    }

    public function refuseImage(string $path): ?string
    {
        if (!UploadMimes::isAllowedImage($path)) {
            return 'This site does not accept that image type.';
        }
        if ((int)filesize($path) > osc_max_size_kb() * 1024) {
            return 'Larger than this site accepts (' . osc_max_size_kb() . ' KB).';
        }

        return null;
    }
}
