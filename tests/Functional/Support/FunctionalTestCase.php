<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional\Support;

use Podoko\ElasticsearchDama\Tests\Functional\App\Kernel;
use Podoko\ElasticsearchDama\Tests\Functional\Model\Post;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Classe de base pour les tests fonctionnels de la lib.
 *
 * Cycle de vie :
 *   setUp() → bootKernel() → PodokoElasticsearchDamaBundle::boot()
 *             → StaticState::initialize()
 *   Test\Prepared (PHPUnit) → StaticState::beginTest() → clone du seed
 *   test()
 *   Test\Finished (PHPUnit) → StaticState::rollbackTest() → suppression du clone
 *   tearDown() → shutdownKernel()
 *
 * setUp() est appelé AVANT Test\Prepared (vérifié dans TestCase.php:511-516),
 * donc StaticState est prêt quand le clone est lancé.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        // Boot du kernel avant Test\Prepared : garantit StaticState::initialize().
        static::bootKernel();
    }

    protected function indexer(): PostIndexer
    {
        return new PostIndexer(static::getContainer());
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers pour les cas de test
    // -----------------------------------------------------------------------

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
