<?php

namespace App\EventSubscriber;

use App\Entity\ServiceInstance;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * v1.1.0 Phase C — multi-instance request binder.
 *
 * Looks at the matched route attributes for a `slug` placeholder and binds
 * the corresponding ServiceInstance onto the autowired RadarrClient or
 * SonarrClient for the rest of the request. This means the ~300 admin
 * methods in RadarrController / SonarrController / MediaController don't
 * have to plumb the slug everywhere — they keep calling `$this->radarr`
 * (or `$this->sonarr`) which now transparently hits the right upstream.
 *
 * Decision is route-name driven (not URL-shape driven) so a future route
 * rename doesn't silently break the binding. The `_route` attribute MUST
 * start with `radarr_`, `sonarr_`, or `app_media_` for the subscriber to
 * touch anything; everything else is left untouched.
 *
 * Skipped on sub-requests (forward(), ESI fragments) so a media controller
 * forwarded into another won't re-bind the parent's client.
 *
 * Throws NotFoundHttpException when the URL carries a slug that does not
 * resolve to an existing instance — gives the user a clean 404 instead of
 * a stale-binding silent miss.
 *
 * Priority 12: runs after ServiceRouteGuardSubscriber (15) so the
 * service-not-configured redirect happens first, but before the controller
 * is invoked.
 */
final class MultiInstanceBinderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 12],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return;
        }

        $type = $this->routeToInstanceType($route);
        if ($type === null) {
            return;
        }

        $slug = $request->attributes->get('slug');
        if (!is_string($slug) || $slug === '') {
            // Route does not carry a slug (legacy / aggregate route).
            // Leave the autowired clients alone — they will lazy-load the
            // default instance via ensureConfig() on first call.
            return;
        }

        $instance = $this->instances->getBySlug($type, $slug);
        if ($instance === null) {
            throw new NotFoundHttpException(sprintf(
                'No %s instance with slug "%s".',
                $type,
                $slug,
            ));
        }

        if ($type === ServiceInstance::TYPE_RADARR) {
            $this->radarr->bindInstance($instance);
        } else {
            $this->sonarr->bindInstance($instance);
        }
    }

    /**
     * Map a Symfony route name to the upstream service type its slug
     * placeholder targets. Returns null when the route is unrelated.
     *
     * Naming conventions (kept stable since v1.0):
     *   - `radarr_*`     → admin pages of a Radarr instance
     *   - `sonarr_*`     → admin pages of a Sonarr instance
     *   - `app_media_*`  → MediaController routes; films/series sub-paths
     *                      target Radarr (films) or Sonarr (series). The
     *                      route name carries the disambiguator.
     */
    private function routeToInstanceType(string $route): ?string
    {
        if (str_starts_with($route, 'radarr_')) {
            return ServiceInstance::TYPE_RADARR;
        }
        if (str_starts_with($route, 'sonarr_')) {
            return ServiceInstance::TYPE_SONARR;
        }
        if (str_starts_with($route, 'app_media_films') || str_starts_with($route, 'app_media_radarr')) {
            return ServiceInstance::TYPE_RADARR;
        }
        if (str_starts_with($route, 'app_media_series') || str_starts_with($route, 'app_media_sonarr')) {
            return ServiceInstance::TYPE_SONARR;
        }
        return null;
    }
}
