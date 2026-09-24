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

/**
 * One API answer: a status code, a JSON body and any extra headers.
 *
 * Built as a value and sent separately, so a test can read what the API would say
 * without a web server.
 */
final class Response
{
    public int $status;

    /** @var array<string,mixed> */
    public array $body;

    /** @var array<string,string> */
    public array $headers;

    /**
     * @param int                  $status
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $body, array $headers = array())
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
    }

    /**
     * A success answer.
     *
     * @param array<string,mixed> $data
     * @param int                 $status
     *
     * @return self
     */
    public static function ok(array $data, int $status = 200): self
    {
        return new self($status, array('data' => $data));
    }

    /**
     * An error answer. Every error has the same shape, so a client reads one field.
     *
     * @param int    $status
     * @param string $code    a stable, machine-readable name such as `not_found`
     * @param string $message a sentence for a person
     *
     * @return self
     */
    public static function error(int $status, string $code, string $message): self
    {
        return new self($status, array('error' => array('code' => $code, 'message' => $message)));
    }

    /**
     * Send it and stop.
     *
     * @return void
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
