<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Admin-only page to edit service configuration without replaying the full
 * setup wizard. Every field is pre-filled from the `setting` DB table; on
 * save, the ConfigService + HealthService caches are invalidated so the
 * new values take effect on the next request.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings', name: 'admin_settings_')]
class AdminSettingsController extends AbstractController
{
    /**
     * Grouped field declarations. Each group maps to a card in the template.
     * @var array<string, list<array{key: string, type: string, label: string, placeholder?: string}>>
     */
    private const FIELDS = [
        'tmdb' => [
            ['key' => 'tmdb_api_key', 'type' => 'password', 'label' => 'Clé API v3', 'placeholder' => '7a2f4…'],
        ],
        'radarr' => [
            ['key' => 'radarr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:7878'],
            ['key' => 'radarr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'sonarr' => [
            ['key' => 'sonarr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:8989'],
            ['key' => 'sonarr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'prowlarr' => [
            ['key' => 'prowlarr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:9696'],
            ['key' => 'prowlarr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'jellyseerr' => [
            ['key' => 'jellyseerr_url',     'type' => 'text',     'label' => 'URL',     'placeholder' => 'http://host.docker.internal:5055'],
            ['key' => 'jellyseerr_api_key', 'type' => 'password', 'label' => 'Clé API'],
        ],
        'qbittorrent' => [
            ['key' => 'qbittorrent_url',      'type' => 'text',     'label' => 'URL',           'placeholder' => 'http://host.docker.internal:8080'],
            ['key' => 'qbittorrent_user',     'type' => 'text',     'label' => 'Utilisateur'],
            ['key' => 'qbittorrent_password', 'type' => 'password', 'label' => 'Mot de passe'],
        ],
        'gluetun' => [
            ['key' => 'gluetun_url',      'type' => 'text',     'label' => 'URL'],
            ['key' => 'gluetun_api_key',  'type' => 'password', 'label' => 'Clé API (si protégé)'],
            ['key' => 'gluetun_protocol', 'type' => 'text',     'label' => 'Protocole (openvpn/wireguard)'],
        ],
    ];

    private const SERVICE_LABELS = [
        'tmdb'        => 'TMDb',
        'radarr'      => 'Radarr',
        'sonarr'      => 'Sonarr',
        'prowlarr'    => 'Prowlarr',
        'jellyseerr'  => 'Jellyseerr',
        'qbittorrent' => 'qBittorrent',
        'gluetun'     => 'Gluetun',
    ];

    /**
     * Internal features — aggregated pages without their own API key/URL.
     * Same visibility-toggle pattern as SERVICE_LABELS, but no fields to edit
     * and no "Tester la connexion" button.
     *
     * @var array<string, array{label: string, subtitle: string}>
     */
    private const INTERNAL_FEATURES = [
        'calendar' => [
            'label'    => 'Calendrier',
            'subtitle' => 'Sorties films + épisodes agrégées (Radarr + Sonarr)',
        ],
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly ConfigService $config,
        private readonly HealthService $health,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly AdapterInterface $appCache,
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'Jeton CSRF invalide, réessayez.';
            }

            if ($errors === []) {
                $this->saveSubmitted($request);
                // POST/Redirect/GET so the flash shows and refreshes work cleanly.
                return $this->redirectToRoute('admin_settings_index');
            }
        }

        return $this->render('admin/settings.html.twig', [
            'groups'             => self::FIELDS,
            'service_labels'     => self::SERVICE_LABELS,
            'values'             => $this->loadValues(),
            'sidebar_visibility' => $this->loadSidebarVisibility(),
            'internal_features'  => self::INTERNAL_FEATURES,
            'errors'             => $errors,
        ]);
    }

    #[Route('/test/{service}', name: 'test', methods: ['POST'])]
    public function test(string $service): JsonResponse
    {
        if (!isset(self::SERVICE_LABELS[$service])) {
            return new JsonResponse(['ok' => false, 'error' => 'Service inconnu'], 400);
        }

        try {
            $this->health->invalidate($service);
            $ok = $this->health->isHealthy($service);
        } catch (\Throwable $e) {
            $this->logger->warning('AdminSettings test failed for {service}: {message}', [
                'service' => $service,
                'message' => $e->getMessage(),
            ]);
            return new JsonResponse(['ok' => false, 'error' => 'Service injoignable']);
        }

        return new JsonResponse([
            'ok'      => $ok,
            'service' => self::SERVICE_LABELS[$service],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function loadValues(): array
    {
        $out = [];
        foreach (self::FIELDS as $group) {
            foreach ($group as $field) {
                $out[$field['key']] = (string) ($this->config->get($field['key']) ?? '');
            }
        }
        return $out;
    }

    /**
     * @return array<string, bool>  id => visible (true by default)
     */
    private function loadSidebarVisibility(): array
    {
        $out = [];
        $all = array_merge(array_keys(self::SERVICE_LABELS), array_keys(self::INTERNAL_FEATURES));
        foreach ($all as $id) {
            $out[$id] = $this->config->get('sidebar_hide_' . $id) !== '1';
        }
        return $out;
    }

    private function saveSubmitted(Request $request): void
    {
        $payload = [];
        foreach (self::FIELDS as $group) {
            foreach ($group as $field) {
                $value = trim((string) $request->request->get($field['key'], ''));
                $payload[$field['key']] = $value !== '' ? $value : null;
            }
        }

        // Sidebar visibility — one checkbox per service/feature. An unchecked
        // box is NOT sent by the browser, so we consider it hidden; a checked
        // one clears the hide flag.
        $all = array_merge(array_keys(self::SERVICE_LABELS), array_keys(self::INTERNAL_FEATURES));
        foreach ($all as $id) {
            $visible = $request->request->has('sidebar_visible_' . $id);
            $payload['sidebar_hide_' . $id] = $visible ? null : '1';
        }

        $this->settings->setMany($payload);
        $this->config->invalidate();
        $this->health->invalidate();
        // Purge TMDb/Radarr/Sonarr response cache so data fetched with
        // the previous config doesn't linger up to an hour.
        $this->appCache->clear();
        $this->addFlash('success', 'Configuration enregistrée.');
    }
}
