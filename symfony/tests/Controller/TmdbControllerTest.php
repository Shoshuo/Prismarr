<?php

namespace App\Tests\Controller;

use App\Controller\TmdbController;
use App\Entity\ServiceInstance;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\ConfigService;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use App\Service\ServiceInstanceProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * v1.1.0 Phase D+E — pin the multi-instance contract of the
 * /decouverte/resolve and /decouverte/mes-recommandations endpoints.
 *
 * The Quick-Add picker (Phase E) reads the `instances` and `candidates`
 * keys returned by the resolve endpoint to decide whether a film/series
 * is already in some Radarr/Sonarr and which instances are still addable.
 * If the controller silently regresses to "default instance only", the
 * picker stops surfacing parallel instances and users get duplicates.
 */
#[AllowMockObjectsWithoutExpectations]
class TmdbControllerTest extends TestCase
{
    private function radarrInstance(string $slug, string $name, bool $isDefault = false): ServiceInstance
    {
        $i = new ServiceInstance(ServiceInstance::TYPE_RADARR, $slug, $name, 'http://r/' . $slug, 'k');
        $i->setEnabled(true);
        $i->setIsDefault($isDefault);
        return $i;
    }

    private function sonarrInstance(string $slug, string $name, bool $isDefault = false): ServiceInstance
    {
        $i = new ServiceInstance(ServiceInstance::TYPE_SONARR, $slug, $name, 'http://s/' . $slug, 'k');
        $i->setEnabled(true);
        $i->setIsDefault($isDefault);
        return $i;
    }

    /**
     * @param array<string, RadarrClient> $radarrPerSlug
     * @param array<string, SonarrClient> $sonarrPerSlug
     */
    private function controller(
        TmdbClient $tmdb,
        RadarrClient $radarrAutowired,
        SonarrClient $sonarrAutowired,
        ServiceInstanceProvider $instances,
        array $radarrPerSlug = [],
        array $sonarrPerSlug = [],
    ): TmdbController {
        // Route per-instance withInstance() to the matching mock, so each
        // instance can be configured with its own getMovies()/getRawAllSeries()
        // payload.
        if ($radarrPerSlug) {
            $radarrAutowired->method('withInstance')->willReturnCallback(
                fn(ServiceInstance $i) => $radarrPerSlug[$i->getSlug()] ?? $radarrAutowired
            );
        } else {
            $radarrAutowired->method('withInstance')->willReturn($radarrAutowired);
        }
        if ($sonarrPerSlug) {
            $sonarrAutowired->method('withInstance')->willReturnCallback(
                fn(ServiceInstance $i) => $sonarrPerSlug[$i->getSlug()] ?? $sonarrAutowired
            );
        } else {
            $sonarrAutowired->method('withInstance')->willReturn($sonarrAutowired);
        }

        $watchRepo  = $this->createMock(WatchlistItemRepository::class);

        $em         = $this->createMock(EntityManagerInterface::class);
        $config     = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn('configured');
        $logger     = $this->createMock(LoggerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TmdbController(
            $tmdb,
            $radarrAutowired,
            $sonarrAutowired,
            $watchRepo,
            $em,
            $config,
            $logger,
            $translator,
            $instances,
        );

        // AbstractController::json() reaches into the container even though
        // we don't use any of its services here. An empty container that
        // returns null on get() is enough to satisfy the typed property.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(null);
        $controller->setContainer($container);

        return $controller;
    }

    public function testResolveMovieReturnsOwnersAndCandidatesAcrossInstances(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getMovie')->willReturn([
            'id'           => 9999,
            'title'        => 'Pokémon Detective Pikachu',
            'release_date' => '2019-05-10',
            'poster_path'  => '/p.jpg',
        ]);

        // Two Radarr instances, both own the same tmdbId, only inst-a is default.
        $radarrA = $this->createMock(RadarrClient::class);
        $radarrA->method('getMovies')->willReturn([
            ['id' => 11, 'tmdbId' => 9999, 'hasFile' => true,  'monitored' => true],
            ['id' => 12, 'tmdbId' => 1234, 'hasFile' => false, 'monitored' => true],
        ]);
        $radarrB = $this->createMock(RadarrClient::class);
        $radarrB->method('getMovies')->willReturn([
            ['id' => 21, 'tmdbId' => 9999, 'hasFile' => false, 'monitored' => true],
        ]);
        $radarrAutowired = $this->createMock(RadarrClient::class);
        $sonarrAutowired = $this->createMock(SonarrClient::class);

        $instA = $this->radarrInstance('radarr-a', 'Radarr A', isDefault: true);
        $instB = $this->radarrInstance('radarr-b', 'Radarr B');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_RADARR ? [$instA, $instB] : []
        );
        $instances->method('hasAnyEnabled')->willReturn(true);

        $controller = $this->controller(
            $tmdb,
            $radarrAutowired,
            $sonarrAutowired,
            $instances,
            radarrPerSlug: ['radarr-a' => $radarrA, 'radarr-b' => $radarrB],
        );

        /** @var JsonResponse $resp */
        $resp = $controller->resolve('movie', 9999);
        $payload = json_decode((string) $resp->getContent(), true);

        $this->assertSame('film', $payload['type']);
        $this->assertSame(9999, $payload['tmdbId']);
        $this->assertTrue($payload['inLibrary'], 'Movie present in any instance must be flagged inLibrary');

        $this->assertCount(2, $payload['instances'], 'Both Radarr instances own this tmdbId — both must appear in instances');
        $slugs = array_column($payload['instances'], 'slug');
        $this->assertContains('radarr-a', $slugs);
        $this->assertContains('radarr-b', $slugs);
        // Status comes from hasFile/monitored on each instance independently.
        $byslug = array_column($payload['instances'], null, 'slug');
        $this->assertSame('downloaded', $byslug['radarr-a']['status']);
        $this->assertSame('missing', $byslug['radarr-b']['status']);

        $this->assertCount(2, $payload['candidates']);
        $cBySlug = array_column($payload['candidates'], null, 'slug');
        $this->assertTrue($cBySlug['radarr-a']['is_default']);
        $this->assertFalse($cBySlug['radarr-b']['is_default']);
    }

    public function testResolveMovieEmptyInstancesWhenNoOwnerFound(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getMovie')->willReturn([
            'id'           => 4242,
            'title'        => 'Foo',
            'release_date' => '2020-01-01',
            'poster_path'  => null,
        ]);

        $radarrA = $this->createMock(RadarrClient::class);
        $radarrA->method('getMovies')->willReturn([]); // empty library
        $radarrB = $this->createMock(RadarrClient::class);
        $radarrB->method('getMovies')->willReturn([]);
        $radarrAutowired = $this->createMock(RadarrClient::class);
        $sonarrAutowired = $this->createMock(SonarrClient::class);

        $instA = $this->radarrInstance('radarr-a', 'Radarr A', isDefault: true);
        $instB = $this->radarrInstance('radarr-b', 'Radarr B');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_RADARR ? [$instA, $instB] : []
        );

        $controller = $this->controller(
            $tmdb,
            $radarrAutowired,
            $sonarrAutowired,
            $instances,
            radarrPerSlug: ['radarr-a' => $radarrA, 'radarr-b' => $radarrB],
        );

        /** @var JsonResponse $resp */
        $resp = $controller->resolve('movie', 4242);
        $payload = json_decode((string) $resp->getContent(), true);

        $this->assertFalse($payload['inLibrary']);
        $this->assertSame([], $payload['instances'], 'No owner = empty instances array');
        // candidates still lists every enabled instance — Phase E picker
        // needs them to render the dropdown when adding a brand-new title.
        $this->assertCount(2, $payload['candidates']);
    }

    public function testResolveSeriesMatchesByTvdbAcrossSonarrInstances(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getTv')->willReturn([
            'id'              => 5555,
            'name'            => 'Dummy Show',
            'first_air_date'  => '2024-01-01',
            'poster_path'     => '/p.jpg',
            'external_ids'    => ['tvdb_id' => 7777],
            'genres'          => [['id' => 18]],
            'origin_country'  => ['US'],
        ]);

        // sonarr-a owns the series (tvdb match), sonarr-b doesn't.
        $sonarrA = $this->createMock(SonarrClient::class);
        $sonarrA->method('getRawAllSeries')->willReturn([
            ['id' => 1, 'tvdbId' => 7777, 'monitored' => true,
             'statistics' => ['episodeFileCount' => 10, 'episodeCount' => 10]],
        ]);
        $sonarrB = $this->createMock(SonarrClient::class);
        $sonarrB->method('getRawAllSeries')->willReturn([
            ['id' => 99, 'tvdbId' => 1, 'monitored' => true,
             'statistics' => ['episodeFileCount' => 0, 'episodeCount' => 5]],
        ]);
        $radarrAutowired = $this->createMock(RadarrClient::class);
        $sonarrAutowired = $this->createMock(SonarrClient::class);

        $instA = $this->sonarrInstance('sonarr-a', 'Sonarr A', isDefault: true);
        $instB = $this->sonarrInstance('sonarr-b', 'Sonarr B');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_SONARR ? [$instA, $instB] : []
        );

        $controller = $this->controller(
            $tmdb,
            $radarrAutowired,
            $sonarrAutowired,
            $instances,
            sonarrPerSlug: ['sonarr-a' => $sonarrA, 'sonarr-b' => $sonarrB],
        );

        /** @var JsonResponse $resp */
        $resp = $controller->resolve('tv', 5555);
        $payload = json_decode((string) $resp->getContent(), true);

        $this->assertSame('serie', $payload['type']);
        $this->assertSame(7777, $payload['tvdbId']);
        $this->assertSame('standard', $payload['seriesType']);

        $this->assertCount(1, $payload['instances'], 'Only sonarr-a owns tvdb 7777');
        $this->assertSame('sonarr-a', $payload['instances'][0]['slug']);
        $this->assertSame('downloaded', $payload['instances'][0]['status']);

        // candidates still shows BOTH so the picker can offer adding to sonarr-b.
        $this->assertCount(2, $payload['candidates']);
    }

    public function testMyRecommendationsDedupsSeedsAcrossRadarrInstances(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getMovieRecommendations')->willReturn(['results' => []]);
        $tmdb->method('getTvRecommendations')->willReturn(['results' => []]);

        // tmdbId 100 is mirrored on both Radarr instances; tmdbId 200 is on B only.
        // After dedup, recommendations should be requested once per tmdbId, not 3 times.
        $radarrA = $this->createMock(RadarrClient::class);
        $radarrA->method('getMovies')->willReturn([
            ['id' => 1, 'tmdbId' => 100, 'added' => '2026-04-01T00:00:00Z', 'hasFile' => true,  'monitored' => true],
        ]);
        $radarrB = $this->createMock(RadarrClient::class);
        $radarrB->method('getMovies')->willReturn([
            ['id' => 2, 'tmdbId' => 100, 'added' => '2026-05-01T00:00:00Z', 'hasFile' => false, 'monitored' => true], // duplicate
            ['id' => 3, 'tmdbId' => 200, 'added' => '2026-04-15T00:00:00Z', 'hasFile' => false, 'monitored' => true],
        ]);
        $radarrAutowired = $this->createMock(RadarrClient::class);
        $sonarrAutowired = $this->createMock(SonarrClient::class);
        $sonarrAutowired->method('getRawAllSeries')->willReturn([]);

        $instA = $this->radarrInstance('radarr-a', 'Radarr A', isDefault: true);
        $instB = $this->radarrInstance('radarr-b', 'Radarr B');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_RADARR
                ? [$instA, $instB]
                : []
        );

        $controller = $this->controller(
            $tmdb,
            $radarrAutowired,
            $sonarrAutowired,
            $instances,
            radarrPerSlug: ['radarr-a' => $radarrA, 'radarr-b' => $radarrB],
        );

        /** @var JsonResponse $resp */
        $resp = $controller->myRecommendations();
        $payload = json_decode((string) $resp->getContent(), true);

        // Two unique tmdbIds across both Radarr instances → 2 seeds, not 3.
        $this->assertSame(2, $payload['seeds']);
        $this->assertSame([], $payload['results'], 'No TMDb recommendations configured = empty results');
    }
}
