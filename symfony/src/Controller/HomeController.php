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
            return $this->redirectToRoute($preferred[0], $preferred[1]);
        }

        // Fallback chain — land on the first configured service so the user
        // always sees something useful even if their chosen preference is
        // not yet backed by config.
        if ($config->has('tmdb_api_key')) {
            return $this->redirectToRoute('tmdb_index');
        }
        if ($instances->hasAnyEnabled(ServiceInstance::TYPE_RADARR)) {
            // Phase C — slug is mandatory, plug the default instance.
            $defaultRadarr = $instances->getDefault(ServiceInstance::TYPE_RADARR);
            return $this->redirectToRoute('app_media_films', ['slug' => $defaultRadarr->getSlug()]);
        }
        if ($instances->hasAnyEnabled(ServiceInstance::TYPE_SONARR)) {
            $defaultSonarr = $instances->getDefault(ServiceInstance::TYPE_SONARR);
            return $this->redirectToRoute('app_media_series', ['slug' => $defaultSonarr->getSlug()]);
        }
        if ($config->has('qbittorrent_url')) {
            return $this->redirectToRoute('app_qbittorrent_index');
        }

        return $this->render('home/welcome.html.twig');
    }

    /**
     * Resolve the admin's preference to a [routeName, params] tuple, or null
     * if the preferred target isn't currently reachable (service not configured,
     * no tracked "last visited" yet, etc.) — in which case the caller falls
     * back to the legacy service-availability chain.
     *
     * Returning the params alongside the route name is mandatory for the
     * Phase C slug-aware routes (`app_media_films`, `app_media_series`,
     * `app_media_qbittorrent_*`) — generating them without a slug throws
     * MissingMandatoryParametersException on every home hit otherwise.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function routeForPreference(
        string $homePage,
        ConfigService $config,
        ServiceInstanceProvider $instances,
        Request $request,
        RouterInterface $router,
    ): ?array {
        switch ($homePage) {
            case 'dashboard':
                return ['app_dashboard', []];
            case 'discovery':
                return $config->has('tmdb_api_key') ? ['tmdb_index', []] : null;
            case 'films':
                if (!$instances->hasAnyEnabled(ServiceInstance::TYPE_RADARR)) return null;
                return ['app_media_films', ['slug' => $instances->getDefault(ServiceInstance::TYPE_RADARR)->getSlug()]];
            case 'series':
                if (!$instances->hasAnyEnabled(ServiceInstance::TYPE_SONARR)) return null;
                return ['app_media_series', ['slug' => $instances->getDefault(ServiceInstance::TYPE_SONARR)->getSlug()]];
            case 'qbittorrent':
                return $config->has('qbittorrent_url') ? ['app_qbittorrent_index', []] : null;
            case 'last':
                return $this->resolveLastVisitedRoute($request, $router, $instances);
            default:
                return null;
        }
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
     *
     * Phase C — if the resolved route requires a {slug} parameter
     * (Radarr/Sonarr admin or media routes), hydrate it from the default
     * instance of the corresponding service type. Otherwise generateUrl()
     * raises MissingMandatoryParametersException and the home page 500s.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function resolveLastVisitedRoute(Request $request, RouterInterface $router, ServiceInstanceProvider $instances): ?array
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

        $compiled = $router->getRouteCollection()->get($route);
        if ($compiled === null) {
            return null;
        }

        $params = [];
        if (in_array('slug', $compiled->compile()->getVariables(), true)) {
            $type = $this->routeNameToInstanceType($route);
            if ($type === null) return null;
            $default = $instances->getDefault($type);
            if ($default === null) return null;
            $params['slug'] = $default->getSlug();
        }
        return [$route, $params];
    }

    /**
     * Mirror of MultiInstanceBinderSubscriber::routeToInstanceType — kept
     * inline here since the subscriber's helper is private and we only
     * need it for the home redirect path.
     */
    private function routeNameToInstanceType(string $route): ?string
    {
        if (str_starts_with($route, 'radarr_'))                                                       return ServiceInstance::TYPE_RADARR;
        if (str_starts_with($route, 'sonarr_'))                                                       return ServiceInstance::TYPE_SONARR;
        if (str_starts_with($route, 'app_media_films') || str_starts_with($route, 'app_media_radarr')) return ServiceInstance::TYPE_RADARR;
        if (str_starts_with($route, 'app_media_series') || str_starts_with($route, 'app_media_sonarr')) return ServiceInstance::TYPE_SONARR;
        return null;
    }
}
