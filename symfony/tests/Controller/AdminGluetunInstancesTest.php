<?php

namespace App\Tests\Controller;

use App\Entity\ServiceInstance;
use App\Tests\AbstractWebTestCase;

/**
 * Issue #131 — Gluetun is a multi-instance service in /admin/settings and
 * each qBittorrent instance links to one (or none) from its modal. Runs
 * through AdminInstancesController against the real SQLite schema, so the
 * unlink-on-delete is checked with a real flush (SQLite foreign keys are
 * not enforced: the provider must do it).
 */
class AdminGluetunInstancesTest extends AbstractWebTestCase
{
    private function csrf(string $id): string
    {
        // Same token the settings page renders for this form.
        $crawler = $this->client->request('GET', '/admin/settings');
        $node = $crawler->filter('[data-instance-csrf][data-instance-url$="/' . $id . '"]');
        return (string) $node->first()->attr('data-instance-csrf');
    }

    /** Submit the add / edit modal form rendered on the settings page. */
    private function submitModal(string $modalId, array $values): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        $form = $crawler->filter('#' . $modalId . ' form')->form();
        $this->client->submit($form, $values);
        $this->assertTrue($this->client->getResponse()->isRedirect(), 'instance forms redirect back to settings');
    }

    private function find(string $type, string $slug): ?ServiceInstance
    {
        $this->em()->clear();
        return $this->em()->getRepository(ServiceInstance::class)->findOneBy(['type' => $type, 'slug' => $slug]);
    }

    public function testAddStandaloneGluetunThenLinkUnlinkAndDelete(): void
    {
        // 1. A standalone Gluetun, no qBittorrent anywhere (upstream #52).
        $this->submitModal('instance-add-modal-gluetun', [
            'name' => 'VPN TV', 'url' => 'http://127.0.0.1:2', 'api_key' => 'k3y',
        ]);
        $gluetun = $this->find('gluetun', 'vpn-tv');
        $this->assertNotNull($gluetun, 'slug derived from the name, like Radarr');
        $this->assertSame('k3y', $gluetun->getApiKey());
        $this->assertTrue($gluetun->isDefault());

        // 2. A qBittorrent instance picking it from the dropdown.
        $this->submitModal('instance-add-modal-qbittorrent', [
            'name' => 'Home', 'url' => 'http://127.0.0.1:1', 'gluetun_instance_id' => (string) $gluetun->getId(),
        ]);
        $qbt = $this->find('qbittorrent', 'home');
        $this->assertSame($gluetun->getId(), $qbt->getGluetunInstanceId());

        // 3. Edit back to None unlinks.
        $this->submitModal('instance-edit-modal-' . $qbt->getId(), ['gluetun_instance_id' => '']);
        $this->assertNull($this->find('qbittorrent', 'home')->getGluetunInstanceId());

        // 4. Relink, then delete the Gluetun: qBittorrent survives, unlinked.
        $this->submitModal('instance-edit-modal-' . $qbt->getId(), ['gluetun_instance_id' => (string) $gluetun->getId()]);
        $this->assertSame($gluetun->getId(), $this->find('qbittorrent', 'home')->getGluetunInstanceId());
        $this->client->request('POST', '/admin/instances/' . $gluetun->getId() . '/delete', [
            '_csrf_token' => $this->csrf($gluetun->getId() . '/delete'),
        ]);
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertNull($this->find('gluetun', 'vpn-tv'));
        $survivor = $this->find('qbittorrent', 'home');
        $this->assertNotNull($survivor, 'deleting a Gluetun never cascades');
        $this->assertNull($survivor->getGluetunInstanceId());
    }

    public function testEditingTheGluetunApiKeyKeepsOrClearsIt(): void
    {
        $this->submitModal('instance-add-modal-gluetun', ['name' => 'VPN', 'url' => 'http://127.0.0.1:2', 'api_key' => 'k3y']);
        $id = $this->find('gluetun', 'vpn')->getId();

        // Browsers drop type=password values: empty = unchanged.
        $this->submitModal('instance-edit-modal-' . $id, ['api_key' => '']);
        $this->assertSame('k3y', $this->find('gluetun', 'vpn')->getApiKey());

        $this->submitModal('instance-edit-modal-' . $id, ['clear_api_key' => '1']);
        $this->assertNull($this->find('gluetun', 'vpn')->getApiKey());
    }

    public function testLinkToAnUnknownGluetunIsRejected(): void
    {
        $this->client->request('GET', '/admin/settings');
        $crawler = $this->client->getCrawler();
        $form = $crawler->filter('#instance-add-modal-qbittorrent form')->form();
        $values = $form->getPhpValues();
        $values['name'] = 'Home';
        $values['url'] = 'http://127.0.0.1:1';
        $values['gluetun_instance_id'] = '999';
        $this->client->request('POST', $form->getUri(), $values);

        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertNull($this->find('qbittorrent', 'home'), 'nothing persisted');
    }

    public function testGluetunTestButtonProbesTheControlServer(): void
    {
        $this->submitModal('instance-add-modal-gluetun', ['name' => 'VPN', 'url' => 'http://127.0.0.1:1']);
        $id = $this->find('gluetun', 'vpn')->getId();

        $this->client->request('POST', '/admin/instances/' . $id . '/test', ['_csrf_token' => $this->csrf($id . '/test')]);

        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($json['ok']);
        $this->assertSame('network', $json['category']);
    }

    public function testGluetunModalsHideTheSlugFieldQbittorrentKeepsIt(): void
    {
        // Gluetun has no pages, so its slug never shows in a URL.
        $this->submitModal('instance-add-modal-gluetun', ['name' => 'VPN', 'url' => 'http://127.0.0.1:2']);
        $this->submitModal('instance-add-modal-qbittorrent', ['name' => 'Home', 'url' => 'http://127.0.0.1:1']);
        $gluetunId = $this->find('gluetun', 'vpn')->getId();
        $qbtId     = $this->find('qbittorrent', 'home')->getId();

        $crawler = $this->client->request('GET', '/admin/settings');
        $this->assertCount(0, $crawler->filter('#instance-add-modal-gluetun input[name="slug"]'));
        $this->assertCount(0, $crawler->filter('#instance-edit-modal-' . $gluetunId . ' input[name="slug"]'));
        $this->assertCount(1, $crawler->filter('#instance-add-modal-qbittorrent input[name="slug"]'));
        $this->assertCount(1, $crawler->filter('#instance-edit-modal-' . $qbtId . ' input[name="slug"]'));

        // Saving the Gluetun edit form (no slug field posted) keeps the slug.
        $this->submitModal('instance-edit-modal-' . $gluetunId, ['name' => 'VPN renamed']);
        $this->assertSame('vpn', $this->em()->getRepository(ServiceInstance::class)->find($gluetunId)->getSlug());
    }

    public function testDeletingALinkedGluetunWarnsAboutTheUnlink(): void
    {
        $this->submitModal('instance-add-modal-gluetun', ['name' => 'VPN', 'url' => 'http://127.0.0.1:2']);
        $gluetunId = $this->find('gluetun', 'vpn')->getId();
        $deleteBtn = fn () => $this->client->request('GET', '/admin/settings')
            ->filter('[data-instance-action="delete"][data-instance-url$="/' . $gluetunId . '/delete"]');

        $this->assertNull($deleteBtn()->attr('data-instance-confirm'), 'unlinked: the generic confirm is enough');

        foreach (['Home', 'Box'] as $i => $name) {
            $this->submitModal('instance-add-modal-qbittorrent', [
                'name' => $name, 'url' => 'http://127.0.0.1:' . (10 + $i), 'gluetun_instance_id' => (string) $gluetunId,
            ]);
        }
        $msg = (string) $deleteBtn()->attr('data-instance-confirm');
        $this->assertStringContainsString('VPN', $msg);
        $this->assertStringContainsString('2 qBittorrent instances', $msg);
    }
}
