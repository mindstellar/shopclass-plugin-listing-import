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

use mindstellar\listingimport\Admin\Sources;
use mindstellar\listingimport\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$apiUrl  = osc_api_url('ext/listing-import');
$keysUrl = osc_admin_base_url(true) . '?page=settings&action=api';
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
        <?php echo $link($keysUrl, __('API keys', 'listing-import')); ?> &middot;
        <?php echo $link(osc_settings_page_url(Plugin::PAGE), __('Settings', 'listing-import')); ?></p>
<?php

osc_admin_form_section(__('Send listings to the API', 'listing-import'), array(
    'spaced' => true,
    'intro'  => sprintf(__('The API lives at %s.', 'listing-import'), $apiUrl),
), array('spaced' => true));
?>
    <p><?php _e('Make an admin key under Settings > API with the Listing import permissions, then list its id on the push source it imports into (Sources > edit). A key listed on no source is refused. Give the key to the partner. It is shown once. Send it on every request:', 'listing-import'); ?></p>
    <?php $code('Authorization: Bearer <key>'); ?>
    <?php $table(array(__('Request', 'listing-import'), __('What it does', 'listing-import')), array(
        array('POST /listings', __('Import one record now.', 'listing-import')),
        array('PUT /listings/{external_id}', __('The same, with the id in the address.', 'listing-import')),
        array('GET /listings/{external_id}', __('The listing this id became, and whether it is live.', 'listing-import')),
        array('DELETE /listings/{external_id}', __('Delete that listing.', 'listing-import')),
        array('POST /listings:batch', __('Up to 200 records, imported in the background.', 'listing-import')),
        array('GET /runs/{id}', __('How a batch went.', 'listing-import')),
    )); ?>
    <p><?php _e('The paths below are under the API address above. The site\'s whole API, this plugin included, is described at', 'listing-import'); ?> <code><?php echo osc_esc_html(osc_api_url('openapi.json')); ?></code>.</p>
    <p><?php _e('The smallest record. The external id is your own id: send it again and the same listing is updated. Always send the whole record; there is no partial update.', 'listing-import'); ?></p>
    <?php $code("{\n  \"external_id\": \"A-1001\",\n  \"title\": \"Blue bike\",\n  \"description\": \"A good bike, 21 gears.\",\n  \"category\": \"Vehicles > Bikes\"\n}"); ?>
<?php
osc_admin_disclosure_open(__('A record with every field', 'listing-import'));
?>
    <p><?php _e('Only external_id, title and description are required, and category when the source has no default one. A field the plugin does not know comes back as a warning; the record is still imported.', 'listing-import'); ?></p>
    <?php $code(<<<'JSON'
{
  "external_id": "A-1001",
  "title": {"en_US": "Blue bike", "de_DE": "Blaues Fahrrad"},
  "description": {"en_US": "A good bike, 21 gears.", "de_DE": "Ein gutes Rad, 21 Gänge."},
  "category": "Vehicles > Bikes",
  "price": {"amount": "1.234,50", "currency": "EUR"},
  "location": {
    "country": "Germany", "region": "Bavaria", "city": "Munich",
    "city_area": "Schwabing", "address": "Leopoldstraße 1", "zip": "80802",
    "lat": 48.1624, "lng": 11.5865
  },
  "contact": {"name": "Sam Seller", "email": "sam@example.com", "phone": "+49 89 123456", "show_email": false},
  "owner": {"email": "sam@example.com"},
  "images": ["https://cdn.example.com/bike-1.jpg", "https://cdn.example.com/bike-2.jpg"],
  "fields": {"colour": "Blue", "frame-size": 56},
  "expires_at": "2026-12-31T23:59:59Z",
  "published_at": "2026-09-01T10:00:00Z",
  "source_url": "https://shop.example.com/bikes/a-1001"
}
JSON
    ); ?>
    <?php $table(array(__('Field', 'listing-import'), __('What it takes', 'listing-import')), array(
        array('category', __('An id, a slug, a path such as "Vehicles > Bikes", or {"id"}, {"slug"}, {"path"} or {"label"}.', 'listing-import')),
        array('price', __('amount as a number or as text such as "1.234,50"; currency as a three-letter code.', 'listing-import')),
        array('location', __('Names are matched to the site\'s own countries, regions and cities. A place the site does not have is kept as text.', 'listing-import')),
        array('owner', __('user_id or email of an account here. Used only when the source allows records to choose accounts.', 'listing-import')),
        array('images', __('One public http or https address, or a list of up to 20; the first 10 are fetched. JPEG, PNG, GIF or WebP, 8 MB each. A refused image is a warning.', 'listing-import')),
        array('fields', __('Custom field slug => text, number or true/false.', 'listing-import')),
    )); ?>
<?php
osc_admin_disclosure_close();

osc_admin_disclosure_open(__('Import one record', 'listing-import'));
$code(sprintf(<<<'SH'
curl -X POST %1$s/listings \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"external_id":"A-1001","title":"Blue bike","description":"A good bike, 21 gears.","category":"Vehicles > Bikes"}'
SH
, $apiUrl));
?>
    <p><?php _e('A new listing answers 201. The same record sent again answers 200 with the status unchanged, and a changed one with updated.', 'listing-import'); ?></p>
    <?php $code('{"data":{"status":"created","external_id":"A-1001","item_id":294,"run_id":20}}'); ?>
    <p><?php _e('Warnings, when there are any, come beside the data:', 'listing-import'); ?></p>
    <?php $code('{"data":{"status":"created","external_id":"A-1001","item_id":294,"run_id":20},"warnings":{"colour":"Unknown field, ignored."}}'); ?>
    <p><?php _e('PUT to /listings/{external_id} does the same, with the id in the address. An id in an address cannot hold a slash; encode other characters as usual.', 'listing-import'); ?></p>
<?php
osc_admin_disclosure_close();

osc_admin_disclosure_open(__('Look up or delete a listing', 'listing-import'));
$code(sprintf("curl %1\$s/listings/A-1001 \\\n  -H \"Authorization: Bearer \$KEY\"", $apiUrl));
$code('{"data":{"external_id":"A-1001","item_id":294,"status":"active","url":"https://example.com/bikes/blue-bike_i294","last_seen":"2026-09-25 05:33:06","synced_at":"2026-09-25 05:33:06"}}');
$code(sprintf("curl -X DELETE %1\$s/listings/A-1001 \\\n  -H \"Authorization: Bearer \$KEY\"", $apiUrl));
$code('{"data":{"external_id":"A-1001","item_id":294,"deleted":true}}');
?>
    <p><?php _e('Deleting needs the Listing import: delete listings permission.', 'listing-import'); ?></p>
<?php
osc_admin_disclosure_close();

osc_admin_disclosure_open(__('Import many records at once', 'listing-import'));
$code(sprintf(<<<'SH'
curl -X POST %1$s/listings:batch \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"records":[{"external_id":"A-1001", ...}, {"external_id":"A-1002", ...}]}'
SH
, $apiUrl));
?>
    <p><?php _e('Up to 200 records. It answers 202 at once; the site\'s background jobs import them.', 'listing-import'); ?></p>
    <?php $code('{"data":{"run_id":23,"records":2,"status":"queued"}}'); ?>
    <p><?php _e('Ask how the run went. It needs the Listing import: read import results permission.', 'listing-import'); ?></p>
    <?php $code(sprintf("curl %1\$s/runs/23 \\\n  -H \"Authorization: Bearer \$KEY\"", $apiUrl)); ?>
    <?php $code('{"data":{"run_id":23,"status":"finished","records":2,"counts":{"created":1,"updated":0,"unchanged":0,"retired":0,"failed":1},"started_at":"2026-09-25 05:33:06","finished_at":"2026-09-25 05:33:07","failures":[{"external_id":"A-1002","errors":{"description":"Required."}}]}}'); ?>
<?php
osc_admin_disclosure_close();

osc_admin_disclosure_open(__('When a request is refused', 'listing-import'));
?>
    <p><?php _e('Errors follow RFC 9457 (application/problem+json), as the rest of the API does. For a record, errors says what is wrong with each field, so it can be fixed in one go.', 'listing-import'); ?></p>
    <?php $code('{"type":"https://mindstellar.com/docs/developers/api/errors/#not_imported","title":"The record was not imported.","status":422,"detail":"The record was not imported; see errors.","code":"not_imported","errors":[{"pointer":"/description","message":"Required.","in":"body"}]}'); ?>
<?php
osc_admin_disclosure_close();

osc_admin_form_section(__('Answers and limits', 'listing-import'), array('spaced' => true));
$table(array(__('Status', 'listing-import'), __('Code', 'listing-import'), __('Why', 'listing-import')), array(
    array('400', 'invalid_json', __('The body is not JSON.', 'listing-import')),
    array('401', 'unauthorized', __('No key, or a wrong one.', 'listing-import')),
    array('403', 'forbidden, insufficient_scope', __('The key is not an admin key, or lacks the permission.', 'listing-import')),
    array('404', 'not_found', __('No such endpoint, listing or run.', 'listing-import')),
    array('405', 'method_not_allowed', __('The endpoint does not take that method.', 'listing-import')),
    array('409', 'conflict', __('The key is linked to no source, or its source is switched off.', 'listing-import')),
    array('413', 'too_large', __('A body over 1 MB, or more than 200 records.', 'listing-import')),
    array('415', 'unsupported_media_type', __('Send Content-Type: application/json.', 'listing-import')),
    array('422', 'not_imported, validation_failed', __('The record is wrong. errors says where.', 'listing-import')),
    array('429', 'rate_limited, too_many_failures', __('Too many requests. Wait for the Retry-After seconds.', 'listing-import')),
    array('500', 'server_error', __('Something failed on the site. Its error log says what.', 'listing-import')),
));
?>
    <p><?php _e('Request limits, and the lockout of an address that keeps sending a wrong key, are the API settings of the site under Settings > API.', 'listing-import'); ?></p>
<?php

osc_admin_form_section(__('Fetch a feed', 'listing-import'), array('spaced' => true));
?>
    <p><?php _e('Add a source that fetches a feed: its address, its format and how often. Formats: JSON, NDJSON, CSV, or another Shopclass site\'s RSS. When the feed names its fields differently, map them one per line:', 'listing-import'); ?></p>
    <?php $code("id = external_id\nHeadline = title\nTown = location.city\nCost = price.amount"); ?>
    <p><?php _e('Preview shows what a fetch would import, without changing anything. A listing whose record leaves the feed is deactivated, not deleted, and comes back with its record. A listing deleted in the admin stays deleted until its record changes. If most records in a fetch fail, nothing is deactivated.', 'listing-import'); ?></p>
<?php

osc_admin_form_section(__('Import a file', 'listing-import'), array('spaced' => true));
?>
    <p><?php _e('Under Import a file, upload a JSON, NDJSON, CSV or RSS file and choose the source it belongs to. You see how each record would be imported before anything changes. Listings the file leaves out are not touched.', 'listing-import'); ?></p>
<?php

osc_admin_form_section(__('Command line', 'listing-import'), array('spaced' => true));
$code("php oc-cli.php import:run --file=records.json --dry-run\nphp oc-cli.php import:run --file=records.json\nphp oc-cli.php import:status\nphp oc-cli.php api:key:create --admin=<username> --name=\"Partner site\" --scopes=ext:listing-import:write,ext:listing-import:runs");
?>
    <p><?php _e('Batches and feeds run with the site\'s background jobs, so the site\'s cron must run.', 'listing-import'); ?></p>
<?php
