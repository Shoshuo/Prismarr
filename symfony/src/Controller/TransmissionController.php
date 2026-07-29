<?php

namespace App\Controller;

use App\Controller\Concerns\ApiClientErrorTrait;
use App\Service\ConfigService;
use App\Service\Media\TorrentResolverService;
use App\Service\Media\TransmissionClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/transmission', name: 'app_transmission_')]
class TransmissionController extends AbstractController
{
    use ApiClientErrorTrait;

    public function __construct(
        private readonly TransmissionClient $transmission,
        private readonly TorrentResolverService $resolver,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Lightweight endpoint for the global poll (sidebar badge + toasts). */
    #[Route('/api/poll-summary', name: 'api_poll_summary', methods: ['GET'])]
    public function apiPollSummary(): JsonResponse
    {
        try {
            $torrents = $this->transmission->getTorrents();
            $active   = 0;
            $items    = [];
            foreach ($torrents as $t) {
                $state = $t['state'] ?? '';
                if ($state === 'downloading') $active++;
                if (in_array($state, ['completed', 'error', 'downloading', 'seeding'], true)) {
                    $items[] = [
                        'hash'  => $t['hash'] ?? '',
                        'state' => $state,
                        'name'  => $t['name'] ?? '—',
                        'size'  => (int) ($t['size'] ?? 0),
                    ];
                }
            }
            return $this->json(['active' => $active, 'items' => $items]);
        } catch (\Throwable $e) {
            $this->logger->warning('Transmission poll-summary failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Main page
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $torrents   = [];
        $stats      = [];
        $categories = [];
        $error      = false;

        try {
            if ($this->transmission->getVersion() === null) {
                $error = true;
            } else {
                $all      = $this->transmission->getTorrents();
                $torrents = array_slice($all, 0, 50);
                $stats    = $this->transmission->getStats($all);
                // Transmission has no label-listing RPC — derive the category
                // sidebar from what's actually on the torrents, qBit-shaped.
                $labels = array_values(array_unique(array_filter(array_column($all, 'category'))));
                $categories = array_fill_keys($labels, ['savePath' => '']);
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Transmission index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('transmission/index.html.twig', [
            'torrents'    => $torrents,
            'stats'       => $stats,
            'categories'  => $categories,
            'error'       => $error,
            'service_url' => $this->config->get('transmission_url'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  JSON API — real-time refresh
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/torrents', name: 'api_torrents', methods: ['GET'])]
    public function apiTorrents(Request $request): JsonResponse
    {
        try {
            $page     = max(1, (int) $request->query->get('page', 1));
            $perPage  = max(1, min(500, (int) $request->query->get('perPage', 50)));
            $filter   = (string) $request->query->get('filter', 'all');
            $search   = trim((string) $request->query->get('search', ''));
            $category = trim((string) $request->query->get('category', ''));
            $sort     = (string) $request->query->get('sort', 'added');
            $desc     = $request->query->get('desc', '1') === '1';

            $all   = $this->transmission->getTorrents();
            $stats = $this->transmission->getStats($all);

            if ($filter === 'active') {
                $all = array_values(array_filter($all, fn($t) => ($t['dlspeed'] ?? 0) > 0 || ($t['upspeed'] ?? 0) > 0));
            } elseif ($filter === 'completed') {
                $all = array_values(array_filter($all, fn($t) => ($t['progress'] ?? 0) >= 100));
            } elseif ($filter !== 'all') {
                $all = array_values(array_filter($all, fn($t) => $t['state'] === $filter));
            }
            if ($category !== '') {
                $all = array_values(array_filter($all, fn($t) => ($t['category'] ?? '') === $category));
            }
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $all = array_values(array_filter($all, fn($t) => str_contains(mb_strtolower($t['name'] ?? ''), $needle)));
            }

            $sortKey = match ($sort) {
                'name'      => 'name',
                'size'      => 'size',
                'progress'  => 'progress',
                'dlspeed'   => 'dlspeed',
                'upspeed'   => 'upspeed',
                'ratio'     => 'ratio',
                'uploaded'  => 'uploaded',
                'completed' => 'completion_on',
                'seeds'     => 'num_seeds',
                'category'  => 'category',
                default     => 'added_on',
            };
            usort($all, function ($a, $b) use ($sortKey, $desc) {
                $va = $a[$sortKey] ?? 0;
                $vb = $b[$sortKey] ?? 0;
                if ($sortKey === 'name' || $sortKey === 'category') {
                    return $desc ? strcmp((string) $vb, (string) $va) : strcmp((string) $va, (string) $vb);
                }
                return $desc ? ($vb <=> $va) : ($va <=> $vb);
            });

            $total      = count($all);
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page       = min($page, $totalPages);
            $slice      = array_slice($all, ($page - 1) * $perPage, $perPage);

            return $this->json([
                'torrents'   => $slice,
                'stats'      => $stats,
                'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => $totalPages],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Transmission torrents listing failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /** Resolve a torrent to its Radarr movie / Sonarr series — same service the qBit tab uses. */
    #[Route('/api/resolve/{pipeline}/{hash}', name: 'api_resolve', methods: ['GET'], requirements: ['pipeline' => 'radarr|sonarr', 'hash' => '[a-fA-F0-9]{40}'])]
    public function apiResolve(string $pipeline, string $hash): JsonResponse
    {
        try {
            $torrent = null;
            foreach ($this->transmission->getTorrents() as $t) {
                if (($t['hash'] ?? '') === $hash) { $torrent = $t; break; }
            }
            if (!$torrent) return $this->json(['found' => false, 'error' => $this->translator->trans('transmission.api.torrent_not_found')], 404);

            return $this->json($this->resolver->resolve($pipeline, $torrent['name'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('Transmission torrent resolve failed', ['pipeline' => $pipeline, 'hash' => $hash, 'exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/torrent/{hash}', name: 'api_torrent_detail', methods: ['GET'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function apiTorrentDetail(string $hash): JsonResponse
    {
        try {
            $detail = $this->transmission->getTorrentDetail($hash);
            if ($detail === null) {
                return $this->json(['error' => $this->translator->trans('transmission.api.torrent_not_found')], 404);
            }
            return $this->json($detail);
        } catch (\Throwable $e) {
            $this->logger->warning('Transmission torrent detail failed', ['hash' => $hash, 'exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Single-item actions
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/torrent/{hash}/pause', name: 'api_pause', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function pause(string $hash): JsonResponse
    {
        $ok = $this->transmission->pauseTorrents([$hash]);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/torrent/{hash}/resume', name: 'api_resume', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function resume(string $hash): JsonResponse
    {
        $ok = $this->transmission->resumeTorrents([$hash]);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/torrent/{hash}/delete', name: 'api_delete', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function delete(Request $request, string $hash): JsonResponse
    {
        $deleteFiles = (bool) ($request->toArray()['deleteFiles'] ?? false);
        $ok = $this->transmission->deleteTorrents([$hash], $deleteFiles);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/torrent/{hash}/recheck', name: 'api_recheck', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function recheck(string $hash): JsonResponse
    {
        $ok = $this->transmission->recheckTorrents([$hash]);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/torrent/{hash}/reannounce', name: 'api_reannounce', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function reannounce(string $hash): JsonResponse
    {
        $ok = $this->transmission->reannounceTorrents([$hash]);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/torrent/{hash}/location', name: 'api_set_location', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function setLocation(Request $request, string $hash): JsonResponse
    {
        $location = trim((string) ($request->toArray()['location'] ?? ''));
        if ($location === '') return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.api.empty_location')], 400);
        $ok = $this->transmission->setTorrentLocation($hash, $location);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/torrent/{hash}/limit', name: 'api_set_limit', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{40}'])]
    public function setLimit(Request $request, string $hash): JsonResponse
    {
        $data = $request->toArray();
        $ok = true;
        if (isset($data['dl'])) $ok = $ok && $this->transmission->setTorrentDownloadLimit([$hash], (int) $data['dl']);
        if (isset($data['up'])) $ok = $ok && $this->transmission->setTorrentUploadLimit([$hash], (int) $data['up']);
        return $this->json(['ok' => $ok]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Bulk actions
    // ══════════════════════════════════════════════════════════════════════════

    /** Transmission hashes are exactly 40 hex chars — no 'all' sentinel (unlike qBit). */
    private static function sanitizeHashes(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, static fn($h) => is_string($h)
            && preg_match('/^[a-fA-F0-9]{40}$/', $h) === 1
        ));
    }

    #[Route('/api/bulk/pause', name: 'api_bulk_pause', methods: ['POST'])]
    public function bulkPause(Request $request): JsonResponse
    {
        $hashes = self::sanitizeHashes($request->toArray()['hashes'] ?? []);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.api.no_valid_hash')], 400);
        $ok = $this->transmission->pauseTorrents($hashes);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/bulk/resume', name: 'api_bulk_resume', methods: ['POST'])]
    public function bulkResume(Request $request): JsonResponse
    {
        $hashes = self::sanitizeHashes($request->toArray()['hashes'] ?? []);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.api.no_valid_hash')], 400);
        $ok = $this->transmission->resumeTorrents($hashes);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/bulk/delete', name: 'api_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data        = $request->toArray();
        $hashes      = self::sanitizeHashes($data['hashes'] ?? []);
        $deleteFiles = (bool) ($data['deleteFiles'] ?? false);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.api.no_valid_hash')], 400);
        $ok = $this->transmission->deleteTorrents($hashes, $deleteFiles);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/bulk/recheck', name: 'api_bulk_recheck', methods: ['POST'])]
    public function bulkRecheck(Request $request): JsonResponse
    {
        $hashes = self::sanitizeHashes($request->toArray()['hashes'] ?? []);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.api.no_valid_hash')], 400);
        $ok = $this->transmission->recheckTorrents($hashes);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/pause-all', name: 'api_pause_all', methods: ['POST'])]
    public function pauseAll(): JsonResponse
    {
        $ok = $this->transmission->pauseAll();
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    #[Route('/api/resume-all', name: 'api_resume_all', methods: ['POST'])]
    public function resumeAll(): JsonResponse
    {
        $ok = $this->transmission->resumeAll();
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Add torrent
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/add', name: 'api_add', methods: ['POST'])]
    public function addTorrent(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $urls     = (string) ($data['urls'] ?? '');
        $savepath = isset($data['savepath']) && $data['savepath'] !== '' ? (string) $data['savepath'] : null;
        $paused   = (bool) ($data['paused'] ?? false);

        if ($urls === '') return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.upload.invalid_url')], 400);

        $violation = self::validateTorrentUrlsStatic($urls);
        if ($violation !== null) {
            $key = $violation === 'forbidden_host' ? 'transmission.upload.forbidden_host' : 'transmission.upload.invalid_url';
            return $this->json(['ok' => false, 'error' => $this->translator->trans($key)], 400);
        }

        $ok = $this->transmission->addTorrentFromUrl($urls, $savepath, $paused);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Transmission', $this->transmission);
    }

    /**
     * SSRF guard — same policy as QBittorrentController::validateTorrentUrls
     * (Transmission fetches whichever URL we hand it via `filename`): http(s)/magnet
     * only, cloud metadata hosts blocked, LAN allowed. Static + translator-free so
     * it unit-tests without a container; returns a violation marker
     * ('invalid_url' | 'forbidden_host'), null when safe.
     */
    private static function validateTorrentUrlsStatic(string $raw): ?string
    {
        $blockedHosts = [
            '169.254.169.254',
            'fd00:ec2::254',
            'metadata.google.internal',
            'metadata.goog',
            'metadata.azure.com',
            'metadata.azure.net',
        ];

        foreach (preg_split('/[\r\n|]+/', trim($raw)) as $url) {
            $url = trim($url);
            if ($url === '') continue;
            if (stripos($url, 'magnet:') === 0) continue;

            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return 'invalid_url';
            }
            $host = strtolower(trim($parts['host'] ?? '', '[]'));
            if ($host === '') {
                return 'invalid_url';
            }
            if (in_array($host, $blockedHosts, true)) {
                return 'forbidden_host';
            }
        }
        return null;
    }

    /** Upload one or more .torrent files (multipart/form-data). */
    #[Route('/api/add-file', name: 'api_add_file', methods: ['POST'])]
    public function addTorrentFile(Request $request): JsonResponse
    {
        $uploaded = $request->files->all()['torrents'] ?? [];
        if (!is_array($uploaded)) $uploaded = [$uploaded];
        $uploaded = array_filter($uploaded);

        if (empty($uploaded)) return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.upload.no_file')], 400);

        $files = [];
        foreach ($uploaded as $file) {
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            if (!$file->isValid()) continue;
            if ($file->getSize() > 10 * 1024 * 1024) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.upload.too_large')], 400);
            }
            if (strtolower($file->getClientOriginalExtension()) !== 'torrent') {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.upload.invalid_format')], 400);
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false || $content === '') {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.upload.unreadable')], 400);
            }
            // Bencoded torrent: starts with 'd' + contains "info"/"announce" early
            if (!str_starts_with($content, 'd') || (!str_contains(substr($content, 0, 4096), 'info') && !str_contains(substr($content, 0, 4096), 'announce'))) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.upload.invalid_format')], 400);
            }
            $origName  = $file->getClientOriginalName() ?: 'upload.torrent';
            $cleanName = basename(str_replace("\0", '', $origName));
            $files[] = ['content' => $content, 'name' => $cleanName !== '' ? $cleanName : 'upload.torrent'];
        }

        if (empty($files)) return $this->json(['ok' => false, 'error' => $this->translator->trans('transmission.api.no_valid_file')], 400);

        $savepath = $request->request->get('savepath') ?: null;
        $paused   = $request->request->get('paused') === 'true';

        $ok = $this->transmission->addTorrentFromFiles($files, $savepath, $paused);
        return $ok
            ? $this->json(['ok' => true, 'count' => count($files)])
            : $this->jsonClientError('Transmission', $this->transmission);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Global speed limits
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/global-limit', name: 'api_global_limit', methods: ['POST'])]
    public function setGlobalLimit(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ok = true;
        if (isset($data['dl'])) $ok = $ok && $this->transmission->setGlobalDownloadLimit((int) $data['dl']);
        if (isset($data['up'])) $ok = $ok && $this->transmission->setGlobalUploadLimit((int) $data['up']);
        return $this->json(['ok' => $ok]);
    }
}
