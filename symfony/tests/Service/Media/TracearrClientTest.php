<?php

namespace App\Tests\Service\Media;

use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Service\Media\TracearrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic coverage of TracearrClient's normalizers — the transforms from
 * raw Tracearr public-API payloads into the sanitized shapes the frontend
 * receives — plus the image-proxy allow-list and fail-open paths. No network.
 *
 * The security-critical assertions are the sanitization ones: user ids,
 * avatar URLs and raw codec/transcode payloads present in the raw stream must
 * NOT survive into the normalized output.
 */
#[AllowMockObjectsWithoutExpectations]
class TracearrClientTest extends TestCase
{
    private const SERVER = '3f1c2b9a-6d4e-4f7a-9b8c-1a2b3c4d5e6f';

    /** A representative GET /streams payload with one transcoding episode. */
    private function streamsFixture(): array
    {
        return [
            'data' => [
                [
                    'id'                      => 'b7f0c3c2-0000-4000-8000-000000000001',
                    'serverId'                => self::SERVER,
                    'serverName'              => 'Plex Home',
                    'username'                => 'alice',
                    'userThumb'               => 'https://plex.tv/users/abc/avatar',
                    'userAvatarUrl'           => '/api/v1/images/proxy?server=x&url=y',
                    'mediaTitle'              => 'Pilot',
                    'mediaType'               => 'episode',
                    'showTitle'               => 'Severance',
                    'seasonNumber'            => 1,
                    'episodeNumber'           => 2,
                    'year'                    => 2022,
                    'thumbPath'               => '/library/metadata/123/thumb/456',
                    'posterUrl'               => '/api/v1/images/proxy?server=x&url=y',
                    'durationMs'              => 3_000_000,
                    'state'                   => 'playing',
                    'progressMs'              => 750_000,
                    'isTranscode'             => true,
                    'videoDecision'           => 'transcode',
                    'audioDecision'           => 'copy',
                    'bitrate'                 => 8500,
                    'sourceVideoCodec'        => 'hevc',
                    'sourceVideoCodecDisplay' => 'HEVC',
                    'streamVideoCodec'        => 'h264',
                    'streamVideoCodecDisplay' => 'H.264',
                    'sourceVideoDetails'      => ['dynamicRange' => 'HDR10', 'colorSpace' => 'bt2020'],
                    'transcodeInfo'           => ['hwDecoding' => 'vaapi', 'reasons' => ['codec']],
                    'resolution'              => '4K',
                    'device'                  => 'Apple TV',
                    'player'                  => 'Living Room',
                    'product'                 => 'Plex for Apple TV',
                    'platform'                => 'tvOS',
                ],
            ],
            'summary' => [
                'total'         => 1,
                'transcodes'    => 1,
                'directStreams' => 0,
                'directPlays'   => 0,
                'totalBitrate'  => '8.5 Mbps',
            ],
        ];
    }

    public function testNormalizesStreamSummary(): void
    {
        $out = TracearrClient::normalizeStreams($this->streamsFixture());

        self::assertSame(1, $out['streamCount']);
        self::assertSame(1, $out['transcodeCount']);
        self::assertSame(0, $out['directPlayCount']);
        self::assertSame('8.5 Mbps', $out['totalBitrate']);
        self::assertCount(1, $out['sessions']);
    }

    public function testNormalizesStreamOntoSharedCardKeys(): void
    {
        $s = TracearrClient::normalizeStreams($this->streamsFixture())['sessions'][0];

        self::assertSame('Severance — S01E02 · Pilot', $s['title']);
        self::assertSame('transcode', $s['transcodeDecision']);
        self::assertSame('HEVC', $s['videoCodec']);
        self::assertSame('H.264', $s['streamVideoCodec']);
        self::assertSame('HDR10', $s['dynamicRange']);
        self::assertSame('4K', $s['quality']);
        self::assertSame(8.5, $s['bandwidthMbps']);
        self::assertSame(25.0, $s['progressPercent']);
        self::assertSame('/library/metadata/123/thumb/456', $s['posterPath']);
        self::assertSame(self::SERVER, $s['posterServerId']);
        self::assertNull($s['ratingKey']);
    }

    public function testStreamDropsPrivateFields(): void
    {
        $s = TracearrClient::normalizeStreams($this->streamsFixture())['sessions'][0];
        $json = (string) json_encode($s);

        foreach (['id', 'userThumb', 'userAvatarUrl', 'posterUrl', 'transcodeInfo', 'sourceVideoDetails'] as $key) {
            self::assertArrayNotHasKey($key, $s);
        }
        self::assertStringNotContainsString('plex.tv/users', $json);
        self::assertStringNotContainsString('vaapi', $json);
        self::assertStringNotContainsString('bt2020', $json);
    }

    public function testDirectStreamAndDirectPlayDecisions(): void
    {
        $base = $this->streamsFixture()['data'][0];
        $copy = ['isTranscode' => false, 'videoDecision' => 'copy', 'audioDecision' => 'directplay'] + $base;
        $play = ['isTranscode' => false, 'videoDecision' => 'directplay', 'audioDecision' => 'directplay'] + $base;

        $out = TracearrClient::normalizeStreams(['data' => [$copy, $play]]);

        self::assertSame('copy', $out['sessions'][0]['transcodeDecision']);
        self::assertSame('direct play', $out['sessions'][1]['transcodeDecision']);
        // No summary block → count falls back to the session list.
        self::assertSame(2, $out['streamCount']);
    }

    public function testDeviceEqualToProductIsDropped(): void
    {
        $base = $this->streamsFixture()['data'][0];
        $mac  = ['product' => 'Plex for Mac', 'device' => 'Plex for Mac'] + $base;

        $s = TracearrClient::normalizeStreams(['data' => [$mac]])['sessions'][0];

        self::assertSame('Plex for Mac', $s['product']);
        self::assertNull($s['device']);
    }

    public function testNormalizeStreamsToleratesGarbage(): void
    {
        $out = TracearrClient::normalizeStreams(['data' => 'nope', 'summary' => null]);

        self::assertSame(0, $out['streamCount']);
        self::assertSame([], $out['sessions']);
        self::assertNull($out['totalBitrate']);
    }

    public function testNormalizesStats(): void
    {
        $out = TracearrClient::normalizeStats(
            ['activeStreams' => 2, 'totalUsers' => 9, 'totalSessions' => 140, 'recentViolations' => 1],
            ['todayPlays' => 7, 'watchTimeHours' => 3.456, 'activeUsersToday' => 4, 'alertsLast24h' => 0],
        );

        self::assertSame(2, $out['activeStreams']);
        self::assertSame(9, $out['totalUsers']);
        self::assertSame(140, $out['plays30d']);
        self::assertSame(1, $out['violations7d']);
        self::assertSame(7, $out['todayPlays']);
        self::assertSame(3.5, $out['watchTimeHours']);
        self::assertSame(4, $out['activeUsersToday']);
    }

    public function testNormalizesActivityCharts(): void
    {
        $out = TracearrClient::normalizeActivityCharts([
            'plays' => [
                ['date' => '2026-09-29 00:00:00', 'count' => 3, 'serverId' => 'a'],
                ['date' => '2026-09-29 00:00:00', 'count' => 2, 'serverId' => 'b'],
                ['date' => '2026-09-28 00:00:00', 'count' => 1, 'serverId' => 'a'],
            ],
            'concurrent' => [
                ['date' => '2026-09-29 00:00:00', 'total' => 3, 'direct' => 1, 'directStream' => 1, 'transcode' => 1],
            ],
            'byHourOfDay' => [['hour' => 0, 'count' => 0], ['hour' => 21, 'count' => 5]],
            'byDayOfWeek' => [['day' => 0, 'name' => 'Sun', 'count' => 2]],
            'platforms'   => [['platform' => 'tvOS', 'count' => 4], ['platform' => null, 'count' => 1]],
            'quality'     => ['directPlay' => 6, 'directStream' => 2, 'transcode' => 2, 'total' => 10,
                              'directPlayPercent' => 60, 'directStreamPercent' => 20, 'transcodePercent' => 20],
        ]);

        // Servers are summed per bucket and buckets sorted ascending.
        self::assertSame(['2026-09-28', '2026-09-29'], $out['plays']['categories']);
        self::assertSame([1, 5], $out['plays']['series'][0]['data']);
        self::assertSame(['Direct Play', 'Direct Stream', 'Transcode'], array_column($out['concurrent']['series'], 'name'));
        self::assertSame(['00', '21'], $out['hour']['categories']);
        self::assertSame(['Sun'], $out['dow']['categories']);
        self::assertSame(['tvOS', '—'], $out['platforms']['categories']);
        self::assertSame(60, $out['quality']['directPlayPercent']);
    }

    public function testHourlyPlaysSortedChronologically(): void
    {
        $out = TracearrClient::normalizeActivityCharts([
            'plays' => [
                ['date' => '2027-01-01 00:00:00', 'count' => 4],
                ['date' => '2026-12-31 06:00:00', 'count' => 1],
                ['date' => '2026-12-31 00:00:00', 'count' => 2],
                ['date' => '2026-12-31 18:00:00', 'count' => 3],
            ],
        ]);

        // Midnight buckets stay in place and the year boundary is respected.
        self::assertSame(['2026-12-31', '12-31 06h', '12-31 18h', '2027-01-01'], $out['plays']['categories']);
        self::assertSame([2, 1, 3, 4], $out['plays']['series'][0]['data']);
    }

    public function testActivityChartsEmptyShape(): void
    {
        $out = TracearrClient::normalizeActivityCharts([]);
        $neutral = ['categories' => [], 'series' => []];

        foreach (['plays', 'concurrent', 'hour', 'dow', 'platforms'] as $k) {
            self::assertSame($neutral, $out[$k], $k);
        }
        self::assertSame(0, $out['quality']['total']);
    }

    public function testNormalizesHistoryAndDropsUserIds(): void
    {
        $rows = TracearrClient::normalizeHistory(['data' => [[
            'id'              => 'play-1',
            'serverId'        => self::SERVER,
            'serverName'      => 'Jellyfin',
            'mediaType'       => 'movie',
            'mediaTitle'      => 'Dune',
            'year'            => 2021,
            'thumbPath'       => '/Items/abc/Images/Primary?tag=f00',
            'startedAt'       => '2026-09-29T20:00:00.000Z',
            'durationMs'      => 9_000_000,
            'progressMs'      => 4_500_000,
            'totalDurationMs' => 9_000_000,
            'watched'         => false,
            'platform'        => 'Android',
            'user'            => ['id' => 'user-uuid-secret', 'username' => 'bob', 'thumbUrl' => 'https://x/avatar'],
        ]], 'meta' => ['total' => 1]]);

        self::assertCount(1, $rows);
        self::assertSame('Dune', $rows[0]['title']);
        self::assertSame('bob', $rows[0]['userDisplayName']);
        self::assertSame(50.0, $rows[0]['percentComplete']);
        self::assertSame(strtotime('2026-09-29T20:00:00.000Z'), $rows[0]['watchedAt']);
        self::assertStringNotContainsString('user-uuid-secret', (string) json_encode($rows));
        self::assertStringNotContainsString('avatar', (string) json_encode($rows));
    }

    /** A v2 (snake_case) stream payload: the same normalizer must read it. */
    public function testNormalizesV2StreamWithShowQuickLook(): void
    {
        $show = '167641fe-e2b2-4946-bc80-9453d81b9d76';
        $seen = [];
        $out = TracearrClient::normalizeStreams([
            'data' => [[
                'server_id'                  => self::SERVER,
                'server_type'                => 'plex',
                'username'                   => 'jerome',
                'media_type'                 => 'episode',
                'media_title'                => 'Balle perdue',
                'show_title'                 => 'Reacher',
                'season_number'              => 4,
                'episode_number'             => 6,
                'thumb_path'                 => '/library/metadata/51787/thumb/1',
                'duration_ms'                => 1000,
                'progress_ms'                => 500,
                'is_transcode'               => false,
                'video_decision'             => 'directplay',
                'source_video_codec_display' => 'HEVC',
                'source_video_details'       => ['dynamicRange' => 'SDR'],
                // Episode-level TMDb id — must NOT be used for the quick-look.
                'tmdb_id'                    => 7438866,
                'show_media_id'              => $show,
                'ip_address'                 => '10.0.0.9',
            ]],
            'summary' => ['total' => 1, 'direct_plays' => 1, 'total_bitrate' => '2.7 Mbps'],
        ], function (string $id) use (&$seen): ?int {
            $seen[] = $id;
            return 108978;
        });

        $s = $out['sessions'][0];
        self::assertSame(1, $out['directPlayCount']);
        self::assertSame('2.7 Mbps', $out['totalBitrate']);
        self::assertSame('Reacher — S04E06 · Balle perdue', $s['title']);
        self::assertSame('plex', $s['serverType']);
        self::assertSame('SDR', $s['dynamicRange']);
        self::assertSame(50.0, $s['progressPercent']);
        self::assertSame(['type' => 'tv', 'id' => 108978], $s['quickLook']);
        self::assertSame([$show], $seen);
        self::assertStringNotContainsString('10.0.0.9', (string) json_encode($out));
        self::assertStringNotContainsString($show, (string) json_encode($out));
    }

    public function testQuickLookForMoviesAndUnknowns(): void
    {
        $rows = TracearrClient::normalizeHistory(['data' => [
            ['media_type' => 'movie', 'media_title' => 'Dune', 'tmdb_id' => 438631],
            ['media_type' => 'movie', 'media_title' => 'No id'],
            ['media_type' => 'episode', 'media_title' => 'Ep', 'show_media_id' => 'not-a-uuid'],
            ['media_type' => 'track', 'media_title' => 'Song', 'tmdb_id' => 5],
        ]], fn(string $id): ?int => 1);

        self::assertSame(['type' => 'movie', 'id' => 438631], $rows[0]['quickLook']);
        self::assertNull($rows[1]['quickLook']);
        self::assertNull($rows[2]['quickLook']);
        self::assertNull($rows[3]['quickLook']);
    }

    public function testLastSeenFallsBackToHistory(): void
    {
        $lastSeen = TracearrClient::lastSeenByUser(['data' => [
            ['startedAt' => '2026-09-29T10:00:00Z', 'user' => ['id' => 'u1']],
            ['startedAt' => '2026-09-30T10:00:00Z', 'user' => ['id' => 'u1']],
            ['startedAt' => '2026-09-28T10:00:00Z', 'user' => ['id' => 'u2']],
        ]]);
        self::assertSame(strtotime('2026-09-30T10:00:00Z'), $lastSeen['u1']);

        $rows = TracearrClient::normalizeUsers(['data' => [
            ['id' => 'u1', 'displayName' => 'A', 'lastActivityAt' => null],
            ['id' => 'u3', 'displayName' => 'C', 'lastActivityAt' => null],
            ['id' => 'u2', 'displayName' => 'B', 'lastActivityAt' => '2026-09-01T00:00:00Z'],
        ]], $lastSeen);
        self::assertSame(strtotime('2026-09-30T10:00:00Z'), $rows[0]['lastSeen']);
        self::assertSame(0, $rows[1]['lastSeen']);
        // An explicit lastActivityAt wins over the history fallback.
        self::assertSame(strtotime('2026-09-01T00:00:00Z'), $rows[2]['lastSeen']);
        self::assertArrayNotHasKey('id', $rows[0]);
    }

    public function testCursorValidation(): void
    {
        self::assertTrue(TracearrClient::isCursor('eyJ0IjoiMjAyNi0wOS0yOFQwMzowMDoyOC4wMDBaIn0'));
        self::assertTrue(TracearrClient::isCursor('p3'));
        self::assertFalse(TracearrClient::isCursor('a&b=c'));
        self::assertFalse(TracearrClient::isCursor(''));
        self::assertFalse(TracearrClient::isCursor(str_repeat('a', 513)));
    }

    public function testNormalizesUsers(): void
    {
        $rows = TracearrClient::normalizeUsers(['data' => [[
            'id'             => 'user-uuid',
            'username'       => 'carol',
            'displayName'    => 'Carol',
            'avatarUrl'      => '/api/v1/images/proxy?x',
            'role'           => 'owner',
            'trustScore'     => 87,
            'serverName'     => 'Plex',
            'lastActivityAt' => null,
            'sessionCount'   => 42,
        ]]]);

        self::assertSame(
            [['displayName' => 'Carol', 'serverName' => 'Plex', 'lastSeen' => 0, 'plays' => 42, 'trustScore' => 87]],
            $rows,
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function imagePathProvider(): iterable
    {
        yield 'plex'             => ['/library/metadata/1/thumb/2', true];
        yield 'jellyfin'         => ['/Items/abc123/Images/Primary', true];
        yield 'jellyfin tag'     => ['/Items/abc123/Images/Primary?tag=deadbeef', true];
        yield 'empty'            => ['', false];
        yield 'absolute url'     => ['http://169.254.169.254/latest', false];
        yield 'protocol rel'     => ['//evil.example/x.png', false];
        yield 'traversal'        => ['/library/../../etc/passwd', false];
        yield 'backslash'        => ['/library\\metadata', false];
        yield 'other query'      => ['/Items/a/Images/Primary?url=http://x', false];
        yield 'no leading slash' => ['library/metadata/1', false];
    }

    #[DataProvider('imagePathProvider')]
    public function testImagePathAllowList(string $path, bool $expected): void
    {
        self::assertSame($expected, TracearrClient::isProxyableImagePath($path));
    }

    public function testUuidValidation(): void
    {
        self::assertTrue(TracearrClient::isUuid(self::SERVER));
        self::assertFalse(TracearrClient::isUuid('not-a-uuid'));
        self::assertFalse(TracearrClient::isUuid(self::SERVER . '&x=1'));
    }

    public function testPeriodIsClamped(): void
    {
        self::assertSame('week', TracearrClient::clampPeriod('week'));
        self::assertSame('year', TracearrClient::clampPeriod('year'));
        self::assertSame('month', TracearrClient::clampPeriod('decade'));
    }

    public function testFailsOpenWhenUnconfigured(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);
        $client = new TracearrClient(new ConfigService($repo), new NullLogger(), null);

        self::assertFalse($client->ping());
        self::assertSame('unconfigured', $client->getActivity()['error']);
        self::assertSame(['rows' => [], 'nextCursor' => null], $client->getHistory());
        self::assertNull($client->showTmdbId('167641fe-e2b2-4946-bc80-9453d81b9d76'));
        self::assertSame([], $client->getUsers());
        self::assertSame(0, $client->getStats()['plays30d']);
        self::assertSame(['categories' => [], 'series' => []], $client->getActivityCharts('month')['plays']);
        self::assertNull($client->fetchImage(self::SERVER, '/library/metadata/1/thumb/2'));
    }

    public function testDisabledKillSwitchSuppressesError(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([
            'tracearr_enabled' => '0',
            'tracearr_url'     => 'http://tracearr:3000',
            'tracearr_api_key' => 'trr_pub_x',
        ]);
        $client = new TracearrClient(new ConfigService($repo), new NullLogger(), null);

        $activity = $client->getActivity();
        self::assertFalse($activity['enabled']);
        self::assertNull($activity['error']);
        self::assertFalse($client->ping());
    }
}
