<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

class SonarrClient implements ResetInterface
{
    private const SERVICE = 'Sonarr';

    private string $baseUrl = '';
    private string $apiKey = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->baseUrl === '') {
            $this->baseUrl = $this->config->require('sonarr_url', self::SERVICE);
            $this->apiKey  = $this->config->require('sonarr_api_key', self::SERVICE);
        }
    }

    public function reset(): void
    {
        $this->baseUrl = '';
        $this->apiKey  = '';
    }

    /** Light ping — true if the API responds and accepts the key. */
    public function ping(): bool
    {
        try {
            return $this->getSystemStatus() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    // ── Full library ──────────────────────────────────────────────────────────

    public function getSeries(): array
    {
        $data = $this->get('/api/v3/series');
        if ($data === null) return [];

        return array_map(fn($s) => $this->normalizeSeries($s), $data);
    }

    /** Returns raw series without normalization (for lightweight cache) */
    public function getRawAllSeries(): array
    {
        return $this->get('/api/v3/series') ?? [];
    }

    public function getSerie(int $id): ?array
    {
        $data = $this->get("/api/v3/series/{$id}");
        return $data ? $this->normalizeSeries($data) : null;
    }

    public function getRawSeries(int $id): ?array
    {
        return $this->get("/api/v3/series/{$id}");
    }

    // ── Lookup & CRUD series ──────────────────────────────────────────────────

    public function lookupSeries(string $term): array
    {
        $data = $this->get('/api/v3/series/lookup', ['term' => $term]);
        if ($data === null) return [];
        return array_map(fn($s) => $this->normalizeSeries($s), $data);
    }

    public function addSeries(array $data): ?array
    {
        $result = $this->request('POST', '/api/v3/series', [], $data);
        return $result ? $this->normalizeSeries($result) : null;
    }

    public function lookupSeriesRaw(string $term): array
    {
        return $this->get('/api/v3/series/lookup', ['term' => $term]) ?? [];
    }

    public function updateSeries(int $id, array $data): ?array
    {
        $result = $this->request('PUT', "/api/v3/series/{$id}", [], $data);
        return $result ? $this->normalizeSeries($result) : null;
    }

    public function deleteSeries(int $id, bool $deleteFiles = false): bool
    {
        return $this->delete("/api/v3/series/{$id}", [
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ]);
    }

    /**
     * PUT /api/v3/series/editor — native bulk edit (monitored, qualityProfileId, seriesType, seasonFolder, rootFolderPath, tags, applyTags)
     */
    public function bulkEditSeries(array $payload): array
    {
        return $this->requestWithError('PUT', '/api/v3/series/editor', $payload);
    }

    /**
     * DELETE /api/v3/series/editor — native bulk delete
     */
    public function bulkDeleteSeries(array $ids, bool $deleteFiles = false, bool $addImportExclusion = false): bool
    {
        return $this->deleteWithBody('/api/v3/series/editor', [
            'seriesIds'          => $ids,
            'deleteFiles'        => $deleteFiles,
            'addImportExclusion' => $addImportExclusion,
        ]);
    }

    /**
     * POST /api/v3/series/import — batch series import
     */
    public function importSeries(array $series): array
    {
        return $this->requestWithError('POST', '/api/v3/series/import', $series);
    }

    public function setMonitored(int $id, bool $monitored): bool
    {
        $series = $this->get("/api/v3/series/{$id}");
        if ($series === null) return false;
        $series['monitored'] = $monitored;
        return $this->request('PUT', "/api/v3/series/{$id}", [], $series) !== null;
    }

    public function setSeasonMonitored(int $seriesId, int $seasonNumber, bool $monitored): bool
    {
        $series = $this->get("/api/v3/series/{$seriesId}");
        if ($series === null) return false;
        foreach ($series['seasons'] as &$s) {
            if ($s['seasonNumber'] === $seasonNumber) {
                $s['monitored'] = $monitored;
            }
        }
        unset($s);
        return $this->request('PUT', "/api/v3/series/{$seriesId}", [], $series) !== null;
    }

    // ── Episodes & Files ──────────────────────────────────────────────────────

    public function getEpisodes(int $seriesId): array
    {
        return $this->get('/api/v3/episode', ['seriesId' => $seriesId]) ?? [];
    }

    public function getEpisode(int $id): ?array
    {
        return $this->get("/api/v3/episode/{$id}");
    }

    public function setEpisodeMonitored(int $id, bool $monitored): bool
    {
        $episode = $this->get("/api/v3/episode/{$id}");
        if ($episode === null) return false;
        $episode['monitored'] = $monitored;
        return $this->request('PUT', "/api/v3/episode/{$id}", [], $episode) !== null;
    }

    public function getEpisodeFiles(int $seriesId): array
    {
        return $this->get('/api/v3/episodefile', ['seriesId' => $seriesId]) ?? [];
    }

    public function getEpisodeFile(int $id): ?array
    {
        return $this->get("/api/v3/episodefile/{$id}");
    }

    public function deleteEpisodeFile(int $id): bool
    {
        return $this->delete("/api/v3/episodefile/{$id}");
    }

    public function bulkDeleteEpisodeFiles(array $ids): bool
    {
        return $this->deleteWithBody('/api/v3/episodefile/bulk', ['episodeFileIds' => $ids]);
    }

    public function updateEpisodeFile(int $id, array $data): array
    {
        $file = $this->getEpisodeFile($id);
        if (!$file) return ['ok' => false, 'error' => 'File not found'];
        if (isset($data['quality'])) $file['quality'] = $data['quality'];
        if (isset($data['languages'])) $file['languages'] = $data['languages'];
        if (isset($data['releaseGroup'])) $file['releaseGroup'] = $data['releaseGroup'];
        if (isset($data['releaseType'])) $file['releaseType'] = $data['releaseType'];
        if (array_key_exists('indexerFlags', $data)) $file['indexerFlags'] = (int) $data['indexerFlags'];
        $result = $this->request('PUT', "/api/v3/episodefile/{$id}", [], $file);
        return $result ? ['ok' => true, 'file' => $result] : ['ok' => false, 'error' => 'Update failed'];
    }

    /**
     * PUT /api/v3/episodefile/editor — bulk update (quality, languages, releaseGroup only)
     */
    public function bulkUpdateEpisodeFilesEditor(array $payload): array
    {
        $result = $this->request('PUT', '/api/v3/episodefile/editor', [], $payload);
        return $result !== null ? ['ok' => true] : ['ok' => false, 'error' => 'Update failed'];
    }

    /**
     * PUT /api/v3/episodefile/bulk — full update (supports releaseType, indexerFlags too)
     * Accepts array of full EpisodeFileResource objects.
     */
    public function bulkUpdateEpisodeFilesFull(array $files): array
    {
        $result = $this->request('PUT', '/api/v3/episodefile/bulk', [], $files);
        return $result !== null ? ['ok' => true, 'files' => $result] : ['ok' => false, 'error' => 'Bulk update failed'];
    }

    /**
     * Reassigns an existing file to an episode via the ManualImport command.
     */
    public function reassignEpisodeFile(array $file, int $seriesId, array $episodeIds): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], [
            'name' => 'ManualImport',
            'files' => [[
                'path'          => $file['path'] ?? '',
                'seriesId'      => $seriesId,
                'episodeIds'    => $episodeIds,
                'quality'       => $file['quality'] ?? null,
                'languages'     => $file['languages'] ?? [],
                'releaseGroup'  => $file['releaseGroup'] ?? '',
                'indexerFlags'  => $file['indexerFlags'] ?? 0,
                'releaseType'   => $file['releaseType'] ?? 'singleEpisode',
                'episodeFileId' => $file['id'] ?? 0,
            ]],
            'importMode' => 'auto',
        ]);
        return $data['id'] ?? null;
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function getHistory(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/history', [
            'page'           => $page,
            'pageSize'       => $pageSize,
            'includeSeries'  => 'true',
            'includeEpisode' => 'true',
            'sortKey'        => 'date',
            'sortDirection'  => 'descending',
        ]) ?? [];
    }

    public function getSeriesHistory(int $seriesId): array
    {
        // /api/v3/history paginated with seriesIds (plural) + includeEpisode works
        // /api/v3/history/series does NOT support includeEpisode despite the docs
        $data = $this->get('/api/v3/history', [
            'seriesIds'      => $seriesId,
            'includeEpisode' => 'true',
            'pageSize'       => 100,
            'sortKey'        => 'date',
            'sortDirection'  => 'descending',
        ]);
        return $data['records'] ?? [];
    }

    public function markHistoryAsFailed(int $historyId): bool
    {
        return $this->request('POST', "/api/v3/history/failed/{$historyId}", [], []) !== null;
    }

    // ── Download queue ────────────────────────────────────────────────────────

    public function getQueue(): array
    {
        $data = $this->get('/api/v3/queue', ['pageSize' => 50, 'includeSeries' => 'true', 'includeEpisode' => 'true']);
        if ($data === null || empty($data['records'])) return [];

        return array_map(fn($r) => [
            'id'            => $r['id'] ?? null,
            'seriesId'      => $r['seriesId'] ?? null,
            'seriesTitle'   => $r['series']['title'] ?? '—',
            'episode'       => isset($r['episode'])
                ? 'S' . str_pad($r['episode']['seasonNumber'] ?? 0, 2, '0', STR_PAD_LEFT)
                  . 'E' . str_pad($r['episode']['episodeNumber'] ?? 0, 2, '0', STR_PAD_LEFT)
                  . ' — ' . ($r['episode']['title'] ?? '')
                : ($r['title'] ?? '—'),
            'size'          => $r['size'] ?? 0,
            'sizeleft'      => $r['sizeleft'] ?? 0,
            'status'        => $r['status'] ?? 'unknown',
            'trackedStatus' => $r['trackedDownloadStatus'] ?? 'ok',
            'trackedState'  => $r['trackedDownloadState'] ?? '',
            'quality'       => $r['quality']['quality']['name'] ?? '—',
            'protocol'      => $r['protocol'] ?? '—',
            'eta'           => $r['estimatedCompletionTime'] ?? null,
            'indexer'       => $r['indexer'] ?? null,
            'downloadId'    => $r['downloadId'] ?? null,
            'outputPath'    => $r['outputPath'] ?? null,
            'downloadClient' => $r['downloadClient'] ?? null,
            'statusMessages' => array_map(fn($m) => [
                'title'    => $m['title'] ?? '',
                'messages' => $m['messages'] ?? [],
            ], $r['statusMessages'] ?? []),
        ], $data['records']);
    }

    public function getQueueDetails(): array
    {
        return $this->get('/api/v3/queue/details', ['includeSeries' => 'true', 'includeEpisode' => 'true']) ?? [];
    }

    public function getQueueStatus(): ?array
    {
        return $this->get('/api/v3/queue/status');
    }

    public function deleteQueueItem(int $id, bool $removeFromClient = false, bool $blocklist = false): bool
    {
        return $this->delete("/api/v3/queue/{$id}", [
            'removeFromClient' => $removeFromClient ? 'true' : 'false',
            'blocklist'        => $blocklist ? 'true' : 'false',
        ]);
    }

    public function grabQueueItem(int $id): array
    {
        return $this->requestWithError('POST', "/api/v3/queue/grab/{$id}", []);
    }

    public function bulkGrabQueue(array $ids): array
    {
        return $this->requestWithError('POST', '/api/v3/queue/grab/bulk', ['ids' => $ids]);
    }

    public function bulkDeleteQueue(array $ids, bool $removeFromClient = false, bool $blocklist = false): bool
    {
        return $this->deleteWithBody('/api/v3/queue/bulk', [
            'ids'              => $ids,
            'removeFromClient' => $removeFromClient,
            'blocklist'        => $blocklist,
        ]);
    }

    // ── Calendar (upcoming episodes) ──────────────────────────────────────────

    public function getCalendar(int $daysAhead = 30, int $daysBefore = 0): array
    {
        $start = (new \DateTimeImmutable("-{$daysBefore} days"))->format('Y-m-d');
        $end   = (new \DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');
        $data  = $this->get('/api/v3/calendar', ['start' => $start, 'end' => $end, 'includeSeries' => 'true']);
        if ($data === null) return [];

        return array_map(fn($e) => [
            'id'            => $e['id'] ?? null,
            'seriesId'      => $e['seriesId'] ?? null,
            'seriesTitle'   => $e['series']['title'] ?? '—',
            'poster'        => $this->posterUrl($e['series'] ?? []),
            'fanart'        => $this->fanartUrl($e['series'] ?? []),
            'season'        => $e['seasonNumber'] ?? 0,
            'episode'       => $e['episodeNumber'] ?? 0,
            'title'         => $e['title'] ?? '—',
            'overview'      => $e['overview'] ?? ($e['series']['overview'] ?? null),
            'airDate'       => isset($e['airDateUtc']) ? new \DateTimeImmutable($e['airDateUtc']) : null,
            'hasFile'       => (bool) ($e['hasFile'] ?? false),
            'monitored'     => (bool) ($e['monitored'] ?? false),
            'runtime'       => $e['runtime'] ?? ($e['series']['runtime'] ?? null),
            'network'       => $e['series']['network'] ?? null,
            'genres'        => $e['series']['genres'] ?? [],
        ], $data);
    }

    // ── Wanted ────────────────────────────────────────────────────────────────

    public function getMissing(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/wanted/missing', [
            'page'           => $page,
            'pageSize'       => $pageSize,
            'sortKey'        => 'airDateUtc',
            'sortDirection'  => 'descending',
            'monitored'      => 'true',
            'includeSeries'  => 'true',
        ]) ?? [];
    }

    public function getCutoff(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/wanted/cutoff', [
            'page'           => $page,
            'pageSize'       => $pageSize,
            'sortKey'        => 'airDateUtc',
            'sortDirection'  => 'descending',
            'monitored'      => 'true',
            'includeSeries'  => 'true',
        ]) ?? [];
    }

    public function getBlocklist(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/blocklist', [
            'page' => $page,
            'pageSize' => $pageSize,
            'sortKey' => 'date',
            'sortDirection' => 'descending',
        ]) ?? [];
    }

    public function deleteBlocklistItem(int $id): bool
    {
        return $this->delete("/api/v3/blocklist/{$id}");
    }

    public function bulkDeleteBlocklist(array $ids): bool
    {
        return $this->deleteWithBody('/api/v3/blocklist/bulk', ['ids' => $ids]);
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    public function sendCommand(string $name, array $body = []): ?array
    {
        return $this->request('POST', '/api/v3/command', [], array_merge(['name' => $name], $body));
    }

    public function manualImport(array $files): array
    {
        $cmdData = $this->request('POST', '/api/v3/command', [], [
            'name'       => 'ManualImport',
            'importMode' => 'move',
            'files'      => $files,
        ]);
        if ($cmdData === null) {
            return ['ok' => false, 'error' => 'Cannot start manual import'];
        }
        return ['ok' => true, 'cmdId' => $cmdData['id'] ?? null];
    }

    public function refreshMonitoredDownloads(): ?int
    {
        $data = $this->sendCommand('RefreshMonitoredDownloads');
        return $data['id'] ?? null;
    }

    public function getCommands(): array
    {
        return $this->get('/api/v3/command') ?? [];
    }

    public function getCommandStatus(int $cmdId): ?array
    {
        return $this->get("/api/v3/command/{$cmdId}");
    }

    public function cancelCommand(int $cmdId): bool
    {
        return $this->delete("/api/v3/command/{$cmdId}");
    }

    public function searchSeriesCmd(int $id): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'SeriesSearch', 'seriesId' => $id]);
        return $data['id'] ?? null;
    }

    public function refreshSeries(int $id): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RefreshSeries', 'seriesId' => $id]);
        return $data['id'] ?? null;
    }

    public function refreshAllSeries(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RefreshSeries']);
        return $data['id'] ?? null;
    }

    public function rssSync(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RssSync']);
        return $data['id'] ?? null;
    }

    public function searchSeason(int $seriesId, int $seasonNumber): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], [
            'name'         => 'SeasonSearch',
            'seriesId'     => $seriesId,
            'seasonNumber' => $seasonNumber,
        ]);
        return $data['id'] ?? null;
    }

    public function searchEpisodes(array $episodeIds): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'EpisodeSearch', 'episodeIds' => $episodeIds]);
        return $data['id'] ?? null;
    }

    public function getEpisodeReleases(int $episodeId): array
    {
        return $this->get('/api/v3/release', ['episodeId' => $episodeId], 45) ?? [];
    }

    public function getSeasonReleases(int $seriesId, int $seasonNumber): array
    {
        return $this->get('/api/v3/release', ['seriesId' => $seriesId, 'seasonNumber' => $seasonNumber], 45) ?? [];
    }

    public function grabRelease(string $guid, int $indexerId): array
    {
        return $this->requestWithError('POST', '/api/v3/release', [
            'guid'      => $guid,
            'indexerId' => $indexerId,
        ]);
    }

    public function searchAllMissing(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'MissingEpisodeSearch']);
        return $data['id'] ?? null;
    }

    // ── Season Pass ───────────────────────────────────────────────────────────

    public function updateSeasonPass(array $data): bool
    {
        return $this->request('POST', '/api/v3/seasonpass', [], $data) !== null;
    }

    // ── System ────────────────────────────────────────────────────────────────

    public function getSystemStatus(): ?array
    {
        return $this->get('/api/v3/system/status');
    }

    public function getHealth(): array
    {
        return $this->get('/api/v3/health') ?? [];
    }

    public function getDiskSpace(): array
    {
        return $this->get('/api/v3/diskspace') ?? [];
    }

    // ── Quality Profiles ──────────────────────────────────────────────────────

    public function getQualityProfiles(): array
    {
        return $this->get('/api/v3/qualityprofile') ?? [];
    }

    public function createQualityProfile(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/qualityprofile', $d);
    }

    public function updateQualityProfile(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/qualityprofile/{$id}", $d);
    }

    public function deleteQualityProfile(int $id): bool
    {
        return $this->delete("/api/v3/qualityprofile/{$id}");
    }

    // ── Quality Definitions ───────────────────────────────────────────────────

    public function getQualityDefinitions(): array
    {
        return $this->get('/api/v3/qualitydefinition') ?? [];
    }

    public function getQualityDefinitionLimits(): ?array
    {
        return $this->get('/api/v3/qualitydefinition/limits');
    }

    // ── Delay Profiles ────────────────────────────────────────────────────────

    public function getDelayProfiles(): array
    {
        return $this->get('/api/v3/delayprofile') ?? [];
    }

    public function createDelayProfile(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/delayprofile', $d);
    }

    public function updateDelayProfile(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/delayprofile/{$id}", $d);
    }

    public function deleteDelayProfile(int $id): bool
    {
        return $this->delete("/api/v3/delayprofile/{$id}");
    }

    // ── Custom Formats ────────────────────────────────────────────────────────

    public function getCustomFormats(): array
    {
        return $this->get('/api/v3/customformat') ?? [];
    }

    public function createCustomFormat(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/customformat', $d);
    }

    public function updateCustomFormat(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/customformat/{$id}", $d);
    }

    public function deleteCustomFormat(int $id): bool
    {
        return $this->delete("/api/v3/customformat/{$id}");
    }

    // ── Root Folders ──────────────────────────────────────────────────────────

    public function getRootFolders(): array
    {
        return $this->get('/api/v3/rootfolder') ?? [];
    }

    public function createRootFolder(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/rootfolder', $d);
    }

    public function deleteRootFolder(int $id): bool
    {
        return $this->delete("/api/v3/rootfolder/{$id}");
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    public function getTags(): array
    {
        return $this->get('/api/v3/tag') ?? [];
    }

    public function getTagsDetail(): array
    {
        return $this->get('/api/v3/tag/detail') ?? [];
    }

    public function createTag(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/tag', $d);
    }

    public function updateTag(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/tag/{$id}", $d);
    }

    public function deleteTag(int $id): bool
    {
        return $this->delete("/api/v3/tag/{$id}");
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    public function getNotifications(): array
    {
        return $this->get('/api/v3/notification') ?? [];
    }

    public function getNotificationSchema(): array
    {
        return $this->get('/api/v3/notification/schema') ?? [];
    }

    public function createNotification(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/notification', $d);
    }

    public function updateNotification(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/notification/{$id}", $d);
    }

    public function deleteNotification(int $id): bool
    {
        return $this->delete("/api/v3/notification/{$id}");
    }

    // ── Indexers ──────────────────────────────────────────────────────────────

    public function getIndexers(): array
    {
        return $this->get('/api/v3/indexer') ?? [];
    }

    public function getIndexerSchema(): array
    {
        return $this->get('/api/v3/indexer/schema') ?? [];
    }

    public function createIndexer(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/indexer', $d);
    }

    public function updateIndexer(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/indexer/{$id}", $d);
    }

    public function deleteIndexer(int $id): bool
    {
        return $this->delete("/api/v3/indexer/{$id}");
    }

    // ── Download Clients ──────────────────────────────────────────────────────

    public function getDownloadClients(): array
    {
        return $this->get('/api/v3/downloadclient') ?? [];
    }

    public function getDownloadClientSchema(): array
    {
        return $this->get('/api/v3/downloadclient/schema') ?? [];
    }

    public function createDownloadClient(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/downloadclient', $d);
    }

    public function updateDownloadClient(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/downloadclient/{$id}", $d);
    }

    public function deleteDownloadClient(int $id): bool
    {
        return $this->delete("/api/v3/downloadclient/{$id}");
    }

    // ── Import Lists ──────────────────────────────────────────────────────────

    public function getImportLists(): array
    {
        return $this->get('/api/v3/importlist') ?? [];
    }

    public function getImportListSchema(): array
    {
        return $this->get('/api/v3/importlist/schema') ?? [];
    }

    public function createImportList(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/importlist', $d);
    }

    public function updateImportList(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/importlist/{$id}", $d);
    }

    public function deleteImportList(int $id): bool
    {
        return $this->delete("/api/v3/importlist/{$id}");
    }

    // ── Import List Exclusions ────────────────────────────────────────────────

    public function getImportListExclusions(): array
    {
        return $this->get('/api/v3/importlistexclusion') ?? [];
    }

    public function createImportListExclusion(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/importlistexclusion', $d);
    }

    public function updateImportListExclusion(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/importlistexclusion/{$id}", $d);
    }

    public function deleteImportListExclusion(int $id): bool
    {
        return $this->delete("/api/v3/importlistexclusion/{$id}");
    }

    // ── Auto-Tagging ──────────────────────────────────────────────────────────

    public function getAutoTags(): array
    {
        return $this->get('/api/v3/autotagging') ?? [];
    }

    public function createAutoTag(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/autotagging', $d);
    }

    public function updateAutoTag(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/autotagging/{$id}", $d);
    }

    public function deleteAutoTag(int $id): bool
    {
        return $this->delete("/api/v3/autotagging/{$id}");
    }

    // ── Custom Filters ────────────────────────────────────────────────────────

    public function getCustomFilters(): array
    {
        return $this->get('/api/v3/customfilter') ?? [];
    }

    public function createCustomFilter(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/customfilter', $d);
    }

    public function updateCustomFilter(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/customfilter/{$id}", $d);
    }

    public function deleteCustomFilter(int $id): bool
    {
        return $this->delete("/api/v3/customfilter/{$id}");
    }

    // ── Remote Path Mappings ──────────────────────────────────────────────────

    public function getRemotePathMappings(): array
    {
        return $this->get('/api/v3/remotepathmapping') ?? [];
    }

    public function createRemotePathMapping(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/remotepathmapping', $d);
    }

    public function updateRemotePathMapping(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/remotepathmapping/{$id}", $d);
    }

    public function deleteRemotePathMapping(int $id): bool
    {
        return $this->delete("/api/v3/remotepathmapping/{$id}");
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    public function getMetadata(): array
    {
        return $this->get('/api/v3/metadata') ?? [];
    }

    public function getMetadataSchema(): array
    {
        return $this->get('/api/v3/metadata/schema') ?? [];
    }

    public function createMetadata(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/metadata', $d);
    }

    public function updateMetadata(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/metadata/{$id}", $d);
    }

    public function deleteMetadata(int $id): bool
    {
        return $this->delete("/api/v3/metadata/{$id}");
    }

    // ── Languages ─────────────────────────────────────────────────────────────

    public function getLanguages(): array
    {
        return $this->get('/api/v3/language') ?? [];
    }

    // ── Logs ──────────────────────────────────────────────────────────────────

    public function getLogs(int $page = 1, int $pageSize = 100, string $level = ''): array
    {
        $params = [
            'page'          => $page,
            'pageSize'      => $pageSize,
            'sortKey'       => 'time',
            'sortDirection' => 'descending',
        ];
        if ($level !== '') {
            $params['level'] = $level;
        }
        return $this->get('/api/v3/log', $params) ?? [];
    }

    public function getLogFiles(): array
    {
        return $this->get('/api/v3/log/file') ?? [];
    }

    // ── Backups ───────────────────────────────────────────────────────────────

    public function getBackups(): array
    {
        return $this->get('/api/v3/system/backup') ?? [];
    }

    public function deleteBackup(int $id): bool
    {
        return $this->delete("/api/v3/system/backup/{$id}");
    }

    // ── Updates ───────────────────────────────────────────────────────────────

    public function getUpdates(): array
    {
        return $this->get('/api/v3/update') ?? [];
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    public function getTasks(): array
    {
        return $this->get('/api/v3/system/task') ?? [];
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public function getHostConfig(): ?array
    {
        return $this->get('/api/v3/config/host');
    }

    public function getUiConfig(): ?array
    {
        return $this->get('/api/v3/config/ui');
    }

    public function getNamingConfig(): ?array
    {
        return $this->get('/api/v3/config/naming');
    }

    public function getNamingExamples(): ?array
    {
        return $this->get('/api/v3/config/naming/examples');
    }

    public function getMediaManagementConfig(): ?array
    {
        return $this->get('/api/v3/config/mediamanagement');
    }

    public function getIndexerConfig(): ?array
    {
        return $this->get('/api/v3/config/indexer');
    }

    public function getDownloadClientConfig(): ?array
    {
        return $this->get('/api/v3/config/downloadclient');
    }

    public function getImportListConfig(): ?array
    {
        return $this->get('/api/v3/config/importlist');
    }

    // ── Config Save ──────────────────────────────────────────────────────────

    public function updateHostConfig(array $d): ?array
    {
        return $this->request('PUT', '/api/v3/config/host', [], $d);
    }

    public function updateUiConfig(array $d): array
    {
        return $this->requestWithError('PUT', '/api/v3/config/ui', $d);
    }

    public function updateNamingConfig(array $d): array
    {
        return $this->requestWithError('PUT', '/api/v3/config/naming', $d);
    }

    public function updateMediaManagementConfig(array $d): array
    {
        return $this->requestWithError('PUT', '/api/v3/config/mediamanagement', $d);
    }

    // ── Test endpoints ───────────────────────────────────────────────────────

    public function testIndexer(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/indexer/test', $d);
    }

    public function testDownloadClient(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/downloadclient/test', $d);
    }

    public function testNotification(int $id): bool
    {
        $notif = $this->get("/api/v3/notification/{$id}");
        if (!$notif) return false;
        return $this->request('POST', '/api/v3/notification/test', [], $notif) !== null;
    }

    public function testNotificationPayload(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/notification/test', $d);
    }

    // ── Custom Format Schema ─────────────────────────────────────────────────

    public function getCustomFormatSchema(): array
    {
        return $this->get('/api/v3/customformat/schema') ?? [];
    }

    // ── Quality definitions bulk ─────────────────────────────────────────────

    public function bulkUpdateQualityDefinitions(array $definitions): bool
    {
        return $this->request('PUT', '/api/v3/qualitydefinition/update', [], $definitions) !== null;
    }

    // ── Root folder add ──────────────────────────────────────────────────────

    public function addRootFolder(string $path): ?array
    {
        return $this->request('POST', '/api/v3/rootfolder', [], ['path' => $path]);
    }

    // ── Backup create/restore ────────────────────────────────────────────────

    public function createBackup(): ?int
    {
        $result = $this->request('POST', '/api/v3/command', [], ['name' => 'Backup']);
        return $result['id'] ?? null;
    }

    public function restoreBackup(int $id): bool
    {
        return $this->request('POST', "/api/v3/system/backup/restore/{$id}", [], []) !== null;
    }

    // ── Update install ───────────────────────────────────────────────────────

    public function installUpdate(): ?int
    {
        $result = $this->request('POST', '/api/v3/command', [], ['name' => 'ApplicationUpdate']);
        return $result['id'] ?? null;
    }

    // ── Parse title (alias) ──────────────────────────────────────────────────

    public function parseTitle(string $title): ?array
    {
        return $this->parse($title);
    }

    // ── Import List Exclusions (paged) ───────────────────────────────────────

    public function getImportListExclusionsPaged(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/importlistexclusion/paged', [
            'page' => $page, 'pageSize' => $pageSize,
        ]) ?? [];
    }

    public function bulkDeleteImportListExclusions(array $ids): bool
    {
        return $this->deleteWithBody('/api/v3/importlistexclusion/bulk', ['ids' => $ids]);
    }

    // ── Import List Series (suggestions) ─────────────────────────────────────

    public function getImportListSeriesWithRecommendations(): array
    {
        // The /api/v3/importlist/series endpoint does not exist in Sonarr v4
        // Return an empty array — Sonarr suggestions are not supported
        return [];
    }

    // ── FileSystem ────────────────────────────────────────────────────────────

    public function getFilesystem(string $path, bool $includeFiles = false): array
    {
        return $this->get('/api/v3/filesystem', [
            'path'         => $path,
            'includeFiles' => $includeFiles ? 'true' : 'false',
        ]) ?? [];
    }

    // ── Manual Import ─────────────────────────────────────────────────────────

    public function getManualImportPreview(string $folder, bool $filterExistingFiles = true): array
    {
        return $this->get('/api/v3/manualimport', [
            'folder'              => $folder,
            'filterExistingFiles' => $filterExistingFiles ? 'true' : 'false',
        ]) ?? [];
    }

    // ── Parse ─────────────────────────────────────────────────────────────────

    public function parse(string $title): ?array
    {
        return $this->get('/api/v3/parse', ['title' => $title]);
    }

    // ── Rename ────────────────────────────────────────────────────────────────

    public function getRename(int $seriesId): array
    {
        return $this->get('/api/v3/rename', ['seriesId' => $seriesId]) ?? [];
    }

    public function executeRename(int $seriesId, array $fileIds = []): ?int
    {
        if (!empty($fileIds)) {
            $data = $this->request('POST', '/api/v3/command', [], [
                'name'     => 'RenameFiles',
                'seriesId' => $seriesId,
                'files'    => $fileIds,
            ]);
        } else {
            $data = $this->request('POST', '/api/v3/command', [], [
                'name'      => 'RenameSeries',
                'seriesIds' => [$seriesId],
            ]);
        }
        return $data['id'] ?? null;
    }

    // ── Normalization ─────────────────────────────────────────────────────────

    private function normalizeSeries(array $s): array
    {
        $stats              = $s['statistics'] ?? $s;
        $totalEpisodeCount  = (int) ($stats['totalEpisodeCount'] ?? 0);
        $episodeCount       = (int) ($stats['episodeCount'] ?? 0);
        $episodeFileCount   = (int) ($stats['episodeFileCount'] ?? 0);
        $sizeBytes          = (float) ($stats['sizeOnDisk'] ?? $s['sizeOnDisk'] ?? 0);

        return [
            'id'               => $s['id'] ?? null,
            'tvdbId'           => $s['tvdbId'] ?? null,
            'tmdbId'           => $s['tmdbId'] ?? null,
            'imdbId'           => $s['imdbId'] ?? null,
            'title'            => $s['title'] ?? '—',
            'sortTitle'        => strtolower($s['sortTitle'] ?? $s['title'] ?? ''),
            'year'             => $s['year'] ?? null,
            'overview'         => $s['overview'] ?? null,
            'poster'           => $this->imageUrl($s, 'poster'),
            'fanart'           => $this->imageUrl($s, 'fanart'),
            'banner'           => $this->imageUrl($s, 'banner'),
            'status'           => $s['status'] ?? 'unknown',
            'ended'            => (bool) ($s['ended'] ?? false),
            'monitored'        => (bool) ($s['monitored'] ?? false),
            'seriesType'       => $s['seriesType'] ?? 'standard',
            'seasonCount'      => (int) ($s['seasonCount'] ?? count($s['seasons'] ?? [])),
            'totalEpisodeCount' => $totalEpisodeCount,
            'episodeCount'     => $episodeCount,
            'episodeFileCount' => $episodeFileCount,
            'percent'          => $episodeCount > 0 ? round($episodeFileCount / $episodeCount * 100) : 0,
            'sizeOnDisk'       => (int) $sizeBytes,
            'sizeGb'           => $sizeBytes > 0 ? round($sizeBytes / 1073741824, 2) : null,
            'runtime'          => $s['runtime'] ?? null,
            'certification'    => $s['certification'] ?? null,
            'network'          => $s['network'] ?? null,
            'airTime'          => $s['airTime'] ?? null,
            'path'             => $s['path'] ?? null,
            'qualityProfileId' => $s['qualityProfileId'] ?? null,
            'rootFolderPath'   => $s['rootFolderPath'] ?? null,
            'seasonFolder'     => (bool) ($s['seasonFolder'] ?? true),
            'monitorNewItems'  => $s['monitorNewItems'] ?? 'all',
            'tags'             => $s['tags'] ?? [],
            'genres'           => $s['genres'] ?? [],
            'ratings'          => $s['ratings']['value'] ?? null,
            'seasons'          => array_map(fn($season) => [
                'seasonNumber'    => $season['seasonNumber'] ?? 0,
                'monitored'       => (bool) ($season['monitored'] ?? false),
                'episodeCount'    => (int) ($season['statistics']['episodeCount'] ?? 0),
                'episodeFileCount'=> (int) ($season['statistics']['episodeFileCount'] ?? 0),
                'totalEpisodeCount' => (int) ($season['statistics']['totalEpisodeCount'] ?? 0),
                'sizeOnDisk'      => (int) ($season['statistics']['sizeOnDisk'] ?? 0),
                'percent'         => ($season['statistics']['episodeCount'] ?? 0) > 0
                    ? round(($season['statistics']['episodeFileCount'] ?? 0) / $season['statistics']['episodeCount'] * 100)
                    : 0,
            ], $s['seasons'] ?? []),
            'addedAt'          => isset($s['added']) ? new \DateTimeImmutable($s['added']) : null,
            'firstAired'       => isset($s['firstAired']) ? new \DateTimeImmutable($s['firstAired']) : null,
            'nextAiring'       => isset($s['nextAiring']) ? new \DateTimeImmutable($s['nextAiring']) : null,
            'previousAiring'   => isset($s['previousAiring']) ? new \DateTimeImmutable($s['previousAiring']) : null,
        ];
    }

    private function imageUrl(array $s, string $type): ?string
    {
        foreach ($s['images'] ?? [] as $img) {
            if ($img['coverType'] === $type) {
                return $img['remoteUrl'] ?? null;
            }
        }
        return null;
    }

    private function posterUrl(array $s): ?string
    {
        return $this->imageUrl($s, 'poster');
    }

    private function fanartUrl(array $s): ?string
    {
        return $this->imageUrl($s, 'fanart');
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $params, array $body): ?array
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json', 'Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $this->logger->warning("SonarrClient {$method} {$path} → HTTP {$code}");
            return null;
        }

        return json_decode($resp ?: '{}', true) ?? [];
    }

    /**
     * Like request() but always returns an array with 'ok' + Sonarr error details.
     */
    private function requestWithError(string $method, string $path, array $body): array
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json', 'Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => "Network error: {$err}"];
        }

        $data = json_decode($resp ?: '{}', true) ?? [];

        if ($code < 200 || $code >= 300) {
            $this->logger->warning("SonarrClient {$method} {$path} → HTTP {$code}", ['response' => $resp]);
            if (isset($data[0]['errorMessage'])) {
                $messages = array_map(fn($e) => ($e['propertyName'] ?? '') . ' : ' . ($e['errorMessage'] ?? '?'), $data);
                $msg = implode(' | ', $messages);
            } else {
                $msg = $data['message'] ?? "Sonarr error (HTTP {$code})";
            }
            return ['ok' => false, 'error' => $msg, 'details' => $data];
        }

        return ['ok' => true, 'data' => $data];
    }

    private function get(string $path, array $params = [], int $timeout = 10): ?array
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            $this->logger->warning("SonarrClient {$path} → HTTP {$code} {$err}");
            return null;
        }

        return json_decode($body, true);
    }

    private function delete(string $path, array $params = []): bool
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}"],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    private function deleteWithBody(string $path, array $body): bool
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}
