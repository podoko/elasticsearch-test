<?php

/**
 * Script de setup des index Elasticsearch pour les tests fonctionnels.
 *
 * À lancer UNE FOIS avant phpunit/paratest :
 *
 *   php tests/Functional/App/bin/setup-test-indexes.php
 *
 * Ce script simule ce que ferait un utilisateur final dans sa propre application :
 * créer les index (mapping) et les peupler avec des fixtures de test.
 *
 * Il supprime et recrée `posts` à chaque exécution pour repartir d'un état propre,
 * et bypasse tout write-block résiduel d'un crash SIGKILL précédent (DELETE ignore
 * index.blocks.write).
 */

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$url    = \getenv('ELASTICSEARCH_URL') ?: 'http://localhost:9200';
$client = new \Elastica\Client(['url' => $url . '/']);

$fixtures = [
    new \Elastica\Document('1', ['title' => 'Hello World', 'status' => 'published', 'body' => 'Premier article de test.']),
    new \Elastica\Document('2', ['title' => 'Draft Post',  'status' => 'draft',     'body' => 'Article en brouillon.']),
    new \Elastica\Document('3', ['title' => 'About Us',    'status' => 'published', 'body' => 'Page à propos.']),
];

// Supprime l'index existant (bypass write-block, reset propre entre deux runs).
try {
    $client->request('posts', \Elastica\Request::DELETE);
    echo "Index 'posts' supprimé.\n";
} catch (\Elastica\Exception\ResponseException $e) {
    if ($e->getResponse()->getStatus() !== 404) {
        throw $e;
    }
}

// Crée l'index avec le mapping.
$client->request('posts', \Elastica\Request::PUT, [
    'mappings' => [
        'properties' => [
            'title'  => ['type' => 'text'],
            'status' => ['type' => 'keyword'],
            'body'   => ['type' => 'text'],
        ],
    ],
]);

// Indexe les fixtures et force un refresh pour les rendre visibles.
$postsIndex = $client->getIndex('posts');
$postsIndex->addDocuments($fixtures);
$postsIndex->refresh();

echo \sprintf("Index 'posts' créé avec %d documents (mapping : title/text, status/keyword, body/text).\n", \count($fixtures));
