<?php

namespace App\Controller;

use App\Controller\Concerns\ApiClientErrorTrait;
use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
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

#[IsGranted('ROLE_ADMIN')]
#[Route('/medias', name: 'app_media_')]
class MediaController extends AbstractController
{
    use ApiClientErrorTrait;

    public function __construct(
        private readonly RadarrClient      $radarr,
        private readonly SonarrClient      $sonarr,
        private readonly ProwlarrClient    $prowlarr,
        private readonly QBittorrentClient $qbittorrent,
        private readonly CacheInterface    $cache,
        private readonly ConfigService     $config,
        private readonly ServiceInstanceProvider $instances,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/films', name: 'films')]
    public function films(): Response
    {
        // Issue #13 — large libraries (5k+ items) make the parse + Twig
        // render flirt with the default 60s ceiling. Bumped to give big
        // homelabs headroom until server-side pagination ships in 1.1.0.
        set_time_limit(120);

        $movies = [];
        $queue  = [];
        $error  = false;

        $indexerCount = 0;
        $warnings = [];
        try {
            // Check that Radarr is reachable
            $status = $this->radarr->getSystemStatus();
            if ($status === null) {
                $error = true;
            }

            if (!$error) {
            $movies = $this->radarr->getMovies();
            $queue  = $this->radarr->getQueue();
            $indexers = $this->radarr->getRadarrIndexers();
            $activeIndexers = array_filter($indexers, fn($i) => ($i['enableAutomaticSearch'] ?? false) || ($i['enableInteractiveSearch'] ?? false));
            $indexerCount = count($activeIndexers);

            // Check indexer status
            if ($indexerCount === 0) {
                $warnings[] = $this->translator->trans('media.api.no_indexer');
            }

            // Check Radarr health
            try {
                $health = $this->radarr->getSystemHealth();
                foreach ($health as $h) {
                    $warnings[] = $this->translator->trans('media.api.warning_format', ['source' => $h['source'] ?? 'Radarr', 'message' => $h['message'] ?? '?']);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Media films failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            }

            // Check for blocked items in the queue
            $blocked = array_filter($queue, fn($q) => ($q['trackedState'] ?? '') === 'importBlocked');
            if (count($blocked) > 0) {
                $warnings[] = $this->translator->trans('media.import.blocked_warning', ['count' => count($blocked)]);
            }
            } // end if (!$error)
        } catch (\Throwable $e) {
            $this->logger->warning('Media films failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        usort($movies, fn($a, $b) => strcmp($a['sortTitle'], $b['sortTitle']));

        $total      = count($movies);
        $downloaded = count(array_filter($movies, fn($m) => $m['hasFile']));
        $monitored  = count(array_filter($movies, fn($m) => $m['monitored'] && !$m['hasFile']));
        $totalGb    = round(array_sum(array_column($movies, 'sizeGb')), 1);

        return $this->render('media/films.html.twig', [
            'movies' => $movies,
            'queue'  => $queue,
            'error'  => $error,
            'service_url' => $this->instances->getDefault(ServiceInstance::TYPE_RADARR)?->getUrl(),
            'warnings' => $warnings,
            'stats'  => compact('total', 'downloaded', 'monitored', 'totalGb'),
            'indexerCount' => $indexerCount,
        ]);
    }

    #[Route('/series', name: 'series')]
    public function series(): Response
    {
        // Same rationale as films() — see issue #13.
        set_time_limit(120);

        $series   = [];
        $queue    = [];
        $calendar = [];
        $error    = false;

        try {
            if ($this->sonarr->getSystemStatus() === null) {
                $error = true;
            } else {
                $series   = $this->sonarr->getSeries();
                $queue    = $this->sonarr->getQueue();
                $calendar = $this->sonarr->getCalendar(14);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media series failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        usort($series, fn($a, $b) => strcmp($a['sortTitle'], $b['sortTitle']));

        $total      = count($series);
        $continuing = count(array_filter($series, fn($s) => $s['status'] === 'continuing'));
        $ended      = count(array_filter($series, fn($s) => $s['status'] === 'ended'));
        $totalGb    = round(array_sum(array_column($series, 'sizeGb')), 1);

        return $this->render('media/series.html.twig', [
            'series'   => $series,
            'queue'    => $queue,
            'calendar' => $calendar,
            'error'    => $error,
            'service_url' => $this->instances->getDefault(ServiceInstance::TYPE_SONARR)?->getUrl(),
            'stats'    => compact('total', 'continuing', 'ended', 'totalGb'),
        ]);
    }

    // ── Movie actions ─────────────────────────────────────────────────────────

    #[Route('/films/warnings', name: 'films_warnings', methods: ['GET'])]
    public function filmWarnings(): JsonResponse
    {
        $warnings = [];
        try {
            $indexers = $this->radarr->getRadarrIndexers();
            $active = count(array_filter($indexers, fn($i) => ($i['enableAutomaticSearch'] ?? false) || ($i['enableInteractiveSearch'] ?? false)));
            if ($active === 0) {
                $warnings[] = $this->translator->trans('media.api.no_indexer');
            }

            $health = $this->radarr->getSystemHealth();
            foreach ($health as $h) {
                $warnings[] = ($h['source'] ?? 'Radarr') . ' : ' . ($h['message'] ?? '?');
            }

            $queue = $this->radarr->getQueue();
            $blocked = count(array_filter($queue, fn($q) => ($q['trackedState'] ?? '') === 'importBlocked'));
            if ($blocked > 0) {
                $warnings[] = $this->translator->trans('media.import.blocked_warning', ['count' => $blocked]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmWarnings failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $warnings[] = $this->translator->trans('media.api.radarr_unreachable');
        }
        return $this->json($warnings);
    }

    #[Route('/films/{id}/detail', name: 'films_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmDetail(int $id): JsonResponse
    {
        $movie = $this->radarr->getMovie($id);
        return $this->json($movie);
    }

    #[Route('/films/{id}/search', name: 'films_search', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmSearch(int $id): JsonResponse
    {
        $cmdId = $this->radarr->searchMovie($id);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/films/{id}/refresh', name: 'films_refresh', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmRefresh(int $id): JsonResponse
    {
        $cmdId = $this->radarr->refreshMovie($id);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/films/{id}/rescan', name: 'films_rescan', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmRescan(int $id): JsonResponse
    {
        $cmdId = $this->radarr->rescanMovie($id);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/films/commands/active', name: 'films_commands_active', methods: ['GET'])]
    public function filmsCommandsActive(): JsonResponse
    {
        $all = $this->radarr->getAllCommands();
        $active = array_values(array_filter($all, fn($c) => in_array(strtolower($c['status'] ?? ''), ['started', 'queued'])));
        return $this->json($active);
    }

    #[Route('/films/command/{cmdId}', name: 'films_command', methods: ['GET'])]
    public function filmCommandStatus(int $cmdId): JsonResponse
    {
        $status = $this->radarr->getCommandStatus($cmdId);
        return $this->json($status ?? ['status' => 'unknown']);
    }

    #[Route('/films/{id}/releases', name: 'films_releases', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmReleases(int $id): JsonResponse
    {
        set_time_limit(90);

        // Fetch the movie's quality profile for score details
        $movie = $this->radarr->getMovie($id);
        $profileScores = [];
        if ($movie) {
            $profiles = $this->radarr->getQualityProfiles();
            foreach ($profiles as $p) {
                if (($p['id'] ?? 0) === ($movie['qualityProfileId'] ?? -1)) {
                    foreach ($p['formatItems'] ?? [] as $fi) {
                        $profileScores[$fi['format'] ?? 0] = $fi['score'] ?? 0;
                    }
                    break;
                }
            }
        }

        $raw = $this->radarr->getReleasesForMovie($id);
        $releases = array_map(function($r) use ($profileScores) {
            // Per-custom-format score breakdown
            $scoreDetails = [];
            foreach ($r['customFormats'] ?? [] as $cf) {
                $cfId = $cf['id'] ?? 0;
                $cfName = $cf['name'] ?? '?';
                $cfScore = $profileScores[$cfId] ?? 0;
                $scoreDetails[] = ['name' => $cfName, 'score' => $cfScore];
            }

            return [
                'guid'              => $r['guid'] ?? '',
                'indexerId'         => $r['indexerId'] ?? 0,
                'title'             => $r['title'] ?? '—',
                'indexer'           => $r['indexer'] ?? '—',
                'quality'           => $r['quality']['quality']['name'] ?? '—',
                'sizeMb'            => isset($r['size']) ? (int) round($r['size'] / 1048576) : 0,
                'seeders'           => $r['seeders'] ?? null,
                'leechers'          => $r['leechers'] ?? null,
                'protocol'          => $r['protocol'] ?? '—',
                'age'               => $r['age'] ?? 0,
                'approved'          => (bool) ($r['approved'] ?? false),
                'rejections'        => $r['rejections'] ?? [],
                'customFormatScore' => $r['customFormatScore'] ?? 0,
                'scoreDetails'      => $scoreDetails,
                'customFormats'     => array_map(fn($cf) => $cf['name'] ?? '?', $r['customFormats'] ?? []),
                'languages'         => array_map(fn($l) => $l['name'] ?? '?', $r['languages'] ?? []),
            ];
        }, $raw);

        usort($releases, fn($a, $b) => $b['approved'] <=> $a['approved']
            ?: $b['customFormatScore'] <=> $a['customFormatScore']
            ?: ($b['seeders'] ?? -1) <=> ($a['seeders'] ?? -1));

        return $this->json($releases);
    }

    #[Route('/films/{id}/grab', name: 'films_grab', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmGrab(int $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $result = $this->radarr->grabRelease($data['guid'] ?? '', (int) ($data['indexerId'] ?? 0));
        return $this->json($result);
    }

    #[Route('/films/{id}/rename-preview', name: 'films_rename_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmRenamePreview(int $id): JsonResponse
    {
        return $this->json($this->radarr->getRenameProposals($id));
    }

    #[Route('/films/{id}/rename', name: 'films_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmRename(int $id): JsonResponse
    {
        $cmdId = $this->radarr->executeRename($id);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/films/{id}/monitor', name: 'films_monitor', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmMonitor(int $id, Request $request): JsonResponse
    {
        $monitored = (bool) ($request->toArray()['monitored'] ?? true);
        $ok        = $this->radarr->setMonitored($id, $monitored);
        return $this->json(['ok' => $ok, 'monitored' => $monitored]);
    }

    #[Route('/films/{id}/delete', name: 'films_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmDelete(int $id, Request $request): JsonResponse
    {
        $data        = $request->toArray();
        $deleteFiles = (bool) ($data['deleteFiles'] ?? false);
        $addExclusion = (bool) ($data['addExclusion'] ?? false);
        $ok          = $this->radarr->deleteMovie($id, $deleteFiles, $addExclusion);
        return $this->json(['ok' => $ok]);
    }

    // ── Lookup + add ──────────────────────────────────────────────────────────

    #[Route('/films/lookup', name: 'films_lookup', methods: ['GET'])]
    public function filmLookup(Request $request): JsonResponse
    {
        $term   = $request->query->get('term', '');
        $movies = $this->radarr->lookupMovies($term);
        return $this->json($movies);
    }

    #[Route('/films/add', name: 'films_add', methods: ['POST'])]
    public function filmAdd(Request $request): JsonResponse
    {
        $data  = $request->toArray();
        $payload = [
            'tmdbId'              => (int) ($data['tmdbId'] ?? 0),
            'title'               => $data['title'] ?? '',
            'year'                => (int) ($data['year'] ?? 0),
            'qualityProfileId'    => (int) ($data['qualityProfileId'] ?? 0),
            'monitored'           => (bool) ($data['monitored'] ?? true),
            'minimumAvailability' => $data['minimumAvailability'] ?? 'released',
            'addOptions'          => ['searchForMovie' => (bool) ($data['searchForMovie'] ?? true)],
        ];
        // If explicit path provided (library import), use it instead of rootFolderPath
        if (!empty($data['path'])) {
            $payload['path'] = $data['path'];
        } else {
            $payload['rootFolderPath'] = $data['rootFolderPath'] ?? '';
        }
        $movie = $this->radarr->addMovie($payload);
        return $this->json(['ok' => $movie !== null, 'movie' => $movie, 'movieId' => $movie['id'] ?? null]);
    }

    // ── Wanted ────────────────────────────────────────────────────────────────

    #[Route('/films/manquants', name: 'films_missing')]
    public function filmsMissing(Request $request): Response
    {
        $page            = $request->query->getInt('page', 1);
        $missing         = [];
        $qualityProfiles = [];
        $error           = false;
        try {
            $missing         = $this->radarr->getMissing($page, 50);
            $qualityProfiles = $this->radarr->getQualityProfiles();
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmsMissing failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('media/films_missing.html.twig', [
            'missing'         => $missing,
            'qualityProfiles' => $qualityProfiles,
            'page'            => $page,
            'error'           => $error,
        ]);
    }

    #[Route('/films/cutoff', name: 'films_cutoff')]
    public function filmsCutoff(Request $request): Response
    {
        $page   = $request->query->getInt('page', 1);
        $cutoff = [];
        $error  = false;
        try {
            $cutoff = $this->radarr->getCutoff($page, 50);
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmsCutoff failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('media/films_cutoff.html.twig', [
            'cutoff' => $cutoff,
            'page'   => $page,
            'error'  => $error,
        ]);
    }

    // ── History ───────────────────────────────────────────────────────────────

    #[Route('/films/historique', name: 'films_history')]
    public function filmsHistory(Request $request): Response
    {
        $page      = $request->query->getInt('page', 1);
        $eventType = $request->query->get('eventType', '');
        $history   = [];
        $error     = false;
        try {
            $history = $this->radarr->getHistory($page, 50, $eventType);
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmsHistory failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('media/films_history.html.twig', [
            'history'   => $history,
            'page'      => $page,
            'eventType' => $eventType,
            'error'     => $error,
        ]);
    }

    #[Route('/films/{id}/history', name: 'films_movie_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmMovieHistory(int $id): JsonResponse
    {
        return $this->json($this->radarr->getMovieHistory($id));
    }

    // ── Credits & Extra Files ─────────────────────────────────────────────────

    #[Route('/films/{id}/info', name: 'films_info', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmInfo(int $id): JsonResponse
    {
        $movie = $this->radarr->getMovie($id);
        return $this->json($movie ?: []);
    }

    #[Route('/films/{id}/credits', name: 'films_credits', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmCredits(int $id): JsonResponse
    {
        return $this->json($this->radarr->getCredits($id));
    }

    #[Route('/films/{id}/extras', name: 'films_extras', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmExtras(int $id): JsonResponse
    {
        return $this->json($this->radarr->getExtraFiles($id));
    }

    // ── Movie Files ───────────────────────────────────────────────────────────

    #[Route('/films/{id}/files', name: 'films_files', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmFiles(int $id): JsonResponse
    {
        return $this->json($this->radarr->getMovieFiles($id));
    }

    #[Route('/films/files/{fileId}/delete', name: 'films_file_delete', methods: ['POST'])]
    public function filmFileDelete(int $fileId): JsonResponse
    {
        $ok = $this->radarr->deleteMovieFile($fileId);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
    }

    #[Route('/films/files/{fileId}/update', name: 'films_file_update', methods: ['POST'])]
    public function filmFileUpdate(int $fileId, Request $request): JsonResponse
    {
        // 1. Fetch the raw moviefile from Radarr
        $current = $this->radarr->getMovieFile($fileId);

        if ($current === null) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.file_not_found')], 404);
        }

        // 2. Merge in the modified fields
        $data = $request->toArray();

        if (isset($data['quality'])) {
            $current['quality'] = $data['quality'];
        }
        if (isset($data['languages'])) {
            $current['languages'] = $data['languages'];
        }
        if (array_key_exists('releaseGroup', $data)) {
            $current['releaseGroup'] = $data['releaseGroup'];
        }
        if (array_key_exists('edition', $data)) {
            $current['edition'] = $data['edition'];
        }

        // 3. PUT via RadarrClient
        $result = $this->radarr->updateMovieFile($fileId, $current);

        return $this->json(['ok' => $result !== null, 'file' => $result]);
    }

    #[Route('/films/quality-definitions', name: 'films_quality_definitions', methods: ['GET'])]
    public function filmsQualityDefinitions(): JsonResponse
    {
        return $this->json($this->radarr->getQualityDefinitions());
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    #[Route('/films/bulk/refresh', name: 'films_bulk_refresh', methods: ['POST'])]
    public function filmsBulkRefresh(Request $request): JsonResponse
    {
        try {
            $ids = $request->toArray()['movieIds'] ?? [];
            if (!$ids) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.no_movies_selected')]);
            $result = $this->radarr->sendCommand('RefreshMovie', ['movieIds' => $ids]);
            return $this->json(['ok' => $result !== null, 'cmdId' => $result['id'] ?? null]);
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmsBulkRefresh failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/films/bulk/search', name: 'films_bulk_search', methods: ['POST'])]
    public function filmsBulkSearch(Request $request): JsonResponse
    {
        try {
            $ids = $request->toArray()['movieIds'] ?? [];
            if (!$ids) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.no_movies_selected')]);
            $result = $this->radarr->sendCommand('MoviesSearch', ['movieIds' => $ids]);
            return $this->json(['ok' => $result !== null, 'cmdId' => $result['id'] ?? null]);
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmsBulkSearch failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/films/bulk/edit', name: 'films_bulk_edit', methods: ['POST'])]
    public function filmsBulkEdit(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ids = $data['movieIds'] ?? [];
        if (!$ids) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.no_movie')]);
        $changes = [];
        if (isset($data['monitored'])) $changes['monitored'] = (bool) $data['monitored'];
        if (isset($data['qualityProfileId'])) $changes['qualityProfileId'] = (int) $data['qualityProfileId'];
        if (isset($data['minimumAvailability'])) $changes['minimumAvailability'] = $data['minimumAvailability'];
        if (isset($data['rootFolderPath'])) $changes['rootFolderPath'] = $data['rootFolderPath'];
        if (isset($data['tags'])) $changes['tags'] = $data['tags'];
        if (isset($data['applyTags'])) $changes['applyTags'] = $data['applyTags']; // add, remove, replace
        $ok = $this->radarr->bulkUpdateMovies($ids, $changes);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
    }

    #[Route('/films/bulk/delete', name: 'films_bulk_delete', methods: ['POST'])]
    public function filmsBulkDelete(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ids = $data['movieIds'] ?? [];
        $deleteFiles = (bool) ($data['deleteFiles'] ?? false);
        $addExclusion = (bool) ($data['addExclusion'] ?? false);
        if (!$ids) return $this->json(['ok' => false]);
        $ok = $this->radarr->bulkDeleteMovies($ids, $deleteFiles, $addExclusion);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
    }

    #[Route('/films/command/refresh-downloads', name: 'films_refresh_downloads', methods: ['POST'])]
    public function refreshDownloads(): JsonResponse
    {
        try {
            $data = $this->radarr->sendCommand('RefreshMonitoredDownloads');
            return $this->json(['ok' => $data !== null]);
        } catch (\Throwable $e) {
            $this->logger->warning('Media refreshDownloads failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false]);
        }
    }

    #[Route('/films/command/refresh-all', name: 'films_refresh_all', methods: ['POST'])]
    public function filmsRefreshAll(): JsonResponse
    {
        $cmdId = $this->radarr->refreshAllMovies();
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/films/command/rss-sync', name: 'films_rss_sync', methods: ['POST'])]
    public function filmsRssSync(): JsonResponse
    {
        $cmdId = $this->radarr->rssSync();
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    // ── Dedicated Sonarr pages ────────────────────────────────────────────────

    #[Route('/series/manquants', name: 'series_missing')]
    public function seriesMissing(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $missing = $this->sonarr->getMissing($page, 50);
        return $this->render('media/series_missing.html.twig', [
            'missing' => $missing,
            'page' => $page,
        ]);
    }

    #[Route('/series/cutoff', name: 'series_cutoff')]
    public function seriesCutoff(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $cutoff = $this->sonarr->getCutoff($page, 50);
        return $this->render('media/series_cutoff.html.twig', [
            'cutoff' => $cutoff,
            'page' => $page,
        ]);
    }

    #[Route('/series/historique', name: 'series_history_page')]
    public function seriesHistoryPage(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $history = $this->sonarr->getHistory($page, 50);
        return $this->render('media/series_history.html.twig', [
            'history' => $history,
            'page' => $page,
        ]);
    }

    #[Route('/series/blocklist', name: 'series_blocklist')]
    public function seriesBlocklist(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $blocklist = $this->sonarr->getBlocklist($page, 50);
        return $this->render('media/series_blocklist.html.twig', [
            'blocklist' => $blocklist,
            'page' => $page,
        ]);
    }

    #[Route('/series/blocklist/{id}/delete', name: 'series_blocklist_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesBlocklistDelete(int $id): JsonResponse
    {
        $ok = $this->sonarr->deleteBlocklistItem($id);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/blocklist/bulk-delete', name: 'series_blocklist_bulk', methods: ['POST'])]
    public function seriesBlocklistBulkDelete(Request $request): JsonResponse
    {
        $ids = $request->toArray()['ids'] ?? [];
        if (!$ids) return $this->json(['ok' => false]);
        $ok = $this->sonarr->bulkDeleteBlocklist($ids);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/systeme', name: 'series_system')]
    public function sonarrSystem(): Response
    {
        $status = $this->sonarr->getSystemStatus();
        $health = $this->sonarr->getHealth();
        $diskSpace = $this->sonarr->getDiskSpace();
        $downloadClients = $this->sonarr->getDownloadClients();
        $indexers = $this->sonarr->getIndexers();
        $logsData = $this->sonarr->getLogs(1, 20);
        $logs = $logsData['records'] ?? $logsData;

        return $this->render('media/sonarr_system.html.twig', [
            'status' => $status,
            'health' => $health,
            'diskSpace' => $diskSpace,
            'downloadClients' => $downloadClients,
            'indexers' => $indexers,
            'logs' => $logs,
        ]);
    }

    #[Route('/series/warnings', name: 'series_warnings', methods: ['GET'])]
    public function seriesWarnings(): JsonResponse
    {
        $warnings = [];
        try {
            $health = $this->sonarr->getHealth();
            foreach ($health as $h) {
                $warnings[] = $this->translator->trans('media.api.warning_format', ['source' => $h['source'] ?? 'Sonarr', 'message' => $h['message'] ?? '?']);
            }
            $queue = $this->sonarr->getQueue();
            $blocked = count(array_filter($queue, fn($q) => ($q['trackedState'] ?? '') === 'importBlocked'));
            if ($blocked > 0) {
                $warnings[] = $this->translator->trans('media.import.blocked_warning', ['count' => $blocked]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media seriesWarnings failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
        return $this->json($warnings);
    }

    #[Route('/series/lookup', name: 'series_lookup', methods: ['GET'])]
    public function seriesLookup(Request $request): JsonResponse
    {
        $term = $request->query->get('term', '');
        $series = $this->sonarr->lookupSeries($term);
        return $this->json($series);
    }

    #[Route('/series/add', name: 'series_add', methods: ['POST'])]
    public function seriesAdd(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $tvdbId = (int) ($data['tvdbId'] ?? 0);
        if (!$tvdbId) return $this->json(['ok' => false, 'error' => 'tvdbId manquant']);

        // Lookup to retrieve full data
        $lookup = $this->sonarr->lookupSeries('tvdb:' . $tvdbId);
        if (empty($lookup)) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.series_not_found')]);

        $lookupRaw = $this->sonarr->lookupSeriesRaw('tvdb:' . $tvdbId);
        if (empty($lookupRaw)) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.series_data_missing')]);
        $raw = $lookupRaw[0];

        // Apply options
        $raw['qualityProfileId'] = (int) ($data['qualityProfileId'] ?? $raw['qualityProfileId'] ?? 1);
        $raw['rootFolderPath'] = $data['rootFolderPath'] ?? '/jellyfin/Series';
        $raw['monitored'] = (bool) ($data['monitored'] ?? true);
        $raw['seasonFolder'] = (bool) ($data['seasonFolder'] ?? true);
        $raw['seriesType'] = $data['seriesType'] ?? $raw['seriesType'] ?? 'standard';
        if (isset($data['tags'])) $raw['tags'] = array_map('intval', $data['tags']);
        $raw['addOptions'] = [
            'monitor' => $data['monitor'] ?? 'all',
            'searchForMissingEpisodes' => (bool) ($data['searchForMissing'] ?? false),
            'searchForCutoffUnmetEpisodes' => false,
        ];

        $result = $this->sonarr->addSeries($raw);
        return $this->json(['ok' => $result !== null, 'series' => $result]);
    }

    #[Route('/series/bulk/edit', name: 'series_bulk_edit', methods: ['POST'])]
    public function seriesBulkEdit(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ids = $data['seriesIds'] ?? [];
        if (!$ids) return $this->json(['ok' => false]);

        // Build the payload for PUT /series/editor (native bulk endpoint)
        $payload = ['seriesIds' => $ids];
        if (isset($data['monitored'])) $payload['monitored'] = (bool) $data['monitored'];
        if (isset($data['qualityProfileId'])) $payload['qualityProfileId'] = (int) $data['qualityProfileId'];
        if (isset($data['seriesType'])) $payload['seriesType'] = $data['seriesType'];
        if (isset($data['seasonFolder'])) $payload['seasonFolder'] = (bool) $data['seasonFolder'];
        if (isset($data['rootFolderPath'])) $payload['rootFolderPath'] = $data['rootFolderPath'];
        if (isset($data['tags'])) $payload['tags'] = array_map('intval', $data['tags']);
        if (isset($data['applyTags'])) $payload['applyTags'] = $data['applyTags'];

        $result = $this->sonarr->bulkEditSeries($payload);
        return $this->json($result);
    }

    #[Route('/series/bulk/delete', name: 'series_bulk_delete', methods: ['POST'])]
    public function seriesBulkDelete(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ids = $data['seriesIds'] ?? [];
        if (!$ids) return $this->json(['ok' => false]);
        $deleteFiles = (bool) ($data['deleteFiles'] ?? false);
        $addExclusion = (bool) ($data['addImportExclusion'] ?? false);
        $ok = $this->sonarr->bulkDeleteSeries($ids, $deleteFiles, $addExclusion);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/import/batch', name: 'series_import_batch', methods: ['POST'])]
    public function seriesImportBatch(Request $request): JsonResponse
    {
        $series = $request->toArray();
        if (empty($series)) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.no_series')]);
        return $this->json($this->sonarr->importSeries($series));
    }

    #[Route('/series/{id}/refresh', name: 'series_refresh', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesRefresh(int $id): JsonResponse
    {
        $cmdId = $this->sonarr->refreshSeries($id);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/series/command/missing-search', name: 'series_missing_search', methods: ['POST'])]
    public function seriesMissingSearch(): JsonResponse
    {
        $cmdId = $this->sonarr->searchAllMissing();
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/series/command/refresh-all', name: 'series_refresh_all', methods: ['POST'])]
    public function seriesRefreshAll(): JsonResponse
    {
        $cmdId = $this->sonarr->refreshAllSeries();
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/series/command/rss-sync', name: 'series_rss_sync', methods: ['POST'])]
    public function seriesRssSync(): JsonResponse
    {
        $cmdId = $this->sonarr->rssSync();
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/films/queue', name: 'films_queue', methods: ['GET'])]
    public function filmQueue(): JsonResponse
    {
        return $this->json($this->radarr->getQueue());
    }

    #[Route('/films/queue/{queueId}/import', name: 'films_queue_import', methods: ['POST'])]
    public function filmQueueImport(int $queueId): JsonResponse
    {
        try {
            // Fetch queue info to get quality + languages + path
            $queue = $this->radarr->getQueue();
            $item = null;
            foreach ($queue as $q) {
                if (($q['id'] ?? null) == $queueId) { $item = $q; break; }
            }
            if (!$item || !$item['outputPath']) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.queue_item_not_found')]);
            }

            // Fetch the raw queue data to get quality/languages in Radarr format
            $rawQueue = $this->radarr->getRawQueue();
            $rawItem = null;
            foreach ($rawQueue as $r) {
                if (($r['id'] ?? null) == $queueId) { $rawItem = $r; break; }
            }

            $files = [[
                'path'      => $item['outputPath'],
                'movieId'   => $item['movieId'],
                'quality'   => $rawItem['quality'] ?? null,
                'languages' => $rawItem['languages'] ?? [['id' => 1, 'name' => 'English']],
            ]];

            $result = $this->radarr->manualImport($files);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Media filmQueueImport failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/films/queue/{queueId}/delete', name: 'films_queue_delete', methods: ['POST'])]
    public function filmQueueDelete(int $queueId, Request $request): JsonResponse
    {
        $data             = $request->toArray();
        $removeFromClient = (bool) ($data['removeFromClient'] ?? false);
        $blocklist        = (bool) ($data['blocklist'] ?? false);
        $skipReimport     = (bool) ($data['skipReimport'] ?? false);
        $ok               = $this->radarr->deleteQueueItem($queueId, $removeFromClient, $blocklist, $skipReimport);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/films/queue/{queueId}/grab', name: 'films_queue_grab', methods: ['POST'], requirements: ['queueId' => '\d+'])]
    public function filmQueueGrab(int $queueId): JsonResponse
    {
        return $this->json($this->radarr->grabQueueItem($queueId));
    }

    #[Route('/films/queue/grab/bulk', name: 'films_queue_grab_bulk', methods: ['POST'])]
    public function filmQueueGrabBulk(Request $request): JsonResponse
    {
        $ids = $request->toArray()['ids'] ?? [];
        if (!$ids) return $this->json(['ok' => false]);
        return $this->json($this->radarr->bulkGrabQueue($ids));
    }

    // ── Search all missing ────────────────────────────────────────────────────

    #[Route('/films/recherche-manquants', name: 'films_search_missing', methods: ['POST'])]
    public function filmsSearchMissing(): JsonResponse
    {
        $cmdId = $this->radarr->searchAllMissing();
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    // ── Collections ───────────────────────────────────────────────────────────

    #[Route('/films/collections', name: 'films_collections')]
    public function filmsCollections(): Response
    {
        $collections = $this->radarr->getCollections();
        // Build tmdbId → hasFile lookup from library
        $movies = $this->radarr->getMovies();
        $movieIndex = [];
        foreach ($movies as $m) {
            if ($tmdb = $m['tmdbId'] ?? null) {
                $movieIndex[$tmdb] = $m['hasFile'] ?? false;
            }
        }
        // Enrich collection movies with hasFile + inLibrary
        foreach ($collections as &$col) {
            $enriched = [];
            foreach (($col['movies'] ?? []) as $m) {
                $tmdb = $m['tmdbId'] ?? null;
                $m['hasFile'] = $tmdb && isset($movieIndex[$tmdb]) ? $movieIndex[$tmdb] : false;
                $m['inLibrary'] = $tmdb && isset($movieIndex[$tmdb]);
                $enriched[] = $m;
            }
            $col['movies'] = $enriched;
        }
        unset($col);

        return $this->render('media/films_collections.html.twig', [
            'collections' => $collections,
        ]);
    }

    // ── Blocklist ─────────────────────────────────────────────────────────────

    #[Route('/films/blocklist', name: 'films_blocklist')]
    public function filmsBlocklist(Request $request): Response
    {
        $page      = $request->query->getInt('page', 1);
        $blocklist = $this->radarr->getBlocklist($page, 50);
        return $this->render('media/films_blocklist.html.twig', [
            'blocklist' => $blocklist,
            'page'      => $page,
        ]);
    }

    #[Route('/films/collections/{id}/monitor', name: 'films_collection_monitor', methods: ['POST'])]
    public function filmCollectionMonitor(int $id, Request $request): JsonResponse
    {
        $monitored = (bool) ($request->toArray()['monitored'] ?? true);
        $ok        = $this->radarr->updateCollection($id, $monitored);
        return $this->json(['ok' => $ok, 'monitored' => $monitored]);
    }

    #[Route('/films/blocklist/{id}/delete', name: 'films_blocklist_delete', methods: ['POST'])]
    public function filmBlocklistDelete(int $id): JsonResponse
    {
        $ok = $this->radarr->deleteBlocklistItem($id);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
    }

    #[Route('/films/blocklist/bulk-delete', name: 'films_blocklist_bulk', methods: ['POST'])]
    public function filmBlocklistBulk(Request $request): JsonResponse
    {
        $ids = $request->toArray()['ids'] ?? [];
        $ok = $this->radarr->bulkDeleteBlocklist($ids);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
    }

    #[Route('/films/{id}/blocklist', name: 'films_movie_blocklist', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function filmMovieBlocklist(int $id): JsonResponse
    {
        return $this->json($this->radarr->getMovieBlocklist($id));
    }

    #[Route('/films/lookup/tmdb', name: 'films_lookup_tmdb', methods: ['GET'])]
    public function filmLookupTmdb(Request $request): JsonResponse
    {
        $tmdbId = $request->query->getInt('tmdbId');
        if (!$tmdbId) return $this->json(null);
        return $this->json($this->radarr->lookupByTmdb($tmdbId));
    }

    // ── System Radarr ─────────────────────────────────────────────────────────

    #[Route('/radarr/systeme', name: 'radarr_system')]
    public function radarrSystem(): Response
    {
        $status          = $this->radarr->getSystemStatus();
        $health          = $this->radarr->getSystemHealth();
        $diskSpace       = $this->radarr->getDiskSpace();
        $downloadClients = $this->radarr->getDownloadClients();
        $indexers        = $this->radarr->getRadarrIndexers();
        $logsData        = $this->radarr->getLogs(1, 20);
        $logs            = $logsData['records'] ?? $logsData;

        return $this->render('media/radarr_system.html.twig', [
            'status'          => $status,
            'health'          => $health,
            'diskSpace'       => $diskSpace,
            'downloadClients' => $downloadClients,
            'indexers'        => $indexers,
            'logs'            => $logs,
        ]);
    }

    // ── Movie edit ────────────────────────────────────────────────────────────

    #[Route('/films/{id}/edit', name: 'films_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function filmEdit(int $id, Request $request): JsonResponse
    {
        $data  = $request->toArray();
        $movie = $this->radarr->getMovie($id);
        if ($movie === null) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.movie_not_found')], 404);
        }

        // Reload raw data for the PUT (normalizeMovie returns a normalized array, not raw)
        $raw = [];
        // Rebuild the editable fields
        if (isset($data['qualityProfileId']))    $raw['qualityProfileId']    = (int) $data['qualityProfileId'];
        if (isset($data['minimumAvailability'])) $raw['minimumAvailability'] = $data['minimumAvailability'];
        if (isset($data['rootFolderPath']))   $raw['rootFolderPath']   = $data['rootFolderPath'];
        if (isset($data['path']))             $raw['path']             = $data['path'];
        if (isset($data['tags']))             $raw['tags']             = (array) $data['tags'];
        if (isset($data['monitored']))        $raw['monitored']        = (bool) $data['monitored'];

        // Merge into the raw movie (reload required for the PUT)
        $fullMovie = $this->radarr->getRawMovie($id);
        if ($fullMovie === null) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.raw_movie_failed')], 500);
        }
        $merged = array_merge($fullMovie, $raw);
        $updated = $this->radarr->updateMovie($id, $merged);

        return $this->json(['ok' => $updated !== null, 'movie' => $updated]);
    }

    // ── Config (quality profiles, root folders, tags) ─────────────────────────

    #[Route('/films/quality-profiles', name: 'films_quality_profiles_json', methods: ['GET'])]
    public function filmsQualityProfilesJson(): JsonResponse
    {
        return $this->json($this->radarr->getQualityProfiles());
    }

    #[Route('/films/root-folders', name: 'films_root_folders_json', methods: ['GET'])]
    public function filmsRootFoldersJson(): JsonResponse
    {
        return $this->json($this->radarr->getRootFolders());
    }

    #[Route('/films/filesystem', name: 'films_filesystem', methods: ['GET'])]
    public function filmsFilesystem(Request $request): JsonResponse
    {
        $path = $request->query->get('path', '/');
        return $this->json($this->radarr->getFilesystem($path));
    }

    #[Route('/films/config/tag', name: 'films_tag_create', methods: ['POST'])]
    public function filmsTagCreate(Request $request): JsonResponse
    {
        $label = $request->toArray()['label'] ?? '';
        if (!$label) return $this->json(['ok' => false]);
        $result = $this->radarr->createTag($label);
        return $this->json(['ok' => !empty($result), 'tag' => $result]);
    }

    #[Route('/films/person/follow', name: 'films_person_follow', methods: ['POST'])]
    public function filmsPersonFollow(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $tmdbId = (int) ($data['personTmdbId'] ?? 0);
        $name = $data['personName'] ?? '';
        $type = $data['type'] ?? 'cast'; // cast or crew
        $job = $data['job'] ?? '';

        if (!$tmdbId) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.tmdb_id_missing')]);

        // Check if already followed
        $existing = $this->radarr->getImportLists();
        foreach ($existing as $list) {
            if (($list['implementation'] ?? '') !== 'TMDbPersonImport') continue;
            foreach ($list['fields'] ?? [] as $field) {
                if ($field['name'] === 'personId' && (string) $field['value'] === (string) $tmdbId) {
                    return $this->json(['ok' => true, 'already' => true, 'listId' => $list['id']]);
                }
            }
        }

        // Get schema template
        $schemas = $this->radarr->getImportListSchema();
        $template = null;
        foreach ($schemas as $s) {
            if (($s['implementation'] ?? '') === 'TMDbPersonImport') { $template = $s; break; }
        }
        if (!$template) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.tmdb_person_schema_missing')]);

        // Get default quality profile and root folder
        $profiles = $this->radarr->getQualityProfiles();
        $roots = $this->radarr->getRootFolders();
        $profileId = !empty($profiles) ? $profiles[0]['id'] : 1;
        $rootPath = !empty($roots) ? $roots[0]['path'] : '/jellyfin/Films';

        // Build fields
        foreach ($template['fields'] as &$f) {
            if ($f['name'] === 'personId') $f['value'] = (string) $tmdbId;
            if ($f['name'] === 'personCast') $f['value'] = ($type === 'cast');
            if ($f['name'] === 'personCastDirector') $f['value'] = ($job === 'Director');
            if ($f['name'] === 'personCastProducer') $f['value'] = ($job === 'Producer' || $job === 'Executive Producer');
            if ($f['name'] === 'personCastWriting') $f['value'] = ($job === 'Screenplay' || $job === 'Writer');
            if ($f['name'] === 'personCastSound') $f['value'] = ($job === 'Music' || $job === 'Original Music Composer');
        }

        $template['name'] = $name;
        $template['enabled'] = true;
        $template['enableAuto'] = false;
        $template['searchOnAdd'] = false;
        $template['qualityProfileId'] = $profileId;
        $template['rootFolderPath'] = $rootPath;
        $template['minimumAvailability'] = 'announced';

        $result = $this->radarr->createImportList($template);
        return $this->json(['ok' => !empty($result['data']), 'listId' => $result['data']['id'] ?? null]);
    }

    #[Route('/films/person/unfollow', name: 'films_person_unfollow', methods: ['POST'])]
    public function filmsPersonUnfollow(Request $request): JsonResponse
    {
        $listId = (int) ($request->toArray()['listId'] ?? 0);
        if (!$listId) return $this->json(['ok' => false]);
        $ok = $this->radarr->deleteImportList($listId);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
    }

    #[Route('/films/person/following', name: 'films_person_following', methods: ['GET'])]
    public function filmsPersonFollowing(): JsonResponse
    {
        $lists = $this->radarr->getImportLists();
        $following = [];
        foreach ($lists as $list) {
            if (($list['implementation'] ?? '') !== 'TMDbPersonImport') continue;
            $personId = null;
            foreach ($list['fields'] ?? [] as $f) {
                if ($f['name'] === 'personId') $personId = (int) $f['value'];
            }
            if ($personId) $following[$personId] = $list['id'];
        }
        return $this->json($following);
    }

    #[Route('/films/config', name: 'films_config', methods: ['GET'])]
    public function filmsConfig(): JsonResponse
    {
        return $this->json([
            'qualityProfiles' => $this->radarr->getQualityProfiles(),
            'rootFolders'     => $this->radarr->getRootFolders(),
            'tags'            => $this->radarr->getTags(),
        ]);
    }

    // ── Series detail ────────────────────────────────────────────────────────

    #[Route('/series/{id}', name: 'series_detail', requirements: ['id' => '\d+'])]
    public function serieDetail(int $id): Response
    {
        $serie    = null;
        $episodes = [];
        $error    = false;
        try {
            $serie    = $this->sonarr->getSerie($id);
            $episodes = $this->sonarr->getEpisodes($id);
        } catch (\Throwable $e) {
            $this->logger->warning('Media serieDetail failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        if (!$serie) {
            return $this->redirectToRoute('app_media_series');
        }

        // Group episodes by season
        $seasons = [];
        foreach ($episodes as $ep) {
            $sn = $ep['seasonNumber'] ?? 0;
            $seasons[$sn][] = $ep;
        }
        krsort($seasons); // Latest season first

        return $this->render('media/series_detail.html.twig', [
            'serie'    => $serie,
            'seasons'  => $seasons,
            'error'    => $error,
        ]);
    }

    #[Route('/series/{id}/info', name: 'series_info', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serieInfo(int $id): JsonResponse
    {
        $serie = $this->sonarr->getSerie($id);
        return $this->json($serie ?: []);
    }

    #[Route('/series/{id}/episodes', name: 'series_episodes', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serieEpisodes(int $id): JsonResponse
    {
        return $this->json($this->sonarr->getEpisodes($id));
    }

    #[Route('/series/episode/{id}/monitor', name: 'series_episode_monitor', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function episodeMonitor(int $id, Request $request): JsonResponse
    {
        $monitored = (bool) ($request->toArray()['monitored'] ?? true);
        $ok = $this->sonarr->setEpisodeMonitored($id, $monitored);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/episode/search', name: 'series_episode_search', methods: ['POST'])]
    public function episodeSearch(Request $request): JsonResponse
    {
        $ids = $request->toArray()['episodeIds'] ?? [];
        $cmdId = $this->sonarr->searchEpisodes($ids);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/series/{seriesId}/season/{seasonNumber}/search', name: 'series_season_search', methods: ['POST'], requirements: ['seriesId' => '\d+', 'seasonNumber' => '\d+'])]
    public function seasonSearch(int $seriesId, int $seasonNumber): JsonResponse
    {
        $cmdId = $this->sonarr->searchSeason($seriesId, $seasonNumber);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    // ── Series queue (AJAX) ──────────────────────────────────────────────────

    #[Route('/series/queue', name: 'series_queue', methods: ['GET'])]
    public function seriesQueue(): JsonResponse
    {
        return $this->json($this->sonarr->getQueue());
    }

    #[Route('/series/queue/{id}/delete', name: 'series_queue_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesQueueDelete(int $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ok = $this->sonarr->deleteQueueItem($id, (bool) ($data['removeFromClient'] ?? false), (bool) ($data['blocklist'] ?? false));
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/queue/{id}/grab', name: 'series_queue_grab', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesQueueGrab(int $id): JsonResponse
    {
        return $this->json($this->sonarr->grabQueueItem($id));
    }

    #[Route('/series/queue/grab/bulk', name: 'series_queue_grab_bulk', methods: ['POST'])]
    public function seriesQueueGrabBulk(Request $request): JsonResponse
    {
        $ids = $request->toArray()['ids'] ?? [];
        if (!$ids) return $this->json(['ok' => false]);
        return $this->json($this->sonarr->bulkGrabQueue($ids));
    }

    #[Route('/series/queue/import', name: 'series_queue_import', methods: ['POST'])]
    public function seriesQueueImport(Request $request): JsonResponse
    {
        $files = $request->toArray()['files'] ?? [];
        if (empty($files)) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.no_file')]);
        }
        return $this->json($this->sonarr->manualImport($files));
    }

    #[Route('/series/queue/refresh', name: 'series_queue_refresh', methods: ['POST'])]
    public function seriesQueueRefresh(): JsonResponse
    {
        $cmdId = $this->sonarr->refreshMonitoredDownloads();
        return $this->json(['ok' => $cmdId !== null]);
    }

    // ── Episode files + series history ───────────────────────────────────────

    #[Route('/series/{id}/files', name: 'series_files', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function seriesFiles(int $id): JsonResponse
    {
        return $this->json($this->sonarr->getEpisodeFiles($id));
    }

    #[Route('/series/file/{id}/delete', name: 'series_file_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesFileDelete(int $id): JsonResponse
    {
        $ok = $this->sonarr->deleteEpisodeFile($id);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/{id}/history', name: 'series_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function seriesHistory(int $id): JsonResponse
    {
        return $this->json($this->sonarr->getSeriesHistory($id));
    }

    #[Route('/series/season/monitor', name: 'series_season_monitor', methods: ['POST'])]
    public function seasonMonitor(Request $request): JsonResponse
    {
        $data      = $request->toArray();
        $seriesId  = (int) ($data['seriesId'] ?? 0);
        $seasonNum = (int) ($data['seasonNumber'] ?? 0);
        $monitored = (bool) ($data['monitored'] ?? true);
        $ok = $this->sonarr->setSeasonMonitored($seriesId, $seasonNum, $monitored);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/series/{id}/rename', name: 'series_rename', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function seriesRename(int $id): JsonResponse
    {
        return $this->json($this->sonarr->getRename($id));
    }

    #[Route('/series/{id}/rename', name: 'series_rename_execute', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesRenameExecute(int $id, Request $request): JsonResponse
    {
        $fileIds = $request->toArray()['fileIds'] ?? [];
        $cmdId = $this->sonarr->executeRename($id, $fileIds);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    #[Route('/series/episode/{id}/releases', name: 'series_episode_releases', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function episodeReleases(int $id): JsonResponse
    {
        set_time_limit(50);

        // Fetch the series' quality profile for custom format scores
        $episode = $this->sonarr->getEpisode($id);
        $profileScores = [];
        if ($episode) {
            $series = $this->sonarr->getSerie((int) ($episode['seriesId'] ?? 0));
            if ($series) {
                $profiles = $this->sonarr->getQualityProfiles();
                foreach ($profiles as $p) {
                    if (($p['id'] ?? 0) === ($series['qualityProfileId'] ?? -1)) {
                        foreach ($p['formatItems'] ?? [] as $fi) {
                            $profileScores[$fi['format'] ?? 0] = $fi['score'] ?? 0;
                        }
                        break;
                    }
                }
            }
        }

        $raw = $this->sonarr->getEpisodeReleases($id);
        $releases = array_map(function($r) use ($profileScores) {
            $scoreDetails = [];
            foreach ($r['customFormats'] ?? [] as $cf) {
                $cfId = $cf['id'] ?? 0;
                $cfName = $cf['name'] ?? '?';
                $cfScore = $profileScores[$cfId] ?? 0;
                $scoreDetails[] = ['name' => $cfName, 'score' => $cfScore];
            }

            // Scene / mapping info
            $fullSeason = (bool) ($r['fullSeason'] ?? false);
            $mappedEps = $r['mappedEpisodeNumbers'] ?? [];
            $mappedSeason = $r['mappedSeasonNumber'] ?? null;

            $versionLabel = '';
            if ($fullSeason) {
                $versionLabel = $this->translator->trans('media.label.season_prefix', ['number' => $r['seasonNumber'] ?? '?']);
            } elseif (!empty($r['episodeNumbers'])) {
                $versionLabel = implode(', ', array_map(fn($n) => $r['seasonNumber'] . 'x' . $n, $r['episodeNumbers']));
            }

            $tvdbLabel = '';
            if ($mappedSeason !== null && !empty($mappedEps)) {
                if (count($mappedEps) > 3) {
                    $tvdbLabel = $mappedSeason . 'x' . $mappedEps[0] . '-' . end($mappedEps);
                } else {
                    $tvdbLabel = implode(', ', array_map(fn($n) => $mappedSeason . 'x' . $n, $mappedEps));
                }
            }

            return [
                'guid'              => $r['guid'] ?? '',
                'indexerId'         => $r['indexerId'] ?? 0,
                'title'             => $r['title'] ?? '—',
                'indexer'           => $r['indexer'] ?? '—',
                'quality'           => $r['quality']['quality']['name'] ?? '—',
                'sizeMb'            => isset($r['size']) ? (int) round($r['size'] / 1048576) : 0,
                'seeders'           => $r['seeders'] ?? null,
                'leechers'          => $r['leechers'] ?? null,
                'protocol'          => $r['protocol'] ?? '—',
                'age'               => $r['age'] ?? 0,
                'approved'          => (bool) ($r['approved'] ?? false),
                'rejections'        => $r['rejections'] ?? [],
                'customFormatScore' => $r['customFormatScore'] ?? 0,
                'scoreDetails'      => $scoreDetails,
                'customFormats'     => array_map(fn($cf) => $cf['name'] ?? '?', $r['customFormats'] ?? []),
                'languages'         => array_map(fn($l) => $l['name'] ?? '?', $r['languages'] ?? []),
                'fullSeason'        => $fullSeason,
                'episodeRequested'  => (bool) ($r['episodeRequested'] ?? true),
                'versionLabel'      => $versionLabel,
                'tvdbLabel'         => $tvdbLabel,
                'releaseGroup'      => $r['releaseGroup'] ?? '',
            ];
        }, $raw);

        usort($releases, fn($a, $b) => $b['approved'] <=> $a['approved']
            ?: $b['customFormatScore'] <=> $a['customFormatScore']
            ?: ($b['seeders'] ?? -1) <=> ($a['seeders'] ?? -1));

        return $this->json($releases);
    }

    #[Route('/series/release/grab', name: 'series_release_grab', methods: ['POST'])]
    public function seriesReleaseGrab(Request $request): JsonResponse
    {
        $guid = $request->toArray()['guid'] ?? '';
        $indexerId = (int) ($request->toArray()['indexerId'] ?? 0);
        $result = $this->sonarr->grabRelease($guid, $indexerId);
        return $this->json($result);
    }

    #[Route('/series/commands/active', name: 'series_commands_active', methods: ['GET'])]
    public function seriesCommandsActive(): JsonResponse
    {
        $all = $this->sonarr->getCommands();
        $active = array_values(array_filter($all, fn($c) => in_array(strtolower($c['status'] ?? ''), ['started', 'queued'])));
        return $this->json($active);
    }

    #[Route('/series/command/{cmdId}', name: 'series_command', methods: ['GET'], requirements: ['cmdId' => '\d+'])]
    public function seriesCommandStatus(int $cmdId): JsonResponse
    {
        $status = $this->sonarr->getCommandStatus($cmdId);
        return $this->json($status ?? ['status' => 'unknown']);
    }

    #[Route('/series/file/{id}/update', name: 'series_file_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesFileUpdate(int $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        return $this->json($this->sonarr->updateEpisodeFile($id, $data));
    }

    #[Route('/series/files/bulk-update', name: 'series_files_bulk_update', methods: ['POST'])]
    public function seriesFilesBulkUpdate(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $fileId = (int) ($data['fileId'] ?? 0);
        if (!$fileId) return $this->json(['ok' => false, 'error' => 'fileId manquant']);

        // GET full file, apply changes, PUT via /bulk
        $file = $this->sonarr->getEpisodeFile($fileId);
        if (!$file) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.file_not_found')]);

        if (isset($data['quality'])) $file['quality'] = $data['quality'];
        if (isset($data['languages'])) $file['languages'] = $data['languages'];
        if (isset($data['releaseGroup'])) $file['releaseGroup'] = $data['releaseGroup'];
        if (isset($data['releaseType'])) $file['releaseType'] = $data['releaseType'];
        if (array_key_exists('indexerFlags', $data)) $file['indexerFlags'] = (int) $data['indexerFlags'];

        return $this->json($this->sonarr->bulkUpdateEpisodeFilesFull([$file]));
    }

    #[Route('/series/quality-definitions', name: 'series_quality_definitions', methods: ['GET'])]
    public function seriesQualityDefinitions(): JsonResponse
    {
        return $this->json($this->sonarr->getQualityDefinitions());
    }

    #[Route('/series/file/{id}/reassign', name: 'series_file_reassign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function seriesFileReassign(int $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $episodeIds = $data['episodeIds'] ?? [];
        $seriesId = (int) ($data['seriesId'] ?? 0);
        if (!$episodeIds || !$seriesId) {
            return $this->json(['ok' => false, 'error' => 'episodeIds et seriesId requis']);
        }
        $file = $this->sonarr->getEpisodeFile($id);
        if (!$file) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.file_not_found')]);
        $cmdId = $this->sonarr->reassignEpisodeFile($file, $seriesId, $episodeIds);
        return $this->json(['ok' => $cmdId !== null, 'cmdId' => $cmdId]);
    }

    // ── Series actions ────────────────────────────────────────────────────────

    #[Route('/series/{seriesId}/season/{seasonNumber}/releases', name: 'series_season_releases', methods: ['GET'], requirements: ['seriesId' => '\d+', 'seasonNumber' => '\d+'])]
    public function seasonReleases(int $seriesId, int $seasonNumber): JsonResponse
    {
        set_time_limit(50);

        $series = $this->sonarr->getSerie($seriesId);
        $profileScores = [];
        if ($series) {
            $profiles = $this->sonarr->getQualityProfiles();
            foreach ($profiles as $p) {
                if (($p['id'] ?? 0) === ($series['qualityProfileId'] ?? -1)) {
                    foreach ($p['formatItems'] ?? [] as $fi) {
                        $profileScores[$fi['format'] ?? 0] = $fi['score'] ?? 0;
                    }
                    break;
                }
            }
        }

        $raw = $this->sonarr->getSeasonReleases($seriesId, $seasonNumber);
        $releases = array_map(function($r) use ($profileScores) {
            $scoreDetails = [];
            foreach ($r['customFormats'] ?? [] as $cf) {
                $cfId = $cf['id'] ?? 0;
                $cfName = $cf['name'] ?? '?';
                $scoreDetails[] = ['name' => $cfName, 'score' => $profileScores[$cfId] ?? 0];
            }

            $fullSeason = (bool) ($r['fullSeason'] ?? false);
            $versionLabel = '';
            if ($fullSeason) {
                $versionLabel = $this->translator->trans('media.label.season_prefix', ['number' => $r['seasonNumber'] ?? '?']);
            } elseif (!empty($r['episodeNumbers'])) {
                $versionLabel = implode(', ', array_map(fn($n) => ($r['seasonNumber'] ?? '?') . 'x' . $n, $r['episodeNumbers']));
            }

            return [
                'guid'              => $r['guid'] ?? '',
                'indexerId'         => $r['indexerId'] ?? 0,
                'title'             => $r['title'] ?? '—',
                'indexer'           => $r['indexer'] ?? '—',
                'quality'           => $r['quality']['quality']['name'] ?? '—',
                'sizeMb'            => isset($r['size']) ? (int) round($r['size'] / 1048576) : 0,
                'seeders'           => $r['seeders'] ?? null,
                'leechers'          => $r['leechers'] ?? null,
                'protocol'          => $r['protocol'] ?? '—',
                'age'               => $r['age'] ?? 0,
                'approved'          => (bool) ($r['approved'] ?? false),
                'rejections'        => $r['rejections'] ?? [],
                'customFormatScore' => $r['customFormatScore'] ?? 0,
                'scoreDetails'      => $scoreDetails,
                'languages'         => array_map(fn($l) => $l['name'] ?? '?', $r['languages'] ?? []),
                'fullSeason'        => $fullSeason,
                'episodeRequested'  => (bool) ($r['episodeRequested'] ?? true),
                'versionLabel'      => $versionLabel,
                'releaseGroup'      => $r['releaseGroup'] ?? '',
            ];
        }, $raw);

        usort($releases, fn($a, $b) => $b['approved'] <=> $a['approved']
            ?: $b['customFormatScore'] <=> $a['customFormatScore']
            ?: ($b['seeders'] ?? -1) <=> ($a['seeders'] ?? -1));

        return $this->json($releases);
    }

    #[Route('/series/{id}/edit', name: 'series_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serieEdit(int $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $series = $this->sonarr->getRawSeries($id);
        if (!$series) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.series_not_found')]);
        }

        if (isset($data['seriesType'])) $series['seriesType'] = $data['seriesType'];
        if (isset($data['qualityProfileId'])) $series['qualityProfileId'] = (int) $data['qualityProfileId'];
        if (isset($data['rootFolderPath'])) $series['rootFolderPath'] = $data['rootFolderPath'];
        if (isset($data['monitored'])) $series['monitored'] = (bool) $data['monitored'];
        if (isset($data['monitorNewItems'])) $series['monitorNewItems'] = $data['monitorNewItems'];
        if (isset($data['seasonFolder'])) $series['seasonFolder'] = (bool) $data['seasonFolder'];
        if (isset($data['tags'])) $series['tags'] = $data['tags'];
        if (isset($data['path'])) $series['path'] = $data['path'];

        $result = $this->sonarr->updateSeries($id, $series);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/sonarr/quality-profiles', name: 'sonarr_quality_profiles_json', methods: ['GET'])]
    public function sonarrQualityProfilesJson(): JsonResponse
    {
        return $this->json($this->sonarr->getQualityProfiles());
    }

    #[Route('/sonarr/root-folders', name: 'sonarr_root_folders_json', methods: ['GET'])]
    public function sonarrRootFoldersJson(): JsonResponse
    {
        return $this->json($this->sonarr->getRootFolders());
    }

    #[Route('/sonarr/filesystem', name: 'sonarr_filesystem_json', methods: ['GET'])]
    public function sonarrFilesystemJson(Request $request): JsonResponse
    {
        $path = $request->query->get('path', '/');
        return $this->json($this->sonarr->getFilesystem($path));
    }

    #[Route('/sonarr/tags', name: 'sonarr_tags_json', methods: ['GET'])]
    public function sonarrTagsJson(): JsonResponse
    {
        return $this->json($this->sonarr->getTags());
    }

    #[Route('/sonarr/tags', name: 'sonarr_tags_create', methods: ['POST'])]
    public function sonarrTagCreate(Request $request): JsonResponse
    {
        $label = $request->toArray()['label'] ?? '';
        if (!$label) return $this->json(['ok' => false]);
        $result = $this->sonarr->createTag(['label' => $label]);
        $tag = $result['data'] ?? null;
        return $this->json(['ok' => !empty($tag), 'tag' => $tag]);
    }

    #[Route('/series/{id}/search', name: 'series_search', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serieSearch(int $id): JsonResponse
    {
        // Search only monitored AND missing episodes (no upgrades)
        $episodes = $this->sonarr->getEpisodes($id);

        // Group missing episodes by season
        $missingSeason = []; // season => [missing episodeIds]
        $totalSeason = [];   // season => total monitored (aired) episodes
        foreach ($episodes as $ep) {
            $sn = $ep['seasonNumber'] ?? 0;
            if ($sn === 0) continue; // ignore specials
            if (!($ep['monitored'] ?? false)) continue;
            // Only count episodes already aired (airDate in the past)
            $aired = !empty($ep['airDateUtc']) && strtotime($ep['airDateUtc']) <= time();
            if (!$aired) continue;
            $totalSeason[$sn] = ($totalSeason[$sn] ?? 0) + 1;
            if (!($ep['hasFile'] ?? false)) {
                $missingSeason[$sn][] = $ep['id'];
            }
        }

        if (empty($missingSeason)) {
            return $this->json(['ok' => true, 'cmdId' => null, 'message' => $this->translator->trans('media.api.no_missing_episodes')]);
        }

        $cmdIds = [];
        $episodeIds = [];
        $totalMissing = 0;

        foreach ($missingSeason as $sn => $ids) {
            $totalMissing += count($ids);
            // If every monitored episode in the season is missing → SeasonSearch (finds packs)
            if (count($ids) === ($totalSeason[$sn] ?? 0) && count($ids) > 1) {
                $cmdId = $this->sonarr->searchSeason($id, $sn);
                if ($cmdId) $cmdIds[] = $cmdId;
            } else {
                // Isolated episodes → accumulate for a grouped EpisodeSearch
                $episodeIds = array_merge($episodeIds, $ids);
            }
        }

        if (!empty($episodeIds)) {
            $cmdId = $this->sonarr->searchEpisodes($episodeIds);
            if ($cmdId) $cmdIds[] = $cmdId;
        }

        // Return the first cmdId for polling (the others run in parallel)
        $firstCmd = !empty($cmdIds) ? $cmdIds[0] : null;
        return $this->json(['ok' => $firstCmd !== null, 'cmdId' => $firstCmd, 'count' => $totalMissing]);
    }

    #[Route('/series/{id}/monitor', name: 'series_monitor', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serieMonitor(int $id, Request $request): JsonResponse
    {
        $monitored = (bool) ($request->toArray()['monitored'] ?? true);
        $ok = $this->sonarr->setMonitored($id, $monitored);
        return $this->json(['ok' => $ok, 'monitored' => $monitored]);
    }

    #[Route('/series/{id}/delete', name: 'series_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serieDelete(int $id, Request $request): JsonResponse
    {
        $deleteFiles = (bool) ($request->toArray()['deleteFiles'] ?? false);
        $ok = $this->sonarr->deleteSeries($id, $deleteFiles);
        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Sonarr', $this->sonarr);
    }

    #[Route('/telechargements', name: 'downloads')]
    public function downloads(): Response
    {
        return $this->redirectToRoute('app_qbittorrent_index', [], 301);
    }

    // ── Global search ────────────────────────────────────────────────────────

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function globalSearch(Request $request): JsonResponse
    {
        $term = trim($request->query->get('q', ''));
        if (strlen($term) < 2) {
            return $this->json([]);
        }

        $results = [];

        // Lightweight search-only cache (60s TTL) — stores only title/id/poster/year
        $movies = $this->cache->get('prismarr_search_movies', function (ItemInterface $item) {
            $item->expiresAfter(60);
            try {
                $raw = $this->radarr->getRawMovies();
                return array_map(fn($m) => [
                    'id'            => $m['id'] ?? null,
                    'title'         => $m['title'] ?? '—',
                    'originalTitle' => $m['originalTitle'] ?? null,
                    'sortTitle'     => $m['sortTitle'] ?? '',
                    'year'          => $m['year'] ?? null,
                    'hasFile'       => (bool) ($m['hasFile'] ?? false),
                    'poster'        => $this->extractPoster($m),
                ], $raw);
            } catch (\Throwable $e) {
                $this->logger->warning('Media globalSearch failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                $item->expiresAfter(5);
                return [];
            }
        });

        $series = $this->cache->get('prismarr_search_series', function (ItemInterface $item) {
            $item->expiresAfter(60);
            try {
                $raw = $this->sonarr->getRawAllSeries();
                return array_map(fn($s) => [
                    'id'            => $s['id'] ?? null,
                    'title'         => $s['title'] ?? '—',
                    'originalTitle' => $s['originalTitle'] ?? null,
                    'sortTitle'     => $s['sortTitle'] ?? '',
                    'year'          => $s['year'] ?? null,
                    'hasFile'       => true,
                    'poster'        => $this->extractPoster($s),
                ], $raw);
            } catch (\Throwable $e) {
                $this->logger->warning('Media globalSearch failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                $item->expiresAfter(5);
                return [];
            }
        });

        // Local filter, case- and accent-insensitive
        $normalize = fn(string $s) => mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s));
        $termNorm = $normalize($term);

        foreach ($movies as $m) {
            if (str_contains($normalize($m['title'] ?? ''), $termNorm)
                || str_contains($normalize($m['originalTitle'] ?? ''), $termNorm)
                || str_contains($normalize($m['sortTitle'] ?? ''), $termNorm)) {
                $results[] = array_merge($m, ['type' => 'film', 'inLibrary' => true]);
            }
        }

        foreach ($series as $s) {
            if (str_contains($normalize($s['title'] ?? ''), $termNorm)
                || str_contains($normalize($s['originalTitle'] ?? ''), $termNorm)
                || str_contains($normalize($s['sortTitle'] ?? ''), $termNorm)) {
                $results[] = array_merge($s, ['type' => 'serie', 'inLibrary' => true]);
            }
        }

        // Sort: titles starting with the term first, then alphabetical
        usort($results, function ($a, $b) use ($termNorm, $normalize) {
            $aStarts = str_starts_with($normalize($a['title'] ?? ''), $termNorm);
            $bStarts = str_starts_with($normalize($b['title'] ?? ''), $termNorm);
            if ($aStarts !== $bStarts) return $bStarts <=> $aStarts;
            return strcasecmp($a['title'], $b['title']);
        });

        return $this->json(array_slice($results, 0, 12));
    }

    #[Route('/search/online', name: 'search_online', methods: ['GET'])]
    public function globalSearchOnline(Request $request): JsonResponse
    {
        $term = trim($request->query->get('q', ''));
        if (strlen($term) < 2) {
            return $this->json([]);
        }

        // Local IDs used to flag "already in library"
        $localMovieIds = array_column(
            $this->cache->get('prismarr_search_movies', fn(ItemInterface $item) => ($item->expiresAfter(60)) ?: []),
            'id'
        );
        $localSeriesIds = array_column(
            $this->cache->get('prismarr_search_series', fn(ItemInterface $item) => ($item->expiresAfter(60)) ?: []),
            'id'
        );

        $results = [];

        try {
            $movies = $this->radarr->lookupMovies($term);
            foreach (array_slice($movies, 0, 6) as $m) {
                $id = $m['id'] ?? 0;
                $results[] = [
                    'type'      => 'film',
                    'id'        => $m['tmdbId'] ?? $id,
                    'title'     => $m['title'] ?? '—',
                    'year'      => $m['year'] ?? null,
                    'poster'    => $m['poster'] ?? null,
                    'hasFile'   => (bool) ($m['hasFile'] ?? false),
                    'inLibrary' => $id > 0 && in_array($id, $localMovieIds),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media globalSearchOnline failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        try {
            $series = $this->sonarr->lookupSeries($term);
            foreach (array_slice($series, 0, 6) as $s) {
                $id = $s['id'] ?? 0;
                $results[] = [
                    'type'      => 'serie',
                    'id'        => $s['tvdbId'] ?? $id,
                    'title'     => $s['title'] ?? '—',
                    'year'      => $s['year'] ?? null,
                    'poster'    => $s['poster'] ?? null,
                    'hasFile'   => false,
                    'inLibrary' => $id > 0 && in_array($id, $localSeriesIds),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media globalSearchOnline failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->json($results);
    }

    // Prowlarr indexers — moved to ProwlarrController

    /** Extract the poster URL from raw Radarr/Sonarr data */
    private function extractPoster(array $item): ?string
    {
        foreach ($item['images'] ?? [] as $img) {
            if (($img['coverType'] ?? '') === 'poster') {
                $url = $img['remoteUrl'] ?? ($img['url'] ?? null);
                return $url ?: null;
            }
        }
        return null;
    }

}
