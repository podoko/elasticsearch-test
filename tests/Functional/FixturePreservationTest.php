<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional;

use Podoko\ElasticsearchTest\Tests\Functional\Factory\PostFactory;
use Podoko\ElasticsearchTest\Tests\Functional\Support\FunctionalTestCase;

/**
 * Verifies that mutations within a test (delete, modify) do not cross test boundaries:
 * each clone starts from the intact seed.
 */
final class FixturePreservationTest extends FunctionalTestCase
{
    private const BASELINE_COUNT = 3;

    /**
     * Deletes baseline document id='1' and asserts it is gone.
     * → The next test must find id='1' again (fresh clone from the seed).
     */
    public function testDeletedFixtureIsGoneWithinThisTest(): void
    {
        $this->deletePost('1');

        self::assertSame(
            self::BASELINE_COUNT - 1,
            $this->countAll(),
            'After deleting a baseline document, the total must be baseline - 1.'
        );
    }

    /**
     * Asserts that id='1' is present → proves that the deletion in the
     * previous test did not affect this clone.
     */
    public function testFixtureIsIntactAfterDeletionInPreviousTest(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'This test\'s clone must be intact (3 fixtures), '
            .'regardless of deletions in the previous test.'
        );

        // Directly verify that id='1' exists in this clone
        $index = $this->indexer()->getIndex();
        $document = $index->getDocument('1');
        self::assertSame('1', $document->getId());
    }

    /**
     * Indexes a new document in this test.
     */
    public function testNewDocumentIsVisibleOnlyInThisTest(): void
    {
        $post = PostFactory::createOne(['title' => 'Unique post for this test']);
        $this->index($post);

        self::assertSame(self::BASELINE_COUNT + 1, $this->countAll());
    }

    /**
     * Without indexing or deletion, the count stays at 3.
     * → Proves that the document created in the previous test is not visible here.
     */
    public function testNoNewDocumentFromPreviousTest(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'The document created in the previous test must not be visible here.'
        );
    }

    /**
     * Verifies that the fixtures have the expected statuses.
     */
    public function testFixtureStatusIsPreserved(): void
    {
        $index = $this->indexer()->getIndex();

        // Fixtures id='1' and id='3' are 'published', id='2' is 'draft'
        $doc1 = $index->getDocument('1');
        self::assertSame('published', $doc1->get('status'));

        $doc2 = $index->getDocument('2');
        self::assertSame('draft', $doc2->get('status'));
    }
}
