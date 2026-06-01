<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit\Subscriber;

use PHPUnit\Event\TestRunner\Started;
use PHPUnit\Event\TestRunner\StartedSubscriber;
use Podoko\ElasticsearchDama\StaticState;

/**
 * Appelé une fois par processus worker au démarrage de la suite PHPUnit.
 * S'assure que l'index seed existe (le crée si nécessaire).
 */
final class TestRunnerStartedSubscriber implements StartedSubscriber
{
    public function notify(Started $event): void
    {
        if (!StaticState::isInitialized()) {
            return;
        }

        StaticState::ensureSeedExists();
    }
}
