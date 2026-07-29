<?php

namespace App\Controller;

use App\Service\Media\TautulliClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only internal API for the "Current Plex activity" dashboard widget,
 * backed by the Tautulli API integration (TautulliClient::getActivity()).
 *
 * Every Tautulli call is made server-side: the API key never reaches the
 * browser, and the JSON returned here is the already-sanitized, normalized
 * shape (no IPs, tokens, machine ids, file paths or raw payload). The endpoint
 * always answers 200 with an `error` code in the body for the disabled /
 * unconfigured / unreachable / auth cases, so the widget never breaks the page.
 *
 * The page also exposes read-only `get_metadata`, `get_history`,
 * `get_home_stats`, `get_plays_by_date`, `get_libraries`,
 * `get_plays_by_stream_type`, `get_plays_by_hourofday`,
 * `get_plays_by_dayofweek`, and `get_stream_type_by_top_10_platforms` —
 * no terminate_session, notifications or any other mutating Tautulli command.
 */
#[IsGranted('ROLE_USER')]
#[Route('/tautulli', name: 'app_tautulli_')]
class TautulliController extends AbstractController
{
    public function __construct(
        private readonly TautulliClient $tautulli,
    ) {}

    /** Request metric, clamped to the two Tautulli accepts. */
    private static function metric(Request $request): string
    {
        return $request->query->get('metric') === 'duration' ? 'duration' : 'plays';
    }

    /** Opaque Tautulli user_id filter token: digits only, else "" (all users). */
    private static function userId(Request $request): string
    {
        $u = (string) $request->query->get('user', '');
        return ctype_digit($u) ? $u : '';
    }

    /**
     * GET /tautulli/api/activity — sanitized current Plex activity as JSON.
     * Mirrors the qBittorrent/Jellyseerr poll-endpoint convention
     * (/{service}/api/...). Fails open: a thrown client returns the neutral
     * shape with error:"unreachable" rather than a 500 + stack trace.
     */
    #[Route('/api/activity', name: 'api_activity', methods: ['GET'])]
    public function apiActivity(): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getActivity());
        } catch (\Throwable) {
            // Defensive: getActivity() already fails open, but never let an
            // unexpected throwable leak a message/secret to the browser.
            return $this->json([
                'enabled'    => true,
                'configured' => true,
                'connected'  => false,
                'error'      => 'unreachable',
                'streamCount'       => 0,
                'directPlayCount'   => 0,
                'directStreamCount' => 0,
                'transcodeCount'    => 0,
                'bandwidth'         => [
                    'totalKbps' => 0, 'lanKbps' => 0, 'wanKbps' => 0,
                    'totalMbps' => 0.0, 'lanMbps' => 0.0, 'wanMbps' => 0.0,
                ],
                'sessions'          => [],
            ]);
        }
    }

    /**
     * GET /tautulli/api/image?img=/library/metadata/123/thumb/456 — streams a
     * Plex poster fetched server-side via Tautulli's pms_image_proxy. The API
     * key stays on the server; the browser only ever sees the image bytes.
     *
     * The `img` value is allow-listed to Plex library image paths inside
     * TautulliClient::fetchImage (SSRF / open-relay guard). A miss returns 404
     * so the widget's CSS placeholder shows through rather than a broken image.
     * The response is privately cacheable for a day — the poster URL is stable
     * per session, so the browser reuses it across the widget's 10s refreshes.
     */
    #[Route('/api/image', name: 'api_image', methods: ['GET'])]
    public function apiImage(Request $request): Response
    {
        $img = (string) $request->query->get('img', '');
        $image = $img !== '' ? $this->tautulli->fetchImage($img) : null;
        if ($image === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($image['body']);
        $response->headers->set('Content-Type', $image['contentType']);
        $response->setMaxAge(86400);
        $response->setPrivate();

        return $response;
    }

    /**
     * GET /tautulli/api/metadata/{ratingKey} — renders the info-modal body for a
     * Plex item. ratingKey is digits-only (route requirement). Optional
     * player/device query params are display-only (live now-playing line) and
     * are Twig-escaped. Fails open: the template renders a clean error state.
     */
    #[Route('/api/metadata/{ratingKey}', name: 'api_metadata', methods: ['GET'], requirements: ['ratingKey' => '\d+'])]
    public function apiMetadata(string $ratingKey, Request $request): Response
    {
        try {
            $data = $this->tautulli->getMetadata($ratingKey);
        } catch (\Throwable) {
            $data = ['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'metadata' => []];
        }

        return $this->render('dashboard/_plex_metadata.html.twig', [
            'plex'   => $data,
            'player' => trim((string) $request->query->get('player', '')),
            'device' => trim((string) $request->query->get('device', '')),
        ]);
    }

    /**
     * GET /tautulli/api/quicklook/{ratingKey} — resolves a Plex rating key to
     * the TMDb {type, id} the app-global quick-look modal opens with. Returns
     * nulls (not an error status) when the item has no tmdb guid — the client
     * then falls back to the legacy Plex metadata modal, so music and home
     * videos keep a working click. Fails open like every endpoint here.
     */
    #[Route('/api/quicklook/{ratingKey}', name: 'api_quicklook', methods: ['GET'], requirements: ['ratingKey' => '\d+'])]
    public function apiQuickLook(string $ratingKey): JsonResponse
    {
        try {
            $resolved = $this->tautulli->resolveTmdbId($ratingKey);
        } catch (\Throwable) {
            $resolved = null;
        }

        return $this->json($resolved ?? ['type' => null, 'id' => null]);
    }

    /**
     * GET /tautulli — the full Plex activity page. Server-renders the cheap
     * "Now Playing" section; heavier sections hydrate client-side. Guarded by
     * ServiceRouteGuardSubscriber when Tautulli is unconfigured.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        set_time_limit(60);
        try {
            $activity = $this->tautulli->getActivity();
        } catch (\Throwable) {
            $activity = ['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'streamCount' => 0, 'sessions' => []];
        }
        return $this->render('tautulli/index.html.twig', ['plex' => $activity]);
    }

    /** GET /tautulli/api/now-playing — live stream cards fragment (polled). */
    #[Route('/api/now-playing', name: 'api_now_playing', methods: ['GET'])]
    public function apiNowPlaying(): Response
    {
        try {
            $activity = $this->tautulli->getActivity();
        } catch (\Throwable) {
            $activity = ['connected' => false, 'error' => 'unreachable', 'sessions' => []];
        }
        return $this->render('tautulli/_now_playing.html.twig', ['plex' => $activity]);
    }

    /** GET /tautulli/api/stats?range=30&metric=plays|duration&user={userId} — watch-stats tiles fragment. */
    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function apiStats(Request $request): Response
    {
        $metric = self::metric($request);
        try {
            $stats = $this->tautulli->getHomeStats((int) $request->query->get('range', 30), $metric, self::userId($request));
        } catch (\Throwable) {
            $stats = TautulliClient::normalizeHomeStats([]);
        }
        return $this->render('tautulli/_stats.html.twig', ['stats' => $stats, 'metric' => $metric]);
    }

    /** GET /tautulli/api/plays?range=30&mode=media|stream&metric=plays|duration&user= — plays series as JSON (Chart.js). */
    #[Route('/api/plays', name: 'api_plays', methods: ['GET'])]
    public function apiPlays(Request $request): JsonResponse
    {
        $range  = (int) $request->query->get('range', 30);
        $mode   = $request->query->get('mode', 'media') === 'stream' ? 'stream' : 'media';
        $metric = self::metric($request);
        $user   = self::userId($request);
        try {
            $data = $mode === 'stream'
                ? $this->tautulli->getPlaysByStreamType($range, $metric, $user)
                : $this->tautulli->getPlaysByDate($range, $metric, $user);
            return $this->json($data);
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/activity-hour?range=30&metric=plays|duration&user= — plays by hour of day as JSON. */
    #[Route('/api/activity-hour', name: 'api_activity_hour', methods: ['GET'])]
    public function apiActivityHour(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysByHourOfDay(
                (int) $request->query->get('range', 30), self::metric($request), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/activity-dow?range=30&metric=plays|duration&user= — plays by day of week as JSON. */
    #[Route('/api/activity-dow', name: 'api_activity_dow', methods: ['GET'])]
    public function apiActivityDow(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysByDayOfWeek(
                (int) $request->query->get('range', 30), self::metric($request), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/clients-stream-type?range=30&metric=plays|duration&user= — plays by platform × stream type as JSON. */
    #[Route('/api/clients-stream-type', name: 'api_clients_stream_type', methods: ['GET'])]
    public function apiClientsStreamType(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getStreamTypeByPlatform(
                (int) $request->query->get('range', 30), self::metric($request), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/plays-source-res?range=&metric=&user= — source-resolution chart JSON. */
    #[Route('/api/plays-source-res', name: 'api_plays_source_res', methods: ['GET'])]
    public function apiPlaysSourceRes(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysBySourceResolution(
                (int) $request->query->get('range', 30), self::metric($request), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/plays-stream-res?range=&metric=&user= — stream-resolution chart JSON. */
    #[Route('/api/plays-stream-res', name: 'api_plays_stream_res', methods: ['GET'])]
    public function apiPlaysStreamRes(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysByStreamResolution(
                (int) $request->query->get('range', 30), self::metric($request), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/users-stream-type?range=&metric=&user= — user × stream-type chart JSON. */
    #[Route('/api/users-stream-type', name: 'api_users_stream_type', methods: ['GET'])]
    public function apiUsersStreamType(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getStreamTypeByUser(
                (int) $request->query->get('range', 30), self::metric($request), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/concurrent?range=&user= — concurrent streams over time JSON (count only). */
    #[Route('/api/concurrent', name: 'api_concurrent', methods: ['GET'])]
    public function apiConcurrent(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getConcurrentStreams(
                (int) $request->query->get('range', 30), self::userId($request)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/history?length=25&start=0 — history rows fragment. */
    #[Route('/api/history', name: 'api_history', methods: ['GET'])]
    public function apiHistory(Request $request): Response
    {
        try {
            $rows = $this->tautulli->getHistory(
                (int) $request->query->get('length', 25),
                (int) $request->query->get('start', 0),
                self::userId($request),
            );
        } catch (\Throwable) {
            $rows = [];
        }
        return $this->render('tautulli/_history_rows.html.twig', ['plex_history' => $rows]);
    }

    /** GET /tautulli/api/libraries — library count cards fragment. */
    #[Route('/api/libraries', name: 'api_libraries', methods: ['GET'])]
    public function apiLibraries(): Response
    {
        try {
            $libraries = $this->tautulli->getLibraries();
        } catch (\Throwable) {
            $libraries = [];
        }
        return $this->render('tautulli/_libraries.html.twig', ['libraries' => $libraries]);
    }

    /** GET /tautulli/api/users — per-user totals table fragment. */
    #[Route('/api/users', name: 'api_users', methods: ['GET'])]
    public function apiUsers(): Response
    {
        try {
            $users = $this->tautulli->getUsersTable();
        } catch (\Throwable) {
            $users = [];
        }
        return $this->render('tautulli/_users.html.twig', ['users' => $users]);
    }

    /** GET /tautulli/api/user-names — [{name,id}] for the user filter dropdown. */
    #[Route('/api/user-names', name: 'api_user_names', methods: ['GET'])]
    public function apiUserNames(): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getUserNames());
        } catch (\Throwable) {
            return $this->json([]);
        }
    }

}
