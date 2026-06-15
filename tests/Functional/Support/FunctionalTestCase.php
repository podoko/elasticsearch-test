<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional\Support;

use Podoko\ElasticsearchDama\Tests\Functional\App\Kernel;
use Podoko\ElasticsearchDama\Tests\Functional\Model\Post;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Classe de base pour les tests fonctionnels de la lib.
 *
 * Prérequis : l'index `posts` doit exister et être peuplé avant de lancer la suite.
 * Lancer `php tests/Functional/App/bin/setup-test-indexes.php` une fois avant phpunit/paratest.
 *
 * Le clonage des index est paresseux : le clone source → worker n'est créé
 * que lors de la première opération d'écriture dans le test (via LazyCloneIndex).
 * Les lectures vont directement sur la source (posts).
 *
 * Cycle de vie :
 *   test() → première écriture → StaticState::copy() → clone créé
 *   tearDown() → ensureKernelShutdown()
 *   Test\Finished → StaticState::rollbackTest() → suppression des clones créés
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    // -----------------------------------------------------------------------
    // Helpers pour les cas de test
    // -----------------------------------------------------------------------

    protected function indexer(): PostIndexer
    {
        return new PostIndexer(static::getContainer());
    }

    /**
     * Indexe un ou plusieurs Post dans l'index de travail et force un refresh.
     */
    protected function index(Post ...$posts): void
    {
        $this->indexer()->index(...$posts);
    }

    /**
     * Retourne le nombre total de documents dans l'index de travail.
     */
    protected function countAll(): int
    {
        return $this->indexer()->countAll();
    }

    /**
     * Supprime un document par id et force un refresh.
     */
    protected function deletePost(string $id): void
    {
        $this->indexer()->delete($id);
    }
}
