<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\Media\TmdbClient;
use App\Tests\AbstractWebTestCase;

/**
 * The Search page (/decouverte) renders the search box, its filters and the
 * home content (trending banners) with a configured TMDb. Catches Twig
 * runtime errors that lint:twig cannot see.
 */
final class SearchPageRenderTest extends AbstractWebTestCase
{
    public function testSearchPageRendersSearchBoxFiltersAndHome(): void
    {
        $this->em()->persist(new Setting('tmdb_api_key', 'test-key'));
        $this->em()->flush();

        $list = ['results' => [
            ['id' => 603, 'title' => 'The Matrix', 'release_date' => '1999-03-30', 'vote_average' => 8.2, 'poster_path' => '/m.jpg', 'backdrop_path' => '/b.jpg', 'overview' => 'Neo.'],
        ]];
        $tv = ['results' => [
            ['id' => 1396, 'name' => 'Breaking Bad', 'first_air_date' => '2008-01-20', 'vote_average' => 8.9, 'poster_path' => '/t.jpg', 'backdrop_path' => '/tb.jpg', 'overview' => 'Walter.'],
        ]];
        $tmdb = $this->createStub(TmdbClient::class);
        $tmdb->method('ping')->willReturn(true);
        foreach (['getTrendingAll', 'getTrendingMovies', 'getPopularMovies', 'getUpcomingMovies', 'getTopRatedMovies'] as $m) {
            $tmdb->method($m)->willReturn($list);
        }
        foreach (['getTrendingTv', 'getPopularTv', 'getOnTheAirTv', 'getTopRatedTv'] as $m) {
            $tmdb->method($m)->willReturn($tv);
        }
        static::getContainer()->set(TmdbClient::class, $tmdb);

        $crawler = $this->client->request('GET', '/decouverte');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#tmdb-search'));
        self::assertCount(3, $crawler->filter('[data-srch-type]'));
        foreach (['#srch-genre', '#srch-vote', '#srch-sort', '#srch-hide-owned', '#srch-results', '#srch-correction', '#srch-home'] as $sel) {
            self::assertCount(1, $crawler->filter($sel), $sel . ' missing');
        }
        // The two "trending this week" banners stay on the home view.
        self::assertStringContainsString('The Matrix', $crawler->filter('#srch-home')->text());
        self::assertStringContainsString('Breaking Bad', $crawler->filter('#srch-home')->text());
    }

    public function testSidebarCallsThePageSearch(): void
    {
        // The entry only shows once TMDb is configured.
        $this->em()->persist(new Setting('tmdb_api_key', 'test-key'));
        $this->em()->flush();

        $this->client->request('GET', '/admin/settings?_locale=fr');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/<span class="nav-link-title">Recherche<\/span>/', $html);
    }
}
