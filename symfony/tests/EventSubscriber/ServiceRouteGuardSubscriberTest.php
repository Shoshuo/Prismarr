<?php

namespace App\Tests\EventSubscriber;

use App\Entity\ServiceInstance;
use App\EventSubscriber\ServiceRouteGuardSubscriber;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
class ServiceRouteGuardSubscriberTest extends TestCase
{
    private function event(string $routeName): RequestEvent
    {
        $request = Request::create('/whatever');
        $request->attributes->set('_route', $routeName);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function subscriber(
        array $configuredKeys = [],
        array $healthy = [],
    ): ServiceRouteGuardSubscriber {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(fn(string $k) => in_array($k, $configuredKeys, true));

        // v1.1.0 — radarr/sonarr now go through ServiceInstanceProvider.
        // Mirror the old "configuredKeys" semantics: presence of the api_key
        // marker → at least one enabled instance exists. The `radarr_url`
        // marker on its own is intentionally NOT enough (mirrors the v1.0
        // "url + api_key both required" rule, kept by testPartiallyConfigured).
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasAnyEnabled')->willReturnCallback(
            fn(string $type) => match ($type) {
                ServiceInstance::TYPE_RADARR =>
                    in_array('radarr_api_key', $configuredKeys, true)
                    && in_array('radarr_url',     $configuredKeys, true),
                ServiceInstance::TYPE_SONARR =>
                    in_array('sonarr_api_key', $configuredKeys, true)
                    && in_array('sonarr_url',     $configuredKeys, true),
                default => false,
            }
        );

        $health = $this->createMock(HealthService::class);
        $health->method('isHealthy')->willReturnCallback(fn(string $s) => in_array($s, $healthy, true));

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(fn(string $name) => '/_route/' . $name);

        return new ServiceRouteGuardSubscriber(
            $config,
            $instances,
            $health,
            $urls,
            $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );
    }

    public function testUnmatchedRouteIsLetThrough(): void
    {
        $event = $this->event('app_home');
        ($this->subscriber())->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testUnconfiguredServiceRedirectsToWizard(): void
    {
        $event = $this->event('radarr_index');
        ($this->subscriber())->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_setup_managers', $response->getTargetUrl());
    }

    public function testConfiguredAndHealthyLetsThroughAnySubRoute(): void
    {
        $event = $this->event('radarr_calendar');
        $sub = $this->subscriber(
            configuredKeys: ['radarr_api_key', 'radarr_url'],
            healthy: ['radarr']
        );
        $sub->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testConfiguredButUnhealthyRedirectsToIndex(): void
    {
        // Not the index itself → redirects to index (which shows the banner).
        $event = $this->event('radarr_calendar');
        $sub = $this->subscriber(
            configuredKeys: ['radarr_api_key', 'radarr_url'],
            healthy: [] // radarr not healthy
        );
        $sub->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_media_films', $response->getTargetUrl());
    }

    public function testUnhealthyOnIndexItselfDoesNotLoop(): void
    {
        // The index handles its own banner: subscriber must not redirect.
        $event = $this->event('app_media_films');
        $sub = $this->subscriber(
            configuredKeys: ['radarr_api_key', 'radarr_url'],
            healthy: []
        );
        $sub->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testPartiallyConfiguredRedirectsToWizard(): void
    {
        // Only radarr_api_key set, radarr_url missing → still treated as "not configured".
        $event = $this->event('radarr_index');
        $sub = $this->subscriber(
            configuredKeys: ['radarr_api_key']
        );
        $sub->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_setup_managers', $response->getTargetUrl());
    }

    public function testTmdbPrefixMatches(): void
    {
        $event = $this->event('tmdb_discover');
        ($this->subscriber())->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_setup_tmdb', $response->getTargetUrl());
    }

    public function testQbittorrentPrefixMatches(): void
    {
        $event = $this->event('app_qbittorrent_add');
        ($this->subscriber())->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_setup_downloads', $response->getTargetUrl());
    }
}
