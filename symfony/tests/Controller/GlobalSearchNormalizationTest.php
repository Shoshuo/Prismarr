<?php

namespace App\Tests\Controller;

use App\Controller\MediaController;
use App\Service\ConfigService;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * globalSearch() used to transliterate 3 fields on every library row on EVERY
 * request and re-normalize both sides of every usort comparison. The work
 * moves into the 60 s index; these cases pin the behaviour that must not
 * drift while it moves.
 */
#[AllowMockObjectsWithoutExpectations]
class GlobalSearchNormalizationTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function search(array $rows, string $term): array
    {
        $c = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(MediaController::class, 'matchAndRank');
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($c, $rows, $term);

        return $out;
    }

    /**
     * @param list<string> $titles
     * @return list<array<string, mixed>>
     */
    private function index(array $titles): array
    {
        $c = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(MediaController::class, 'normalizeIndexRow');

        return array_map(
            static fn (string $t, int $i) => $m->invoke($c, [
                'id' => $i + 1, 'title' => $t, 'originalTitle' => null, 'sortTitle' => strtolower($t),
                'year' => 2000, 'hasFile' => true, 'poster' => null,
                'instance' => ['slug' => 'radarr-1', 'name' => 'Radarr'],
            ]),
            $titles,
            array_keys($titles),
        );
    }

    public function testAccentAndCaseInsensitiveMatch(): void
    {
        $hits = $this->search($this->index(['Amélie', 'Other']), 'amelie');

        $this->assertCount(1, $hits);
        $this->assertSame('Amélie', $hits[0]['title']);
    }

    public function testStartsWithRanksBeforeAlphabetical(): void
    {
        $hits = $this->search($this->index(['A Matrix Story', 'Matrix']), 'matrix');

        $this->assertSame('Matrix', $hits[0]['title'], 'titles starting with the term come first');
        $this->assertSame('A Matrix Story', $hits[1]['title']);
    }

    public function testAlphabeticalTieBreakUsesTheRawTitle(): void
    {
        $hits = $this->search($this->index(['matrix zulu', 'Matrix Alpha']), 'matrix');

        $this->assertSame('Matrix Alpha', $hits[0]['title']);
    }

    public function testTheOriginalTitleFieldIsSearchable(): void
    {
        $rows = $this->index(['Le Fabuleux Destin']);
        $rows[0]['originalTitle'] = 'Amelie';
        $rows[0]['_n_original']   = 'amelie';

        $this->assertCount(1, $this->search($rows, 'amelie'));
    }

    public function testNormalizedHelperFieldsArePresentOnAnIndexRow(): void
    {
        $row = $this->index(['Amélie'])[0];

        $this->assertSame('amelie', $row['_n_title']);
        $this->assertArrayHasKey('_n_original', $row);
        $this->assertArrayHasKey('_n_sort', $row);
    }

    public function testHelperFieldsAreStrippedBeforeTheResponse(): void
    {
        $c = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(MediaController::class, 'stripIndexHelpers');

        $json = json_encode($m->invoke($c, $this->index(['Amélie'])));

        // The client stringifies each whole result into a data-item attribute,
        // so ANY leftover key ships to the DOM.
        $this->assertStringNotContainsString('_n_', (string) $json);
    }

    // ── Full globalSearch() coverage ────────────────────────────────────────
    //
    // The tests above exercise the private helpers in isolation. The two
    // below drive the PUBLIC globalSearch() end to end against a `_v3` index
    // pre-seeded straight into a mocked CacheInterface, so they pin the
    // 12-result cap + its ordering across the cap boundary, and that a
    // genuine zero-result query short-circuits to a JSON array.

    /**
     * @param list<array<string, mixed>> $movies
     * @param list<array<string, mixed>> $series
     */
    private function makeController(array $movies, array $series = []): MediaController
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'prismarr_search_movies_v3' => $movies,
            'prismarr_search_series_v3' => $series,
            default => [],
        });

        $controller = new MediaController(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(QBittorrentClient::class),
            $cache,
            $this->createMock(ConfigService::class),
            $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(MediaLibraryCache::class),
        );

        // AbstractController::json() reads $this->container->has('serializer')
        // before falling back to plain json_encode(); a controller built via
        // `new` (no kernel) never gets that property set, so it must be
        // primed by hand or the call blows up on an uninitialized property.
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    /** @return list<array<string, mixed>> */
    private function search12(MediaController $controller, string $term): array
    {
        $response = $controller->globalSearch(Request::create('/x', 'GET', ['q' => $term]));
        $content  = (string) $response->getContent();

        // The renderer JSON.stringify()s every result into a data-item
        // attribute, so a leftover _n_* helper key would ship to the DOM.
        $this->assertStringNotContainsString('_n_', $content);

        /** @var list<array<string, mixed>> $data */
        $data = json_decode($content, true);

        return $data;
    }

    public function testTwelveResultCapAndOrderAcrossTheBoundary(): void
    {
        // 8 titles start with the term (rank group 1, alphabetical A..H), 6
        // only contain it (rank group 2, alphabetical A..F) — 14 candidates
        // total, so the 12-cap slices INSIDE the second group: group 1 in
        // full (A..H) plus only the first 4 of group 2 (A..D); E and F fall
        // off the end. This pins both the cap AND that starts-with-first
        // ordering holds right up to (and across) the cut line.
        //
        // Deliberately NOT listed in sorted order: a fixture that already
        // happens to be pre-sorted would still pass this assertion even if
        // the controller's usort() were deleted outright (array order alone
        // would coincidentally match). Interleaving both groups makes the
        // assertion causal — only an actual sort by "starts-with, then
        // alphabetical" reproduces the expected order below.
        $titles = [
            'Zone D', 'Alpha Zone B', 'Zone A', 'Alpha Zone F', 'Zone G', 'Zone C',
            'Alpha Zone D', 'Zone F', 'Alpha Zone A', 'Zone H', 'Alpha Zone E', 'Zone B',
            'Alpha Zone C', 'Zone E',
        ];

        $data = $this->search12($this->makeController($this->index($titles)), 'zone');

        $this->assertCount(12, $data);
        $this->assertSame(
            [
                'Zone A', 'Zone B', 'Zone C', 'Zone D', 'Zone E', 'Zone F', 'Zone G', 'Zone H',
                'Alpha Zone A', 'Alpha Zone B', 'Alpha Zone C', 'Alpha Zone D',
            ],
            array_column($data, 'title'),
        );
    }

    public function testAThirteenthMatchIsNeverRendered(): void
    {
        // 13 candidates, all starting with the term and zero-padded so
        // lexical order == numeric id order (1..13) == rank order. The 13th
        // is a genuine match that the cap alone excludes — it must not reach
        // the payload, because everything after the slice (enrichment, the
        // DOM render) is per-result work.
        $titles = array_map(static fn (int $n) => sprintf('Wanted %02d', $n), range(1, 13));

        $data = $this->search12($this->makeController($this->index($titles)), 'wanted');

        $this->assertCount(12, $data);
        $this->assertSame(range(1, 12), array_column($data, 'id'));
    }

    public function testZeroResultQueryReturnsAnEmptyJsonArray(): void
    {
        $controller = $this->makeController($this->index(['Something Else Entirely']));
        $response   = $controller->globalSearch(Request::create('/x', 'GET', ['q' => 'zzzznomatch']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        // Must be the JSON array "[]", never an object "{}" — the client
        // renderer iterates the payload as a list.
        $this->assertSame('[]', trim((string) $response->getContent()));
        $this->assertSame([], json_decode((string) $response->getContent(), true));
    }
}
