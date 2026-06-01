<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Unit\Reset;

use Elastica\Client;
use Elastica\Request;
use Elastica\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Podoko\ElasticsearchDama\Reset\CloneResetStrategy;

final class CloneResetStrategyTest extends TestCase
{
    private MockObject&Client $client;
    private CloneResetStrategy $strategy;

    protected function setUp(): void
    {
        $this->client   = $this->createMock(Client::class);
        $this->strategy = new CloneResetStrategy($this->client);
    }

    public function test_seed_name_format(): void
    {
        self::assertSame('posts_seed', CloneResetStrategy::seedName('posts'));
    }

    public function test_worker_name_format(): void
    {
        self::assertSame('posts_2', CloneResetStrategy::workerName('posts', '2'));
    }

    public function test_seed_creates_index_and_blocks_writes_when_not_existing(): void
    {
        // HEAD → 404 (n'existe pas)
        $notFound = new Response('', 404);
        $ok       = new Response('{}', 200);

        $this->client
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $path, string $method) use ($notFound, $ok): Response {
                if ($method === Request::HEAD) {
                    return $notFound;
                }

                return $ok;
            });

        $this->strategy->seed('posts');
    }

    public function test_seed_is_noop_when_already_existing(): void
    {
        $existing = new Response('{}', 200);

        $this->client
            ->expects($this->once()) // Seulement le HEAD
            ->method('request')
            ->with('posts_seed', Request::HEAD)
            ->willReturn($existing);

        $this->strategy->seed('posts');
    }

    public function test_prepare_clones_seed_to_worker_index(): void
    {
        $existing    = new Response('{}', 200);
        $notFound    = new Response('', 404);
        $cloneOk     = new Response('{}', 200);
        $healthOk    = new Response('{}', 200);

        $calls = [];

        $this->client
            ->method('request')
            ->willReturnCallback(
                function (string $path, string $method) use ($existing, $notFound, $cloneOk, $healthOk, &$calls): Response {
                    $calls[] = [$path, $method];

                    if ($method === Request::HEAD && $path === 'posts_seed') {
                        return $existing;  // Le seed existe
                    }

                    if ($method === Request::HEAD && $path === 'posts_1') {
                        return $notFound;  // Le clone n'existe pas
                    }

                    if (\str_contains($path, '_clone')) {
                        return $cloneOk;
                    }

                    if (\str_contains($path, '_cluster/health')) {
                        return $healthOk;
                    }

                    return new Response('{}', 200);
                }
            );

        $this->strategy->prepare('posts', '1');

        $paths = \array_column($calls, 0);
        self::assertContains('posts_seed/_clone/posts_1', $paths);
    }

    public function test_cleanup_deletes_worker_index(): void
    {
        $existing = new Response('{}', 200);

        $this->client
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $path, string $method) use ($existing): Response {
                return $existing;
            });

        $this->strategy->cleanup('posts', '1');
    }
}
