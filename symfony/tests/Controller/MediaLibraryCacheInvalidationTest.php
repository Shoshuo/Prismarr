<?php

namespace App\Tests\Controller;

use App\Controller\MediaController;
use App\Entity\ServiceInstance;
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
 * Regression for the write-through invalidation of the films library cache.
 *
 * normalizeMovie() embeds the movie file's quality + language into the cached
 * library entry, so editing a file's metadata must drop the cache or the
 * change stays hidden until the 45 s TTL. This locks two contracts:
 *   - a successful filmFileUpdate() invalidates the bound instance's cache,
 *   - a failed upstream write leaves the cache intact (no invalidation).
 */
#[AllowMockObjectsWithoutExpectations]
class MediaLibraryCacheInvalidationTest extends TestCase
{
    private function controller(RadarrClient $radarr, MediaLibraryCache $cache): MediaController
    {
        $controller = new MediaController(
            $radarr,
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(ConfigService::class),
            $this->createMock(ServiceInstanceProvider::class),
            new NullLogger(),
            $this->createMock(TranslatorInterface::class),
            $cache,
            $this->createMock(BazarrSubtitleIndex::class),
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        return $controller;
    }

    private function boundRadarr(): RadarrClient
    {
        $instance = $this->createMock(ServiceInstance::class);
        $instance->method('getSlug')->willReturn('radarr-1');

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getInstance')->willReturn($instance);
        $radarr->method('getMovieFile')->willReturn(['id' => 5, 'quality' => ['quality' => ['id' => 1]]]);
        return $radarr;
    }

    private function updateRequest(): Request
    {
        return Request::create(
            '/medias/radarr-1/films/files/5/update',
            'POST',
            content: json_encode(['quality' => ['quality' => ['id' => 7, 'name' => '1080p']]]),
        );
    }

    public function testMovieFileUpdateInvalidatesLibraryCacheOnSuccess(): void
    {
        $radarr = $this->boundRadarr();
        $radarr->method('updateMovieFile')->willReturn(['id' => 5]); // upstream write succeeds

        $cache = $this->createMock(MediaLibraryCache::class);
        $cache->expects($this->once())
            ->method('invalidate')
            ->with('radarr', 'radarr-1');

        $res = $this->controller($radarr, $cache)->filmFileUpdate(5, $this->updateRequest());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('"ok":true', (string) $res->getContent());
    }

    public function testMovieFileUpdateDoesNotInvalidateWhenUpstreamWriteFails(): void
    {
        $radarr = $this->boundRadarr();
        $radarr->method('updateMovieFile')->willReturn(null); // upstream write fails

        $cache = $this->createMock(MediaLibraryCache::class);
        $cache->expects($this->never())->method('invalidate');

        $res = $this->controller($radarr, $cache)->filmFileUpdate(5, $this->updateRequest());

        $this->assertStringContainsString('"ok":false', (string) $res->getContent());
    }
}
