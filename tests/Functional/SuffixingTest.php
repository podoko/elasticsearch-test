<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional;

use Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension;
use Podoko\ElasticsearchTest\StaticState;
use Podoko\ElasticsearchTest\TestToken;
use Podoko\ElasticsearchTest\Tests\Functional\Factory\PostFactory;
use Podoko\ElasticsearchTest\Tests\Functional\Support\FunctionalTestCase;

/**
 * Verifies the lazy-cloning behaviour of LazyCloneIndex.
 *
 * Before any write, the index points to the source (posts).
 * After the first write, it points to the worker clone (posts_<token>).
 */
final class SuffixingTest extends FunctionalTestCase
{
    public function test_extension_is_bootstrapped(): void
    {
        self::assertTrue(
            ElasticsearchTestExtension::isBootstrapped(),
            'The PHPUnit extension must be bootstrapped to activate suffixing.'
        );
    }

    public function test_index_points_to_source_before_any_write(): void
    {
        $index = static::getContainer()->get('fos_elastica.index.posts');

        self::assertSame(
            'posts',
            $index->getName(),
            'Before any write, the index must point to the source.'
        );
    }

    public function test_index_points_to_worker_clone_after_write(): void
    {
        $this->index(PostFactory::createOne());

        $index = static::getContainer()->get('fos_elastica.index.posts');

        self::assertSame(
            'posts_' . TestToken::get(),
            $index->getName(),
            'After the first write, the index must point to the worker clone.'
        );
    }

    public function test_no_clone_created_for_read_only_test(): void
    {
        // A read-only test must not create a clone.
        $this->countAll();

        self::assertFalse(
            StaticState::hasCopy('posts'),
            'A read-only test must not create a clone.'
        );
    }

    public function test_clone_is_created_on_first_write(): void
    {
        self::assertFalse(StaticState::hasCopy('posts'));

        $this->index(PostFactory::createOne());

        self::assertTrue(StaticState::hasCopy('posts'));
    }
}
