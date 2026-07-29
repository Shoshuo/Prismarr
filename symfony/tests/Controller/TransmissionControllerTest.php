<?php

namespace App\Tests\Controller;

use App\Controller\TransmissionController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransmissionControllerTest extends TestCase
{
    private function invokeStatic(string $method, array $args): mixed
    {
        $m = (new \ReflectionClass(TransmissionController::class))->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs(null, $args);
    }

    /**
     * Transmission hashes are exactly 40 hex chars — unlike qBit, there is
     * no 'all' sentinel (pause-all/resume-all are separate explicit routes).
     *
     * @return iterable<string, array{mixed, array<int, string>}>
     */
    public static function hashInputs(): iterable
    {
        $valid1 = str_repeat('a', 40);
        $valid2 = str_repeat('B', 40);

        yield 'not an array'          => ['not-an-array', []];
        yield 'empty array'           => [[], []];
        yield 'single valid hash'     => [[$valid1], [$valid1]];
        yield 'mixed case valid hash' => [[$valid2], [$valid2]];
        yield 'too short'             => [[str_repeat('a', 39)], []];
        yield 'too long'              => [[str_repeat('a', 41)], []];
        yield 'non-hex characters'    => [['g' . str_repeat('a', 39)], []];
        yield "'all' sentinel rejected" => [['all'], []];
        yield 'non-string entries dropped' => [[123, null, true, $valid1], [$valid1]];
        yield 'valid + invalid mixed' => [[$valid1, 'nope', $valid2], [$valid1, $valid2]];
    }

    #[DataProvider('hashInputs')]
    public function testSanitizeHashesAcceptsOnly40HexCharsNoAllSentinel(mixed $raw, array $expected): void
    {
        $this->assertSame($expected, $this->invokeStatic('sanitizeHashes', [$raw]));
    }

    /**
     * SSRF guard for `/api/add` (Transmission fetches whatever URL we hand
     * it via `filename`): http(s)/magnet only, cloud-metadata hosts blocked,
     * everything else (including LAN) allowed. Static + translator-free so
     * it unit-tests without booting the container.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function urlValidationCases(): iterable
    {
        yield 'https url'                  => ['https://example.com/a.torrent', null];
        yield 'http url'                   => ['http://example.com/a.torrent', null];
        yield 'magnet link'                => ['magnet:?xt=urn:btih:' . str_repeat('a', 40), null];
        yield 'lan http url'               => ['http://192.168.1.10:8080/a.torrent', null];
        yield 'multiple valid lines'       => ["https://a.example/1.torrent\nmagnet:?xt=urn:btih:" . str_repeat('a', 40), null];
        yield 'file scheme blocked'        => ['file:///etc/passwd', 'invalid_url'];
        yield 'gopher scheme blocked'      => ['gopher://example.com', 'invalid_url'];
        yield 'missing host'               => ['https:///a.torrent', 'invalid_url'];
        yield 'aws metadata blocked'       => ['http://169.254.169.254/latest/meta-data/', 'forbidden_host'];
        yield 'gcp metadata blocked'       => ['http://metadata.google.internal/', 'forbidden_host'];
        yield 'azure metadata blocked'     => ['http://metadata.azure.com/', 'forbidden_host'];
        yield 'metadata blocked case-insensitive' => ['http://METADATA.GOOGLE.INTERNAL/', 'forbidden_host'];
        yield 'blank lines ignored'        => ["\n\nhttps://example.com/a.torrent\n\n", null];
    }

    #[DataProvider('urlValidationCases')]
    public function testValidateTorrentUrlsStaticBlocksForbiddenSchemesAndHosts(string $raw, ?string $expectedViolation): void
    {
        $this->assertSame($expectedViolation, $this->invokeStatic('validateTorrentUrlsStatic', [$raw]));
    }
}
