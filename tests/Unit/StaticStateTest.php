<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Podoko\ElasticsearchDama\Reset\ResetStrategyInterface;
use Podoko\ElasticsearchDama\StaticState;
use Podoko\ElasticsearchDama\TestToken;

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
        StaticState::beginTest();
    }

    public function test_is_initialized_after_initialize(): void
    {
        $this->initialize();
        self::assertTrue(StaticState::isInitialized());
    }

    public function test_begin_test_calls_prepare_for_each_index(): void
    {
        $this->initialize(['posts', 'comments']);

        $this->strategy
            ->expects($this->exactly(2))
            ->method('prepare')
            ->with(self::anything(), '1');

        StaticState::beginTest();
    }

    public function test_rollback_test_calls_cleanup_for_each_index(): void
    {
        $this->initialize(['posts']);

        $this->strategy
            ->expects($this->once())
            ->method('cleanup')
            ->with('posts', '1');

        StaticState::rollbackTest();
    }

    public function test_ensure_seed_exists_calls_seed_for_each_index(): void
    {
        $this->initialize(['posts', 'tags']);

        $this->strategy
            ->expects($this->exactly(2))
            ->method('seed');

        StaticState::ensureSeedExists();
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
