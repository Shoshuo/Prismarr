<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\GluetunClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\TorrentResolverService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/qbittorrent', name: 'app_qbittorrent_')]
class QBittorrentController extends AbstractController
{
    public function __construct(
        private readonly QBittorrentClient $qbt,
        private readonly GluetunClient $gluetun,
        private readonly TorrentResolverService $resolver,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Lightweight endpoint for the global poll (sidebar + toasts) — returns only important transitions.
     * Returns only hash + state + name — no heavy details.
     */
    #[Route('/api/poll-summary', name: 'api_poll_summary', methods: ['GET'])]
    public function apiPollSummary(): JsonResponse
    {
        try {
            $torrents = $this->qbt->getTorrents();
            $active   = 0;
            $items    = [];
            foreach ($torrents as $t) {
                $state = $t['state'] ?? '';
                if ($state === 'downloading') $active++;
                // Only the states watched for toasts: completed + error
                if (in_array($state, ['completed', 'error', 'downloading', 'seeding'], true)) {
                    $items[] = [
                        'hash'  => $t['hash'] ?? '',
                        'state' => $state,
                        'name'  => $t['name'] ?? '—',
                        'size'  => (int)($t['size'] ?? 0),
                    ];
                }
            }
            return $this->json(['active' => $active, 'items' => $items]);
        } catch (\Throwable $e) {
            $this->logger->warning('QBittorrent poll-summary failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/vpn', name: 'api_vpn', methods: ['GET'])]
    public function apiVpn(): JsonResponse
    {
        $summary    = $this->gluetun->getSummary();
        $listenPort = null;
        try { $listenPort = $this->qbt->getListenPort(); } catch (\Throwable $e) {
            $this->logger->warning('QBittorrent getListenPort failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        $fwdPort  = $summary['forwarded_port'] ?? null;
        $portSync = null; // null = unknown, true = match, false = out-of-sync
        if ($fwdPort !== null && $listenPort !== null) {
            $portSync = $fwdPort === $listenPort;
        }

        return $this->json(array_merge($summary, [
            'qbt_port'  => $listenPort,
            'port_sync' => $portSync,
        ]));
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
        $tags       = [];
        $error      = false;

        try {
            if ($this->qbt->getVersion() === null) {
                $error = true;
            } else {
                // Fast initial render: the 50 most recent (default sort).
                // JS refresh every 2s then replaces it based on user preferences (server-side pagination).
                $all        = $this->qbt->getTorrents();
                $torrents   = array_slice($all, 0, 50);
                $stats      = $this->qbt->getStats($all);
                $categories = $this->qbt->getCategories();
                $tags       = $this->qbt->getTags();
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('QBittorrent index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        // VPN (Gluetun) + qBit port — non-blocking, graceful failure
        $vpn = null;
        try {
            $summary = $this->gluetun->getSummary();
            $listenPort = null;
            try { $listenPort = $this->qbt->getListenPort(); } catch (\Throwable $e) {
                $this->logger->warning('QBittorrent getListenPort failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            }
            $vpn = array_merge($summary, [
                'qbt_port'  => $listenPort,
                'port_sync' => ($summary['forwarded_port'] ?? null) !== null && $listenPort !== null
                    ? ($summary['forwarded_port'] === $listenPort)
                    : null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Gluetun summary failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('qbittorrent/index.html.twig', [
            'torrents'   => $torrents,
            'stats'      => $stats,
            'categories' => $categories,
            'tags'       => $tags,
            'error'      => $error,
            'vpn'        => $vpn,
            'service_url' => $this->config->get('qbittorrent_url'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  JSON API — real-time refresh
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/torrents', name: 'api_torrents', methods: ['GET'])]
    public function apiTorrents(Request $request): JsonResponse
    {
        try {
            $page     = max(1, (int)$request->query->get('page', 1));
            $perPage  = max(1, min(500, (int)$request->query->get('perPage', 50)));
            $filter   = (string)$request->query->get('filter', 'all');
            $search   = trim((string)$request->query->get('search', ''));
            $category = trim((string)$request->query->get('category', ''));
            $tag      = trim((string)$request->query->get('tag', ''));
            $sort     = (string)$request->query->get('sort', 'added');
            $desc     = $request->query->get('desc', '1') === '1';

            $all   = $this->qbt->getTorrents();
            $stats = $this->qbt->getStats($all);

            // State filter
            if ($filter === 'active') {
                $all = array_values(array_filter($all, fn($t) => ($t['dlspeed'] ?? 0) > 0 || ($t['upspeed'] ?? 0) > 0));
            } elseif ($filter !== 'all') {
                $all = array_values(array_filter($all, fn($t) => $t['state'] === $filter));
            }
            // Category filter
            if ($category !== '') {
                $all = array_values(array_filter($all, fn($t) => ($t['category'] ?? '') === $category));
            }
            // Tag filter (qBit tags come as a "tag1,tag2" string)
            if ($tag !== '') {
                $all = array_values(array_filter($all, function($t) use ($tag) {
                    $tags = array_map('trim', explode(',', (string)($t['tags'] ?? '')));
                    return in_array($tag, $tags, true);
                }));
            }
            // Text search (case-insensitive)
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $all = array_values(array_filter($all, fn($t) => str_contains(mb_strtolower($t['name'] ?? ''), $needle)));
            }
            // Sort
            $sortKey = match($sort) {
                'name'     => 'name',
                'size'     => 'size',
                'progress' => 'progress',
                'dlspeed'  => 'dlspeed',
                'upspeed'  => 'upspeed',
                'ratio'    => 'ratio',
                'seeds'    => 'num_seeds',
                'category' => 'category',
                default    => 'added_on',
            };
            usort($all, function($a, $b) use ($sortKey, $desc) {
                $va = $a[$sortKey] ?? 0;
                $vb = $b[$sortKey] ?? 0;
                if ($sortKey === 'name') {
                    return $desc ? strcmp((string)$vb, (string)$va) : strcmp((string)$va, (string)$vb);
                }
                return $desc ? ($vb <=> $va) : ($va <=> $vb);
            });

            $total      = count($all);
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;
            $slice      = array_slice($all, $offset, $perPage);

            return $this->json([
                'torrents'   => $slice,
                'stats'      => $stats,
                'pagination' => [
                    'page'       => $page,
                    'perPage'    => $perPage,
                    'total'      => $total,
                    'totalPages' => $totalPages,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('QBittorrent torrents listing failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolves a torrent to its Radarr movie or Sonarr series (via name + year).
     * Delegates to TorrentResolverService (business logic extracted for testability).
     */
    #[Route('/api/resolve/{pipeline}/{hash}', name: 'api_resolve', methods: ['GET'], requirements: ['pipeline' => 'radarr|sonarr', 'hash' => '[a-fA-F0-9]{32,64}'])]
    public function apiResolve(string $pipeline, string $hash): JsonResponse
    {
        try {
            $torrent = null;
            foreach ($this->qbt->getTorrents() as $t) {
                if (($t['hash'] ?? '') === $hash) { $torrent = $t; break; }
            }
            if (!$torrent) return $this->json(['found' => false, 'error' => 'Torrent introuvable'], 404);

            return $this->json($this->resolver->resolve($pipeline, $torrent['name'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('Torrent resolve failed', ['pipeline' => $pipeline, 'hash' => $hash, 'exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/torrent/{hash}', name: 'api_torrent_detail', methods: ['GET'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function apiTorrentDetail(string $hash): JsonResponse
    {
        try {
            $props    = $this->qbt->getTorrentProperties($hash);
            $files    = $this->qbt->getTorrentFiles($hash);
            $trackers = $this->qbt->getTorrentTrackers($hash);
            $peers    = $this->qbt->getTorrentPeers($hash);

            return $this->json([
                'properties' => $props,
                'files'      => $files,
                'trackers'   => $trackers,
                'peers'      => $peers,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('QBittorrent torrent detail failed', ['hash' => $hash, 'exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Single-item actions
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/torrent/{hash}/pause', name: 'api_pause', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function pause(string $hash): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->pauseTorrents([$hash])]);
    }

    #[Route('/api/torrent/{hash}/resume', name: 'api_resume', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function resume(string $hash): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->resumeTorrents([$hash])]);
    }

    #[Route('/api/torrent/{hash}/delete', name: 'api_delete', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function delete(Request $request, string $hash): JsonResponse
    {
        $deleteFiles = (bool)($request->toArray()['deleteFiles'] ?? false);
        return $this->json(['ok' => $this->qbt->deleteTorrents([$hash], $deleteFiles)]);
    }

    #[Route('/api/torrent/{hash}/recheck', name: 'api_recheck', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function recheck(string $hash): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->recheckTorrents([$hash])]);
    }

    #[Route('/api/torrent/{hash}/reannounce', name: 'api_reannounce', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function reannounce(string $hash): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->reannounceTorrents([$hash])]);
    }

    #[Route('/api/torrent/{hash}/force-start', name: 'api_force_start', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function forceStart(Request $request, string $hash): JsonResponse
    {
        $value = (bool)($request->toArray()['value'] ?? true);
        return $this->json(['ok' => $this->qbt->setForceStart([$hash], $value)]);
    }

    #[Route('/api/torrent/{hash}/category', name: 'api_set_category', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function setCategory(Request $request, string $hash): JsonResponse
    {
        $category = $request->toArray()['category'] ?? '';
        return $this->json(['ok' => $this->qbt->setTorrentCategory([$hash], $category)]);
    }

    #[Route('/api/torrent/{hash}/sequential', name: 'api_toggle_sequential', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function toggleSequential(string $hash): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->toggleSequentialDownload([$hash])]);
    }

    #[Route('/api/torrent/{hash}/first-last', name: 'api_toggle_first_last', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function toggleFirstLast(string $hash): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->toggleFirstLastPiecePrio([$hash])]);
    }

    #[Route('/api/torrent/{hash}/files/priority', name: 'api_file_priority', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function setFilePriority(Request $request, string $hash): JsonResponse
    {
        $data = $request->toArray();
        $fileIds  = $data['fileIds'] ?? [];
        $priority = (int)($data['priority'] ?? 1);
        return $this->json(['ok' => $this->qbt->setFilePriority($hash, $fileIds, $priority)]);
    }

    #[Route('/api/torrent/{hash}/rename', name: 'api_rename', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function rename(Request $request, string $hash): JsonResponse
    {
        $name = $request->toArray()['name'] ?? '';
        if (!$name) return $this->json(['ok' => false, 'error' => 'Nom vide'], 400);
        return $this->json(['ok' => $this->qbt->renameTorrent($hash, $name)]);
    }

    #[Route('/api/torrent/{hash}/limit', name: 'api_set_limit', methods: ['POST'], requirements: ['hash' => '[a-fA-F0-9]{32,64}'])]
    public function setLimit(Request $request, string $hash): JsonResponse
    {
        $data = $request->toArray();
        $ok = true;
        if (isset($data['dl'])) $ok = $ok && $this->qbt->setTorrentDownloadLimit([$hash], (int)$data['dl']);
        if (isset($data['up'])) $ok = $ok && $this->qbt->setTorrentUploadLimit([$hash], (int)$data['up']);
        return $this->json(['ok' => $ok]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Bulk actions
    // ══════════════════════════════════════════════════════════════════════════

    /** Filter an array of hashes: keep only valid hex (+ 'all' accepted). */
    private static function sanitizeHashes(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, static fn($h) => is_string($h)
            && ($h === 'all' || preg_match('/^[a-fA-F0-9]{32,64}$/', $h) === 1)
        ));
    }

    #[Route('/api/bulk/pause', name: 'api_bulk_pause', methods: ['POST'])]
    public function bulkPause(Request $request): JsonResponse
    {
        $hashes = self::sanitizeHashes($request->toArray()['hashes'] ?? []);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => 'Aucun hash valide'], 400);
        return $this->json(['ok' => $this->qbt->pauseTorrents($hashes)]);
    }

    #[Route('/api/bulk/resume', name: 'api_bulk_resume', methods: ['POST'])]
    public function bulkResume(Request $request): JsonResponse
    {
        $hashes = self::sanitizeHashes($request->toArray()['hashes'] ?? []);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => 'Aucun hash valide'], 400);
        return $this->json(['ok' => $this->qbt->resumeTorrents($hashes)]);
    }

    #[Route('/api/bulk/delete', name: 'api_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data        = $request->toArray();
        $hashes      = self::sanitizeHashes($data['hashes'] ?? []);
        $deleteFiles = (bool)($data['deleteFiles'] ?? false);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => 'Aucun hash valide'], 400);
        return $this->json(['ok' => $this->qbt->deleteTorrents($hashes, $deleteFiles)]);
    }

    #[Route('/api/bulk/recheck', name: 'api_bulk_recheck', methods: ['POST'])]
    public function bulkRecheck(Request $request): JsonResponse
    {
        $hashes = self::sanitizeHashes($request->toArray()['hashes'] ?? []);
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => 'Aucun hash valide'], 400);
        return $this->json(['ok' => $this->qbt->recheckTorrents($hashes)]);
    }

    #[Route('/api/bulk/category', name: 'api_bulk_category', methods: ['POST'])]
    public function bulkCategory(Request $request): JsonResponse
    {
        $data     = $request->toArray();
        $hashes   = self::sanitizeHashes($data['hashes'] ?? []);
        $category = is_string($data['category'] ?? null) ? $data['category'] : '';
        if (empty($hashes)) return $this->json(['ok' => false, 'error' => 'Aucun hash valide'], 400);
        return $this->json(['ok' => $this->qbt->setTorrentCategory($hashes, $category)]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Add torrent
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/add', name: 'api_add', methods: ['POST'])]
    public function addTorrent(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $urls     = $data['urls'] ?? '';
        $category = $data['category'] ?? null;
        $savepath = $data['savepath'] ?? null;
        $paused   = (bool)($data['paused'] ?? false);

        if (!$urls) return $this->json(['ok' => false, 'error' => 'URL/magnet manquant'], 400);

        $error = $this->validateTorrentUrls($urls);
        if ($error !== null) {
            return $this->json(['ok' => false, 'error' => $error], 400);
        }

        return $this->json(['ok' => $this->qbt->addTorrentFromUrl($urls, $category, $savepath, $paused)]);
    }

    /**
     * SSRF guard on user-provided URLs passed to qBittorrent.
     *
     * qBittorrent fetches whichever URL we hand it, so an unsanitized
     * URL can be abused to probe the cloud metadata endpoint or an
     * internal admin interface via CSRF on the Prismarr admin.
     *
     * Rules: only http(s) and magnet: are accepted; cloud metadata
     * hosts are blocked. LAN/localhost remain allowed (legitimate
     * homelab use — private trackers, etc.).
     *
     * @return string|null  error message to return to the client, or null if safe
     */
    private function validateTorrentUrls(string $raw): ?string
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

            if (stripos($url, 'magnet:') === 0) {
                continue;
            }

            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return 'Seuls les liens http(s) et magnet: sont acceptés';
            }

            $host = strtolower($parts['host'] ?? '');
            if ($host === '') {
                return 'URL invalide';
            }
            // parse_url keeps IPv6 brackets — strip them for blocklist comparison.
            $host = trim($host, '[]');
            foreach ($blockedHosts as $blocked) {
                if ($host === $blocked) {
                    return 'Cette URL pointe vers un hôte interdit (métadonnées cloud)';
                }
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

        if (empty($uploaded)) return $this->json(['ok' => false, 'error' => 'Aucun fichier reçu'], 400);

        $files = [];
        foreach ($uploaded as $file) {
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            if (!$file->isValid()) continue;
            if ($file->getSize() > 10 * 1024 * 1024) { // 10 MB max, a normal .torrent is < 1 MB
                return $this->json(['ok' => false, 'error' => 'Fichier trop volumineux (>10 Mo)'], 400);
            }
            $ext = strtolower($file->getClientOriginalExtension());
            if ($ext !== 'torrent') {
                return $this->json(['ok' => false, 'error' => 'Seuls les fichiers .torrent sont acceptés'], 400);
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false || $content === '') {
                return $this->json(['ok' => false, 'error' => 'Fichier illisible'], 400);
            }
            // Magic-bytes check: a bencoded torrent always starts with 'd' (dict)
            // + typically contains "announce" or "info" within the first few KB
            if (!str_starts_with($content, 'd') || (!str_contains(substr($content, 0, 4096), 'info') && !str_contains(substr($content, 0, 4096), 'announce'))) {
                return $this->json(['ok' => false, 'error' => 'Fichier .torrent invalide (bencoding non reconnu)'], 400);
            }
            // Sanitized name (basename, no slashes or null bytes)
            $origName  = $file->getClientOriginalName() ?: 'upload.torrent';
            $cleanName = basename(str_replace("\0", '', $origName));
            $files[] = [
                'content' => $content,
                'name'    => $cleanName !== '' ? $cleanName : 'upload.torrent',
            ];
        }

        if (empty($files)) return $this->json(['ok' => false, 'error' => 'Aucun fichier valide'], 400);

        $category = $request->request->get('category') ?: null;
        $savepath = $request->request->get('savepath') ?: null;
        $paused   = $request->request->get('paused') === 'true';

        return $this->json([
            'ok'    => $this->qbt->addTorrentFromFiles($files, $category, $savepath, $paused),
            'count' => count($files),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Global speed
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/api/speed-mode', name: 'api_speed_mode', methods: ['POST'])]
    public function toggleSpeedMode(): JsonResponse
    {
        return $this->json(['ok' => $this->qbt->toggleSpeedLimitsMode()]);
    }

    #[Route('/api/global-limit', name: 'api_global_limit', methods: ['POST'])]
    public function setGlobalLimit(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ok = true;
        if (isset($data['dl'])) $ok = $ok && $this->qbt->setGlobalDownloadLimit((int)$data['dl']);
        if (isset($data['up'])) $ok = $ok && $this->qbt->setGlobalUploadLimit((int)$data['up']);
        return $this->json(['ok' => $ok]);
    }
}
