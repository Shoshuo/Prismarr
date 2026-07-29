<?php

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

#[AllowMockObjectsWithoutExpectations]
class HealthControllerTest extends TestCase
{
    private function newController(bool $isAdmin = false): HealthController
    {
        $controller = new HealthController();
        $checker = $this->createMock(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($isAdmin);
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            fn(string $id) => $id === 'security.authorization_checker' ? $checker : null
        );
        $controller->setContainer($container);
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

        $health->method('chips')->willReturn([
            ['id' => 'qbittorrent', 'name' => 'qBittorrent', 'status' => 'up',   'latencyMs' => 40, 'color' => '#2f67ba'],
            ['id' => 'sabnzbd',     'name' => 'SABnzbd',     'status' => 'up',   'latencyMs' => 30, 'color' => '#fbc531'],
            ['id' => 'tautulli',    'name' => 'Tautulli',    'status' => 'down', 'latencyMs' => null, 'color' => '#e5a00d'],
        ]);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturn([]);

        $response = $this->newController()->servicesHealth($health, $instances);
        $payload  = json_decode((string) $response->getContent(), true);

        // Both clients present in the services map.
        $this->assertArrayHasKey('sabnzbd', $payload['services']);
        $this->assertArrayHasKey('nzbget', $payload['services']);
        $this->assertTrue($payload['services']['sabnzbd']);
        $this->assertNull($payload['services']['nzbget']);

        // ok/total now mirror the chip list (2 of 3 up); legacy keys still present.
        $this->assertSame(2, $payload['ok']);
        $this->assertSame(3, $payload['total']);
        $this->assertCount(3, $payload['chips']);
        $this->assertSame('qBittorrent', $payload['chips'][0]['name']);
    }

    public function testUnraidChipIsAdminGated(): void
    {
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturn([]);

        $health = $this->createMock(HealthService::class);
        $health->expects($this->once())->method('chips')->with(true)->willReturn([]);
        $this->newController(isAdmin: true)->servicesHealth($health, $instances);

        $health2 = $this->createMock(HealthService::class);
        $health2->expects($this->once())->method('chips')->with(false)->willReturn([]);
        $this->newController(isAdmin: false)->servicesHealth($health2, $instances);
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
}
