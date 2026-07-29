<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\TransmissionClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class TransmissionClientTest extends TestCase
{
    private function makeClient(): TransmissionClient
    {
        $config = $this->createMock(ConfigService::class);

        return new TransmissionClient($config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter()));
    }

    /**
     * The 409 handshake is the expected first round trip, not a failure —
     * the fresh session id must be parsed out of the raw response headers
     * regardless of casing or surrounding headers.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function sessionIdHeaders(): iterable
    {
        yield 'canonical casing' => [
            "HTTP/1.1 409 Conflict\r\nX-Transmission-Session-Id: abc123XYZ==\r\n\r\n",
            'abc123XYZ==',
        ];
        yield 'lowercase header name' => [
            "HTTP/1.1 409 Conflict\r\nx-transmission-session-id: lower-case-id\r\n\r\n",
            'lower-case-id',
        ];
        yield 'surrounded by other headers' => [
            "HTTP/1.1 409 Conflict\r\nServer: Transmission\r\nX-Transmission-Session-Id: mid-id\r\nContent-Length: 0\r\n\r\n",
            'mid-id',
        ];
        yield 'missing header' => [
            "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\n",
            null,
        ];
    }

    #[DataProvider('sessionIdHeaders')]
    public function testExtractSessionIdParsesThe409Handshake(string $rawHeaders, ?string $expected): void
    {
        $client = $this->makeClient();

        $m = (new \ReflectionClass($client))->getMethod('extractSessionId');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $rawHeaders));
    }

    /**
     * A non-zero `error` field always wins over `status`, matching Deluge's
     * precedence; otherwise Transmission's numeric status maps onto the
     * same state vocabulary qBit/Deluge normalize to.
     *
     * @return iterable<string, array{int, int, string}>
     */
    public static function statusMappings(): iterable
    {
        yield 'stopped (0)'                => [0, 0, 'paused'];
        yield 'verify queued (1)'          => [1, 0, 'checking'];
        yield 'verifying (2)'              => [2, 0, 'checking'];
        yield 'download queued (3)'        => [3, 0, 'queued'];
        yield 'downloading (4)'            => [4, 0, 'downloading'];
        yield 'seed queued (5)'            => [5, 0, 'queued'];
        yield 'seeding (6)'                => [6, 0, 'seeding'];
        yield 'unknown status code'        => [42, 0, 'unknown'];
        yield 'error overrides seeding'    => [6, 1, 'error'];
        yield 'error overrides downloading' => [4, 2, 'error'];
    }

    #[DataProvider('statusMappings')]
    public function testNormalizeStateAppliesErrorPrecedence(int $status, int $errorNum, string $expected): void
    {
        $client = $this->makeClient();

        $m = (new \ReflectionClass($client))->getMethod('normalizeState');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $status, $errorNum));
    }

    /**
     * Transmission speed limits are KiB/s with 0/disabled meaning unlimited;
     * Prismarr's shared UI speaks bytes/s with -1 meaning unlimited.
     *
     * @return iterable<string, array{float, int}>
     */
    public static function kibToBytesCases(): iterable
    {
        yield 'disabled (0)'   => [0.0, -1];
        yield 'negative'       => [-5.0, -1];
        yield '1 MiB/s'        => [1024.0, 1024 * 1024];
        yield 'fractional KiB' => [10.5, (int) round(10.5 * 1024)];
    }

    #[DataProvider('kibToBytesCases')]
    public function testKibToBytesConvertsAndTreatsZeroAsUnlimited(float $kib, int $expected): void
    {
        $client = $this->makeClient();

        $m = (new \ReflectionClass($client))->getMethod('kibToBytes');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $kib));
    }

    /**
     * A user-entered URL with a redundant `/transmission`, `/transmission/rpc`,
     * or `/transmission/web` suffix must be stripped, otherwise it doubles up
     * with the `/transmission/rpc` suffix httpPost() always appends.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function baseUrlCases(): iterable
    {
        yield 'plain host:port'               => ['http://192.168.86.10:9091', 'http://192.168.86.10:9091'];
        yield 'trailing slash'                 => ['http://192.168.86.10:9091/', 'http://192.168.86.10:9091'];
        yield 'redundant /transmission suffix' => ['http://192.168.86.10:9091/transmission', 'http://192.168.86.10:9091'];
        yield 'redundant /transmission/ suffix with trailing slash' => ['http://192.168.86.10:9091/transmission/', 'http://192.168.86.10:9091'];
        yield 'redundant /transmission/rpc suffix' => ['http://192.168.86.10:9091/transmission/rpc', 'http://192.168.86.10:9091'];
        yield 'redundant /transmission/web suffix' => ['http://192.168.86.10:9091/transmission/web', 'http://192.168.86.10:9091'];
        yield 'case-insensitive suffix'        => ['http://192.168.86.10:9091/Transmission', 'http://192.168.86.10:9091'];
        yield 'reverse-proxy path preserved'   => ['http://host.docker.internal:8080/transmission-daemon', 'http://host.docker.internal:8080/transmission-daemon'];
    }

    #[DataProvider('baseUrlCases')]
    public function testNormalizeBaseUrlStripsRedundantTransmissionSuffix(string $input, string $expected): void
    {
        $client = $this->makeClient();

        $m = (new \ReflectionClass($client))->getMethod('normalizeBaseUrl');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $input));
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function bytesToKibCases(): iterable
    {
        yield 'unlimited (-1)' => [-1, 0.0];
        yield 'zero'           => [0, 0.0];
        yield '1 MiB/s'        => [1024 * 1024, 1024.0];
    }

    #[DataProvider('bytesToKibCases')]
    public function testBytesToKibRoundTripsWithKibToBytes(int $bytes, float $expected): void
    {
        $client = $this->makeClient();

        $m = (new \ReflectionClass($client))->getMethod('bytesToKib');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $bytes));
    }
}
