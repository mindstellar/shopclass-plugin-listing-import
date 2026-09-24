<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The v1 record check: what is required, what each field must look like, and that unknown
 * fields warn instead of failing.
 */

require __DIR__ . '/lib/harness.php';
require __DIR__ . '/../src/Record/Validator.php';

use mindstellar\listingimport\Record\Validator;

$base = array(
    'external_id' => 'A1',
    'title'       => 'Blue bike',
    'description' => 'A good bike.',
    'category'    => 'bikes',
);

/** The error paths a record produces. */
function errors(array $record): array
{
    return array_keys(Validator::check($record)['errors']);
}

harness_section('the smallest valid record');

pin('has no errors and no warnings', array('errors' => array(), 'warnings' => array()), Validator::check($base));
pin('an empty record lists the three required fields', array('external_id', 'title', 'description'), errors(array()));
pin('an empty string counts as missing', array('title'), errors(array('title' => '') + $base));

harness_section('a full record');

$full = $base + array(
    'price'        => array('amount' => '1.234,50', 'currency' => 'EUR'),
    'location'     => array('country' => 'DE', 'region' => 'Bayern', 'city' => 'München', 'zip' => '80331', 'lat' => 48.13, 'lng' => 11.58),
    'contact'      => array('name' => 'Jo', 'email' => 'jo@example.com', 'phone' => '555', 'show_email' => false),
    'owner'        => array('email' => 'seller@example.com'),
    'images'       => array('https://example.com/a.jpg', 'http://example.com/b.png'),
    'fields'       => array('colour' => 'blue', 'gears' => 21, 'extras' => array('lights', 'bell')),
    'expires_at'   => '2026-12-31',
    'published_at' => '2026-10-01T12:00:00Z',
    'source_url'   => 'https://partner.example/ad/1',
);
pin('has no errors and no warnings', array('errors' => array(), 'warnings' => array()), Validator::check($full));
pin(
    'title and description may be given per language',
    array(),
    errors(array('title' => array('en_US' => 'Bike', 'de_DE' => 'Fahrrad'), 'description' => array('en_US' => 'x')) + $base)
);
pin('the category may be an id', array(), errors(array('category' => 12) + $base));
pin('or a path', array(), errors(array('category' => array('path' => 'Vehicles > Bikes')) + $base));

harness_section('fields in the wrong shape');

pin('an external id over 191 characters', array('external_id'), errors(array('external_id' => str_repeat('x', 192)) + $base));
pin('a title in a made-up locale', array('title.english'), errors(array('title' => array('english' => 'Bike')) + $base));
pin('a title given as a list', array('title'), errors(array('title' => array('Bike')) + $base));
pin('a negative price and a currency that is not a code', array('price.amount', 'price.currency'), errors(array('price' => array('amount' => -5, 'currency' => 'euro')) + $base));
pin('a price with no amount', array('price.amount'), errors(array('price' => array('currency' => 'EUR')) + $base));
pin('a category naming two ways at once', array('category'), errors(array('category' => array('id' => 1, 'slug' => 'bikes')) + $base));
pin('a latitude off the planet', array('location.lat'), errors(array('location' => array('lat' => 95)) + $base));
pin('a contact e-mail that is not one', array('contact.email'), errors(array('contact' => array('email' => 'nope')) + $base));
pin('an owner naming both an id and an e-mail', array('owner'), errors(array('owner' => array('user_id' => 3, 'email' => 'a@b.co')) + $base));
pin('an image address that is not http', array('images.1'), errors(array('images' => array('https://example.com/a.jpg', 'file:///etc/passwd')) + $base));
pin('more than 20 images', array('images'), errors(array('images' => array_fill(0, 21, 'https://example.com/a.jpg')) + $base));
pin('a custom field holding an object', array('fields.size'), errors(array('fields' => array('size' => array('w' => 1))) + $base));
pin('a date that is not a date', array('expires_at'), errors(array('expires_at' => 'next week') + $base));
pin('a source address that is not http', array('source_url'), errors(array('source_url' => 'javascript:alert(1)') + $base));

harness_section('fields it does not know');

pin(
    'warn, and do not stop the record',
    array('errors' => array(), 'warnings' => array('colour' => 'Unknown field, ignored.', 'location.planet' => 'Unknown field, ignored.')),
    Validator::check(array('colour' => 'red', 'location' => array('planet' => 'Mars')) + $base)
);

exit(harness_result());
