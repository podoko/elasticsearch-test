<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest;

use Elastica\Client;
use Podoko\ElasticsearchTest\Reset\ResetStrategyInterface;

/**
 * Service Symfony dont le seul rôle est d'alimenter StaticState au boot du kernel.
 * L'injection se fait dans le constructeur (eager initialization lors du
 * premier accès au conteneur, avant les tests).
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

        // Sous ParaTest, on évite le unlock entre les tests (race condition : Worker A
        // pourrait déverrouiller la source pendant que Worker B est entre write-block et clone).
        // Le setUpBeforeClass() appelle explicitement unlockSourceIndexes() avant tout test.
        if (\getenv('PARATEST') === false) {
            // Crash recovery : retire tout write-block résiduel d'une run précédente (SIGKILL).
            StaticState::unlockSourceIndexes();

            // Unlock en fin de suite propre (sortie normale ou dd()/exit()).
            \register_shutdown_function(static function (): void {
                StaticState::unlockSourceIndexes();
            });
        }
    }
}
