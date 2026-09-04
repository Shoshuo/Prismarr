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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Interactive release search: when the upstream call doesn't complete (cURL
 * timeout, Sonarr/Radarr unreachable) the action returns 504 — not a 200 with
 * an empty list — so the frontend can say "the indexers took too long" instead
 * of a misleading "no releases found".
 */
#[AllowMockObjectsWithoutExpectations]
class MediaReleasesSearchTest extends TestCase
{
    private function controller(?RadarrClient $radarr = null, ?SonarrClient $sonarr = null): MediaController
    {
        $controller = new MediaController(
            $radarr ?? $this->createMock(RadarrClient::class),
            $sonarr ?? $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(ConfigService::class),
            $this->createMock(ServiceInstanceProvider::class),
            new NullLogger(),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(MediaLibraryCache::class),
            $this->createMock(BazarrSubtitleIndex::class),
        );
        // Empty container so AbstractController::json() falls back to a plain
        // JsonResponse instead of looking up the serializer service.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        return $controller;
    }

    public function testEpisodeReleasesReturns504WhenSonarrCallDidNotComplete(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getEpisode')->willReturn(null);
        $sonarr->method('getEpisodeReleases')->willReturn(null);

        $res = $this->controller(sonarr: $sonarr)->episodeReleases(1);
        $this->assertInstanceOf(JsonResponse::class, $res);
        $this->assertSame(504, $res->getStatusCode());
    }

    public function testSeasonReleasesReturns504WhenSonarrCallDidNotComplete(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getSerie')->willReturn(null);
        $sonarr->method('getQualityProfiles')->willReturn([]);
        $sonarr->method('getSeasonReleases')->willReturn(null);

        $res = $this->controller(sonarr: $sonarr)->seasonReleases(1, 1);
        $this->assertSame(504, $res->getStatusCode());
    }

    public function testFilmReleasesReturns504WhenRadarrCallDidNotComplete(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getMovie')->willReturn(null);
        $radarr->method('getReleasesForMovie')->willReturn(null);

        $res = $this->controller(radarr: $radarr)->filmReleases(1);
        $this->assertSame(504, $res->getStatusCode());
    }

    public function testEpisodeReleasesReturnsAJsonArrayWhenSonarrAnswersWithNoReleases(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getEpisode')->willReturn(null);
        $sonarr->method('getEpisodeReleases')->willReturn([]); // answered, but nothing

        $res = $this->controller(sonarr: $sonarr)->episodeReleases(1);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('[]', $res->getContent());
    }
}
