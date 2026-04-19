<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/prowlarr', name: 'prowlarr_')]
class ProwlarrController extends AbstractController
{
    public function __construct(
        private readonly ProwlarrClient $prowlarr,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    // ── Page principale — Indexeurs ───────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $indexers = [];
        $status   = [];
        $stats    = [];
        $health   = [];
        $apps     = [];
        $idxStats = [];
        $error    = false;

        try {
            if ($this->prowlarr->getSystemStatus() === null) {
                $error = true;
            } else {
                $indexers = $this->prowlarr->getIndexers();
                $status   = $this->prowlarr->getIndexerStatus();
                $stats    = $this->prowlarr->getStats();
                $health   = $this->prowlarr->getHealth();
                $apps     = $this->prowlarr->getApplications();
                // Per-indexer stats (queries, grabs, fails)
                $rawStats = $this->prowlarr->getIndexerStats();
                foreach ($rawStats['indexers'] ?? [] as $s) {
                    $idxStats[$s['indexerId']] = $s;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('prowlarr/index.html.twig', [
            'indexers'   => $indexers,
            'status'     => $status,
            'stats'      => $stats,
            'health'     => $health,
            'apps'       => $apps,
            'idxStats'   => $idxStats,
            'error'     => $error,
            'service_url' => $this->config->get('prowlarr_url'),
        ]);
    }

    // ── CRUD Indexeurs ────────────────────────────────────────────────────────

    #[Route('/indexer/schema', name: 'indexer_schema', methods: ['GET'])]
    public function indexerSchema(): JsonResponse
    {
        return $this->json($this->prowlarr->getIndexerSchema());
    }

    #[Route('/indexer/{id}', name: 'indexer_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function indexerGet(int $id): JsonResponse
    {
        return $this->json($this->prowlarr->getRawIndexer($id));
    }

    #[Route('/indexer/add', name: 'indexer_add', methods: ['POST'])]
    public function indexerAdd(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $result = $this->prowlarr->addIndexerWithError($data);
        return $this->json($result);
    }

    #[Route('/indexer/{id}/update', name: 'indexer_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function indexerUpdate(int $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $result = $this->prowlarr->updateIndexerWithError($id, $data);
        return $this->json($result);
    }

    #[Route('/indexer/{id}/delete', name: 'indexer_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function indexerDelete(int $id): JsonResponse
    {
        $ok = $this->prowlarr->deleteIndexer($id);
        return $this->json(['ok' => $ok]);
    }

    #[Route('/indexer/test', name: 'indexer_test', methods: ['POST'])]
    public function indexerTest(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $result = $this->prowlarr->testIndexer($data);
        return $this->json($result);
    }

    #[Route('/indexer/{id}/toggle', name: 'indexer_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function indexerToggle(int $id): JsonResponse
    {
        $raw = $this->prowlarr->getRawIndexer($id);
        if (!$raw) return $this->json(['ok' => false, 'error' => 'Indexeur introuvable']);
        $raw['enable'] = !($raw['enable'] ?? false);
        $result = $this->prowlarr->updateIndexer($id, $raw);
        return $this->json(['ok' => $result !== null, 'enabled' => $raw['enable']]);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $indexerId = $request->query->getInt('indexerId') ?: null;
        if (strlen($query) < 2) return $this->json([]);
        $results = $this->prowlarr->search($query, $indexerId);
        return $this->json($results);
    }

    // ── Historique ────────────────────────────────────────────────────────────

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        return $this->json($this->prowlarr->getHistory($page, 50));
    }

    #[Route('/indexer/{id}/history', name: 'indexer_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function indexerHistory(int $id): JsonResponse
    {
        $data = $this->prowlarr->getIndexerHistory($id, 30);
        return $this->json($data['records'] ?? []);
    }

    // ── Applications (page) ─────────────────────────────────────────────────

    #[Route('/apps', name: 'apps_page')]
    public function appsPage(): Response
    {
        $apps = [];
        $schema = [];
        $error = false;
        try {
            $apps = $this->prowlarr->getApplications();
            $schema = $this->prowlarr->getApplicationSchema();
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr appsPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('prowlarr/apps.html.twig', [
            'apps' => $apps,
            'schema' => $schema,
            'error' => $error,
        ]);
    }

    #[Route('/applications', name: 'applications', methods: ['GET'])]
    public function applications(): JsonResponse
    {
        return $this->json($this->prowlarr->getApplications());
    }

    #[Route('/application/schema', name: 'application_schema', methods: ['GET'])]
    public function applicationSchema(): JsonResponse
    {
        return $this->json($this->prowlarr->getApplicationSchema());
    }

    #[Route('/application/{id}', name: 'application_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function applicationGet(int $id): JsonResponse
    {
        return $this->json($this->prowlarr->getRawApplication($id));
    }

    #[Route('/application/add', name: 'application_add', methods: ['POST'])]
    public function applicationAdd(Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->addApplication($request->toArray()));
    }

    #[Route('/application/{id}/update', name: 'application_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function applicationUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->updateApplication($id, $request->toArray()));
    }

    #[Route('/application/{id}/delete', name: 'application_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function applicationDelete(int $id): JsonResponse
    {
        return $this->json(['ok' => $this->prowlarr->deleteApplication($id)]);
    }

    #[Route('/application/test', name: 'application_test', methods: ['POST'])]
    public function applicationTest(Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->testApplication($request->toArray()));
    }

    // ── Download Client CRUD ─────────────────────────────────────────────

    #[Route('/downloadclient/schema', name: 'downloadclient_schema', methods: ['GET'])]
    public function downloadClientSchema(): JsonResponse
    {
        return $this->json($this->prowlarr->getDownloadClientSchema());
    }

    #[Route('/downloadclient/{id}', name: 'downloadclient_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadClientGet(int $id): JsonResponse
    {
        return $this->json($this->prowlarr->getRawDownloadClient($id));
    }

    #[Route('/downloadclient/add', name: 'downloadclient_add', methods: ['POST'])]
    public function downloadClientAdd(Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->addDownloadClient($request->toArray()));
    }

    #[Route('/downloadclient/{id}/update', name: 'downloadclient_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function downloadClientUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->updateDownloadClient($id, $request->toArray()));
    }

    #[Route('/downloadclient/{id}/delete', name: 'downloadclient_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function downloadClientDelete(int $id): JsonResponse
    {
        return $this->json(['ok' => $this->prowlarr->deleteDownloadClient($id)]);
    }

    #[Route('/downloadclient/test', name: 'downloadclient_test', methods: ['POST'])]
    public function downloadClientTest(Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->testDownloadClient($request->toArray()));
    }

    // ── Notification CRUD ────────────────────────────────────────────────

    #[Route('/notification/schema', name: 'notification_schema', methods: ['GET'])]
    public function notificationSchema(): JsonResponse
    {
        return $this->json($this->prowlarr->getNotificationSchema());
    }

    #[Route('/notification/{id}', name: 'notification_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function notificationGet(int $id): JsonResponse
    {
        return $this->json($this->prowlarr->getRawNotification($id));
    }

    #[Route('/notification/add', name: 'notification_add', methods: ['POST'])]
    public function notificationAdd(Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->addNotification($request->toArray()));
    }

    #[Route('/notification/{id}/update', name: 'notification_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function notificationUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->updateNotification($id, $request->toArray()));
    }

    #[Route('/notification/{id}/delete', name: 'notification_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function notificationDelete(int $id): JsonResponse
    {
        return $this->json(['ok' => $this->prowlarr->deleteNotification($id)]);
    }

    #[Route('/notification/test', name: 'notification_test', methods: ['POST'])]
    public function notificationTest(Request $request): JsonResponse
    {
        return $this->json($this->prowlarr->testNotification($request->toArray()));
    }

    // ── Historique (page) ────────────────────────────────────────────────────

    #[Route('/historique', name: 'history_page')]
    public function historyPage(Request $request): Response
    {
        $records = [];
        $total = 0;
        $error = false;
        $since = $request->query->get('since');

        try {
            if ($since) {
                $raw = $this->prowlarr->getHistorySince($since);
                // getHistorySince returns a flat array of objects
                $records = is_array($raw) && !isset($raw['records']) ? $raw : ($raw['records'] ?? []);
                $total = count($records);
            } else {
                $history = $this->prowlarr->getHistory(1, 100);
                $records = $history['records'] ?? [];
                $total = $history['totalRecords'] ?? 0;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr historyPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('prowlarr/history.html.twig', [
            'records' => $records,
            'total'   => $total,
            'error'   => $error,
        ]);
    }

    // ── System status (page) ─────────────────────────────────────────────────

    #[Route('/systeme', name: 'system_page')]
    public function systemPage(): Response
    {
        $status = null;
        $health = [];
        $error = false;
        try {
            $status = $this->prowlarr->getSystemStatus();
            $health = $this->prowlarr->getHealth();
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr systemPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('prowlarr/system.html.twig', [
            'status' => $status,
            'health' => $health,
            'error'  => $error,
        ]);
    }

    // ── Logs (page) ──────────────────────────────────────────────────────────

    #[Route('/logs-page', name: 'logs_page')]
    public function logsPage(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $logs = [];
        $error = false;
        try {
            $logs = $this->prowlarr->getLogs($page, 100);
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr logsPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('prowlarr/logs.html.twig', [
            'records' => $logs['records'] ?? [],
            'total'   => $logs['totalRecords'] ?? 0,
            'page'    => $page,
            'error'   => $error,
        ]);
    }

    // ── Download Clients (page) ─────────────────────────────────────────

    #[Route('/download-clients', name: 'download_clients_page')]
    public function downloadClientsPage(): Response
    {
        $clients = [];
        $dlConfig = null;
        $error = false;
        try {
            $clients = $this->prowlarr->getDownloadClients();
            $dlConfig = $this->prowlarr->getDownloadClientConfig();
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr downloadClientsPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/download_clients.html.twig', ['clients' => $clients, 'dlConfig' => $dlConfig, 'error' => $error]);
    }

    // ── Notifications (page) ─────────────────────────────────────────────

    #[Route('/notifications', name: 'notifications_page')]
    public function notificationsPage(): Response
    {
        $notifs = [];
        $error = false;
        try { $notifs = $this->prowlarr->getNotifications(); } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr notificationsPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/notifications.html.twig', ['notifications' => $notifs, 'error' => $error]);
    }

    // ── Tags (page) ──────────────────────────────────────────────────────

    #[Route('/tags-page', name: 'tags_page')]
    public function tagsPage(): Response
    {
        $tags = [];
        $tagsDetail = [];
        $error = false;
        try {
            $tags = $this->prowlarr->getTags();
            $detail = $this->prowlarr->getTagsDetail();
            foreach ($detail as $d) {
                $tagsDetail[$d['id']] = $d;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr tagsPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/tags.html.twig', ['tags' => $tags, 'tagsDetail' => $tagsDetail, 'error' => $error]);
    }

    // ── General (page) ───────────────────────────────────────────────────

    #[Route('/general', name: 'general_page')]
    public function generalPage(): Response
    {
        $config = null;
        $error = false;
        try { $config = $this->prowlarr->getGeneralConfig(); } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr generalPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/general.html.twig', ['config' => $config, 'error' => $error]);
    }

    #[Route('/general/save', name: 'general_save', methods: ['POST'])]
    public function generalSave(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $result = $this->prowlarr->updateGeneralConfig($data);
        return $this->json(['ok' => $result !== null]);
    }

    // ── UI (page) ────────────────────────────────────────────────────────

    #[Route('/ui', name: 'ui_page')]
    public function uiPage(): Response
    {
        $config = null;
        $error = false;
        try { $config = $this->prowlarr->getUiConfig(); } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr uiPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/ui.html.twig', ['config' => $config, 'error' => $error]);
    }

    #[Route('/ui/save', name: 'ui_save', methods: ['POST'])]
    public function uiSave(Request $request): JsonResponse
    {
        $result = $this->prowlarr->updateUiConfig($request->toArray());
        return $this->json(['ok' => $result !== null]);
    }

    // ── Tasks (page) ─────────────────────────────────────────────────────

    #[Route('/tasks', name: 'tasks_page')]
    public function tasksPage(): Response
    {
        $tasks = [];
        $error = false;
        try { $tasks = $this->prowlarr->getTasks(); } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr tasksPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/tasks.html.twig', ['tasks' => $tasks, 'error' => $error]);
    }

    // ── Backups (page) ───────────────────────────────────────────────────

    #[Route('/backups', name: 'backups_page')]
    public function backupsPage(): Response
    {
        $backups = [];
        $error = false;
        try { $backups = $this->prowlarr->getBackups(); } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr backupsPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/backups.html.twig', ['backups' => $backups, 'error' => $error]);
    }

    // ── Updates (page) ───────────────────────────────────────────────────

    #[Route('/updates', name: 'updates_page')]
    public function updatesPage(): Response
    {
        $updates = [];
        $status = null;
        $error = false;
        try { $updates = $this->prowlarr->getUpdates(); $status = $this->prowlarr->getSystemStatus(); } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr updatesPage failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('prowlarr/updates.html.twig', ['updates' => $updates, 'status' => $status, 'error' => $error]);
    }

    // ── Tasks run ────────────────────────────────────────────────────────

    #[Route('/taches/executer', name: 'task_run', methods: ['POST'])]
    public function taskRun(Request $request): JsonResponse
    {
        $name = $request->toArray()['name'] ?? '';
        $result = $this->prowlarr->sendCommand($name);
        return $this->json(['ok' => $result !== null]);
    }

    // ── Backup create/delete ─────────────────────────────────────────────

    #[Route('/sauvegardes/creer', name: 'backup_create', methods: ['POST'])]
    public function backupCreate(): JsonResponse
    {
        $result = $this->prowlarr->sendCommand('Backup');
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/sauvegardes/{id}/supprimer', name: 'backup_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function backupDelete(int $id): JsonResponse
    {
        $ok = $this->prowlarr->deleteBackup($id);
        return $this->json(['ok' => $ok]);
    }

    // ── Tag delete ───────────────────────────────────────────────────────

    #[Route('/tags/{id}/supprimer', name: 'tag_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function tagDelete(int $id): JsonResponse
    {
        $ok = $this->prowlarr->deleteTag($id);
        return $this->json(['ok' => $ok]);
    }

    // ── Indexer Stats ─────────────────────────────────────────────────

    #[Route('/indexer/stats', name: 'indexer_stats', methods: ['GET'])]
    public function indexerStats(): JsonResponse
    {
        return $this->json($this->prowlarr->getIndexerStats());
    }

    // ── Indexer Categories ───────────────────────────────────────────

    #[Route('/indexer/categories', name: 'indexer_categories', methods: ['GET'])]
    public function indexerCategories(): JsonResponse
    {
        return $this->json($this->prowlarr->getIndexerDefaultCategories());
    }

    // ── Indexer Bulk ─────────────────────────────────────────────────

    #[Route('/indexer/bulk/update', name: 'indexer_bulk_update', methods: ['POST'])]
    public function indexerBulkUpdate(Request $request): JsonResponse
    {
        $result = $this->prowlarr->bulkUpdateIndexers($request->toArray());
        return $this->json(['ok' => $result !== null]);
    }

    #[Route('/indexer/bulk/delete', name: 'indexer_bulk_delete', methods: ['POST'])]
    public function indexerBulkDelete(Request $request): JsonResponse
    {
        $ids = $request->toArray()['ids'] ?? [];
        return $this->json(['ok' => $this->prowlarr->bulkDeleteIndexers($ids)]);
    }

    #[Route('/indexer/testall', name: 'indexer_testall', methods: ['POST'])]
    public function indexerTestAll(): JsonResponse
    {
        return $this->json($this->prowlarr->testAllIndexers());
    }

    // ── Command Status ──────────────────────────────────────────────

    #[Route('/command/{id}', name: 'command_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function commandStatus(int $id): JsonResponse
    {
        return $this->json($this->prowlarr->getCommand($id));
    }

    // ── Download Client Config ──────────────────────────────────────

    #[Route('/downloadclient-config', name: 'downloadclient_config', methods: ['GET'])]
    public function downloadClientConfig(): JsonResponse
    {
        return $this->json($this->prowlarr->getDownloadClientConfig());
    }

    #[Route('/downloadclient-config/save', name: 'downloadclient_config_save', methods: ['POST'])]
    public function downloadClientConfigSave(Request $request): JsonResponse
    {
        $result = $this->prowlarr->updateDownloadClientConfig($request->toArray());
        return $this->json(['ok' => $result !== null]);
    }

    // ── History Since ───────────────────────────────────────────────

    #[Route('/history/since', name: 'history_since', methods: ['GET'])]
    public function historySince(Request $request): JsonResponse
    {
        $date = $request->query->get('date', (new \DateTimeImmutable('-7 days'))->format('Y-m-d'));
        return $this->json($this->prowlarr->getHistorySince($date));
    }

    // ── Tag Detail ──────────────────────────────────────────────────

    #[Route('/tags/detail', name: 'tags_detail', methods: ['GET'])]
    public function tagsDetail(): JsonResponse
    {
        return $this->json($this->prowlarr->getTagsDetail());
    }

    // ── JSON endpoints ──────────────────────────────────────────────────────

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json($this->prowlarr->getHealth());
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json($this->prowlarr->getSystemStatus());
    }

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function logs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        return $this->json($this->prowlarr->getLogs($page));
    }

    // ── Tags ─────────────────────────────────────────────────────────────────

    #[Route('/tags', name: 'tags', methods: ['GET'])]
    public function tags(): JsonResponse
    {
        return $this->json($this->prowlarr->getTags());
    }

    #[Route('/tag/create', name: 'tag_create', methods: ['POST'])]
    public function tagCreate(Request $request): JsonResponse
    {
        $label = $request->toArray()['label'] ?? '';
        if (!$label) return $this->json(['ok' => false]);
        $result = $this->prowlarr->createTag($label);
        return $this->json(['ok' => !empty($result), 'tag' => $result]);
    }

    // ── App Profiles ─────────────────────────────────────────────────────────

    #[Route('/app-profiles', name: 'app_profiles', methods: ['GET'])]
    public function appProfiles(): JsonResponse
    {
        return $this->json($this->prowlarr->getAppProfiles());
    }

    #[Route('/app-profile/add', name: 'app_profile_add', methods: ['POST'])]
    public function appProfileAdd(Request $request): JsonResponse
    {
        $result = $this->prowlarr->addAppProfile($request->toArray());
        return $this->json(['ok' => $result !== null, 'profile' => $result]);
    }

    #[Route('/app-profile/{id}/update', name: 'app_profile_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function appProfileUpdate(int $id, Request $request): JsonResponse
    {
        $result = $this->prowlarr->updateAppProfile($id, $request->toArray());
        return $this->json(['ok' => $result !== null, 'profile' => $result]);
    }

    #[Route('/app-profile/{id}/delete', name: 'app_profile_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function appProfileDelete(int $id): JsonResponse
    {
        return $this->json(['ok' => $this->prowlarr->deleteAppProfile($id)]);
    }

    // ── Commandes ────────────────────────────────────────────────────────────

    #[Route('/command', name: 'command', methods: ['POST'])]
    public function command(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $name = $data['name'] ?? '';
        $result = $this->prowlarr->sendCommand($name, $data);
        return $this->json(['ok' => $result !== null, 'command' => $result]);
    }
}
