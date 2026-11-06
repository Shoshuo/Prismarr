<?php

namespace App\Tests\Controller;

use App\Controller\MediaController;
use App\Service\ConfigService;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review 2026-11-06 — the data-affecting *arr write paths (H1, H2, H3, H4,
 * H5, AC-10). Each test pins what the controller hands the client, because
 * every one of these shipped as a 200 "ok" while sending the wrong thing.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaArrWritePathsTest extends TestCase
{
    private function controller(?RadarrClient $radarr = null, ?SonarrClient $sonarr = null): MediaController
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new MediaController(
            $radarr ?? $this->createMock(RadarrClient::class),
            $sonarr ?? $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(ConfigService::class),
            $this->createMock(ServiceInstanceProvider::class),
            new NullLogger(),
            $translator,
            $this->createMock(MediaLibraryCache::class),
            $this->createMock(BazarrSubtitleIndex::class),
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        return $controller;
    }

    /** @param array<string, mixed> $body */
    private static function post(array $body): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    // ── H1: Sonarr library import must adopt the scanned folder ─────────────

    public function testSeriesAddForwardsTheScannedFolderAsPath(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('lookupSeriesRaw')->willReturn([['tvdbId' => 81189, 'title' => 'Breaking Bad', 'qualityProfileId' => 0, 'seriesType' => 'standard']]);
        $sonarr->expects($this->once())
            ->method('addSeries')
            ->with($this->callback(fn(array $raw) => ($raw['path'] ?? null) === '/tv/Breaking Bad (2008)'
                && ($raw['rootFolderPath'] ?? null) === '/tv'))
            ->willReturn(['id' => 1]);

        $r = $this->controller(sonarr: $sonarr)->seriesAdd(self::post([
            'tvdbId' => 81189, 'rootFolderPath' => '/tv', 'path' => '/tv/Breaking Bad (2008)', 'qualityProfileId' => 1,
        ]));

        $this->assertTrue(json_decode((string) $r->getContent(), true)['ok']);
    }

    public function testSeriesAddWithoutPathLetsSonarrBuildTheFolder(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('lookupSeriesRaw')->willReturn([['tvdbId' => 81189, 'title' => 'Breaking Bad']]);
        $sonarr->expects($this->once())
            ->method('addSeries')
            ->with($this->callback(fn(array $raw) => !array_key_exists('path', $raw)))
            ->willReturn(['id' => 1]);

        $this->controller(sonarr: $sonarr)->seriesAdd(self::post(['tvdbId' => 81189, 'rootFolderPath' => '/tv', 'qualityProfileId' => 1]));
    }

    public function testSeriesAddLooksTheSeriesUpOnce(): void
    {
        // AC-9: the add used to call /series/lookup twice (normalized then
        // raw) on a 4 s SkyHook budget; one raw lookup is enough.
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->once())->method('lookupSeriesRaw')->willReturn([['tvdbId' => 81189, 'title' => 'x']]);
        $sonarr->expects($this->never())->method('lookupSeries');
        $sonarr->method('addSeries')->willReturn(['id' => 1]);

        $this->controller(sonarr: $sonarr)->seriesAdd(self::post(['tvdbId' => 81189, 'qualityProfileId' => 1]));
    }

    // ── H2: collection follow toggle must not PUT a partial body ────────────

    public function testCollectionMonitorUsesTheNullSafeBulkEndpoint(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())->method('bulkUpdateCollections')->with([5], false)->willReturn(true);
        $radarr->expects($this->never())->method('updateCollection');

        $r = $this->controller(radarr: $radarr)->filmCollectionMonitor(5, self::post(['monitored' => false]));

        $payload = json_decode((string) $r->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['monitored']);
    }

    // ── H3: queue import goes through Radarr's preview ──────────────────────

    public function testQueueImportUsesThePreviewFlowWithTheDownloadId(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getQueue')->willReturn([
            ['id' => 42, 'movieId' => 7, 'outputPath' => '/dl/Movie.2024.1080p', 'downloadId' => 'ABCDEF0123'],
        ]);
        $radarr->expects($this->once())
            ->method('manualImportFromQueueItem')
            ->with('/dl/Movie.2024.1080p', 'ABCDEF0123')
            ->willReturn(['ok' => true, 'cmdId' => 99, 'imported' => 2, 'skipped' => 1, 'reasons' => ['sample.mkv: Sample']]);
        $radarr->expects($this->never())->method('manualImport');
        $radarr->expects($this->never())->method('getRawQueue');

        $r = $this->controller(radarr: $radarr)->filmQueueImport(42);

        $payload = json_decode((string) $r->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(99, $payload['cmdId']);
        $this->assertSame(2, $payload['imported']);
        $this->assertSame(1, $payload['skipped']);
    }

    public function testQueueImportSurfacesAQueueFetchFailureInsteadOfNotFound(): void
    {
        // C1: getQueue() returns [] on transport failure; the controller must
        // read getLastError() instead of answering "queue item not found".
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getQueue')->willReturn([]);
        $radarr->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/api/v3/queue', 'message' => 'Failed to connect']);

        $r = $this->controller(radarr: $radarr)->filmQueueImport(42);

        $this->assertGreaterThanOrEqual(500, $r->getStatusCode());
        $this->assertSame('Failed to connect', json_decode((string) $r->getContent(), true)['error']);
    }

    // ── H4: the file managers read the language table from the *arr ────────

    public function testFilmsLanguagesJsonProxiesRadarrLanguageTable(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getLanguages')->willReturn([['id' => 8, 'name' => 'Japanese'], ['id' => 1, 'name' => 'English']]);

        $r = $this->controller(radarr: $radarr)->filmsLanguagesJson();

        $this->assertSame([['id' => 8, 'name' => 'Japanese'], ['id' => 1, 'name' => 'English']], json_decode((string) $r->getContent(), true));
    }

    public function testSeriesLanguagesJsonProxiesSonarrLanguageTable(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getLanguages')->willReturn([['id' => 27, 'name' => 'Hindi']]);

        $r = $this->controller(sonarr: $sonarr)->seriesLanguagesJson();

        $this->assertSame([['id' => 27, 'name' => 'Hindi']], json_decode((string) $r->getContent(), true));
    }

    // ── H5: an empty language list must never be stored ─────────────────────

    public function testSeriesFileBulkUpdateIgnoresAnEmptyLanguageList(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getEpisodeFile')->willReturn(['id' => 3, 'languages' => [['id' => 8, 'name' => 'Japanese']], 'releaseType' => 'unknown']);
        $sonarr->expects($this->once())
            ->method('bulkUpdateEpisodeFilesFull')
            ->with($this->callback(fn(array $files) => $files[0]['languages'] === [['id' => 8, 'name' => 'Japanese']]
                && $files[0]['releaseGroup'] === 'GRP'))
            ->willReturn(['ok' => true]);

        $this->controller(sonarr: $sonarr)->seriesFilesBulkUpdate(self::post(['fileId' => 3, 'languages' => [], 'releaseGroup' => 'GRP']));
    }

    public function testFilmFileUpdateIgnoresAnEmptyLanguageList(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getMovieFile')->willReturn(['id' => 3, 'languages' => [['id' => 18, 'name' => 'Dutch']]]);
        $radarr->expects($this->once())
            ->method('updateMovieFile')
            ->with(3, $this->callback(fn(array $file) => $file['languages'] === [['id' => 18, 'name' => 'Dutch']]))
            ->willReturn(['id' => 3]);

        $this->controller(radarr: $radarr)->filmFileUpdate(3, self::post(['languages' => []]));
    }

    // ── AC-10: path / root-folder edits can move the files ──────────────────

    public function testFilmEditForwardsMoveFiles(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getMovie')->willReturn(['id' => 7]);
        $radarr->method('getRawMovie')->willReturn(['id' => 7, 'title' => 'x', 'path' => '/old']);
        $radarr->expects($this->once())
            ->method('updateMovie')
            ->with(7, $this->callback(fn(array $m) => $m['path'] === '/new'), true)
            ->willReturn(['id' => 7]);

        $this->controller(radarr: $radarr)->filmEdit(7, self::post(['path' => '/new', 'moveFiles' => true]));
    }

    public function testFilmEditDefaultsToNotMovingFiles(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getMovie')->willReturn(['id' => 7]);
        $radarr->method('getRawMovie')->willReturn(['id' => 7]);
        $radarr->expects($this->once())->method('updateMovie')->with(7, $this->anything(), false)->willReturn(['id' => 7]);

        $this->controller(radarr: $radarr)->filmEdit(7, self::post(['monitored' => true]));
    }

    public function testFilmsBulkEditForwardsMoveFiles(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())
            ->method('bulkUpdateMovies')
            ->with([1, 2], ['rootFolderPath' => '/new', 'moveFiles' => true])
            ->willReturn(true);

        $this->controller(radarr: $radarr)->filmsBulkEdit(self::post(['movieIds' => [1, 2], 'rootFolderPath' => '/new', 'moveFiles' => true]));
    }

    public function testSerieEditForwardsMoveFiles(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getRawSeries')->willReturn(['id' => 9, 'title' => 'x', 'path' => '/old']);
        $sonarr->expects($this->once())
            ->method('updateSeries')
            ->with(9, $this->callback(fn(array $s) => $s['path'] === '/new'), true)
            ->willReturn(['id' => 9]);

        $this->controller(sonarr: $sonarr)->serieEdit(9, self::post(['path' => '/new', 'moveFiles' => true]));
    }

    public function testSeriesBulkEditForwardsMoveFiles(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->once())
            ->method('bulkEditSeries')
            ->with(['seriesIds' => [4], 'rootFolderPath' => '/new', 'moveFiles' => true])
            ->willReturn(['ok' => true]);

        $this->controller(sonarr: $sonarr)->seriesBulkEdit(self::post(['seriesIds' => [4], 'rootFolderPath' => '/new', 'moveFiles' => true]));
    }

    // ── MT-10: series bulk search / refresh go through one endpoint ─────────

    public function testSeriesBulkRefreshSendsOneCommandWithAllIds(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->once())
            ->method('sendCommand')
            ->with('RefreshSeries', ['seriesIds' => [4, 9]])
            ->willReturn(['id' => 77]);

        $r = $this->controller(sonarr: $sonarr)->seriesBulkRefresh(self::post(['seriesIds' => [4, 9]]));

        $payload = json_decode((string) $r->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(77, $payload['cmdId']);
    }

    public function testSeriesBulkSearchCountsRejectedCommands(): void
    {
        // The page used to fire one POST per series straight at Sonarr and
        // count every rejection as "launched".
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->exactly(3))
            ->method('sendCommand')
            ->willReturnCallback(fn(string $name, array $body) => ($body['seriesId'] ?? 0) === 9 ? null : ['id' => 100 + $body['seriesId']]);

        $r = $this->controller(sonarr: $sonarr)->seriesBulkSearch(self::post(['seriesIds' => [4, 9, 12]]));

        $payload = json_decode((string) $r->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(2, $payload['launched']);
        $this->assertSame(1, $payload['failed']);
        $this->assertSame([104, 112], $payload['cmdIds']);
    }

    public function testSeriesBulkSearchWithEveryCommandRejectedIsNotOk(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('sendCommand')->willReturn(null);
        $sonarr->method('getLastError')->willReturn(['code' => 0, 'method' => 'POST', 'path' => '/api/v3/command', 'message' => 'Failed to connect']);

        $r = $this->controller(sonarr: $sonarr)->seriesBulkSearch(self::post(['seriesIds' => [4]]));

        $this->assertGreaterThanOrEqual(500, $r->getStatusCode());
        $this->assertFalse(json_decode((string) $r->getContent(), true)['ok']);
    }

    public function testFilmQueueSurfacesAnOutageInsteadOfAnEmptyQueue(): void
    {
        // C1 / MT-6: [] on a Radarr blip made the page paint every queued
        // card "Downloaded"; the poll must get an error envelope instead.
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getQueue')->willReturn([]);
        $radarr->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/api/v3/queue', 'message' => 'Failed to connect']);

        $r = $this->controller(radarr: $radarr)->filmQueue();

        $this->assertSame(502, $r->getStatusCode());
    }

    public function testFilmQueueEmptyWithoutAnErrorIsAnEmptyList(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getQueue')->willReturn([]);
        $radarr->method('getLastError')->willReturn(null);

        $r = $this->controller(radarr: $radarr)->filmQueue();

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame([], json_decode((string) $r->getContent(), true));
    }
}
