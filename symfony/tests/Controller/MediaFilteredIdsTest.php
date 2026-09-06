<?php

namespace App\Tests\Controller;

use App\Controller\MediaController;
use App\Service\ConfigService;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\MovieLibraryFilter;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SeriesLibraryFilter;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Issue #19 — the films/filter/ids and series/filter/ids endpoints are
 * thin resolvers that re-apply the current filter server-side and return
 * the matching ids. The frontend uses them to scope the bulk-filtered
 * actions to the WHOLE filter, not just the page of cards in the DOM.
 *
 * Exercises:
 *   - happy path returns the matching ids ordered by the filter
 *   - searchable_only=1 narrows to monitored + with-gaps entries
 *   - upstream client failure surfaces as a clean 5xx (via jsonClientError)
 */
#[AllowMockObjectsWithoutExpectations]
class MediaFilteredIdsTest extends TestCase
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

    public function testFilmsFilteredIdsReturnsMatchingIdsForActiveStatus(): void
    {
        $movies = [
            ['id' => 1, 'title' => 'Matrix',       'sortTitle' => 'matrix',       'hasFile' => true,  'monitored' => true,  'genres' => [], 'quality' => '1080p'],
            ['id' => 2, 'title' => 'Inception',    'sortTitle' => 'inception',    'hasFile' => false, 'monitored' => true,  'genres' => [], 'quality' => '720p'],
            ['id' => 3, 'title' => 'Pulp Fiction', 'sortTitle' => 'pulp fiction', 'hasFile' => false, 'monitored' => false, 'genres' => [], 'quality' => null],
        ];
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getMovies')->willReturn($movies);

        $request = Request::create('/medias/radarr-1/films/filter/ids?status=missing');

        $response = $this->controller(radarr: $radarr)->filmsFilteredIds($request, new MovieLibraryFilter());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame([2], $payload['ids'], 'only the missing (hasFile=false && monitored=true) movie qualifies');
        $this->assertSame(1, $payload['total']);
    }

    public function testSeriesFilteredIdsHonorsSearchableOnly(): void
    {
        // Search-filtered uses searchable_only=1 to keep only monitored series
        // with at least one missing episode — what a bulk indexer search
        // actually wants.
        $series = [
            ['id' => 10, 'title' => 'Show A', 'sortTitle' => 'show a', 'monitored' => true,  'status' => 'continuing', 'seriesType' => 'standard', 'percent' => 50,  'episodeCount' => 10, 'genres' => []],
            ['id' => 11, 'title' => 'Show B', 'sortTitle' => 'show b', 'monitored' => true,  'status' => 'continuing', 'seriesType' => 'standard', 'percent' => 100, 'episodeCount' => 10, 'genres' => []],
            ['id' => 12, 'title' => 'Show C', 'sortTitle' => 'show c', 'monitored' => false, 'status' => 'continuing', 'seriesType' => 'standard', 'percent' => 25,  'episodeCount' => 10, 'genres' => []],
        ];
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getSeries')->willReturn($series);

        $request = Request::create('/medias/sonarr-1/series/filter/ids?searchable_only=1');

        $response = $this->controller(sonarr: $sonarr)->seriesFilteredIds($request, new SeriesLibraryFilter());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame([10], $payload['ids'], 'only the monitored series with percent < 100 and at least one episode qualifies');
    }

    public function testSeriesFilteredIdsReturnsAllMatchingIdsWithoutSearchableOnly(): void
    {
        // Refresh-filtered (no searchable_only flag) returns every series that
        // matches the filter, regardless of monitoring or completeness.
        $series = [
            ['id' => 10, 'title' => 'Show A', 'sortTitle' => 'show a', 'monitored' => true,  'status' => 'continuing', 'seriesType' => 'anime',   'percent' => 100, 'episodeCount' => 10, 'genres' => []],
            ['id' => 11, 'title' => 'Show B', 'sortTitle' => 'show b', 'monitored' => true,  'status' => 'continuing', 'seriesType' => 'standard','percent' => 100, 'episodeCount' => 10, 'genres' => []],
            ['id' => 12, 'title' => 'Show C', 'sortTitle' => 'show c', 'monitored' => false, 'status' => 'ended',      'seriesType' => 'anime',   'percent' => 25,  'episodeCount' => 10, 'genres' => []],
        ];
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getSeries')->willReturn($series);

        $request = Request::create('/medias/sonarr-1/series/filter/ids?status=anime');

        $response = $this->controller(sonarr: $sonarr)->seriesFilteredIds($request, new SeriesLibraryFilter());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame([10, 12], $payload['ids'], 'every anime series matches regardless of monitoring');
    }

    public function testFilmsFilteredIdsBubblesClientFailureAsClientError(): void
    {
        // Radarr unreachable → the controller surfaces via jsonClientError,
        // a non-2xx response with a readable hint, not a 200 with empty ids.
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getMovies')->willThrowException(new \RuntimeException('boom'));

        $request = Request::create('/medias/radarr-1/films/filter/ids?status=missing');

        $response = $this->controller(radarr: $radarr)->filmsFilteredIds($request, new MovieLibraryFilter());

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode(), 'a client failure is surfaced, not swallowed');
    }
}
