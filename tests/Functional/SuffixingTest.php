<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional;

use Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension;
use Podoko\ElasticsearchDama\StaticState;
use Podoko\ElasticsearchDama\TestToken;
use Podoko\ElasticsearchDama\Tests\Functional\Factory\PostFactory;
use Podoko\ElasticsearchDama\Tests\Functional\Support\FunctionalTestCase;

/**
 * Vérifie le comportement de clonage paresseux de LazyCloneIndex.
 *
 * Avant toute écriture, l'index pointe sur la source (posts).
 * Après la première écriture, il pointe sur le clone worker (posts_<token>).
 */
final class SuffixingTest extends FunctionalTestCase
{
    public function test_extension_is_bootstrapped(): void
    {
        self::assertTrue(
            ElasticsearchDamaExtension::isBootstrapped(),
            'L\'extension PHPUnit doit être bootstrappée pour activer le suffixage.'
        );
    }

    public function test_index_points_to_source_before_any_write(): void
    {
        $index = static::getContainer()->get('fos_elastica.index.posts');

        self::assertSame(
            'posts',
            $index->getName(),
            'Avant toute écriture, l\'index doit pointer sur la source.'
        );
    }

    public function test_index_points_to_worker_clone_after_write(): void
    {
        $this->index(PostFactory::createOne());

        $index = static::getContainer()->get('fos_elastica.index.posts');

        self::assertSame(
            'posts_' . TestToken::get(),
            $index->getName(),
            'Après la première écriture, l\'index doit pointer sur le clone worker.'
        );
    }

    public function test_no_clone_created_for_read_only_test(): void
    {
        // Un test qui ne fait que lire ne doit pas créer de clone.
        $this->countAll();

        self::assertFalse(
            StaticState::hasCopy('posts'),
            'Un test sans écriture ne doit pas créer de clone.'
        );
    }

    public function test_clone_is_created_on_first_write(): void
    {
        self::assertFalse(StaticState::hasCopy('posts'));

        $this->index(PostFactory::createOne());

        self::assertTrue(StaticState::hasCopy('posts'));
    }
}
