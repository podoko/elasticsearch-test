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
        $this->client   = $this->createMock(Client::class);
        $this->strategy = new CloneResetStrategy($this->client);
    }

    public function test_worker_name_format(): void
    {
        self::assertSame('posts_2', CloneResetStrategy::workerName('posts', '2'));
    }

    public function test_prepare_write_blocks_source_before_clone(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method, array $body = []) use (&$calls): Response {
                    $calls[] = [$path, $method, $body];

                    // HEAD posts_1 → 404 (pas de clone résiduel)
                    if ($method === Request::HEAD) {
                        return new Response('', 404);
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        // Le write-block doit être le premier appel PUT, avant le _clone.
        $putCalls = \array_values(\array_filter($calls, static fn ($c) => $c[1] === Request::PUT));
        self::assertNotEmpty($putCalls);
        self::assertSame('posts/_settings', $putCalls[0][0]);
        self::assertTrue($putCalls[0][2]['index']['blocks']['write']);
    }

    public function test_prepare_clones_source_to_worker_index(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method) use (&$calls): Response {
                    $calls[] = [$path, $method];

                    if ($method === Request::HEAD) {
                        return new Response('', 404); // clone résiduel absent
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        $paths = \array_column($calls, 0);
        self::assertContains('posts/_clone/posts_1', $paths);
    }

    public function test_prepare_deletes_residual_clone_before_cloning(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method) use (&$calls): Response {
                    $calls[] = [$path, $method];

                    if ($method === Request::HEAD) {
                        return new Response('{}', 200); // clone résiduel présent
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        $paths = \array_column($calls, 0);
        self::assertContains('posts_1', $paths); // DELETE du résiduel
        self::assertContains('posts/_clone/posts_1', $paths);
    }

    public function test_cleanup_deletes_worker_index(): void
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

        $deleteCalls = \array_values(\array_filter($calls, static fn ($c) => $c[1] === Request::DELETE));
        self::assertNotEmpty($deleteCalls);
        self::assertSame('posts_1', $deleteCalls[0][0]);
    }

    public function test_cleanup_is_noop_when_worker_index_absent(): void
    {
        $this->client
            ->expects($this->once()) // uniquement le HEAD
            ->method('request')
            ->with('posts_1', Request::HEAD)
            ->willReturn(new Response('', 404));

        $this->strategy->cleanup('posts', '1');
    }

    public function test_unlock_source_removes_write_block(): void
    {
        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method, array $body = []) use (&$calls): Response {
                    $calls[] = [$path, $method, $body];

                    if ($method === Request::HEAD) {
                        return new Response('{}', 200); // index existe
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->unlockSource('posts');

        $putCalls = \array_values(\array_filter($calls, static fn ($c) => $c[1] === Request::PUT));
        self::assertNotEmpty($putCalls);
        self::assertSame('posts/_settings', $putCalls[0][0]);
        self::assertFalse($putCalls[0][2]['index']['blocks']['write']);
    }

    public function test_unlock_source_is_noop_when_index_absent(): void
    {
        $this->client
            ->expects($this->once()) // uniquement le HEAD
            ->method('request')
            ->with('posts', Request::HEAD)
            ->willReturn(new Response('', 404));

        $this->strategy->unlockSource('posts');
    }
}
