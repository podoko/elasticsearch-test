<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional;

use Podoko\ElasticsearchTest\Tests\Functional\Factory\PostFactory;
use Podoko\ElasticsearchTest\Tests\Functional\Support\FunctionalTestCase;

/**
 * Verifies isolation between tests: documents created in one test
 * are not visible in another test.
 *
 * Each method indexes a different number of documents and checks
 * that the total is exactly baseline + N (no accumulation between tests).
 */
final class IsolationTest extends FunctionalTestCase
{
    private const BASELINE_COUNT = 3;

    public function testIndexOneDocumentDoesNotLeakIntoOtherTests(): void
    {
        // Index 1 additional document
        $post = PostFactory::createOne(['status' => 'published']);
        $this->index($post);

        self::assertSame(
            self::BASELINE_COUNT + 1,
            $this->countAll(),
            'After indexing 1 doc, the total must be baseline + 1.'
        );
    }

    public function testIndexFiveDocumentsDoesNotLeakIntoOtherTests(): void
    {
        // Index 5 additional documents
        $posts = PostFactory::createMany(5);
        $this->index(...$posts);

        self::assertSame(
            self::BASELINE_COUNT + 5,
            $this->countAll(),
            'After indexing 5 docs, the total must be baseline + 5, '
            .'not baseline + 6 (leak from the previous test).'
        );
    }

    public function testIndexTenDocumentsDoesNotLeakIntoOtherTests(): void
    {
        $posts = PostFactory::createMany(10);
        $this->index(...$posts);

        self::assertSame(
            self::BASELINE_COUNT + 10,
            $this->countAll(),
        );
    }

    public function testCleanCloneWithoutAnyIndexing(): void
    {
        // No indexing in this test — the total must stay at the baseline
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Without indexing, the total must remain at the baseline.'
        );
    }

    public function testOnlyPublishedPostsAreIndexed(): void
    {
        // Controlled baseline: 2 published + 3 drafts → only the total count changes
        $this->index(
            ...PostFactory::createMany(2, ['status' => 'published']),
            ...PostFactory::createMany(3, ['status' => 'draft']),
        );

        self::assertSame(self::BASELINE_COUNT + 5, $this->countAll());
    }
}
