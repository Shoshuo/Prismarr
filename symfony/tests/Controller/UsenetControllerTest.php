<?php

namespace App\Tests\Controller;

use App\Controller\UsenetController;
use App\Entity\Setting;
use App\Service\HealthService;
use App\Service\Media\Usenet\SabnzbdClient;
use App\Service\Media\Usenet\UsenetDownload;
use App\Service\Media\Usenet\UsenetStatus;
use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * #20 — a configured-but-unreachable Usenet client must show an explicit
 * banner (like qBittorrent), not a silent empty page. The page probes the
 * client at render via HealthService::diagnose() (NOT getVersion(): SABnzbd
 * answers mode=version 200 for any key, so a wrong API key would slip past).
 * The diagnosis category drives the banner: auth (bad key/credentials),
 * host_whitelist (host blocked) or a generic unreachable.
 */
#[AllowMockObjectsWithoutExpectations]
class UsenetControllerTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The recent-history preview is cached in the app pool (filesystem in
        // the test env), which outlives a single test — drop both clients' keys
        // so no test inherits another's cached history.
        $cache = static::getContainer()->get(CacheInterface::class);
        foreach (['sabnzbd', 'nzbget'] as $kind) {
            $cache->delete(UsenetController::recentHistoryCacheKey($kind));
        }
    }

    public function testUnreachableSabnzbdShowsErrorBanner(): void
    {
        $em = $this->em();
        // Invalid host resolves to NXDOMAIN fast, so the probe fails quickly.
        $em->persist(new Setting('sabnzbd_url', 'http://sabnzbd.invalid:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'k'));
        $em->flush();

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        // Error banner shown, live content hidden. (We can't assert on
        // "data-usenet-client" — the JS block always references it by selector;
        // the stats markup `data-stat=` only renders inside {% if not error %}.)
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('data-reason="unreachable"', $html);
        $this->assertStringNotContainsString('data-stat="active"', $html);
    }

    public function testInvalidApiKeyShowsAuthBanner(): void
    {
        // Regression for #20: SABnzbd's mode=version returns 200 for ANY key,
        // so the old getVersion() probe left a wrong-key page silently empty.
        // diagnose() probes mode=queue and reports category 'auth' on a 403
        // "API Key Incorrect" → the page must surface the auth banner.
        $em = $this->em();
        $em->persist(new Setting('sabnzbd_url', 'http://sab.test:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'wrong-key'));
        $em->flush();

        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturn(true);
        $health->method('diagnose')->willReturn(['ok' => false, 'category' => 'auth', 'http' => 403]);
        static::getContainer()->set(HealthService::class, $health);

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('data-reason="auth"', $html);
        $this->assertStringNotContainsString('data-stat="active"', $html);
    }

    public function testHostWhitelistShowsDedicatedBanner(): void
    {
        // SABnzbd answers 403 for both a bad key and a host not in its
        // host_whitelist; diagnose() tells them apart so the admin gets the
        // actionable host_whitelist hint instead of a generic failure.
        $em = $this->em();
        $em->persist(new Setting('sabnzbd_url', 'http://sab.test:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'k'));
        $em->flush();

        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturn(true);
        $health->method('diagnose')->willReturn(['ok' => false, 'category' => 'host_whitelist', 'http' => 403]);
        static::getContainer()->set(HealthService::class, $health);

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('data-reason="host_whitelist"', $html);
        $this->assertStringNotContainsString('data-stat="active"', $html);
    }

    public function testUnconfiguredClientRedirectsHome(): void
    {
        // No sabnzbd_* settings seeded → not configured → redirect with flash.
        $this->client->request('GET', '/usenet/sabnzbd');
        $this->assertTrue($this->client->getResponse()->isRedirect());
    }

    // ── Recent history on the downloads page (#47) ────────────────────────────

    public function testDownloadsPageRendersRecentHistory(): void
    {
        $sab = $this->configureSabnzbd();
        $this->mockHealthy();
        // Pin the fetch window: 15 rows from offset 0. That limit governs the
        // per-render cost, especially on NZBGet (no upstream paging).
        $sab->expects($this->once())->method('getHistoryPage')->with(0, 15)->willReturn([
            'items' => [
                $this->historyItem('Recent.One', UsenetStatus::COMPLETED, 1755800000),
                $this->historyItem('Recent.Two', UsenetStatus::FAILED),
            ],
            'total' => 42,
        ]);

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        // The section, its rows and the "view all" link (with the grand total)
        // must all be server-rendered — no poller fills this in.
        $this->assertStringContainsString('Recent history', $html);
        $this->assertStringContainsString('Recent.One', $html);
        $this->assertStringContainsString('Recent.Two', $html);
        $this->assertStringContainsString('class="uh-row" data-status="completed"', $html);
        $this->assertStringContainsString('View all (42)', $html);
        $this->assertStringContainsString('/usenet/sabnzbd/history', $html);
        // Two age cells; exactly one falls back to the em dash, so the dated
        // row really rendered a relative label (locale-independent assertion).
        $this->assertSame(2, substr_count($html, 'class="uh-age"'));
        $this->assertSame(1, substr_count($html, 'class="uh-age">—</div>'));
    }

    public function testDownloadsPageSurvivesHistoryFailure(): void
    {
        // A history call that blows up must not take the whole page with it —
        // the queue still renders and the section is simply absent.
        $sab = $this->configureSabnzbd();
        $this->mockHealthy();
        $sab->method('getHistoryPage')->willThrowException(new \RuntimeException('boom'));

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('Recent history', $html);
        // Match the markup, not the bare class name — the .uh-* stylesheet is
        // included unconditionally, so "uh-row" also appears in the CSS.
        $this->assertStringNotContainsString('class="uh-row"', $html);
        $this->assertStringContainsString('data-stat="active"', $html);
    }

    public function testUnreachableClientSkipsHistoryFetch(): void
    {
        // The probe already failed → the page shows its banner; a doomed
        // history call would only burn the connect timeout.
        $sab = $this->configureSabnzbd();
        $sab->expects($this->never())->method('getHistoryPage');

        $this->client->request('GET', '/usenet/sabnzbd');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('Recent history', (string) $this->client->getResponse()->getContent());
    }

    public function testRecentHistoryIsFetchedOncePerCacheWindow(): void
    {
        // NZBGet's history RPC has no upstream paging — getHistoryPage() pulls
        // the WHOLE retained history and slices locally. Without a short cache
        // that unbounded payload would cross the wire on every page render, so
        // two renders inside the TTL must cost exactly one client call.
        $this->client->disableReboot(); // keep the mocks + cache pool across both renders
        $sab = $this->configureSabnzbd();
        $this->mockHealthy();
        $sab->expects($this->once())->method('getHistoryPage')->with(0, 15)->willReturn([
            'items' => [$this->historyItem('Cached.Release', UsenetStatus::COMPLETED, 1755800000)],
            'total' => 7,
        ]);

        $this->client->request('GET', '/usenet/sabnzbd');
        $first = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/usenet/sabnzbd');
        $second = (string) $this->client->getResponse()->getContent();

        // Both renders show the rows — the second one out of the cache, which
        // also proves the UsenetDownload DTOs survive a round-trip through the
        // pool.
        $this->assertStringContainsString('Cached.Release', $first);
        $this->assertStringContainsString('Cached.Release', $second);
        $this->assertStringContainsString('View all (7)', $second);
    }

    public function testFailedHistoryFetchIsNotCached(): void
    {
        // A transient failure must not be pinned for the whole TTL: the next
        // render retries (mirrors MediaLibraryCache's "empty is not cached").
        $this->client->disableReboot();
        $sab = $this->configureSabnzbd();
        $this->mockHealthy();
        $calls = 0;
        $sab->method('getHistoryPage')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('boom');
            }
            return ['items' => [$this->historyItem('Retried.Release', UsenetStatus::COMPLETED)], 'total' => 1];
        });

        $this->client->request('GET', '/usenet/sabnzbd');
        $first = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/usenet/sabnzbd');
        $second = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString('Recent history', $first);
        $this->assertStringContainsString('Retried.Release', $second);
        $this->assertSame(2, $calls);
    }

    /** Make the render-time probe report a healthy client. */
    private function mockHealthy(): void
    {
        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturn(true);
        $health->method('diagnose')->willReturn(['ok' => true, 'category' => 'ok', 'http' => 200]);
        static::getContainer()->set(HealthService::class, $health);
    }

    // ── History page ─────────────────────────────────────────────────────────

    public function testHistoryPageRendersItems(): void
    {
        $sab = $this->configureSabnzbd();
        $sab->method('getHistoryPage')->willReturn([
            'items' => [$this->historyItem('My.Test.Release', UsenetStatus::COMPLETED)],
            'total' => 1,
        ]);

        $this->client->request('GET', '/usenet/sabnzbd/history');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('My.Test.Release', (string) $this->client->getResponse()->getContent());
    }

    public function testHistoryPagePaginates(): void
    {
        $sab = $this->configureSabnzbd();
        // 120 entries / 50 per page → 3 pages; on page 2 both prev (1) and next (3) link.
        $sab->method('getHistoryPage')->willReturn([
            'items' => [$this->historyItem('Some.Release', UsenetStatus::FAILED)],
            'total' => 120,
        ]);

        $this->client->request('GET', '/usenet/sabnzbd/history?page=2');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('history?page=1', $html);
        $this->assertStringContainsString('history?page=3', $html);
    }

    public function testHistoryPageUnconfiguredRedirects(): void
    {
        $this->client->request('GET', '/usenet/sabnzbd/history');
        $this->assertTrue($this->client->getResponse()->isRedirect());
    }

    private function historyItem(string $name, string $status, ?int $completedAt = null): UsenetDownload
    {
        return new UsenetDownload(
            id: 'x', name: $name, status: $status, rawStatus: 'Completed',
            sizeBytes: 1048576, remainingBytes: 0, percentage: 100.0, category: 'movies',
            etaSeconds: null, speedBytes: 0, failMessage: null, isHistory: true,
            completedAt: $completedAt,
        );
    }

    // ── Actions (write) ──────────────────────────────────────────────────────

    public function testPauseAllReturnsOk(): void
    {
        $sab = $this->configureSabnzbd();
        $sab->expects($this->once())->method('pauseAll')->willReturn(true);

        $this->post('/usenet/sabnzbd/pause');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['ok' => true], $this->jsonResponse());
    }

    public function testActionReturns502WhenClientRejects(): void
    {
        $sab = $this->configureSabnzbd();
        $sab->expects($this->once())->method('resumeAll')->willReturn(false);

        $this->post('/usenet/sabnzbd/resume');

        $this->assertSame(502, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->jsonResponse()['ok']);
    }

    public function testDeleteItemRemovesPartialFiles(): void
    {
        $sab = $this->configureSabnzbd();
        // The page always deletes with files — pin the deleteFiles=true contract.
        $sab->expects($this->once())->method('deleteItem')
            ->with('SABnzbd_nzo_abc', true)->willReturn(true);

        $this->post('/usenet/sabnzbd/item/SABnzbd_nzo_abc/delete');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testSpeedLimitConvertsMbpsToBytes(): void
    {
        $sab = $this->configureSabnzbd();
        // 2 MB/s → 2 * 1024 * 1024 bytes/s.
        $sab->expects($this->once())->method('setSpeedLimitBytes')
            ->with(2 * 1024 * 1024)->willReturn(true);

        $this->post('/usenet/sabnzbd/speed-limit', '{"mbps":2}');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAddUrlRequiresUrl(): void
    {
        $this->configureSabnzbd();

        $this->post('/usenet/sabnzbd/add', '{}');

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->jsonResponse()['ok']);
    }

    public function testAddUrlRejectsSsrfUrl(): void
    {
        // The downloader fetches the URL server-side; a link-local / metadata
        // URL must be rejected before it ever reaches the client.
        $sab = $this->configureSabnzbd();
        $sab->expects($this->never())->method('addNzbFromUrl');

        $this->post('/usenet/sabnzbd/add', '{"url":"http://169.254.169.254/latest/meta-data/"}');

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->jsonResponse()['ok']);
    }

    public function testAddUrlForwardsToClient(): void
    {
        $sab = $this->configureSabnzbd();
        $sab->expects($this->once())->method('addNzbFromUrl')
            ->with('http://indexer.test/x.nzb', 'movies')->willReturn(true);

        $this->post('/usenet/sabnzbd/add', '{"url":"http://indexer.test/x.nzb","category":"movies"}');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkPauseAppliesToEachId(): void
    {
        $sab = $this->configureSabnzbd();
        $sab->expects($this->exactly(2))->method('pauseItem')->willReturn(true);

        $this->post('/usenet/sabnzbd/bulk/pause', '{"ids":["A","B"]}');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = $this->jsonResponse();
        $this->assertTrue($json['ok']);
        $this->assertSame(2, $json['count']);
    }

    public function testBulkDeleteRemovesPartialFiles(): void
    {
        $sab = $this->configureSabnzbd();
        $seen = [];
        $sab->method('deleteItem')->willReturnCallback(function (string $id, bool $files) use (&$seen) {
            $seen[] = [$id, $files];
            return true;
        });

        $this->post('/usenet/sabnzbd/bulk/delete', '{"ids":["A"]}');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([['A', true]], $seen);
    }

    public function testBulkRequiresIds(): void
    {
        $this->configureSabnzbd();
        $this->post('/usenet/sabnzbd/bulk/pause', '{}');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testActionRejectsGet(): void
    {
        $this->configureSabnzbd();
        $this->client->request('GET', '/usenet/sabnzbd/pause');
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testActionOnUnconfiguredClientReturns403(): void
    {
        // No sabnzbd_* settings → isConfigured false → 403, no client touched.
        $this->post('/usenet/sabnzbd/pause');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Seed a configured SABnzbd and swap the autowired client for a mock so the
     * action endpoints run without touching a real downloader.
     *
     * @return SabnzbdClient&MockObject
     */
    private function configureSabnzbd(): MockObject
    {
        $em = $this->em();
        $em->persist(new Setting('sabnzbd_url', 'http://sab.test:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'k'));
        $em->flush();

        $mock = $this->createMock(SabnzbdClient::class);
        static::getContainer()->set(SabnzbdClient::class, $mock);

        return $mock;
    }

    private function post(string $path, string $json = '{}'): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE'        => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], $json);
    }

    /** @return array<string, mixed> */
    private function jsonResponse(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
