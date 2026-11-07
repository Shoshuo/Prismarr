<?php

namespace App\Tests\Controller;

use App\Controller\ProwlarrController;
use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review 2026-11-06 (MS-7): Prowlarr runs a full connection test on every
 * PUT /indexer/{id} unless forceSave=true, so a failing or slow indexer could
 * never be re-enabled and the error was swallowed (the switch just flipped
 * back). The toggle now forces the save and surfaces Prowlarr's message.
 */
#[AllowMockObjectsWithoutExpectations]
class ProwlarrIndexerToggleTest extends TestCase
{
    private function controller(ProwlarrClient $prowlarr): ProwlarrController
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller = new ProwlarrController($prowlarr, $this->createMock(ConfigService::class), new NullLogger(), $translator);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        return $controller;
    }

    public function testToggleForcesTheSaveAndReportsTheNewState(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('getRawIndexer')->willReturn(['id' => 5, 'name' => 'Idx', 'enable' => false]);
        $prowlarr->expects($this->once())
            ->method('updateIndexerWithError')
            ->with(5, ['id' => 5, 'name' => 'Idx', 'enable' => true], true)
            ->willReturn(['ok' => true, 'data' => []]);

        $r = $this->controller($prowlarr)->indexerToggle(5);

        $payload = json_decode((string) $r->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['enabled']);
    }

    public function testToggleSurfacesProwlarrsErrorAndKeepsTheOldState(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('getRawIndexer')->willReturn(['id' => 5, 'name' => 'Idx', 'enable' => false]);
        $prowlarr->method('updateIndexerWithError')->willReturn(['ok' => false, 'error' => 'Unable to connect to indexer']);

        $r = $this->controller($prowlarr)->indexerToggle(5);

        $payload = json_decode((string) $r->getContent(), true);
        $this->assertFalse($payload['ok']);
        $this->assertSame('Unable to connect to indexer', $payload['error']);
        $this->assertFalse($payload['enabled'], 'the switch must reflect the state Prowlarr still has');
    }
}
