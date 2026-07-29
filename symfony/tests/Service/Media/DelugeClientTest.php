<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\DelugeClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class DelugeClientTest extends TestCase
{
    private function makeClient(): DelugeClient
    {
        $config = $this->createMock(ConfigService::class);
        return new DelugeClient($config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter()));
    }

    private function invokeStatic(string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass(DelugeClient::class))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    /**
     * deluge-web answers HTTP 200 for EVERYTHING — the real outcome lives in
     * the JSON-RPC envelope. parseRpcBody() must return the result on
     * success and a structured error otherwise (including malformed JSON,
     * which happens when a reverse proxy serves an HTML error page).
     *
     * @return iterable<string, array{string, mixed, ?string}>
     */
    public static function rpcBodies(): iterable
    {
        yield 'success with result'  => ['{"result": "2.1.1", "error": null, "id": 1}', '2.1.1', null];
        yield 'success null result'  => ['{"result": null, "error": null, "id": 2}', null, null];
        yield 'auth error (code 1)'  => ['{"result": null, "error": {"message": "Not authenticated", "code": 1}, "id": 3}', null, 'Not authenticated'];
        yield 'plugin missing'       => ['{"result": null, "error": {"message": "Unknown method", "code": 2}, "id": 4}', null, 'Unknown method'];
        yield 'malformed body'       => ['<html>proxy error</html>', null, 'malformed JSON-RPC response'];
    }

    #[DataProvider('rpcBodies')]
    public function testParseRpcBody(string $body, mixed $expectedResult, ?string $expectedError): void
    {
        $parsed = $this->invokeStatic('parseRpcBody', $body);
        $this->assertSame($expectedResult, $parsed['result']);
        if ($expectedError === null) {
            $this->assertNull($parsed['error']);
        } else {
            $this->assertSame($expectedError, $parsed['error']['message']);
        }
    }

    public function testParseRpcBodyExposesErrorCodeForReloginDetection(): void
    {
        $parsed = $this->invokeStatic('parseRpcBody', '{"result": null, "error": {"message": "Not authenticated", "code": 1}, "id": 9}');
        $this->assertSame(1, $parsed['error']['code']);
    }

    /**
     * deluge-web sets `_session_id` on auth.login. We must echo it back as a
     * ready-to-send "name=value" pair, and return null when login failed
     * (wrong password → result false, no cookie).
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function setCookieHeaders(): iterable
    {
        yield 'session cookie' => [
            "HTTP/1.1 200 OK\r\nSet-Cookie: _session_id=abcDEF123; Expires=...; Path=/json\r\n\r\n",
            '_session_id=abcDEF123',
        ];
        yield 'no cookie (bad password)' => [
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n",
            null,
        ];
    }

    #[DataProvider('setCookieHeaders')]
    public function testExtractSessionCookie(string $rawHeaders, ?string $expected): void
    {
        $this->assertSame($expected, $this->invokeStatic('extractSessionCookie', $rawHeaders));
    }

    /**
     * Deluge state names → the normalized vocabulary the (copied) qBit
     * template understands. `Paused` stays paused even when finished —
     * Deluge has no "completed" state.
     *
     * @return iterable<string, array{string, bool, string}>
     */
    public static function states(): iterable
    {
        yield 'Downloading'        => ['Downloading', false, 'downloading'];
        yield 'Seeding'            => ['Seeding', true, 'seeding'];
        yield 'Paused unfinished'  => ['Paused', false, 'paused'];
        yield 'Paused finished'    => ['Paused', true, 'paused'];
        yield 'Queued'             => ['Queued', false, 'queued'];
        yield 'Checking'           => ['Checking', false, 'checking'];
        yield 'Error'              => ['Error', false, 'error'];
        yield 'Moving'             => ['Moving', true, 'moving'];
        yield 'Allocating'         => ['Allocating', false, 'downloading'];
        yield 'unknown unfinished' => ['Bogus', false, 'unknown'];
        yield 'unknown finished'   => ['Bogus', true, 'seeding'];
    }

    #[DataProvider('states')]
    public function testNormalizeState(string $state, bool $finished, string $expected): void
    {
        $this->assertSame($expected, $this->invokeStatic('normalizeState', $state, $finished));
    }

    /**
     * Deluge speaks KiB/s for speed limits (-1 = unlimited); the rest of
     * Prismarr (and the copied template) speaks bytes/s.
     */
    public function testSpeedLimitUnitConversions(): void
    {
        $this->assertSame(-1, $this->invokeStatic('kibToBytes', -1.0));
        $this->assertSame(1024, $this->invokeStatic('kibToBytes', 1.0));
        $this->assertSame(512000, $this->invokeStatic('kibToBytes', 500.0));
        $this->assertSame(-1.0, $this->invokeStatic('bytesToKib', 0));
        $this->assertSame(-1.0, $this->invokeStatic('bytesToKib', -1));
        $this->assertSame(500.0, $this->invokeStatic('bytesToKib', 512000));
    }

    public function testNormalizeTorrentMapsDelugeStatusToQbitShape(): void
    {
        $client = $this->makeClient();
        $m = (new \ReflectionClass($client))->getMethod('normalizeTorrent');
        $m->setAccessible(true);

        $t = $m->invoke($client, 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', [
            'name' => 'Example.2026.1080p.WEB.h264-GRP',
            'total_wanted' => 4000000000, 'total_size' => 4000000000,
            'total_done' => 4000000000, 'all_time_download' => 4100000000,
            'total_uploaded' => 900000000,
            'progress' => 100.0, 'download_payload_rate' => 0, 'upload_payload_rate' => 12345,
            'eta' => 0, 'state' => 'Seeding', 'is_finished' => true,
            'label' => 'tv-sonarr', 'ratio' => 0.219512,
            'num_seeds' => 1, 'total_seeds' => 14, 'num_peers' => 2, 'total_peers' => 3,
            'time_added' => 1751000000, 'completed_time' => 1751100000,
            'save_path' => '/downloads', 'tracker_host' => 'xspeeds.eu',
            'seeding_time' => 86400, 'max_download_speed' => -1.0, 'max_upload_speed' => 500.0,
            'distributed_copies' => 14.97,
        ]);

        $this->assertSame('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', $t['hash']);
        $this->assertSame('seeding', $t['state']);
        $this->assertSame('Seeding', $t['raw_state']);
        $this->assertSame(100.0, $t['progress']);
        $this->assertSame('tv-sonarr', $t['category']);   // Deluge label rides the category field
        $this->assertSame('', $t['tags']);
        $this->assertSame(0.22, $t['ratio']);
        $this->assertSame(8640000, $t['eta']);            // 0 → qBit "no ETA" sentinel
        $this->assertSame(1751000000, $t['added_on']);
        $this->assertSame(1751100000, $t['completion_on']);
        $this->assertSame('xspeeds.eu', $t['tracker']);
        $this->assertSame(-1, $t['dl_limit']);
        $this->assertSame(512000, $t['up_limit']);        // 500 KiB/s → bytes
        $this->assertSame(86400, $t['seeding_time']);
    }

    /**
     * splitAddUrls() feeds addTorrentFromUrl(): magnet lines go to
     * core.add_torrent_magnet, http(s) lines to core.add_torrent_url.
     * Split on newlines and pipes like the qBit add box.
     */
    public function testSplitAddUrlsSeparatesMagnetsFromHttp(): void
    {
        $split = $this->invokeStatic('splitAddUrls', "magnet:?xt=urn:btih:aaa\nhttps://tracker.example/x.torrent | magnet:?xt=urn:btih:bbb");
        $this->assertSame(['magnet:?xt=urn:btih:aaa', 'magnet:?xt=urn:btih:bbb'], $split['magnets']);
        $this->assertSame(['https://tracker.example/x.torrent'], $split['urls']);
    }
}
