<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Reset;

use Elastica\Client;
use Elastica\Exception\ResponseException;
use Elastica\Request;

/**
 * Clone strategy — copies from a pre-existing source index.
 *
 * The user is responsible for creating and populating their indexes before the suite
 * (via fos:elastica:populate, Symfony fixtures, setUpBeforeClass…).
 *
 * Lifecycle:
 *   prepare()       → idempotent write-block on <index>, then _clone to <index>_<token>
 *   cleanup()       → deletes <index>_<token>
 *   unlockSource()  → removes the write-block on <index> (suite end or crash recovery)
 */
final class CloneResetStrategy implements ResetStrategyInterface
{
    public function __construct(
        private readonly Client $adminClient,
    ) {}

    // -----------------------------------------------------------------------
    // ResetStrategyInterface
    // -----------------------------------------------------------------------

    public function prepare(string $indexName, string $token): void
    {
        $targetName = self::workerName($indexName, $token);

        // Idempotent write-block on the source (required by _clone, safe in parallel).
        $this->adminClient->request(
            "$indexName/_settings",
            Request::PUT,
            ['index' => ['blocks' => ['write' => true]]],
        );

        // Delete any leftover clone from a previous crash.
        if ($this->indexExists($targetName)) {
            $this->adminClient->request($targetName, Request::DELETE);
        }

        // Clone source → worker (immediately writable, 0 replicas to stay green).
        $this->adminClient->request(
            "$indexName/_clone/$targetName",
            Request::POST,
            ['settings' => ['index.blocks.write' => false, 'index.number_of_replicas' => 0]],
        );

        $this->adminClient->request(
            "_cluster/health/$targetName",
            Request::GET,
            [],
            ['wait_for_status' => 'green', 'timeout' => '5s'],
        );
    }

    public function cleanup(string $indexName, string $token): void
    {
        $targetName = self::workerName($indexName, $token);

        if ($this->indexExists($targetName)) {
            $this->adminClient->request($targetName, Request::DELETE);
        }
    }

    public function unlockSource(string $indexName): void
    {
        if (!$this->indexExists($indexName)) {
            return;
        }

        $this->adminClient->request(
            "$indexName/_settings",
            Request::PUT,
            ['index' => ['blocks' => ['write' => false]]],
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public static function workerName(string $indexName, string $token): string
    {
        return $indexName . '_' . $token;
    }

    private function indexExists(string $indexName): bool
    {
        try {
            $response = $this->adminClient->request("$indexName", Request::HEAD);

            return $response->getStatus() === 200;
        } catch (ResponseException $e) {
            if ($e->getResponse()->getStatus() === 404) {
                return false;
            }

            throw $e;
        }
    }
}
