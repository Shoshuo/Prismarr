<?php

namespace App\Tests\Controller;

use App\Controller\JellyseerrController;
use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review 2026-11-06 — Seerr controller edges: MS-2 (user creation without a
 * typed password / with a username), MS-8 (request edit modal must load the
 * profiles of the request's own server, not the default one), MS-13 (the
 * logs page number must be capped — an int overflow turned the offset into
 * a float and a TypeError 500).
 */
#[AllowMockObjectsWithoutExpectations]
class JellyseerrControllerHardeningTest extends TestCase
{
    private function controller(JellyseerrClient $jellyseerr): JellyseerrController
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller = new JellyseerrController($jellyseerr, $this->createMock(ConfigService::class), new NullLogger(), $translator);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(null);
        $controller->setContainer($container);
        return $controller;
    }

    private static function json(array $body): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    public function testCreateUserWithoutAPasswordLetsSeerrGenerateOneAndForwardsTheUsername(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->expects($this->once())
            ->method('createLocalUser')
            ->with('alice@example.test', null, 'alice')
            ->willReturn(['id' => 9]);

        $r = $this->controller($client)->createUser(self::json(['email' => 'alice@example.test', 'username' => 'alice']));

        $this->assertTrue(json_decode((string) $r->getContent(), true)['ok']);
    }

    public function testCreateUserStillRequiresAnEmail(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->expects($this->never())->method('createLocalUser');

        $r = $this->controller($client)->createUser(self::json(['password' => 'x']));

        $this->assertFalse(json_decode((string) $r->getContent(), true)['ok']);
    }

    public function testServiceInfoHonoursAnExplicitServerId(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->method('getRadarrSettings')->willReturn([
            ['id' => 1, 'name' => 'Radarr', 'isDefault' => true],
            ['id' => 2, 'name' => 'Radarr 4K', 'isDefault' => false],
        ]);
        $client->expects($this->once())->method('getServiceRadarr')->with(2)->willReturn(['profiles' => [['id' => 7]], 'rootFolders' => []]);

        $r = $this->controller($client)->serviceInfo('radarr', Request::create('/x', 'GET', ['serverId' => '2']));

        $this->assertSame(7, json_decode((string) $r->getContent(), true)['profiles'][0]['id']);
    }

    public function testServiceInfoFallsBackToTheDefaultServerForAnUnknownId(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->method('getRadarrSettings')->willReturn([
            ['id' => 1, 'name' => 'Radarr', 'isDefault' => true],
        ]);
        $client->expects($this->once())->method('getServiceRadarr')->with(1)->willReturn(['profiles' => [], 'rootFolders' => []]);

        $this->controller($client)->serviceInfo('radarr', Request::create('/x', 'GET', ['serverId' => '42']));
    }

    public function testSettingsLogsCapsThePageNumber(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->expects($this->once())
            ->method('getLogs')
            ->with(50, $this->callback(fn($skip) => is_int($skip) && $skip <= 50 * 10000), null)
            ->willReturn(['results' => [], 'pageInfo' => []]);

        try {
            $this->controller($client)->settingsLogs(Request::create('/x', 'GET', ['page' => '9223372036854775807']));
        } catch (\TypeError $e) {
            $this->fail('page overflow must not reach getLogs() as a float: ' . $e->getMessage());
        } catch (\Throwable) {
            // render() has no Twig in this harness — the getLogs() expectation above is the assertion
        }
    }
}
