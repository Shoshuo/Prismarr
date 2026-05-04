<?php

namespace App\Twig;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\ServiceInstanceProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?RequestStack $requestStack = null,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('service_configured', [$this, 'isServiceConfigured']),
            new TwigFunction('service_visible_in_sidebar', [$this, 'isServiceVisibleInSidebar']),
            new TwigFunction('feature_visible_in_sidebar', [$this, 'isFeatureVisibleInSidebar']),
            new TwigFunction('service_instances', [$this, 'getServiceInstances']),
            new TwigFunction('instance_path', [$this, 'instancePath']),
            new TwigFunction('current_slug', [$this, 'currentSlug']),
        ];
    }

    /**
     * Returns the slug to use for a hardcoded URL of the given type. Same
     * resolution chain as instance_path() but exposed standalone for the
     * cases where the URL is built by string concat in a Twig literal
     * (e.g. `'/medias/' ~ current_slug('radarr') ~ '/radarr/.../__ID__/...'`).
     */
    public function currentSlug(string $type): string
    {
        return $this->resolveSlug($type);
    }

    /**
     * Drop-in replacement for path() on instance-aware routes (Phase C).
     * Auto-injects the slug placeholder so templates don't have to thread
     * the current instance through every link. Resolution order:
     *
     *   1. explicit `slug` in $params (caller knows what it wants)
     *   2. current request's slug attribute (we're already on a per-instance
     *      page → keep navigating in that instance)
     *   3. default instance slug for the route's type (radarr/sonarr)
     *
     * For routes that are NOT instance-aware (anything not starting with
     * radarr_/sonarr_/app_media_films/etc.) we just delegate to path() and
     * pass $params untouched — the helper is safe to use everywhere.
     *
     * @param array<string, mixed> $params
     */
    public function instancePath(string $name, array $params = []): string
    {
        if ($this->urls === null) {
            // Stateless test environment without RouterInterface — return
            // a stub so unit tests of unrelated helpers don't blow up.
            return '/_route/' . $name;
        }
        $type = $this->routeNameToType($name);
        if ($type !== null && !isset($params['slug'])) {
            $params['slug'] = $this->resolveSlug($type);
        }
        return $this->urls->generate($name, $params);
    }

    private function routeNameToType(string $name): ?string
    {
        if (str_starts_with($name, 'radarr_')) {
            return ServiceInstance::TYPE_RADARR;
        }
        if (str_starts_with($name, 'sonarr_')) {
            return ServiceInstance::TYPE_SONARR;
        }
        if (str_starts_with($name, 'app_media_films') || str_starts_with($name, 'app_media_radarr')) {
            return ServiceInstance::TYPE_RADARR;
        }
        if (str_starts_with($name, 'app_media_series') || str_starts_with($name, 'app_media_sonarr')) {
            return ServiceInstance::TYPE_SONARR;
        }
        return null;
    }

    private function resolveSlug(string $type): string
    {
        // 1. Current request already carries a slug → reuse it (but only if
        //    it actually points to an instance of the right type — protects
        //    against radarr-routed templates rendered during a sonarr request).
        $request = $this->requestStack?->getCurrentRequest();
        if ($request !== null) {
            $candidate = $request->attributes->get('slug');
            if (is_string($candidate) && $candidate !== ''
                && $this->instances->getBySlug($type, $candidate) !== null) {
                return $candidate;
            }
        }
        // 2. Default instance of the type. Fallback to a placeholder so the
        //    URL generator doesn't blow up — the request will 404 on hit,
        //    which is what we want when no instance exists at all.
        $default = $this->instances->getDefault($type);
        return $default?->getSlug() ?? $type . '-1';
    }

    /**
     * Enabled instances for radarr/sonarr (other services return []).
     * Used by the sidebar to render one entry per instance when the user
     * configured several (Phase B2).
     *
     * @return list<\App\Entity\ServiceInstance>
     */
    public function getServiceInstances(string $service): array
    {
        $type = self::INSTANCE_TYPES[$service] ?? null;
        return $type !== null ? $this->instances->getEnabled($type) : [];
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
