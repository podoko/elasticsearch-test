<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest;

/**
 * Resolves the current worker token.
 *
 * In single-process PHPUnit mode, returns '1'.
 * In parallel ParaTest mode, reads TEST_TOKEN or UNIQUE_TEST_TOKEN
 * to isolate each worker on its own index.
 */
final class TestToken
{
    private static ?string $resolved = null;

    public static function get(): string
    {
        if (null !== self::$resolved) {
            return self::$resolved;
        }

        // ParaTest injects TEST_TOKEN (integer, e.g. "1", "2", "3") or
        // UNIQUE_TEST_TOKEN (UUID, available since paratest 6.x).
        // TEST_TOKEN is preferred for shorter index names.
        $token = \getenv('TEST_TOKEN');

        if (false === $token || '' === $token) {
            $token = \getenv('UNIQUE_TEST_TOKEN');
        }

        if (false === $token || '' === $token) {
            $token = '1';
        }

        // Sanitize: keep only valid characters for an ES index name
        // (alphanumerics and hyphens).
        $token = \preg_replace('/[^a-zA-Z0-9\-]/', '-', $token);

        return self::$resolved = $token;
    }

    /** Resets the cached token (only needed for unit tests of this library itself). */
    public static function reset(): void
    {
        self::$resolved = null;
    }
}
