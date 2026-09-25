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
use mindstellar\listingimport\Admin\PreviewTable;
use mindstellar\listingimport\Admin\Sources;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Guard::view(__('Only an administrator can manage import sources.', 'listing-import'))) {
    return;
}

$source = (new DbStore())->source(Params::getParamInt('id'));
osc_admin_page_head(__('Feed preview', 'listing-import'), array(
    array('label' => __('Back to sources', 'listing-import'), 'url' => osc_route_admin_url(Sources::ROUTE), 'variant' => 'dim'),
));
if ($source === null || $source->kind !== 'pull') {
    osc_admin_empty(array('icon' => 'bi-rss', 'title' => __('That source has no feed to preview.', 'listing-import')));

    return;
}

// A dry run: the feed is fetched and each record is placed on this site, but nothing is written.
$read = Plugin::pull()->read($source);
osc_admin_form_section($source->name, array(
    'spaced' => true,
    'intro'  => $read['error'] === null
        ? sprintf(__('%1$d records in the feed. The first %2$d, as they would be imported. Nothing has been changed.', 'listing-import'), count($read['records']), min(20, count($read['records'])))
        : __('The feed could not be read.', 'listing-import'),
));
if ($read['error'] !== null) {
    osc_admin_empty(array('icon' => 'bi-exclamation-triangle', 'title' => $read['error']));

    return;
}
PreviewTable::render($source, $read['records']);
