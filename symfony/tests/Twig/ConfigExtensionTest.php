<?php

namespace App\Tests\Twig;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\ServiceInstanceProvider;
use App\Twig\ConfigExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConfigExtensionTest extends TestCase
{
    /**
     * Build the extension with a fake config + provider. The legacy test API
     * (a flat list of "configured" keys) is preserved: if radarr_api_key /
     * sonarr_api_key appears in $configuredKeys, the corresponding instance
     * provider returns hasAnyEnabled() = true. Everything else keeps using
     * ConfigService::has() so existing assertions stay readable.
     *
     * @param list<string> $configuredKeys
     */
    private function extension(array $configuredKeys): ConfigExtension
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(
            fn(string $key) => in_array($key, $configuredKeys, true)
        );
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasAnyEnabled')->willReturnCallback(
            fn(string $type) => match ($type) {
                ServiceInstance::TYPE_RADARR => in_array('radarr_api_key', $configuredKeys, true),
                ServiceInstance::TYPE_SONARR => in_array('sonarr_api_key', $configuredKeys, true),
                default => false,
            }
        );
        return new ConfigExtension($config, $instances);
    }

    public function testRegistersTheServiceConfiguredFunction(): void
    {
        $functions = ($this->extension([]))->getFunctions();
        $names = array_map(fn($fn) => $fn->getName(), $functions);
        $this->assertContains('service_configured', $names);
    }

    public function testRadarrConfiguredWhenApiKeyPresent(): void
    {
        $ext = $this->extension(['radarr_api_key']);
        $this->assertTrue($ext->isServiceConfigured('radarr'));
    }

    public function testRadarrNotConfiguredWhenApiKeyMissing(): void
    {
        $ext = $this->extension([]);
        $this->assertFalse($ext->isServiceConfigured('radarr'));
    }

    public function testQbittorrentConfiguredByUrlNotApiKey(): void
    {
        // qBittorrent uses URL as the presence indicator (no api_key concept).
        $ext = $this->extension(['qbittorrent_url']);
        $this->assertTrue($ext->isServiceConfigured('qbittorrent'));
    }

    public function testGluetunConfiguredByUrl(): void
    {
        $ext = $this->extension(['gluetun_url']);
        $this->assertTrue($ext->isServiceConfigured('gluetun'));
    }

    public function testUnknownServiceReturnsFalse(): void
    {
        $ext = $this->extension(['radarr_api_key', 'sonarr_api_key']);
        $this->assertFalse($ext->isServiceConfigured('unknown_service'));
    }

    public function testEachServiceMappedToExpectedKey(): void
    {
        $expectations = [
            'tmdb'        => 'tmdb_api_key',
            'radarr'      => 'radarr_api_key',
            'sonarr'      => 'sonarr_api_key',
            'prowlarr'    => 'prowlarr_api_key',
            'jellyseerr'  => 'jellyseerr_api_key',
            'qbittorrent' => 'qbittorrent_url',
            'gluetun'     => 'gluetun_url',
        ];

        foreach ($expectations as $service => $requiredKey) {
            $ext = $this->extension([$requiredKey]);
            $this->assertTrue(
                $ext->isServiceConfigured($service),
                "Expected $service to be configured when $requiredKey is present"
            );
        }
    }

    // ── isServiceVisibleInSidebar ────────────────────────────────────────

    /**
     * @param list<string> $configuredKeys
     * @param list<string> $hiddenServices
     */
    private function extensionWithHideFlag(array $configuredKeys, array $hiddenServices): ConfigExtension
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(
            fn(string $key) => in_array($key, $configuredKeys, true)
        );
        $config->method('get')->willReturnCallback(function (string $key) use ($hiddenServices) {
            foreach ($hiddenServices as $service) {
                if ($key === 'sidebar_hide_' . $service) {
                    return '1';
                }
            }
            return null;
        });
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasAnyEnabled')->willReturnCallback(
            fn(string $type) => match ($type) {
                ServiceInstance::TYPE_RADARR => in_array('radarr_api_key', $configuredKeys, true),
                ServiceInstance::TYPE_SONARR => in_array('sonarr_api_key', $configuredKeys, true),
                default => false,
            }
        );
        return new ConfigExtension($config, $instances);
    }

    public function testVisibleInSidebarWhenConfiguredAndNotHidden(): void
    {
        $ext = $this->extensionWithHideFlag(['radarr_api_key'], []);
        $this->assertTrue($ext->isServiceVisibleInSidebar('radarr'));
    }

    public function testNotVisibleInSidebarWhenNotConfigured(): void
    {
        // Hide flag is irrelevant — not configured = not visible.
        $ext = $this->extensionWithHideFlag([], []);
        $this->assertFalse($ext->isServiceVisibleInSidebar('radarr'));
    }

    public function testNotVisibleInSidebarWhenExplicitlyHidden(): void
    {
        // Configured but admin hid it from the sidebar.
        $ext = $this->extensionWithHideFlag(['radarr_api_key'], ['radarr']);
        $this->assertFalse($ext->isServiceVisibleInSidebar('radarr'));
    }

    public function testSidebarFunctionRegistered(): void
    {
        $names = array_map(fn($fn) => $fn->getName(), ($this->extension([]))->getFunctions());
        $this->assertContains('service_visible_in_sidebar', $names);
        $this->assertContains('feature_visible_in_sidebar', $names);
    }

    // ── isFeatureVisibleInSidebar ────────────────────────────────────────

    public function testFeatureVisibleByDefault(): void
    {
        $ext = $this->extensionWithHideFlag([], []);
        $this->assertTrue($ext->isFeatureVisibleInSidebar('calendar'));
    }

    public function testFeatureHiddenWhenFlagSet(): void
    {
        $ext = $this->extensionWithHideFlag([], ['calendar']);
        $this->assertFalse($ext->isFeatureVisibleInSidebar('calendar'));
    }
}
