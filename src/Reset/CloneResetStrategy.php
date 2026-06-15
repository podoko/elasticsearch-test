<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Reset;

use Elastica\Client;
use Elastica\Exception\ResponseException;
use Elastica\Request;

/**
 * Stratégie clone — copie depuis l'index source préexistant.
 *
 * L'utilisateur est responsable de créer et peupler ses index avant la suite
 * (via fos:elastica:populate, fixtures Symfony, setUpBeforeClass…).
 *
 * Cycle de vie :
 *   prepare()       → write-block idempotent sur <index>, puis _clone vers <index>_<token>
 *   cleanup()       → supprime <index>_<token>
 *   unlockSource()  → retire le write-block sur <index> (fin de suite ou crash recovery)
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

        // Write-block idempotent sur la source (requis par _clone, safe en parallèle).
        $this->adminClient->request(
            "$indexName/_settings",
            Request::PUT,
            ['index' => ['blocks' => ['write' => true]]],
        );

        // Supprime un clone résiduel éventuel (crash précédent).
        if ($this->indexExists($targetName)) {
            $this->adminClient->request($targetName, Request::DELETE);
        }

        // Clone source → worker (writable immédiatement).
        $this->adminClient->request(
            "$indexName/_clone/$targetName",
            Request::POST,
            ['settings' => ['index.blocks.write' => false]],
        );

        $this->adminClient->request(
            "_cluster/health/$targetName",
            Request::GET,
            [],
            ['wait_for_status' => 'yellow', 'timeout' => '5s'],
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
