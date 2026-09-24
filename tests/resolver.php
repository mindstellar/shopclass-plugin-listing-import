<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * What a record becomes on this site: the category, the place, the owner, the price, and
 * which gaps the source's defaults fill.
 */

require __DIR__ . '/lib/harness.php';
define('ABS_PATH', '/tmp/');
spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\listingimport\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require __DIR__ . '/lib/fakes.php';

use mindstellar\listingimport\Resolve\Resolver;
use mindstellar\listingimport\Resolve\Site;

$resolver = new Resolver(new MemoryLookups(), new Site(array('en_US', 'de_DE'), 'en_US', ',', 'site@example.com'));
$base     = array('external_id' => 'A1', 'title' => 'Blue bike', 'description' => 'A bike.', 'category' => 'bikes');

harness_section('the smallest record');

$r = $resolver->resolve($base);
pin('no errors, no warnings', array(array(), array()), array($r['errors'], $r['warnings']));
pin('text goes to the site default language', array(array('en_US' => 'Blue bike'), array('en_US' => 'A bike.')), array($r['fields']['title'], $r['fields']['description']));
pin('the category by slug', 2, $r['fields']['catId']);
pin('with no contact, the site address is the contact', 'site@example.com', $r['fields']['contactEmail']);
pin('no price means no price', array('', ''), array($r['fields']['price'], $r['fields']['currency']));

harness_section('categories');

pin('by path', 3, $resolver->resolve(array('category' => 'Vehicles > Cars') + $base)['fields']['catId']);
pin('by id', 1, $resolver->resolve(array('category' => array('id' => 1)) + $base)['fields']['catId']);
pin('by name', 3, $resolver->resolve(array('category' => array('label' => 'Cars')) + $base)['fields']['catId']);
$r = $resolver->resolve(array('category' => 'Boats') + $base);
pin('an unknown one with no default is an error', 'No such category here, and the source has no default.', $r['errors']['category'] ?? null);
$r = $resolver->resolve(array('category' => 'Boats') + $base, array('category' => 1));
pin('with a default, it is used and said', array(1, false), array($r['fields']['catId'], isset($r['errors']['category'])));
check('as a warning', isset($r['warnings']['category']));
$none = $base;
unset($none['category']);
$r = $resolver->resolve($none, array('category' => 1));
pin('a record naming no category takes the default quietly', array(1, false), array($r['fields']['catId'], isset($r['warnings']['category'])));

harness_section('prices');

$r = $resolver->resolve(array('price' => array('amount' => '1.234,50', 'currency' => 'EUR')) + $base);
pin('written in the site\'s own format', array('1234,50', 'EUR'), array($r['fields']['price'], $r['fields']['currency']));
$r = $resolver->resolve(array('price' => array('amount' => '650')) + $base, array('currency' => 'EUR'));
pin('the source default currency fills a gap', 'EUR', $r['fields']['currency']);
$r = $resolver->resolve(array('price' => array('amount' => '650', 'currency' => 'USD')) + $base);
pin('a currency the site does not use is an error', 'This site does not use USD.', $r['errors']['price.currency'] ?? null);

harness_section('owner and contact');

$r = $resolver->resolve(array('owner' => array('email' => 'sam@example.com'), 'contact' => array('email' => 'other@example.com')) + $base);
pin('an owner\'s address is the contact, which is how core attaches the listing', array('Sam Seller', 'sam@example.com'), array($r['fields']['contactName'], $r['fields']['contactEmail']));
$r = $resolver->resolve(array('owner' => array('user_id' => 99), 'contact' => array('name' => 'Jo', 'email' => 'jo@example.com', 'show_email' => true)) + $base);
pin('an unknown owner is a warning, and the record\'s contact stands', array('Jo', 'jo@example.com', 1), array($r['fields']['contactName'], $r['fields']['contactEmail'], $r['fields']['showEmail']));
check('with a warning', isset($r['warnings']['owner']));
$r = $resolver->resolve($base, array('owner_user_id' => 7));
pin('a source default owner', 'sam@example.com', $r['fields']['contactEmail']);
$r = $resolver->resolve($base, array('contact_email' => 'feeds@partner.example'));
pin('a source default contact address beats the site address', 'feeds@partner.example', $r['fields']['contactEmail']);

harness_section('location');

$r = $resolver->resolve(array('location' => array('country' => 'Germany', 'region' => 'bayern', 'city' => 'Munchen', 'zip' => '80331')) + $base);
pin(
    'names are matched to the site\'s own places',
    array('DE', '11', 'Bayern', '111', 'München', '80331'),
    array($r['fields']['countryId'], $r['fields']['regionId'], $r['fields']['region'], $r['fields']['cityId'], $r['fields']['city'], $r['fields']['zip'])
);
$r = $resolver->resolve(array('location' => array('country' => 'DE', 'city' => 'München')) + $base);
pin('a city found without its region brings the region', '11', $r['fields']['regionId']);
$r = $resolver->resolve(array('location' => array('country' => 'DE', 'city' => 'Atlantis')) + $base);
pin('an unknown city is kept as text, with a warning', array('Atlantis', ''), array($r['fields']['city'], $r['fields']['cityId']));
check('warned', isset($r['warnings']['location.city']));
$r = $resolver->resolve($base, array('country' => 'DE'));
pin('a source default country', 'DE', $r['fields']['countryId']);

harness_section('languages, expiry and custom fields');

$r = $resolver->resolve(array('title' => array('de_DE' => 'Fahrrad', 'fr_FR' => 'Vélo')) + $base);
pin('a language the site lacks is dropped', array('de_DE' => 'Fahrrad'), $r['fields']['title']);
check('with a warning', isset($r['warnings']['title.fr_FR']));
$r = $resolver->resolve(array('title' => array('fr_FR' => 'Vélo')) + $base);
pin('nothing left is an error', 'Nothing left in a language this site uses.', $r['errors']['title'] ?? null);
$r = $resolver->resolve($base, array('locale' => 'de_DE'));
pin('a source default language', array('de_DE' => 'Blue bike'), $r['fields']['title']);
$r = $resolver->resolve(array('expires_at' => '2001-01-01') + $base);
pin('an expiry in the past is ignored', '', $r['fields']['dt_expiration']);
$r = $resolver->resolve(array('expires_at' => date('Y-m-d', time() + 86400 * 10)) + $base);
check('one in the future is kept', $r['fields']['dt_expiration'] !== '');
$r = $resolver->resolve(array('fields' => array('colour' => 'blue', 'gears' => 21, 'wheels' => 2, 'lights' => true)) + $base);
pin('custom fields go by id, unknown ones are dropped', array(5 => 'blue', 6 => 21), $r['meta']);
check('with a warning', isset($r['warnings']['fields.wheels']));

exit(harness_result());
