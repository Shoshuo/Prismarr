<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home page — picks the best landing page based on the admin's
 * `display_home_page` preference. Defaults to the dashboard (new in
 * Session 9b). Falls back to the legacy service-availability chain
 * (tmdb → radarr → sonarr → qbit → welcome) when the preferred target
 * is not usable (e.g. "discovery" chosen but TMDb not yet configured).
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(ConfigService $config, DisplayPreferencesService $prefs): Response
    {
        $preferred = $this->routeForPreference($prefs->getHomePage(), $config);
        if ($preferred !== null) {
            return $this->redirectToRoute($preferred);
        }

        // Fallback chain — land on the first configured service so the user
        // always sees something useful even if their chosen preference is
        // not yet backed by config.
        if ($config->has('tmdb_api_key')) {
            return $this->redirectToRoute('tmdb_index');
        }
        if ($config->has('radarr_api_key')) {
            return $this->redirectToRoute('app_media_films');
        }
        if ($config->has('sonarr_api_key')) {
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
     * or "last visited" which isn't tracked yet).
     */
    private function routeForPreference(string $homePage, ConfigService $config): ?string
    {
        return match ($homePage) {
            'dashboard'   => 'app_dashboard',
            'discovery'   => $config->has('tmdb_api_key')    ? 'tmdb_index'              : null,
            'films'       => $config->has('radarr_api_key')  ? 'app_media_films'         : null,
            'series'      => $config->has('sonarr_api_key')  ? 'app_media_series'        : null,
            'qbittorrent' => $config->has('qbittorrent_url') ? 'app_qbittorrent_index'   : null,
            default       => null, // 'last' not yet implemented → fallback chain
        };
    }
}
