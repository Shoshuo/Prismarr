<?php

namespace App\Tests\Controller;

use App\Controller\CalendrierController;
use App\Service\ConfigService;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Focuses on the v1.0.6 "service unreachable" banner — making sure the
 * controller exposes radarrFailed / sonarrFailed flags whenever the
 * upstream calendar fetch throws, so the template can surface the
 * difference between "nothing upcoming" and "service down".
 */
#[AllowMockObjectsWithoutExpectations]
class CalendrierControllerTest extends TestCase
{
    private function newController(
        RadarrClient $radarr,
        SonarrClient $sonarr,
        ?array &$capturedContext = null,
        ?ConfigService $config = null,
        ?\App\Service\ServiceInstanceProvider $instances = null,
    ): CalendrierController {
        if ($config === null) {
            $config = $this->createMock(ConfigService::class);
            // Default test posture: both services are fully configured, so
            // the banner is driven purely by the throw / silent-error path.
            $config->method('get')->willReturn('configured');
        }
        if ($instances === null) {
            // Default: both radarr/sonarr instances exist (post-v1.1.0 source
            // of truth for "configured"). Tests that need the unconfigured
            // posture pass an empty mock explicitly.
            $radarrInst = new \App\Entity\ServiceInstance(\App\Entity\ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://r:7878', 'k');
            $sonarrInst = new \App\Entity\ServiceInstance(\App\Entity\ServiceInstance::TYPE_SONARR, 'sonarr-1', 'Sonarr', 'http://s:8989', 'k');
            $instances = $this->createMock(\App\Service\ServiceInstanceProvider::class);
            $instances->method('hasAnyEnabled')->willReturn(true);
            // Phase D — index() iterates over getEnabled() and calls
            // withInstance() per instance, so every test needs at least one
            // instance per type to feed the loop.
            $instances->method('getEnabled')->willReturnCallback(
                fn(string $type) => $type === \App\Entity\ServiceInstance::TYPE_RADARR ? [$radarrInst] : [$sonarrInst]
            );
        }
        // Self-bind so $client->withInstance(\$i) returns the same mock — the
        // test cases configure getCalendar() on the mock and don't care
        // about per-instance routing.
        $radarr->method('withInstance')->willReturn($radarr);
        $sonarr->method('withInstance')->willReturn($sonarr);
        $controller = new CalendrierController(
            $radarr,
            $sonarr,
            $this->createMock(LoggerInterface::class),
            $this->createMock(TranslatorInterface::class),
            $config,
            $instances,
        );

        // Capture the Twig render context so we can assert on the flags
        // without rendering an actual template.
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            function (string $name, array $context = []) use (&$capturedContext) {
                $capturedContext = $context;
                return '<html>rendered</html>';
            }
        );

        $router = $this->createMock(UrlGeneratorInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn(string $id) => in_array($id, ['twig', 'router'], true));
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'twig'   => $twig,
            'router' => $router,
            default  => null,
        });

        $controller->setContainer($container);
        return $controller;
    }

    public function testIndexFlagsRadarrFailedWhenClientThrows(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willThrowException(new \RuntimeException('connection refused'));

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willReturn([]);

        $captured = null;
        $controller = $this->newController($radarr, $sonarr, $captured);
        $controller->index();

        $this->assertTrue($captured['radarrFailed']);
        $this->assertFalse($captured['sonarrFailed']);
    }

    public function testIndexFlagsSonarrFailedWhenClientThrows(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willReturn([]);

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willThrowException(new \RuntimeException('timeout'));

        $captured = null;
        $controller = $this->newController($radarr, $sonarr, $captured);
        $controller->index();

        $this->assertFalse($captured['radarrFailed']);
        $this->assertTrue($captured['sonarrFailed']);
    }

    public function testIndexFlagsBothFailedWhenBothClientsThrow(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willThrowException(new \RuntimeException('boom'));

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willThrowException(new \RuntimeException('boom'));

        $captured = null;
        $controller = $this->newController($radarr, $sonarr, $captured);
        $controller->index();

        $this->assertTrue($captured['radarrFailed']);
        $this->assertTrue($captured['sonarrFailed']);
    }

    public function testIndexFlagsRadarrFailedWhenSilentlyEmpty(): void
    {
        // The realistic prod case: the HTTP call inside RadarrClient bailed
        // out (timeout, 401, etc.) and getCalendar() returned [] without
        // throwing. The non-null getLastError() is our only signal that the
        // empty array means "service down" rather than "library quiet".
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willReturn([]);
        $radarr->method('getLastError')->willReturn(['code' => 503, 'method' => 'GET', 'path' => '/api/v3/calendar', 'message' => 'Service Unavailable']);

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willReturn([]);
        $sonarr->method('getLastError')->willReturn(null);

        $captured = null;
        $controller = $this->newController($radarr, $sonarr, $captured);
        $controller->index();

        $this->assertTrue($captured['radarrFailed'], 'Silent Radarr failure should set the banner flag');
        $this->assertFalse($captured['sonarrFailed']);
    }

    public function testIndexFlagsSonarrFailedWhenSilentlyEmpty(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willReturn([]);
        $radarr->method('getLastError')->willReturn(null);

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willReturn([]);
        $sonarr->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/api/v3/calendar', 'message' => 'curl error: timeout']);

        $captured = null;
        $controller = $this->newController($radarr, $sonarr, $captured);
        $controller->index();

        $this->assertFalse($captured['radarrFailed']);
        $this->assertTrue($captured['sonarrFailed']);
    }

    public function testIndexFlagsBothFalseWhenBothReturnEmpty(): void
    {
        // Empty response is the "legitimate empty calendar" case — the
        // banner should NOT show, so the user isn't told something failed
        // when nothing is actually wrong.
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willReturn([]);

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willReturn([]);

        $captured = null;
        $controller = $this->newController($radarr, $sonarr, $captured);
        $controller->index();

        $this->assertFalse($captured['radarrFailed']);
        $this->assertFalse($captured['sonarrFailed']);
        $this->assertSame(0, $captured['totalFilms']);
        $this->assertSame(0, $captured['totalEpisodes']);
    }

    // ── DD-10 (review 2026-11-06): the iCal feed ────────────────────────────

    public function testIcalEmitsEpisodesAsAllDayEventsInTheDisplayZoneWithFoldedLines(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getCalendar')->willReturn([]);
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getCalendar')->willReturn([[
            'airDate'     => new \DateTimeImmutable('2026-11-06', new \DateTimeZone('UTC')),
            'seriesTitle' => str_repeat('A very long series title ', 8),
            'season'      => 1,
            'episode'     => 2,
            'tvdbId'      => 7,
            'title'       => 'Episode',
            'overview'    => str_repeat('overview text ', 30),
            'runtime'     => 45,
        ]]);
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $k === 'display_timezone' ? 'America/New_York' : 'configured');

        $body = (string) $this->newController($radarr, $sonarr, config: $config)->ical()->getContent();

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261106', $body, 'an air DATE is an all-day event, not a 00:00Z instant (the previous evening in the Americas)');
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261107', $body);
        $this->assertStringNotContainsString('T000000Z', $body);
        $this->assertStringContainsString('X-WR-TIMEZONE:America/New_York', $body);
        $this->assertStringNotContainsString('X-WR-TIMEZONE:Europe/Paris', $body);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'RFC 5545 §3.1: content lines are folded at 75 octets');
        }
    }
}
