<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit\Subscriber;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension;
use Podoko\ElasticsearchDama\StaticState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Appelé après chaque test (quelle que soit son issue).
 * Supprime l'index de travail du worker courant.
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if (!ElasticsearchDamaExtension::isBootstrapped()) {
            return;
        }

        $testClass = $event->test()->className();

        if (!class_exists(KernelTestCase::class) || !is_a($testClass, KernelTestCase::class, true)) {
            return;
        }

        if (!StaticState::isInitialized()) {
            return;
        }

        StaticState::rollbackTest();
    }
}
