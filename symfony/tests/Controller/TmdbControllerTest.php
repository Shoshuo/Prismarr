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
        $radarrA->method('getRawMovies')->willReturn([
            ['id' => 11, 'tmdbId' => 9999, 'hasFile' => true,  'monitored' => true],
            ['id' => 12, 'tmdbId' => 1234, 'hasFile' => false, 'monitored' => true],
        ]);
        $radarrB = $this->createMock(RadarrClient::class);
        $radarrB->method('getRawMovies')->willReturn([
            ['id' => 21, 'tmdbId' => 9999, 'hasFile' => false, 'monitored' => true],
        ]);
        $radarrAutowired = $this->createMock(RadarrClient::class);
        $sonarrAutowired = $this->createMock(SonarrClient::class);

        $instA = $this->radarrInstance('radarr-a', 'Radarr A', isDefault: true);
        $instA->setDefaultQualityProfileId(7); // issue #5 — must surface in candidates
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
        // Issue #5 — each candidate carries its instance's default quality profile
        // so the quick-add modal can preselect it (null when unset).
        $this->assertSame(7, $cBySlug['radarr-a']['defaultQualityProfileId']);
        $this->assertNull($cBySlug['radarr-b']['defaultQualityProfileId']);
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
        $radarrA->method('getRawMovies')->willReturn([]); // empty library
        $radarrB = $this->createMock(RadarrClient::class);
        $radarrB->method('getRawMovies')->willReturn([]);
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
        $radarrA->method('getRawMovies')->willReturn([
            ['id' => 1, 'tmdbId' => 100, 'added' => '2026-04-01T00:00:00Z', 'hasFile' => true,  'monitored' => true],
        ]);
        $radarrB = $this->createMock(RadarrClient::class);
        $radarrB->method('getRawMovies')->willReturn([
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

    private function smartSearchController(TmdbClient $tmdb, array $ownedTmdbIds = []): TmdbController
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn(array_map(
            fn(int $id) => ['id' => 100 + $id, 'tmdbId' => $id, 'hasFile' => true, 'monitored' => true],
            $ownedTmdbIds,
        ));
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $inst = $this->radarrInstance('radarr-1', 'Radarr', isDefault: true);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === ServiceInstance::TYPE_RADARR ? [$inst] : []
        );
        $instances->method('getDefault')->willReturn($inst);

        return $this->controller($tmdb, $radarr, $this->createMock(SonarrClient::class), $instances);
    }

    private function smartSearchMulti(): array
    {
        return ['page' => 1, 'total_pages' => 1, 'results' => [
            ['media_type' => 'movie', 'id' => 624860, 'title' => 'The Matrix Resurrections', 'popularity' => 900, 'vote_count' => 6000, 'vote_average' => 6.4, 'release_date' => '2021-12-16', 'genre_ids' => [28]],
            ['media_type' => 'person', 'id' => 6384, 'name' => 'Keanu Reeves', 'popularity' => 40, 'known_for_department' => 'Acting', 'known_for' => [['title' => 'The Matrix'], ['title' => 'John Wick']]],
            ['media_type' => 'movie', 'id' => 603, 'title' => 'The Matrix', 'popularity' => 80, 'vote_count' => 25000, 'vote_average' => 8.2, 'release_date' => '1999-03-30', 'genre_ids' => [28, 878]],
            ['media_type' => 'tv', 'id' => 99, 'name' => 'Matrix Theories', 'popularity' => 2, 'vote_count' => 5, 'first_air_date' => '2010-01-01', 'genre_ids' => [99]],
        ]];
    }

    public function testSmartSearchRanksPullsPeopleAndFlagsTheLibrary(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('searchMulti')->willReturn($this->smartSearchMulti());
        $tmdb->method('getGenresMovies')->willReturn(['genres' => [['id' => 28, 'name' => 'Action'], ['id' => 878, 'name' => 'Science-Fiction']]]);

        $res = $this->smartSearchController($tmdb, ownedTmdbIds: [603])
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'the matrix']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertSame('search', $data['mode']);
        self::assertSame(603, $data['best']['id'], 'the exact title beats a more popular partial match');
        self::assertTrue($data['best']['in_library']);
        self::assertSame(['Action', 'Science-Fiction'], $data['best']['genres']);
        self::assertSame('Keanu Reeves', $data['people'][0]['name']);
        self::assertSame(['The Matrix', 'John Wick'], $data['people'][0]['titles']);
        self::assertSame(3, $data['total']);
        self::assertNotContains('person', array_column($data['results'], 'type'));
    }

    public function testSmartSearchFiltersTypeAndHidesOwned(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('searchMulti')->willReturn($this->smartSearchMulti());
        $tmdb->method('getGenresMovies')->willReturn(['genres' => []]);

        $res = $this->smartSearchController($tmdb, ownedTmdbIds: [603])
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'matrix', 'type' => 'movie', 'hide_owned' => '1', 'sort' => 'bogus']));
        $data = json_decode((string) $res->getContent(), true);

        $ids = array_merge([$data['best']['id']], array_column($data['results'], 'id'));
        self::assertSame([624860], $ids, 'series dropped by the type filter, owned movie hidden');
    }

    public function testSmartSearchBrowsesWithFiltersWhenThereIsNoQuery(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->expects(self::never())->method('searchMulti');
        $tmdb->expects(self::never())->method('discoverMovies');
        $tmdb->expects(self::once())->method('discoverTv')
            ->with(self::callback(fn(array $p) => $p['with_genres'] === 18 && $p['first_air_date.gte'] === '2015-01-01' && $p['sort_by'] === 'popularity.desc'))
            ->willReturn(['page' => 1, 'total_pages' => 3, 'results' => [['id' => 1396, 'name' => 'Breaking Bad', 'popularity' => 300, 'first_air_date' => '2008-01-20']]]);

        $res = $this->smartSearchController($tmdb)
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['type' => 'tv', 'genre' => '18', 'year_min' => '2015']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertSame('discover', $data['mode']);
        self::assertSame('tv', $data['results'][0]['type']);
        self::assertSame(3, $data['total_pages']);
    }

    private function typoTmdb(): TmdbClient
    {
        $interstellar = ['media_type' => 'movie', 'id' => 157336, 'title' => 'Interstellar', 'popularity' => 150, 'vote_count' => 35000, 'release_date' => '2014-11-05', 'genre_ids' => [878]];
        $tmdb = $this->createMock(TmdbClient::class);
        // TMDb has no typo tolerance: the typo finds nothing, a shorter start
        // of the word ("interst…") finds the real title.
        $tmdb->method('searchMulti')->willReturnCallback(fn(string $q) => match (true) {
            strtolower($q) === 'interstelar' => ['page' => 1, 'total_pages' => 1, 'results' => []],
            strtolower($q) === 'interstellr' => ['page' => 1, 'total_pages' => 1, 'results' => [
                ['media_type' => 'movie', 'id' => 5, 'title' => 'Stellar Docs', 'popularity' => 20, 'vote_count' => 300, 'release_date' => '2020-01-01'],
            ]],
            str_starts_with(strtolower($q), 'interst') => ['page' => 1, 'total_pages' => 1, 'results' => [$interstellar]],
            default => ['page' => 1, 'total_pages' => 1, 'results' => []],
        });
        $tmdb->method('getGenresMovies')->willReturn(['genres' => [['id' => 878, 'name' => 'Science-Fiction']]]);

        return $tmdb;
    }

    public function testSmartSearchCorrectsATypoWithNoResult(): void
    {
        $res = $this->smartSearchController($this->typoTmdb())
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'interstelar']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertSame(157336, $data['best']['id']);
        self::assertSame('interstelar', $data['corrected_from']);
        self::assertSame('Interstellar', $data['corrected_to']);
        self::assertNull($data['suggestion']);
    }

    public function testSmartSearchOnlySuggestsWhenSomethingWasFound(): void
    {
        $res = $this->smartSearchController($this->typoTmdb())
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'interstellr']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertSame(5, $data['best']['id'], 'the literal results stay');
        self::assertNull($data['corrected_from']);
        self::assertSame('Interstellar', $data['suggestion']);
    }

    public function testSmartSearchCorrectsWhenOnlyObscureTitlesMatchLiterally(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('searchMulti')->willReturnCallback(fn(string $q) => match (strtolower($q)) {
            'star wors' => ['page' => 1, 'total_pages' => 1, 'results' => [
                ['media_type' => 'movie', 'id' => 7, 'title' => 'Star Worse', 'popularity' => 0.3, 'vote_count' => 1, 'release_date' => '2019-01-01'],
            ]],
            'star wars' => ['page' => 1, 'total_pages' => 1, 'results' => [
                ['media_type' => 'movie', 'id' => 11, 'title' => 'Star Wars', 'popularity' => 90, 'vote_count' => 20000, 'release_date' => '1977-05-25'],
            ]],
            default => ['page' => 1, 'total_pages' => 1, 'results' => [
                ['media_type' => 'movie', 'id' => 11, 'title' => 'Star Wars', 'popularity' => 90, 'vote_count' => 20000, 'release_date' => '1977-05-25'],
            ]],
        });
        $tmdb->method('getGenresMovies')->willReturn(['genres' => []]);

        $res = $this->smartSearchController($tmdb)
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'star wors']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertSame(11, $data['best']['id']);
        self::assertSame('star wors', $data['corrected_from']);
    }

    public function testSmartSearchPutsAKnownPersonFirstAndCollapsesNamesakes(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('searchMulti')->willReturn(['page' => 1, 'total_pages' => 1, 'results' => [
            ['media_type' => 'movie', 'id' => 1, 'title' => "Inside Christopher Nolan's Films", 'popularity' => 4, 'vote_count' => 20, 'release_date' => '2023-01-01'],
            ['media_type' => 'person', 'id' => 525, 'name' => 'Christopher Nolan', 'popularity' => 25, 'known_for_department' => 'Directing', 'known_for' => [['title' => 'Inception']]],
            ['media_type' => 'person', 'id' => 9001, 'name' => 'Christopher Nolan', 'popularity' => 1.6, 'known_for_department' => 'Sound'],
        ]]);
        $tmdb->method('getGenresMovies')->willReturn(['genres' => []]);

        $res = $this->smartSearchController($tmdb)
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'christopher nolan']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertSame(525, $data['best_person']['id']);
        self::assertNull($data['best']);
        self::assertSame([], $data['people'], 'the namesake collapsed into the best-known one');
        self::assertSame(1, $data['results'][0]['id'], 'the title moved back to the grid');
    }

    public function testSmartSearchExactSkipsTheCorrection(): void
    {
        $res = $this->smartSearchController($this->typoTmdb())
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => 'interstelar', 'exact' => '1']));
        $data = json_decode((string) $res->getContent(), true);

        self::assertNull($data['best']);
        self::assertNull($data['suggestion']);
        self::assertNull($data['corrected_from']);
    }

    public function testSmartSearchCapsTheQueryLength(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->expects(self::atLeastOnce())->method('searchMulti')
            ->with(self::callback(fn(string $q) => mb_strlen($q) <= 100))
            ->willReturn(['page' => 1, 'total_pages' => 1, 'results' => []]);

        $this->smartSearchController($tmdb)
            ->smartSearch(new \Symfony\Component\HttpFoundation\Request(['q' => str_repeat('matrix ', 60), 'exact' => '1']));
    }
}
