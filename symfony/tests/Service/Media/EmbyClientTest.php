<?php

namespace App\Tests\Service\Media;

use App\Service\Media\EmbyClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage of EmbyClient::normalizeSessions() — the transform from
 * a raw Emby `GET /Sessions` list into the sanitized shape the frontend
 * receives. No network: the input is a captured-style fixture.
 *
 * The security-critical assertions are the sanitization ones: private fields
 * (remote address, device id, user id, access token, file path) present in
 * the raw session must NOT survive into the normalized output.
 */
class EmbyClientTest extends TestCase
{
    /** One idle session (app open, nothing playing) + one 4K episode playing directly on the LAN. */
    private function fixture(): array
    {
        return [
            [
                'Id'             => 'idle-session',
                'UserName'       => 'alice',
                'Client'         => 'Emby Web',
                'DeviceName'     => 'Chrome',
                'RemoteEndPoint' => '192.168.1.20',
                'PlayState'      => ['IsPaused' => false, 'PlayMethod' => null],
            ],
            [
                'Id'               => 'a1b2c3',
                'UserId'           => 'user-guid-secret',
                'UserName'         => 'alice',
                'Client'           => 'AndroidTv',
                'DeviceName'       => 'Living room TV',
                'DeviceId'         => 'device-guid-secret',
                'RemoteEndPoint'   => '192.168.1.30',
                'AccessToken'      => 'token-secret',
                'PlayState'        => ['PositionTicks' => 6777272000, 'IsPaused' => false, 'PlayMethod' => 'DirectPlay'],
                'NowPlayingItem'   => [
                    'Id'                => '167872',
                    'Name'              => 'Lights Out',
                    'Type'              => 'Episode',
                    'SeriesName'        => 'Lanterns',
                    'SeriesId'          => '167870',
                    'ParentIndexNumber' => 1,
                    'IndexNumber'       => 5,
                    'ProductionYear'    => 2026,
                    'RunTimeTicks'      => 33886360000,
                    'Path'              => '/storage/TV/Lanterns/S01/Lanterns.s01e05.mkv',
                    'Bitrate'           => 19813063,
                    'MediaStreams'      => [
                        ['Type' => 'Video', 'Codec' => 'hevc', 'Width' => 3840, 'Height' => 1920, 'VideoRange' => 'DolbyVision', 'ExtendedVideoType' => 'DolbyVision', 'IsDefault' => true],
                        ['Type' => 'Audio', 'Codec' => 'eac3', 'Channels' => 6, 'IsDefault' => true],
                        ['Type' => 'Subtitle', 'Codec' => 'srt'],
                    ],
                ],
            ],
        ];
    }

    public function testIdleSessionsAreSkippedAndCountsAreDerived(): void
    {
        $out = EmbyClient::normalizeSessions($this->fixture());

        self::assertSame(1, $out['streamCount']);
        self::assertSame(1, $out['directPlayCount']);
        self::assertSame(0, $out['directStreamCount']);
        self::assertSame(0, $out['transcodeCount']);
        self::assertCount(1, $out['sessions']);
    }

    public function testEpisodeSessionIsNormalized(): void
    {
        $s = EmbyClient::normalizeSessions($this->fixture())['sessions'][0];

        self::assertSame('a1b2c3', $s['sessionId']);
        self::assertSame('167872', $s['ratingKey']);
        self::assertSame('167870', $s['posterPath'], 'episodes use the series poster');
        self::assertSame('Lanterns - S01E05 - Lights Out', $s['title']);
        self::assertSame('Lanterns', $s['grandparentTitle']);
        self::assertSame('2026', $s['year']);
        self::assertSame('episode', $s['mediaType']);
        self::assertSame('playing', $s['state']);
        self::assertSame('alice', $s['userDisplayName']);
        self::assertSame('AndroidTv', $s['product']);
        self::assertSame('Living room TV', $s['player']);
        self::assertSame('direct play', $s['transcodeDecision']);
        self::assertSame('copy', $s['videoDecision']);
        self::assertSame('4K', $s['quality']);
        self::assertSame('Dolby Vision', $s['dynamicRange']);
        self::assertSame('hevc', $s['videoCodec']);
        self::assertSame('eac3', $s['audioCodec']);
        self::assertSame('lan', $s['location']);
        self::assertSame(19813, $s['bandwidthKbps']);
        self::assertSame(19.8, $s['bandwidthMbps']);
        self::assertSame(20.0, $s['progressPercent']);
        self::assertSame(3388, $s['durationSeconds']);
    }

    public function testBandwidthIsSplitByLocation(): void
    {
        $data = $this->fixture();
        $wan = $data[1];
        $wan['Id'] = 'wan-session';
        $wan['RemoteEndPoint'] = '203.0.113.9:51234';
        $data[] = $wan;

        $out = EmbyClient::normalizeSessions($data);

        self::assertSame(2, $out['streamCount']);
        self::assertSame(19813, $out['bandwidth']['lanKbps']);
        self::assertSame(19813, $out['bandwidth']['wanKbps']);
        self::assertSame(39626, $out['bandwidth']['totalKbps']);
        self::assertSame(39.6, $out['bandwidth']['totalMbps']);
        self::assertSame('wan', $out['sessions'][1]['location']);
    }

    /** Sensitive raw fields must never be copied out (allow-list normalization). */
    public function testSensitiveFieldsDoNotSurvive(): void
    {
        $json = json_encode(EmbyClient::normalizeSessions($this->fixture()));
        self::assertNotFalse($json);

        foreach (['192.168.1.30', '192.168.1.20', 'device-guid-secret', 'user-guid-secret', 'token-secret', '/storage/TV', 'RemoteEndPoint', 'AccessToken'] as $needle) {
            self::assertStringNotContainsString($needle, $json, "$needle leaked into the normalized output");
        }
    }

    public function testTranscodeSessionUsesTranscoderBitrateAndCodecs(): void
    {
        $data = $this->fixture();
        $data[1]['PlayState']['PlayMethod'] = 'Transcode';
        $data[1]['PlayState']['IsPaused'] = true;
        $data[1]['TranscodingInfo'] = [
            'VideoCodec' => 'h264', 'AudioCodec' => 'aac', 'Bitrate' => 8000000,
            'IsVideoDirect' => false, 'IsAudioDirect' => false,
        ];

        $out = EmbyClient::normalizeSessions($data);
        $s = $out['sessions'][0];

        self::assertSame(1, $out['transcodeCount']);
        self::assertSame('transcode', $s['transcodeDecision']);
        self::assertSame('paused', $s['state']);
        self::assertSame('transcode', $s['videoDecision']);
        self::assertSame('transcode', $s['audioDecision']);
        self::assertSame('hevc', $s['videoCodec']);
        self::assertSame('h264', $s['streamVideoCodec']);
        self::assertSame('eac3', $s['audioCodec']);
        self::assertSame('aac', $s['streamAudioCodec']);
        self::assertSame(8000, $s['bandwidthKbps']);
    }

    public function testDirectStreamCountsAsCopy(): void
    {
        $data = $this->fixture();
        $data[1]['PlayState']['PlayMethod'] = 'DirectStream';

        $out = EmbyClient::normalizeSessions($data);

        self::assertSame(1, $out['directStreamCount']);
        self::assertSame('copy', $out['sessions'][0]['transcodeDecision']);
    }

    public function testMoviePosterIsTheItemItselfAndTitleIsBare(): void
    {
        $data = $this->fixture();
        $data[1]['NowPlayingItem'] = [
            'Id' => '555', 'Name' => 'Sirāt', 'Type' => 'Movie', 'ProductionYear' => 2025, 'RunTimeTicks' => 0,
            'MediaStreams' => [['Type' => 'Video', 'Codec' => 'hevc', 'Width' => 1920, 'Height' => 800, 'VideoRange' => 'SDR']],
        ];

        $s = EmbyClient::normalizeSessions($data)['sessions'][0];

        self::assertSame('movie', $s['mediaType']);
        self::assertSame('Sirāt', $s['title'], 'unicode titles pass through untouched');
        self::assertSame('555', $s['posterPath']);
        self::assertSame('1080p', $s['quality']);
        self::assertSame('SDR', $s['dynamicRange']);
        self::assertSame(0.0, $s['progressPercent'], 'zero runtime must not divide by zero');
    }

    public function testEmptyAndMalformedPayloadsYieldTheNeutralShape(): void
    {
        foreach ([[], ['garbage', 42, null], [['NowPlayingItem' => 'not-an-array']]] as $payload) {
            $out = EmbyClient::normalizeSessions($payload);
            self::assertSame(0, $out['streamCount']);
            self::assertSame([], $out['sessions']);
            self::assertSame(0.0, $out['bandwidth']['totalMbps']);
        }
    }

    public function testEpisodeTitleSkipsMissingNumbers(): void
    {
        $data = $this->fixture();
        unset($data[1]['NowPlayingItem']['IndexNumber']);

        $s = EmbyClient::normalizeSessions($data)['sessions'][0];

        self::assertSame('Lanterns - Lights Out', $s['title']);
    }

    #[DataProvider('itemIdProvider')]
    public function testIsItemId(string $id, bool $expected): void
    {
        self::assertSame($expected, EmbyClient::isItemId($id));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function itemIdProvider(): iterable
    {
        yield 'numeric'            => ['167872', true];
        yield 'guid'               => ['3914bb89348d4b7c9a46583451582ee5', true];
        yield 'empty'              => ['', false];
        yield 'path traversal'     => ['../Users', false];
        yield 'query injection'    => ['1?api_key=x', false];
        yield 'slash'              => ['1/Images', false];
        yield 'too long numeric'   => [str_repeat('9', 21), false];
        yield 'unicode'            => ['１２３', false];
    }

    public function testTmdbIdFromItem(): void
    {
        self::assertSame(['type' => 'movie', 'id' => 42], EmbyClient::tmdbIdFromItem(['Type' => 'Movie', 'ProviderIds' => ['Imdb' => 'tt1', 'Tmdb' => '42']]));
        self::assertSame(['type' => 'tv', 'id' => 95350], EmbyClient::tmdbIdFromItem(['Type' => 'Series', 'ProviderIds' => ['tmdb' => 95350]]));
        self::assertNull(EmbyClient::tmdbIdFromItem(['Type' => 'Episode', 'ProviderIds' => ['Tmdb' => '7']]), 'episodes resolve through the series');
        self::assertNull(EmbyClient::tmdbIdFromItem(['Type' => 'Movie', 'ProviderIds' => ['Tmdb' => 'abc']]));
        self::assertNull(EmbyClient::tmdbIdFromItem(['Type' => 'Movie']));
        self::assertNull(EmbyClient::tmdbIdFromItem([]));
    }
}
