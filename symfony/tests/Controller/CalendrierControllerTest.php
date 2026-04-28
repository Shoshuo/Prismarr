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
    ): CalendrierController {
        if ($config === null) {
            $config = $this->createMock(ConfigService::class);
            // Default test posture: both services are fully configured, so
            // the banner is driven purely by the throw / silent-error path.
            $config->method('get')->willReturn('configured');
        }
        $controller = new CalendrierController(
            $radarr,
            $sonarr,
            $this->createMock(LoggerInterface::class),
            $this->createMock(TranslatorInterface::class),
            $config,
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
}
