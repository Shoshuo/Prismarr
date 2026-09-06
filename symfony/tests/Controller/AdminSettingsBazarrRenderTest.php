<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * — Bazarr admin settings card. Regression for the "new service must
 * be registered in several hardcoded lists" trap: a service missing from
 * FIELDS/SERVICE_LABELS/groupings/service_meta renders no card at all, and
 * a service missing from the Test match/TEST_FIELDS silently ignores typed
 * values. This asserts the card actually renders with its URL/api key
 * inputs and enabled toggle.
 */
final class AdminSettingsBazarrRenderTest extends AbstractWebTestCase
{
    public function testSettingsPageRendersBazarrCard(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSelectorExists('[data-service-card="bazarr"]');
        $this->assertSelectorExists('[name="bazarr_url"]');
        $this->assertSelectorExists('[name="bazarr_api_key"]');
        $this->assertSelectorExists('[name="bazarr_enabled"]');
        // The Test-connection button must be present for bazarr (it is
        // hidden only for gluetun, which has no health probe).
        $this->assertSelectorExists('.admin-settings-test[data-service="bazarr"]');

        // Card must render under a real translated subtitle, not a raw
        // dotted translation key (the "forgot the locale entry" trap).
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('admin.services.subtitle.bazarr', $html);
    }

    public function testSettingsPageRendersBazarrCardInFrench(): void
    {
        $this->client->request('GET', '/admin/settings?_locale=fr');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('admin.services.subtitle.bazarr', $html);
    }
}
