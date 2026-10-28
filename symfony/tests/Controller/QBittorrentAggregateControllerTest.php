<?php

namespace App\Tests\Controller;

use App\Controller\QBittorrentAggregateController;
use App\Entity\ServiceInstance;
use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Issue #131 — the global poll (sidebar badge + completion toasts) sums
 * every enabled qBittorrent instance.
 */
#[AllowMockObjectsWithoutExpectations]
final class QBittorrentAggregateControllerTest extends TestCase
{
    private function instance(string $slug, string $name): ServiceInstance
    {
        return new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, $slug, $name, 'http://10.0.0.2:8080');
    }

    /**
     * @param list<ServiceInstance>                        $instances
     * @param array<string, list<array<string, mixed>>|\Throwable> $torrentsBySlug
     */
    private function poll(array $instances, array $torrentsBySlug, ?string $enabledFlag = null): array
    {
        $qbt = $this->createMock(QBittorrentClient::class);
        $qbt->method('withInstance')->willReturnCallback(function (ServiceInstance $i) use ($torrentsBySlug) {
            $clone = $this->createMock(QBittorrentClient::class);
            $result = $torrentsBySlug[$i->getSlug()] ?? [];
            if ($result instanceof \Throwable) {
                $clone->method('getTorrents')->willThrowException($result);
            } else {
                $clone->method('getTorrents')->willReturn($result);
            }
            return $clone;
        });
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturn($instances);
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $k === 'qbittorrent_enabled' ? $enabledFlag : null);

        $controller = new QBittorrentAggregateController($qbt, $provider, $config, new NullLogger());
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        $response = $controller->apiPollSummary();
        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    public function testSingleInstanceKeepsThePre131ItemShape(): void
    {
        $out = $this->poll([$this->instance('qbittorrent-1', 'qBittorrent 1')], [
            'qbittorrent-1' => [
                ['hash' => 'aa', 'state' => 'downloading', 'name' => 'A', 'size' => 10],
                ['hash' => 'bb', 'state' => 'paused',      'name' => 'B', 'size' => 20],
            ],
        ]);

        self::assertSame(200, $out['status']);
        self::assertSame(1, $out['body']['active']);
        self::assertSame(['qbittorrent-1' => 1], $out['body']['active_by_instance']);
        self::assertSame(
            [['key' => 'qbittorrent-1:aa', 'hash' => 'aa', 'state' => 'downloading', 'name' => 'A', 'size' => 10]],
            $out['body']['items'],
            'no `instance` label with a single instance; paused is not a toast state',
        );
    }

    public function testSeveralInstancesAreSummedAndLabelled(): void
    {
        // The same hash on two instances (cross-seed) stays two items.
        $out = $this->poll(
            [$this->instance('qbittorrent-1', 'Home'), $this->instance('qbittorrent-2', 'Seedbox')],
            [
                'qbittorrent-1' => [['hash' => 'aa', 'state' => 'downloading', 'name' => 'A', 'size' => 1]],
                'qbittorrent-2' => [
                    ['hash' => 'aa', 'state' => 'completed',   'name' => 'A', 'size' => 1],
                    ['hash' => 'cc', 'state' => 'downloading', 'name' => 'C', 'size' => 3],
                ],
            ],
        );

        self::assertSame(2, $out['body']['active']);
        self::assertSame(['qbittorrent-1' => 1, 'qbittorrent-2' => 1], $out['body']['active_by_instance']);
        self::assertSame(['qbittorrent-1:aa', 'qbittorrent-2:aa', 'qbittorrent-2:cc'], array_column($out['body']['items'], 'key'));
        self::assertSame(['Home', 'Seedbox', 'Seedbox'], array_column($out['body']['items'], 'instance'));
    }

    public function testOneThrowingInstanceDoesNotFailThePoll(): void
    {
        $out = $this->poll(
            [$this->instance('qbittorrent-1', 'Home'), $this->instance('qbittorrent-2', 'Seedbox')],
            [
                'qbittorrent-1' => new \RuntimeException('boom'),
                'qbittorrent-2' => [['hash' => 'cc', 'state' => 'downloading', 'name' => 'C', 'size' => 3]],
            ],
        );

        self::assertSame(200, $out['status']);
        self::assertSame(['qbittorrent-2' => 1], $out['body']['active_by_instance']);
    }

    public function testEveryInstanceThrowingReturnsTheErrorShape(): void
    {
        $out = $this->poll(
            [$this->instance('qbittorrent-1', 'Home')],
            ['qbittorrent-1' => new ServiceNotConfiguredException('qBittorrent', 'service_instance:qbittorrent')],
        );

        self::assertSame(500, $out['status']);
        self::assertArrayHasKey('error', $out['body']);
    }

    public function testNoInstanceReturnsTheErrorShape(): void
    {
        self::assertSame(500, $this->poll([], [])['status']);
    }

    public function testKillSwitchShortCircuits(): void
    {
        $out = $this->poll([$this->instance('qbittorrent-1', 'Home')], [], enabledFlag: '0');

        self::assertSame(503, $out['status']);
        self::assertSame(['error' => 'disabled'], $out['body']);
    }
}
