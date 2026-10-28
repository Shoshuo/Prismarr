<?php

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Entity\ServiceInstance;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

#[AllowMockObjectsWithoutExpectations]
class HealthControllerTest extends TestCase
{
    private function newController(): HealthController
    {
        $controller = new HealthController();
        // AbstractController constructor initializes nothing, but some helpers
        // require a container. Minimal stub.
        $controller->setContainer($this->createMock(ContainerInterface::class));
        return $controller;
    }

    public function testReturns200OkWhenDbResponds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('executeQuery')->with('SELECT 1');

        $response = $this->newController()->health($db);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('ok', $payload['db']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['timestamp']
        );
    }

    public function testReturns503WhenDbThrows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('executeQuery')->willThrowException(new \RuntimeException('DB down'));

        $response = $this->newController()->health($db);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('"status":"error"', $response->getContent());
        $this->assertStringContainsString('"db":"unreachable"', $response->getContent());
    }

    public function testServicesHealthExposesUsenetClients(): void
    {
        // #20 — SABnzbd / NZBGet must appear in the topbar health feed like the
        // other flat services. isHealthy returns null when not configured or
        // disabled (counts 0) and a bool otherwise.
        $health = $this->createMock(HealthService::class);
        $health->method('isHealthy')->willReturnCallback(fn(string $service) => match ($service) {
            'sabnzbd'     => true,   // configured + up
            'nzbget'      => null,   // not configured / disabled
            'qbittorrent' => true,
            default       => null,   // prowlarr, jellyseerr, tmdb not configured
        });

        // Issue #131 — qBittorrent is probed per instance.
        $health->method('isConfigured')->willReturn(true);
        $qbt = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://10.0.0.2:8080');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_QBITTORRENT ? [$qbt] : []
        );

        $response = $this->newController()->servicesHealth($health, $instances);
        $payload  = json_decode((string) $response->getContent(), true);

        $this->assertTrue($payload['services']['qbittorrent']);
        $this->assertSame(
            [['slug' => 'qbittorrent-1', 'name' => 'qBittorrent 1', 'state' => true]],
            $payload['instances']['qbittorrent'],
        );

        // Both clients present in the services map.
        $this->assertArrayHasKey('sabnzbd', $payload['services']);
        $this->assertArrayHasKey('nzbget', $payload['services']);
        $this->assertTrue($payload['services']['sabnzbd']);
        $this->assertNull($payload['services']['nzbget']);

        // sabnzbd + qbittorrent are up → counted; nzbget (null) is not.
        $this->assertSame(2, $payload['ok']);
        $this->assertSame(2, $payload['total']);
    }

    public function testErrorResponseDoesNotLeakDetails(): void
    {
        // Security: the exception message must not appear in the JSON body.
        $db = $this->createMock(Connection::class);
        $db->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000]: password for user at /var/www/.../db')
        );

        $response = $this->newController()->health($db);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function testQbittorrentKillSwitchHidesEveryInstance(): void
    {
        // Issue #15 kill switch stays service-level: no instance is probed.
        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturnCallback(fn(string $s) => $s !== 'qbittorrent');
        $probed = [];
        $health->method('isHealthy')->willReturnCallback(function (string $s) use (&$probed) {
            $probed[] = $s;
            return null;
        });
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_QBITTORRENT
                ? [new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://10.0.0.2:8080')]
                : []
        );

        $payload = json_decode((string) $this->newController()->servicesHealth($health, $instances)->getContent(), true);

        $this->assertNotContains('qbittorrent', $probed);
        $this->assertNull($payload['services']['qbittorrent']);
        $this->assertSame([], $payload['instances']['qbittorrent']);
    }
}
