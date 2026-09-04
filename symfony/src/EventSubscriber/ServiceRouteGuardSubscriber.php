<?php

namespace App\EventSubscriber;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Two guard levels for service sections:
 *   1. If the service is NOT configured (key missing in DB) → redirect to wizard.
 *   2. If the service is configured but UNREACHABLE → redirect to the section
 *      index (which displays the banner) — avoids landing on a broken sub-page.
 *
 * The health-check is cached per-process via HealthService (1 ping per worker).
 * The index routes themselves are exempt from the health-check (they handle
 * their own banner, otherwise we would create a redirect loop).
 *
 * v1.1.0 — radarr/sonarr rules use `instance_type` (queried via
 * ServiceInstanceProvider) instead of `keys` (queried via ConfigService),
 * since their config moved to the service_instance table.
 */
class ServiceRouteGuardSubscriber implements EventSubscriberInterface
{
    /**
     * `exclude_prefix` (optional) carves a sub-tree out of the rule's own
     * route prefix: matching routes are left completely alone by this guard.
     * Used for JSON API endpoints, which must answer with their controller's
     * JSON error shape — a 302 to an HTML page turns a background fetch into
     * an unparseable response, silently drops POST bodies, and leaks a flash
     * message into the next page the user happens to load.
     *
     * @var array<string, array{service: string, service_id: string, keys?: list<string>, instance_type?: string, exclude_prefix?: string, wizard: string, index: string}>
     */
    private const RULES = [
        'radarr_'           => ['service' => 'Radarr',      'service_id' => 'radarr',      'instance_type' => ServiceInstance::TYPE_RADARR,    'wizard' => 'app_setup_managers',  'index' => 'app_media_films'],
        'app_media_films'   => ['service' => 'Radarr',      'service_id' => 'radarr',      'instance_type' => ServiceInstance::TYPE_RADARR,    'wizard' => 'app_setup_managers',  'index' => 'app_media_films'],
        'app_media_radarr'  => ['service' => 'Radarr',      'service_id' => 'radarr',      'instance_type' => ServiceInstance::TYPE_RADARR,    'wizard' => 'app_setup_managers',  'index' => 'app_media_films'],
        'sonarr_'           => ['service' => 'Sonarr',      'service_id' => 'sonarr',      'instance_type' => ServiceInstance::TYPE_SONARR,    'wizard' => 'app_setup_managers',  'index' => 'app_media_series'],
        'app_media_series'  => ['service' => 'Sonarr',      'service_id' => 'sonarr',      'instance_type' => ServiceInstance::TYPE_SONARR,    'wizard' => 'app_setup_managers',  'index' => 'app_media_series'],
        'app_media_sonarr'  => ['service' => 'Sonarr',      'service_id' => 'sonarr',      'instance_type' => ServiceInstance::TYPE_SONARR,    'wizard' => 'app_setup_managers',  'index' => 'app_media_series'],
        'prowlarr_'         => ['service' => 'Prowlarr',    'service_id' => 'prowlarr',    'keys' => ['prowlarr_api_key', 'prowlarr_url'],     'wizard' => 'app_setup_indexers',  'index' => 'prowlarr_index'],
        'jellyseerr_'       => ['service' => 'Jellyseerr',  'service_id' => 'jellyseerr',  'keys' => ['jellyseerr_api_key', 'jellyseerr_url'], 'wizard' => 'app_setup_indexers',  'index' => 'jellyseerr_index'],
        'qbittorrent_'      => ['service' => 'qBittorrent', 'service_id' => 'qbittorrent', 'keys' => ['qbittorrent_url', 'qbittorrent_user'],  'wizard' => 'app_setup_downloads', 'index' => 'app_qbittorrent_index'],
        'app_qbittorrent'   => ['service' => 'qBittorrent', 'service_id' => 'qbittorrent', 'keys' => ['qbittorrent_url', 'qbittorrent_user'],  'wizard' => 'app_setup_downloads', 'index' => 'app_qbittorrent_index'],
        'deluge_'           => ['service' => 'Deluge',      'service_id' => 'deluge',      'keys' => ['deluge_url'],                            'wizard' => 'app_setup_downloads', 'index' => 'app_deluge_index'],
        'app_deluge'        => ['service' => 'Deluge',      'service_id' => 'deluge',      'keys' => ['deluge_url'],                            'wizard' => 'app_setup_downloads', 'index' => 'app_deluge_index'],
        'transmission_'     => ['service' => 'Transmission', 'service_id' => 'transmission', 'keys' => ['transmission_url'],                   'wizard' => 'app_setup_downloads', 'index' => 'app_transmission_index'],
        'app_transmission'  => ['service' => 'Transmission', 'service_id' => 'transmission', 'keys' => ['transmission_url'],                   'wizard' => 'app_setup_downloads', 'index' => 'app_transmission_index'],
        'tmdb_'             => ['service' => 'TMDb',        'service_id' => 'tmdb',        'keys' => ['tmdb_api_key'],                         'wizard' => 'app_setup_tmdb',      'index' => 'tmdb_index'],
        'app_tautulli_index' => ['service' => 'Tautulli',   'service_id' => 'tautulli',    'keys' => ['tautulli_url', 'tautulli_api_key'],      'wizard' => 'admin_settings_index', 'index' => 'app_tautulli_index'],
        'app_bazarr_'       => ['service' => 'Bazarr',      'service_id' => 'bazarr',      'keys' => ['bazarr_url', 'bazarr_api_key'],          'exclude_prefix' => 'app_bazarr_api_', 'wizard' => 'admin_settings_index', 'index' => 'app_bazarr_index'],
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly ServiceInstanceProvider $instances,
        private readonly HealthService $health,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 15: after SetupRedirectSubscriber (prio 20), before the Symfony firewall.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 15],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return;
        }

        $rule = $this->matchRule($route);
        if ($rule === null) {
            return;
        }

        // 0. Service explicitly disabled (issue #15 — flat services only;
        //    radarr/sonarr toggle per instance). Bounce home and say so, so
        //    the user knows it's a toggle, not a missing config.
        if ($this->isDisabled($rule)) {
            $this->flash($event, $this->translator->trans('error.service_not_configured.service_disabled_flash', ['service' => $rule['service']]));
            $event->setResponse(new RedirectResponse($this->urls->generate('app_home')));
            return;
        }

        // 0b. URL targets a disabled Radarr/Sonarr instance — same treatment,
        //     named after the instance. (Unknown slug stays a 404, handled by
        //     MultiInstanceBinderSubscriber which runs after this one.)
        if (isset($rule['instance_type'])) {
            $slug = $event->getRequest()->attributes->get('slug');
            if (is_string($slug) && $slug !== '') {
                $instance = $this->instances->getBySlug($rule['instance_type'], $slug);
                if ($instance !== null && !$instance->isEnabled()) {
                    $this->flash($event, $this->translator->trans('error.service_not_configured.service_disabled_flash', ['service' => $instance->getName()]));
                    $event->setResponse(new RedirectResponse($this->urls->generate('app_home')));
                    return;
                }
            }
        }

        // 1. Service not configured → wizard
        if (!$this->isConfigured($rule)) {
            $this->flash($event, $this->translator->trans('error.service_not_configured.service_unavailable_flash', ['service' => $rule['service']]));
            $event->setResponse(new RedirectResponse($this->urls->generate($rule['wizard'])));
            return;
        }

        // 2. Service configured but unreachable → redirect to section index
        //    (skip if we ARE already on the index, which has its own banner).
        //    Strict comparison: isHealthy() now also returns null for
        //    unconfigured services, but step 1 above already redirects those
        //    to the wizard, so only "true down" should trigger this redirect.
        //    v1.1.0 — pass the slug so the health probe targets THIS instance,
        //    not the default. Without it, breaking Radarr 4K would also flag
        //    Radarr 1 as down (shared cache key). The redirect target also
        //    needs the slug since /medias/{slug}/films expects it.
        $slug = is_string($event->getRequest()->attributes->get('slug'))
            ? $event->getRequest()->attributes->get('slug')
            : null;
        if ($route !== $rule['index'] && $this->health->isHealthy($rule['service_id'], $slug) === false) {
            $params = $slug !== null ? ['slug' => $slug] : [];
            $event->setResponse(new RedirectResponse($this->urls->generate($rule['index'], $params)));
        }
    }

    /**
     * @return array{service: string, service_id: string, keys?: list<string>, instance_type?: string, exclude_prefix?: string, wizard: string, index: string}|null
     */
    private function matchRule(string $route): ?array
    {
        foreach (self::RULES as $prefix => $rule) {
            if (!str_starts_with($route, $prefix)) {
                continue;
            }
            // Carve-out wins over the rule it belongs to: the route is exempt
            // from the guard entirely (see RULES' doc block).
            if (isset($rule['exclude_prefix']) && str_starts_with($route, $rule['exclude_prefix'])) {
                return null;
            }
            return $rule;
        }
        return null;
    }

    /**
     * @param array{service_id?: string} $rule
     */
    private function isDisabled(array $rule): bool
    {
        $id = $rule['service_id'] ?? null;
        return is_string($id)
            && in_array($id, HealthService::TOGGLEABLE_SERVICES, true)
            && $this->config->get($id . '_enabled') === '0';
    }

    /**
     * @param array{keys?: list<string>, instance_type?: string} $rule
     */
    private function isConfigured(array $rule): bool
    {
        if (isset($rule['instance_type'])) {
            return $this->instances->hasAnyEnabled($rule['instance_type']);
        }
        foreach ($rule['keys'] ?? [] as $key) {
            if (!$this->config->has($key)) {
                return false;
            }
        }
        return true;
    }

    private function flash(RequestEvent $event, string $message): void
    {
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($session !== null && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('warning', $message);
        }
    }
}
