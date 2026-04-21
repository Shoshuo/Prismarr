<?php

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Covers the landing page redirect logic. Since Session 9b the admin can
 * choose a `display_home_page` preference (dashboard by default) and we
 * only fall back to the legacy service-availability chain when the chosen
 * target isn't reachable.
 */
class HomeControllerTest extends TestCase
{
    private function newController(): HomeController
    {
        $controller = new HomeController();

        $container = $this->createMock(ContainerInterface::class);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn(string $n) => '/_route/' . $n);

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>welcome</html>');

        $container->method('has')->willReturnCallback(
            fn(string $id) => in_array($id, ['router', 'twig'], true)
        );
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'router' => $router,
            'twig'   => $twig,
            default  => null,
        });

        $controller->setContainer($container);
        return $controller;
    }

    private function config(array $hasKeys): ConfigService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(fn(string $k) => in_array($k, $hasKeys, true));
        return $config;
    }

    private function prefs(string $homePage): DisplayPreferencesService
    {
        $prefs = $this->createMock(DisplayPreferencesService::class);
        $prefs->method('getHomePage')->willReturn($homePage);
        return $prefs;
    }

    public function testDashboardIsTheDefaultLanding(): void
    {
        // Default preference = 'dashboard' → always redirects to dashboard,
        // regardless of which services are configured.
        $response = $this->newController()->index($this->config([]), $this->prefs('dashboard'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_dashboard', $response->getTargetUrl());
    }

    public function testDiscoveryPreferenceRedirectsToTmdbWhenConfigured(): void
    {
        $response = $this->newController()->index($this->config(['tmdb_api_key']), $this->prefs('discovery'));

        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }

    public function testFilmsPreferenceRedirectsToRadarrWhenConfigured(): void
    {
        $response = $this->newController()->index($this->config(['radarr_api_key']), $this->prefs('films'));

        $this->assertStringContainsString('app_media_films', $response->getTargetUrl());
    }

    public function testSeriesPreferenceRedirectsToSonarrWhenConfigured(): void
    {
        $response = $this->newController()->index($this->config(['sonarr_api_key']), $this->prefs('series'));

        $this->assertStringContainsString('app_media_series', $response->getTargetUrl());
    }

    public function testQbittorrentPreferenceRedirectsWhenConfigured(): void
    {
        $response = $this->newController()->index($this->config(['qbittorrent_url']), $this->prefs('qbittorrent'));

        $this->assertStringContainsString('app_qbittorrent_index', $response->getTargetUrl());
    }

    public function testFallsBackToChainWhenPreferredTargetIsNotConfigured(): void
    {
        // User prefers discovery but hasn't configured TMDb → fallback to
        // the first configured service (Radarr here).
        $response = $this->newController()->index($this->config(['radarr_api_key']), $this->prefs('discovery'));

        $this->assertStringContainsString('app_media_films', $response->getTargetUrl());
    }

    public function testLastVisitedPreferenceFallsBackToChain(): void
    {
        // 'last' is declared in DISPLAY_OPTIONS but not yet implemented —
        // it must never crash: fall back to whatever is configured.
        $response = $this->newController()->index($this->config(['tmdb_api_key']), $this->prefs('last'));

        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }

    public function testRendersWelcomeWhenNothingConfiguredAndPreferenceUnreachable(): void
    {
        // No config + preference that requires config → welcome template.
        // Covers a fresh install where the wizard hasn't been completed.
        $response = $this->newController()->index($this->config([]), $this->prefs('discovery'));

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }
}
