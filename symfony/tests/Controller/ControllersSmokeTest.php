<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Smoke tests for every controller that is not already covered by a
 * dedicated test class. Goal: catch obvious regressions (uncaught
 * exceptions, broken DI, missing templates) on the main landing route
 * of each controller — not business-logic correctness.
 *
 * Admin is pre-seeded and logged in, setup is marked completed, but no
 * external service URL/API key is configured, so the routes are
 * expected to degrade gracefully (bandeau "service non configuré" →
 * 503, or 200 with empty state) rather than 500.
 */
class ControllersSmokeTest extends AbstractWebTestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function routesProvider(): array
    {
        return [
            // Main media landings
            'dashboard'           => ['/tableau-de-bord', 'DashboardController::index'],
            'media films'         => ['/medias/films', 'MediaController::films'],
            'media series'        => ['/medias/series', 'MediaController::series'],
            'tmdb discovery'      => ['/decouverte', 'TmdbController::index'],
            'calendrier'          => ['/calendrier', 'CalendrierController::index'],
            'calendrier ical'     => ['/calendrier.ics', 'CalendrierController::ical'],
            'profile'             => ['/profil', 'ProfileController::index'],
            'settings export'     => ['/admin/settings/export', 'AdminSettingsController::export'],

            // Arrs system pages (not service-data-heavy, still need to render)
            'radarr updates'      => ['/radarr/mises-a-jour', 'RadarrController::updates'],
            'sonarr updates'      => ['/sonarr/mises-a-jour', 'SonarrController::updates'],

            // Service indexes
            'prowlarr index'      => ['/prowlarr', 'ProwlarrController::index'],
            'jellyseerr index'    => ['/jellyseerr', 'JellyseerrController::index'],
            'qbittorrent index'   => ['/qbittorrent', 'QBittorrentController::index'],
        ];
    }

    #[DataProvider('routesProvider')]
    public function testRouteDoesNotCrash(string $path, string $label): void
    {
        $this->client->request('GET', $path);
        $this->assertDidNotCrash($path);
    }

    public function testLoginPageIsPubliclyAccessible(): void
    {
        // Drop the admin session so we hit the actual form, not a redirect.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('csrf', strtolower($this->client->getResponse()->getContent() ?: ''));
    }

    public function testHealthEndpointIsPublicAndReturnsJson(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/health');

        // Health returns 200 (DB ping OK) or 503 (DB unavailable). Both
        // are JSON responses — only a 500 would mean a code bug.
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertTrue($status === 200 || $status === 503, "Got $status");

        $content = $this->client->getResponse()->getContent();
        $this->assertNotFalse($content);
        $this->assertJson($content);
    }

    public function testLocaleQueryParamSwitchesUiToEnglish(): void
    {
        // `?_locale=en` is the only runtime override (preview). Admin
        // changes `display_language` via /admin/settings to persist.
        $this->client->request('GET', '/tableau-de-bord?_locale=en');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        // "Pending requests" (EN) must be present, not "Requêtes en attente" (FR).
        $this->assertStringContainsString('Pending requests', $body);
        $this->assertStringNotContainsString('Requêtes en attente', $body);
    }
}
