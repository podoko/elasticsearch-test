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
        $this->savedTestToken       = \getenv('TEST_TOKEN');
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

        $this->savedTestToken !== false
            ? \putenv('TEST_TOKEN=' . $this->savedTestToken)
            : \putenv('TEST_TOKEN');

        $this->savedUniqueTestToken !== false
            ? \putenv('UNIQUE_TEST_TOKEN=' . $this->savedUniqueTestToken)
            : \putenv('UNIQUE_TEST_TOKEN');
    }

    public function test_throws_when_not_initialized(): void
    {
        $this->expectException(\LogicException::class);
        StaticState::copy('posts');
    }

    public function test_is_initialized_after_initialize(): void
    {
        $this->initialize();
        self::assertTrue(StaticState::isInitialized());
    }

    public function test_copy_calls_prepare_for_given_index(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy
            ->expects($this->once())
            ->method('prepare')
            ->with('posts', '1');

        StaticState::copy('posts');
    }

    public function test_copy_is_idempotent(): void
    {
        $this->initialize(['posts']);

        $this->strategy
            ->expects($this->once())
            ->method('prepare');

        StaticState::copy('posts');
        StaticState::copy('posts');
    }

    public function test_has_copy_returns_false_before_copy(): void
    {
        $this->initialize(['posts']);
        self::assertFalse(StaticState::hasCopy('posts'));
    }

    public function test_has_copy_returns_true_after_copy(): void
    {
        $this->initialize(['posts']);
        $this->strategy->method('prepare');

        StaticState::copy('posts');
        self::assertTrue(StaticState::hasCopy('posts'));
    }

    public function test_rollback_only_cleans_copied_indexes(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy->method('prepare');

        // On ne copie que 'posts', pas 'comments'
        StaticState::copy('posts');

        $this->strategy
            ->expects($this->once())
            ->method('cleanup')
            ->with('posts', '1');

        StaticState::rollbackTest();
    }

    public function test_rollback_does_nothing_when_nothing_copied(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy
            ->expects($this->never())
            ->method('cleanup');

        StaticState::rollbackTest();
    }

    public function test_rollback_resets_copied_state(): void
    {
        $this->initialize(['posts']);
        $this->strategy->method('prepare');
        $this->strategy->method('cleanup');

        StaticState::copy('posts');
        self::assertTrue(StaticState::hasCopy('posts'));

        StaticState::rollbackTest();
        self::assertFalse(StaticState::hasCopy('posts'));
    }

    public function test_unlock_source_indexes_calls_unlock_for_each_managed_index(): void
    {
        $this->initialize(['posts', 'tags']);

        $this->strategy
            ->expects($this->exactly(2))
            ->method('unlockSource');

        StaticState::unlockSourceIndexes();
    }

    public function test_unlock_source_indexes_is_noop_when_not_initialized(): void
    {
        // StaticState non initialisé — ne doit pas lever d'exception.
        $this->strategy
            ->expects($this->never())
            ->method('unlockSource');

        StaticState::unlockSourceIndexes();
    }

    public function test_reset_clears_state(): void
    {
        $this->initialize();
        StaticState::reset();
        self::assertFalse(StaticState::isInitialized());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

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
