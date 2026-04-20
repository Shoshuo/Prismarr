<?php

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Covers the smart redirect logic added in Session 8c that replaced the
 * hardcoded `redirect tmdb_index` with a chain of `has(key)` checks.
 * Regression test for the /setup/tmdb loop after a wizard skip.
 */
class HomeControllerTest extends TestCase
{
    private function newController(array $hasKeys): HomeController
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

    public function testRedirectsToTmdbWhenConfigured(): void
    {
        $controller = $this->newController([]);
        $response = $controller->index($this->config(['tmdb_api_key']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }

    public function testFallsBackToRadarrIfNoTmdb(): void
    {
        $controller = $this->newController([]);
        $response = $controller->index($this->config(['radarr_api_key']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_media_films', $response->getTargetUrl());
    }

    public function testFallsBackToSonarrIfOnlySonarr(): void
    {
        $controller = $this->newController([]);
        $response = $controller->index($this->config(['sonarr_api_key']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_media_series', $response->getTargetUrl());
    }

    public function testFallsBackToQbittorrentIfOnlyQbit(): void
    {
        $controller = $this->newController([]);
        $response = $controller->index($this->config(['qbittorrent_url']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_qbittorrent_index', $response->getTargetUrl());
    }

    public function testRendersWelcomeWhenNothingConfigured(): void
    {
        $controller = $this->newController([]);
        $response = $controller->index($this->config([]));

        // Not a redirect → must be a 200 render (welcome.html.twig).
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTmdbTakesPriorityOverRadarr(): void
    {
        // Both configured → TMDb wins (the discovery is the intended landing).
        $controller = $this->newController([]);
        $response = $controller->index($this->config(['tmdb_api_key', 'radarr_api_key']));

        $this->assertStringContainsString('tmdb_index', $response->getTargetUrl());
    }
}
