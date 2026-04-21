<?php

namespace App\Controller;

use App\Repository\Media\WatchlistItemRepository;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard landing page — aggregates the most relevant recent signals from
 * every configured service into a single screen. Every widget fails open:
 * if a client throws (service down, misconfigured, HTTP timeout) we log a
 * warning and render the widget in its empty state rather than crash the
 * whole page. Session 9c will wire the UI preferences (timezone, date
 * format, density…) into this template.
 */
class DashboardController extends AbstractController
{
    private const UPCOMING_DAYS       = 7;
    private const MAX_REQUESTS        = 5;
    private const MAX_UPCOMING        = 8;
    private const MAX_RECOMMENDATIONS = 16;
    private const MAX_RECENT          = 16;
    private const MAX_WATCHLIST       = 16;

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
        private readonly LoggerInterface $logger,
    ) {}

    private function movies(): array
    {
        return $this->moviesCache ??= $this->safeFetch('library.movies', fn() => $this->radarr->getMovies()) ?? [];
    }

    private function series(): array
    {
        return $this->seriesCache ??= $this->safeFetch('library.series', fn() => $this->sonarr->getSeries()) ?? [];
    }

    #[Route('/tableau-de-bord', name: 'app_dashboard')]
    public function index(): Response
    {
        // The dashboard aggregates 8+ remote service calls sequentially. A
        // single slow service (qBit + Radarr + Sonarr + TMDb + …) can push
        // the total past the default 30s max_execution_time and trigger a
        // FatalError. 60s covers worst-case homelab latency without masking
        // a real runaway. Session 9c / v1.1+ will move to async widget
        // hydration to bring the initial paint back under 1s.
        set_time_limit(60);

        $recommendations = $this->recommendations();
        $upcoming        = $this->upcomingReleases();

        return $this->render('dashboard/index.html.twig', [
            'stats'               => $this->stats(),
            'upcoming'            => $upcoming,
            'upcoming_days'       => $this->upcomingByDay($upcoming),
            'jellyseerr_requests' => $this->pendingRequests(),
            'services_health'     => $this->servicesHealth(),
            'recommendations'     => $recommendations,
            'recent_additions'    => $this->recentAdditions(),
            'watchlist'           => $this->watchlist(),
            'hero_spotlight'      => $this->pickHeroSpotlight($recommendations),
        ]);
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
        $now      = new \DateTimeImmutable();
        $movies   = $this->safeFetch('upcoming.radarr', fn() => $this->radarr->getCalendar(self::UPCOMING_DAYS, 0)) ?? [];
        $episodes = $this->safeFetch('upcoming.sonarr', fn() => $this->sonarr->getCalendar(self::UPCOMING_DAYS, 0)) ?? [];

        $items = [];
        foreach ($movies as $m) {
            $next = $this->pickNextReleaseDate($m, $now);
            if ($next === null) {
                continue;
            }

            $items[] = [
                'type'     => 'movie',
                'title'    => $m['title'] ?? '—',
                'subtitle' => $m['year'] ? ((string) $m['year']) : null,
                'badge'    => $next['badge'],
                'poster'   => $m['poster'] ?? null,
                'date'     => $next['at'],
            ];
        }
        foreach ($episodes as $e) {
            $airDate = $e['airDate'] ?? null;
            if (!$airDate instanceof \DateTimeImmutable || $airDate < $now) {
                continue;
            }

            $sxe = sprintf('S%02dE%02d', $e['season'] ?? 0, $e['episode'] ?? 0);
            $items[] = [
                'type'     => 'episode',
                'title'    => $e['seriesTitle'] ?? '—',
                'subtitle' => $sxe . ($e['title'] && $e['title'] !== '—' ? ' — ' . $e['title'] : ''),
                'badge'    => $e['network'] ?? null,
                'poster'   => $e['poster'] ?? null,
                'date'     => $airDate,
            ];
        }

        usort($items, fn($a, $b) => $a['date'] <=> $b['date']);

        return array_slice($items, 0, self::MAX_UPCOMING);
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
                'dow'     => mb_strtoupper(mb_substr($this->frenchDayName($date), 0, 3)),
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

    private function frenchDayName(\DateTimeImmutable $d): string
    {
        return ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'][(int) $d->format('N') - 1];
    }

    /**
     * Return the earliest release date strictly in the future for a Radarr
     * movie, together with a human-readable badge identifying which date
     * it is (digital / cinema / physical). Null if every date is stale or
     * missing — the movie is then dropped from the upcoming widget.
     *
     * @param array<string, mixed> $movie
     * @return array{at: \DateTimeImmutable, badge: string}|null
     */
    private function pickNextReleaseDate(array $movie, \DateTimeImmutable $now): ?array
    {
        $candidates = array_filter([
            ['at' => $movie['digitalAt']   ?? null, 'badge' => 'Numérique'],
            ['at' => $movie['inCinemasAt'] ?? null, 'badge' => 'Cinéma'],
            ['at' => $movie['physicalAt']  ?? null, 'badge' => 'Blu-ray'],
        ], fn($c) => $c['at'] instanceof \DateTimeImmutable && $c['at'] >= $now);

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

    /**
     * @return array<string, bool|null>  service id => healthy?  (null = not configured)
     */
    private function servicesHealth(): array
    {
        $services = ['radarr', 'sonarr', 'prowlarr', 'jellyseerr', 'qbittorrent', 'tmdb'];
        $out = [];
        foreach ($services as $s) {
            try {
                $out[$s] = $this->health->isHealthy($s);
            } catch (\Throwable) {
                $out[$s] = null;
            }
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
     * sorted by `addedAt` desc. Used for the single "Ajouts récents" row at
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
            $items[] = [
                'type'     => 'movie',
                'title'    => $m['title'] ?? '—',
                'subtitle' => $this->relativeDate($m['addedAt'] ?? null, $now),
                'poster'   => $m['poster'] ?? null,
                'badge'    => ($m['hasFile'] ?? false) ? 'Téléchargé' : null,
                'addedAt'  => $m['addedAt'] ?? null,
                'href'     => $this->generateUrl('app_media_films') . '?open=' . ($m['id'] ?? ''),
            ];
        }
        foreach ($series as $s) {
            $items[] = [
                'type'     => 'series',
                'title'    => $s['title'] ?? '—',
                'subtitle' => $this->relativeDate($s['addedAt'] ?? null, $now),
                'poster'   => $s['poster'] ?? null,
                'badge'    => $s['network'] ?? null,
                'addedAt'  => $s['addedAt'] ?? null,
                'href'     => $this->generateUrl('app_media_series') . '?open=' . ($s['id'] ?? ''),
            ];
        }

        usort($items, fn($a, $b) => ($b['addedAt'] ?? $epoch) <=> ($a['addedAt'] ?? $epoch));

        return array_slice($items, 0, self::MAX_RECENT);
    }

    /**
     * Personal watchlist — up to MAX_WATCHLIST most recently starred items.
     * Read straight from the local DB so it's always fast even when every
     * remote service is down. Each item carries tmdbId + mediaType so the
     * dashboard tile can deep-link into the Découverte modal.
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
                'badge'     => $m['hasFile'] ? 'Dans votre bibliothèque' : 'Suivi · à télécharger',
                'cta'       => '▶ Voir la fiche',
                'detailUrl' => $m['id'] ? $this->generateUrl('app_media_films') . '?open=' . $m['id'] : null,
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
                    'badge'     => '★ Tendance de la semaine',
                    'cta'       => '▶ Découvrir',
                    'detailUrl' => $item['id'] ? $this->generateUrl('tmdb_index') . '?detail=' . $type . '/' . $item['id'] : null,
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
        if ($days === 0)     return "Aujourd'hui";
        if ($days === 1)     return 'Hier';
        if ($days < 7)       return "il y a {$days} j";
        if ($days < 30)      return 'il y a ' . (int) round($days / 7) . ' sem.';
        if ($days < 365)     return 'il y a ' . (int) round($days / 30) . ' mois';
        return 'il y a ' . (int) round($days / 365) . ' an' . ($days >= 730 ? 's' : '');
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
     */
    private function safeFetch(string $label, callable $fn): mixed
    {
        try {
            return $fn();
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
