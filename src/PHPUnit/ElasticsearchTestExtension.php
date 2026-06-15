<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\PHPUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Podoko\ElasticsearchTest\PHPUnit\Subscriber\TestFinishedSubscriber;
use Podoko\ElasticsearchTest\PHPUnit\Subscriber\TestPreparedSubscriber;
use Podoko\ElasticsearchTest\PHPUnit\Subscriber\TestSuiteFinishedSubscriber;

/**
 * PHPUnit 11 extension — entry point of the library.
 *
 * Register in phpunit.xml:
 *
 *   <extensions>
 *     <bootstrap class="Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension"/>
 *   </extensions>
 *
 * This extension reads no parameters: all configuration (Elasticsearch URL,
 * managed indexes, strategy, fixtures) comes from the Symfony bundle
 * (config/packages/test/elasticsearch_test.yaml).
 *
 * Role of this class:
 *   1. Set $bootstrapped = true (sentinel used by RefreshForcingClient::getIndex()
 *      to enable suffixing only within PHPUnit context).
 *   2. Register the PHPUnit subscribers that clone/delete the worker index.
 *
 * StaticState is initialized separately by PodokoElasticsearchTestBundle::boot()
 * (eager call to StaticStateInitializer) on the first Symfony kernel boot, in
 * the test setUp(). This is therefore guaranteed before Test\Prepared is dispatched.
 */
final class ElasticsearchTestExtension implements Extension
{
    private static bool $bootstrapped = false;

    public static function isBootstrapped(): bool
    {
        return self::$bootstrapped;
    }

    /** Resets the flag (only needed for unit tests of this library itself). */
    public static function reset(): void
    {
        self::$bootstrapped = false;
    }

    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        self::$bootstrapped = true;

        $facade->registerSubscribers(
            new TestPreparedSubscriber(),
            new TestFinishedSubscriber(),
            new TestSuiteFinishedSubscriber(),
        );
    }
}
