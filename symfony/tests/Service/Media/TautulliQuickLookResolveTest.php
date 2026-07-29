<?php

namespace App\Tests\Service\Media;

use App\Service\Media\TautulliClient;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage of TautulliClient::tmdbIdFromMetadata() — the transform
 * that turns a raw Tautulli `get_metadata` payload into the {type, id} pair
 * the global quick-look modal needs. No network, same style as the
 * normalizeActivity tests.
 *
 * Level rules under test:
 *  - movie / show → the item's own `guids`
 *  - season       → `parent_guids` (the show)
 *  - episode      → `grandparent_guids` (the show)
 * Episode/season-level tmdb guids are deliberately NOT used: they identify
 * the episode, and the quick-look renders shows, not episodes.
 */
class TautulliQuickLookResolveTest extends TestCase
{
    public function testMovieResolvesFromItsOwnGuids(): void
    {
        $r = TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'movie',
            'guids'      => ['imdb://tt0133093', 'tmdb://603'],
        ]);

        self::assertSame(['type' => 'movie', 'id' => 603], $r);
    }

    public function testShowResolvesAsTvFromItsOwnGuids(): void
    {
        $r = TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'show',
            'guids'      => ['tvdb://121361', 'tmdb://1399'],
        ]);

        self::assertSame(['type' => 'tv', 'id' => 1399], $r);
    }

    public function testEpisodeResolvesFromGrandparentGuidsOnly(): void
    {
        $r = TautulliClient::tmdbIdFromMetadata([
            'media_type'        => 'episode',
            // Episode-level tmdb guid = the EPISODE's id — must be ignored.
            'guids'             => ['tmdb://999999'],
            'grandparent_guids' => ['imdb://tt0903747', 'tmdb://1396'],
        ]);

        self::assertSame(['type' => 'tv', 'id' => 1396], $r);
    }

    public function testSeasonResolvesFromParentGuids(): void
    {
        $r = TautulliClient::tmdbIdFromMetadata([
            'media_type'   => 'season',
            'parent_guids' => ['tmdb://1396'],
        ]);

        self::assertSame(['type' => 'tv', 'id' => 1396], $r);
    }

    public function testEpisodeWithoutShowLevelGuidsIsNull(): void
    {
        // The pure transform can't hop to the grandparent — that's
        // resolveTmdbId()'s orchestration job. It must signal "unresolved".
        $r = TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'episode',
            'guids'      => ['tmdb://999999'],
        ]);

        self::assertNull($r);
    }

    public function testMusicAndUnknownTypesAreNull(): void
    {
        self::assertNull(TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'track',
            'guids'      => ['tmdb://603'],
        ]));
        self::assertNull(TautulliClient::tmdbIdFromMetadata([]));
    }

    public function testNoTmdbGuidIsNull(): void
    {
        self::assertNull(TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'movie',
            'guids'      => ['imdb://tt0133093', 'tvdb://81189'],
        ]));
    }

    public function testMalformedGuidShapesAreNullNotFatal(): void
    {
        self::assertNull(TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'movie',
            'guids'      => 'tmdb://603', // string, not list
        ]));
        self::assertNull(TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'movie',
            'guids'      => [42, null, ['tmdb://603']],
        ]));
        self::assertNull(TautulliClient::tmdbIdFromMetadata([
            'media_type' => 'movie',
            'guids'      => ['tmdb://not-a-number'],
        ]));
    }
}
