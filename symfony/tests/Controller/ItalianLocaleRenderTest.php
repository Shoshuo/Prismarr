<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Entity\ServiceInstance;
use App\Entity\Setting;
use App\Service\DisplayPreferencesService;
use App\Service\Media\TmdbClient;
use App\Tests\AbstractWebTestCase;

final class ItalianLocaleRenderTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Translation rendering does not need live Radarr/Sonarr connections.
        $em = $this->em();
        foreach ($em->getRepository(ServiceInstance::class)->findAll() as $instance) {
            $instance->setEnabled(false);
        }
        $em->flush();
    }

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
        $this->assertSelectorExists('[data-svc-search][placeholder="Cerca servizi"]');
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

    public function testNewSearchPageRendersInItalian(): void
    {
        $em = $this->em();
        $em->persist(new Setting('tmdb_api_key', 'test-key'));
        $em->flush();

        $tmdb = $this->createStub(TmdbClient::class);
        $tmdb->method('ping')->willReturn(true);
        foreach (['getTrendingAll', 'getTrendingMovies', 'getTrendingTv', 'getPopularMovies', 'getPopularTv', 'getUpcomingMovies', 'getOnTheAirTv', 'getTopRatedMovies', 'getTopRatedTv'] as $method) {
            $tmdb->method($method)->willReturn(['results' => []]);
        }
        static::getContainer()->set(TmdbClient::class, $tmdb);

        $this->client->request('GET', '/decouverte?_locale=it');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html[lang="it"]');
        $this->assertSelectorExists('#tmdb-search[placeholder="Cerca un film, una serie, un attore o un regista"]');
        $this->assertSelectorTextContains('#srch-genre', 'Tutti i generi');
        $this->assertSelectorTextContains('#srch-sort', 'Più pertinenti');
    }

    public function testSimplifiedSetupRendersInItalian(): void
    {
        $em = $this->em();
        $flag = $em->getRepository(Setting::class)->find(SetupController::SETUP_DONE_KEY);
        $em->remove($flag);
        $em->remove($this->admin);
        $em->flush();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/setup/welcome?_locale=it');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Benvenuto in Prismarr');
        $this->assertSelectorExists('#setup-locale-select option[value="it"][selected]');

        $this->client->request('GET', '/setup/admin?_locale=it');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Passaggio 2 di 3');
        $this->assertSelectorTextContains('body', 'Servizi');
    }
}
