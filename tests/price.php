<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Prices as partners write them. The old plugin turned "1.234,56" into 1.23456.
 */

require __DIR__ . '/lib/harness.php';
require __DIR__ . '/../src/Resolve/Price.php';

use mindstellar\listingimport\Resolve\Price;

harness_section('reading a price');

$cases = array(
    '650'         => 650.0,
    '€650'        => 650.0,
    'EUR 650'     => 650.0,
    '1.234,56'    => 1234.56,
    '1,234.56'    => 1234.56,
    '1.234'       => 1234.0,
    '1,234'       => 1234.0,
    '12.5'        => 12.5,
    '12,50'       => 12.5,
    '1.234.567'   => 1234567.0,
    '1 234 567,8' => 1234567.8,
    'free'        => null,
    ''            => null,
);
foreach ($cases as $in => $want) {
    pin(var_export((string)$in, true), $want, Price::parse((string)$in));
}
pin('a number stays a number', 19.99, Price::parse(19.99));
pin('a negative number is refused', null, Price::parse(-5));

harness_section('writing it for the site');

pin('a comma-decimal site', '1234,50', Price::forSite(1234.5, ','));
pin('a dot-decimal site', '1234.50', Price::forSite(1234.5, '.'));

exit(harness_result());
