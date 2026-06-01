<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Reset;

use Elastica\Client;
use Elastica\Exception\ResponseException;
use Elastica\Request;

/**
 * Stratégie B1 — Clone depuis un seed figé.
 *
 * Cycle de vie :
 *   seed()    → crée <index>_seed (mappings + fixtures, write-bloqué)
 *   prepare() → bloque les écritures sur le seed (idempotent), puis _clone
 *               vers <index>_<token>, retire le write-block sur le clone
 *   cleanup() → supprime <index>_<token>
 *
 * Le seed est partagé entre tous les workers (clones concurrents depuis
 * un index write-blocked = safe selon la doc ES).
 */
final class CloneResetStrategy implements ResetStrategyInterface
{
    /**
     * @param Client            $adminClient  Client Elastica brut (pas le client FOSElastica décoré).
     * @param \Closure|null     $seedCallback Fonction qui peuple le seed : reçoit (Client $client, string $seedIndexName).
     *                                        Si null, le seed est créé vide (mappings uniquement).
     * @param array<string, array<string, mixed>> $indexMappings Mappings Elastica par nom logique d'index.
     * @param array<string, array<string, mixed>> $indexSettings Settings ES par nom logique d'index.
     */
    public function __construct(
        private readonly Client $adminClient,
        private readonly ?\Closure $seedCallback = null,
        private readonly array $indexMappings = [],
        private readonly array $indexSettings = [],
    ) {}

    // -----------------------------------------------------------------------
    // ResetStrategyInterface
    // -----------------------------------------------------------------------

    public function seed(string $indexName): void
    {
        $seedName = $this->seedName($indexName);

        // Si le seed existe déjà (processus parallèle ou rejeu), on ne touche à rien.
        if ($this->indexExists($seedName)) {
            return;
        }

        // Création du seed.
        $body = [];

        if (isset($this->indexSettings[$indexName])) {
            $body['settings'] = $this->indexSettings[$indexName];
        }

        if (isset($this->indexMappings[$indexName])) {
            $body['mappings'] = $this->indexMappings[$indexName];
        }

        $this->adminClient->request(
            $seedName,
            Request::PUT,
            $body,
        );

        // Peuplement via callback si fourni.
        if ($this->seedCallback !== null) {
            ($this->seedCallback)($this->adminClient, $seedName);

            // Refresh pour que les fixtures soient visibles dans le clone.
            $this->adminClient->request("$seedName/_refresh", Request::POST);
        }

        // Bloquer les écritures sur le seed pour permettre les clones concurrents.
        $this->blockWrites($seedName);
    }

    public function prepare(string $indexName, string $token): void
    {
        $seedName   = $this->seedName($indexName);
        $targetName = $this->workerName($indexName, $token);

        // Assure que le seed existe (cas où seed() n'a pas encore été appelé).
        if (!$this->indexExists($seedName)) {
            $this->seed($indexName);
        }

        // Supprime un éventuel index de travail résiduel (crash précédent).
        if ($this->indexExists($targetName)) {
            $this->adminClient->request($targetName, Request::DELETE);
        }

        // Clone le seed vers l'index de travail.
        $this->adminClient->request(
            "$seedName/_clone/$targetName",
            Request::POST,
            ['settings' => ['index.blocks.write' => false]],
        );

        // Attendre que le clone soit vert (la plupart du temps instantané en mono-nœud).
        $this->adminClient->request(
            "_cluster/health/$targetName",
            Request::GET,
            [],
            ['wait_for_status' => 'yellow', 'timeout' => '5s'],
        );
    }

    public function cleanup(string $indexName, string $token): void
    {
        $targetName = $this->workerName($indexName, $token);

        if ($this->indexExists($targetName)) {
            $this->adminClient->request($targetName, Request::DELETE);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public static function seedName(string $indexName): string
    {
        return $indexName . '_seed';
    }

    public static function workerName(string $indexName, string $token): string
    {
        return $indexName . '_' . $token;
    }

    private function blockWrites(string $indexName): void
    {
        $this->adminClient->request(
            "$indexName/_settings",
            Request::PUT,
            ['index' => ['blocks' => ['write' => true]]],
        );
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
