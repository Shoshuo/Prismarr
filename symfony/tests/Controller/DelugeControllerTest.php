<?php

namespace App\Tests\Controller;

use App\Controller\DelugeController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DelugeControllerTest extends TestCase
{
    private function invokeStatic(string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass(DelugeController::class))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    /**
     * Deluge hashes are exactly 40 hex chars. No 'all' sentinel (unlike qBit).
     */
    public function testSanitizeHashesKeepsOnly40CharHex(): void
    {
        $valid = str_repeat('a1', 20); // 40 chars
        $out = $this->invokeStatic('sanitizeHashes', [
            $valid,
            'all',                       // qBit sentinel — NOT valid for Deluge
            str_repeat('a', 32),         // qBit-length v1 hash prefix — reject
            'zz' . substr($valid, 2),    // non-hex
            42,                          // not a string
        ]);
        $this->assertSame([$valid], $out);
    }

    /**
     * Same SSRF policy as the qBit add box: http(s)/magnet only, cloud
     * metadata hosts blocked, LAN allowed (private trackers are legitimate).
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function urlCases(): iterable
    {
        yield 'magnet ok'          => ['magnet:?xt=urn:btih:abc', true];
        yield 'https ok'           => ['https://tracker.example/file.torrent', true];
        yield 'lan ok'             => ['http://192.168.1.10:8112/file.torrent', true];
        yield 'file scheme'        => ['file:///etc/passwd', false];
        yield 'gopher scheme'      => ['gopher://evil/x', false];
        yield 'aws metadata'       => ['http://169.254.169.254/latest/meta-data/', false];
        yield 'gcp metadata'       => ['http://metadata.google.internal/computeMetadata/', false];
    }

    #[DataProvider('urlCases')]
    public function testValidateTorrentUrls(string $url, bool $ok): void
    {
        $error = $this->invokeStatic('validateTorrentUrlsStatic', $url);
        $ok ? $this->assertNull($error) : $this->assertNotNull($error);
    }
}
