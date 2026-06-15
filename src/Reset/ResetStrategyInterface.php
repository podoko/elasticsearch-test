<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Reset;

/**
 * Contract for index reset strategies between tests.
 */
interface ResetStrategyInterface
{
    /**
     * Prepares the worker index before the test.
     *
     * @param string $indexName logical index name (without token suffix)
     * @param string $token     current worker token (from TestToken::get())
     */
    public function prepare(string $indexName, string $token): void;

    /**
     * Cleans up the worker index after the test.
     *
     * @param string $indexName logical index name
     * @param string $token     current worker token
     */
    public function cleanup(string $indexName, string $token): void;

    /**
     * Removes the write-block placed on the source index in preparation for cloning.
     * No-op if the index does not exist or is not blocked.
     *
     * @param string $indexName logical name of the source index
     */
    public function unlockSource(string $indexName): void;
}
