<?php

namespace App\Tests\Service\Media;

use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Service\Media\TautulliClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic coverage of TautulliClient::normalizeActivity() — the transform
 * from a raw Tautulli `get_activity` `data` object into the sanitized shape
 * the frontend receives. No network: the input is a captured-style fixture.
 *
 * The security-critical assertions are the sanitization ones: private fields
 * (IPs, tokens, machine id, file paths, Plex username/email) present in the
 * raw session must NOT survive into the normalized output.
 */
#[AllowMockObjectsWithoutExpectations]
class TautulliClientTest extends TestCase
{
    /** A representative single-session get_activity `data` payload. */
    private function fixtureData(): array
    {
        return [
            'stream_count'               => '1',
            'stream_count_direct_play'   => 1,
            'stream_count_direct_stream' => 0,
            'stream_count_transcode'     => 0,
            'total_bandwidth'            => 8700,
            'lan_bandwidth'              => 8700,
            'wan_bandwidth'              => 0,
            'sessions'                   => [
                [
                    'session_key'        => '123',
                    'session_id'         => 'abc-session-id',
                    'state'              => 'playing',
                    'full_title'         => "Tom Clancy's Jack Ryan: Ghost War",
                    'title'              => 'Ghost War',
                    'grandparent_title'  => 'Tom Clancy\'s Jack Ryan',
                    'year'               => '2026',
                    'media_type'         => 'movie',
                    'thumb'              => '/library/metadata/12345/thumb/1700000000',
                    'friendly_name'      => 'nDanDan',
                    'username'           => 'plexlogin_secret',
                    'user'               => 'nDanDan',
                    'product'            => 'Plex for Windows',
                    'player'             => 'Main-PC',
                    'device'             => 'Windows',
                    'platform'           => 'Windows',
                    'quality_profile'    => 'Original',
                    'container_decision' => 'direct play',
                    'video_decision'     => 'direct play',
                    'audio_decision'     => 'direct play',
                    'subtitle_decision'  => 'direct play',
                    'transcode_decision' => 'direct play',
                    'location'           => 'lan',
                    'bandwidth'          => '8700',
                    'progress_percent'   => '36.4',
                    // ── Private fields that must be stripped ──────────────
                    'ip_address'         => '192.168.68.55',
                    'ip_address_public'  => '203.0.113.9',
                    'machine_id'         => 'mach-deadbeef',
                    'session_token'      => 'tok-should-not-leak',
                    'file'               => '/data/media/movies/JackRyan.mkv',
                    'email'              => 'user@example.com',
                ],
            ],
        ];
    }

    public function testNormalizesCountsAndBandwidth(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());

        self::assertSame(1, $out['streamCount']);
        self::assertSame(1, $out['directPlayCount']);
        self::assertSame(0, $out['directStreamCount']);
        self::assertSame(0, $out['transcodeCount']);

        self::assertSame(8700, $out['bandwidth']['totalKbps']);
        self::assertSame(8.7, $out['bandwidth']['totalMbps']);
        self::assertSame(8.7, $out['bandwidth']['lanMbps']);
        self::assertSame(0.0, $out['bandwidth']['wanMbps']);
    }

    public function testNormalizesSessionFields(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());
        self::assertCount(1, $out['sessions']);
        $s = $out['sessions'][0];

        self::assertSame('123', $s['sessionKey']);
        self::assertSame('playing', $s['state']);
        self::assertSame("Tom Clancy's Jack Ryan: Ghost War", $s['title']);
        self::assertSame('2026', $s['year']);
        self::assertSame('movie', $s['mediaType']);
        self::assertSame('nDanDan', $s['userDisplayName']);
        self::assertSame('Plex for Windows', $s['product']);
        self::assertSame('Original', $s['quality']);
        self::assertSame('direct play', $s['transcodeDecision']);
        self::assertSame('lan', $s['location']);
        self::assertSame(8700, $s['bandwidthKbps']);
        self::assertSame(8.7, $s['bandwidthMbps']);
        self::assertSame(36.4, $s['progressPercent']);
    }

    public function testEpisodeSessionCarriesSeasonEpisodeCode(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['media_type']         = 'episode';
        $data['sessions'][0]['full_title']         = 'Tulsa King - Triad';
        $data['sessions'][0]['title']              = 'Triad';
        $data['sessions'][0]['grandparent_title']  = 'Tulsa King';
        $data['sessions'][0]['parent_media_index'] = '2';
        $data['sessions'][0]['media_index']        = '5';
        $s = TautulliClient::normalizeActivity($data)['sessions'][0];

        self::assertSame('S02E05', $s['episodeCode']);
        self::assertSame('Triad', $s['episodeTitle']);
        self::assertSame('Tulsa King', $s['grandparentTitle']);
    }

    public function testMovieSessionHasNoEpisodeCode(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['parent_media_index'] = '';
        $data['sessions'][0]['media_index']        = '';
        $s = TautulliClient::normalizeActivity($data)['sessions'][0];

        self::assertNull($s['episodeCode']);
    }

    public function testEpisodeCodeFormatting(): void
    {
        self::assertSame('E07', TautulliClient::episodeCode('episode', null, '7'));
        self::assertSame('S00E01', TautulliClient::episodeCode('episode', '0', '1'));
        self::assertSame('S01E120', TautulliClient::episodeCode('episode', 1, 120));
        self::assertNull(TautulliClient::episodeCode('episode', '3', ''));
        self::assertNull(TautulliClient::episodeCode('movie', '1', '2'));
    }

    public function testStripsPrivateFields(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());
        $s = $out['sessions'][0];

        // Whatever the allow-list grows to, none of these may ever appear.
        foreach (['ip_address', 'ip_address_public', 'machine_id', 'session_token', 'file', 'email', 'username'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $s, "private field {$forbidden} leaked");
        }
        // Belt-and-suspenders: the secret values must not appear under any key.
        $flat = implode('|', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $s));
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('tok-should-not-leak', $flat);
        self::assertStringNotContainsString('203.0.113.9', $flat);
        self::assertStringNotContainsString('/data/media/movies', $flat);
    }

    public function testEmptyActivity(): void
    {
        $out = TautulliClient::normalizeActivity([
            'stream_count' => 0,
            'sessions'     => [],
        ]);

        self::assertSame(0, $out['streamCount']);
        self::assertSame([], $out['sessions']);
        self::assertSame(0.0, $out['bandwidth']['totalMbps']);
    }

    public function testProgressPercentIsClamped(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['progress_percent'] = '150';
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame(100.0, $out['sessions'][0]['progressPercent']);

        $data['sessions'][0]['progress_percent'] = 'not-a-number';
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame(0.0, $out['sessions'][0]['progressPercent']);
    }

    public function testFallsBackToBareTitleWhenFullTitleMissing(): void
    {
        $data = $this->fixtureData();
        unset($data['sessions'][0]['full_title']);
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame('Ghost War', $out['sessions'][0]['title']);
    }

    /**
     * Episodes carry a landscape `thumb` (the episode still); the portrait
     * series poster lives in `grandparent_thumb`. The poster tile wants the
     * portrait art, so episodes must resolve to grandparent_thumb.
     */
    public function testEpisodePosterPrefersGrandparentThumb(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['media_type']       = 'episode';
        $data['sessions'][0]['thumb']             = '/library/metadata/999/thumb/1';
        $data['sessions'][0]['grandparent_thumb'] = '/library/metadata/100/thumb/2';
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame('/library/metadata/100/thumb/2', $out['sessions'][0]['posterPath']);
    }

    public function testMoviePosterUsesThumb(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());
        self::assertSame('/library/metadata/12345/thumb/1700000000', $out['sessions'][0]['posterPath']);
    }

    /**
     * The image-proxy endpoint must only ever fetch Plex library image paths —
     * never an absolute URL, a scheme, a traversal, or a non-image Plex route —
     * so it can't be abused as an open relay/SSRF vector.
     */
    #[DataProvider('proxyablePaths')]
    public function testIsProxyableImagePath(string $img, bool $expected): void
    {
        self::assertSame($expected, TautulliClient::isProxyableImagePath($img));
    }

    public static function proxyablePaths(): array
    {
        return [
            'metadata thumb'        => ['/library/metadata/12345/thumb/1700000000', true],
            'grandparent thumb'     => ['/library/metadata/100/art/2', true],
            'empty'                 => ['', false],
            'absolute http url'     => ['http://169.254.169.254/latest/meta-data', false],
            'absolute https url'    => ['https://evil.example.com/x.jpg', false],
            'protocol-relative'     => ['//evil.example.com/x.jpg', false],
            'path traversal'        => ['/library/../../etc/passwd', false],
            'not a library path'    => ['/api/v2?cmd=get_settings', false],
            'query injection'       => ['/library/metadata/1/thumb/2?cmd=delete', false],
            'backslash'             => ['/library\\metadata/1', false],
        ];
    }

    /** A representative get_metadata `data` object for a movie. */
    private function metadataMovieFixture(): array
    {
        return [
            'rating_key'     => '12345',
            'media_type'     => 'movie',
            'title'          => 'See How They Run',
            'year'           => '2022',
            'summary'        => 'In 1950s London, plans for a movie adaptation grind to a halt.',
            'tagline'        => 'Watch your step.',
            'content_rating' => 'PG-13',
            'duration'       => '5820000',
            'rating'         => '7.5',
            'audience_rating'=> '8.1',
            'studio'         => 'Searchlight Pictures',
            'originally_available_at' => '2022-09-16',
            'genres'         => ['Comedy', 'Crime', 'Mystery'],
            'directors'      => ['Tom George'],
            'writers'        => ['Mark Chappell'],
            'actors'         => ['Sam Rockwell', 'Saoirse Ronan', 'Adrien Brody', 'Ruth Wilson',
                                 'Reece Shearsmith', 'Harris Dickinson', 'David Oyelowo', 'Pippa Bennett-Warner',
                                 'Extra Person Nine'],
            'thumb'          => '/library/metadata/12345/thumb/1700000000',
            'media_info'     => [[
                'container'            => 'mkv',
                'bitrate'              => '8700',
                'video_codec'          => 'hevc',
                'audio_codec'          => 'eac3',
                'video_full_resolution'=> '1080p',
            ]],
            // private fields that must be stripped:
            'file'           => '/data/media/movies/SeeHowTheyRun.mkv',
            'section_id'     => '1',
            'guid'           => 'plex://movie/abc',
            'live'           => 0,
        ];
    }

    public function testNormalizeMetadataMapsMovieFields(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());

        self::assertSame('movie', $out['mediaType']);
        self::assertSame('See How They Run', $out['title']);
        self::assertSame('2022', $out['year']);
        self::assertSame('PG-13', $out['contentRating']);
        self::assertSame('Searchlight Pictures', $out['studio']);
        self::assertSame(['Comedy', 'Crime', 'Mystery'], $out['genres']);
        self::assertSame(['Tom George'], $out['directors']);
        self::assertSame(7.5, $out['ratings']['critic']);
        self::assertSame(8.1, $out['ratings']['audience']);
        self::assertSame('1080p', $out['media']['resolution']);
        self::assertSame('hevc', $out['media']['videoCodec']);
        self::assertSame('mkv', $out['media']['container']);
        self::assertSame(8700, $out['media']['bitrateKbps']);
        self::assertSame('1 h 37 min', $out['durationLabel']);
    }

    public function testNormalizeMetadataCapsCastAtEight(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());
        self::assertCount(8, $out['cast']);
        self::assertSame('Sam Rockwell', $out['cast'][0]);
        self::assertNotContains('Extra Person Nine', $out['cast']);
    }

    public function testNormalizeMetadataMapsEpisodeFields(): void
    {
        $data = $this->metadataMovieFixture();
        $data['media_type']        = 'episode';
        $data['title']             = 'Ghost War';
        $data['grandparent_title'] = "Tom Clancy's Jack Ryan";
        $data['parent_media_index']= '1';
        $data['media_index']       = '3';
        $data['grandparent_thumb'] = '/library/metadata/100/thumb/2';

        $out = TautulliClient::normalizeMetadata($data);

        self::assertSame('episode', $out['mediaType']);
        self::assertSame("Tom Clancy's Jack Ryan", $out['grandparentTitle']);
        self::assertSame(1, $out['season']);
        self::assertSame(3, $out['episode']);
    }

    public function testNormalizeMetadataStripsPrivateFields(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());
        foreach (['file', 'section_id', 'guid', 'media_info'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $out, "private field {$forbidden} leaked");
        }
        $flat = json_encode($out);
        self::assertStringNotContainsString('/data/media/movies', $flat);
        self::assertStringNotContainsString('plex://', $flat);
    }

    public function testNormalizeMetadataDurationUnderOneHourAndZero(): void
    {
        // 2_700_000 ms = 45 min
        self::assertSame('45 min', TautulliClient::normalizeMetadata(['duration' => '2700000'])['durationLabel']);
        // zero / missing duration -> null label
        self::assertNull(TautulliClient::normalizeMetadata(['duration' => '0'])['durationLabel']);
        self::assertNull(TautulliClient::normalizeMetadata([])['durationLabel']);
    }

    public function testNormalizeMetadataRatingsAbsentVsZero(): void
    {
        // absent ratings -> null
        $out = TautulliClient::normalizeMetadata([]);
        self::assertNull($out['ratings']['critic']);
        self::assertNull($out['ratings']['audience']);
        // a genuine zero rating must survive as 0.0 (not collapse to null)
        $out = TautulliClient::normalizeMetadata(['rating' => '0', 'audience_rating' => '0']);
        self::assertSame(0.0, $out['ratings']['critic']);
        self::assertSame(0.0, $out['ratings']['audience']);
    }

    public function testNormalizeMetadataHandlesEmptyMediaInfo(): void
    {
        $out = TautulliClient::normalizeMetadata(['media_info' => []]);
        self::assertNull($out['media']['resolution']);
        self::assertNull($out['media']['videoCodec']);
        self::assertNull($out['media']['container']);
        self::assertSame(0, $out['media']['bitrateKbps']);
    }

    /** A representative get_history `data` envelope (Tautulli wraps rows in `data`). */
    private function historyFixture(): array
    {
        return [
            'recordsFiltered' => 2,
            'data' => [
                [
                    'rating_key'        => '12345',
                    'media_type'        => 'movie',
                    'full_title'        => 'See How They Run',
                    'title'             => 'See How They Run',
                    'year'              => '2022',
                    'thumb'             => '/library/metadata/12345/thumb/1',
                    'friendly_name'     => 'nDanDan',
                    'user'              => 'nDanDan',
                    'date'              => 1781377600,
                    'percent_complete'  => 96,
                    'watched_status'    => 1,
                    'ip_address'        => '192.168.1.5',
                    'user_id'           => 99,
                ],
                [
                    'rating_key'        => '777',
                    'media_type'        => 'episode',
                    'full_title'        => "Tom Clancy's Jack Ryan - Ghost War",
                    'title'             => 'Ghost War',
                    'grandparent_title' => "Tom Clancy's Jack Ryan",
                    'grandparent_thumb' => '/library/metadata/100/thumb/2',
                    'thumb'             => '/library/metadata/777/thumb/3',
                    'parent_media_index'=> 1,
                    'media_index'       => 4,
                    'friendly_name'     => 'nDanDan',
                    'username'          => 'plexlogin_secret',
                    'date'              => 1781370000,
                    'percent_complete'  => 50,
                ],
            ],
        ];
    }

    public function testNormalizeHistoryMapsRows(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertCount(2, $out);

        self::assertSame('12345', $out[0]['ratingKey']);
        self::assertSame('See How They Run', $out[0]['title']);
        self::assertSame('nDanDan', $out[0]['userDisplayName']);
        self::assertSame(1781377600, $out[0]['watchedAt']);
        self::assertSame(96, $out[0]['percentComplete']);
        self::assertSame('/library/metadata/12345/thumb/1', $out[0]['posterPath']);
    }

    public function testNormalizeHistoryEpisodePrefersGrandparentPoster(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertSame('episode', $out[1]['mediaType']);
        self::assertSame("Tom Clancy's Jack Ryan", $out[1]['grandparentTitle']);
        self::assertSame('/library/metadata/100/thumb/2', $out[1]['posterPath']);
    }

    public function testNormalizeHistoryEpisodeCarriesSeasonEpisodeCode(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertNull($out[0]['episodeCode']);
        self::assertSame('S01E04', $out[1]['episodeCode']);
        self::assertSame('Ghost War', $out[1]['title']);
    }

    public function testNormalizeHistoryNeverLeaksPlexLogin(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        $flat = json_encode($out);
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('192.168.1.5', $flat);
        foreach ($out as $row) {
            self::assertArrayNotHasKey('username', $row);
            self::assertArrayNotHasKey('ip_address', $row);
        }
    }

    /** A representative get_home_stats `data` list (groups with rows). */
    private function homeStatsFixture(): array
    {
        return [
            ['stat_id' => 'top_movies', 'rows' => [
                ['rating_key' => '12345', 'title' => 'See How They Run', 'year' => '2022',
                 'total_plays' => 7, 'total_duration' => 18000, 'thumb' => '/library/metadata/12345/thumb/1',
                 'file' => '/data/media/x.mkv', 'guid' => 'plex://movie/abc'],
            ]],
            ['stat_id' => 'top_tv', 'rows' => [
                ['rating_key' => '777', 'title' => "Tom Clancy's Jack Ryan", 'total_plays' => 12,
                 'thumb' => '/library/metadata/700/thumb/9', 'grandparent_thumb' => '/library/metadata/100/thumb/2'],
            ]],
            ['stat_id' => 'top_users', 'rows' => [
                ['user' => 'plexlogin_secret', 'friendly_name' => 'nDanDan', 'user_id' => 99,
                 'total_plays' => 30, 'total_duration' => 36000, 'user_thumb' => 'https://plex.tv/users/abc/avatar'],
            ]],
            ['stat_id' => 'top_platforms', 'rows' => [
                ['platform' => 'Chrome', 'platform_name' => 'Chrome', 'total_plays' => 18],
            ]],
            ['stat_id' => 'popular_movies', 'rows' => [
                ['rating_key' => '555', 'title' => 'Dune', 'year' => '2021', 'users_watched' => 6,
                 'thumb' => '/library/metadata/555/thumb/1', 'file' => '/data/x.mkv'],
            ]],
            ['stat_id' => 'popular_tv', 'rows' => [
                ['rating_key' => '888', 'title' => 'Severance', 'users_watched' => 9,
                 'grandparent_thumb' => '/library/metadata/880/thumb/2'],
            ]],
            ['stat_id' => 'most_concurrent', 'rows' => [
                ['title' => 'Concurrent Streams', 'count' => 5],
                ['title' => 'Concurrent Transcodes', 'count' => 2],
            ]],
            ['stat_id' => 'last_watched', 'rows' => [['title' => 'ignored']]],
        ];
    }

    public function testNormalizeHomeStatsMapsGroups(): void
    {
        $out = TautulliClient::normalizeHomeStats($this->homeStatsFixture());

        self::assertSame('12345', $out['topMovies'][0]['ratingKey']);
        self::assertSame('See How They Run', $out['topMovies'][0]['title']);
        self::assertSame('2022', $out['topMovies'][0]['year']);
        self::assertSame('/library/metadata/12345/thumb/1', $out['topMovies'][0]['posterPath']);
        self::assertSame(7, $out['topMovies'][0]['plays']);

        self::assertSame('/library/metadata/100/thumb/2', $out['topShows'][0]['posterPath']);
        self::assertSame(12, $out['topShows'][0]['plays']);

        self::assertSame('nDanDan', $out['topUsers'][0]['userDisplayName']);
        self::assertSame(30, $out['topUsers'][0]['plays']);

        self::assertSame('Chrome', $out['topPlatforms'][0]['platform']);
        self::assertSame(18, $out['topPlatforms'][0]['plays']);
    }

    public function testNormalizeHomeStatsNeverLeaksPrivateFields(): void
    {
        $flat = json_encode(TautulliClient::normalizeHomeStats($this->homeStatsFixture()));
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('/data/media', $flat);
        self::assertStringNotContainsString('/data/x.mkv', $flat);
        self::assertStringNotContainsString('plex://', $flat);
        self::assertStringNotContainsString('avatar', $flat);
        foreach (TautulliClient::normalizeHomeStats($this->homeStatsFixture())['topUsers'] as $u) {
            self::assertArrayNotHasKey('user', $u);
            self::assertArrayNotHasKey('user_id', $u);
        }
    }

    public function testNormalizeHomeStatsEmptyInputYieldsEmptyLists(): void
    {
        $out = TautulliClient::normalizeHomeStats([]);
        self::assertSame([
            'topMovies' => [], 'topShows' => [], 'topUsers' => [], 'topPlatforms' => [],
            'popularMovies' => [], 'popularShows' => [], 'mostConcurrent' => [],
        ], $out);
    }

    public function testNormalizeHomeStatsAddsDurationToWatchedAndUsers(): void
    {
        $out = TautulliClient::normalizeHomeStats($this->homeStatsFixture());
        self::assertSame(18000, $out['topMovies'][0]['duration']);
        self::assertSame('5h 0m', $out['topMovies'][0]['durationLabel']);
        self::assertSame(36000, $out['topUsers'][0]['duration']);
        self::assertSame('10h 0m', $out['topUsers'][0]['durationLabel']);
    }

    public function testNormalizeHomeStatsMapsPopularAndConcurrent(): void
    {
        $out = TautulliClient::normalizeHomeStats($this->homeStatsFixture());
        self::assertSame('Dune', $out['popularMovies'][0]['title']);
        self::assertSame('2021', $out['popularMovies'][0]['year']);
        self::assertSame(6, $out['popularMovies'][0]['usersWatched']);
        self::assertSame('/library/metadata/555/thumb/1', $out['popularMovies'][0]['posterPath']);

        self::assertSame('Severance', $out['popularShows'][0]['title']);
        self::assertSame(9, $out['popularShows'][0]['usersWatched']);
        self::assertSame('/library/metadata/880/thumb/2', $out['popularShows'][0]['posterPath']);

        self::assertSame('Concurrent Streams', $out['mostConcurrent'][0]['title']);
        self::assertSame(5, $out['mostConcurrent'][0]['count']);
    }

    /** A representative get_plays_by_date `data` envelope. */
    private function playsByDateFixture(): array
    {
        return [
            'categories' => ['2026-06-01', '2026-06-02', '2026-06-03'],
            'series'     => [
                ['name' => 'TV',     'data' => [3, 0, 5]],
                ['name' => 'Movies', 'data' => [1, 2, 0]],
            ],
        ];
    }

    public function testNormalizePlaysByDateMapsSeries(): void
    {
        $out = TautulliClient::normalizePlaysByDate($this->playsByDateFixture());
        self::assertSame(['2026-06-01', '2026-06-02', '2026-06-03'], $out['categories']);
        self::assertCount(2, $out['series']);
        self::assertSame('TV', $out['series'][0]['name']);
        self::assertSame([3, 0, 5], $out['series'][0]['data']);
        self::assertSame([1, 2, 0], $out['series'][1]['data']);
    }

    public function testNormalizePlaysByDateCoercesAndDefaults(): void
    {
        $out = TautulliClient::normalizePlaysByDate(['series' => [['data' => ['2', '4']]]]);
        self::assertSame([], $out['categories']);
        self::assertSame('', $out['series'][0]['name']);
        self::assertSame([2, 4], $out['series'][0]['data']); // string → int
    }

    public function testNormalizePlaysByDateEmpty(): void
    {
        self::assertSame(['categories' => [], 'series' => []], TautulliClient::normalizePlaysByDate([]));
    }

    public function testNormalizePlaysByDateSkipsNonArraySeriesEntry(): void
    {
        $out = TautulliClient::normalizePlaysByDate([
            'categories' => ['2026-06-01'],
            'series'     => ['not-an-array', ['name' => 'TV', 'data' => [1]]],
        ]);
        self::assertCount(1, $out['series']);
        self::assertSame('TV', $out['series'][0]['name']);
    }

    public function testNormalizePlaysByDateDropsTotalSeries(): void
    {
        $out = TautulliClient::normalizePlaysByDate([
            'categories' => ['2026-06-01', '2026-06-02'],
            'series'     => [
                ['name' => 'TV',     'data' => [3, 1]],
                ['name' => 'Total',  'data' => [4, 3]],
                ['name' => 'Movies', 'data' => [1, 2]],
            ],
        ]);
        // The aggregate "Total" series is dropped (case-insensitive); per-type stays.
        self::assertCount(2, $out['series']);
        self::assertSame(['TV', 'Movies'], array_map(static fn ($s) => $s['name'], $out['series']));
    }

    public function testChartMethodsFailOpenWhenUnconfigured(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);
        $client = new TautulliClient(new ConfigService($repo), new NullLogger(), null);

        $neutral = ['categories' => [], 'series' => []];
        self::assertSame($neutral, $client->getPlaysByStreamType(30));
        self::assertSame($neutral, $client->getPlaysByHourOfDay(30));
        self::assertSame($neutral, $client->getPlaysByDayOfWeek(30));
        self::assertSame($neutral, $client->getStreamTypeByPlatform(30));
        self::assertSame($neutral, $client->getPlaysByDate(30));
    }

    /** A representative get_libraries `data` list. */
    private function librariesFixture(): array
    {
        return [
            ['section_id' => '1', 'section_name' => 'Movies', 'section_type' => 'movie',
             'count' => '1204', 'thumb' => '/library/sections/1/thumb'],
            ['section_id' => '2', 'section_name' => 'TV Shows', 'section_type' => 'show',
             'count' => '312', 'parent_count' => '900', 'child_count' => '8800'],
        ];
    }

    public function testNormalizeLibrariesMapsRows(): void
    {
        $out = TautulliClient::normalizeLibraries($this->librariesFixture());
        self::assertCount(2, $out);
        self::assertSame('Movies', $out[0]['name']);
        self::assertSame('movie', $out[0]['type']);
        self::assertSame(1204, $out[0]['count']);
        self::assertNull($out[0]['childCount']);
        self::assertSame('show', $out[1]['type']);
        self::assertSame(312, $out[1]['count']);
        self::assertSame(8800, $out[1]['childCount']);
    }

    public function testNormalizeLibrariesDropsPrivateFields(): void
    {
        $out  = TautulliClient::normalizeLibraries($this->librariesFixture());
        $flat = json_encode($out);
        self::assertStringNotContainsString('section_id', $flat);
        self::assertStringNotContainsString('/library/sections', $flat);
        self::assertStringNotContainsString('parent_count', $flat);
        foreach ($out as $lib) {
            self::assertArrayNotHasKey('section_id', $lib);
            self::assertArrayNotHasKey('thumb', $lib);
        }
    }

    public function testGetHistoryAcceptsStartOffset(): void
    {
        // Signature accepts (length, start, userId). On an unconfigured client it
        // returns [] without error — proves the 2-arg and 3-arg signatures exist.
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);
        $client = new TautulliClient(
            new ConfigService($repo),
            new NullLogger(),
            null,
        );
        self::assertSame([], $client->getHistory(25, 25));
        self::assertSame([], $client->getHistory(25, 25, '99'));
    }

    public function testNormalizesDynamicRangeAndTranscodeCodecs(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['transcode_decision']        = 'transcode';
        $data['sessions'][0]['video_decision']            = 'transcode';
        $data['sessions'][0]['audio_decision']            = 'transcode';
        $data['sessions'][0]['video_codec']               = 'hevc';
        $data['sessions'][0]['stream_video_codec']        = 'h264';
        $data['sessions'][0]['audio_codec']               = 'truehd';
        $data['sessions'][0]['stream_audio_codec']        = 'aac';
        $data['sessions'][0]['video_dynamic_range']       = 'HDR';
        $data['sessions'][0]['stream_video_dynamic_range']= 'SDR';

        $s = TautulliClient::normalizeActivity($data)['sessions'][0];

        self::assertSame('SDR', $s['dynamicRange']); // stream value preferred
        self::assertSame('hevc', $s['videoCodec']);
        self::assertSame('h264', $s['streamVideoCodec']);
        self::assertSame('truehd', $s['audioCodec']);
        self::assertSame('aac', $s['streamAudioCodec']);

        // Still no private fields after growing the allow-list.
        foreach (['ip_address', 'ip_address_public', 'machine_id', 'session_token', 'file', 'email', 'username'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $s);
        }
    }

    public function testDynamicRangeFallsBackToSourceWhenStreamAbsent(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['video_dynamic_range'] = 'HDR10';
        // no stream_video_dynamic_range key → must fall back to the source value
        $s = TautulliClient::normalizeActivity($data)['sessions'][0];
        self::assertSame('HDR10', $s['dynamicRange']);
    }

    private function usersTableFixture(): array
    {
        return ['data' => [
            ['friendly_name' => 'nDanDan', 'user' => 'nDanDan', 'last_seen' => 1781377600,
             'last_played' => 'See How They Run', 'plays' => 240, 'duration' => 360000,
             'ip_address' => '192.168.1.5', 'email' => 'user@example.com', 'user_id' => 99,
             'user_thumb' => 'https://plex.tv/users/abc/avatar'],
            ['friendly_name' => 'Rob', 'last_seen' => 0, 'last_played' => null, 'plays' => 12, 'duration' => 0],
        ]];
    }

    public function testNormalizeUsersTableMapsRows(): void
    {
        $out = TautulliClient::normalizeUsersTable($this->usersTableFixture());
        self::assertCount(2, $out);
        self::assertSame('nDanDan', $out[0]['friendlyName']);
        self::assertSame(1781377600, $out[0]['lastSeen']);
        self::assertSame('See How They Run', $out[0]['lastPlayed']);
        self::assertSame(240, $out[0]['plays']);
        self::assertSame(360000, $out[0]['durationSeconds']);

        self::assertSame('Rob', $out[1]['friendlyName']);
        self::assertSame(0, $out[1]['lastSeen']);
        self::assertNull($out[1]['lastPlayed']);
        self::assertSame(12, $out[1]['plays']);
        self::assertSame(0, $out[1]['durationSeconds']);
    }

    public function testNormalizeUsersTableNeverLeaksPrivateFields(): void
    {
        $flat = json_encode(TautulliClient::normalizeUsersTable($this->usersTableFixture()));
        self::assertStringNotContainsString('192.168.1.5', $flat);
        self::assertStringNotContainsString('user@example.com', $flat);
        self::assertStringNotContainsString('avatar', $flat);
        foreach (TautulliClient::normalizeUsersTable($this->usersTableFixture()) as $u) {
            self::assertArrayNotHasKey('ip_address', $u);
            self::assertArrayNotHasKey('email', $u);
            self::assertArrayNotHasKey('user_id', $u);
            self::assertArrayNotHasKey('user_thumb', $u);
        }
    }

    public function testNormalizeUserNamesMapsAndDropsIncomplete(): void
    {
        $out = TautulliClient::normalizeUserNames([
            ['friendly_name' => 'nDanDan', 'user_id' => 99],
            ['friendly_name' => 'NoId'],          // dropped — no id
            ['user_id' => 7],                      // dropped — no name
            'not-an-array',                        // dropped
        ]);
        self::assertSame([['name' => 'nDanDan', 'id' => '99']], $out);
    }

    public function testNewChartMethodsFailOpenWhenUnconfigured(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);
        $client = new TautulliClient(new ConfigService($repo), new NullLogger(), null);

        $neutral = ['categories' => [], 'series' => []];
        self::assertSame($neutral, $client->getPlaysBySourceResolution(30, 'duration', '99'));
        self::assertSame($neutral, $client->getPlaysByStreamResolution(30));
        self::assertSame($neutral, $client->getStreamTypeByUser(30));
        self::assertSame($neutral, $client->getConcurrentStreams(30, '99'));
    }
}
