<?php

namespace App\Tests\Service;

use App\Controller\AdminSettingsController;
use App\Service\HealthCatalog;
use App\Service\HealthService;
use App\Twig\ConfigExtension;
use PHPUnit\Framework\TestCase;

/**
 * The list of services is declared in several places (health, kill switches,
 * config keys, settings form, settings layout, dashboard colours, logos).
 * Adding a service to one and forgetting another silently breaks a screen,
 * so this pins them together.
 */
final class ServiceListsCoherenceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    /**
     * Services stored in service_instance. qBittorrent is per-instance AND
     * keeps its service-level kill switch (issue #131).
     */
    private const INSTANCE = ['radarr', 'sonarr', 'qbittorrent'];
    /**
     * Instance-backed too (issue #131), but with no flat settings fields,
     * no health probe nor kill switch: Gluetun only feeds the VPN card of
     * the qBittorrent instances that link to it.
     */
    private const INSTANCE_ONLY = ['gluetun'];

    private static function sorted(array $a): array
    {
        sort($a);
        return array_values($a);
    }

    private static function constant(string $class, string $name): array
    {
        return (new \ReflectionClassConstant($class, $name))->getValue();
    }

    public function testHealthCatalogMatchesTheKillSwitchList(): void
    {
        self::assertSame(self::sorted(HealthService::TOGGLEABLE_SERVICES), self::sorted(array_keys(HealthCatalog::FLAT_SERVICES)));
    }

    public function testConfigKeysCoverEveryFlatToggleableService(): void
    {
        $keys = array_keys(self::constant(ConfigExtension::class, 'SERVICE_KEYS'));
        $flat = array_diff(HealthService::TOGGLEABLE_SERVICES, self::INSTANCE);
        self::assertSame(self::sorted($flat), self::sorted($keys));
        $instanceTypes = array_keys(self::constant(ConfigExtension::class, 'INSTANCE_TYPES'));
        self::assertSame(self::sorted(array_merge(self::INSTANCE, self::INSTANCE_ONLY)), self::sorted($instanceTypes));
    }

    public function testSettingsFormAndLabelsCoverEveryService(): void
    {
        $expected = self::sorted(array_unique(array_merge(HealthService::TOGGLEABLE_SERVICES, self::INSTANCE)));
        self::assertSame($expected, self::sorted(array_keys(self::constant(AdminSettingsController::class, 'FIELDS'))));
        self::assertSame(
            self::sorted(array_merge($expected, self::INSTANCE_ONLY)),
            self::sorted(array_keys(self::constant(AdminSettingsController::class, 'SERVICE_LABELS'))),
        );
    }

    public function testSettingsLayoutListsEveryServiceOnce(): void
    {
        $tpl = (string) file_get_contents(self::ROOT . '/templates/admin/_services_list.html.twig');
        preg_match_all("/ids: \[([^\]]*)\]/", $tpl, $m);
        $ids = [];
        foreach ($m[1] as $group) {
            preg_match_all("/'([a-z]+)'/", $group, $g);
            $ids = array_merge($ids, $g[1]);
        }
        self::assertSame(count($ids), count(array_unique($ids)), 'a service is listed in two groups');
        self::assertSame(self::sorted(array_keys(self::constant(AdminSettingsController::class, 'SERVICE_LABELS'))), self::sorted($ids));
    }

    public function testEveryServiceHasAMetaEntryALogoAndADashboardColour(): void
    {
        $settings = (string) file_get_contents(self::ROOT . '/templates/admin/settings.html.twig');
        $health = (string) file_get_contents(self::ROOT . '/templates/dashboard/_health.html.twig');

        foreach (array_keys(self::constant(AdminSettingsController::class, 'SERVICE_LABELS')) as $id) {
            self::assertMatchesRegularExpression("/'{$id}':\s+\{ icon_bg/", $settings, "service_meta has no entry for {$id}");
            self::assertFileExists(self::ROOT . "/public/img/services/{$id}.svg");
        }
        foreach (array_unique(array_merge(self::INSTANCE, array_keys(HealthCatalog::FLAT_SERVICES))) as $id) {
            self::assertMatchesRegularExpression("/^\s+{$id}:\s+'#[0-9a-fA-F]{6}'/m", $health, "dashboard has no colour for {$id}");
        }
    }
}
