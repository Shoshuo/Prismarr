<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\EventSubscriber\LastVisitedRouteSubscriber;
use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use App\Service\ServiceInstanceProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Home page — picks the best landing page based on the admin's
 * `display_home_page` preference. Defaults to the dashboard (new in
 * Session 9b). Falls back to the legacy service-availability chain
 * (tmdb → radarr → sonarr → qbit → welcome) when the preferred target
 * is not usable (e.g. "discovery" chosen but TMDb not yet configured
 * or "last visited" without a tracked cookie yet).
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        Request $request,
        ConfigService $config,
        ServiceInstanceProvider $instances,
        DisplayPreferencesService $prefs,
        RouterInterface $router,
    ): Response {
        $preferred = $this->routeForPreference($prefs->getHomePage(), $config, $instances, $request, $router);
        if ($preferred !== null) {
            return $this->redirectToRoute($preferred);
        }

        // Fallback chain — land on the first configured service so the user
        // always sees something useful even if their chosen preference is
        // not yet backed by config.
        if ($config->has('tmdb_api_key')) {
            return $this->redirectToRoute('tmdb_index');
        }
        if ($instances->hasAnyEnabled(ServiceInstance::TYPE_RADARR)) {
            return $this->redirectToRoute('app_media_films');
        }
        if ($instances->hasAnyEnabled(ServiceInstance::TYPE_SONARR)) {
            return $this->redirectToRoute('app_media_series');
        }
        if ($config->has('qbittorrent_url')) {
            return $this->redirectToRoute('app_qbittorrent_index');
        }

        return $this->render('home/welcome.html.twig');
    }

    /**
     * Resolve the admin's preference to a concrete route name, or null if
     * the preferred target isn't currently reachable (service not configured,
     * no tracked "last visited" yet, etc.) — in which case the caller falls
     * back to the legacy service-availability chain.
     */
    private function routeForPreference(
        string $homePage,
        ConfigService $config,
        ServiceInstanceProvider $instances,
        Request $request,
        RouterInterface $router,
    ): ?string {
        return match ($homePage) {
            'dashboard'   => 'app_dashboard',
            'discovery'   => $config->has('tmdb_api_key')                                 ? 'tmdb_index'              : null,
            'films'       => $instances->hasAnyEnabled(ServiceInstance::TYPE_RADARR)      ? 'app_media_films'         : null,
            'series'      => $instances->hasAnyEnabled(ServiceInstance::TYPE_SONARR)      ? 'app_media_series'        : null,
            'qbittorrent' => $config->has('qbittorrent_url')                              ? 'app_qbittorrent_index'   : null,
            'last'        => $this->resolveLastVisitedRoute($request, $router),
            default       => null,
        };
    }

    /**
     * Read the cookie set by LastVisitedRouteSubscriber and only honor it
     * when the route still exists in the current router (a user upgrading
     * through a route rename shouldn't end up on a 404 on every home hit).
     *
     * Extra safety net: reject obvious non-landing routes even if a stale
     * cookie from an older Prismarr build points at them (e.g. JSON APIs
     * or internal endpoints — a regression from an earlier subscriber
     * version could have cached them).
     */
    private function resolveLastVisitedRoute(Request $request, RouterInterface $router): ?string
    {
        $route = $request->cookies->get(LastVisitedRouteSubscriber::COOKIE_NAME);
        if (!is_string($route) || $route === '' || $route === 'app_home') {
            return null;
        }

        $badPrefixes = ['api_', 'app_profile_avatar_', 'app_qbittorrent_api_', '_'];
        foreach ($badPrefixes as $p) {
            if (str_starts_with($route, $p)) {
                return null;
            }
        }

        return $router->getRouteCollection()->get($route) !== null ? $route : null;
    }
}
