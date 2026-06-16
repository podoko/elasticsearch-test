<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\PHPUnit\Subscriber;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Called before each test.
 *
 * Index cloning is now lazy: LazyCloneIndex creates the source → worker clone
 * only on the first write operation in the test.
 * This subscriber therefore no longer needs to boot the kernel or clone anything.
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
    }
}
