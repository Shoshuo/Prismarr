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
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * the global search dropdown renders results
 * client-side from a JSON payload (it can't reach for the `subtitle_status`
 * Twig function), so MediaController::globalSearch() must attach a reduced
 * `subtitle: {state, count}` key to every in-library result itself, omitted
 * for the 'hidden' state and for online/TMDb-only results.
 *
 * `attachSubtitleStatus()` is exercised directly (pure unit, no kernel) for
 * the missing/complete/hidden/non-numeric-id cases: a WebTestCase can't mock
 * BazarrSubtitleIndex via `container->set()` here because AbstractWebTestCase
 * ::setUp() calls EntityManager::flush() at least once (seeding the admin
 * user), and turbo-bundle's `turbo.doctrine.event_listener` reacts to ANY
 * flush by resolving the `twig` service to render its broadcast templates —
 * which eagerly builds Twig's full extension set (extensions are
 * constructor-injected into `addExtension()` calls, so PHP must construct
 * every one of them up front). `SubtitleBadgeExtension` is one such
 * extension, so BazarrSubtitleIndex (and BazarrClient underneath it) are
 * ALREADY resolved as private container services before any test body runs,
 * which trips Symfony's TestContainer "already initialized" guard the
 * instant you try to `set()` a replacement. This is a pre-existing
 * Turbo+Twig+Doctrine interaction, not something this task introduced.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaControllerSearchSubtitleTest extends TestCase
{
    private function callAttachSubtitleStatus(MediaController $controller, array $result, string $kind): array
    {
        $ref = new \ReflectionMethod($controller, 'attachSubtitleStatus');
        $ref->setAccessible(true);

        return $ref->invoke($controller, $result, $kind);
    }

    private function makeController(BazarrSubtitleIndex $bazarrIndex): MediaController
    {
        return new MediaController(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(ConfigService::class),
            $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(MediaLibraryCache::class),
            $bazarrIndex,
        );
    }

    public function testMissingStateAttachesReducedSubtitleKey(): void
    {
        $bazarr = $this->createMock(BazarrSubtitleIndex::class);
        $bazarr->expects($this->once())->method('movieStatus')->with(42)->willReturn(['state' => 'missing', 'count' => 3]);

        $result = $this->callAttachSubtitleStatus($this->makeController($bazarr), ['id' => 42, 'title' => 'Interstellar'], 'movie');

        $this->assertSame(['state' => 'missing', 'count' => 3], $result['subtitle']);
    }

    public function testCompleteStateAttachesReducedSubtitleKey(): void
    {
        $bazarr = $this->createMock(BazarrSubtitleIndex::class);
        $bazarr->expects($this->once())->method('seriesStatus')->with(7)->willReturn(['state' => 'complete', 'count' => 0]);

        $result = $this->callAttachSubtitleStatus($this->makeController($bazarr), ['id' => 7, 'title' => 'Dune Chronicles'], 'series');

        $this->assertSame(['state' => 'complete', 'count' => 0], $result['subtitle']);
    }

    public function testHiddenStateOmitsSubtitleKeyEntirely(): void
    {
        $bazarr = $this->createMock(BazarrSubtitleIndex::class);
        $bazarr->expects($this->once())->method('movieStatus')->with(99)->willReturn(['state' => 'hidden', 'count' => 0]);

        $result = $this->callAttachSubtitleStatus($this->makeController($bazarr), ['id' => 99, 'title' => 'Unconfigured'], 'movie');

        $this->assertArrayNotHasKey('subtitle', $result);
    }

    public function testMissingIdIsLeftUntouched(): void
    {
        // Defensive: buildMovieSearchIndex()/buildSeriesSearchIndex() always set
        // 'id' (possibly null if Radarr/Sonarr omitted it), but this must never
        // call into BazarrSubtitleIndex with a garbage argument.
        $bazarr = $this->createMock(BazarrSubtitleIndex::class);
        $bazarr->expects($this->never())->method('movieStatus');
        $bazarr->expects($this->never())->method('seriesStatus');

        $result = $this->callAttachSubtitleStatus($this->makeController($bazarr), ['id' => null, 'title' => 'No Id'], 'movie');

        $this->assertArrayNotHasKey('subtitle', $result);
    }
}
