<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use PHPUnit\Framework\TestCase;

/**
 * Guards the registration points a new service must appear in. Every one of
 * these has been silently missed at least once (Deluge, Houndarr, UniFi).
 */
class TransmissionRegistrationTest extends TestCase
{
    private const BASE_TWIG     = __DIR__ . '/../../templates/base.html.twig';
    private const SETTINGS_TWIG = __DIR__ . '/../../templates/admin/settings.html.twig';

    public function testTransmissionIsToggleable(): void
    {
        self::assertContains('transmission', HealthService::TOGGLEABLE_SERVICES);
    }

    public function testSettingsTemplateRegistersTransmissionEverywhere(): void
    {
        $tpl = file_get_contents(self::SETTINGS_TWIG);

        // 1. service_meta icon/subtitle map, ~line 437
        self::assertStringContainsString(
            "'transmission': { icon_bg: '#d7302f', subtitle: 'admin.services.subtitle.transmission'|trans },",
            $tpl,
        );
        // 2. groupings "downloads" list, ~line 451
        self::assertStringContainsString(
            "['qbittorrent', 'deluge', 'transmission', 'gluetun', 'sabnzbd', 'nzbget']",
            $tpl,
        );
        // 3. per-service kill-switch visibility list (issue #15), ~line 493 —
        // a distinct list from the groupings one above (different membership
        // and order), so this proves transmission is in THIS list specifically.
        self::assertStringContainsString(
            "['tmdb', 'prowlarr', 'jellyseerr', 'qbittorrent', 'deluge', 'transmission', 'sabnzbd', 'nzbget', 'tautulli', 'unraid', 'unifi', 'houndarr']",
            $tpl,
        );
        // 4. "Test connection" TEST_FIELDS map, ~line 1314
        self::assertStringContainsString(
            "transmission: ['transmission_url', 'transmission_user', 'transmission_password'],",
            $tpl,
        );
    }

    public function testBaseTemplateRegistersSidebarAndPoller(): void
    {
        $tpl = file_get_contents(self::BASE_TWIG);

        // sidebar nav item, ~line 1027
        self::assertStringContainsString("service_visible_in_sidebar('transmission')", $tpl);
        // global poll script's enable/kill-switch guard, ~line 2734
        self::assertStringContainsString("service_configured('transmission')", $tpl);
    }

    public function testTurboBeforeRenderClearsTransmissionPollTimer(): void
    {
        $tpl = file_get_contents(self::BASE_TWIG);

        // The turbo:before-render cleanup array, ~line 2357 — a full-page
        // Turbo navigation must clear every poller's timer or it survives
        // (and keeps firing) into the next page. Assert the exact array
        // literal so a resolution that keeps the poller's own code but
        // drops it from THIS cleanup list still fails loudly.
        // The trailing '_prismarrTorrentPagePollTimer' is the shared handle for
        // the qBittorrent/Deluge/Transmission *page* pollers — distinct from the
        // topbar handles before it. See TorrentPagePollerTest for why it exists.
        self::assertStringContainsString(
            "['_prismarrQbtPollTimer', '_prismarrQbtVpnTimer', '_prismarrDelugePollTimer', '_prismarrUnifiPollTimer', '_prismarrTransmissionPollTimer', '_prismarrTorrentPagePollTimer']",
            $tpl,
        );
    }

    public function testUnifiRegistrationSurvivedTheRebase(): void
    {
        // The rebase conflicted on the same lists UniFi occupies — prove we
        // added Transmission without dropping UniFi.
        self::assertContains('unifi', HealthService::TOGGLEABLE_SERVICES);

        $tpl = file_get_contents(self::BASE_TWIG);
        self::assertStringContainsString("service_visible_in_sidebar('unifi')", $tpl);
    }
}
