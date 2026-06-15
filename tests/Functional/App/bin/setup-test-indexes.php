<?php

/**
 * Script de setup des index Elasticsearch pour les tests fonctionnels.
 *
 * À lancer UNE FOIS avant phpunit/paratest :
 *
 *   php tests/Functional/App/bin/setup-test-indexes.php
 *
 * Ce script simule ce que ferait un utilisateur final dans sa propre application :
 * créer les index (mapping via fos:elastica:reset) et les peupler avec des fixtures.
 *
 * fos:elastica:reset bypasse tout write-block résiduel (DELETE ignore index.blocks.write)
 * et recrée les index avec le mapping défini dans fos_elastica.yaml — source unique de vérité.
 *
 * Note : pour le benchmark de clone, utilisez `app:benchmark:seed` après ce script
 * pour remplacer l'index 'articles' par 10k+ documents.
 */

declare(strict_types=1);

use Elastica\Document;
use Podoko\ElasticsearchDama\Tests\Functional\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// Charge les variables d'environnement depuis .env.test.
$envFile = dirname(__DIR__, 4) . '/.env.test';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!isset($_ENV[$key]) && !getenv($key)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$esUrl = getenv('ELASTICSEARCH_URL') ?: 'http://localhost:9200';
$parts = parse_url($esUrl);
foreach ([
    'ELASTICSEARCH_HOST' => $parts['host'] ?? 'localhost',
    'ELASTICSEARCH_PORT' => (string) ($parts['port'] ?? 9200),
    'APP_SECRET'         => 'test-secret',
] as $key => $value) {
    if (!getenv($key)) {
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
}

$_SERVER['APP_ENV']   = 'test';
$_SERVER['APP_DEBUG'] = '1';

// -----------------------------------------------------------------------
// 1. Recréer les index via fos:elastica:reset
//    Lit le mapping depuis fos_elastica.yaml — pas de duplication.
// -----------------------------------------------------------------------
$kernel      = new Kernel();
$application = new Application($kernel);
$application->setAutoExit(false);

$output = new ConsoleOutput();

$exitCode = $application->run(
    new ArrayInput(['command' => 'fos:elastica:reset', '--no-interaction' => true]),
    $output,
);

if ($exitCode !== 0) {
    exit($exitCode);
}

// -----------------------------------------------------------------------
// 2. Pousser les fixtures via l'admin client.
//    Le mapping est déjà créé par fos:elastica:reset ci-dessus.
// -----------------------------------------------------------------------
// StaticState est initialisé au boot du Kernel (PodokoElasticsearchDamaBundle::boot).
$adminClient = \Podoko\ElasticsearchDama\StaticState::getAdminClient();

$postsIndex = $adminClient->getIndex('posts');
$postsIndex->addDocuments([
    new Document('1', ['title' => 'Hello World', 'status' => 'published', 'body' => 'Premier article de test.']),
    new Document('2', ['title' => 'Draft Post',  'status' => 'draft',     'body' => 'Article en brouillon.']),
    new Document('3', ['title' => 'About Us',    'status' => 'published', 'body' => 'Page à propos.']),
]);
$postsIndex->refresh();
echo "Index 'posts' peuplé avec 3 documents.\n";

$articlesIndex = $adminClient->getIndex('articles');
$articlesIndex->addDocuments([
    new Document('1', ['title' => 'Introduction au cloud', 'content' => 'Le cloud computing...', 'category' => 'tech',    'tags' => ['cloud', 'devops'], 'author' => 'Alice',  'publishedAt' => '2024-01-15T10:00:00', 'views' => 1200]),
    new Document('2', ['title' => 'Docker en pratique',   'content' => 'Conteneurisation...',    'category' => 'tech',    'tags' => ['docker', 'linux'], 'author' => 'Bob',    'publishedAt' => '2024-03-20T14:30:00', 'views' => 850]),
    new Document('3', ['title' => 'IA et éthique',        'content' => 'Les enjeux éthiques...', 'category' => 'science', 'tags' => ['ia', 'data'],      'author' => 'Claire', 'publishedAt' => '2024-06-01T09:00:00', 'views' => 3400]),
]);
$articlesIndex->refresh();
echo "Index 'articles' peuplé avec 3 documents (benchmark: relancer app:benchmark:seed --count=10000).\n";

// -----------------------------------------------------------------------
// 3. Force-merge les index sources à 1 segment.
//    _clone copie les segments Lucene un par un via hardlinks ; moins de
//    segments = moins de coordination cluster = clone plus rapide.
// -----------------------------------------------------------------------
foreach (['posts', 'articles'] as $indexName) {
    $adminClient->request("$indexName/_forcemerge", \Elastica\Request::POST, [], ['max_num_segments' => 1]);
    echo "Index '$indexName' force-mergé à 1 segment.\n";
}
