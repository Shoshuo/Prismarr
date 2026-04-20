<?php

namespace App\Twig;

use App\Service\ConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ConfigExtension extends AbstractExtension
{
    /**
     * Minimal keys that indicate a configured service.
     * @var array<string, string>
     */
    private const SERVICE_KEYS = [
        'tmdb'        => 'tmdb_api_key',
        'radarr'      => 'radarr_api_key',
        'sonarr'      => 'sonarr_api_key',
        'prowlarr'    => 'prowlarr_api_key',
        'jellyseerr'  => 'jellyseerr_api_key',
        'qbittorrent' => 'qbittorrent_url',
        'gluetun'     => 'gluetun_url',
    ];

    public function __construct(
        private readonly ConfigService $config,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('service_configured', [$this, 'isServiceConfigured']),
            new TwigFunction('service_visible_in_sidebar', [$this, 'isServiceVisibleInSidebar']),
            new TwigFunction('feature_visible_in_sidebar', [$this, 'isFeatureVisibleInSidebar']),
        ];
    }

    public function isServiceConfigured(string $service): bool
    {
        $key = self::SERVICE_KEYS[$service] ?? null;
        return $key !== null && $this->config->has($key);
    }

    /**
     * True when the service is configured AND the admin has not hidden it
     * from the sidebar via /admin/settings. Absence of the hide flag means
     * "visible" (default) — preserves behavior for existing installs.
     */
    public function isServiceVisibleInSidebar(string $service): bool
    {
        if (!$this->isServiceConfigured($service)) {
            return false;
        }
        return $this->config->get('sidebar_hide_' . $service) !== '1';
    }

    /**
     * Internal features (Calendar, etc.) — aggregated pages without their own
     * API key. The caller is expected to validate upstream dependencies
     * separately; this only checks the admin-controlled hide flag.
     */
    public function isFeatureVisibleInSidebar(string $feature): bool
    {
        return $this->config->get('sidebar_hide_' . $feature) !== '1';
    }
}
