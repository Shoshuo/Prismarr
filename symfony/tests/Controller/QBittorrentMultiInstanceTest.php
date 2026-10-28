<?php

namespace App\Tests\Controller;

use App\Entity\ServiceInstance;
use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #131 — qBittorrent routes are slug-aware (`/qbittorrent/{slug}`),
 * the pre-#131 slug-less URLs redirect to the default instance, the global
 * poll sums every instance, and the VPN card follows the Gluetun instance
 * the selected qBittorrent links to.
 *
 * Instances point at 127.0.0.1:1 (connection refused at once): the goal is
 * the routing / rendering, not a reachable qBittorrent.
 */
class QBittorrentMultiInstanceTest extends AbstractWebTestCase
{
    private function seedQbt(string $slug, string $name, bool $default = false, bool $enabled = true, ?ServiceInstance $gluetun = null): ServiceInstance
    {
        $i = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, $slug, $name, 'http://127.0.0.1:1');
        $i->setIsDefault($default);
        $i->setEnabled($enabled);
        $i->setPosition($default ? 0 : 1);
        $i->setGluetunInstanceId($gluetun?->getId());
        $em = $this->em();
        $em->persist($i);
        $em->flush();
        return $i;
    }

    private function seedGluetun(string $slug, string $name, string $url = 'http://127.0.0.1:2', bool $enabled = true): ServiceInstance
    {
        $g = new ServiceInstance(ServiceInstance::TYPE_GLUETUN, $slug, $name, $url, 'gluetun-test-key');
        $g->setEnabled($enabled);
        $em = $this->em();
        $em->persist($g);
        $em->flush();
        return $g;
    }

    /** Gluetun instance the request's GluetunClient was bound to. */
    private function boundGluetun(): ?ServiceInstance
    {
        return static::getContainer()->get(\App\Service\Media\GluetunClient::class)->getInstance();
    }

    private function assertRedirectsTo(string $from, string $expectedLocation): void
    {
        $this->client->request('GET', $from);
        $res = $this->client->getResponse();
        $this->assertSame(Response::HTTP_TEMPORARY_REDIRECT, $res->getStatusCode(), "GET $from");
        $this->assertSame($expectedLocation, $res->headers->get('Location'), "GET $from");
    }

    public function testLegacyUrlsRedirectToTheDefaultInstance(): void
    {
        $this->seedQbt('qbittorrent-1', 'Home', default: true);
        $this->seedQbt('qbittorrent-2', 'Seedbox');

        $this->assertRedirectsTo('/qbittorrent', '/qbittorrent/qbittorrent-1');
        $this->assertRedirectsTo('/qbittorrent/api/torrents?filter=all&page=2', '/qbittorrent/qbittorrent-1/api/torrents?filter=all&page=2');
        $this->assertRedirectsTo('/qbittorrent/api/torrent/abc/pause', '/qbittorrent/qbittorrent-1/api/torrent/abc/pause');
    }

    public function testLegacyUrlWithoutAnyInstanceGoesHome(): void
    {
        $this->client->request('GET', '/qbittorrent');
        $res = $this->client->getResponse();
        $this->assertTrue($res->isRedirect());
        $this->assertSame('/', $res->headers->get('Location'));
    }

    public function testUnknownSlugIs404(): void
    {
        $this->seedQbt('qbittorrent-1', 'Home', default: true);

        $this->client->request('GET', '/qbittorrent/ghost');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSingleInstancePageHasNoSwitcher(): void
    {
        $this->seedQbt('qbittorrent-1', 'qBittorrent 1', default: true);

        $this->client->request('GET', '/qbittorrent/qbittorrent-1');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('qbt-instance-switcher', $body);
        $this->assertStringContainsString('"\/qbittorrent\/qbittorrent-1"', $body, 'JS BASE must target the instance');
    }

    public function testSwitcherListsEveryEnabledInstanceAndMarksTheCurrentOne(): void
    {
        $this->seedQbt('qbittorrent-1', 'Home', default: true);
        $this->seedQbt('qbittorrent-2', 'Seedbox');
        $this->seedQbt('qbittorrent-3', 'Old box', enabled: false);

        $crawler = $this->client->request('GET', '/qbittorrent/qbittorrent-2');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $items = $crawler->filter('#qbt-instance-switcher a.dropdown-item');
        $this->assertSame(['Home', 'Seedbox'], $items->each(fn($a) => trim($a->text())));
        $this->assertSame(
            ['/qbittorrent/qbittorrent-1', '/qbittorrent/qbittorrent-2'],
            $items->each(fn($a) => $a->attr('href')),
        );
        $this->assertStringContainsString('active', (string) $items->eq(1)->attr('class'));
        // Sidebar: one badge per enabled instance for the global poll.
        $this->assertCount(2, $crawler->filter('[data-qbt-badge]'));
        $this->assertStringContainsString('"\/qbittorrent\/qbittorrent-2"', (string) $this->client->getResponse()->getContent());
    }

    public function testPollSummaryCoversEveryEnabledInstance(): void
    {
        // Unreachable instances answer no torrents (same as pre-#131: the
        // client returns [] rather than throwing), each with its own count.
        $this->seedQbt('qbittorrent-1', 'Home', default: true);
        $this->seedQbt('qbittorrent-2', 'Seedbox');
        $this->seedQbt('qbittorrent-3', 'Old box', enabled: false);

        $this->client->request('GET', '/qbittorrent/api/poll-summary');
        $res = $this->client->getResponse();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(
            ['active' => 0, 'active_by_instance' => ['qbittorrent-1' => 0, 'qbittorrent-2' => 0], 'items' => []],
            json_decode((string) $res->getContent(), true),
        );
    }

    public function testPollSummaryIsGuardedByTheKillSwitch(): void
    {
        $this->seedQbt('qbittorrent-1', 'Home', default: true);
        $em = $this->em();
        $em->persist(new Setting('qbittorrent_enabled', '0'));
        $em->flush();

        $this->client->request('GET', '/qbittorrent/api/poll-summary');
        $this->assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testVpnCardFollowsTheLinkedGluetunOfTheSelectedInstance(): void
    {
        $tv     = $this->seedGluetun('vpn-tv', 'VPN TV');
        $movies = $this->seedGluetun('vpn-movies', 'VPN Movies');
        $this->seedQbt('qbittorrent-1', 'TV', default: true, gluetun: $tv);
        $this->seedQbt('qbittorrent-2', 'Movies', gluetun: $movies);
        $this->seedQbt('qbittorrent-3', 'Books');

        // Page render (the /api/vpn poll is health-guarded and these test
        // instances are unreachable; both read the same bound client).
        $this->client->request('GET', '/qbittorrent/qbittorrent-2');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('vpn-movies', $this->boundGluetun()?->getSlug());

        $this->client->request('GET', '/qbittorrent/qbittorrent-1');
        $this->assertSame('vpn-tv', $this->boundGluetun()?->getSlug());

        // No link → the 1.3.0 empty state, NOT the default instance's Gluetun.
        $this->client->request('GET', '/qbittorrent/qbittorrent-3');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->boundGluetun());
        $gluetun = static::getContainer()->get(\App\Service\Media\GluetunClient::class);
        $this->assertFalse($gluetun->isConfigured());
        $this->assertSame(
            ['ok' => false, 'public_ip' => null, 'forwarded_port' => null],
            array_intersect_key($gluetun->getSummary(), ['ok' => 1, 'public_ip' => 1, 'forwarded_port' => 1]),
        );
    }

    public function testDisabledLinkedGluetunRendersTheEmptyState(): void
    {
        $vpn = $this->seedGluetun('vpn', 'VPN', enabled: false);
        $this->seedQbt('qbittorrent-1', 'Home', default: true, gluetun: $vpn);

        $this->client->request('GET', '/qbittorrent/qbittorrent-1');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->boundGluetun());
    }

    public function testSettingsPageRendersQbittorrentAndGluetunInstanceRowsAndModals(): void
    {
        $vpn = $this->seedGluetun('vpn', 'VPN TV');
        $this->seedGluetun('vpn-off', 'VPN Old', enabled: false);
        $qbt = $this->seedQbt('qbittorrent-1', 'Home', default: true, gluetun: $vpn);

        $crawler = $this->client->request('GET', '/admin/settings');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // qBittorrent: credentials + a Gluetun picker, no API key.
        $add = $crawler->filter('#instance-add-modal-qbittorrent');
        $this->assertCount(1, $add);
        $this->assertCount(1, $add->filter('input[name="username"]'));
        $this->assertCount(0, $add->filter('input[name="api_key"]'));
        $this->assertSame(
            ['', (string) $vpn->getId(), (string) ($vpn->getId() + 1)],
            $add->filter('select[name="gluetun_instance_id"] option')->each(fn($o) => (string) $o->attr('value')),
            'None + every Gluetun instance',
        );
        $this->assertStringContainsString('VPN Old', $add->filter('select[name="gluetun_instance_id"] option')->eq(2)->text());
        $this->assertCount(0, $add->filter('select[name="gluetun_instance_id"] option[selected]'), 'a new instance starts on None');
        $edit = $crawler->filter('#instance-edit-modal-' . $qbt->getId());
        $this->assertSame((string) $vpn->getId(), $edit->filter('select[name="gluetun_instance_id"] option[selected]')->attr('value'));
        // The row names its linked Gluetun.
        $this->assertStringContainsString('VPN TV', $crawler->filter('[data-instance-row="' . $qbt->getId() . '"] .inst-link')->text());

        // Gluetun is its own service again, in the Downloads group: rows +
        // add modal with a URL and an optional API key, no Gluetun picker.
        $card = $crawler->filter('[data-service-card="gluetun"]');
        $this->assertCount(1, $card);
        $this->assertCount(2, $card->filter('[data-instance-row]'));
        $gAdd = $crawler->filter('#instance-add-modal-gluetun');
        $this->assertCount(1, $gAdd->filter('input[name="url"]'));
        $this->assertCount(1, $gAdd->filter('input[name="api_key"]'));
        $this->assertCount(0, $gAdd->filter('select'));
        // Old flat fields are gone everywhere.
        $this->assertCount(0, $crawler->filter('input[name="gluetun_url"]'));
        $this->assertCount(0, $crawler->filter('input[name="gluetun_api_key"]'));
    }

    public function testKillSwitchSharesTheAddButtonRow(): void
    {
        // Owner review — the qBittorrent add button and its service-level
        // switch sit on one flex row; Radarr/Sonarr rows keep no switch.
        $this->seedQbt('qbittorrent-1', 'Home', default: true); // Radarr is seeded by the base case.

        $crawler = $this->client->request('GET', '/admin/settings');

        $foot = $crawler->filter('[data-service-card="qbittorrent"] .inst-foot');
        $this->assertCount(1, $foot);
        $this->assertCount(1, $foot->filter('.inst-add'));
        $this->assertCount(1, $foot->filter('input[name="qbittorrent_enabled"]'));
        $this->assertCount(1, $crawler->filter('input[name="qbittorrent_enabled"]'), 'service-level kill switch kept, once');
        $this->assertCount(0, $crawler->filter('[data-service-card="qbittorrent"] .svc-actions'));

        $radarrFoot = $crawler->filter('[data-service-card="radarr"] .inst-foot');
        $this->assertCount(1, $radarrFoot->filter('.inst-add'));
        $this->assertCount(0, $radarrFoot->filter('input[type="checkbox"]'));
        $this->assertCount(0, $crawler->filter('[data-service-card="gluetun"] .inst-foot input[type="checkbox"]'));
    }
}
