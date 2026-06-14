<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional\Support;

use Elastica\Document;
use Elastica\Index;
use Podoko\ElasticsearchDama\Tests\Functional\Model\Post;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Helper pour indexer des Post dans fos_elastica.index.posts et forcer un refresh.
 *
 * La lib ne force aucun refresh automatique — c'est à la charge des tests.
 * Ce helper regroupe l'indexation + refresh pour rendre les documents visibles
 * immédiatement après l'appel.
 */
final class PostIndexer
{
    private readonly Index $index;

    public function __construct(ContainerInterface $container)
    {
        /** @var Index $index */
        $index       = $container->get('fos_elastica.index.posts');
        $this->index = $index;
    }

    /**
     * Indexe un ou plusieurs Post et force un refresh pour les rendre visibles.
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
     * Force un refresh de l'index sans indexer de nouveau document.
     * Utile après des opérations de suppression.
     */
    public function refresh(): void
    {
        $this->index->refresh();
    }

    /**
     * Retourne le nombre total de documents dans l'index clone actuel.
     */
    public function countAll(): int
    {
        return (int) $this->index->count();
    }

    /**
     * Supprime un document par id et force un refresh.
     */
    public function delete(string $id): void
    {
        $this->index->deleteById($id);
        $this->index->refresh();
    }

    /**
     * Expose l'index sous-jacent pour des assertions bas niveau.
     */
    public function getIndex(): Index
    {
        return $this->index;
    }
}
