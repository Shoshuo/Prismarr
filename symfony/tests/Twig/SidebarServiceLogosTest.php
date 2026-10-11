<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Every service entry in the sidebar shows the service's own logo from
 * public/img/services/, and every logo it references must ship.
 */
final class SidebarServiceLogosTest extends TestCase
{
    private const SERVICES = [
        'radarr', 'sonarr', 'prowlarr', 'jellyseerr', 'qbittorrent', 'deluge', 'transmission',
        'sabnzbd', 'nzbget', 'tautulli', 'bazarr', 'tracearr',
    ];

    public function testEachServiceEntryUsesItsLogo(): void
    {
        $root = __DIR__ . '/../..';
        $src = (string) file_get_contents($root . '/templates/base.html.twig');

        foreach (self::SERVICES as $service) {
            $this->assertStringContainsString("asset('img/services/{$service}.svg')", $src, "sidebar entry for {$service} has no logo");
        }

        preg_match_all("#asset\('img/services/([a-z]+)\.svg'\)#", $src, $m);
        foreach (array_unique($m[1]) as $logo) {
            $this->assertFileExists($root . "/public/img/services/{$logo}.svg");
        }
    }
}
