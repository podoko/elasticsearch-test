<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Seed;

use Elastica\Client;
use Elastica\Request;

/**
 * Construit le callback de peuplement des seeds depuis des fixtures tableaux.
 *
 * Usage via le DI (elasticsearch_dama.yaml → seed.fixtures) :
 *   SeedBuilder::buildGlobalCallback() retourne une Closure unique capable
 *   de peupler n'importe quel seed, en dérivant le nom logique depuis le nom
 *   du seed (ex : "posts_seed" → "posts") pour retrouver les fixtures.
 *
 * Pour des cas avancés (fixtures Doctrine, FOSElastica populate…), passez
 * votre propre callable à CloneResetStrategy::$seedCallback.
 */
final class SeedBuilder
{
    /**
     * @param Client                                    $adminClient Client Elastica brut.
     * @param array<string, list<array<string, mixed>>> $fixtures    Fixtures indexées par nom logique d'index.
     *                                                               Chaque élément est une liste de documents.
     */
    public function __construct(
        private readonly Client $adminClient,
        private readonly array $fixtures = [],
    ) {}

    /**
     * Retourne un Closure unique compatible avec CloneResetStrategy::$seedCallback.
     * Le Closure reçoit (Client $client, string $seedIndexName).
     *
     * Il déduit le nom logique depuis le nom du seed en retirant le suffixe "_seed"
     * (ex : "posts_seed" → "posts"), puis recherche les fixtures correspondantes.
     *
     * Utilisé comme service "factory" dans le conteneur Symfony :
     *   new Definition(\Closure::class)
     *       ->setFactory([new Reference('elasticsearch_dama.seed_builder'), 'buildGlobalCallback'])
     */
    public function buildGlobalCallback(): \Closure
    {
        $fixtures = $this->fixtures;

        return static function (Client $client, string $seedIndexName) use ($fixtures): void {
            // "posts_seed" → "posts"
            $logicalName    = (string) \preg_replace('/_seed$/', '', $seedIndexName);
            $indexFixtures  = $fixtures[$logicalName] ?? [];

            if (empty($indexFixtures)) {
                return;
            }

            $bulkBody = '';

            foreach ($indexFixtures as $i => $document) {
                $id        = $document['id'] ?? ((string) ($i + 1));
                $bulkBody .= \json_encode(['index' => ['_index' => $seedIndexName, '_id' => $id]], \JSON_THROW_ON_ERROR) . "\n";
                $bulkBody .= \json_encode($document, \JSON_THROW_ON_ERROR) . "\n";
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

    /**
     * Retourne un callable spécifique à un index donné (usage autonome hors DI).
     * Le callable reçoit (Client $client, string $seedIndexName).
     *
     * @deprecated Préférer buildGlobalCallback() pour l'intégration DI.
     */
    public function buildCallback(string $indexName): callable
    {
        $fixtures = $this->fixtures[$indexName] ?? [];

        return static function (Client $client, string $seedIndexName) use ($fixtures): void {
            if (empty($fixtures)) {
                return;
            }

            $bulkBody = '';

            foreach ($fixtures as $i => $document) {
                $id        = $document['id'] ?? ((string) ($i + 1));
                $bulkBody .= \json_encode(['index' => ['_index' => $seedIndexName, '_id' => $id]], \JSON_THROW_ON_ERROR) . "\n";
                $bulkBody .= \json_encode($document, \JSON_THROW_ON_ERROR) . "\n";
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
