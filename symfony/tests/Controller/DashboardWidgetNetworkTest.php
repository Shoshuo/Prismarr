<?php

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\DashboardLayoutService;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\UnifiClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * widgetNetwork() gating: non-admins and unconfigured UniFi both get an empty
 * body (hidden client-side) — no UniFi call is ever made in either case.
 */
#[AllowMockObjectsWithoutExpectations]
class DashboardWidgetNetworkTest extends TestCase
{
    private function makeController(bool $isAdmin, bool $configured, ?UnifiClient $unifi): DashboardController
    {
        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturnCallback(
            fn(string $s) => $s === 'unifi' ? $configured : false
        );

        $controller = new DashboardController(
            $health,
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(TmdbClient::class),
            $this->createMock(WatchlistItemRepository::class),
            $this->createMock(ServiceInstanceProvider::class),
            new NullLogger(),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(TautulliClient::class),
            new DashboardLayoutService($this->createMock(\App\Service\ConfigService::class)),
            unifi: $unifi,
        );

        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($isAdmin);
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            fn(string $id) => $id === 'security.authorization_checker' ? $checker : null
        );
        $controller->setContainer($container);

        return $controller;
    }

    public function testNonAdminGetsEmptyBodyAndNoUnifiCall(): void
    {
        $unifi = $this->createMock(UnifiClient::class);
        $unifi->expects($this->never())->method('overview');

        $resp = $this->makeController(isAdmin: false, configured: true, unifi: $unifi)->widgetNetwork();
        $this->assertSame('', $resp->getContent());
    }

    public function testUnconfiguredGetsEmptyBodyAndNoUnifiCall(): void
    {
        $unifi = $this->createMock(UnifiClient::class);
        $unifi->expects($this->never())->method('overview');

        $resp = $this->makeController(isAdmin: true, configured: false, unifi: $unifi)->widgetNetwork();
        $this->assertSame('', $resp->getContent());
    }

    public function testNetworkSectionRegistered(): void
    {
        $this->assertContains('network', \App\Dashboard\DashboardSections::DEFAULT_ORDER);
        $this->assertArrayHasKey('network', \App\Dashboard\DashboardSections::META);
        $this->assertTrue(\App\Dashboard\DashboardSections::isValid('network'));
    }
}
