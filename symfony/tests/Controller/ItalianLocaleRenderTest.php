<?php

namespace App\Tests\Controller;

use App\Service\DisplayPreferencesService;
use App\Tests\AbstractWebTestCase;

final class ItalianLocaleRenderTest extends AbstractWebTestCase
{
    public function testLoginRendersInItalian(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login?_locale=it');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html[lang="it"]');
        $this->assertSelectorTextContains('button[type="submit"]', 'Accedi');
    }

    public function testAdminSettingsOfferItalianAndRenderTranslatedLabels(): void
    {
        $this->client->request('GET', '/admin/settings?_locale=it');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html[lang="it"]');
        $this->assertSelectorTextContains('#prismarr_ui option[value="it"]', 'Italiano');
        $this->assertSelectorTextContains('#prismarr_metadata option[value="it-IT"]', 'Italiano (it-IT)');
        $this->assertSelectorTextContains('label[for="prismarr_ui"]', "Lingua dell'interfaccia");
    }

    public function testItalianMetadataLanguagePersistsAcrossRequests(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings?_locale=it');
        $form = $crawler->selectButton('Salva lingue')->form([
            'prismarr_metadata' => 'it-IT',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#prismarr_metadata option[value="it-IT"][selected]');
        $this->assertSame(
            'it-IT',
            static::getContainer()->get(DisplayPreferencesService::class)->getMetadataLanguage(),
        );
    }
}
