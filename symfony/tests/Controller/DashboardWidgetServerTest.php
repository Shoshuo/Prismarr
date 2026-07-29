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
use App\Service\Media\UnraidClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * widgetServer() gating: non-admins and unconfigured Unraid both get an empty
 * body (hidden client-side) — no Unraid call is ever made in either case.
 */
#[AllowMockObjectsWithoutExpectations]
class DashboardWidgetServerTest extends TestCase
{
    private function makeController(bool $isAdmin, bool $configured, ?UnraidClient $unraid): DashboardController
    {
        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturnCallback(
            fn(string $s) => $s === 'unraid' ? $configured : false
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
            $unraid,
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

    public function testNonAdminGetsEmptyBodyAndNoUnraidCall(): void
    {
        $unraid = $this->createMock(UnraidClient::class);
        $unraid->expects($this->never())->method('overview');

        $resp = $this->makeController(isAdmin: false, configured: true, unraid: $unraid)->widgetServer();
        $this->assertSame('', $resp->getContent());
    }

    public function testUnconfiguredGetsEmptyBodyAndNoUnraidCall(): void
    {
        $unraid = $this->createMock(UnraidClient::class);
        $unraid->expects($this->never())->method('overview');

        $resp = $this->makeController(isAdmin: true, configured: false, unraid: $unraid)->widgetServer();
        $this->assertSame('', $resp->getContent());
    }
}
