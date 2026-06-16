<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Podoko\ElasticsearchTest\Reset\ResetStrategyInterface;
use Podoko\ElasticsearchTest\StaticState;
use Podoko\ElasticsearchTest\TestToken;

final class StaticStateTest extends TestCase
{
    private MockObject&ResetStrategyInterface $strategy;

    private string|false $savedTestToken;
    private string|false $savedUniqueTestToken;

    protected function setUp(): void
    {
        $this->savedTestToken = \getenv('TEST_TOKEN');
        $this->savedUniqueTestToken = \getenv('UNIQUE_TEST_TOKEN');

        StaticState::reset();
        TestToken::reset();
        \putenv('TEST_TOKEN');
        \putenv('UNIQUE_TEST_TOKEN');

        $this->strategy = $this->createMock(ResetStrategyInterface::class);
    }

    protected function tearDown(): void
    {
        StaticState::reset();
        TestToken::reset();

        false !== $this->savedTestToken
            ? \putenv('TEST_TOKEN='.$this->savedTestToken)
            : \putenv('TEST_TOKEN');

        false !== $this->savedUniqueTestToken
            ? \putenv('UNIQUE_TEST_TOKEN='.$this->savedUniqueTestToken)
            : \putenv('UNIQUE_TEST_TOKEN');
    }

    public function testThrowsWhenNotInitialized(): void
    {
        $this->expectException(\LogicException::class);
        StaticState::copy('posts');
    }

    public function testIsInitializedAfterInitialize(): void
    {
        $this->initialize();
        self::assertTrue(StaticState::isInitialized());
    }

    public function testCopyCallsPrepareForGivenIndex(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy
            ->expects($this->once())
            ->method('prepare')
            ->with('posts', '1');

        StaticState::copy('posts');
    }

    public function testCopyIsIdempotent(): void
    {
        $this->initialize(['posts']);

        $this->strategy
            ->expects($this->once())
            ->method('prepare');

        StaticState::copy('posts');
        StaticState::copy('posts');
    }

    public function testHasCopyReturnsFalseBeforeCopy(): void
    {
        $this->initialize(['posts']);
        self::assertFalse(StaticState::hasCopy('posts'));
    }

    public function testHasCopyReturnsTrueAfterCopy(): void
    {
        $this->initialize(['posts']);
        $this->strategy->method('prepare');

        StaticState::copy('posts');
        self::assertTrue(StaticState::hasCopy('posts'));
    }

    public function testRollbackOnlyCleansCopiedIndexes(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy->method('prepare');

        // Only copy 'posts', not 'comments'
        StaticState::copy('posts');

        $this->strategy
            ->expects($this->once())
            ->method('cleanup')
            ->with('posts', '1');

        StaticState::rollbackTest();
    }

    public function testRollbackDoesNothingWhenNothingCopied(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy
            ->expects($this->never())
            ->method('cleanup');

        StaticState::rollbackTest();
    }

    public function testRollbackResetsCopiedState(): void
    {
        $this->initialize(['posts']);
        $this->strategy->method('prepare');
        $this->strategy->method('cleanup');

        StaticState::copy('posts');
        self::assertTrue(StaticState::hasCopy('posts'));

        StaticState::rollbackTest();
        self::assertFalse(StaticState::hasCopy('posts'));
    }

    public function testUnlockSourceIndexesCallsUnlockForEachManagedIndex(): void
    {
        $this->initialize(['posts', 'tags']);

        $this->strategy
            ->expects($this->exactly(2))
            ->method('unlockSource');

        StaticState::unlockSourceIndexes();
    }

    public function testUnlockSourceIndexesIsNoopWhenNotInitialized(): void
    {
        // StaticState not initialized — must not throw.
        $this->strategy
            ->expects($this->never())
            ->method('unlockSource');

        StaticState::unlockSourceIndexes();
    }

    public function testResetClearsState(): void
    {
        $this->initialize();
        StaticState::reset();
        self::assertFalse(StaticState::isInitialized());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @param string[] $indexes */
    private function initialize(array $indexes = ['posts']): void
    {
        $clientMock = $this->createMock(\Elastica\Client::class);

        StaticState::initialize(
            adminClient: $clientMock,
            managedIndexes: $indexes,
            resetStrategy: $this->strategy,
            elasticsearchUrl: 'http://localhost:9200',
        );
    }
}
