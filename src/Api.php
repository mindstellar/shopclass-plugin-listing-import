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

namespace mindstellar\listingimport;

use mindstellar\listingimport\Http\Response;
use Params;

/**
 * The REST API under /api/v1/. Every request arrives on one route hook and is sent to a
 * handler by method and path.
 */
final class Api
{
    /**
     * Answer the current request and stop.
     *
     * @return void
     */
    public static function handle(): void
    {
        $method = strtoupper((string)Params::getServerParam('REQUEST_METHOD'));
        $path   = Params::getParamString('path');

        self::dispatch($method, $path)->send();
    }

    /**
     * The answer for one method and path.
     *
     * @param string $method
     * @param string $path the part after /api/v1/
     *
     * @return Response
     */
    public static function dispatch(string $method, string $path): Response
    {
        $path = trim($path, '/');

        if ($path === 'ping') {
            if ($method !== 'GET') {
                return self::notAllowed('GET');
            }

            return Response::ok(array('plugin' => 'listing-import', 'version' => Plugin::VERSION));
        }

        return Response::error(404, 'not_found', 'No such endpoint.');
    }

    /**
     * @param string $allowed
     *
     * @return Response
     */
    private static function notAllowed(string $allowed): Response
    {
        $response            = Response::error(405, 'method_not_allowed', 'Use ' . $allowed . ' for this endpoint.');
        $response->headers['Allow'] = $allowed;

        return $response;
    }
}
