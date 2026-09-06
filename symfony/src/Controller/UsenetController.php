<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\Usenet\NzbgetClient;
use App\Service\Media\Usenet\SabnzbdClient;
use App\Service\Media\Usenet\UsenetClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Usenet downloads page — one route family per client (sabnzbd | nzbget),
 * mirroring the qBittorrent page but for Usenet semantics (no seeding / ratio
 * / trackers). The {client} slug picks the right UsenetClientInterface; an
 * unknown or disabled client bounces home / 403 like qBittorrent (#15).
 *
 * Read endpoints: the page shell + the JSON queue/history feed. Write
 * endpoints (pause/resume all, per-item pause/resume/delete, speed limit, add
 * NZB by URL or file) mirror the qBittorrent action API: POST-only, gated by
 * ROLE_ADMIN, and returning a {ok:bool[, error]} envelope the page JS reads.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/usenet/{client}', name: 'app_usenet_', requirements: ['client' => 'sabnzbd|nzbget'])]
class UsenetController extends AbstractController
{
    /** Rows in the downloads page's "Recent history" preview (#47). */
    private const RECENT_HISTORY_LIMIT = 15;

    /**
     * Cache window for that preview. NZBGet's history RPC has no upstream
     * paging — getHistoryPage() pulls the whole retained history and slices
     * locally — so an uncached preview would drag that payload across the wire
     * on every page render. History only grows when a job finishes, so a short
     * window needs no invalidation.
     *
     * @internal Exposed for tests; matches MediaLibraryCache::TTL.
     */
    public const RECENT_HISTORY_TTL = 45; // seconds

    /** @internal Exposed for tests — one key per client, never shared. */
    public static function recentHistoryCacheKey(string $client): string
    {
        return 'usenet.recent_history.' . $client;
    }

    public function __construct(
        private readonly SabnzbdClient $sabnzbd,
        private readonly NzbgetClient $nzbget,
        private readonly HealthService $health,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly CacheInterface $cache,
    ) {}

    private function client(string $kind): UsenetClientInterface
    {
        return $kind === 'nzbget' ? $this->nzbget : $this->sabnzbd;
    }

    #[Route('', name: 'index')]
    public function index(string $client): Response
    {
        $label = $client === 'nzbget' ? 'NZBGet' : 'SABnzbd';
        if (!$this->health->isConfigured($client)) {
            $this->addFlash('warning', $this->translator->trans('usenet.disabled_notice', [
                'client' => $label,
            ]));
            return $this->redirectToRoute('app_home');
        }

        // Probe at render time so a configured-but-unreachable client shows an
        // explicit banner (like qBittorrent) instead of a silent empty page.
        // Use HealthService::diagnose() rather than the client's getVersion():
        // SABnzbd answers mode=version with 200 for ANY key, so getVersion()
        // would sail past a wrong API key and leave the page silently empty.
        // diagnose() probes mode=queue, which actually validates the key, and
        // tells a bad key (auth) apart from a host_whitelist block.
        $reason = null;
        try {
            $diag = $this->health->diagnose($client);
            if (!($diag['ok'] ?? false)) {
                $reason = match ($diag['category'] ?? '') {
                    'auth'           => 'auth',
                    'host_whitelist' => 'host_whitelist',
                    default          => 'unreachable',
                };
            }
        } catch (\Throwable $e) {
            $reason = 'unreachable';
            $this->logger->warning('Usenet index probe crashed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
        if ($reason !== null) {
            $this->logger->warning('Usenet {client} not OK on page render', [
                'client' => $client,
                'reason' => $reason,
            ]);
        }

        // Categories for the Add NZB picker — optional UI sugar, never block on
        // them (the circuit breaker keeps this cheap when the client is down).
        $categories = [];
        try {
            $categories = $this->client($client)->getCategories();
        } catch (\Throwable) {
        }

        // Recent history preview (#47): server-rendered once, at page load —
        // the queue keeps its JS poller, history doesn't need one. Skipped when
        // the probe already failed: the page shows its unreachable banner
        // instead, and a doomed call would just burn the connect timeout.
        //
        // Behind a short per-client cache: NZBGet's history RPC returns the
        // WHOLE retained history (the client slices locally), so an uncached
        // preview would pull an unbounded payload on every render. An empty
        // result isn't cached, and a throwing fetch caches nothing at all, so
        // neither a fresh install nor a transient failure gets pinned for the
        // window.
        $recentHistory = [];
        $historyTotal  = 0;
        if ($reason === null) {
            try {
                $hist = $this->cache->get(
                    self::recentHistoryCacheKey($client),
                    function (ItemInterface $item) use ($client): array {
                        $result = $this->client($client)->getHistoryPage(0, self::RECENT_HISTORY_LIMIT);
                        $item->expiresAfter($result['items'] === [] ? 0 : self::RECENT_HISTORY_TTL);
                        return $result;
                    },
                );
                $recentHistory = $hist['items'];
                $historyTotal  = $hist['total'];
            } catch (\Throwable $e) {
                $this->logger->warning('Usenet recent history failed', [
                    'client'    => $client,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        return $this->render('usenet/index.html.twig', [
            'client'         => $client,
            'client_label'   => $label,
            'error'          => $reason !== null,
            'error_reason'   => $reason ?? 'unreachable',
            'service_url'    => $this->config->get($client . '_url'),
            'categories'     => $categories,
            'recent_history' => $recentHistory,
            'history_total'  => $historyTotal,
        ]);
    }

    /**
     * Dedicated, server-paginated history page (the downloads page only shows a
     * compact preview). SABnzbd paginates upstream; NZBGet is sliced locally.
     */
    #[Route('/history', name: 'history', methods: ['GET'])]
    public function historyPage(string $client, Request $request): Response
    {
        $label = $client === 'nzbget' ? 'NZBGet' : 'SABnzbd';
        if (!$this->health->isConfigured($client)) {
            $this->addFlash('warning', $this->translator->trans('usenet.disabled_notice', ['client' => $label]));
            return $this->redirectToRoute('app_home');
        }

        $perPage = 50;
        $page    = max(1, $request->query->getInt('page', 1));

        $items = [];
        $total = 0;
        $error = false;
        try {
            $usenet = $this->client($client);
            $result = $usenet->getHistoryPage(($page - 1) * $perPage, $perPage);
            $total  = $result['total'];
            // Clamp a past-the-end page back to the last real page so the UI
            // doesn't show "Page 99999 of 3" with an empty list.
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($page > $lastPage) {
                $page   = $lastPage;
                $result = $usenet->getHistoryPage(($page - 1) * $perPage, $perPage);
            }
            $items = $result['items'];
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Usenet history page failed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }

        return $this->render('usenet/history.html.twig', [
            'client'       => $client,
            'client_label' => $label,
            'items'        => $items,
            'page'         => $page,
            'total_pages'  => max(1, (int) ceil($total / $perPage)),
            'total'        => $total,
            'error'        => $error,
        ]);
    }

    /**
     * JSON feed for the async page: the normalized active-queue snapshot.
     * Filtering / sorting is client-side, like qBittorrent.
     */
    #[Route('/api/queue', name: 'api_queue', methods: ['GET'])]
    public function apiQueue(string $client): JsonResponse
    {
        if (!$this->health->isConfigured($client)) {
            $label = $client === 'nzbget' ? 'NZBGet' : 'SABnzbd';
            return $this->json(['error' => $this->translator->trans('usenet.disabled_notice', [
                'client' => $label,
            ])], 403);
        }

        try {
            // History lives on its own paginated page now — the live queue feed
            // only carries the active queue, saving a call per poll.
            $queue = $this->client($client)->getQueue();

            return $this->json([
                'paused'      => $queue->paused,
                'speed'       => $queue->speedBytes,
                'speed_limit' => $queue->speedLimitBytes,
                'remaining'   => $queue->remainingBytes,
                'active'      => $queue->activeCount,
                'queued'      => $queue->queuedCount,
                'eta'         => $queue->etaSeconds,
                'free_space'  => $queue->freeSpaceBytes,
                'items'       => array_map([$this, 'serializeItem'], $queue->items),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Usenet apiQueue failed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return $this->json(['error' => $this->translator->trans('usenet.api.unreachable')], 502);
        }
    }

    /**
     * Lightweight feed for the sidebar badge poll — only the active count, so
     * the badge stays cheap to refresh.
     */
    #[Route('/api/poll-summary', name: 'api_poll_summary', methods: ['GET'])]
    public function apiPollSummary(string $client): JsonResponse
    {
        if (!$this->health->isConfigured($client)) {
            return $this->json(['error' => 'disabled'], 403);
        }
        try {
            $queue = $this->client($client)->getQueue();
            return $this->json(['active' => $queue->activeCount]);
        } catch (\Throwable $e) {
            $this->logger->warning('Usenet poll-summary failed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            // Don't echo the exception message to the client — keep it generic
            // like the other endpoints (the detail is logged above).
            return $this->json(['error' => 'unreachable'], 502);
        }
    }

    // ── Actions (write) ───────────────────────────────────────────────────────

    #[Route('/pause', name: 'pause', methods: ['POST'])]
    public function pauseAll(string $client): JsonResponse
    {
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->pauseAll());
    }

    #[Route('/resume', name: 'resume', methods: ['POST'])]
    public function resumeAll(string $client): JsonResponse
    {
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->resumeAll());
    }

    #[Route('/item/{id}/pause', name: 'item_pause', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9_.\-]+'])]
    public function pauseItem(string $client, string $id): JsonResponse
    {
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->pauseItem($id));
    }

    #[Route('/item/{id}/resume', name: 'item_resume', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9_.\-]+'])]
    public function resumeItem(string $client, string $id): JsonResponse
    {
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->resumeItem($id));
    }

    #[Route('/item/{id}/delete', name: 'item_delete', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9_.\-]+'])]
    public function deleteItem(string $client, string $id): JsonResponse
    {
        // The confirm dialog warns the user that partial files go too.
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->deleteItem($id, true));
    }

    #[Route('/speed-limit', name: 'speed_limit', methods: ['POST'])]
    public function speedLimit(string $client, Request $request): JsonResponse
    {
        $mbps = (float) ($request->toArray()['mbps'] ?? 0);
        $bytes = $mbps > 0 ? (int) round($mbps * 1024 * 1024) : 0;
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->setSpeedLimitBytes($bytes));
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function addUrl(string $client, Request $request): JsonResponse
    {
        $data     = $request->toArray();
        $url      = trim((string) ($data['url'] ?? ''));
        $category = trim((string) ($data['category'] ?? '')) ?: null;
        if ($url === '') {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('usenet.api.add_no_url')], 400);
        }
        // The downloader fetches this URL server-side, so an unvalidated URL
        // turns it into an SSRF proxy (cloud metadata, intranet, file://). Guard
        // it with the shared scheme + link-local check (qBittorrent uses a
        // separate, weaker literal-host blocklist).
        if (HealthService::urlBlockedReason($url) !== null) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('usenet.api.add_bad_url')], 400);
        }
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->addNzbFromUrl($url, $category));
    }

    #[Route('/add-file', name: 'add_file', methods: ['POST'])]
    public function addFile(string $client, Request $request): JsonResponse
    {
        $category = trim((string) $request->request->get('category', '')) ?: null;
        $uploaded = $request->files->get('files');
        $list = is_array($uploaded) ? $uploaded : ($uploaded !== null ? [$uploaded] : []);

        $payload = [];
        foreach ($list as $file) {
            if ($file === null) continue;
            $content = @file_get_contents($file->getPathname());
            if ($content === false) continue;
            $payload[] = ['content' => $content, 'name' => $file->getClientOriginalName()];
        }
        if ($payload === []) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('usenet.api.add_no_file')], 400);
        }
        return $this->runAction($client, static fn(UsenetClientInterface $c) => $c->addNzbFromFiles($payload, $category));
    }

    #[Route('/bulk/pause', name: 'bulk_pause', methods: ['POST'])]
    public function bulkPause(string $client, Request $request): JsonResponse
    {
        return $this->runBulk($client, $request, static fn(UsenetClientInterface $c, string $id) => $c->pauseItem($id));
    }

    #[Route('/bulk/resume', name: 'bulk_resume', methods: ['POST'])]
    public function bulkResume(string $client, Request $request): JsonResponse
    {
        return $this->runBulk($client, $request, static fn(UsenetClientInterface $c, string $id) => $c->resumeItem($id));
    }

    #[Route('/bulk/delete', name: 'bulk_delete', methods: ['POST'])]
    public function bulkDelete(string $client, Request $request): JsonResponse
    {
        return $this->runBulk($client, $request, static fn(UsenetClientInterface $c, string $id) => $c->deleteItem($id, true));
    }

    /**
     * Apply a per-item action to every id in the POST body's `ids` array.
     * Mirrors the qBittorrent bulk endpoints: best-effort (each id is tried
     * independently) and reports how many succeeded so the page can toast a
     * partial result. `ok` is true only when every id succeeded.
     *
     * @param callable(UsenetClientInterface, string):bool $fn
     */
    private function runBulk(string $client, Request $request, callable $fn): JsonResponse
    {
        if (!$this->health->isConfigured($client)) {
            return $this->json(['ok' => false, 'error' => 'disabled'], 403);
        }
        $ids = $request->toArray()['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('usenet.api.action_failed')], 400);
        }

        $usenet = $this->client($client);
        $ok = true;
        $count = 0;
        foreach ($ids as $id) {
            if (!is_string($id) && !is_int($id)) { $ok = false; continue; }
            try {
                if ($fn($usenet, (string) $id)) {
                    $count++;
                } else {
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $ok = false;
                $this->logger->warning('Usenet bulk action crashed', [
                    'client'    => $client,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }
        return $this->json(['ok' => $ok, 'count' => $count]);
    }

    /**
     * Run a write action against the selected client. Guards the
     * configured/enabled state, swallows upstream failures into the
     * {ok:false,error} envelope the page JS expects, and never leaks an
     * exception message to the client.
     *
     * @param callable(UsenetClientInterface):bool $fn
     */
    private function runAction(string $client, callable $fn): JsonResponse
    {
        if (!$this->health->isConfigured($client)) {
            return $this->json(['ok' => false, 'error' => 'disabled'], 403);
        }
        try {
            $ok = $fn($this->client($client));
        } catch (\Throwable $e) {
            $this->logger->warning('Usenet action crashed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return $this->json(['ok' => false, 'error' => $this->translator->trans('usenet.api.unreachable')], 502);
        }
        if (!$ok) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('usenet.api.action_failed')], 502);
        }
        return $this->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(\App\Service\Media\Usenet\UsenetDownload $d): array
    {
        return [
            'id'          => $d->id,
            'name'        => $d->name,
            'status'      => $d->status,
            'raw_status'  => $d->rawStatus,
            'size'        => $d->sizeBytes,
            'remaining'   => $d->remainingBytes,
            'percentage'  => $d->percentage,
            'category'    => $d->category,
            'eta'         => $d->etaSeconds,
            'speed'       => $d->speedBytes,
            'fail'        => $d->failMessage,
            'history'     => $d->isHistory,
            'wait'        => $d->waitSeconds,
        ];
    }
}
