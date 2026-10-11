<?php

namespace App\Controller;

use App\Service\Media\TracearrClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tracearr activity page + its read-only internal API (TracearrClient).
 *
 * Every Tracearr call is made server-side: the API key never reaches the
 * browser and the JSON / fragments returned here are already sanitized.
 * Endpoints always answer 200 with a neutral shape for disabled /
 * unconfigured / unreachable / auth cases, so the page never breaks. Only
 * read-only public-API endpoints are used — never stream termination.
 */
#[IsGranted('ROLE_USER')]
#[Route('/tracearr', name: 'app_tracearr_')]
class TracearrController extends AbstractController
{
    private const HISTORY_PAGE = 24;

    public function __construct(
        private readonly TracearrClient $tracearr,
    ) {}

    /**
     * GET /tracearr — full activity page. Server-renders the cheap "Now
     * playing" section; heavier sections hydrate client-side. Guarded by
     * ServiceRouteGuardSubscriber when Tracearr is unconfigured.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $activity = $this->tracearr->getActivity();
        } catch (\Throwable) {
            $activity = ['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'streamCount' => 0, 'sessions' => []];
        }
        return $this->render('tracearr/index.html.twig', ['activity' => $activity]);
    }

    /** GET /tracearr/api/activity — active streams as JSON. */
    #[Route('/api/activity', name: 'api_activity', methods: ['GET'])]
    public function apiActivity(): JsonResponse
    {
        try {
            return $this->json($this->tracearr->getActivity());
        } catch (\Throwable) {
            return $this->json(['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'streamCount' => 0, 'sessions' => []]);
        }
    }

    /** GET /tracearr/api/now-playing — live stream cards fragment (polled). */
    #[Route('/api/now-playing', name: 'api_now_playing', methods: ['GET'])]
    public function apiNowPlaying(): Response
    {
        try {
            $activity = $this->tracearr->getActivity();
        } catch (\Throwable) {
            $activity = ['error' => 'unreachable', 'connected' => false, 'sessions' => []];
        }
        return $this->render('tracearr/_now_playing.html.twig', ['activity' => $activity]);
    }

    /** GET /tracearr/api/stats — overview tiles fragment (today + last 30 days). */
    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function apiStats(): Response
    {
        try {
            $stats = $this->tracearr->getStats();
        } catch (\Throwable) {
            $stats = TracearrClient::normalizeStats([], []);
        }
        return $this->render('tracearr/_stats.html.twig', ['stats' => $stats]);
    }

    /** GET /tracearr/api/charts?period=week|month|year — every chart series as JSON (Chart.js). */
    #[Route('/api/charts', name: 'api_charts', methods: ['GET'])]
    public function apiCharts(Request $request): JsonResponse
    {
        $period = TracearrClient::clampPeriod((string) $request->query->get('period', 'month'));
        try {
            return $this->json($this->tracearr->getActivityCharts($period));
        } catch (\Throwable) {
            return $this->json(TracearrClient::normalizeActivityCharts([]));
        }
    }

    /**
     * GET /tracearr/api/history?cursor= — history grid cells fragment. The
     * next page's cursor rides along in a hidden sentinel cell (validated in
     * TracearrClient::getHistory; a malformed cursor yields an empty page).
     */
    #[Route('/api/history', name: 'api_history', methods: ['GET'])]
    public function apiHistory(Request $request): Response
    {
        $cursor = trim((string) $request->query->get('cursor', ''));
        try {
            $page = $this->tracearr->getHistory($cursor !== '' ? $cursor : null, self::HISTORY_PAGE);
        } catch (\Throwable) {
            $page = ['rows' => [], 'nextCursor' => null];
        }
        return $this->render('tracearr/_history_rows.html.twig', [
            'history'     => $page['rows'],
            'next_cursor' => $page['nextCursor'],
        ]);
    }

    /** GET /tracearr/api/users — users overview fragment. */
    #[Route('/api/users', name: 'api_users', methods: ['GET'])]
    public function apiUsers(): Response
    {
        try {
            $users = $this->tracearr->getUsers();
        } catch (\Throwable) {
            $users = [];
        }
        return $this->render('tracearr/_users.html.twig', ['users' => $users]);
    }

    /**
     * GET /tracearr/api/image?server={uuid}&img=/library/metadata/1/thumb/2 —
     * streams a poster fetched server-side through Tracearr's image proxy.
     * Both params are allow-listed in TracearrClient::fetchImage (SSRF guard);
     * a miss returns 404 so the CSS placeholder shows through.
     */
    #[Route('/api/image', name: 'api_image', methods: ['GET'])]
    public function apiImage(Request $request): Response
    {
        $server = (string) $request->query->get('server', '');
        $img    = (string) $request->query->get('img', '');
        $image  = $server !== '' && $img !== '' ? $this->tracearr->fetchImage($server, $img) : null;
        if ($image === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($image['body']);
        $response->headers->set('Content-Type', $image['contentType']);
        $response->setMaxAge(86400);
        $response->setPrivate();

        return $response;
    }
}
