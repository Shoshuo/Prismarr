<?php

namespace App\Tests\Controller;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

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
