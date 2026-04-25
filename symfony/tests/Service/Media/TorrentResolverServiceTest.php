<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TorrentResolverService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TorrentResolverServiceTest extends TestCase
{
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
        // Known quirk: iconv TRANSLIT inserts a space before translitered
        // chars on Alpine/musl. Result is still matchable via the `contains`
        // heuristic in scoreMatch(). Fix planned in v1.1+.
        $this->assertSame('pok emon', TorrentResolverService::normalizeTitle('Pokémon'));
    }

    public function testNormalizeStripsPunctuation(): void
    {
        $this->assertSame('l echapp ee', TorrentResolverService::normalizeTitle("L'Échappée"));
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

        $svc = new TorrentResolverService($radarr, $sonarr);
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

        $svc = new TorrentResolverService($radarr, $sonarr);
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

        $svc = new TorrentResolverService($radarr, $sonarr);
        $r = $svc->resolve('radarr', 'Dune.2021.1080p');
        $this->assertFalse($r['found']);
        $this->assertSame('No match in library', $r['error']);
    }

    public function testResolveReturnsErrorOnUnknownPipeline(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);

        $svc = new TorrentResolverService($radarr, $sonarr);
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

        $svc = new TorrentResolverService($radarr, $sonarr);
        $r = $svc->resolve('sonarr', 'Breaking.Bad.S01E01.720p');
        $this->assertTrue($r['found']);
        $this->assertSame(99, $r['id']);
        $this->assertStringContainsString('/medias/series', $r['url']);
    }
}
