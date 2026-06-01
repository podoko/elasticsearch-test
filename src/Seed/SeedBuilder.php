<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Seed;

use Elastica\Client;
use Elastica\Request;

/**
 * Construit l'index seed à partir de données fournies sous forme de tableau.
 *
 * Usage via le CompilerPass / DI :
 *   new SeedBuilder($adminClient, ['posts' => [['title' => 'Hello']], ...])
 *
 * Pour des cas avancés (fixtures Doctrine, FOSElastica populate…), passez
 * votre propre callable à CloneResetStrategy::$seedCallback.
 */
final class SeedBuilder
{
    /**
     * @param Client                                   $adminClient Client Elastica brut.
     * @param array<string, list<array<string, mixed>>> $fixtures   Fixtures indexées par nom logique d'index.
     *                                                              Chaque élément est un tableau de documents.
     */
    public function __construct(
        private readonly Client $adminClient,
        private readonly array $fixtures = [],
    ) {}

    /**
     * Retourne un callable compatible avec CloneResetStrategy::$seedCallback.
     * Le callable reçoit (Client $client, string $seedIndexName).
     */
    public function buildCallback(string $indexName): callable
    {
        $fixtures = $this->fixtures[$indexName] ?? [];

        return static function (Client $client, string $seedIndexName) use ($fixtures): void {
            if (empty($fixtures)) {
                return;
            }

            // Bulk index de toutes les fixtures dans le seed.
            $bulkBody = '';

            foreach ($fixtures as $i => $document) {
                $id = $document['id'] ?? ((string) ($i + 1));
                $bulkBody .= \json_encode(['index' => ['_index' => $seedIndexName, '_id' => $id]]) . "\n";
                $bulkBody .= \json_encode($document) . "\n";
            }

            $client->request(
                '_bulk',
                Request::POST,
                $bulkBody,
                [],
                'application/x-ndjson',
            );
        };
    }
}
