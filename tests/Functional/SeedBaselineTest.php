<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional;

use Podoko\ElasticsearchTest\Tests\Functional\Support\FunctionalTestCase;

/**
 * Verifies that each test starts with exactly the seed documents (3 fixtures).
 *
 * This test guards two critical invariants:
 *   - Fixtures are correctly injected into the seed.
 *   - Each test starts from the same baseline regardless of execution order.
 *
 * Under ParaTest, multiple workers run these methods in parallel.
 * If a worker sees a count other than 3, that is a cross-worker collision.
 */
final class SeedBaselineTest extends FunctionalTestCase
{
    private const BASELINE_COUNT = 3;

    public function testBaselineAtStartOfFirstTest(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'The clone must contain exactly 3 seed fixtures at the start of the test.'
        );
    }

    public function testBaselineAtStartOfSecondTest(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'The clone must be reset between tests: 3 fixtures expected.'
        );
    }

    public function testBaselineAtStartOfThirdTest(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Same result for a third consecutive test.'
        );
    }

    /**
     * Verifies that the fixed IDs of the baseline fixtures are present.
     */
    public function testFixtureIdsArePresent(): void
    {
        $index = $this->indexer()->getIndex();

        // Look up each baseline document by its id
        foreach (['1', '2', '3'] as $fixtureId) {
            $document = $index->getDocument($fixtureId);
            self::assertSame($fixtureId, $document->getId());
        }
    }
}
