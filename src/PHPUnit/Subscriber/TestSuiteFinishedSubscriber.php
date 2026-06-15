<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\PHPUnit\Subscriber;

use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension;
use Podoko\ElasticsearchTest\StaticState;

/**
 * Removes the write-block from source indexes at PHPUnit suite end (clean exit).
 *
 * Not active under ParaTest (PARATEST=1): workers keep the write-block
 * until the next kernel boot to avoid inter-worker races.
 * Crash coverage is handled by register_shutdown_function (dd()/exit())
 * and by StaticStateInitializer on the next boot (SIGKILL).
 */
final class TestSuiteFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if (!ElasticsearchTestExtension::isBootstrapped()) {
            return;
        }

        if (false !== \getenv('PARATEST')) {
            return;
        }

        StaticState::unlockSourceIndexes();
    }
}
