<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/jellyseerr', name: 'jellyseerr_')]
class JellyseerrController extends AbstractController
{
    private const VALID_FILTERS = ['all', 'pending', 'approved', 'processing', 'available', 'partial', 'unavailable', 'failed'];

    public function __construct(
        private readonly JellyseerrClient $jellyseerr,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    // ── Main page — Requests ─────────────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $filter = $request->query->get('filter', 'all');
        if (!in_array($filter, self::VALID_FILTERS, true)) {
            $filter = 'all';
        }

        $type = $request->query->get('type', '');
        $sort = $request->query->get('sort', 'added');
        $page = max(1, $request->query->getInt('page', 1));
        $take = 20;
        $skip = ($page - 1) * $take;

        $needsPhpFilter = $filter === 'partial' || in_array($type, ['movie', 'tv'], true);
        $apiFilter = match ($filter) {
            'all', 'partial' => null,
            default => $filter,
        };
        $apiSort = in_array($sort, ['added', 'modified'], true) ? $sort : 'added';

        $error  = false;
        $data   = ['pageInfo' => [], 'results' => []];
        $counts = [];
        $status = null;

        try {
            // /api/v1/status is public on Jellyseerr: we hit getAbout()
            // (admin settings) to validate both URL and API key.
            if ($this->jellyseerr->getAbout() === null) {
                $error = true;
            }
            $status = $this->jellyseerr->getStatus();

            if (!$error && $needsPhpFilter) {
                // Filters not supported by the API → load all and filter in PHP
                $allData = $this->jellyseerr->getRequests(500, 0, $apiFilter, $apiSort);
                $results = $allData['results'] ?? [];

                // Filter by type (movie/tv)
                if (in_array($type, ['movie', 'tv'], true)) {
                    $results = array_filter($results, fn($r) => ($r['type'] ?? '') === $type);
                }

                // Partial filter (media.status === 4)
                if ($filter === 'partial') {
                    $results = array_filter($results, fn($r) => ($r['media']['status'] ?? 0) === 4);
                }

                $filtered = array_values($results);
                $total = count($filtered);
                $data = [
                    'pageInfo' => ['pages' => max(1, (int) ceil($total / $take)), 'results' => $total, 'page' => $page],
                    'results'  => array_slice($filtered, $skip, $take),
                ];
            } elseif (!$error) {
                $data = $this->jellyseerr->getRequests($take, $skip, $apiFilter, $apiSort);
            }

            if (!$error) {
                $counts = $this->jellyseerr->getRequestCount();

                // Enrich each request with TMDb info
                foreach ($data['results'] as &$req) {
                    $req = $this->enrichRequest($req);
                }
                unset($req);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Jellyseerr index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('jellyseerr/index.html.twig', [
            'requests'  => $data['results'] ?? [],
            'pageInfo'  => $data['pageInfo'] ?? [],
            'counts'    => $counts,
            'filter'    => $filter,
            'sort'      => $sort,
            'page'      => $page,
            'status'    => $status,
            'error'     => $error,
            'service_url' => $this->config->get('jellyseerr_url'),
        ]);
    }

    // ── Users page ───────────────────────────────────────────────────────────

    #[Route('/utilisateurs', name: 'users')]
    public function users(): Response
    {
        $error = false;
        $users = [];
        $counts = [];

        try {
            $data   = $this->jellyseerr->getUsers(100, 0);
            $users  = $data['results'] ?? [];
            $counts = $this->jellyseerr->getRequestCount();

            // Enrich with quotas
            foreach ($users as &$u) {
                $u['_role'] = $this->decodeRole($u['permissions'] ?? 0);
                $u['_permList'] = $this->decodePermissions($u['permissions'] ?? 0);
            }
            unset($u);
        } catch (\Throwable $e) {
            $this->logger->warning('Jellyseerr users failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('jellyseerr/users.html.twig', [
            'users'  => $users,
            'counts' => $counts,
            'error'  => $error,
        ]);
    }

    #[Route('/utilisateurs/{id}', name: 'user_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function userDetail(int $id, Request $request): Response
    {
        if ($id === 1) {
            return $this->redirectToRoute('jellyseerr_users');
        }

        $user = $this->jellyseerr->getUser($id);
        if (!$user) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        $user['_role'] = $this->decodeRole($user['permissions'] ?? 0);
        $user['_permList'] = $this->decodePermissions($user['permissions'] ?? 0);

        $quota = $this->jellyseerr->getUserQuota($id);
        $user['_quota'] = $quota;

        $requests = $this->jellyseerr->getUserRequests($id, 50, 0);
        $userRequests = $requests['results'] ?? [];
        foreach ($userRequests as &$req) {
            $req = $this->enrichRequest($req);
        }
        unset($req);

        // If AJAX → return JSON (for the modal)
        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            $user['_recentRequests'] = $userRequests;
            return $this->json($user);
        }

        $settings = $this->jellyseerr->getUserSettingsMain($id) ?? [];
        $notifSettings = $this->jellyseerr->getUserSettingsNotifications($id) ?? [];
        $mainSettings = $this->jellyseerr->getMainSettings() ?? [];

        // Check whether a 4K server is configured
        $radarrSettings = $this->jellyseerr->getRadarrSettings();
        $sonarrSettings = $this->jellyseerr->getSonarrSettings();
        $has4k = false;
        foreach (array_merge($radarrSettings, $sonarrSettings) as $srv) {
            if (!empty($srv['is4k'])) { $has4k = true; break; }
        }

        $languages = $this->jellyseerr->getLanguages();
        $regions = $this->jellyseerr->getRegions();


        return $this->render('jellyseerr/user_detail.html.twig', [
            'user'     => $user,
            'quota'    => $quota,
            'settings' => $settings,
            'notifs'   => $notifSettings,
            'requests' => $userRequests,
            'defaultPerms' => $mainSettings['defaultPermissions'] ?? 0,
            'has4k'    => $has4k,
            'languages' => $languages,
            'regions'  => $regions,
        ]);
    }

    #[Route('/utilisateurs/create', name: 'user_create', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        if (!$email || !$password) {
            return $this->json(['ok' => false, 'error' => 'Email et mot de passe requis']);
        }
        $result = $this->jellyseerr->createLocalUser($email, $password);
        return $this->json(['ok' => $result !== null, 'data' => $result]);
    }

    #[Route('/utilisateurs/import-jellyfin', name: 'user_import_jellyfin', methods: ['POST'])]
    public function importJellyfinUsers(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = $data['jellyfinUserIds'] ?? [];
        if (empty($ids)) {
            return $this->json(['ok' => false, 'error' => 'Aucun utilisateur sélectionné']);
        }
        $result = $this->jellyseerr->importJellyfinUsers($ids);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/utilisateurs/{id}/delete', name: 'user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteUser(int $id): JsonResponse
    {
        if ($id === 1) {
            return $this->json(['ok' => false, 'error' => 'Le compte propriétaire ne peut pas être supprimé.']);
        }

        // Check whether the user exists and has admin/manager rights
        $user = $this->jellyseerr->getUser($id);
        if (!$user) {
            return $this->json(['ok' => false, 'error' => 'Utilisateur introuvable.']);
        }

        $perms = $user['permissions'] ?? 0;
        if ($perms & 2 || $perms & 4 || $perms & 8) {
            return $this->json(['ok' => false, 'error' => 'Les comptes administrateurs ne peuvent pas être supprimés.']);
        }

        $ok = $this->jellyseerr->deleteUser($id);
        if (!$ok) {
            return $this->json(['ok' => false, 'error' => 'Ce compte ne peut pas être supprimé.']);
        }
        return $this->json(['ok' => true]);
    }

    #[Route('/utilisateurs/{id}/password', name: 'user_update_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPassword(int $id, Request $request): JsonResponse
    {
        if ($id === 1) return $this->json(['ok' => false, 'error' => 'Compte propriétaire protégé.']);
        $data = json_decode($request->getContent(), true) ?? [];
        $password = $data['password'] ?? '';
        if (strlen($password) < 8) {
            return $this->json(['ok' => false, 'error' => '8 caractères minimum']);
        }
        $ok = $this->jellyseerr->updateUserPassword($id, $password);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/utilisateurs/{id}/notifications', name: 'user_update_notifs', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserNotifications(int $id, Request $request): JsonResponse
    {
        if ($id === 1) return $this->json(['ok' => false, 'error' => 'Compte propriétaire protégé.']);
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->updateUserNotifications($id, $data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/utilisateurs/{id}/settings', name: 'user_update_settings', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserMainSettings(int $id, Request $request): JsonResponse
    {
        if ($id === 1) return $this->json(['ok' => false, 'error' => 'Compte propriétaire protégé.']);
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->updateUserSettings($id, $data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/utilisateurs/{id}/permissions', name: 'user_update_perms', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPermissions(int $id, Request $request): JsonResponse
    {
        if ($id === 1) return $this->json(['ok' => false, 'error' => 'Compte propriétaire protégé.']);
        $data = json_decode($request->getContent(), true) ?? [];
        $perms = $data['permissions'] ?? null;
        if ($perms === null) {
            return $this->json(['ok' => false, 'error' => 'Permissions manquantes']);
        }
        $result = $this->jellyseerr->updateUserPermissions($id, (int) $perms);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/utilisateurs/{id}/quotas', name: 'user_update_quotas', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserQuotas(int $id, Request $request): JsonResponse
    {
        if ($id === 1) return $this->json(['ok' => false, 'error' => 'Compte propriétaire protégé.']);
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->updateUserSettings($id, $data);
        return $this->json(['ok' => $result !== null]);
    }

    // ── Settings pages ───────────────────────────────────────────────────────

    #[Route('/parametres', name: 'settings')]
    public function settings(): Response
    {
        $main = $this->jellyseerr->getMainSettings() ?? [];
        $languages = $this->jellyseerr->getLanguages();
        $regions = $this->jellyseerr->getRegions();
        return $this->render('jellyseerr/settings/general.html.twig', [
            'main' => $main,
            'languages' => $languages,
            'regions' => $regions,
        ]);
    }

    #[Route('/parametres/save', name: 'settings_save', methods: ['POST'])]
    public function settingsSave(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->updateMainSettings($data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/parametres/utilisateurs', name: 'settings_users')]
    public function settingsUsers(): Response
    {
        $main = $this->jellyseerr->getMainSettings() ?? [];
        return $this->render('jellyseerr/settings/users.html.twig', ['main' => $main]);
    }

    #[Route('/parametres/jellyfin', name: 'settings_jellyfin')]
    public function settingsJellyfin(): Response
    {
        $jellyfin = $this->jellyseerr->getJellyfinSettings() ?? [];
        return $this->render('jellyseerr/settings/jellyfin.html.twig', ['jellyfin' => $jellyfin]);
    }

    #[Route('/parametres/services/rules', name: 'settings_rules_list', methods: ['GET'])]
    public function settingsRulesList(): JsonResponse
    {
        $rules = $this->jellyseerr->getOverrideRules();
        return $this->json($rules);
    }

    #[Route('/parametres/services/rules/save', name: 'settings_rules_save', methods: ['POST'])]
    public function settingsRulesSave(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $id = $data['id'] ?? null;
        unset($data['id']);
        if ($id) {
            $result = $this->jellyseerr->updateOverrideRule($id, $data);
        } else {
            $result = $this->jellyseerr->createOverrideRule($data);
        }
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/parametres/services/rules/{id}/delete', name: 'settings_rules_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settingsRulesDelete(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->deleteOverrideRule($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/parametres/services', name: 'settings_services')]
    public function settingsServices(): Response
    {
        $radarr = $this->jellyseerr->getRadarrSettings();
        $sonarr = $this->jellyseerr->getSonarrSettings();
        // Load profiles/folders for each service
        $radarrProfiles = [];
        $sonarrProfiles = [];
        foreach ($radarr as $srv) {
            $svc = $this->jellyseerr->getServiceRadarr($srv['id'] ?? 0);
            $radarrProfiles[$srv['id'] ?? 0] = $svc;
        }
        foreach ($sonarr as $srv) {
            $svc = $this->jellyseerr->getServiceSonarr($srv['id'] ?? 0);
            $sonarrProfiles[$srv['id'] ?? 0] = $svc;
        }
        $rules = $this->jellyseerr->getOverrideRules();
        $users = $this->jellyseerr->getUsers(100, 0);
        $genres = array_merge($this->jellyseerr->getGenresMovie(), $this->jellyseerr->getGenresTv());
        // Deduplicate genres by ID
        $uniqueGenres = [];
        foreach ($genres as $g) { $uniqueGenres[$g['id'] ?? 0] = $g; }
        $languages = $this->jellyseerr->getLanguages();

        return $this->render('jellyseerr/settings/services.html.twig', [
            'users' => $users['results'] ?? [],
            'genres' => array_values($uniqueGenres),
            'languages' => $languages,
            'radarr' => $radarr,
            'sonarr' => $sonarr,
            'radarrProfiles' => $radarrProfiles,
            'sonarrProfiles' => $sonarrProfiles,
            'rules' => $rules,
        ]);
    }

    #[Route('/parametres/services/radarr/create', name: 'settings_radarr_create', methods: ['POST'])]
    public function settingsRadarrCreate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->createRadarrServer($data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/parametres/services/sonarr/create', name: 'settings_sonarr_create', methods: ['POST'])]
    public function settingsSonarrCreate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->createSonarrServer($data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/parametres/services/radarr/{id}/save', name: 'settings_radarr_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settingsRadarrSave(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->updateRadarrSettings($id, $data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/parametres/services/sonarr/{id}/save', name: 'settings_sonarr_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settingsSonarrSave(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $result = $this->jellyseerr->updateSonarrSettings($id, $data);
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/parametres/services/radarr/{id}/delete', name: 'settings_radarr_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settingsRadarrDelete(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->deleteRadarrServer($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/parametres/services/sonarr/{id}/delete', name: 'settings_sonarr_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settingsSonarrDelete(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->deleteSonarrServer($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/parametres/services/test', name: 'settings_service_test', methods: ['POST'])]
    public function settingsServiceTest(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $type = $data['type'] ?? 'radarr';
        unset($data['type']);
        $result = ($type === 'sonarr')
            ? $this->jellyseerr->testSonarrConnection($data)
            : $this->jellyseerr->testRadarrConnection($data);
        return $this->json(['ok' => $result !== null, 'data' => $result]);
    }

    #[Route('/parametres/a-propos', name: 'settings_about')]
    public function settingsAbout(): Response
    {
        $about = $this->jellyseerr->getAbout() ?? [];
        $status = $this->jellyseerr->getStatus() ?? [];
        $requestCounts = $this->jellyseerr->getRequestCount();
        $users = $this->jellyseerr->getUsers(1, 0);
        $issueCounts = $this->jellyseerr->getIssueCount();

        return $this->render('jellyseerr/settings/about.html.twig', [
            'about'         => $about,
            'status'        => $status,
            'requestCounts' => $requestCounts,
            'totalUsers'    => $users['pageInfo']['results'] ?? 0,
            'issueCounts'   => $issueCounts,
        ]);
    }

    #[Route('/parametres/mises-a-jour', name: 'settings_updates')]
    public function settingsUpdates(): Response
    {
        $status = $this->jellyseerr->getStatus() ?? [];
        $releases = $this->jellyseerr->getGitHubReleases(15);

        $currentVersion = $status['version'] ?? null;
        $parsed = [];

        foreach ($releases as $rel) {
            $tag = $rel['tag_name'] ?? '';
            $version = ltrim($tag, 'v');
            $body = $rel['body'] ?? '';

            // Parse markdown sections
            $sections = [];
            $sectionMap = [
                'Security'    => 'security',
                'Features'    => 'features',
                'Bug Fixes'   => 'fixes',
                'Refactor'    => 'refactor',
                'Performance' => 'performance',
            ];

            foreach ($sectionMap as $header => $key) {
                if (preg_match('/### [^\n]*' . preg_quote($header) . '\s*\r?\n(.*?)(?=\n### |\n## |$)/s', $body, $m)) {
                    $items = [];
                    preg_match_all('/^- (.+)$/m', $m[1], $lines);
                    foreach ($lines[1] as $line) {
                        // Clean markdown links and commits
                        $clean = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $line);
                        $clean = preg_replace('/ - \([a-f0-9]+\)$/', '', $clean);
                        $clean = trim($clean);
                        if ($clean) $items[] = $clean;
                    }
                    if ($items) $sections[$key] = $items;
                }
            }

            $parsed[] = [
                'version'   => $version,
                'tag'       => $tag,
                'date'      => $rel['published_at'] ?? null,
                'url'       => $rel['html_url'] ?? '#',
                'current'   => $currentVersion && $version === $currentVersion,
                'sections'  => $sections,
            ];
        }

        return $this->render('jellyseerr/settings/updates.html.twig', [
            'status'   => $status,
            'releases' => $parsed,
        ]);
    }

    #[Route('/parametres/logs', name: 'settings_logs')]
    public function settingsLogs(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $take = 50;
        $skip = ($page - 1) * $take;
        $filter = $request->query->get('filter', '');

        $data = $this->jellyseerr->getLogs($take, $skip, $filter ?: null);
        return $this->render('jellyseerr/settings/logs.html.twig', [
            'logs'     => $data['results'] ?? [],
            'pageInfo' => $data['pageInfo'] ?? [],
            'page'     => $page,
            'filter'   => $filter,
        ]);
    }

    #[Route('/parametres/taches-cache', name: 'settings_tasks_cache')]
    public function settingsTasksCache(): Response
    {
        $jobs = $this->jellyseerr->getJobs();
        $cache = $this->jellyseerr->getCacheStats() ?? [];

        return $this->render('jellyseerr/settings/tasks_cache.html.twig', [
            'jobs'  => $jobs,
            'cache' => $cache,
        ]);
    }

    #[Route('/job/{jobId}/run', name: 'job_run', methods: ['POST'])]
    public function jobRun(string $jobId): JsonResponse
    {
        $result = $this->jellyseerr->runJob($jobId);
        return $this->json(['ok' => $result !== null, 'job' => $result]);
    }

    #[Route('/job/{jobId}/cancel', name: 'job_cancel', methods: ['POST'])]
    public function jobCancel(string $jobId): JsonResponse
    {
        $result = $this->jellyseerr->cancelJob($jobId);
        return $this->json(['ok' => $result !== null, 'job' => $result]);
    }

    #[Route('/job/{jobId}/schedule', name: 'job_schedule', methods: ['POST'])]
    public function jobSchedule(string $jobId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $schedule = $data['schedule'] ?? '';
        if (!$schedule) {
            return $this->json(['ok' => false, 'error' => 'Schedule requis']);
        }
        $result = $this->jellyseerr->updateJobSchedule($jobId, $schedule);
        return $this->json(['ok' => $result !== null, 'job' => $result]);
    }

    #[Route('/cache/flush', name: 'cache_flush', methods: ['POST'])]
    public function cacheFlush(): JsonResponse
    {
        $ok = $this->jellyseerr->flushCache();
        return $this->json(['ok' => $ok]);
    }

    #[Route('/cache/{cacheId}/flush', name: 'cache_flush_one', methods: ['POST'])]
    public function cacheFlushOne(string $cacheId): JsonResponse
    {
        $ok = $this->jellyseerr->flushCacheById($cacheId);
        return $this->json(['ok' => $ok]);
    }

    // ── Request actions ──────────────────────────────────────────────────────

    #[Route('/request/{id}/approve', name: 'request_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approveRequest(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->approveRequest($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/request/{id}/decline', name: 'request_decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function declineRequest(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->declineRequest($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/request/{id}/delete', name: 'request_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteRequest(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->deleteRequest($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/request/{id}/retry', name: 'request_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retryRequest(int $id): JsonResponse
    {
        $ok = $this->jellyseerr->retryRequest($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/request/{id}/edit', name: 'request_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editRequest(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        if (empty($data)) {
            return $this->json(['ok' => false, 'error' => 'Données vides']);
        }
        $result = $this->jellyseerr->updateRequest($id, $data);
        return $this->json(['ok' => $result !== null, 'data' => $result]);
    }

    #[Route('/request/{id}', name: 'request_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function requestDetail(int $id): JsonResponse
    {
        $req = $this->jellyseerr->getRequest($id);
        if (!$req) {
            return $this->json(['error' => 'Requête introuvable'], 404);
        }

        $req = $this->enrichRequest($req);
        return $this->json($req);
    }

    #[Route('/service/jellyfin-sync', name: 'jellyfin_sync', methods: ['POST'])]
    public function jellyfinSync(): JsonResponse
    {
        $ok = $this->jellyseerr->syncJellyfinLibraries();
        return $this->json(['ok' => $ok]);
    }

    #[Route('/service/settings-main', name: 'settings_main', methods: ['GET'])]
    public function settingsMain(): JsonResponse
    {
        $settings = $this->jellyseerr->getMainSettings();
        return $this->json($settings ?? []);
    }

    #[Route('/service/jellyfin-users', name: 'jellyfin_users', methods: ['GET'])]
    public function jellyfinUsers(): JsonResponse
    {
        $jfUsers = $this->jellyseerr->getJellyfinUsers();
        $existingUsers = $this->jellyseerr->getUsers(200, 0);
        $settings = $this->jellyseerr->getMainSettings();

        // Collect jellyfinUserIds already imported
        $importedIds = [];
        foreach (($existingUsers['results'] ?? []) as $u) {
            if (!empty($u['jellyfinUserId'])) {
                $importedIds[$u['jellyfinUserId']] = true;
            }
        }

        // Filter: keep only those not yet imported
        $available = array_values(array_filter($jfUsers, fn($u) => !isset($importedIds[$u['id'] ?? ''])));

        return $this->json([
            'users' => $available,
            'mediaServerLogin' => $settings['mediaServerLogin'] ?? false,
        ]);
    }

    #[Route('/service/{type}', name: 'service_info', methods: ['GET'], requirements: ['type' => 'radarr|sonarr'])]
    public function serviceInfo(string $type): JsonResponse
    {
        // Fetch the server list to find the default server ID
        $servers = match ($type) {
            'radarr' => $this->jellyseerr->getRadarrSettings(),
            'sonarr' => $this->jellyseerr->getSonarrSettings(),
            default  => [],
        };

        $defaultServer = null;
        foreach ($servers as $srv) {
            if ($srv['isDefault'] ?? false) {
                $defaultServer = $srv;
                break;
            }
        }
        if (!$defaultServer && !empty($servers)) {
            $defaultServer = $servers[0];
        }

        $serverId = $defaultServer['id'] ?? 0;
        $data = match ($type) {
            'radarr' => $this->jellyseerr->getServiceRadarr($serverId),
            'sonarr' => $this->jellyseerr->getServiceSonarr($serverId),
            default  => null,
        };
        return $this->json($data ?? ['profiles' => [], 'rootFolders' => []]);
    }

    #[Route('/users', name: 'users_list', methods: ['GET'])]
    public function usersList(): JsonResponse
    {
        $data = $this->jellyseerr->getUsers(100, 0);
        return $this->json($data['results'] ?? []);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function decodeRole(int $perms): string
    {
        if ($perms & 2) return 'Admin';
        if ($perms & 8) return 'Gestionnaire';
        if ($perms & 32) return 'Utilisateur';
        return 'Limité';
    }

    private function decodePermissions(int $perms): array
    {
        $map = [
            2 => 'Admin',
            4 => 'Gérer paramètres',
            8 => 'Gérer utilisateurs',
            16 => 'Gérer demandes',
            32 => 'Demander',
            64 => 'Voter',
            128 => 'Auto-approbation',
            256 => 'Auto films',
            512 => 'Auto séries',
            1024 => 'Demander 4K',
            2048 => '4K films',
            4096 => '4K séries',
            8192 => 'Demande avancée',
            16384 => 'Voir demandes',
            32768 => 'Auto 4K',
            65536 => 'Auto 4K films',
            131072 => 'Auto 4K séries',
            262144 => 'Demander films',
            524288 => 'Demander séries',
            1048576 => 'Gérer issues',
            2097152 => 'Voir issues',
            4194304 => 'Créer issues',
            8388608 => 'Demande auto',
            16777216 => 'Demande auto films',
            33554432 => 'Demande auto séries',
            67108864 => 'Activité récente',
            134217728 => 'Listes surveillance',
            268435456 => 'Gérer blocage',
            1073741824 => 'Voir blocage',
        ];

        $result = [];
        foreach ($map as $bit => $label) {
            if ($perms & $bit) {
                $result[] = $label;
            }
        }
        return $result;
    }

    private function enrichRequest(array $req): array
    {
        $tmdbId = $req['media']['tmdbId'] ?? null;
        $type   = $req['type'] ?? $req['media']['mediaType'] ?? null;

        if ($tmdbId && $type) {
            try {
                $tmdb = ($type === 'movie')
                    ? $this->jellyseerr->searchMovie($tmdbId)
                    : $this->jellyseerr->searchTv($tmdbId);

                if ($tmdb) {
                    $req['_tmdb'] = [
                        'title'        => $tmdb['title'] ?? $tmdb['name'] ?? '?',
                        'overview'     => $tmdb['overview'] ?? '',
                        'posterPath'   => $tmdb['posterPath'] ?? null,
                        'backdropPath' => $tmdb['backdropPath'] ?? null,
                        'releaseDate'  => $tmdb['releaseDate'] ?? $tmdb['firstAirDate'] ?? null,
                        'voteAverage'  => $tmdb['voteAverage'] ?? null,
                        'genres'       => array_map(fn($g) => $g['name'] ?? $g, $tmdb['genres'] ?? []),
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Jellyseerr enrichRequest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                // No big deal, render without enrichment
            }
        }

        // Human-readable status — based on media.status (not request.status for availability)
        $mediaStatus   = $req['media']['status'] ?? 1;
        $requestStatus = $req['status'] ?? 0;

        // Priority: request status first, then media status for availability
        if ($requestStatus === 3) {
            $req['_statusLabel'] = 'Refusée';
            $req['_statusColor'] = 'danger';
        } elseif ($mediaStatus === 5) {
            $req['_statusLabel'] = 'Disponible';
            $req['_statusColor'] = 'success';
        } elseif ($mediaStatus === 4) {
            $req['_statusLabel'] = 'Partiellement disponible';
            $req['_statusColor'] = 'cyan';
        } elseif ($requestStatus === 2) {
            $req['_statusLabel'] = 'En cours';
            $req['_statusColor'] = 'purple';
        } elseif ($mediaStatus === 3) {
            $req['_statusLabel'] = 'Approuvée';
            $req['_statusColor'] = 'info';
        } elseif ($requestStatus === 1) {
            $req['_statusLabel'] = 'En attente';
            $req['_statusColor'] = 'warning';
        } else {
            $req['_statusLabel'] = 'Inconnu';
            $req['_statusColor'] = 'secondary';
        }

        return $req;
    }
}
