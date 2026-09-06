<?php

namespace App\Tests\Controller;

use App\Controller\ProwlarrController;
use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prowlarr search results — Grab action (upstream #71): sends a release's
 * `guid` + `indexerId` to Prowlarr, which routes the grab to the indexer's
 * configured download client (no client picker on our side). Missing or
 * invalid input is rejected with 400 before the upstream client is ever
 * touched.
 */
#[AllowMockObjectsWithoutExpectations]
class ProwlarrGrabTest extends TestCase
{
    private function controller(?ProwlarrClient $prowlarr = null): ProwlarrController
    {
        $controller = new ProwlarrController(
            $prowlarr ?? $this->createMock(ProwlarrClient::class),
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $this->createMock(TranslatorInterface::class),
        );
        // Empty container so AbstractController::json() falls back to a plain
        // JsonResponse instead of looking up the serializer service.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        return $controller;
    }

    private function grabRequest(array $body): Request
    {
        return Request::create('/prowlarr/grab', 'POST', [], [], [], [], json_encode($body));
    }

    public function testValidGrabCallsClientAndReturnsItsResultVerbatim(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects($this->once())
            ->method('grab')
            ->with('g', 3)
            ->willReturn(['ok' => true, 'data' => ['id' => 42]]);

        $res = $this->controller($prowlarr)->grab($this->grabRequest(['guid' => 'g', 'indexerId' => 3]));

        $this->assertInstanceOf(JsonResponse::class, $res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(['ok' => true, 'data' => ['id' => 42]], json_decode($res->getContent(), true));
    }

    public function testMissingGuidReturns400WithoutCallingClient(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects($this->never())->method('grab');

        $res = $this->controller($prowlarr)->grab($this->grabRequest(['indexerId' => 3]));

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['ok' => false, 'error' => 'invalid_request'], json_decode($res->getContent(), true));
    }

    public function testZeroIndexerIdReturns400WithoutCallingClient(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects($this->never())->method('grab');

        $res = $this->controller($prowlarr)->grab($this->grabRequest(['guid' => 'g', 'indexerId' => 0]));

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['ok' => false, 'error' => 'invalid_request'], json_decode($res->getContent(), true));
    }
}
