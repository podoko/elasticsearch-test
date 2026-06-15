<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\PHPUnit\Subscriber;

use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension;
use Podoko\ElasticsearchTest\StaticState;

/**
 * Retire le write-block sur les index sources en fin de suite PHPUnit (sortie propre).
 *
 * Non actif sous ParaTest (PARATEST=1) : les workers conservent le write-block
 * jusqu'au prochain boot kernel pour éviter les races inter-workers.
 * La couverture des crashes est assurée par register_shutdown_function (dd()/exit())
 * et par StaticStateInitializer au boot suivant (SIGKILL).
 */
final class TestSuiteFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if (!ElasticsearchTestExtension::isBootstrapped()) {
            return;
        }

        if (\getenv('PARATEST') !== false) {
            return;
        }

        StaticState::unlockSourceIndexes();
    }
}
