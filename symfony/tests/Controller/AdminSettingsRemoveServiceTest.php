<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use App\Tests\AbstractWebTestCase;

/**
 * "Remove" on a configured service sends _remove_<service>=1, which wipes
 * every field of that service, secrets included (an empty secret alone
 * still means "unchanged"), and leaves the other services alone.
 */
final class AdminSettingsRemoveServiceTest extends AbstractWebTestCase
{
    private function seed(): void
    {
        foreach (['prowlarr_url' => 'http://p:9696', 'prowlarr_api_key' => 'pk', 'sabnzbd_url' => 'http://s:8080', 'sabnzbd_api_key' => 'sk'] as $k => $v) {
            $this->em()->persist(new Setting($k, $v));
        }
        $this->em()->flush();
    }

    private function setting(string $name): ?string
    {
        $this->em()->clear();
        return static::getContainer()->get(SettingRepository::class)->get($name);
    }

    public function testRemoveWipesOnlyThatService(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/admin/settings');
        $form = $crawler->filter('form[action$="/admin/settings"]')->form();
        $form['_remove_prowlarr'] = '1';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/settings');

        self::assertNull($this->setting('prowlarr_url'));
        self::assertNull($this->setting('prowlarr_api_key'));
        self::assertSame('http://s:8080', $this->setting('sabnzbd_url'));
        self::assertSame('sk', $this->setting('sabnzbd_api_key'));
    }

    public function testUntouchedSaveKeepsSecrets(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/admin/settings');
        $form = $crawler->filter('form[action$="/admin/settings"]')->form();
        $form['prowlarr_api_key'] = '';  // the browser dropped the password value
        $this->client->submit($form);

        self::assertSame('pk', $this->setting('prowlarr_api_key'));
    }

    public function testNotConfiguredServicesRenderCollapsedWithoutAddButtons(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertCount(0, $crawler->filter('[data-svc-add-for]'), 'no more "Add" chips');
        $tautulli = $crawler->filter('.svc-item[data-service-card="tautulli"]');
        self::assertCount(1, $tautulli);
        self::assertNull($tautulli->attr('hidden'), 'listed, not hidden');
        self::assertStringNotContainsString('is-open', (string) $tautulli->attr('class'));
        self::assertCount(0, $crawler->filter('[name="_remove_tautulli"]'), 'nothing to remove on an unconfigured service');
    }
}
