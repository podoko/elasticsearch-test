<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest;

use Elastica\Client;
use Podoko\ElasticsearchTest\Reset\ResetStrategyInterface;

/**
 * Symfony service whose sole purpose is to populate StaticState at kernel boot.
 * Injection happens in the constructor (eager initialization on first container access,
 * before any test).
 */
final class StaticStateInitializer
{
    public function __construct(
        private readonly Client $adminClient,
        private readonly array $managedIndexes,
        private readonly ResetStrategyInterface $resetStrategy,
        private readonly string $elasticsearchUrl,
    ) {
        StaticState::initialize(
            adminClient: $this->adminClient,
            managedIndexes: $this->managedIndexes,
            resetStrategy: $this->resetStrategy,
            elasticsearchUrl: $this->elasticsearchUrl,
        );

        // Under ParaTest, avoid unlocking between tests (race condition: Worker A
        // could unlock the source while Worker B is between write-block and clone).
        // setUpBeforeClass() explicitly calls unlockSourceIndexes() before any test.
        if (\getenv('PARATEST') === false) {
            // Crash recovery: remove any leftover write-block from a previous run (SIGKILL).
            StaticState::unlockSourceIndexes();

            // Unlock at the end of a clean suite (normal exit or dd()/exit()).
            \register_shutdown_function(static function (): void {
                StaticState::unlockSourceIndexes();
            });
        }
    }
}
