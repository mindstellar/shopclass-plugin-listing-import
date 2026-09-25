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
use mindstellar\listingimport\Admin\Upload;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Guard::view(__('Only an administrator can import listings.', 'listing-import'))) {
    return;
}

$selfUrl = osc_route_admin_url(Upload::ROUTE);
$token   = Params::getParamString('token');
$file    = $token === '' ? null : Upload::read($token);

osc_admin_page_head(__('Import a file', 'listing-import'));

if ($file === null) {
    $sources = array_column(osc_db_select(
        'SELECT pk_i_id, s_name FROM ' . DB_TABLE_PREFIX . 't_listing_import_source WHERE b_enabled = 1 ORDER BY pk_i_id'
    ), 's_name', 'pk_i_id');
    if ($sources === array()) {
        osc_admin_empty(array(
            'icon'  => 'bi-box-arrow-in-down',
            'title' => __('Add a source first', 'listing-import'),
            'text'  => __('A file is imported into a source, which gives its listings their defaults and rules.', 'listing-import'),
        ));

        return;
    }
    osc_admin_form_open(array(
        'url'    => $selfUrl,
        'upload' => true,
        'fields' => array('li_do' => 'upload'),
    ));
    osc_admin_field(array(
        'type'  => 'file',
        'name'  => 'file',
        'label' => __('File', 'listing-import'),
        'help'  => __('JSON, NDJSON, CSV, or RSS from another Shopclass site. Up to 20 MB and 5,000 records.', 'listing-import'),
        'attrs' => array('accept' => '.json,.ndjson,.jsonl,.csv,.xml,.rss', 'required' => true),
    ));
    osc_admin_field(array(
        'type'    => 'select',
        'name'    => 'format',
        'label'   => __('Format', 'listing-import'),
        'options' => Upload::formats(),
    ));
    osc_admin_field(array(
        'type'    => 'select',
        'name'    => 'source',
        'label'   => __('Import into', 'listing-import'),
        'options' => array_map('strval', $sources),
        'help'    => __('The source whose defaults, rules and field names the records follow. Listings the file leaves out are not touched.', 'listing-import'),
    ));
    osc_admin_form_close(array(
        array('label' => __('Preview', 'listing-import'), 'type' => 'submit', 'variant' => 'primary'),
    ));

    return;
}

osc_admin_form_section($file['name'], array(
    'spaced' => true,
    'intro'  => $file['error'] === null
        ? sprintf(
            __('%1$d records, read as %2$s, into "%3$s". The first %4$d, as they would be imported. Nothing has been changed yet.', 'listing-import'),
            count($file['records']),
            Upload::formats()[$file['format']] ?? $file['format'],
            $file['source']->name,
            min(PreviewTable::LIMIT, count($file['records']))
        )
        : __('The file could not be read.', 'listing-import'),
));
if ($file['error'] !== null) {
    osc_admin_empty(array('icon' => 'bi-exclamation-triangle', 'title' => $file['error']));
} else {
    PreviewTable::render($file['source'], $file['records']);
}

osc_admin_form_open(array(
    'url'    => $selfUrl,
    'fields' => array('token' => $token),
));
$actions = array(array('label' => __('Upload another file', 'listing-import'), 'type' => 'submit', 'variant' => 'dim', 'attrs' => array('name' => 'li_do', 'value' => 'discard')));
if ($file['error'] === null && $file['records'] !== array()) {
    array_unshift($actions, array(
        'label' => sprintf(_n('Import %d record', 'Import %d records', count($file['records']), 'listing-import'), count($file['records'])),
        'type'  => 'submit',
        'variant' => 'primary',
        'attrs' => array('name' => 'li_do', 'value' => 'import'),
    ));
}
osc_admin_form_close($actions);
