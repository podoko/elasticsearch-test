<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\Support;

use Podoko\ElasticsearchTest\Tests\Functional\App\Kernel;
use Podoko\ElasticsearchTest\Tests\Functional\Model\Post;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for the library's functional tests.
 *
 * Prerequisite: the `posts` index must exist and be populated before running the suite.
 * Run `php tests/Functional/App/bin/setup-test-indexes.php` once before phpunit/paratest.
 *
 * Index cloning is lazy: the source → worker clone is only created on the first write
 * operation in the test (via LazyCloneIndex). Reads go directly to the source (posts).
 *
 * Lifecycle:
 *   test() → first write → StaticState::copy() → clone created
 *   tearDown() → ensureKernelShutdown()
 *   Test\Finished → StaticState::rollbackTest() → clones deleted
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    // -----------------------------------------------------------------------
    // Test case helpers
    // -----------------------------------------------------------------------

    protected function indexer(): PostIndexer
    {
        return new PostIndexer(static::getContainer());
    }

    /**
     * Indexes one or more Posts into the worker index and forces a refresh.
     */
    protected function index(Post ...$posts): void
    {
        $this->indexer()->index(...$posts);
    }

    /**
     * Returns the total number of documents in the worker index.
     */
    protected function countAll(): int
    {
        return $this->indexer()->countAll();
    }

    /**
     * Deletes a document by id and forces a refresh.
     */
    protected function deletePost(string $id): void
    {
        $this->indexer()->delete($id);
    }
}
