<?php

/**
 * Setup script for Elasticsearch indexes used by the functional tests.
 *
 * Run ONCE before phpunit/paratest:
 *
 *   php tests/Functional/App/bin/setup-test-indexes.php
 *
 * This script simulates what a final user would do in their own application:
 * create the indexes (mapping via fos:elastica:reset) and populate them with fixtures.
 *
 * fos:elastica:reset bypasses any leftover write-block (DELETE ignores index.blocks.write)
 * and recreates the indexes with the mapping defined in fos_elastica.yaml — single source of truth.
 *
 * Note: for the clone benchmark, run `app:benchmark:seed` after this script
 * to replace the 'articles' index with 10k+ documents.
 */

declare(strict_types=1);

use Elastica\Document;
use Podoko\ElasticsearchTest\Tests\Functional\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 4).'/vendor/autoload.php';

// Load environment variables from .env.test.
$envFile = dirname(__DIR__, 4).'/.env.test';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!isset($_ENV[$key]) && !getenv($key)) {
            $_ENV[$key] = $value;
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
    'APP_SECRET' => 'test-secret',
] as $key => $value) {
    if (!getenv($key)) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
}

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';

// -----------------------------------------------------------------------
// 1. Recreate indexes via fos:elastica:reset
//    Reads the mapping from fos_elastica.yaml — no duplication.
// -----------------------------------------------------------------------
$kernel = new Kernel();
$application = new Application($kernel);
$application->setAutoExit(false);

$output = new ConsoleOutput();

$exitCode = $application->run(
    new ArrayInput(['command' => 'fos:elastica:reset', '--no-interaction' => true]),
    $output,
);

if (0 !== $exitCode) {
    exit($exitCode);
}

// -----------------------------------------------------------------------
// 2. Push fixtures via the admin client.
//    The mapping is already created by fos:elastica:reset above.
// -----------------------------------------------------------------------
// StaticState is initialized at Kernel boot (PodokoElasticsearchTestBundle::boot).
$adminClient = Podoko\ElasticsearchTest\StaticState::getAdminClient();

$postsIndex = $adminClient->getIndex('posts');
$postsIndex->addDocuments([
    new Document('1', ['title' => 'Hello World', 'status' => 'published', 'body' => 'First test article.']),
    new Document('2', ['title' => 'Draft Post',  'status' => 'draft',     'body' => 'Draft article.']),
    new Document('3', ['title' => 'About Us',    'status' => 'published', 'body' => 'About page.']),
]);
$postsIndex->refresh();
echo "Index 'posts' populated with 3 documents.\n";

$articlesIndex = $adminClient->getIndex('articles');
$articlesIndex->addDocuments([
    new Document('1', ['title' => 'Introduction to Cloud Computing', 'content' => 'Cloud computing...', 'category' => 'tech',    'tags' => ['cloud', 'devops'], 'author' => 'Alice',  'publishedAt' => '2024-01-15T10:00:00', 'views' => 1200]),
    new Document('2', ['title' => 'Docker in Practice',             'content' => 'Containerization...', 'category' => 'tech',    'tags' => ['docker', 'linux'], 'author' => 'Bob',    'publishedAt' => '2024-03-20T14:30:00', 'views' => 850]),
    new Document('3', ['title' => 'AI and Ethics',                  'content' => 'The ethical challenges...', 'category' => 'science', 'tags' => ['ai', 'data'],   'author' => 'Claire', 'publishedAt' => '2024-06-01T09:00:00', 'views' => 3400]),
]);
$articlesIndex->refresh();
echo "Index 'articles' populated with 3 documents (benchmark: run app:benchmark:seed --count=10000).\n";

// -----------------------------------------------------------------------
// 3. Force-merge source indexes to 1 segment.
//    _clone copies Lucene segments one by one via hardlinks; fewer
//    segments = less cluster coordination = faster clone.
// -----------------------------------------------------------------------
foreach (['posts', 'articles'] as $indexName) {
    $adminClient->request("$indexName/_forcemerge", Elastica\Request::POST, [], ['max_num_segments' => 1]);
    echo "Index '$indexName' force-merged to 1 segment.\n";
}
