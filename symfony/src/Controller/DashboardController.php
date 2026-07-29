<?php

namespace App\Controller;

use App\Dashboard\NetworkUsageChart;
use App\Entity\ServiceInstance;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\HealthService;
use App\Service\Media\HoundarrClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\UnifiClient;
use App\Service\Media\UnraidClient;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dashboard landing page — aggregates the most relevant recent signals from
 * every configured service into a single screen. Every widget fails open:
 * if a client throws (service down, misconfigured, HTTP timeout) we log a
 * warning and render the widget in its empty state rather than crash the
 * whole page. Session 9c will wire the UI preferences (timezone, date
 * format, density…) into this template.
 */
class DashboardController extends AbstractController implements ResetInterface
{
    private const UPCOMING_DAYS       = 7;
    private const MAX_REQUESTS        = 5;
    private const MAX_RECOMMENDATIONS = 16;
    private const MAX_RECENT          = 16;
    private const MAX_WATCHLIST       = 16;
    // #30 — short server-side cache for the heavy per-widget upstream calls.
    // Radarr/Sonarr aren't client-cached (unlike TMDb), so the async widget
    // fragments would each hit them live; this dedupes the calls shared across
    // the parallel fragments and makes revisits within the window instant.
    private const WIDGET_CACHE_TTL    = 45;

    /**
     * Per-request memoization for the expensive library listings — each
     * of `getMovies()` / `getSeries()` is called by 3 different widgets
     * (stats, recent additions, hero spotlight). Without this cache every
     * dashboard paint would hit Radarr/Sonarr 3× for the same payload.
     * @var list<array<string, mixed>>|null
     */
    private ?array $moviesCache = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $seriesCache = null;

    public function __construct(
        private readonly HealthService $health,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly JellyseerrClient $jellyseerr,
        private readonly TmdbClient $tmdb,
        private readonly WatchlistItemRepository $watchlistRepo,
        private readonly ServiceInstanceProvider $instances,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly CacheInterface $cache,
        private readonly TautulliClient $tautulli,
        private readonly \App\Service\DashboardLayoutService $layout,
        // Unraid server widget — nullable + last so legacy positional test
        // constructors keep working.
        private readonly ?UnraidClient $unraid = null,
        // Houndarr stat tile — nullable + last so legacy positional test
        // constructors keep working.
        private readonly ?HoundarrClient $houndarr = null,
        // UniFi network widget — nullable + last so legacy positional test
        // constructors keep working.
        private readonly ?UnifiClient $unifi = null,
    ) {}

    /**
     * FrankenPHP worker mode — controllers autowired as services are shared
     * singletons that survive across requests in a worker. Without this, the
     * per-request `$moviesCache` / `$seriesCache` memo (primed by the first
     * dashboard paint) would be served to every later request in the same
     * worker, showing stale library data until the worker recycled. Symfony's
     * services_resetter calls reset() between requests (auto-tagged
     * kernel.reset via the ResetInterface autoconfiguration).
     */
    public function reset(): void
    {
        $this->moviesCache = null;
        $this->seriesCache = null;
    }

    /**
     * #30 — wrap an expensive upstream aggregate in a short shared cache.
     * An empty result (every instance failed / nothing configured) is NOT
     * cached: it expires immediately so the next paint retries instead of
     * pinning a transient failure for the whole window. Keyed globally (the
     * dashboard aggregates across instances); the TTL self-heals topology
     * changes and the pool is purged on /admin/settings save.
     *
     * @param callable():array $fn
     * @return array<mixed>
     */
    private function cached(string $key, callable $fn): array
    {
        return $this->cache->get('dash.' . $key, function (ItemInterface $item) use ($fn) {
            $result = $fn();
            $item->expiresAfter($result === [] ? 0 : self::WIDGET_CACHE_TTL);
            return $result;
        });
    }

    /**
     * Slug helper for the deep-link URLs the dashboard renders into films
     * and series. Dashboard pages aren't instance-scoped (they aggregate
     * across instances), so we always link to the user's default Radarr /
     * Sonarr — the films/series page itself can switch via the sidebar.
     */
    private function defaultSlug(string $type): string
    {
        return $this->instances->getDefault($type)?->getSlug() ?? $type . '-1';
    }

    /**
     * Aggregate Radarr movies across every enabled instance, tagging each
     * row with `_instanceSlug` so the consumers (recent additions, hero
     * spotlight) can deep-link to the right instance instead of always
     * pointing at the default. Same fan-out / per-request memoization
     * pattern as before — `safeFetch` swallows per-instance failures so
     * one ailing Radarr 4K doesn't blank out the whole dashboard.
     */
    private function movies(): array
    {
        return $this->moviesCache ??= $this->cached('movies', function () {
            $out = [];
            foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
                $rows = $this->safeFetch(
                    'library.movies.' . $inst->getSlug(),
                    fn() => $this->radarr->withInstance($inst)->getMovies(),
                ) ?? [];
                foreach ($rows as $row) {
                    $row['_instanceSlug'] = $inst->getSlug();
                    $row['_instanceName'] = $inst->getName();
                    $out[] = $row;
                }
            }
            return $out;
        });
    }

    private function series(): array
    {
        return $this->seriesCache ??= $this->cached('series', function () {
            $out = [];
            foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
                $rows = $this->safeFetch(
                    'library.series.' . $inst->getSlug(),
                    fn() => $this->sonarr->withInstance($inst)->getSeries(),
                ) ?? [];
                foreach ($rows as $row) {
                    $row['_instanceSlug'] = $inst->getSlug();
                    $row['_instanceName'] = $inst->getName();
                    $out[] = $row;
                }
            }
            return $out;
        });
    }

    #[Route('/tableau-de-bord', name: 'app_dashboard')]
    public function index(): Response
    {
        // Issue #27 — every upstream-dependent widget (hero, stats, upcoming,
        // requests, health, recommendations, recent additions) now loads from
        // its own async fragment endpoint after first paint, so index() makes
        // no remote calls: it renders the shell + skeletons instantly. Only the
        // watchlist (local DB) and the cheap service-configured flags stay here.
        $configured = [
            'radarr'     => $this->health->isConfigured('radarr'),
            'sonarr'     => $this->health->isConfigured('sonarr'),
            'jellyseerr' => $this->health->isConfigured('jellyseerr'),
            'tmdb'       => $this->health->isConfigured('tmdb'),
            'tautulli'   => $this->health->isConfigured('tautulli'),
            'unraid'     => $this->health->isConfigured('unraid'),
            'houndarr'   => $this->health->isConfigured('houndarr'),
            'unifi'      => $this->health->isConfigured('unifi'),
        ];

        return $this->render('dashboard/index.html.twig', [
            'watchlist'           => $this->watchlist(),
            'services_configured' => $configured,
            'dashboard_layout'    => $this->layout->resolve(),
        ]);
    }

    /**
     * Async fragment (#27) — the "Recent additions" poster row, fetched after
     * the dashboard shell has painted so the initial render stays fast. Fails
     * open: an empty body (no items / fetch failure) hides the section
     * client-side. Skipped entirely when neither library is configured.
     */
    #[Route('/tableau-de-bord/widget/recent', name: 'app_dashboard_widget_recent')]
    public function widgetRecent(): Response
    {
        if (!$this->health->isConfigured('radarr') && !$this->health->isConfigured('sonarr')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_recent_additions.html.twig', [
            'recent_additions' => $this->recentAdditions(),
        ]);
    }

    /**
     * Async fragment (#27) — TMDb trending poster row. Empty body hides the
     * section client-side. Skipped when TMDb isn't configured.
     */
    #[Route('/tableau-de-bord/widget/recommendations', name: 'app_dashboard_widget_recommendations')]
    public function widgetRecommendations(): Response
    {
        if (!$this->health->isConfigured('tmdb')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_recommendations.html.twig', [
            'recommendations' => $this->recommendations(),
        ]);
    }

    /**
     * Async fragment (#27) — the upcoming 7-day mini-calendar. Returns the
     * empty-state message when there's nothing (so the card keeps showing);
     * an empty body only happens when neither library is configured, hiding
     * the card client-side.
     */
    #[Route('/tableau-de-bord/widget/upcoming', name: 'app_dashboard_widget_upcoming')]
    public function widgetUpcoming(): Response
    {
        if (!$this->health->isConfigured('radarr') && !$this->health->isConfigured('sonarr')) {
            return new Response('');
        }
        set_time_limit(60);
        $upcoming = $this->upcomingReleases();

        return $this->render('dashboard/_upcoming.html.twig', [
            'upcoming'      => $upcoming,
            'upcoming_days' => $this->upcomingByDay($upcoming),
        ]);
    }

    /**
     * Async fragment (#27) — pending Jellyseerr requests list. Only reached
     * when Jellyseerr is configured (the card is otherwise not rendered);
     * returns the empty-state message when there are no pending requests.
     */
    #[Route('/tableau-de-bord/widget/requests', name: 'app_dashboard_widget_requests')]
    public function widgetRequests(): Response
    {
        if (!$this->health->isConfigured('jellyseerr')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_requests.html.twig', [
            'jellyseerr_requests' => $this->pendingRequests(),
        ]);
    }

    /**
     * Combined live-widget fragment endpoint (#perf). The dashboard's five
     * "live" widgets (plex/health/server/houndarr/network) poll on staggered
     * cadences; instead of one timer + one HTTP request each, the client
     * coalesces every widget due on a given tick into a single request here and
     * we render each requested fragment into a { name: html } JSON map.
     *
     * Reuses the individual widget actions verbatim, so their admin gates,
     * empty-when-unconfigured contract and fail-open behaviour are identical. A
     * widget that renders empty (not applicable) or an unknown name is omitted
     * from the map — the client then leaves that node at its last value, which
     * matches each per-widget poll's own "keep last on empty" behaviour.
     */
    #[Route('/tableau-de-bord/widgets', name: 'app_dashboard_widgets')]
    public function widgets(Request $request): Response
    {
        set_time_limit(60);

        $names = array_filter(array_map('trim', explode(',', (string) $request->query->get('w', ''))));
        $out = [];
        foreach (array_unique($names) as $name) {
            $fragment = $this->renderLiveWidget($name);
            if ($fragment === null) {
                continue;
            }
            $html = (string) $fragment->getContent();
            if (trim($html) === '') {
                continue;
            }
            $out[$name] = $html;
        }

        return $this->json($out);
    }

    /**
     * Dispatch a live-widget name to its existing fragment action. Unknown
     * names return null (omitted from the combined map). Kept in sync with the
     * data-dash-poll nodes in the dashboard section partials.
     */
    private function renderLiveWidget(string $name): ?Response
    {
        return match ($name) {
            'plex'     => $this->widgetPlex(),
            'health'   => $this->widgetHealth(),
            'server'   => $this->widgetServer(),
            'houndarr' => $this->widgetHoundarr(),
            'network'  => $this->widgetNetwork(),
            default    => null,
        };
    }

    /**
     * Async fragment (#27) — services health chips. The card is always present;
     * the pings can be slow (timeouts on a down service), so it loads after
     * first paint. Unconfigured services come back as null and are filtered out.
     */
    #[Route('/tableau-de-bord/widget/health', name: 'app_dashboard_widget_health')]
    public function widgetHealth(): Response
    {
        set_time_limit(60);

        return $this->render('dashboard/_health.html.twig', [
            'services_health' => $this->health->chips($this->isGranted('ROLE_ADMIN')),
        ]);
    }

    /**
     * Async fragment — current Plex activity from Tautulli. Skipped entirely
     * (empty body → hidden client-side) when Tautulli isn't configured /
     * enabled. Otherwise renders the widget body; the fragment re-fetches on a
     * 10 s interval (see index.html.twig) and fails open to an error state, so
     * a down/misconfigured Tautulli never breaks the dashboard.
     */
    #[Route('/tableau-de-bord/widget/plex', name: 'app_dashboard_widget_plex')]
    public function widgetPlex(): Response
    {
        if (!$this->health->isConfigured('tautulli')) {
            return new Response('');
        }
        set_time_limit(60);

        $activity = $this->tautulli->getActivity();

        // History changes slowly; cache it ~60s so the 10s now-playing poll
        // doesn't hit get_history every time. The relative time label is now
        // formatted in the template via the relative_date Twig filter.
        $history = $this->cached('plex_history', fn() => $this->tautulli->getHistory(8));

        $streaming = ($activity['streamCount'] ?? 0) > 0;

        return $this->render('dashboard/_plex_activity.html.twig', [
            'plex'        => $activity,
            'plex_history'=> $history,
            'plex_tab'    => $streaming ? 'now' : 'recent',
        ]);
    }

    /**
     * Async fragment — Unraid server monitoring (array, disks, system, Docker,
     * UPS). Admin-only: server internals aren't for regular users, so both the
     * fragment and the section partial gate on ROLE_ADMIN, and non-admins never
     * trigger an Unraid API call. Empty body → hidden client-side. Fails open:
     * an unreachable Unraid renders the fragment's "unreachable" state.
     */
    #[Route('/tableau-de-bord/widget/server', name: 'app_dashboard_widget_server')]
    public function widgetServer(): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return new Response('');
        }
        if ($this->unraid === null || !$this->health->isConfigured('unraid')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_server.html.twig', [
            'server' => $this->unraid->overview(),
        ]);
    }

    /**
     * Async fragment — Houndarr backlog-search totals. Visible to every
     * logged-in user (harmless read-only counts). Empty body → hidden
     * client-side when unconfigured. Fails open: an unreachable Houndarr
     * renders the fragment's "unreachable" state, never breaks the dashboard.
     */
    #[Route('/tableau-de-bord/widget/houndarr', name: 'app_dashboard_widget_houndarr')]
    public function widgetHoundarr(): Response
    {
        if ($this->houndarr === null || !$this->health->isConfigured('houndarr')) {
            return new Response('');
        }

        return $this->render('dashboard/_houndarr.html.twig', [
            'houndarr'  => $this->houndarr->widget(),
            'instances' => $this->arrWantedCounts(),
        ]);
    }

    /**
     * Per-instance Radarr/Sonarr wanted counts for the Houndarr widget's
     * *arr split rows. These come from OUR *arr clients (`totalRecords` of
     * /wanted/missing and /wanted/cutoff), not from Houndarr's cooldown
     * math — the widget renders a micro-note to that effect. One row per
     * enabled instance, Radarr first. Fails open per instance: a dead
     * Radarr yields null counts (rendered as '—'), never a broken fragment.
     *
     * @return list<array{type: string, name: string, wanted: ?int, cutoffUnmet: ?int}>
     */
    private function arrWantedCounts(): array
    {
        return $this->cached('houndarr.arr_wanted', function () {
            // totalRecords read defensively: the *arr wanted endpoints return
            // the decoded paging envelope directly ({page, totalRecords,
            // records…}), but a failed call surfaces as [] — count unknown.
            $total = static function (array $data): ?int {
                return isset($data['totalRecords']) ? (int) $data['totalRecords'] : null;
            };

            $out = [];
            foreach ([['radarr', ServiceInstance::TYPE_RADARR, $this->radarr],
                      ['sonarr', ServiceInstance::TYPE_SONARR, $this->sonarr]] as [$type, $instType, $client]) {
                foreach ($this->instances->getEnabled($instType) as $inst) {
                    $wanted = $cutoff = null;
                    try {
                        $bound  = $client->withInstance($inst);
                        // pageSize 1 — only the envelope's totalRecords matters.
                        $wanted = $total($bound->getMissing(1, 1));
                        $cutoff = $total($bound->getCutoff(1, 1));
                    } catch (\Throwable $e) {
                        $this->logger->warning('Dashboard widget failed [houndarr.arr_wanted.{slug}]: {message}', [
                            'slug'    => $inst->getSlug(),
                            'message' => $e->getMessage(),
                        ]);
                    }
                    $out[] = [
                        'type'        => $type,
                        'name'        => $inst->getName(),
                        'wanted'      => $wanted,
                        'cutoffUnmet' => $cutoff,
                    ];
                }
            }
            return $out;
        });
    }

    /**
     * Async fragment — UniFi network overview (WAN throughput, clients, 24h
     * usage, infrastructure). Admin-only: WAN IPs and client counts aren't
     * for regular users, so both the fragment and the section partial gate
     * on ROLE_ADMIN, and non-admins never trigger a UniFi call. Empty body →
     * hidden client-side. Fails open: an unreachable console renders the
     * fragment's "unreachable" state.
     */
    #[Route('/tableau-de-bord/widget/network', name: 'app_dashboard_widget_network')]
    public function widgetNetwork(): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return new Response('');
        }
        if ($this->unifi === null || !$this->health->isConfigured('unifi')) {
            return new Response('');
        }
        set_time_limit(60);

        $net = $this->unifi->overview();

        return $this->render('dashboard/_network.html.twig', [
            'net'   => $net,
            'chart' => NetworkUsageChart::build($net['usage24h'] ?? null),
        ]);
    }

    /**
     * Async fragment (#27) — hero spotlight + library stats. Pulls the three
     * heaviest sources (TMDb recommendations, Radarr/Sonarr library counts and
     * the upcoming count) off the initial render; the greeting/clock stay in
     * index.html.twig so the hero shell paints instantly.
     */
    #[Route('/tableau-de-bord/widget/hero', name: 'app_dashboard_widget_hero')]
    public function widgetHero(): Response
    {
        set_time_limit(60);
        $recommendations = $this->health->isConfigured('tmdb') ? $this->recommendations() : [];
        $upcoming = ($this->health->isConfigured('radarr') || $this->health->isConfigured('sonarr'))
            ? $this->upcomingReleases() : [];

        return $this->render('dashboard/_hero.html.twig', [
            'hero_spotlight' => $this->pickHeroSpotlight($recommendations),
            'stats'          => $this->stats(),
            'upcoming'       => $upcoming,
        ]);
    }

    /**
     * Quick-look fragment for a TMDb item (trending / watchlist, movie|tv).
     * Read-only; the modal on the dashboard fetches this and swaps it in.
     * Fails open to a small graceful body.
     */
    #[Route('/tableau-de-bord/quicklook/tmdb/{type}/{id}', name: 'app_dashboard_quicklook_tmdb', requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function quickLookTmdbAction(string $type, int $id): Response
    {
        set_time_limit(30);
        try {
            $ql = $this->quickLookTmdb($type, $id);
        } catch (\Throwable $e) {
            $this->logger->warning('Quick-look TMDb failed [{t}/{i}]: {m}', ['t' => $type, 'i' => $id, 'm' => $e->getMessage()]);
            $ql = null;
        }
        if ($ql === null) {
            return new Response('<div class="ql-error">' . htmlspecialchars($this->translator->trans('dashboard.quicklook.error')) . '</div>');
        }
        return $this->render('dashboard/_quicklook_body.html.twig', ['ql' => $ql]);
    }

    /**
     * Quick-look fragment for a Radarr/Sonarr library item (movie|series).
     * Read-only; the modal on the dashboard fetches this and swaps it in.
     * Fails open to a small graceful body.
     */
    #[Route('/tableau-de-bord/quicklook/{type}/{slug}/{id}', name: 'app_dashboard_quicklook', requirements: ['type' => 'movie|series', 'id' => '\d+'])]
    public function quickLook(string $type, string $slug, int $id): Response
    {
        set_time_limit(30);
        try {
            $ql = $this->quickLookLibrary($type, $slug, $id);
        } catch (\Throwable $e) {
            $this->logger->warning('Quick-look failed [{t}/{s}/{i}]: {m}', ['t' => $type, 's' => $slug, 'i' => $id, 'm' => $e->getMessage()]);
            $ql = null;
        }
        if ($ql === null) {
            return new Response('<div class="ql-error">' . htmlspecialchars($this->translator->trans('dashboard.quicklook.error')) . '</div>');
        }
        return $this->render('dashboard/_quicklook_body.html.twig', ['ql' => $ql]);
    }

    /**
     * @return array{films: ?int, series: ?int}
     */
    private function stats(): array
    {
        // qBittorrent counts used to live here but a single getTorrents()
        // call on a loaded daemon can add several seconds to the dashboard
        // paint. Active-download monitoring belongs on /qbittorrent anyway,
        // so the widget and the "en cours" hero stat were dropped.
        $movies = $this->movies();
        $series = $this->series();

        return [
            'films'  => $movies === [] ? null : count($movies),
            'series' => $series === [] ? null : count($series),
        ];
    }

    /**
     * Merges Radarr + Sonarr calendars over the next N days, keeping only
     * items with a future release/air date. Radarr's `getCalendar(7, 0)`
     * returns movies whose *any* of {digitalAt, inCinemasAt, physicalAt}
     * falls inside the window — so a movie that came out 2 months ago in
     * cinemas but has a Blu-ray release next week will appear. We pick the
     * earliest future date per item (and its matching badge) so the user
     * sees the genuinely upcoming event, not a stale one.
     */
    private function upcomingReleases(): array
    {
        return $this->cached('upcoming', function () {
        // Compare by calendar day (midnight) so events earlier today —
        // a morning episode, a midnight digital release — are still
        // classified as "today" rather than silently filtered out as past.
        $today = new \DateTimeImmutable('today');

        // Phase D — fan out across every enabled Radarr/Sonarr instance and
        // dedupe identical entries. Two Radarr instances both tracking the
        // same movie would otherwise double the upcoming card; we collapse
        // by (type, tmdbId/year/title) and keep the earliest date.
        $items = [];
        $movieKey = fn(array $m): string => 'movie:' . ($m['tmdbId'] ?? ($m['title'] ?? '?') . ':' . ($m['year'] ?? '?'));
        // tvdbId is stable across Sonarr instances; seriesId is per-instance
        // and would split the same episode into 2 rows when two Sonarr
        // instances both follow the show. SonarrClient::getCalendar()
        // surfaces tvdbId since v1.1.0 — fall back to seriesTitle for
        // legacy payloads that omit it.
        $episodeKey = fn(array $e): string => 'episode:'
            . ($e['tvdbId'] ?? $e['seriesTitle'] ?? '?')
            . ':S' . ($e['season'] ?? 0) . 'E' . ($e['episode'] ?? 0);
        $seen = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
            $movies = $this->safeFetch(
                'upcoming.radarr.' . $inst->getSlug(),
                fn() => $this->radarr->withInstance($inst)->getCalendar(self::UPCOMING_DAYS, 0),
            ) ?? [];
            foreach ($movies as $m) {
                $next = $this->pickNextReleaseDate($m, $today);
                if ($next === null) continue;
                $key = $movieKey($m);
                if (isset($seen[$key])) continue; // first instance to surface the movie wins
                $seen[$key] = true;
                $items[] = [
                    'type'     => 'movie',
                    'id'       => $m['id'] ?? null,
                    'title'    => $m['title'] ?? '—',
                    'subtitle' => $m['year'] ? ((string) $m['year']) : null,
                    'badge'    => $next['badge'],
                    'poster'   => $m['poster'] ?? null,
                    'date'     => $next['at'],
                    'slug'     => $inst->getSlug(),
                ];
            }
        }
        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
            $episodes = $this->safeFetch(
                'upcoming.sonarr.' . $inst->getSlug(),
                fn() => $this->sonarr->withInstance($inst)->getCalendar(self::UPCOMING_DAYS, 0),
            ) ?? [];
            foreach ($episodes as $e) {
                $airDate = $e['airDate'] ?? null;
                if (!$airDate instanceof \DateTimeImmutable) continue;
                if ($airDate->setTime(0, 0) < $today) continue;
                $key = $episodeKey($e);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $sxe = sprintf('S%02dE%02d', $e['season'] ?? 0, $e['episode'] ?? 0);
                $items[] = [
                    'type'     => 'episode',
                    'id'       => $e['seriesId'] ?? null,
                    'title'    => $e['seriesTitle'] ?? '—',
                    'subtitle' => $sxe . ($e['title'] && $e['title'] !== '—' ? ' — ' . $e['title'] : ''),
                    'badge'    => $e['network'] ?? null,
                    'poster'   => $e['poster'] ?? null,
                    'date'     => $airDate,
                    'slug'     => $inst->getSlug(),
                ];
            }
        }

        usort($items, fn($a, $b) => $a['date'] <=> $b['date']);

        return $items;
        });
    }

    /**
     * Group an already-sorted list of upcoming items into a 7-day calendar
     * structure [iso_date => {date, dayOfWeek, dayOfMonth, isToday, items}].
     * Missing days are still present with an empty items[] so the template
     * can render a fixed 7-column grid.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, array{date: \DateTimeImmutable, dow: string, dom: int, isToday: bool, items: list<array<string, mixed>>}>
     */
    private function upcomingByDay(array $items): array
    {
        $today = (new \DateTimeImmutable('today'));
        $days = [];

        for ($d = 0; $d < self::UPCOMING_DAYS; $d++) {
            $date = $today->modify("+{$d} days");
            $iso  = $date->format('Y-m-d');
            $days[$iso] = [
                'date'    => $date,
                'dow'     => mb_strtoupper(mb_substr($this->localizedDayName($date), 0, 3)),
                'dom'     => (int) $date->format('j'),
                'isToday' => $d === 0,
                'items'   => [],
            ];
        }

        foreach ($items as $item) {
            if (!$item['date'] instanceof \DateTimeImmutable) {
                continue;
            }
            $iso = $item['date']->format('Y-m-d');
            if (isset($days[$iso])) {
                $days[$iso]['items'][] = $item;
            }
        }

        return $days;
    }

    private function localizedDayName(\DateTimeImmutable $d): string
    {
        $keys = [
            'dashboard.weekdays.mon', 'dashboard.weekdays.tue', 'dashboard.weekdays.wed',
            'dashboard.weekdays.thu', 'dashboard.weekdays.fri', 'dashboard.weekdays.sat',
            'dashboard.weekdays.sun',
        ];

        return $this->translator->trans($keys[(int) $d->format('N') - 1]);
    }

    /**
     * Build the ordered release-date chips for a movie quick-look. Fixed
     * semantic order cinema→digital→physical; nulls skipped; each chip flags
     * whether the date is today-or-later (so the template can emphasize the
     * next upcoming event). @return list<array{kind:string,label:string,date:\DateTimeImmutable,upcoming:bool}>
     */
    private function movieReleaseChips(?\DateTimeImmutable $cinema, ?\DateTimeImmutable $digital, ?\DateTimeImmutable $physical, \DateTimeImmutable $today): array
    {
        $defs = [
            ['kind' => 'cinema',   'at' => $cinema,   'key' => 'dashboard.quicklook.date.cinema'],
            ['kind' => 'digital',  'at' => $digital,  'key' => 'dashboard.quicklook.date.digital'],
            ['kind' => 'physical', 'at' => $physical, 'key' => 'dashboard.quicklook.date.physical'],
        ];
        $chips = [];
        foreach ($defs as $d) {
            if (!$d['at'] instanceof \DateTimeImmutable) continue;
            $chips[] = [
                'kind'     => $d['kind'],
                'label'    => $this->translator->trans($d['key']),
                'date'     => $d['at'],
                'upcoming' => $d['at']->setTime(0, 0) >= $today,
            ];
        }
        return $chips;
    }

    /**
     * Extract cinema/digital/physical dates from TMDb's release_dates append,
     * preferring FR then US then the first country that has each type. TMDb
     * type codes: 2/3 = theatrical, 4 = digital, 5 = physical.
     * @return list<array{kind:string,label:string,date:\DateTimeImmutable,upcoming:bool}>
     */
    private function tmdbMovieReleaseDates(array $results, \DateTimeImmutable $today): array
    {
        $byCountry = [];
        foreach ($results as $r) {
            $cc = $r['iso_3166_1'] ?? '';
            foreach ($r['release_dates'] ?? [] as $rd) {
                $byCountry[$cc][(int) ($rd['type'] ?? 0)] = $rd['release_date'] ?? null;
            }
        }
        // Locale-led country priority (was hardcoded FR→US), then any other
        // countries the payload contains, so an English user gets US/GB dates.
        $order = TmdbClient::regionPriority($this->translator->getLocale(), array_keys($byCountry));
        $pick = function (array $types) use ($byCountry, $order): ?\DateTimeImmutable {
            foreach ($order as $cc) {
                foreach ($types as $t) {
                    $raw = $byCountry[$cc][$t] ?? null;
                    if ($raw) return new \DateTimeImmutable($raw);
                }
            }
            return null;
        };
        return $this->movieReleaseChips($pick([3, 2]), $pick([4]), $pick([5]), $today);
    }

    /**
     * Air-date chips for a series quick-look: first-aired (if known), then
     * either the next upcoming episode (continuing) or the last-aired date
     * (ended). @return list<array{kind:string,label:string,date:\DateTimeImmutable,upcoming:bool}>
     */
    private function seriesReleaseChips(?\DateTimeImmutable $firstAired, ?\DateTimeImmutable $nextEpisode, ?\DateTimeImmutable $lastEpisode, bool $ended, \DateTimeImmutable $today): array
    {
        $chip = fn(string $kind, string $key, \DateTimeImmutable $d): array => [
            'kind' => $kind, 'label' => $this->translator->trans($key),
            'date' => $d, 'upcoming' => $d->setTime(0, 0) >= $today,
        ];
        $chips = [];
        if ($firstAired) $chips[] = $chip('first_aired', 'dashboard.quicklook.date.first_aired', $firstAired);
        if (!$ended && $nextEpisode) {
            $chips[] = $chip('next_episode', 'dashboard.quicklook.date.next_episode', $nextEpisode);
        } elseif ($ended && $lastEpisode) {
            $chips[] = $chip('ended', 'dashboard.quicklook.date.ended', $lastEpisode);
        }
        return $chips;
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        return ($raw !== null && $raw !== '') ? new \DateTimeImmutable($raw) : null;
    }

    /**
     * Return the earliest release date that is today or later for a Radarr
     * movie, together with a human-readable badge identifying which date
     * it is (digital / cinema / physical). The comparison is done at
     * calendar-day granularity so a digital release set to 02:00 today
     * still counts as "today" even at 14:00.
     * Null if every date is strictly in the past or missing.
     *
     * @param array<string, mixed> $movie
     * @return array{at: \DateTimeImmutable, badge: string}|null
     */
    private function pickNextReleaseDate(array $movie, \DateTimeImmutable $today): ?array
    {
        $candidates = array_filter([
            ['at' => $movie['digitalAt']   ?? null, 'badge' => $this->translator->trans('dashboard.release_badge.digital')],
            ['at' => $movie['inCinemasAt'] ?? null, 'badge' => $this->translator->trans('dashboard.release_badge.cinema')],
            ['at' => $movie['physicalAt']  ?? null, 'badge' => $this->translator->trans('dashboard.release_badge.bluray')],
        ], fn($c) => $c['at'] instanceof \DateTimeImmutable && $c['at']->setTime(0, 0) >= $today);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn($a, $b) => $a['at'] <=> $b['at']);

        return $candidates[0];
    }

    /**
     * Jellyseerr's `/request` API returns `media.tmdbId` but no title or
     * poster. We enrich each entry via TmdbClient — detail lookups are
     * cached 1h by the client so the overhead is amortized across paints.
     * Capped at MAX_REQUESTS (5) so a spike in pending requests can't
     * balloon the paint cost.
     *
     * @return list<array{id: int, type: string, tmdbId: int, title: string, poster: ?string, requestedBy: string, requestedAt: ?string}>
     */
    private function pendingRequests(): array
    {
        $data = $this->safeFetch(
            'jellyseerr.requests',
            fn() => $this->jellyseerr->getRequests(self::MAX_REQUESTS, 0, 'pending'),
        ) ?? [];

        $results = $data['results'] ?? $data;
        $out = [];

        foreach ($results as $req) {
            $tmdbId    = $req['media']['tmdbId'] ?? null;
            $mediaType = $req['media']['mediaType'] ?? $req['type'] ?? 'movie';
            if (!$tmdbId) {
                continue;
            }

            $details = $this->safeFetch(
                "jellyseerr.tmdb.{$mediaType}.{$tmdbId}",
                fn() => $mediaType === 'tv'
                    ? $this->tmdb->getTv((int) $tmdbId)
                    : $this->tmdb->getMovie((int) $tmdbId),
            ) ?? [];

            $out[] = [
                'id'          => (int) ($req['id'] ?? 0),
                'type'        => $mediaType,
                'tmdbId'      => (int) $tmdbId,
                'title'       => $details['title'] ?? $details['name'] ?? ('TMDb #' . $tmdbId),
                'poster'      => $details['poster_path'] ?? null,
                'requestedBy' => $req['requestedBy']['displayName'] ?? $req['requestedBy']['email'] ?? '—',
                'requestedAt' => $req['createdAt'] ?? null,
            ];
        }

        return $out;
    }


    private function recommendations(): array
    {
        $payload = $this->safeFetch(
            'tmdb.trending',
            fn() => $this->tmdb->getTrendingAll('week'),
        ) ?? [];

        // TMDb wraps its lists in {page, results: [...], total_pages, ...}.
        // Slicing the wrapper directly would yield scalar metadata entries.
        $items = $payload['results'] ?? [];

        return array_slice($items, 0, self::MAX_RECOMMENDATIONS);
    }

    /**
     * Merged list of the most recently added Radarr movies + Sonarr series,
     * sorted by `addedAt` desc. Used for the single "Recent additions" row at
     * the bottom of the dashboard.
     */
    private function recentAdditions(): array
    {
        $movies = $this->movies();
        $series = $this->series();

        $epoch = new \DateTimeImmutable('1970-01-01');
        $items = [];

        $now = new \DateTimeImmutable();
        foreach ($movies as $m) {
            $downloaded = ($m['hasFile'] ?? false) === true;
            // _instanceSlug is set by movies() per row — link points at the
            // exact instance the movie lives in (Radarr default OR 4K OR
            // anime) so clicking the tile lands the user on the right page.
            $slug = $m['_instanceSlug'] ?? $this->defaultSlug(ServiceInstance::TYPE_RADARR);
            $items[] = [
                'type'         => 'movie',
                'id'           => $m['id'] ?? null,
                'title'        => $m['title'] ?? '—',
                'subtitle'     => $this->relativeDate($m['addedAt'] ?? null, $now),
                'poster'       => $m['poster'] ?? null,
                'badge'        => $downloaded ? $this->translator->trans('dashboard.lib_badge.downloaded') : null,
                'is_downloaded'=> $downloaded,
                'addedAt'      => $m['addedAt'] ?? null,
                'href'         => $this->generateUrl('app_media_films', ['slug' => $slug]) . '?open=' . ($m['id'] ?? ''),
                'slug'         => $slug,
            ];
        }
        foreach ($series as $s) {
            $slug = $s['_instanceSlug'] ?? $this->defaultSlug(ServiceInstance::TYPE_SONARR);
            $items[] = [
                'type'     => 'series',
                'id'       => $s['id'] ?? null,
                'title'    => $s['title'] ?? '—',
                'subtitle' => $this->relativeDate($s['addedAt'] ?? null, $now),
                'poster'   => $s['poster'] ?? null,
                'badge'    => $s['network'] ?? null,
                'addedAt'  => $s['addedAt'] ?? null,
                'href'     => $this->generateUrl('app_media_series', ['slug' => $slug]) . '?open=' . ($s['id'] ?? ''),
                'slug'     => $slug,
            ];
        }

        usort($items, fn($a, $b) => ($b['addedAt'] ?? $epoch) <=> ($a['addedAt'] ?? $epoch));

        return array_slice($items, 0, self::MAX_RECENT);
    }

    /**
     * Find a single library row for the quick-look. Prefers the already-cached
     * movies()/series() aggregate (zero upstream calls on a warm dashboard);
     * falls back to a direct client fetch for the given instance on a miss.
     *
     * @return array<string, mixed>|null
     */
    private function findLibraryRow(string $type, string $slug, int $id): ?array
    {
        $rows = $type === 'series' ? $this->series() : $this->movies();
        $match = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) !== $id) {
                continue;
            }
            // Prefer the row from the requested instance; otherwise keep the first hit.
            if (($row['_instanceSlug'] ?? null) === $slug) {
                return $row;
            }
            $match ??= $row;
        }
        if ($match !== null) {
            return $match;
        }

        // Cache miss / not loaded — fetch directly from the requested instance.
        $svcType = $type === 'series' ? ServiceInstance::TYPE_SONARR : ServiceInstance::TYPE_RADARR;
        $inst = $this->instances->getBySlug($svcType, $slug);
        if ($inst === null) {
            return null;
        }
        return $this->safeFetch(
            "quicklook.{$type}.{$slug}.{$id}",
            fn() => $type === 'series'
                ? $this->sonarr->withInstance($inst)->getSerie($id)
                : $this->radarr->withInstance($inst)->getMovie($id),
        );
    }

    /**
     * Build the read-only quick-look view-model for a Radarr/Sonarr library item.
     * Returns null when the item can't be resolved (caller renders a graceful body).
     *
     * @return array<string, mixed>|null
     */
    private function quickLookLibrary(string $type, string $slug, int $id): ?array
    {
        $row = $this->findLibraryRow($type, $slug, $id);
        if ($row === null) {
            return null;
        }

        $hasFile   = ($row['hasFile'] ?? false) === true;
        $monitored = ($row['monitored'] ?? false) === true;
        $badgeKind = $hasFile ? 'downloaded' : ($monitored ? 'monitored' : 'missing');
        $badgeKey  = 'dashboard.quicklook.status.' . $badgeKind;

        $ended     = ($row['ended'] ?? false) === true || ($row['status'] ?? '') === 'ended';
        $airStatus = $type === 'series' ? ($ended ? 'ended' : 'continuing') : null;

        if ($type === 'series') {
            $metaLine  = $row['network'] ?? null;
            $actionUrl = $this->generateUrl('app_media_series', ['slug' => $slug]) . '?open=' . $id;
        } else {
            $runtime   = $row['runtime'] ?? null;
            $metaLine  = $runtime ? $this->translator->trans('dashboard.quicklook.runtime', ['min' => $runtime]) : null;
            $actionUrl = $this->generateUrl('app_media_films', ['slug' => $slug]) . '?open=' . $id;
        }

        return [
            'title'        => $row['title'] ?? '—',
            'year'         => $row['year'] ?? null,
            'poster'       => $row['poster'] ?? null,
            'backdrop'     => $row['fanart'] ?? null,
            'overview'     => $row['overview'] ?? null,
            'genres'       => array_slice($row['genres'] ?? [], 0, 4),
            'rating'       => $row['ratings'] ?? null,
            'metaLine'     => $metaLine,
            'statusBadge'  => ['label' => $this->translator->trans($badgeKey), 'kind' => $badgeKind],
            'actionUrl'    => $actionUrl,
            'actionLabel'  => $this->translator->trans('dashboard.quicklook.manage'),
            'inLibrary'    => true,
            'airStatus'    => $airStatus,
            'releaseDates' => $type === 'series'
                ? $this->seriesReleaseChips(
                    $row['firstAired'] ?? null,
                    $row['nextAiring'] ?? null,
                    $row['previousAiring'] ?? null,
                    $ended,
                    new \DateTimeImmutable('today'),
                )
                : $this->movieReleaseChips(
                    $row['inCinemasAt'] ?? null,
                    $row['digitalAt'] ?? null,
                    $row['physicalAt'] ?? null,
                    new \DateTimeImmutable('today'),
                ),
        ];
    }

    private function tmdbImage(?string $path, string $size): ?string
    {
        return $path ? 'https://image.tmdb.org/t/p/' . $size . $path : null;
    }

    /**
     * Build the read-only quick-look view-model for a TMDb item (trending /
     * watchlist). Returns null when TMDb has no record (graceful body upstream).
     *
     * @return array<string, mixed>|null
     */
    private function quickLookTmdb(string $type, int $id): ?array
    {
        $isTv = $type === 'tv';
        $data = $this->safeFetch(
            "quicklook.tmdb.{$type}.{$id}",
            fn() => $isTv ? $this->tmdb->getTv($id) : $this->tmdb->getMovie($id),
        );
        if (!$data) {
            return null;
        }

        $date = $isTv ? ($data['first_air_date'] ?? '') : ($data['release_date'] ?? '');
        $year = $date !== '' ? (int) substr((string) $date, 0, 4) : null;

        if ($isTv) {
            $network  = $data['networks'][0]['name'] ?? null;
            $seasons  = $data['number_of_seasons'] ?? null;
            $parts    = array_filter([
                $network,
                $seasons !== null
                    ? $this->translator->trans('dashboard.quicklook.seasons', ['count' => $seasons])
                    : null,
            ]);
            $metaLine = $parts === [] ? null : implode(' · ', $parts);
        } else {
            $runtime  = $data['runtime'] ?? null;
            $metaLine = $runtime ? $this->translator->trans('dashboard.quicklook.runtime', ['min' => $runtime]) : null;
        }

        $ended     = $isTv && in_array($data['status'] ?? '', ['Ended', 'Canceled'], true);
        $airStatus = $isTv ? ($ended ? 'ended' : 'continuing') : null;

        $extras = $this->quickLookExtras($data);

        // Unify the action model with the old Explorer modal: a title already
        // in a Radarr/Sonarr library deep-links to Manage (exact instance);
        // otherwise the body renders an Add affordance. Reuses the cached
        // library aggregates — no extra upstream calls.
        $match = $this->quickLookLibraryMatch($type, $id);
        if ($match !== null) {
            $statusBadge = [
                'label' => $this->translator->trans('dashboard.quicklook.status.' . $match['status']),
                'kind'  => $match['status'],
            ];
            $actionUrl   = $isTv
                ? $this->generateUrl('app_media_series', ['slug' => $match['slug']]) . '?open=' . $match['id']
                : $this->generateUrl('app_media_films', ['slug' => $match['slug']]) . '?open=' . $match['id'];
            $actionLabel = $this->translator->trans('dashboard.quicklook.manage');
        } else {
            $statusBadge = null;
            $actionUrl   = $this->generateUrl('tmdb_index') . '?detail=' . $type . '/' . $id;
            $actionLabel = $this->translator->trans('dashboard.quicklook.discover');
        }

        return [
            'title'        => $data['title'] ?? $data['name'] ?? '—',
            'year'         => $year,
            'poster'       => $this->tmdbImage($data['poster_path'] ?? null, 'w342'),
            'posterPath'   => $data['poster_path'] ?? null,
            'backdrop'     => $this->tmdbImage($data['backdrop_path'] ?? null, 'w1280'),
            'overview'     => $data['overview'] ?? null,
            'genres'       => array_slice(array_map(fn($g) => $g['name'] ?? '', $data['genres'] ?? []), 0, 4),
            'rating'       => $data['vote_average'] ?? null,
            'metaLine'     => $metaLine,
            'statusBadge'  => $statusBadge,
            'actionUrl'    => $actionUrl,
            'actionLabel'  => $actionLabel,
            'inLibrary'    => $match !== null,
            'airStatus'    => $airStatus,
            'cast'         => $extras['cast'],
            'providers'    => $extras['providers'],
            'trailerKey'   => $extras['trailerKey'],
            'imdbId'       => $extras['imdbId'],
            'tmdbId'       => $id,
            'tmdbType'     => $type,
            'releaseDates' => $isTv
                ? $this->seriesReleaseChips(
                    $this->parseDate($data['first_air_date'] ?? null),
                    $this->parseDate($data['next_episode_to_air']['air_date'] ?? null),
                    $this->parseDate($data['last_episode_to_air']['air_date'] ?? null),
                    $ended,
                    new \DateTimeImmutable('today'),
                )
                : $this->tmdbMovieReleaseDates(
                    $data['release_dates']['results'] ?? [],
                    new \DateTimeImmutable('today'),
                ),
        ];
    }

    /**
     * Locate a TMDb id within the aggregated Radarr/Sonarr libraries so the
     * quick-look can deep-link to Manage (and badge the status) for titles
     * already added, and show an Add affordance otherwise. Reuses the
     * per-request cached movies()/series() aggregates — no extra upstream
     * calls. $type is the TMDb media type ('movie'|'tv').
     *
     * @return array{slug: string, id: int, status: string}|null
     */
    private function quickLookLibraryMatch(string $type, int $tmdbId): ?array
    {
        // Fail open: if the library can't be read (service down / not
        // configured), treat the title as not-in-library so the modal still
        // offers Add rather than erroring — same philosophy as the widgets.
        try {
            $rows = $type === 'movie' ? $this->movies() : $this->series();
        } catch (\Throwable $e) {
            return null;
        }

        if ($type === 'movie') {
            foreach ($rows as $m) {
                if ((int) ($m['tmdbId'] ?? 0) !== $tmdbId) {
                    continue;
                }
                $slug = $m['_instanceSlug'] ?? null;
                if ($slug === null) {
                    continue;
                }
                $status = ($m['hasFile'] ?? false) === true
                    ? 'downloaded'
                    : (($m['monitored'] ?? false) === true ? 'monitored' : 'missing');
                return ['slug' => $slug, 'id' => (int) ($m['id'] ?? 0), 'status' => $status];
            }
            return null;
        }

        foreach ($rows as $s) {
            if ((int) ($s['tmdbId'] ?? 0) !== $tmdbId) {
                continue;
            }
            $slug = $s['_instanceSlug'] ?? null;
            if ($slug === null) {
                continue;
            }
            $status = ($s['monitored'] ?? false) === true ? 'monitored' : 'missing';
            return ['slug' => $slug, 'id' => (int) ($s['id'] ?? 0), 'status' => $status];
        }
        return null;
    }

    /**
     * Extract the richer detail bits (cast, streaming providers, trailer, IMDb
     * id) from a TMDb detail payload. TmdbClient::getMovie/getTv already pull
     * credits/videos/watch-providers/external_ids via append_to_response, so
     * this is pure extraction — no extra API call.
     *
     * @param array<string, mixed> $data
     * @return array{cast: list<array{name: string, profile: ?string}>, providers: list<array{name: string, logo: ?string}>, trailerKey: ?string, imdbId: ?string}
     */
    private function quickLookExtras(array $data): array
    {
        $cast = [];
        foreach (array_slice($data['credits']['cast'] ?? [], 0, 6) as $c) {
            $cast[] = [
                'name'    => $c['name'] ?? '',
                'profile' => TmdbClient::posterUrl($c['profile_path'] ?? null, 'w185'),
            ];
        }

        // Streaming (flatrate) providers, locale-led then common fallbacks —
        // mirrors TmdbController::pickProviders' country priority.
        $providers = [];
        foreach (TmdbClient::regionPriority($this->translator->getLocale()) as $cc) {
            $flat = $data['watch/providers']['results'][$cc]['flatrate'] ?? [];
            if ($flat === []) {
                continue;
            }
            foreach ($flat as $p) {
                $providers[] = [
                    'name' => $p['provider_name'] ?? '',
                    'logo' => TmdbClient::posterUrl($p['logo_path'] ?? null, 'w92'),
                ];
            }
            break;
        }

        // Best YouTube trailer/teaser — official + EN/FR preferred, mirrors
        // TmdbController::pickTrailer's scoring (trimmed for the modal).
        $trailerKey = null;
        $videos = array_filter(
            $data['videos']['results'] ?? [],
            static fn($v) => ($v['site'] ?? '') === 'YouTube',
        );
        $score = static function (array $v): int {
            $s = 0;
            if (($v['type'] ?? '') === 'Trailer') {
                $s += 100;
            } elseif (($v['type'] ?? '') === 'Teaser') {
                $s += 50;
            }
            if (($v['official'] ?? false) === true) {
                $s += 40;
            }
            $lang = strtolower($v['iso_639_1'] ?? '');
            if ($lang === 'en') {
                $s += 20;
            } elseif ($lang === 'fr') {
                $s += 15;
            }
            return $s;
        };
        usort($videos, static fn($a, $b) => $score($b) <=> $score($a));
        $first = reset($videos);
        if ($first) {
            $trailerKey = $first['key'] ?? null;
        }

        $imdbId = $data['imdb_id'] ?? ($data['external_ids']['imdb_id'] ?? null);

        return [
            'cast'       => $cast,
            'providers'  => $providers,
            'trailerKey' => $trailerKey,
            'imdbId'     => $imdbId,
        ];
    }

    /**
     * Personal watchlist — up to MAX_WATCHLIST most recently starred items.
     * Read straight from the local DB so it's always fast even when every
     * remote service is down. Each item carries tmdbId + mediaType so the
     * dashboard tile can deep-link into the Discover modal.
     */
    private function watchlist(): array
    {
        try {
            $items = $this->watchlistRepo->findAllOrdered();
        } catch (\Throwable $e) {
            $this->logger->warning('Dashboard watchlist failed: {msg}', ['msg' => $e->getMessage()]);
            return [];
        }

        return array_slice(array_map(fn($w) => [
            'tmdbId'    => $w->getTmdbId(),
            'mediaType' => $w->getMediaType(),
            'title'     => $w->getTitle(),
            'poster'    => $w->getPosterPath(),
            'year'      => $w->getYear(),
            'vote'      => $w->getVote(),
        ], $items), 0, self::MAX_WATCHLIST);
    }

    /**
     * Pick a "spotlight" movie for the hero banner. Priority:
     *   1. A random movie from the local Radarr library with a fanart —
     *      "Envie de le revoir ?" vibe, feels personal because it's
     *      already in the user's collection.
     *   2. Otherwise fall back to a TMDb trending item (first result with
     *      a backdrop_path).
     *   3. Null if neither source yields anything → flat gradient hero.
     *
     * @param list<array<string, mixed>> $recommendations
     */
    private function pickHeroSpotlight(array $recommendations): ?array
    {
        $withFanart = array_values(array_filter($this->movies(), fn($m) => !empty($m['fanart']) && !empty($m['title'])));

        if ($withFanart !== []) {
            $m = $withFanart[array_rand($withFanart)];
            // Pick the slug of the instance the spotlight movie actually lives
            // in (multi-instance) so the CTA opens the right page. _instanceSlug
            // is injected by movies(); fall back on default for safety.
            $slug = $m['_instanceSlug'] ?? $this->defaultSlug(ServiceInstance::TYPE_RADARR);
            return [
                'source'    => 'library',
                'url'       => $m['fanart'],
                'title'     => $m['title'],
                'overview'  => $this->truncate($m['overview'] ?? null, 220),
                'year'      => $m['year'] ?? null,
                'runtime'   => $m['runtime'] ?? null,
                'quality'   => $m['quality'] ?? null,
                'rating'    => $m['ratings'] ?? null,
                'genres'    => array_slice($m['genres'] ?? [], 0, 3),
                'badge'     => $m['hasFile']
                    ? $this->translator->trans('dashboard.hero_badge.in_library')
                    : $this->translator->trans('dashboard.hero_badge.monitored'),
                'cta'       => $this->translator->trans('dashboard.hero_badge.cta_view'),
                'detailUrl' => $m['id'] ? $this->generateUrl('app_media_films', ['slug' => $slug]) . '?open=' . $m['id'] : null,
                'qlSource'  => 'library',
                'qlType'    => 'movie',
                'qlSlug'    => $slug,
                'qlId'      => $m['id'] ?? null,
            ];
        }

        foreach ($recommendations as $item) {
            if (!empty($item['backdrop_path'])) {
                $type = ($item['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
                $year = !empty($item['release_date']) ? (int) substr($item['release_date'], 0, 4)
                      : (!empty($item['first_air_date']) ? (int) substr($item['first_air_date'], 0, 4) : null);
                return [
                    'source'    => 'tmdb',
                    'url'       => 'https://image.tmdb.org/t/p/w1280' . $item['backdrop_path'],
                    'title'     => $item['title'] ?? $item['name'] ?? null,
                    'overview'  => $this->truncate($item['overview'] ?? null, 220),
                    'year'      => $year,
                    'runtime'   => null,
                    'quality'   => null,
                    'rating'    => $item['vote_average'] ?? null,
                    'genres'    => [],
                    'badge'     => $this->translator->trans('dashboard.hero_badge.trending'),
                    'cta'       => $this->translator->trans('dashboard.hero_badge.cta_discover'),
                    'detailUrl' => $item['id'] ? $this->generateUrl('tmdb_index') . '?detail=' . $type . '/' . $item['id'] : null,
                    'qlSource'  => 'tmdb',
                    'qlType'    => $type,
                    'qlSlug'    => null,
                    'qlId'      => $item['id'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Friendly "Aujourd'hui / Hier / il y a 3 j / il y a 2 sem." label
     * for a past DateTimeImmutable, or null if date is missing.
     */
    private function relativeDate(?\DateTimeImmutable $at, \DateTimeImmutable $now): ?string
    {
        if ($at === null) {
            return null;
        }

        $days = (int) $now->diff($at)->days;
        if ($days === 0) return $this->translator->trans('dashboard.relative.today');
        if ($days === 1) return $this->translator->trans('dashboard.relative.yesterday');
        if ($days < 7)   return $this->translator->trans('dashboard.relative.days_ago',   ['count' => $days]);
        if ($days < 30)  return $this->translator->trans('dashboard.relative.weeks_ago',  ['count' => (int) round($days / 7)]);
        if ($days < 365) return $this->translator->trans('dashboard.relative.months_ago', ['count' => (int) round($days / 30)]);
        return $this->translator->trans('dashboard.relative.years_ago', ['count' => (int) round($days / 365)]);
    }

    private function truncate(?string $s, int $max): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }

    /**
     * Execute a fetch that may hit a remote service and return `null` on any
     * failure (with a warning logged). Keeps the dashboard resilient: a dead
     * Radarr doesn't mean a blank page, just an empty widget.
     *
     * "Service not configured" is a deliberate user state (issue #9 — they
     * never enabled Jellyseerr / VPN / etc.), so we skip it silently rather
     * than spamming a warning every dashboard render.
     */
    private function safeFetch(string $label, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\App\Exception\ServiceNotConfiguredException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Dashboard widget failed [{label}]: {message}', [
                'label'     => $label,
                'message'   => $e->getMessage(),
                'exception' => $e::class,
            ]);
            return null;
        }
    }
}
