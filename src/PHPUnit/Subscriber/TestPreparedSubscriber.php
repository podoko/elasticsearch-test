<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit\Subscriber;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use Podoko\ElasticsearchDama\StaticState;

/**
 * Appelé avant chaque test.
 * Clone le seed vers l'index de travail du worker courant.
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        if (!StaticState::isInitialized()) {
            return;
        }

        StaticState::beginTest();
    }
}
