<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest;

use Elastica\Client;
use Podoko\ElasticsearchTest\Reset\ResetStrategyInterface;

/**
 * Central static registry — equivalent of StaticDriver from dama/doctrine-test-bundle.
 *
 * Designed to be callable from the PHPUnit extension (outside the Symfony container)
 * via static methods. The Symfony container populates this registry at boot
 * through StaticStateInitializer.
 */
final class StaticState
{
    private static bool $initialized = false;

    /** @var string[] Logical names of managed indexes (FOSElastica keys, without token). */
    private static array $managedIndexes = [];

    private static ?ResetStrategyInterface $resetStrategy = null;

    private static ?Client $adminClient = null;

    private static ?string $elasticsearchUrl = null;

    /** @var array<string, true> Logical indexes cloned during the current test. */
    private static array $copiedIndexes = [];

    // -----------------------------------------------------------------------
    // Bootstrap (called by StaticStateInitializer from the Symfony container)
    // -----------------------------------------------------------------------

    public static function initialize(
        Client $adminClient,
        array $managedIndexes,
        ResetStrategyInterface $resetStrategy,
        string $elasticsearchUrl,
    ): void {
        self::$adminClient      = $adminClient;
        self::$managedIndexes   = $managedIndexes;
        self::$resetStrategy    = $resetStrategy;
        self::$elasticsearchUrl = $elasticsearchUrl;
        self::$initialized      = true;
        self::$copiedIndexes    = [];
    }

    // -----------------------------------------------------------------------
    // Per-test lifecycle (called by PHPUnit subscribers and LazyCloneIndex)
    // -----------------------------------------------------------------------

    /**
     * Returns true if a clone has already been created for this index in the current test.
     * Called by LazyCloneIndex::getName() to resolve the physical index name.
     */
    public static function hasCopy(string $indexName): bool
    {
        return isset(self::$copiedIndexes[$indexName]);
    }

    /**
     * Lazy clone: creates the source → worker clone for this index if not already done.
     * Called by LazyCloneIndex::ensureCopy() on the first write operation.
     * Idempotent: a second call for the same index is a no-op.
     */
    public static function copy(string $indexName): void
    {
        self::assertInitialized();

        if (isset(self::$copiedIndexes[$indexName])) {
            return;
        }

        self::$resetStrategy->prepare($indexName, TestToken::get());
        self::$copiedIndexes[$indexName] = true;
    }

    /**
     * Called by TestFinishedSubscriber: deletes only the clones created during this test.
     */
    public static function rollbackTest(): void
    {
        self::assertInitialized();

        $token    = TestToken::get();
        $strategy = self::$resetStrategy;

        foreach (\array_keys(self::$copiedIndexes) as $indexName) {
            $strategy->cleanup($indexName, $token);
        }

        self::$copiedIndexes = [];
    }

    /**
     * Removes the write-block placed on all source indexes.
     * Called at suite end (clean exit), on the next boot (SIGKILL recovery),
     * and via register_shutdown_function (dd()/exit()).
     *
     * No-op if StaticState is not initialized (crash guard before init).
     * Not executed under ParaTest — each worker keeps the write-block
     * until the next kernel boot, avoiding any race condition between workers.
     */
    public static function unlockSourceIndexes(): void
    {
        if (!self::$initialized) {
            return;
        }

        $strategy = self::$resetStrategy;

        foreach (self::$managedIndexes as $indexName) {
            $strategy->unlockSource($indexName);
        }
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    public static function getAdminClient(): Client
    {
        self::assertInitialized();

        return self::$adminClient;
    }

    /** @return string[] */
    public static function getManagedIndexes(): array
    {
        return self::$managedIndexes;
    }

    public static function getElasticsearchUrl(): string
    {
        self::assertInitialized();

        return self::$elasticsearchUrl;
    }

    // -----------------------------------------------------------------------
    // Reset (library unit tests)
    // -----------------------------------------------------------------------

    public static function reset(): void
    {
        self::$initialized      = false;
        self::$managedIndexes   = [];
        self::$resetStrategy    = null;
        self::$adminClient      = null;
        self::$elasticsearchUrl = null;
        self::$copiedIndexes    = [];
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private static function assertInitialized(): void
    {
        if (!self::$initialized) {
            throw new \LogicException(
                \sprintf(
                    '%s is not initialized. Make sure the bundle is configured '
                    . 'and that StaticStateInitializer::initialize() was called at startup.',
                    self::class,
                )
            );
        }
    }
}
