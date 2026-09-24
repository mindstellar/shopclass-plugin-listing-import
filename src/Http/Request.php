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

namespace mindstellar\listingimport\Http;

use Params;

/**
 * One API request: method, path, token header, caller's address and body.
 *
 * Built from the server globals in production and by hand in tests.
 */
final class Request
{
    /** The largest body the API reads. */
    public const MAX_BODY = 1048576;

    /** How deep a JSON body may nest. */
    public const MAX_DEPTH = 32;

    public string $method;

    public string $path;

    public string $authorization;

    public string $ip;

    public string $contentType;

    /** The raw body, or null when it was larger than MAX_BODY. */
    public ?string $body;

    /**
     * @param string      $method
     * @param string      $path
     * @param string      $authorization
     * @param string      $ip
     * @param string      $contentType
     * @param string|null $body
     */
    public function __construct(
        string $method,
        string $path,
        string $authorization = '',
        string $ip = '',
        string $contentType = '',
        ?string $body = ''
    ) {
        $this->method        = strtoupper($method);
        $this->path          = trim($path, '/');
        $this->authorization = $authorization;
        $this->ip            = $ip;
        $this->contentType   = strtolower(trim(explode(';', $contentType)[0]));
        $this->body          = $body;
    }

    /**
     * The current request.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        // Apache hides Authorization from PHP unless it is rewritten into the environment,
        // which is where REDIRECT_HTTP_AUTHORIZATION comes from.
        $authorization = (string)Params::getServerParam('HTTP_AUTHORIZATION', false, false);
        if ($authorization === '') {
            $authorization = (string)Params::getServerParam('REDIRECT_HTTP_AUTHORIZATION', false, false);
        }

        // One byte past the cap is enough to know the body is too large, without reading it all.
        $stream = fopen('php://input', 'rb');
        $body   = $stream ? stream_get_contents($stream, self::MAX_BODY + 1) : '';
        if ($stream) {
            fclose($stream);
        }

        // A friendly address hands the route its path still encoded; a query string is decoded already.
        $path = Params::getParamString('path');

        return new self(
            (string)Params::getServerParam('REQUEST_METHOD'),
            filter_has_var(INPUT_GET, 'path') ? $path : rawurldecode($path),
            $authorization,
            (string)Params::getServerParam('REMOTE_ADDR'),
            (string)Params::getServerParam('CONTENT_TYPE'),
            is_string($body) && strlen($body) <= self::MAX_BODY ? $body : null
        );
    }

    /**
     * The body as JSON, or the answer to send when it is not usable.
     *
     * @return array<mixed>|Response
     */
    public function json()
    {
        if ($this->body === null) {
            return Response::error(413, 'too_large', 'The body is larger than 1 MB.');
        }
        if ($this->contentType !== 'application/json') {
            return Response::error(415, 'unsupported_media_type', 'Send the body as application/json.');
        }
        try {
            $data = json_decode($this->body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return Response::error(400, 'invalid_json', 'The body is not valid JSON: ' . $e->getMessage() . '.');
        }
        if (!is_array($data)) {
            return Response::error(400, 'invalid_json', 'The body must be a JSON object.');
        }

        return $data;
    }
}
