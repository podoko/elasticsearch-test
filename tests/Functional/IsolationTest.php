<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional;

use Podoko\ElasticsearchDama\Tests\Functional\Factory\PostFactory;
use Podoko\ElasticsearchDama\Tests\Functional\Support\FunctionalTestCase;

/**
 * Vérifie l'isolation entre tests : les documents créés dans un test
 * ne sont pas visibles dans un autre test.
 *
 * Chaque méthode indexe un nombre différent de documents et vérifie
 * que le total est exactement baseline + N (sans accumulation entre tests).
 */
final class IsolationTest extends FunctionalTestCase
{
    private const BASELINE_COUNT = 3;

    public function test_index_one_document_does_not_leak_into_other_tests(): void
    {
        // Indexer 1 document supplémentaire
        $post = PostFactory::createOne(['status' => 'published']);
        $this->index($post);

        self::assertSame(
            self::BASELINE_COUNT + 1,
            $this->countAll(),
            'Après indexation d\'1 doc, le total doit être baseline + 1.'
        );
    }

    public function test_index_five_documents_does_not_leak_into_other_tests(): void
    {
        // Indexer 5 documents supplémentaires
        $posts = PostFactory::createMany(5);
        $this->index(...$posts);

        self::assertSame(
            self::BASELINE_COUNT + 5,
            $this->countAll(),
            'Après indexation de 5 docs, le total doit être baseline + 5, '
            . 'pas baseline + 6 (leak du test précédent).'
        );
    }

    public function test_index_ten_documents_does_not_leak_into_other_tests(): void
    {
        $posts = PostFactory::createMany(10);
        $this->index(...$posts);

        self::assertSame(
            self::BASELINE_COUNT + 10,
            $this->countAll(),
        );
    }

    public function test_clean_clone_without_any_indexing(): void
    {
        // Aucune indexation dans ce test — le total doit rester à la baseline
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Sans indexation, le total doit rester à la baseline.'
        );
    }

    public function test_only_published_posts_are_indexed(): void
    {
        // Fondation maîtrisée : 2 publiés + 3 drafts → seul le compte total varie
        $this->index(
            ...PostFactory::createMany(2, ['status' => 'published']),
            ...PostFactory::createMany(3, ['status' => 'draft']),
        );

        self::assertSame(self::BASELINE_COUNT + 5, $this->countAll());
    }
}
