<?php

namespace App\Tests\Controller;

use App\Controller\DelugeController;
use App\Controller\QBittorrentController;
use App\Controller\TransmissionController;
use App\Service\ConfigService;
use App\Service\Media\DelugeClient;
use App\Service\Media\GluetunClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\TorrentResolverService;
use App\Service\Media\TransmissionClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review 2026-11-06 (C1): the download-client JSON endpoints treated the
 * client's [] on a transport failure as "no torrents" — the page blanked
 * the list, the global poller's back-off never fired and the completion
 * toasts lost their baseline. An outage is now a 502 envelope.
 */
#[AllowMockObjectsWithoutExpectations]
class TorrentJsonOutageTest extends TestCase
{
    private const DOWN = ['code' => 0, 'method' => 'GET', 'path' => '/api/v2/torrents/info', 'message' => 'Failed to connect'];

    private function wire(object $controller): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
    }

    private function translator(): TranslatorInterface
    {
        $t = $this->createMock(TranslatorInterface::class);
        $t->method('trans')->willReturnArgument(0);
        return $t;
    }

    public function testQbittorrentListAndSummaryAnswer502OnAnOutage(): void
    {
        $qbt = $this->createMock(QBittorrentClient::class);
        $qbt->method('getTorrents')->willReturn([]);
        $qbt->method('getLastError')->willReturn(self::DOWN);
        $c = new QBittorrentController($qbt, $this->createMock(GluetunClient::class), $this->createMock(TorrentResolverService::class), $this->createMock(ConfigService::class), new NullLogger(), $this->translator());
        $this->wire($c);

        $this->assertSame(502, $c->apiTorrents(Request::create('/x'))->getStatusCode());
        $this->assertSame(502, $c->apiPollSummary()->getStatusCode());
    }

    public function testQbittorrentEmptyListWithoutAnErrorIsAnEmptyList(): void
    {
        $qbt = $this->createMock(QBittorrentClient::class);
        $qbt->method('getTorrents')->willReturn([]);
        $qbt->method('getStats')->willReturn([]);
        $qbt->method('getLastError')->willReturn(null);
        $c = new QBittorrentController($qbt, $this->createMock(GluetunClient::class), $this->createMock(TorrentResolverService::class), $this->createMock(ConfigService::class), new NullLogger(), $this->translator());
        $this->wire($c);

        $this->assertSame(200, $c->apiPollSummary()->getStatusCode());
        $this->assertSame(0, json_decode((string) $c->apiPollSummary()->getContent(), true)['active']);
    }

    public function testDelugeListAndSummaryAnswer502OnAnOutage(): void
    {
        $deluge = $this->createMock(DelugeClient::class);
        $deluge->method('getTorrents')->willReturn([]);
        $deluge->method('getLastError')->willReturn(self::DOWN);
        $c = new DelugeController($deluge, $this->createMock(TorrentResolverService::class), $this->createMock(ConfigService::class), new NullLogger(), $this->translator());
        $this->wire($c);

        $this->assertSame(502, $c->apiTorrents(Request::create('/x'))->getStatusCode());
        $this->assertSame(502, $c->apiPollSummary()->getStatusCode());
    }

    public function testTransmissionListAndSummaryAnswer502OnAnOutage(): void
    {
        $tr = $this->createMock(TransmissionClient::class);
        $tr->method('getTorrents')->willReturn([]);
        $tr->method('getLastError')->willReturn(self::DOWN);
        $tr->method('isUnavailable')->willReturn(true);
        $c = new TransmissionController($tr, $this->createMock(TorrentResolverService::class), $this->createMock(ConfigService::class), new NullLogger(), $this->translator());
        $this->wire($c);

        $this->assertSame(502, $c->apiTorrents(Request::create('/x'))->getStatusCode());
        $this->assertSame(502, $c->apiPollSummary()->getStatusCode());
    }
}
