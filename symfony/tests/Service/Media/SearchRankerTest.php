<?php

namespace App\Tests\Service\Media;

use App\Service\Media\SearchRanker;
use PHPUnit\Framework\TestCase;

final class SearchRankerTest extends TestCase
{
    public function testNormalizeDropsAccentsCaseAndPunctuation(): void
    {
        self::assertSame('amelie poulain', SearchRanker::normalize('  Amélie   Poulain! '));
        self::assertSame('mad max fury road', SearchRanker::normalize('Mad Max: Fury Road'));
    }

    public function testSplitYear(): void
    {
        self::assertSame(['dune', 2021], SearchRanker::splitYear('dune 2021'));
        self::assertSame(['Dune', 1984], SearchRanker::splitYear('Dune (1984)'));
        self::assertSame(['blade runner 2049', null], SearchRanker::splitYear('blade runner 2049'), '2049 is not a plausible year hint');
        self::assertSame(['1917', null], SearchRanker::splitYear('1917'));
    }

    public function testExactTitleBeatsAMorePopularPartialMatch(): void
    {
        $items = [
            ['media_type' => 'movie', 'title' => 'The Matrix Resurrections', 'popularity' => 900, 'vote_count' => 6000, 'release_date' => '2021-12-16'],
            ['media_type' => 'movie', 'title' => 'The Matrix', 'popularity' => 80, 'vote_count' => 25000, 'release_date' => '1999-03-30'],
        ];
        $sorted = SearchRanker::sort($items, 'relevance', SearchRanker::normalize('the matrix'));
        self::assertSame('The Matrix', $sorted[0]['title']);
    }

    public function testYearHintPicksTheRightRemake(): void
    {
        $items = [
            ['media_type' => 'movie', 'title' => 'Dune', 'popularity' => 120, 'vote_count' => 12000, 'release_date' => '2021-09-15'],
            ['media_type' => 'movie', 'title' => 'Dune', 'popularity' => 30, 'vote_count' => 2500, 'release_date' => '1984-12-14'],
        ];
        $sorted = SearchRanker::sort($items, 'relevance', 'dune', 1984);
        self::assertSame('1984-12-14', $sorted[0]['release_date']);
    }

    public function testAccentInsensitiveMatchOnOriginalTitle(): void
    {
        $items = [
            ['media_type' => 'movie', 'title' => 'Something Else', 'popularity' => 500],
            ['media_type' => 'movie', 'title' => 'Amelie', 'original_title' => 'Le Fabuleux Destin d\'Amélie Poulain', 'popularity' => 40],
        ];
        $sorted = SearchRanker::sort($items, 'relevance', SearchRanker::normalize('amélie'));
        self::assertSame('Amelie', $sorted[0]['title']);
    }

    public function testVoteSortIgnoresTinyVoteCounts(): void
    {
        $items = [
            ['media_type' => 'movie', 'title' => 'Obscure', 'vote_average' => 10.0, 'vote_count' => 3],
            ['media_type' => 'movie', 'title' => 'Classic', 'vote_average' => 8.6, 'vote_count' => 20000],
        ];
        self::assertSame('Classic', SearchRanker::sort($items, 'vote')[0]['title']);
    }

    public function testNewestSort(): void
    {
        $items = [
            ['media_type' => 'tv', 'name' => 'Old', 'first_air_date' => '2008-01-20'],
            ['media_type' => 'movie', 'title' => 'New', 'release_date' => '2024-03-01'],
            ['media_type' => 'movie', 'title' => 'Undated'],
        ];
        self::assertSame(['New', 'Old', 'Undated'], array_map(fn($i) => $i['title'] ?? $i['name'], SearchRanker::sort($items, 'newest')));
    }

    public function testFilters(): void
    {
        $movie = ['media_type' => 'movie', 'title' => 'X', 'genre_ids' => [28, 12], 'release_date' => '2015-05-01', 'vote_average' => 7.4];
        $tv = ['media_type' => 'tv', 'name' => 'Y', 'genre_ids' => [18], 'first_air_date' => '2019-01-01', 'vote_average' => 8.9];

        self::assertTrue(SearchRanker::matches($movie, []));
        self::assertFalse(SearchRanker::matches($movie, ['type' => 'tv']));
        self::assertTrue(SearchRanker::matches($tv, ['type' => 'tv', 'genre' => 18]));
        self::assertFalse(SearchRanker::matches($movie, ['genre' => 18]));
        self::assertFalse(SearchRanker::matches($movie, ['year_min' => 2016]));
        self::assertFalse(SearchRanker::matches($tv, ['year_max' => 2018]));
        self::assertFalse(SearchRanker::matches($movie, ['vote_min' => 8.0]));
        self::assertFalse(SearchRanker::matches(['media_type' => 'person', 'name' => 'Z'], []));
    }
}
