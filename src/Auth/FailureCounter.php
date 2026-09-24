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

namespace mindstellar\listingimport\Auth;

use mindstellar\security\ActionThrottle;

/**
 * Failed token checks from one address. Past the limit, that address is refused before its
 * token is even looked at, so guessing keys is slow.
 */
class FailureCounter
{
    /** Failed checks allowed per address in the window. */
    public const MAX = 20;

    /** The window, in seconds. */
    public const WINDOW = 900;

    private const CONTEXT = 'listing-import-auth';

    /**
     * @return bool
     */
    public function exceeded(): bool
    {
        return ActionThrottle::exceeded(self::CONTEXT, self::MAX, self::WINDOW);
    }

    /**
     * @return void
     */
    public function record(): void
    {
        ActionThrottle::record(self::CONTEXT);
    }
}
