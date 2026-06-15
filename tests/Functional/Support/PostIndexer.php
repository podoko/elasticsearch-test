<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\Support;

use Elastica\Document;
use Elastica\Index;
use Podoko\ElasticsearchTest\Tests\Functional\Model\Post;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Helper to index Posts into fos_elastica.index.posts and force a refresh.
 *
 * The library does not force any automatic refresh — that is the test's responsibility.
 * This helper combines indexing + refresh to make documents visible immediately after the call.
 */
final class PostIndexer
{
    private readonly Index $index;

    public function __construct(ContainerInterface $container)
    {
        /** @var Index $index */
        $index = $container->get('fos_elastica.index.posts');
        $this->index = $index;
    }

    /**
     * Indexes one or more Posts and forces a refresh to make them visible.
     */
    public function index(Post ...$posts): void
    {
        if (empty($posts)) {
            return;
        }

        $documents = [];

        foreach ($posts as $post) {
            $documents[] = new Document($post->id, $post->toDocument());
        }

        $this->index->addDocuments($documents);
        $this->index->refresh();
    }

    /**
     * Forces an index refresh without indexing any new document.
     * Useful after delete operations.
     */
    public function refresh(): void
    {
        $this->index->refresh();
    }

    /**
     * Returns the total number of documents in the current clone index.
     */
    public function countAll(): int
    {
        return (int) $this->index->count();
    }

    /**
     * Deletes a document by id and forces a refresh.
     */
    public function delete(string $id): void
    {
        $this->index->deleteById($id);
        $this->index->refresh();
    }

    /**
     * Exposes the underlying index for low-level assertions.
     */
    public function getIndex(): Index
    {
        return $this->index;
    }
}
