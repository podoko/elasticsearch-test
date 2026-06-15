<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\PHPUnit\Subscriber;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension;
use Podoko\ElasticsearchTest\StaticState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Called after each test (regardless of its outcome).
 * Deletes the current worker's index.
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if (!ElasticsearchTestExtension::isBootstrapped()) {
            return;
        }

        $test = $event->test();
        if (!$test instanceof TestMethod) {
            return;
        }

        $testClass = $test->className();

        if (!class_exists(KernelTestCase::class) || !is_a($testClass, KernelTestCase::class, true)) {
            return;
        }

        if (!StaticState::isInitialized()) {
            return;
        }

        StaticState::rollbackTest();
    }
}
