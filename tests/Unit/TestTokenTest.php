<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Podoko\ElasticsearchDama\TestToken;

final class TestTokenTest extends TestCase
{
    protected function setUp(): void
    {
        TestToken::reset();
    }

    protected function tearDown(): void
    {
        TestToken::reset();
        \putenv('TEST_TOKEN');
        \putenv('UNIQUE_TEST_TOKEN');
    }

    public function test_default_token_is_one(): void
    {
        self::assertSame('1', TestToken::get());
    }

    public function test_reads_test_token_env(): void
    {
        \putenv('TEST_TOKEN=3');
        self::assertSame('3', TestToken::get());
    }

    public function test_falls_back_to_unique_test_token(): void
    {
        \putenv('TEST_TOKEN=');
        \putenv('UNIQUE_TEST_TOKEN=abc-def');
        self::assertSame('abc-def', TestToken::get());
    }

    public function test_sanitizes_invalid_characters(): void
    {
        \putenv('TEST_TOKEN=foo/bar:baz');
        self::assertSame('foo-bar-baz', TestToken::get());
    }

    public function test_caches_resolved_value(): void
    {
        \putenv('TEST_TOKEN=5');
        $first  = TestToken::get();
        \putenv('TEST_TOKEN=99');
        $second = TestToken::get();

        self::assertSame($first, $second);
    }
}
