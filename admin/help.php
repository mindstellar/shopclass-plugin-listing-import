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

use mindstellar\listingimport\Admin\Keys;
use mindstellar\listingimport\Admin\Sources;
use mindstellar\listingimport\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$apiUrl = dirname(osc_route_url(Plugin::ROUTE, array('path' => 'ping')));
$link   = static fn (string $url, string $text): string => '<a href="' . osc_esc_html($url) . '">' . osc_esc_html($text) . '</a>';
$code   = static function (string $text): void {
    echo '<pre><code>' . osc_esc_html($text) . '</code></pre>';
};
$table  = static function (array $head, array $rows): void {
    echo '<div class="osc-table-stack"><table class="table"><thead><tr>';
    foreach ($head as $cell) {
        echo '<th>' . osc_esc_html($cell) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $i => $cell) {
            echo '<td data-col-name="' . osc_esc_html($head[$i]) . '">' . ($i === 0 ? '<code>' . osc_esc_html($cell) . '</code>' : osc_esc_html($cell)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
osc_admin_page_head(__('Listing import help', 'listing-import'));

osc_admin_form_section(__('How it works', 'listing-import'));
?>
    <p><?php _e('Listings arrive in two ways. A partner sends them to this site\'s API with a key, or this site fetches a feed on a schedule. Each way is a source, with its own defaults and rules.', 'listing-import'); ?></p>
    <p><?php _e('Every listing is saved the way a posted one is: validation, spam checks, custom fields and expiry apply. A record keeps the same listing across imports, by its external id. The same record sent again changes nothing.', 'listing-import'); ?></p>
    <p><?php echo $link(osc_route_admin_url(Sources::ROUTE), __('Sources', 'listing-import')); ?> &middot;
        <?php echo $link(osc_route_admin_url(Keys::ROUTE), __('API keys', 'listing-import')); ?> &middot;
        <?php echo $link(osc_settings_page_url(Plugin::PAGE), __('Settings', 'listing-import')); ?></p>
<?php

osc_admin_form_section(__('Send listings to the API', 'listing-import'), array(
    'spaced' => true,
    'intro'  => sprintf(__('The API lives at %s.', 'listing-import'), $apiUrl),
), array('spaced' => true));
?>
    <p><?php _e('Make a key under API keys and give it to the partner. It is shown once. Send it on every request:', 'listing-import'); ?></p>
    <?php $code('Authorization: Bearer <key>'); ?>
    <?php $table(array(__('Request', 'listing-import'), __('What it does', 'listing-import')), array(
        array('POST /listings', __('Import one record now.', 'listing-import')),
        array('PUT /listings/{external_id}', __('The same, with the id in the address.', 'listing-import')),
        array('GET /listings/{external_id}', __('The listing this id became, and whether it is live.', 'listing-import')),
        array('DELETE /listings/{external_id}', __('Delete that listing.', 'listing-import')),
        array('POST /listings:batch', __('Up to 200 records, imported in the background.', 'listing-import')),
        array('GET /runs/{id}', __('How a batch went.', 'listing-import')),
        array('GET /openapi.json', __('The whole API as an OpenAPI file. No key needed.', 'listing-import')),
    )); ?>
    <p><?php _e('The smallest record:', 'listing-import'); ?></p>
    <?php $code("{\n  \"external_id\": \"A-1001\",\n  \"title\": \"Blue bike\",\n  \"description\": \"A good bike, 21 gears.\",\n  \"category\": \"Vehicles > Bikes\"\n}"); ?>
    <p><?php _e('Optional fields: price, location, contact, owner, images (up to 20 public addresses), fields (custom fields by slug), expires_at. A title and description may be given per language. Always send the whole record; there is no partial update.', 'listing-import'); ?></p>
<?php

osc_admin_form_section(__('Answers and limits', 'listing-import'), array('spaced' => true));
$table(array(__('Status', 'listing-import'), __('Why', 'listing-import')), array(
    array('401', __('No key, or a wrong one.', 'listing-import')),
    array('403', __('The key lacks the permission.', 'listing-import')),
    array('409', __('The key\'s source is missing or switched off.', 'listing-import')),
    array('422', __('The record is wrong. The answer says which fields.', 'listing-import')),
    array('429', __('Too many requests. Wait for the Retry-After seconds.', 'listing-import')),
));
?>
    <p><?php _e('Each key may send a set number of requests a minute; change it under Settings. An address that sends a wrong key 20 times in 15 minutes is refused for a while.', 'listing-import'); ?></p>
<?php

osc_admin_form_section(__('Fetch a feed', 'listing-import'), array('spaced' => true));
?>
    <p><?php _e('Add a source that fetches a feed: its address, its format and how often. Formats: JSON, NDJSON, CSV, or another Shopclass site\'s RSS. When the feed names its fields differently, map them one per line:', 'listing-import'); ?></p>
    <?php $code("id = external_id\nHeadline = title\nTown = location.city\nCost = price.amount"); ?>
    <p><?php _e('Preview shows what a fetch would import, without changing anything. A listing whose record leaves the feed is deactivated, not deleted, and comes back with its record. If most records in a fetch fail, nothing is deactivated.', 'listing-import'); ?></p>
<?php

osc_admin_form_section(__('Command line', 'listing-import'), array('spaced' => true));
$code("php oc-cli.php import:run --file=records.json --dry-run\nphp oc-cli.php import:run --file=records.json\nphp oc-cli.php import:status\nphp oc-cli.php import:key:create --name=\"Partner site\"");
?>
    <p><?php _e('Batches and feeds run with the site\'s background jobs, so the site\'s cron must run.', 'listing-import'); ?></p>
<?php
