<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class QBittorrentClientTest extends TestCase
{
    /**
     * Issue #28 — qBittorrent 5.2.0 answers `204 No Content` on some Web API
     * endpoints; the runtime client used to demand exactly `200`, so the
     * Downloads page and the health badge said "unreachable" while the
     * connection test was green. Any 2xx must now count as success.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '200 OK'             => [200, true];
        yield '201 Created'        => [201, true];
        yield '204 No Content'     => [204, true];  // the qBit 5.2.0 case
        yield '299 (top of 2xx)'   => [299, true];
        yield '0 (no connection)'  => [0, false];
        yield '301 Moved'          => [301, false];
        yield '401 Unauthorized'   => [401, false];
        yield '403 Forbidden'      => [403, false];
        yield '500 Server Error'   => [500, false];
    }

    #[DataProvider('statuses')]
    public function testIsOkStatusAcceptsTheWhole2xxRange(int $code, bool $expected): void
    {
        $config = $this->createMock(ConfigService::class);
        $client = new QBittorrentClient($config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter()), $this->createMock(ServiceInstanceProvider::class));

        $m = (new \ReflectionClass($client))->getMethod('isOkStatus');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $code));
    }

    /**
     * Issue #33 — qBittorrent 5.2.0 renamed the session cookie from `SID` to
     * `QBT_SID_<port>` (and added HttpOnly/SameSite attributes). The old
     * `Set-Cookie: SID=` extraction stopped matching, so login() produced no
     * session, every authenticated GET 403'd and the qBittorrent tab read
     * "cannot reach" while the connection test still passed. We now capture
     * whichever cookie name is present and echo it back verbatim.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function setCookieHeaders(): iterable
    {
        yield 'legacy SID (<5.2)' => [
            "HTTP/1.1 200 OK\r\nSet-Cookie: SID=abc123XYZ; path=/\r\n\r\n",
            'SID=abc123XYZ',
        ];
        yield 'qBit 5.2.0+ QBT_SID_<port>' => [
            "HTTP/1.1 204 No Content\r\nSet-Cookie: QBT_SID_8112=Zm9vYmFy0=; HttpOnly; SameSite=Strict\r\n\r\n",
            'QBT_SID_8112=Zm9vYmFy0=',
        ];
        yield 'no cookie (wrong creds)' => [
            "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nFails.",
            null,
        ];
    }

    #[DataProvider('setCookieHeaders')]
    public function testExtractSessionCookieHandlesLegacyAndQBit52(string $rawHeaders, ?string $expected): void
    {
        $config = $this->createMock(ConfigService::class);
        $client = new QBittorrentClient($config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter()), $this->createMock(ServiceInstanceProvider::class));

        $m = (new \ReflectionClass($client))->getMethod('extractSessionCookie');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(null, $rawHeaders));
    }
}
