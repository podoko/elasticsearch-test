<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit\Subscriber;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use Podoko\ElasticsearchDama\StaticState;

/**
 * Appelé après chaque test (quelle que soit son issue).
 * Supprime l'index de travail du worker courant.
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if (!StaticState::isInitialized()) {
            return;
        }

        StaticState::rollbackTest();
    }
}
