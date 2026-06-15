<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama;

use Elastica\Client;
use Podoko\ElasticsearchDama\Reset\ResetStrategyInterface;

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

        // Le clonage est désormais paresseux (déclenché à la première écriture via LazyCloneIndex).
        // Les lectures vont directement sur le seed (posts_seed) — il doit exister avant le
        // premier accès en lecture. On le garantit ici au boot du kernel, avant tout test.
        StaticState::ensureSeedExists();
    }
}
