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

    public function testSuggestFixesTypos(): void
    {
        $candidates = ['Interstellar', 'Inception', 'Interview with the Vampire'];
        self::assertSame('Interstellar', SearchRanker::suggest('interstelar', $candidates));
        self::assertSame('Inception', SearchRanker::suggest('incepsion', $candidates));
        self::assertSame('Christopher Nolan', SearchRanker::suggest('cristopher nolan', ['Christopher Nolan', 'Chris Nolan Jr']));
    }

    public function testSuggestMatchesTheLeadingWordsOfALongerTitle(): void
    {
        self::assertSame('The Matrix', SearchRanker::suggest('the mtrix', ['The Matrix Reloaded', 'The Mask']));
    }

    public function testSuggestStaysQuietWhenNothingIsCloseOrTheQueryIsCorrect(): void
    {
        self::assertNull(SearchRanker::suggest('interstellar', ['Interstellar', 'Inception']), 'already a real title');
        self::assertNull(SearchRanker::suggest('zzzzqqq', ['Interstellar']), 'nothing close');
        self::assertNull(SearchRanker::suggest('dun', ['Dune']), 'too short to correct');
        self::assertNull(SearchRanker::suggest('alien', ['Aliens', 'Alien']), 'a real title among the candidates wins over a near one');
    }

    public function testSuggestPrefersAFamousTitleOverAnObscureExactOne(): void
    {
        $candidates = [
            ['label' => 'Interstelar', 'popularity' => 0.4],
            ['label' => 'Interstellar', 'popularity' => 65],
        ];
        self::assertSame('Interstellar', SearchRanker::suggest('interstelar', $candidates));
        // Comparable popularity: the real title stands, no suggestion.
        self::assertNull(SearchRanker::suggest('interstelar', [['label' => 'Interstelar', 'popularity' => 30], ['label' => 'Interstellar', 'popularity' => 65]]));
    }

    public function testSuggestCompletesAHalfTypedWordWhenAllowed(): void
    {
        $candidates = [['label' => 'Poke', 'popularity' => 2], ['label' => 'Pokémon', 'popularity' => 152]];
        self::assertSame('Pokémon', SearchRanker::suggest('pokémo', $candidates, allowCompletion: true));
        $longer = [['label' => 'Pokémonia', 'popularity' => 14]];
        self::assertNull(SearchRanker::suggest('pokémo', $longer), 'three letters short: only a completion reaches it');
        self::assertSame('Pokémonia', SearchRanker::suggest('pokémo', $longer, allowCompletion: true));
    }

    public function testSuggestRanksEqualDistancesByPopularity(): void
    {
        $candidates = [['label' => 'Pokemen', 'popularity' => 0.1], ['label' => 'Pokémon', 'popularity' => 152]];
        self::assertSame('Pokémon', SearchRanker::suggest('pokumon', $candidates));
    }

    public function testFamousPrefixMatchOutranksAnObscureExactTitle(): void
    {
        $items = [
            ['media_type' => 'movie', 'title' => 'Poke', 'popularity' => 2, 'vote_count' => 2, 'release_date' => '2020-01-01'],
            ['media_type' => 'tv', 'name' => 'Pokémon', 'popularity' => 152, 'vote_count' => 2000, 'first_air_date' => '1997-04-01'],
        ];
        self::assertSame('Pokémon', SearchRanker::sort($items, 'relevance', 'poke')[0]['name'] ?? null);
    }

    public function testNormalizeKeepsApostropheWordsWhole(): void
    {
        self::assertSame('jojos bizarre adventure', SearchRanker::normalize("JoJo's Bizarre Adventure"));
        self::assertSame('lord of the rings the return of the king', SearchRanker::normalize('Lord of the Rings: The Return of the King'));
    }

    public function testSuggestCorrectsWordByWordWithAHalfTypedLastWord(): void
    {
        $candidates = [
            ['label' => "JoJo's Bizarre Adventure", 'popularity' => 60],
            ['label' => 'Bizarre Love Triangle', 'popularity' => 2],
        ];
        self::assertSame("JoJo's Bizarre Adventure", SearchRanker::suggest('jojo bizzare adven', $candidates));
        self::assertSame('Breaking Bad', SearchRanker::suggest('braking ba', [['label' => 'Breaking Bad', 'popularity' => 300]]));
    }

    public function testWordByWordStaysStrictOnShortWords(): void
    {
        // A 3-letter word tolerates no typo.
        self::assertNull(SearchRanker::suggest('red dawn', [['label' => 'Rex Dawson', 'popularity' => 50]]));
        self::assertNull(SearchRanker::suggest('dog day', [['label' => 'Dig Dug', 'popularity' => 50]]));
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
