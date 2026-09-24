<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The same record twice makes one listing; a changed record updates it; a deleted listing
 * comes back only when the record changes; a dry run changes nothing; and moderation and
 * listing limits are applied even though core is asked as an admin.
 */

require __DIR__ . '/lib/harness.php';
harness_plugin();

use mindstellar\listingimport\Import\Source;

$record = array('external_id' => 'A1', 'title' => 'Blue bike', 'description' => 'A bike.', 'category' => 'bikes');

/** A fresh importer and source 1 with a policy. */
function fresh(array $policy = array()): array
{
    $GLOBALS['__store']    = new MemoryStore();
    $GLOBALS['__listings'] = new MemoryListings();

    return array(fake_importer(), new Source(1, 'API', array(), $policy));
}

harness_section('the same record twice');

[$importer, $source] = fresh();
$first  = $importer->import($source, $record, 1);
$second = $importer->import($source, $record, 1);
pin('creates, then changes nothing', array('created', 'unchanged'), array($first['status'], $second['status']));
pin('both name the same listing', $first['item_id'], $second['item_id']);
pin('there is one listing', 1, count($GLOBALS['__listings']->items));
pin('keys in another order are the same record', 'unchanged', $importer->import($source, array_reverse($record, true), 1)['status']);

harness_section('a changed record');

$changed = array('title' => 'Red bike') + $record;
$r       = $importer->import($source, $changed, 1);
pin('updates the same listing', array('updated', $first['item_id']), array($r['status'], $r['item_id']));
pin('with the new title', array('en_US' => 'Red bike'), $GLOBALS['__listings']->items[$first['item_id']]['fields']['title']);
pin('and sent again is unchanged', 'unchanged', $importer->import($source, $changed, 1)['status']);

harness_section('the same external id from another source');

$other = new Source(2, 'Feed');
pin('is another listing', 'created', $importer->import($other, $record, 1)['status']);
pin('so there are two', 2, count($GLOBALS['__listings']->items));

harness_section('a listing an admin deleted');

unset($GLOBALS['__listings']->items[$first['item_id']]);
$r = $importer->import($source, $changed, 1);
pin('is created again when the record comes in', 'created', $r['status']);
check('as a new listing', $r['item_id'] !== $first['item_id']);

harness_section('a dry run');

[$importer, $source] = fresh();
$r = $importer->import($source, $record, 1, true);
pin('says what would happen', 'created', $r['status']);
pin('and changes nothing', array(array(), array()), array($GLOBALS['__listings']->items, $GLOBALS['__store']->map));
$importer->import($source, $record, 1);
$r = $importer->import($source, $record, 1, true);
pin('an unchanged record still shows where it lands', array('unchanged', true), array($r['status'], isset($r['fields']['catId'])));

harness_section('moderation');

[$importer, $source] = fresh(array('status' => Source::STATUS_SITE));
$r = $importer->import($source, $record, 1);
pin('the site does not moderate: the listing goes live', array(), $GLOBALS['__listings']->held);
[$importer, $source] = fresh(array('status' => Source::STATUS_SITE));
$GLOBALS['__listings']->moderates = true;
$r = $importer->import($source, $record, 1);
pin('the site holds new listings: so is this one', array($r['item_id']), $GLOBALS['__listings']->held);
[$importer, $source] = fresh(array('status' => Source::STATUS_PENDING));
$r = $importer->import($source, $record, 1);
pin('a source set to pending always holds', array($r['item_id']), $GLOBALS['__listings']->held);
[$importer, $source] = fresh(array('status' => Source::STATUS_ACTIVE));
$GLOBALS['__listings']->moderates = true;
$importer->import($source, $record, 1);
pin('a source set to active goes live even so', array(), $GLOBALS['__listings']->held);
$importer->import($source, array('title' => 'Changed') + $record, 1);
pin('an update never holds', array(), $GLOBALS['__listings']->held);

harness_section('listing limits');

[$importer, $source] = fresh();
$GLOBALS['__listings']->limited = array('sam@example.com');
$source->defaults['owners_from_records'] = true;
$owned = array('owner' => array('email' => 'sam@example.com')) + $record;
$r     = $importer->import($source, $owned, 1);
pin('an owner at their limit gets no new listing', array('failed', array('owner' => 'The owner has reached their listing limit.')), array($r['status'], $r['errors']));
[$importer, $source] = fresh(array('respect_caps' => false));
$GLOBALS['__listings']->limited = array('sam@example.com');
pin('unless the source ignores limits', 'created', $importer->import($source, $owned, 1)['status']);

harness_section('failures');

[$importer, $source] = fresh();
$r = $importer->import($source, array('title' => 'REFUSE') + $record, 7);
pin('core refusing a listing is a failure with its reason', array('failed', array('listing' => 'Title too short.')), array($r['status'], $r['errors']));
$r = $importer->import($source, array('category' => 'Boats') + $record, 7);
pin('a record that cannot be placed fails before core is asked', array('failed', 0), array($r['status'], count($GLOBALS['__listings']->items)));
$r = $importer->import($source, array('title' => 'x'), 7);
pin('a record in the wrong shape fails first', 'failed', $r['status']);
pin('every failure is logged against its run', array(7, 7, 7), array_column($GLOBALS['__store']->logs, 0));
pin('as errors', array('error', 'error', 'error'), array_column($GLOBALS['__store']->logs, 2));

harness_section('warnings');

[$importer, $source] = fresh();
$r = $importer->import($source, array('colour' => 'red', 'location' => array('country' => 'DE', 'city' => 'Atlantis')) + $record, 1);
pin('reach the caller, from both the check and the resolver', array('colour', 'location.city'), array_keys($r['warnings']));

harness_section('images');

$GLOBALS['__store']    = new MemoryStore();
$GLOBALS['__listings'] = new MemoryListings();
$images                = new FakeImages();
$images->bodies        = array('https://cdn.example/a.jpg' => 'AAA', 'https://cdn.example/b.jpg' => 'BBB', 'https://mirror.example/a.jpg' => 'AAA');
$importer              = fake_importer($images);
$source                = new Source(1, 'API');
$withImages            = array('images' => array('https://cdn.example/a.jpg', 'https://cdn.example/missing.jpg', 'https://mirror.example/a.jpg')) + $record;
$r                     = $importer->import($source, $withImages, 1);
pin('a new listing gets its images, each once even under two addresses', array('AAA'), $GLOBALS['__listings']->items[$r['item_id']]['photos']);
pin('an image that could not be fetched is a warning, not a failure', array('created', 'The server answered 404.'), array($r['status'], $r['warnings']['images.1'] ?? null));

$images->asked = array();
$importer->import($source, $withImages, 1);
pin('an unchanged record fetches nothing', array(), $images->asked);

$more = array('images' => array('https://cdn.example/a.jpg', 'https://cdn.example/b.jpg')) + $record;
$importer->import($source, $more, 1);
pin('an update adds only the image the listing lacks', array('AAA', 'BBB'), $GLOBALS['__listings']->items[$r['item_id']]['photos']);

$many = array('external_id' => 'A9', 'images' => array('https://cdn.example/a.jpg', 'https://cdn.example/b.jpg', 'https://x.example/1', 'https://x.example/2')) + $record;
$r    = $importer->import($source, $many, 1);
pin('past the limit the rest are skipped, and said', 'Only the first 3 images are imported.', $r['warnings']['images'] ?? null);

$GLOBALS['__store']    = new MemoryStore();
$GLOBALS['__listings'] = new MemoryListings();
$importer              = fake_importer($images);
$before                = count(glob(sys_get_temp_dir() . '/import_*'));
$importer->import($source, array('title' => 'REFUSE', 'images' => array('https://cdn.example/a.jpg')) + $record, 1);
pin('a listing core refuses leaves no image behind', $before, count(glob(sys_get_temp_dir() . '/import_*')));

$images->bodies['https://cdn.example/c.webp'] = 'REFUSED-IMAGE';
$before = count(glob(sys_get_temp_dir() . '/import_*'));
$r      = $importer->import($source, array('external_id' => 'W1', 'images' => array('https://cdn.example/c.webp', 'https://cdn.example/b.jpg')) + $record, 1);
pin('an image the site would refuse is skipped with a warning; the listing is still made', array('created', array('BBB'), 'This site does not accept that image type.'), array($r['status'], $GLOBALS['__listings']->items[$r['item_id']]['photos'] ?? null, $r['warnings']['images.0'] ?? null));
pin('and its file is deleted', $before, count(glob(sys_get_temp_dir() . '/import_*')));

$GLOBALS['__store']    = new MemoryStore();
$GLOBALS['__listings'] = new MemoryListings();
$r                     = fake_importer()->import($source, $withImages, 1);
pin('with no image source, images are skipped and said', 'Images are not imported here.', $r['warnings']['images'] ?? null);

exit(harness_result());
