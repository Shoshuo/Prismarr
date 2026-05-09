<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TorrentResolverService;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TorrentResolverServiceTest extends TestCase
{
    /**
     * Mock provider that returns a default Radarr instance with slug 'radarr-1'
     * and a default Sonarr instance with slug 'sonarr-1'. Used by every resolve()
     * test case to mimic a fresh single-instance install.
     */
    private function instancesMock(): ServiceInstanceProvider
    {
        $radarrInst = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://r:7878', 'k');
        $sonarrInst = new ServiceInstance(ServiceInstance::TYPE_SONARR, 'sonarr-1', 'Sonarr', 'http://s:8989', 'k');
        $mock = $this->createMock(ServiceInstanceProvider::class);
        $mock->method('getDefault')->willReturnCallback(
            fn(string $type) => match ($type) {
                ServiceInstance::TYPE_RADARR => $radarrInst,
                ServiceInstance::TYPE_SONARR => $sonarrInst,
                default                       => null,
            }
        );
        return $mock;
    }

    // ── parseReleaseName ────────────────────────────────────────────────

    public function testParsesTitleAndYearFromStandardName(): void
    {
        $r = TorrentResolverService::parseReleaseName('Dune.Part.Two.2024.1080p.BluRay.x264');
        $this->assertSame('Dune Part Two', $r['title']);
        $this->assertSame(2024, $r['year']);
    }

    public function testParsesUnderscoresAndDotsAsSpaces(): void
    {
        $r = TorrentResolverService::parseReleaseName('Dune_Part_Two.2024.2160p');
        $this->assertSame('Dune Part Two', $r['title']);
        $this->assertSame(2024, $r['year']);
    }

    public function testYearInsideTitleDoesNotShadowRealYear(): void
    {
        // "1917" is part of the title, 2019 is the release year.
        $r = TorrentResolverService::parseReleaseName('1917.2019.1080p.BluRay');
        $this->assertSame('1917', $r['title']);
        $this->assertSame(2019, $r['year']);
    }

    public function testParsesWithoutYearCutsAtQualityToken(): void
    {
        $r = TorrentResolverService::parseReleaseName('The.Matrix.BluRay.x264');
        $this->assertSame('The Matrix', $r['title']);
        $this->assertNull($r['year']);
    }

    public function testParsesSeriesEpisodeAsNoYear(): void
    {
        $r = TorrentResolverService::parseReleaseName('Breaking.Bad.S01E01.720p.HDTV');
        $this->assertSame('Breaking Bad', $r['title']);
        $this->assertNull($r['year']);
    }

    public function testParsesJunkName(): void
    {
        $r = TorrentResolverService::parseReleaseName('random.garbage.no.markers');
        // No year marker, cut at first quality token (none) → keeps everything as title
        $this->assertSame('random garbage no markers', $r['title']);
        $this->assertNull($r['year']);
    }

    // ── normalizeTitle ──────────────────────────────────────────────────

    public function testNormalizeStripsAccents(): void
    {
        // Switched from iconv to intl Transliterator at session 13 — clean
        // ASCII fold, no stray spaces around accented chars.
        $this->assertSame('pokemon', TorrentResolverService::normalizeTitle('Pokémon'));
    }

    public function testNormalizeStripsPunctuation(): void
    {
        $this->assertSame('l echappee', TorrentResolverService::normalizeTitle("L'Échappée"));
    }

    public function testNormalizeFoldsAccentedTitleToReleaseSpelling(): void
    {
        // Real case: "La traversée" stored in Radarr, "La.Traversee" in the
        // release name. After normalize both must collapse to "la traversee".
        $this->assertSame('la traversee', TorrentResolverService::normalizeTitle('La traversée'));
        $this->assertSame('la traversee', TorrentResolverService::normalizeTitle('La.Traversee'));
    }

    public function testNormalizeLowercases(): void
    {
        $this->assertSame('the matrix', TorrentResolverService::normalizeTitle('THE MATRIX'));
    }

    // ── resolve() with mocked Radarr/Sonarr ─────────────────────────────

    public function testResolveReturnsBestMatchByYear(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            ['id' => 1, 'title' => 'Dune',           'year' => 1984],
            ['id' => 2, 'title' => 'Dune',           'year' => 2021],
            ['id' => 3, 'title' => 'Dune Part Two',  'year' => 2024],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        // Release says 2021 → must return the 2021 one, not the 1984.
        $r = $svc->resolve('radarr', 'Dune.2021.1080p.BluRay');
        $this->assertTrue($r['found']);
        $this->assertSame(2, $r['id']);
    }

    public function testResolveIgnoresShortNeedleContainsMatch(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        // Needle "It" (2 chars) should NOT match "Split" even though "It" is in "Split".
        $radarr->method('getRawMovies')->willReturn([
            ['id' => 42, 'title' => 'Split', 'year' => 2016],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'It.2017.1080p.BluRay');
        $this->assertFalse($r['found']);
    }

    public function testResolveReturnsNotFoundBelowMinScore(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            ['id' => 1, 'title' => 'Completely Different Title', 'year' => 2020],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'Dune.2021.1080p');
        $this->assertFalse($r['found']);
        $this->assertSame('No match in library', $r['error']);
    }

    public function testResolveReturnsErrorOnUnknownPipeline(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('unknown', 'Whatever.2020.1080p');
        $this->assertFalse($r['found']);
        $this->assertSame('Unknown pipeline', $r['error']);
    }

    public function testResolveSeriesPipelineUsesSonarr(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getRawAllSeries')->willReturn([
            ['id' => 99, 'title' => 'Breaking Bad', 'year' => 2008],
        ]);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('sonarr', 'Breaking.Bad.S01E01.720p');
        $this->assertTrue($r['found']);
        $this->assertSame(99, $r['id']);
        $this->assertSame('/medias/sonarr-1/series?open=99', $r['url']);
    }

    /**
     * v1.1.0 regression — the resolver URL must carry the default instance
     * slug so the click on the qBit Radarr/Sonarr badge lands on a real route.
     * Without the slug prefix, /medias/films and /medias/series are 404 and
     * the badge click produces a silent error.
     */
    public function testResolveMovieUrlIncludesDefaultRadarrSlug(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            ['id' => 7, 'title' => 'Dune', 'year' => 2021],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'Dune.2021.1080p.BluRay');
        $this->assertTrue($r['found']);
        $this->assertSame('/medias/radarr-1/films?open=7', $r['url']);
    }

    /**
     * Real-world miss: a French Radarr installation stores movies under
     * their FR title ("Aventures croisées") while torrents are typically
     * named after the original (English) title ("Swapped"). Without scoring
     * against `originalTitle` and `alternateTitles[]`, every multi-language
     * release fails to match. Reproduces a case Joshua flagged at session 13.
     */
    public function testResolveMatchesAgainstOriginalTitle(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            [
                'id'            => 42,
                'title'         => 'Aventures croisées',
                'originalTitle' => 'Swapped',
                'year'          => 2026,
            ],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'Swapped.2026.MULTi.1080p.WEB.x264-FW');
        $this->assertTrue($r['found'], 'EN release name should match the FR-stored movie via originalTitle');
        $this->assertSame(42, $r['id']);
    }

    /**
     * Same pattern but the EN title is in alternateTitles[] rather than
     * originalTitle (Radarr ingests both fields with slightly different
     * semantics; we don't care which one carries the variant).
     */
    public function testResolveMatchesAgainstAlternateTitles(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            [
                'id'              => 99,
                'title'           => 'Une vie',
                'originalTitle'   => 'Une vie',
                'alternateTitles' => [
                    ['title' => 'One Life', 'sourceType' => 'tmdb'],
                ],
                'year'            => 2023,
            ],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'One.Life.2023.MULTI.VFF.1080p.10bit.WEBRip.6CH.x265.HEVC-SERQPH');
        $this->assertTrue($r['found']);
        $this->assertSame(99, $r['id']);
    }

    /**
     * Real-world miss reproduced: French release name without accents
     * ("La.Traversee.2022") vs Radarr title with accents ("La traversée").
     * The transliteration switch (iconv → intl Transliterator) makes both
     * sides collapse to the same lowercase ASCII form, so the contains
     * heuristic gives a 70+20 = 90 score.
     */
    public function testResolveMatchesAccentedTitleAgainstAsciiRelease(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            ['id' => 17, 'title' => 'La traversée', 'year' => 2022],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'La.Traversee.2022.FRENCH.1080p.WEB.H264-SEiGHT');
        $this->assertTrue($r['found']);
        $this->assertSame(17, $r['id']);
    }

    /**
     * Robustness: a user without Radarr configured (or with Radarr currently
     * unreachable) must NOT see a 500 when clicking the resolve badge in qBit.
     * The Radarr client throws ServiceNotConfiguredException which the service
     * catches and converts into a clean `found: false`.
     */
    public function testResolveRadarrUnavailableReturnsNotFound(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willThrowException(
            new \App\Exception\ServiceNotConfiguredException('Radarr', 'service_instance:radarr')
        );
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('radarr', 'Whatever.2024.1080p');
        $this->assertFalse($r['found']);
        $this->assertSame('Radarr unavailable', $r['error']);
    }

    /**
     * Same for Sonarr. Catches any \Throwable so both
     * ServiceNotConfiguredException and runtime cURL failures degrade
     * gracefully.
     */
    public function testResolveSonarrUnavailableReturnsNotFound(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('getRawAllSeries')->willThrowException(new \RuntimeException('cURL connect failed'));

        $svc = new TorrentResolverService($radarr, $sonarr, $this->instancesMock());
        $r = $svc->resolve('sonarr', 'Whatever.S01E01.720p');
        $this->assertFalse($r['found']);
        $this->assertSame('Sonarr unavailable', $r['error']);
    }

    /**
     * Edge case: a match was found but no default instance exists (e.g. an
     * admin disabled all Radarr instances after the matching ran). Resolver
     * must return found=false rather than producing a broken /medias/?open=X URL.
     */
    public function testResolveReturnsNotFoundWhenNoDefaultInstance(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('getRawMovies')->willReturn([
            ['id' => 1, 'title' => 'Dune', 'year' => 2021],
        ]);
        $sonarr = $this->createMock(SonarrClient::class);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getDefault')->willReturn(null);

        $svc = new TorrentResolverService($radarr, $sonarr, $instances);
        $r = $svc->resolve('radarr', 'Dune.2021.1080p');
        $this->assertFalse($r['found']);
        $this->assertSame('No Radarr instance', $r['error']);
    }
}
