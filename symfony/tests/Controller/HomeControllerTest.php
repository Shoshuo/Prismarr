<?php

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Entity\ServiceInstance;
use App\EventSubscriber\LastVisitedRouteSubscriber;
use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Route as RouteDefinition;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Covers the landing page redirect logic. Since Session 9b the admin can
 * choose a `display_home_page` preference (dashboard by default) and we
 * only fall back to the legacy service-availability chain when the chosen
 * target isn't reachable.
 */
#[AllowMockObjectsWithoutExpectations]
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

    /**
     * Mirror the legacy "radarr_api_key" / "sonarr_api_key" markers from
     * $hasKeys into the v1.1.0 instance provider — keeps the tests below
     * readable.
     *
     * @param list<string> $hasKeys
     */
    private function instances(array $hasKeys): ServiceInstanceProvider
    {
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('hasAnyEnabled')->willReturnCallback(
            fn(string $type) => match ($type) {
                ServiceInstance::TYPE_RADARR => in_array('radarr_api_key', $hasKeys, true),
                ServiceInstance::TYPE_SONARR => in_array('sonarr_api_key', $hasKeys, true),
                default => false,
            }
        );
        // v1.1.0 Phase C — HomeController now reads getDefault() to plug
        // the slug into redirectToRoute('app_media_films', [...]). Stub
        // matching instances for whichever type is "configured".
        $provider->method('getDefault')->willReturnCallback(
            function (string $type) use ($hasKeys) {
                $configured = match ($type) {
                    ServiceInstance::TYPE_RADARR => in_array('radarr_api_key', $hasKeys, true),
                    ServiceInstance::TYPE_SONARR => in_array('sonarr_api_key', $hasKeys, true),
                    default => false,
                };
                if (!$configured) return null;
                return new ServiceInstance($type, $type . '-1', ucfirst($type) . ' 1', 'http://test:1234');
            }
        );
        return $provider;
    }

    private function prefs(string $homePage): DisplayPreferencesService
    {
        $prefs = $this->createMock(DisplayPreferencesService::class);
        $prefs->method('getHomePage')->willReturn($homePage);
        return $prefs;
    }

    /**
     * @param list<string> $knownRoutes
     */
    private function router(array $knownRoutes = []): RouterInterface
    {
        $collection = new RouteCollection();
        foreach ($knownRoutes as $name) {
            $collection->add($name, new RouteDefinition('/' . $name));
        }

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $router;
    }

    private function request(?string $lastRouteCookie = null): Request
    {
        $request = Request::create('/');
        if ($lastRouteCookie !== null) {
            $request->cookies->set(LastVisitedRouteSubscriber::COOKIE_NAME, $lastRouteCookie);
        }
        return $request;
    }

    public function testDashboardIsTheDefaultLanding(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config([]),
            $this->instances([]),
            $this->prefs('dashboard'),
            $this->router(),
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_dashboard', $response->getTargetUrl());
    }

    public function testDiscoveryPreferenceRedirectsToTmdbWhenConfigured(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config(['tmdb_api_key']),
            $this->instances(['tmdb_api_key']),
            $this->prefs('discovery'),
            $this->router(),
        );

        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }

    public function testFilmsPreferenceRedirectsToRadarrWhenConfigured(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config(['radarr_api_key']),
            $this->instances(['radarr_api_key']),
            $this->prefs('films'),
            $this->router(),
        );

        $this->assertStringContainsString('app_media_films', $response->getTargetUrl());
    }

    public function testSeriesPreferenceRedirectsToSonarrWhenConfigured(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config(['sonarr_api_key']),
            $this->instances(['sonarr_api_key']),
            $this->prefs('series'),
            $this->router(),
        );

        $this->assertStringContainsString('app_media_series', $response->getTargetUrl());
    }

    public function testQbittorrentPreferenceRedirectsWhenConfigured(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config(['qbittorrent_url']),
            $this->instances(['qbittorrent_url']),
            $this->prefs('qbittorrent'),
            $this->router(),
        );

        $this->assertStringContainsString('app_qbittorrent_index', $response->getTargetUrl());
    }

    public function testFallsBackToChainWhenPreferredTargetIsNotConfigured(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config(['radarr_api_key']),
            $this->instances(['radarr_api_key']),
            $this->prefs('discovery'),
            $this->router(),
        );

        $this->assertStringContainsString('app_media_films', $response->getTargetUrl());
    }

    public function testLastVisitedPreferenceUsesCookieWhenRouteExists(): void
    {
        $response = $this->newController()->index(
            $this->request('app_qbittorrent_index'),
            $this->config([]),
            $this->instances([]),
            $this->prefs('last'),
            $this->router(['app_qbittorrent_index']),
        );

        $this->assertStringContainsString('app_qbittorrent_index', $response->getTargetUrl());
    }

    public function testLastVisitedFallsBackWhenCookieRouteNoLongerExists(): void
    {
        // Simulates an upgrade that removed/renamed the stored route.
        $response = $this->newController()->index(
            $this->request('stale_renamed_route'),
            $this->config(['tmdb_api_key']),
            $this->instances(['tmdb_api_key']),
            $this->prefs('last'),
            $this->router([]),
        );

        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }

    public function testLastVisitedFallsBackWhenCookieMissing(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config(['tmdb_api_key']),
            $this->instances(['tmdb_api_key']),
            $this->prefs('last'),
            $this->router(),
        );

        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }

    public function testRendersWelcomeWhenNothingConfiguredAndPreferenceUnreachable(): void
    {
        $response = $this->newController()->index(
            $this->request(),
            $this->config([]),
            $this->instances([]),
            $this->prefs('discovery'),
            $this->router(),
        );

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }
}
