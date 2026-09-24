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

use mindstellar\listingimport\Admin\SourceForm;
use mindstellar\listingimport\Admin\Sources;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (osc_is_moderator()) {
    osc_admin_empty(array('icon' => 'bi-shield-lock', 'title' => __('Only an administrator can manage import sources.', 'listing-import')));

    return;
}

// The id is the route's; a row that does not exist is an add.
$id = Params::getParamInt('id') ?: null;
if ($id !== null && osc_db_scalar('SELECT 1 FROM ' . DB_TABLE_PREFIX . SourceForm::TABLE . ' WHERE pk_i_id = ?', array($id)) === null) {
    $id = null;
}
$page   = SourceForm::register($id);
$values = Sources::$retry ?? osc_settings_values($page, $id);

osc_admin_page_head($id === null ? __('Add import source', 'listing-import') : __('Edit import source', 'listing-import'), array(
    array('label' => __('Back to sources', 'listing-import'), 'url' => osc_route_admin_url(Sources::ROUTE), 'variant' => 'dim'),
));
?>
<ul id="error_list"></ul>
<?php
osc_admin_settings_form($page, SourceForm::formVars($id, $values));
