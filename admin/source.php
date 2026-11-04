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

use mindstellar\listingimport\Admin\Guard;
use mindstellar\listingimport\Admin\SourceForm;
use mindstellar\listingimport\Admin\Sources;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Guard::view(__('Only an administrator can manage import sources.', 'listing-import'))) {
    return;
}

$id     = Sources::editId();
$page   = SourceForm::register($id);
$values = Sources::$retry ?? osc_settings_values($page, $id);

osc_admin_page_head(Sources::editTitle(), array(
    array('label' => __('Back to sources', 'listing-import'), 'url' => osc_route_admin_url(Sources::ROUTE), 'variant' => 'dim'),
));
?>
<ul id="error_list"></ul>
<?php
osc_admin_settings_form($page, SourceForm::formVars($id, $values));
