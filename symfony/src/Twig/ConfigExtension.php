<?php

namespace App\Twig;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\ServiceInstanceProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ConfigExtension extends AbstractExtension
{
    /**
     * Single setting key that indicates a configured service. Only services
     * still on the v1.0 flat-settings model — radarr & sonarr moved to the
     * service_instance table in v1.1.0 and are checked via the provider.
     *
     * @var array<string, string>
     */
    private const SERVICE_KEYS = [
        'tmdb'        => 'tmdb_api_key',
        'prowlarr'    => 'prowlarr_api_key',
        'jellyseerr'  => 'jellyseerr_api_key',
        'qbittorrent' => 'qbittorrent_url',
        'gluetun'     => 'gluetun_url',
    ];

    /** Services backed by service_instance instead of a flat setting. */
    private const INSTANCE_TYPES = [
        'radarr' => ServiceInstance::TYPE_RADARR,
        'sonarr' => ServiceInstance::TYPE_SONARR,
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly ServiceInstanceProvider $instances,
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
        if (isset(self::INSTANCE_TYPES[$service])) {
            return $this->instances->hasAnyEnabled(self::INSTANCE_TYPES[$service]);
        }
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
