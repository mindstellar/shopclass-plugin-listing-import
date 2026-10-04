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

use mindstellar\api\ApiProblem;
use mindstellar\api\auth\Credential;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\listingimport\Import\Batch;
use mindstellar\listingimport\Import\Importer;
use mindstellar\listingimport\Import\Listings;
use mindstellar\listingimport\Import\Source;
use mindstellar\listingimport\Import\Store;

/**
 * The endpoints under /api/v1/ext/listing-import/. Core's API checks the key, its scope and
 * its rate limit before a handler runs; a handler imports into the source the key is linked to.
 */
final class Api
{
    public const WRITE  = 'ext:listing-import:write';
    public const DELETE = 'ext:listing-import:delete';
    public const RUNS   = 'ext:listing-import:runs';

    private const BASE = 'ext/listing-import/';

    private const TAG = 'Listing import';

    public function __construct(
        private Importer $importer,
        private Store $store,
        private Batch $batch,
        private Listings $listings
    ) {
    }

    /**
     * The plugin's scopes, for the `api_scopes` filter. Only admin keys may hold them.
     *
     * @return array<string,array{description:string,audience:string}>
     */
    public static function scopes(): array
    {
        return array(
            self::WRITE  => array('description' => __('Listing import: add, update and read listings', 'listing-import'), 'audience' => 'admin'),
            self::DELETE => array('description' => __('Listing import: delete listings', 'listing-import'), 'audience' => 'admin'),
            self::RUNS   => array('description' => __('Listing import: read import results', 'listing-import'), 'audience' => 'admin'),
        );
    }

    /**
     * 'METHOD path' => route spec, for osc_api_register_route(). The handlers build the Api
     * only when a request reaches them.
     *
     * @param callable $make returns the Api
     *
     * @return array<string,array<string,mixed>>
     */
    public static function routes(callable $make): array
    {
        $handler = static fn (string $method): \Closure
            => static fn (Request $request, Credential $credential, array $args): Response
                => $make()->$method($request, $credential, $args);
        $record  = array('type' => 'object');
        $problem = array('type' => 'object');
        $problems = array(
            401 => $problem,
            403 => $problem,
            404 => $problem,
            409 => $problem,
            422 => $problem,
        );

        return array(
            'POST ' . self::BASE . 'listings' => array(
                'handler'   => $handler('createListing'),
                'auth'      => 'admin',
                'scope'     => self::WRITE,
                'tags'      => array(self::TAG),
                'summary'   => 'Import one record',
                'body'      => $record,
                'responses' => array(200 => array('type' => 'object'), 201 => array('type' => 'object')) + $problems,
            ),
            'POST ' . self::BASE . 'listings:batch' => array(
                'handler'   => $handler('createBatch'),
                'auth'      => 'admin',
                'scope'     => self::WRITE,
                'tags'      => array(self::TAG),
                'summary'   => 'Import up to ' . Batch::MAX_RECORDS . ' records in the background',
                'description' => 'More than ' . Batch::MAX_RECORDS . ' records is refused with 413.',
                'body'      => array(
                    'type'       => 'object',
                    'required'   => array('records'),
                    'properties' => array('records' => array('type' => 'array', 'minItems' => 1)),
                ),
                'responses' => array(202 => array('type' => 'object'), 413 => $problem) + $problems,
            ),
            'GET ' . self::BASE . 'listings/{external_id}' => array(
                'handler'   => $handler('showListing'),
                'auth'      => 'admin',
                'scope'     => self::WRITE,
                'tags'      => array(self::TAG),
                'summary'   => 'The listing an external id became',
                'responses' => array(200 => array('type' => 'object')) + $problems,
            ),
            'PUT ' . self::BASE . 'listings/{external_id}' => array(
                'handler'   => $handler('putListing'),
                'auth'      => 'admin',
                'scope'     => self::WRITE,
                'tags'      => array(self::TAG),
                'summary'   => 'Create or replace the listing for an external id',
                'body'      => $record,
                'responses' => array(200 => array('type' => 'object'), 201 => array('type' => 'object')) + $problems,
            ),
            'DELETE ' . self::BASE . 'listings/{external_id}' => array(
                'handler'   => $handler('deleteListing'),
                'auth'      => 'admin',
                'scope'     => self::DELETE,
                'tags'      => array(self::TAG),
                'summary'   => 'Delete the listing for an external id',
                'responses' => array(200 => array('type' => 'object')) + $problems,
            ),
            'GET ' . self::BASE . 'runs/{id}' => array(
                'handler'   => $handler('showRun'),
                'auth'      => 'admin',
                'scope'     => self::RUNS,
                'tags'      => array(self::TAG),
                'summary'   => 'How an import run went',
                'responses' => array(200 => array('type' => 'object')) + $problems,
            ),
        );
    }

    /**
     * The 0.2 paths, which redirect to their new place under ext/listing-import/. Deprecated;
     * removed in 0.4, and core stops serving them from the sunset day. Core answers GET and POST listings and listings/{id} itself, so only the
     * other shapes are kept. A GET moves with 301, a write with 308, which keeps the method
     * and body.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function oldPaths(): array
    {
        $moved = array(
            'handler'    => array(self::class, 'moved'),
            'auth'       => 'none',
            'tags'       => array('Deprecated'),
            'summary'    => 'Moved to /api/v1/' . self::BASE . '... (Listing Import); removed in 0.4',
            'deprecated' => '2026-10-04',
            'sunset'     => '2027-04-04',
        );
        $write = $moved + array('responses' => array(308 => array('type' => 'null')));
        // A numeric id is a core listing, so OPTIONS and 405 for listings/12 never offer PUT.
        $external = $write + array('where' => array('external_id' => '(?![0-9]+(?:/|$))[^/]+'));

        return array(
            'POST listings:batch'           => $write,
            'PUT listings/{external_id}'    => $external,
            'DELETE listings/{external_id}' => $external,
            'GET runs/{id}'                 => $moved + array('responses' => array(301 => array('type' => 'null'))),
        );
    }

    /**
     * Redirect an old path to the same one under ext/listing-import/, query kept.
     *
     * @param array<string,string> $args
     */
    public static function moved(Request $request, Credential $credential, array $args): Response
    {
        $path   = (string)preg_replace('#^v[0-9]+/#', '', (string)$request->path());
        $query  = http_build_query($request->query(), '', '&', PHP_QUERY_RFC3986);
        $target = osc_api_url(self::BASE . str_replace('%3A', ':', implode('/', array_map('rawurlencode', explode('/', $path)))) . ($query === '' ? '' : '?' . $query));

        return new Response($request->isRead() ? 301 : 308, null, array('Location' => $target));
    }

    /**
     * Import one record into the key's source.
     */
    public function createListing(Request $request, Credential $credential, array $args = array()): Response
    {
        return $this->importOne($request->json(), $credential);
    }

    /**
     * Create or replace the listing for an external id with the whole record.
     */
    public function putListing(Request $request, Credential $credential, array $args): Response
    {
        $record = $request->json();
        if (isset($record['external_id']) && (string)$record['external_id'] !== $args['external_id']) {
            throw ApiProblem::of('validation_failed', 'The external_id in the body is not the one in the address.', array(
                'errors' => array(array('pointer' => '/external_id', 'code' => 'id_mismatch', 'message' => 'must match the address', 'in' => 'body')),
            ));
        }
        $record['external_id'] = $args['external_id'];

        return $this->importOne($record, $credential);
    }

    /**
     * Where one record stands: the listing it became, and whether it is still live.
     */
    public function showListing(Request $request, Credential $credential, array $args): Response
    {
        $source = $this->source($credential);
        $mapped = $this->store->mapped($source->id, $args['external_id']);
        $itemId = $this->liveItem($mapped);
        if ($itemId === null) {
            throw ApiProblem::of('not_found', 'No listing for this external id.');
        }

        return Response::ok(array(
            'external_id' => $args['external_id'],
            'item_id'     => $itemId,
            'status'      => ($mapped['e_status'] ?? 'active') === 'retired' ? 'removed_from_feed' : 'active',
            'url'         => $this->listings->url($itemId),
            'last_seen'   => $mapped['dt_last_seen'] ?? null,
            'synced_at'   => $mapped['dt_synced'] ?? null,
        ));
    }

    /**
     * Delete the listing for an external id. The owner asked, so it really is deleted.
     */
    public function deleteListing(Request $request, Credential $credential, array $args): Response
    {
        $source = $this->source($credential);
        $itemId = $this->liveItem($this->store->mapped($source->id, $args['external_id']));
        if ($itemId === null) {
            throw ApiProblem::of('not_found', 'No listing for this external id.');
        }
        if (!$this->listings->delete($itemId)) {
            throw ApiProblem::of('server_error', 'The listing could not be deleted.');
        }
        $this->store->forgetRecord($source->id, $args['external_id']);

        return Response::ok(array('external_id' => $args['external_id'], 'item_id' => $itemId, 'deleted' => true));
    }

    /**
     * Start a run for up to MAX_RECORDS records and queue them. The answer is at once; the
     * run's endpoint says how it went.
     */
    public function createBatch(Request $request, Credential $credential, array $args = array()): Response
    {
        $records = $request->json()['records'] ?? null;
        if (!is_array($records) || $records === array() || array_keys($records) !== range(0, count($records) - 1)) {
            throw ApiProblem::of('validation_failed', 'Send {"records": [...]} with at least one record.');
        }
        if (count($records) > Batch::MAX_RECORDS) {
            throw ApiProblem::of('too_large', 'At most ' . Batch::MAX_RECORDS . ' records per batch.');
        }
        $runId = $this->batch->queue($this->source($credential), $records, 'push');

        return Response::ok(array('run_id' => $runId, 'records' => count($records), 'status' => 'queued'), 202);
    }

    /**
     * How a run went. A key sees only runs of its own source.
     */
    public function showRun(Request $request, Credential $credential, array $args): Response
    {
        $run    = $this->store->run((int)$args['id']);
        $source = $this->source($credential);
        if ($run === null || (int)$run['fk_i_source_id'] !== $source->id) {
            throw ApiProblem::of('not_found', 'No such run.');
        }
        $counts = array();
        foreach (array('created', 'updated', 'unchanged', 'retired', 'failed') as $name) {
            $counts[$name] = (int)$run['i_' . $name];
        }

        return Response::ok(array(
            'run_id'      => (int)$run['pk_i_id'],
            'status'      => $run['dt_finished'] === null ? 'running' : 'finished',
            'records'     => (int)$run['i_total'],
            'counts'      => $counts,
            'started_at'  => (string)$run['dt_started'],
            'finished_at' => $run['dt_finished'] === null ? null : (string)$run['dt_finished'],
            'failures'    => $this->store->runErrors((int)$run['pk_i_id'], 50),
        ));
    }

    /**
     * @param array<string,mixed> $record
     */
    private function importOne(array $record, Credential $credential): Response
    {
        $source = $this->source($credential);
        $runId  = $this->store->startRun($source->id, 'push', false);
        $result = $this->importer->import($source, $record, $runId);
        $this->store->finishRun($runId, array($result['status'] => 1), 'Key ' . $credential->id());

        if ($result['status'] === Importer::FAILED) {
            $errors = array();
            foreach ($result['errors'] as $field => $message) {
                $errors[] = array('pointer' => '/' . str_replace('.', '/', (string)$field), 'code' => 'rejected', 'message' => (string)$message, 'in' => 'body');
            }

            throw ApiProblem::of('validation_failed', 'The record was not imported; see errors.', array('errors' => $errors));
        }
        $response = Response::ok(array(
            'status'      => $result['status'],
            'external_id' => $result['external_id'],
            'item_id'     => $result['item_id'],
            'run_id'      => $runId,
        ), $result['status'] === Importer::CREATED ? 201 : 200);

        return $result['warnings'] === array() ? $response : $response->withBodyMember('warnings', $result['warnings']);
    }

    /**
     * The listing a mapped record points at, while it is still on the site.
     *
     * @param array<string,mixed>|null $mapped
     */
    private function liveItem(?array $mapped): ?int
    {
        $itemId = $mapped === null || $mapped['fk_i_item_id'] === null ? null : (int)$mapped['fk_i_item_id'];

        return $itemId !== null && $this->listings->exists($itemId) ? $itemId : null;
    }

    /**
     * The source the key is linked to. A key linked to none, or to one that is off, is refused
     * rather than sent to another source.
     */
    private function source(Credential $credential): Source
    {
        $source = $this->store->sourceForKey((int)$credential->id());
        if ($source === null) {
            throw ApiProblem::of('conflict', 'This key is not linked to an import source, or its source is switched off. Link it under Plugins > Listing import > Sources.');
        }

        return $source;
    }
}
