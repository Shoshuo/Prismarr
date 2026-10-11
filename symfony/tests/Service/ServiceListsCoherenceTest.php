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
    /** Radarr / Sonarr are per-instance; Gluetun has no health probe nor kill switch. */
    private const INSTANCE = ['radarr', 'sonarr'];
    private const NO_PROBE = ['gluetun'];

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

    public function testConfigKeysCoverEveryToggleableServicePlusGluetun(): void
    {
        $keys = array_keys(self::constant(ConfigExtension::class, 'SERVICE_KEYS'));
        self::assertSame(self::sorted(array_merge(HealthService::TOGGLEABLE_SERVICES, self::NO_PROBE)), self::sorted($keys));
    }

    public function testSettingsFormAndLabelsCoverEveryService(): void
    {
        $expected = self::sorted(array_merge(HealthService::TOGGLEABLE_SERVICES, self::NO_PROBE, self::INSTANCE));
        self::assertSame($expected, self::sorted(array_keys(self::constant(AdminSettingsController::class, 'FIELDS'))));
        self::assertSame($expected, self::sorted(array_keys(self::constant(AdminSettingsController::class, 'SERVICE_LABELS'))));
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
        self::assertSame(self::sorted(array_keys(self::constant(AdminSettingsController::class, 'FIELDS'))), self::sorted($ids));
    }

    public function testEveryServiceHasAMetaEntryALogoAndADashboardColour(): void
    {
        $settings = (string) file_get_contents(self::ROOT . '/templates/admin/settings.html.twig');
        $health = (string) file_get_contents(self::ROOT . '/templates/dashboard/_health.html.twig');

        foreach (array_keys(self::constant(AdminSettingsController::class, 'FIELDS')) as $id) {
            self::assertMatchesRegularExpression("/'{$id}':\s+\{ icon_bg/", $settings, "service_meta has no entry for {$id}");
            self::assertFileExists(self::ROOT . "/public/img/services/{$id}.svg");
        }
        foreach (array_merge(self::INSTANCE, array_keys(HealthCatalog::FLAT_SERVICES)) as $id) {
            self::assertMatchesRegularExpression("/^\s+{$id}:\s+'#[0-9a-fA-F]{6}'/m", $health, "dashboard has no colour for {$id}");
        }
    }
}
