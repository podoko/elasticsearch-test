<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\PHPUnit\Subscriber;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Appelé avant chaque test.
 *
 * Le clonage des index est désormais paresseux : LazyCloneIndex crée le clone
 * seed → worker uniquement lors de la première opération d'écriture dans le test.
 * Ce subscriber n'a donc plus besoin de démarrer le kernel ni de cloner quoi que ce soit.
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
    }
}
