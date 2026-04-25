<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
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
            ['key' => 'tmdb_api_key', 'type' => 'password', 'label' => 'admin.field.tmdb.api_key', 'placeholder' => '7a2f4…'],
        ],
        'radarr' => [
            ['key' => 'radarr_url',     'type' => 'text',     'label' => 'admin.field.url',     'placeholder' => 'http://host.docker.internal:7878'],
            ['key' => 'radarr_api_key', 'type' => 'password', 'label' => 'admin.field.api_key'],
        ],
        'sonarr' => [
            ['key' => 'sonarr_url',     'type' => 'text',     'label' => 'admin.field.url',     'placeholder' => 'http://host.docker.internal:8989'],
            ['key' => 'sonarr_api_key', 'type' => 'password', 'label' => 'admin.field.api_key'],
        ],
        'prowlarr' => [
            ['key' => 'prowlarr_url',     'type' => 'text',     'label' => 'admin.field.url',     'placeholder' => 'http://host.docker.internal:9696'],
            ['key' => 'prowlarr_api_key', 'type' => 'password', 'label' => 'admin.field.api_key'],
        ],
        'jellyseerr' => [
            ['key' => 'jellyseerr_url',     'type' => 'text',     'label' => 'admin.field.url',     'placeholder' => 'http://host.docker.internal:5055'],
            ['key' => 'jellyseerr_api_key', 'type' => 'password', 'label' => 'admin.field.api_key'],
        ],
        'qbittorrent' => [
            ['key' => 'qbittorrent_url',      'type' => 'text',     'label' => 'admin.field.url',             'placeholder' => 'http://host.docker.internal:8080'],
            ['key' => 'qbittorrent_user',     'type' => 'text',     'label' => 'admin.field.username'],
            ['key' => 'qbittorrent_password', 'type' => 'password', 'label' => 'admin.field.password'],
        ],
        'gluetun' => [
            ['key' => 'gluetun_url',      'type' => 'text',     'label' => 'admin.field.url'],
            ['key' => 'gluetun_api_key',  'type' => 'password', 'label' => 'admin.field.api_key_if_protected'],
            ['key' => 'gluetun_protocol', 'type' => 'text',     'label' => 'admin.field.protocol'],
        ],
    ];

    /**
     * Curated language lists for services that don't expose a /language
     * endpoint (Prowlarr, Jellyseerr). Values are the codes the service
     * actually accepts; labels are translated client-side via Twig (we
     * keep the native autonyms here so they stay readable in any locale).
     *
     * @var array<string, string>
     */
    private const PROWLARR_UI_LANGUAGES = [
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'it' => 'Italiano',
        'pt' => 'Português',
    ];

    /**
     * @var array<string, string>
     */
    private const JELLYSEERR_UI_LANGUAGES = [
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'it' => 'Italiano',
        'pt' => 'Português',
        'ja' => '日本語',
        'ko' => '한국어',
        'zh' => '中文',
        'ru' => 'Русский',
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
            'label'    => 'admin.internal.calendar.label',
            'subtitle' => 'admin.internal.calendar.subtitle',
        ],
    ];

    /**
     * Display preferences — stored in DB under `display_*` keys, surfaced as
     * selects/switches/swatches in the "Affichage" section. Source of truth
     * for defaults and allowed values — DisplayPreferencesService reads the
     * same constants at runtime to validate incoming values.
     *
     * @var array<string, array{label: string, type: string, default: string, options?: array<string, string>, help?: string}>
     */
    public const DISPLAY_OPTIONS = [
        'display_home_page' => [
            'label'   => 'admin.display.home_page.label',
            'type'    => 'select',
            'default' => 'dashboard',
            'options' => [
                'dashboard'   => 'admin.display.home_page.options.dashboard',
                'discovery'   => 'admin.display.home_page.options.discovery',
                'films'       => 'admin.display.home_page.options.films',
                'series'      => 'admin.display.home_page.options.series',
                'qbittorrent' => 'admin.display.home_page.options.qbittorrent',
                'last'        => 'admin.display.home_page.options.last',
            ],
            'help' => 'admin.display.home_page.help',
        ],
        'display_toasts' => [
            'label'   => 'admin.display.toasts.label',
            'type'    => 'switch',
            'default' => '1',
            'help'    => 'admin.display.toasts.help',
        ],
        'display_timezone' => [
            'label'   => 'admin.display.timezone.label',
            'type'    => 'timezone',
            'default' => 'Europe/Paris',
            'help'    => 'admin.display.timezone.help',
        ],
        'display_date_format' => [
            'label'   => 'admin.display.date_format.label',
            'type'    => 'select',
            'default' => 'fr',
            'options' => [
                'fr'  => 'admin.display.date_format.options.fr',
                'us'  => 'admin.display.date_format.options.us',
                'iso' => 'admin.display.date_format.options.iso',
            ],
        ],
        'display_time_format' => [
            'label'   => 'admin.display.time_format.label',
            'type'    => 'select',
            'default' => '24h',
            'options' => [
                '24h' => 'admin.display.time_format.options.24h',
                '12h' => 'admin.display.time_format.options.12h',
            ],
        ],
        'display_theme_color' => [
            'label'   => 'admin.display.theme_color.label',
            'type'    => 'color',
            'default' => 'indigo',
            'options' => [
                'indigo' => '#6366f1',
                'red'    => '#ef4444',
                'green'  => '#22c55e',
                'orange' => '#f59e0b',
                'pink'   => '#ec4899',
                'blue'   => '#3b82f6',
            ],
        ],
        'display_qbit_refresh' => [
            'label'   => 'admin.display.qbit_refresh.label',
            'type'    => 'select',
            'default' => '2',
            'options' => [
                '1'  => 'admin.display.qbit_refresh.options.1',
                '2'  => 'admin.display.qbit_refresh.options.2',
                '5'  => 'admin.display.qbit_refresh.options.5',
                '10' => 'admin.display.qbit_refresh.options.10',
                '0'  => 'admin.display.qbit_refresh.options.0',
            ],
            'help' => 'admin.display.qbit_refresh.help',
        ],
        'display_ui_density' => [
            'label'   => 'admin.display.ui_density.label',
            'type'    => 'select',
            'default' => 'comfortable',
            'options' => [
                'comfortable' => 'admin.display.ui_density.options.comfortable',
                'compact'     => 'admin.display.ui_density.options.compact',
            ],
        ],
        // Langues : conservées dans DISPLAY_OPTIONS pour fournir les defaults
        // à DisplayPreferencesService et loadDisplayValues, mais marquées
        // `hidden: true` pour ne pas s'afficher dans la section Display
        // (l'édition se fait via la section Langues unifiée).
        'display_language' => [
            'label'   => 'admin.display.language.label',
            'type'    => 'select',
            'default' => 'en',
            'hidden'  => true,
            'options' => [
                'fr' => 'admin.display.language.options.fr',
                'en' => 'admin.display.language.options.en',
            ],
            'help' => 'admin.display.language.help',
        ],
        'display_metadata_language' => [
            'label'   => 'admin.display.metadata_language.label',
            'type'    => 'select',
            'default' => 'en-US',
            'hidden'  => true,
            'options' => [
                'fr-FR' => 'admin.display.metadata_language.options.fr_FR',
                'en-US' => 'admin.display.metadata_language.options.en_US',
                'en-GB' => 'admin.display.metadata_language.options.en_GB',
                'es-ES' => 'admin.display.metadata_language.options.es_ES',
                'de-DE' => 'admin.display.metadata_language.options.de_DE',
                'it-IT' => 'admin.display.metadata_language.options.it_IT',
                'pt-PT' => 'admin.display.metadata_language.options.pt_PT',
                'pt-BR' => 'admin.display.metadata_language.options.pt_BR',
                'ja-JP' => 'admin.display.metadata_language.options.ja_JP',
            ],
            'help' => 'admin.display.metadata_language.help',
        ],
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly ConfigService $config,
        private readonly HealthService $health,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly AdapterInterface $appCache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings', (string) $request->request->get('_csrf_token'))) {
                $errors[] = $this->translator?->trans('flash.csrf.invalid') ?? 'Jeton CSRF invalide, réessayez.';
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
            'display_options'    => self::DISPLAY_OPTIONS,
            'display_values'     => $this->loadDisplayValues(),
            'timezones'          => \DateTimeZone::listIdentifiers(),
            'system_info'        => $this->systemInfo(),
            'export_counts'      => $this->exportCounts(),
            'languages'          => $this->loadServiceLanguages(),
            'errors'             => $errors,
        ]);
    }

    /**
     * Read-only snapshot of the runtime environment for the "À propos"
     * section. Everything is computed on render — no caching — since the
     * settings page is visited rarely enough that the cost is negligible.
     */
    private function systemInfo(): array
    {
        $projectDir = $this->projectDir ?: ($_SERVER['KERNEL_PROJECT_DIR'] ?? '');
        $dbPath     = $projectDir . '/var/data/prismarr.db';

        // Library counts are best-effort — if Radarr/Sonarr are down we
        // render "—" instead of crashing the whole page.
        $films  = null;
        $series = null;
        try {
            /** @var RadarrClient $radarr */
            $radarr = $this->container->get(RadarrClient::class);
            $films  = count($radarr->getMovies());
        } catch (\Throwable) {}
        try {
            /** @var SonarrClient $sonarr */
            $sonarr = $this->container->get(SonarrClient::class);
            $series = count($sonarr->getSeries());
        } catch (\Throwable) {}

        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);
        $userCount = null;
        try {
            $userCount = $users->count([]);
        } catch (\Throwable) {}

        return [
            'prismarr_version' => $_ENV['PRISMARR_VERSION'] ?? getenv('PRISMARR_VERSION') ?: '1.0.0-dev',
            'symfony_version'  => Kernel::VERSION,
            'php_version'      => PHP_VERSION,
            'sapi'             => PHP_SAPI,
            'environment'      => $this->environment,
            'db_path'          => $dbPath,
            'db_size'          => is_file($dbPath) ? filesize($dbPath) : 0,
            'avatars_dir'      => $projectDir . '/var/data/avatars',
            'user_count'       => $userCount,
            'film_count'       => $films,
            'series_count'     => $series,
            'server_time'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'timezone'         => date_default_timezone_get(),
        ];
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            RadarrClient::class     => RadarrClient::class,
            SonarrClient::class     => SonarrClient::class,
            ProwlarrClient::class   => ProwlarrClient::class,
            JellyseerrClient::class => JellyseerrClient::class,
            UserRepository::class   => UserRepository::class,
        ]);
    }

    #[Route('/test/{service}', name: 'test', methods: ['POST'])]
    public function test(string $service): JsonResponse
    {
        if (!isset(self::SERVICE_LABELS[$service])) {
            return new JsonResponse(['ok' => false, 'error' => $this->translator?->trans('admin.test.unknown_service') ?? 'Service inconnu'], 400);
        }

        try {
            $this->health->invalidate($service);
            $ok = $this->health->isHealthy($service);
        } catch (\Throwable $e) {
            $this->logger->warning('AdminSettings test failed for {service}: {message}', [
                'service' => $service,
                'message' => $e->getMessage(),
            ]);
            return new JsonResponse(['ok' => false, 'error' => $this->translator?->trans('admin.test.unreachable') ?? 'Service injoignable']);
        }

        return new JsonResponse([
            'ok'      => $ok,
            'service' => self::SERVICE_LABELS[$service],
        ]);
    }

    #[Route('/languages/save', name: 'languages_save', methods: ['POST'])]
    public function languagesSave(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_languages', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', $this->translator?->trans('flash.csrf.invalid') ?? 'Jeton CSRF invalide, réessayez.');
            return $this->redirectToRoute('admin_settings_index');
        }

        $payload  = $request->request->all();
        $failed   = [];
        $changed  = false;

        // Prismarr settings (BDD)
        $prismarrUi   = (string) ($payload['prismarr_ui'] ?? '');
        $prismarrMeta = (string) ($payload['prismarr_metadata'] ?? '');

        if ($prismarrUi !== '' && in_array($prismarrUi, ['fr', 'en'], true)) {
            $this->settings->set('display_language', $prismarrUi);
            $changed = true;
        }
        if ($prismarrMeta !== '' && in_array($prismarrMeta, ['fr-FR', 'en-US'], true)) {
            $this->settings->set('display_metadata_language', $prismarrMeta);
            $changed = true;
        }
        // Invalidate cache so DisplayPreferencesService picks up the new values
        if ($changed) {
            try { $this->appCache->clear(); } catch (\Throwable) {}
        }

        // Radarr UI + Movie Info lang (push via API, même endpoint /config/ui)
        if ($this->config->get('radarr_url') && $this->config->get('radarr_api_key')
            && (isset($payload['radarr_ui']) || isset($payload['radarr_info']))) {
            try {
                /** @var RadarrClient $radarr */
                $radarr   = $this->container->get(RadarrClient::class);
                $ui       = $radarr->getUiConfig() ?? [];
                $changed_ = false;
                if (isset($payload['radarr_ui'])) {
                    $newId = (int) $payload['radarr_ui'];
                    if ($newId > 0 && ($ui['uiLanguage'] ?? null) !== $newId) {
                        $ui['uiLanguage'] = $newId;
                        $changed_ = true;
                    }
                }
                if (isset($payload['radarr_info'])) {
                    $newId = (int) $payload['radarr_info'];
                    if ($newId > 0 && ($ui['movieInfoLanguage'] ?? null) !== $newId) {
                        $ui['movieInfoLanguage'] = $newId;
                        $changed_ = true;
                    }
                }
                if ($changed_) {
                    $radarr->updateUiConfig($ui);
                }
            } catch (\Throwable $e) {
                $failed[] = 'Radarr';
                $this->logger->warning('AdminSettings languagesSave radarr failed', ['message' => $e->getMessage()]);
            }
        }

        // Sonarr UI lang (Sonarr v4 n'expose pas movieInfoLanguage/seriesInfoLanguage —
        // chaque série a son propre originalLanguage, pas de paramètre global)
        if ($this->config->get('sonarr_url') && $this->config->get('sonarr_api_key') && isset($payload['sonarr_ui'])) {
            try {
                /** @var SonarrClient $sonarr */
                $sonarr = $this->container->get(SonarrClient::class);
                $ui     = $sonarr->getUiConfig() ?? [];
                $newId  = (int) $payload['sonarr_ui'];
                if ($newId > 0 && ($ui['uiLanguage'] ?? null) !== $newId) {
                    $ui['uiLanguage'] = $newId;
                    $sonarr->updateUiConfig($ui);
                }
            } catch (\Throwable $e) {
                $failed[] = 'Sonarr';
                $this->logger->warning('AdminSettings languagesSave sonarr failed', ['message' => $e->getMessage()]);
            }
        }

        // Prowlarr UI lang (string code)
        if ($this->config->get('prowlarr_url') && $this->config->get('prowlarr_api_key') && isset($payload['prowlarr_ui'])) {
            try {
                /** @var ProwlarrClient $prowlarr */
                $prowlarr = $this->container->get(ProwlarrClient::class);
                $ui       = $prowlarr->getUiConfig() ?? [];
                $newCode  = (string) $payload['prowlarr_ui'];
                if (isset(self::PROWLARR_UI_LANGUAGES[$newCode]) && ($ui['uiLanguage'] ?? null) !== $newCode) {
                    $ui['uiLanguage'] = $newCode;
                    $prowlarr->updateUiConfig($ui);
                }
            } catch (\Throwable $e) {
                $failed[] = 'Prowlarr';
                $this->logger->warning('AdminSettings languagesSave prowlarr failed', ['message' => $e->getMessage()]);
            }
        }

        // Jellyseerr UI lang : on pousse à la fois sur le global (`/settings/main`,
        // visible dans Jellyseerr Settings → General → Display Language, défaut nouveaux
        // users) ET sur user 1 per-user (`/user/1/settings/main`). Ce dernier est
        // important parce que les appels API faits via la clé admin (par Prismarr)
        // résolvent le locale via le user-1, donc les métadonnées TMDb (titres,
        // overviews dans /request, /discover, etc.) suivent ce setting.
        // Note : POST /settings/main avec full payload échoue en HTTP 400 (apiKey
        // read-only) ; POST /user/1/settings/main valide l'email, donc full payload OK.
        if ($this->config->get('jellyseerr_url') && $this->config->get('jellyseerr_api_key') && isset($payload['jellyseerr_ui'])) {
            try {
                /** @var JellyseerrClient $jellyseerr */
                $jellyseerr = $this->container->get(JellyseerrClient::class);
                $newCode    = (string) $payload['jellyseerr_ui'];
                if (isset(self::JELLYSEERR_UI_LANGUAGES[$newCode])) {
                    // Global app default
                    $globalCurrent = $jellyseerr->getMainSettings() ?? [];
                    if (($globalCurrent['locale'] ?? null) !== $newCode) {
                        $jellyseerr->updateMainSettings(['locale' => $newCode]);
                    }
                    // User 1 (admin) per-user — drives metadata language for API calls
                    $userCurrent = $jellyseerr->getUserSettingsMain(1) ?? [];
                    if (($userCurrent['locale'] ?? null) !== $newCode) {
                        $userCurrent['locale'] = $newCode;
                        $jellyseerr->updateUserSettings(1, $userCurrent);
                    }
                }
            } catch (\Throwable $e) {
                $failed[] = 'Jellyseerr';
                $this->logger->warning('AdminSettings languagesSave jellyseerr failed', ['message' => $e->getMessage()]);
            }
        }

        if ($failed === []) {
            $this->addFlash('success', $this->translator?->trans('admin.languages.saved_success') ?? 'Langues mises à jour.');
        } else {
            $this->addFlash('warning', $this->translator?->trans('admin.languages.partial_error', ['services' => implode(', ', $failed)]) ?? 'Sauvegarde partielle : ' . implode(', ', $failed) . ' ont échoué.');
        }

        return $this->redirectToRoute('admin_settings_index');
    }

    /**
     * Fetches the current UI language + the list of available languages
     * for each connected *Arr service. Best-effort: each service is wrapped
     * in try/catch so a single dead service doesn't break the page.
     *
     * @return array<string, array{configured: bool, current: string|int|null, available: array<string|int, string>, error: bool}>
     */
    private function loadServiceLanguages(): array
    {
        $out = [
            'radarr'     => ['configured' => false, 'current' => null, 'current_info' => null, 'available' => [], 'error' => false],
            'sonarr'     => ['configured' => false, 'current' => null, 'available' => [], 'error' => false],
            'prowlarr'   => ['configured' => false, 'current' => null, 'available' => self::PROWLARR_UI_LANGUAGES, 'error' => false],
            'jellyseerr' => ['configured' => false, 'current' => null, 'available' => self::JELLYSEERR_UI_LANGUAGES, 'error' => false],
        ];

        // Radarr: id-based language list, /api/v3/language + /api/v3/config/ui
        if ($this->config->get('radarr_url') && $this->config->get('radarr_api_key')) {
            $out['radarr']['configured'] = true;
            try {
                /** @var RadarrClient $radarr */
                $radarr = $this->container->get(RadarrClient::class);
                $ui     = $radarr->getUiConfig() ?? [];
                $langs  = $radarr->getLanguages();
                $out['radarr']['current']      = $ui['uiLanguage'] ?? null;
                $out['radarr']['current_info'] = $ui['movieInfoLanguage'] ?? null;
                $out['radarr']['available']    = [];
                foreach ($langs as $l) {
                    if (isset($l['id'], $l['name'])) {
                        $out['radarr']['available'][(int) $l['id']] = (string) $l['name'];
                    }
                }
            } catch (\Throwable $e) {
                $out['radarr']['error'] = true;
                $this->logger->warning('AdminSettings loadLanguages radarr failed', ['message' => $e->getMessage()]);
            }
        }

        // Sonarr: UI language only — Sonarr v4 ne propose plus de paramètre global
        // pour la langue des metadata séries (chaque série a son originalLanguage).
        if ($this->config->get('sonarr_url') && $this->config->get('sonarr_api_key')) {
            $out['sonarr']['configured'] = true;
            try {
                /** @var SonarrClient $sonarr */
                $sonarr = $this->container->get(SonarrClient::class);
                $ui     = $sonarr->getUiConfig() ?? [];
                $langs  = $sonarr->getLanguages();
                $out['sonarr']['current']   = $ui['uiLanguage'] ?? null;
                $out['sonarr']['available'] = [];
                foreach ($langs as $l) {
                    if (isset($l['id'], $l['name'])) {
                        $out['sonarr']['available'][(int) $l['id']] = (string) $l['name'];
                    }
                }
            } catch (\Throwable $e) {
                $out['sonarr']['error'] = true;
                $this->logger->warning('AdminSettings loadLanguages sonarr failed', ['message' => $e->getMessage()]);
            }
        }

        // Prowlarr: ISO codes (no /language endpoint), curated list
        if ($this->config->get('prowlarr_url') && $this->config->get('prowlarr_api_key')) {
            $out['prowlarr']['configured'] = true;
            try {
                /** @var ProwlarrClient $prowlarr */
                $prowlarr = $this->container->get(ProwlarrClient::class);
                $ui = $prowlarr->getUiConfig() ?? [];
                $out['prowlarr']['current'] = $ui['uiLanguage'] ?? null;
            } catch (\Throwable $e) {
                $out['prowlarr']['error'] = true;
                $this->logger->warning('AdminSettings loadLanguages prowlarr failed', ['message' => $e->getMessage()]);
            }
        }

        // Jellyseerr: global app locale (`/api/v1/settings/main`).
        if ($this->config->get('jellyseerr_url') && $this->config->get('jellyseerr_api_key')) {
            $out['jellyseerr']['configured'] = true;
            try {
                /** @var JellyseerrClient $jellyseerr */
                $jellyseerr = $this->container->get(JellyseerrClient::class);
                $main = $jellyseerr->getMainSettings() ?? [];
                $out['jellyseerr']['current'] = $main['locale'] ?? null;
            } catch (\Throwable $e) {
                $out['jellyseerr']['error'] = true;
                $this->logger->warning('AdminSettings loadLanguages jellyseerr failed', ['message' => $e->getMessage()]);
            }
        }

        return $out;
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

    /**
     * @return array<string, string>  display_* key => current or default value
     */
    private function loadDisplayValues(): array
    {
        $out = [];
        foreach (self::DISPLAY_OPTIONS as $key => $spec) {
            $stored = $this->config->get($key);
            $out[$key] = $stored !== null && $stored !== '' ? $stored : $spec['default'];
        }
        return $out;
    }

    private function saveSubmitted(Request $request): void
    {
        $payload = [];
        foreach (self::FIELDS as $group) {
            foreach ($group as $field) {
                $value = trim((string) $request->request->get($field['key'], ''));
                // Sensitive fields (api keys, passwords) come back empty when
                // the browser refuses to keep type="password" pre-filled with
                // autocomplete="off" (Firefox + recent Chrome). Treat empty
                // submission as "unchanged" so saving the form from another
                // section (theme color, sidebar, etc.) does not silently wipe
                // every credential at once. To clear a key, the user must use
                // the dedicated reset action — never via an empty input.
                if (($field['type'] ?? null) === 'password' && $value === '') {
                    continue;
                }
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

        // Display preferences — only accept values from the declared allow-list
        // (selects/colors) or '1'/'0' for switches. Anything else is dropped
        // silently and the default kicks back in on next read. Hidden options
        // (display_language, display_metadata_language) sont édités par la
        // section Langues, donc on les ignore ici pour ne pas les écraser.
        foreach (self::DISPLAY_OPTIONS as $key => $spec) {
            if ($spec['hidden'] ?? false) continue;
            $raw = trim((string) $request->request->get($key, ''));
            $payload[$key] = $this->normalizeDisplayValue($spec, $raw);
        }

        $this->settings->setMany($payload);
        $this->config->invalidate();
        $this->health->invalidate();
        // Purge TMDb/Radarr/Sonarr response cache so data fetched with
        // the previous config doesn't linger up to an hour.
        $this->appCache->clear();
        $this->addFlash('success', $this->translator?->trans('admin.flash.saved') ?? 'Configuration enregistrée.');
    }

    /**
     * Non-sensitive settings only — keys containing secrets (api_key,
     * password) are filtered out so the exported JSON is safe to share
     * or commit to a private dotfiles repo.
     */
    private const EXPORT_SENSITIVE_PATTERNS = ['api_key', 'password', 'secret'];

    /**
     * @return array{safe: int, skipped: int}
     */
    private function exportCounts(): array
    {
        $safe = 0; $skipped = 0;
        foreach ($this->settings->findAll() as $s) {
            if ($this->isSensitiveKey($s->getName())) { $skipped++; } else { $safe++; }
        }
        return ['safe' => $safe, 'skipped' => $skipped];
    }

    #[Route('/reset-display', name: 'reset_display', methods: ['POST'])]
    public function resetDisplay(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings_reset_display', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->translator?->trans('flash.csrf.invalid') ?? 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_settings_index');
        }

        // Null value = delete the row, which makes the next read fall back
        // to the declared default in DISPLAY_OPTIONS.
        $payload = [];
        foreach (array_keys(self::DISPLAY_OPTIONS) as $key) {
            $payload[$key] = null;
        }
        $this->settings->setMany($payload);
        $this->config->invalidate();
        $this->health->invalidate();
        $this->appCache->clear();

        $this->addFlash('success', $this->translator?->trans('admin.flash.display_reset_full') ?? 'Préférences d\'affichage réinitialisées aux valeurs par défaut.');
        return $this->redirectToRoute('admin_settings_index');
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $all = $this->settings->findAll();
        $payload = [
            'prismarr_export_version' => 1,
            'exported_at'             => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'settings'                => [],
        ];

        foreach ($all as $setting) {
            $name = $setting->getName();
            if ($this->isSensitiveKey($name)) {
                continue;
            }
            $payload['settings'][$name] = $setting->getValue();
        }

        ksort($payload['settings']);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new JsonResponse(['error' => $this->translator?->trans('admin.export.encode_failed') ?? 'Encodage impossible.'], 500);
        }

        return new Response(
            $json,
            Response::HTTP_OK,
            [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => 'attachment; filename="prismarr-config-' . date('Y-m-d') . '.json"',
            ],
        );
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings_import', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->translator?->trans('flash.csrf.invalid') ?? 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_settings_index');
        }

        $file = $request->files->get('config');
        if (!$file || !$file->isValid()) {
            $this->addFlash('error', $this->translator?->trans('admin.import.no_file') ?? 'Aucun fichier reçu.');
            return $this->redirectToRoute('admin_settings_index');
        }

        if ($file->getSize() > 64_000) {
            $this->addFlash('error', $this->translator?->trans('admin.import.too_big') ?? 'Fichier trop volumineux (max 64 Ko).');
            return $this->redirectToRoute('admin_settings_index');
        }

        $raw = @file_get_contents($file->getPathname());
        if ($raw === false) {
            $this->addFlash('error', $this->translator?->trans('admin.import.read_failed') ?? 'Lecture du fichier impossible.');
            return $this->redirectToRoute('admin_settings_index');
        }

        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->addFlash('error', ($this->translator?->trans('admin.import.invalid_json') ?? 'JSON invalide') . ' : ' . $e->getMessage());
            return $this->redirectToRoute('admin_settings_index');
        }

        if (!is_array($payload) || ($payload['prismarr_export_version'] ?? 0) !== 1 || !is_array($payload['settings'] ?? null)) {
            $this->addFlash('error', $this->translator?->trans('admin.import.unknown_format') ?? 'Format non reconnu (version export v1 attendue).');
            return $this->redirectToRoute('admin_settings_index');
        }

        $applied = 0;
        $skipped = 0;
        $toApply = [];
        foreach ($payload['settings'] as $name => $value) {
            if (!is_string($name) || $name === '') {
                $skipped++;
                continue;
            }
            // Never let an import overwrite secrets — even if someone
            // managed to craft a payload with them, we refuse silently
            // so a compromised export file can't leak into DB.
            if ($this->isSensitiveKey($name)) {
                $skipped++;
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                $skipped++;
                continue;
            }
            $toApply[$name] = $value === null ? null : (string) $value;
            $applied++;
        }

        if ($applied > 0) {
            $this->settings->setMany($toApply);
            $this->config->invalidate();
            $this->health->invalidate();
            $this->appCache->clear();
        }

        $this->addFlash(
            'success',
            $this->translator?->trans('admin.import.result', [
                'applied' => $applied,
                'skipped' => $skipped,
            ]) ?? sprintf('%d réglage%s importé%s, %d ignoré%s.', $applied, $applied > 1 ? 's' : '', $applied > 1 ? 's' : '', $skipped, $skipped > 1 ? 's' : ''),
        );

        return $this->redirectToRoute('admin_settings_index');
    }

    private function isSensitiveKey(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::EXPORT_SENSITIVE_PATTERNS as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{label: string, type: string, default: string, options?: array<string, string>, help?: string} $spec
     */
    private function normalizeDisplayValue(array $spec, string $raw): ?string
    {
        if ($spec['type'] === 'switch') {
            return $raw === '1' ? '1' : '0';
        }

        if ($spec['type'] === 'timezone') {
            return in_array($raw, \DateTimeZone::listIdentifiers(), true) ? $raw : null;
        }

        if (isset($spec['options']) && isset($spec['options'][$raw])) {
            return $raw;
        }

        // Unknown / blanked value → null so loadDisplayValues() falls back to default.
        return null;
    }
}
