<?php

namespace App\Tests\EventSubscriber;

use App\Entity\ServiceInstance;
use App\EventSubscriber\MultiInstanceBinderSubscriber;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AllowMockObjectsWithoutExpectations]
class MultiInstanceBinderSubscriberTest extends TestCase
{
    /**
     * Build a RequestEvent with the given route name and slug attribute,
     * defaulting to a MAIN_REQUEST so the subscriber actually runs.
     */
    private function makeEvent(?string $route, ?string $slug, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create('/');
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        if ($slug !== null) {
            $request->attributes->set('slug', $slug);
        }
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, $type);
    }

    private function newSubscriber(
        ServiceInstanceProvider $instances,
        RadarrClient $radarr,
        SonarrClient $sonarr,
    ): MultiInstanceBinderSubscriber {
        return new MultiInstanceBinderSubscriber($instances, $radarr, $sonarr);
    }

    public function testSubRequestIsIgnored(): void
    {
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $radarr    = $this->createMock(RadarrClient::class);
        $sonarr    = $this->createMock(SonarrClient::class);
        // Sub-requests must NOT trigger any binding — the parent request
        // already bound the right instance.
        $radarr->expects($this->never())->method('bindInstance');
        $sonarr->expects($this->never())->method('bindInstance');
        $instances->expects($this->never())->method('getBySlug');

        $event = $this->makeEvent('radarr_indexers', 'radarr-1', HttpKernelInterface::SUB_REQUEST);
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }

    public function testRouteWithoutSlugAttributeIsNoOp(): void
    {
        // Global routes (e.g. /admin/settings, /api/health/services) carry
        // no slug — the subscriber must leave the autowired clients alone
        // so they lazy-load the default instance via ensureConfig().
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $radarr    = $this->createMock(RadarrClient::class);
        $sonarr    = $this->createMock(SonarrClient::class);
        $radarr->expects($this->never())->method('bindInstance');
        $sonarr->expects($this->never())->method('bindInstance');

        $event = $this->makeEvent('admin_settings_index', null);
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }

    public function testRouteOutOfScopeIsNoOp(): void
    {
        // Route name doesn't match radarr_/sonarr_/app_media_* prefixes — the
        // subscriber's routeToInstanceType() returns null and we exit.
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $radarr    = $this->createMock(RadarrClient::class);
        $sonarr    = $this->createMock(SonarrClient::class);
        $radarr->expects($this->never())->method('bindInstance');
        $sonarr->expects($this->never())->method('bindInstance');

        $event = $this->makeEvent('app_dashboard', 'whatever');
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }

    public function testRadarrRouteWithSlugBindsRadarrClient(): void
    {
        $instance = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-4k', '4K', 'http://r:7878', 'k');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')
            ->with(ServiceInstance::TYPE_RADARR, 'radarr-4k')
            ->willReturn($instance);
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $radarr->expects($this->once())->method('bindInstance')->with($this->identicalTo($instance));
        $sonarr->expects($this->never())->method('bindInstance');

        $event = $this->makeEvent('radarr_indexers', 'radarr-4k');
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }

    public function testAppMediaFilmsRouteBindsRadarrClient(): void
    {
        // Cross-controller: MediaController routes are prefixed app_media_*
        // and films / series sub-paths target the radarr / sonarr type
        // respectively.
        $instance = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Default', 'http://r:7878', 'k');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')
            ->with(ServiceInstance::TYPE_RADARR, 'radarr-1')
            ->willReturn($instance);
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $radarr->expects($this->once())->method('bindInstance')->with($this->identicalTo($instance));
        $sonarr->expects($this->never())->method('bindInstance');

        $event = $this->makeEvent('app_media_films_rename', 'radarr-1');
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }

    public function testAppMediaSeriesRouteBindsSonarrClient(): void
    {
        $instance = new ServiceInstance(ServiceInstance::TYPE_SONARR, 'sonarr-anime', 'Anime', 'http://s:8989', 'k');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')
            ->with(ServiceInstance::TYPE_SONARR, 'sonarr-anime')
            ->willReturn($instance);
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $radarr->expects($this->never())->method('bindInstance');
        $sonarr->expects($this->once())->method('bindInstance')->with($this->identicalTo($instance));

        $event = $this->makeEvent('app_media_series_rename', 'sonarr-anime');
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }

    public function testUnknownSlugThrowsNotFound(): void
    {
        // The route declared a slug, the request carries it, but no instance
        // matches — must surface as a clean 404 rather than silently bind null.
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')->willReturn(null);
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $radarr->expects($this->never())->method('bindInstance');
        $sonarr->expects($this->never())->method('bindInstance');

        $event = $this->makeEvent('radarr_indexers', 'ghost-slug');
        $this->expectException(NotFoundHttpException::class);
        $this->newSubscriber($instances, $radarr, $sonarr)->onKernelRequest($event);
    }
}
