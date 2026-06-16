<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Unit\Reset;

use Elastica\Client;
use Elastica\Request;
use Elastica\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Podoko\ElasticsearchTest\Reset\CloneResetStrategy;

final class CloneResetStrategyTest extends TestCase
{
    private MockObject&Client $client;
    private CloneResetStrategy $strategy;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->strategy = new CloneResetStrategy($this->client);
    }

    public function testWorkerNameFormat(): void
    {
        self::assertSame('posts_2', CloneResetStrategy::workerName('posts', '2'));
    }

    public function testPrepareWriteBlocksSourceBeforeClone(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method, array $body = []) use (&$calls): Response {
                    $calls[] = [$path, $method, $body];

                    // HEAD posts_1 → 404 (no leftover clone)
                    if (Request::HEAD === $method) {
                        return new Response('', 404);
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        // The write-block must be the first PUT call, before _clone.
        $putCalls = \array_values(\array_filter($calls, static fn ($c) => Request::PUT === $c[1]));
        self::assertNotEmpty($putCalls);
        self::assertSame('posts/_settings', $putCalls[0][0]);
        self::assertTrue($putCalls[0][2]['index']['blocks']['write']);
    }

    public function testPrepareClonesSourceToWorkerIndex(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method) use (&$calls): Response {
                    $calls[] = [$path, $method];

                    if (Request::HEAD === $method) {
                        return new Response('', 404); // no leftover clone
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        $paths = \array_column($calls, 0);
        self::assertContains('posts/_clone/posts_1', $paths);
    }

    public function testPrepareDeletesResidualCloneBeforeCloning(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method) use (&$calls): Response {
                    $calls[] = [$path, $method];

                    if (Request::HEAD === $method) {
                        return new Response('{}', 200); // leftover clone present
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        $paths = \array_column($calls, 0);
        self::assertContains('posts_1', $paths); // DELETE of the leftover
        self::assertContains('posts/_clone/posts_1', $paths);
    }

    public function testCleanupDeletesWorkerIndex(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method) use (&$calls): Response {
                    $calls[] = [$path, $method];

                    return new Response('{}', 200);
                }
            );

        $this->strategy->cleanup('posts', '1');

        $deleteCalls = \array_values(\array_filter($calls, static fn ($c) => Request::DELETE === $c[1]));
        self::assertNotEmpty($deleteCalls);
        self::assertSame('posts_1', $deleteCalls[0][0]);
    }

    public function testCleanupIsNoopWhenWorkerIndexAbsent(): void
    {
        $this->client
            ->expects($this->once()) // only the HEAD
            ->method('request')
            ->with('posts_1', Request::HEAD)
            ->willReturn(new Response('', 404));

        $this->strategy->cleanup('posts', '1');
    }

    public function testUnlockSourceRemovesWriteBlock(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method, array $body = []) use (&$calls): Response {
                    $calls[] = [$path, $method, $body];

                    if (Request::HEAD === $method) {
                        return new Response('{}', 200); // index exists
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->unlockSource('posts');

        $putCalls = \array_values(\array_filter($calls, static fn ($c) => Request::PUT === $c[1]));
        self::assertNotEmpty($putCalls);
        self::assertSame('posts/_settings', $putCalls[0][0]);
        self::assertFalse($putCalls[0][2]['index']['blocks']['write']);
    }

    public function testUnlockSourceIsNoopWhenIndexAbsent(): void
    {
        $this->client
            ->expects($this->once()) // only the HEAD
            ->method('request')
            ->with('posts', Request::HEAD)
            ->willReturn(new Response('', 404));

        $this->strategy->unlockSource('posts');
    }
}
