<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Review 2026-11-06 (AS-5): HomeController's "last visited" pre-check kept
 * its own list of six services, so a cookie pointing at any other guarded
 * page (Deluge, Transmission, Tautulli, UniFi, Bazarr, Tracearr) looped
 * between / and the guard once that service was switched off. The list
 * must be the guard's own.
 */
class HomeServicePrecheckGuardTest extends TestCase
{
    public function testHomeControllerDelegatesTheServiceLookupToTheRouteGuard(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Controller/HomeController.php');

        self::assertStringContainsString('ServiceRouteGuardSubscriber::serviceIdForRoute(', $src);
        self::assertStringNotContainsString("return 'qbittorrent';", $src, 'no second hand-kept service list');
        self::assertStringNotContainsString("if (str_starts_with(\$route, 'tmdb_'))", $src);
    }
}
