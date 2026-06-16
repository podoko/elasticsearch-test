<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Podoko\ElasticsearchTest\TestToken;

final class TestTokenTest extends TestCase
{
    private string|false $savedTestToken;
    private string|false $savedUniqueTestToken;

    protected function setUp(): void
    {
        $this->savedTestToken = \getenv('TEST_TOKEN');
        $this->savedUniqueTestToken = \getenv('UNIQUE_TEST_TOKEN');

        TestToken::reset();
        \putenv('TEST_TOKEN');
        \putenv('UNIQUE_TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        TestToken::reset();

        false !== $this->savedTestToken
            ? \putenv('TEST_TOKEN='.$this->savedTestToken)
            : \putenv('TEST_TOKEN');

        false !== $this->savedUniqueTestToken
            ? \putenv('UNIQUE_TEST_TOKEN='.$this->savedUniqueTestToken)
            : \putenv('UNIQUE_TEST_TOKEN');
    }

    public function testDefaultTokenIsOne(): void
    {
        self::assertSame('1', TestToken::get());
    }

    public function testReadsTestTokenEnv(): void
    {
        \putenv('TEST_TOKEN=3');
        self::assertSame('3', TestToken::get());
    }

    public function testFallsBackToUniqueTestToken(): void
    {
        \putenv('TEST_TOKEN=');
        \putenv('UNIQUE_TEST_TOKEN=abc-def');
        self::assertSame('abc-def', TestToken::get());
    }

    public function testSanitizesInvalidCharacters(): void
    {
        \putenv('TEST_TOKEN=foo/bar:baz');
        self::assertSame('foo-bar-baz', TestToken::get());
    }

    public function testCachesResolvedValue(): void
    {
        \putenv('TEST_TOKEN=5');
        $first = TestToken::get();
        \putenv('TEST_TOKEN=99');
        $second = TestToken::get();

        self::assertSame($first, $second);
    }
}
