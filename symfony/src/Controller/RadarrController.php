<?php

namespace App\Controller;

use App\Controller\Concerns\ApiClientErrorTrait;
use App\Service\Media\RadarrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/medias/{slug}/radarr', name: 'radarr_', requirements: ['slug' => '[a-z0-9][a-z0-9-]*'])]
class RadarrController extends AbstractController
{
    use ApiClientErrorTrait;

    public function __construct(
        private readonly RadarrClient $radarr,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    // ── Updates ───────────────────────────────────────────────────────────────

    #[Route('/mises-a-jour', name: 'updates')]
    public function updates(): Response
    {
        $updates = [];
        $status = null;
        $error   = false;
        try {
            $updates = $this->radarr->getUpdates();
            $status = $this->radarr->getSystemStatus();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr updates failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/updates.html.twig', ['updates' => $updates, 'status' => $status, 'error' => $error]);
    }

    #[Route('/mises-a-jour/installer', name: 'install_update', methods: ['POST'])]
    public function installUpdate(): JsonResponse
    {
        try {
            $cmdId = $this->radarr->installUpdate();
            return $this->json(['ok' => true, 'cmdId' => $cmdId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr installUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Backups ───────────────────────────────────────────────────────────────

    #[Route('/sauvegardes', name: 'backups')]
    public function backups(): Response
    {
        $backups = [];
        $error   = false;
        try {
            $backups = $this->radarr->getBackups();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr backups failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/backups.html.twig', ['backups' => $backups, 'error' => $error]);
    }

    #[Route('/sauvegardes/creer', name: 'backup_create', methods: ['POST'])]
    public function backupCreate(): JsonResponse
    {
        try {
            $cmdId = $this->radarr->createBackup();
            return $this->json(['ok' => true, 'cmdId' => $cmdId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr backupCreate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/sauvegardes/{id}/supprimer', name: 'backup_delete', methods: ['POST'])]
    public function backupDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteBackup($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr backupDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/sauvegardes/{id}/restaurer', name: 'backup_restore', methods: ['POST'])]
    public function backupRestore(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->restoreBackup($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr backupRestore failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    #[Route('/notifications', name: 'notifications')]
    public function notifications(): Response
    {
        $notifications = [];
        $error         = false;
        try {
            $notifications = $this->radarr->getNotifications();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notifications failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/notifications.html.twig', ['notifications' => $notifications, 'error' => $error]);
    }

    #[Route('/notifications/{id}/supprimer', name: 'notification_delete', methods: ['POST'])]
    public function notificationDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteNotification($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notificationDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/notifications/{id}/tester', name: 'notification_test', methods: ['POST'])]
    public function notificationTest(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->testNotification($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notificationTest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/notifications/schema', name: 'notifications_schema', methods: ['GET'])]
    public function notificationsSchema(): JsonResponse
    {
        try {
            return $this->json($this->radarr->getNotificationSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notificationsSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/notifications/ajouter', name: 'notification_add', methods: ['POST'])]
    public function notificationAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createNotificationWithError($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notificationAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/notifications/{id}/modifier', name: 'notification_update', methods: ['POST'])]
    public function notificationUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateNotificationWithError($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notificationUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/notifications/tester-payload', name: 'notification_test_payload', methods: ['POST'])]
    public function notificationTestPayload(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->testNotificationPayload($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr notificationTestPayload failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Import List Exclusions ─────────────────────────────────────────────────

    #[Route('/exclusions', name: 'exclusions')]
    public function exclusions(Request $request): Response
    {
        $page       = $request->query->getInt('page', 1);
        $exclusions = [];
        $error      = false;
        try {
            $exclusions = $this->radarr->getImportListExclusions($page, 50);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr exclusions failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/exclusions.html.twig', [
            'exclusions' => $exclusions,
            'page'       => $page,
            'error'      => $error,
        ]);
    }

    #[Route('/exclusions/ajouter', name: 'exclusion_add', methods: ['POST'])]
    public function exclusionAdd(Request $request): JsonResponse
    {
        try {
            $data      = $request->toArray();
            $exclusion = $this->radarr->addImportListExclusion(
                (int) ($data['tmdbId'] ?? 0),
                $data['movieTitle'] ?? '',
                (int) ($data['year'] ?? 0),
            );
            return $this->json(['ok' => $exclusion !== null, 'exclusion' => $exclusion]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr exclusionAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/exclusions/{id}/supprimer', name: 'exclusion_delete', methods: ['POST'])]
    public function exclusionDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteImportListExclusion($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr exclusionDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/exclusions/bulk-supprimer', name: 'exclusion_bulk_delete', methods: ['POST'])]
    public function exclusionBulkDelete(Request $request): JsonResponse
    {
        try {
            $ids = $request->toArray()['ids'] ?? [];
            $ok = $this->radarr->bulkDeleteImportListExclusions($ids);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr exclusionBulkDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Import List Movies (suggestions) ──────────────────────────────────────

    #[Route('/suggestions', name: 'suggestions')]
    public function suggestions(): Response
    {
        $movies          = [];
        $qualityProfiles = [];
        $rootFolders     = [];
        $error           = false;
        try {
            $movies          = $this->radarr->getImportListMoviesWithRecommendations();
            $qualityProfiles = $this->radarr->getQualityProfiles();
            $rootFolders     = $this->radarr->getRootFolders();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr suggestions failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/suggestions.html.twig', [
            'movies'          => $movies,
            'qualityProfiles' => $qualityProfiles,
            'rootFolders'     => $rootFolders,
            'error'           => $error,
        ]);
    }

    // ── Library import ──────────────────────────────────────────────────────

    #[Route('/import-bibliotheque', name: 'library_import')]
    public function libraryImport(): Response
    {
        $rootFolders     = [];
        $qualityProfiles = [];
        $error           = false;
        try {
            $rootFolders     = $this->radarr->getRootFolders();
            $qualityProfiles = $this->radarr->getQualityProfiles();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr libraryImport failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/library_import.html.twig', [
            'rootFolders'     => $rootFolders,
            'qualityProfiles' => $qualityProfiles,
            'error'           => $error,
        ]);
    }

    #[Route('/import-bibliotheque/scan', name: 'library_import_scan', methods: ['POST'])]
    public function libraryImportScan(Request $request): JsonResponse
    {
        try {
            $folder = rtrim($request->toArray()['folder'] ?? '', '/') . '/';

            // List subdirectories
            $fs = $this->radarr->getFilesystem($folder, false);
            $dirs = $fs['directories'] ?? [];

            // Get existing movie paths
            $movies = $this->radarr->getMovies();
            $existingPaths = [];
            foreach ($movies as $m) {
                $existingPaths[rtrim($m['path'] ?? '', '/')] = true;
            }

            // Filter unmatched folders
            $unmatched = [];
            foreach ($dirs as $d) {
                $dirPath = rtrim($d['path'] ?? '', '/');
                if (!isset($existingPaths[$dirPath])) {
                    $unmatched[] = [
                        'name' => $d['name'] ?? '',
                        'path' => $dirPath,
                        'size' => $d['size'] ?? 0,
                    ];
                }
            }

            return $this->json(['ok' => true, 'folders' => $unmatched]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr libraryImportScan failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Renaming ─────────────────────────────────────────────────────────────

    #[Route('/renommer', name: 'rename')]
    public function rename(): Response
    {
        $movies = [];
        $error  = false;
        try {
            $allMovies = $this->radarr->getMovies();
            // Just title + id for the select
            $movies = array_map(fn($m) => ['id' => $m['id'], 'title' => $m['title'], 'year' => $m['year']], $allMovies);
            usort($movies, fn($a, $b) => strcmp($a['title'] ?? '', $b['title'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr rename failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/rename.html.twig', ['movies' => $movies, 'error' => $error]);
    }

    #[Route('/renommer/{movieId}/propositions', name: 'rename_proposals', methods: ['GET'])]
    public function renameProposals(int $movieId): JsonResponse
    {
        try {
            return $this->json($this->radarr->getRenameProposals($movieId));
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr renameProposals failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/renommer/{movieId}/executer', name: 'rename_execute', methods: ['POST'])]
    public function renameExecute(int $movieId): JsonResponse
    {
        try {
            $cmdId = $this->radarr->executeRename($movieId);
            return $this->json(['ok' => true, 'cmdId' => $cmdId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr renameExecute failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Quality profiles ──────────────────────────────────────────────────────

    #[Route('/qualite', name: 'quality')]
    public function quality(): Response
    {
        $profiles      = [];
        $definitions   = [];
        $customFormats = [];
        $languages     = [];
        $limits        = null;
        $error         = false;
        try {
            $profiles      = $this->radarr->getQualityProfiles();
            $definitions   = $this->radarr->getQualityDefinitions();
            $customFormats = $this->radarr->getCustomFormats();
            $languages     = $this->radarr->getLanguages();
            $limits        = $this->radarr->getQualityDefinitionLimits();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr quality failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/quality.html.twig', [
            'profiles'      => $profiles,
            'definitions'   => $definitions,
            'customFormats' => $customFormats,
            'languages'     => $languages,
            'limits'        => $limits,
            'error'         => $error,
        ]);
    }

    #[Route('/qualite/profils/ajouter', name: 'quality_profile_create', methods: ['POST'])]
    public function qualityProfileCreate(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createQualityProfileWithError($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr qualityProfileCreate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/qualite/profils/{id}/modifier', name: 'quality_profile_update', methods: ['POST'])]
    public function qualityProfileUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateQualityProfileWithError($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr qualityProfileUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/qualite/profils/{id}/supprimer', name: 'quality_profile_delete', methods: ['POST'])]
    public function qualityProfileDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteQualityProfile($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr qualityProfileDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/qualite/definitions/sauvegarder', name: 'quality_definitions_save', methods: ['POST'])]
    public function qualityDefinitionsSave(Request $request): JsonResponse
    {
        try {
            $definitions = $request->toArray();
            $ok = $this->radarr->bulkUpdateQualityDefinitions($definitions);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr qualityDefinitionsSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/qualite/definitions/limites', name: 'quality_definitions_limits', methods: ['GET'])]
    public function qualityDefinitionsLimits(): JsonResponse
    {
        $limits = $this->radarr->getQualityDefinitionLimits();
        return $this->json($limits ?? []);
    }

    // ── Delay profiles ────────────────────────────────────────────────────────

    #[Route('/profils-delai', name: 'delay_profiles')]
    public function delayProfiles(): Response
    {
        $profiles = [];
        $error    = false;
        try {
            $profiles = $this->radarr->getDelayProfiles();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr delayProfiles failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/delay_profiles.html.twig', ['profiles' => $profiles, 'error' => $error]);
    }

    #[Route('/profils-delai/{id}/supprimer', name: 'delay_profile_delete', methods: ['POST'])]
    public function delayProfileDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteDelayProfile($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr delayProfileDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/profils-delai/ajouter', name: 'delay_profile_add', methods: ['POST'])]
    public function delayProfileAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createDelayProfile($request->toArray());
            return $this->json(['ok' => $result !== null, 'profile' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr delayProfileAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/profils-delai/{id}/modifier', name: 'delay_profile_update', methods: ['POST'])]
    public function delayProfileUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateDelayProfile($id, $request->toArray());
            return $this->json(['ok' => $result !== null, 'profile' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr delayProfileUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Formats custom ────────────────────────────────────────────────────────

    #[Route('/formats', name: 'custom_formats')]
    public function customFormats(): Response
    {
        $formats = [];
        $error   = false;
        try {
            $formats = $this->radarr->getCustomFormats();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFormats failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/custom_formats.html.twig', ['formats' => $formats, 'error' => $error]);
    }

    #[Route('/formats/schema', name: 'custom_format_schema', methods: ['GET'])]
    public function customFormatSchema(): JsonResponse
    {
        try {
            return $this->json($this->radarr->getCustomFormatSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFormatSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/formats/ajouter', name: 'custom_format_add', methods: ['POST'])]
    public function customFormatAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createCustomFormat($request->toArray());
            return $this->json(['ok' => $result !== null, 'format' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFormatAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/formats/{id}/modifier', name: 'custom_format_update', methods: ['POST'])]
    public function customFormatUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateCustomFormat($id, $request->toArray());
            return $this->json(['ok' => $result !== null, 'format' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFormatUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/formats/{id}/supprimer', name: 'custom_format_delete', methods: ['POST'])]
    public function customFormatDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteCustomFormat($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFormatDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Auto-Tagging ──────────────────────────────────────────────────────────

    #[Route('/auto-tags', name: 'auto_tags')]
    public function autoTags(): Response
    {
        $tags  = [];
        $error = false;
        try {
            $tags = $this->radarr->getAutoTags();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr autoTags failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/auto_tags.html.twig', ['tags' => $tags, 'error' => $error]);
    }

    #[Route('/auto-tags/{id}/supprimer', name: 'auto_tag_delete', methods: ['POST'])]
    public function autoTagDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteAutoTag($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr autoTagDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/auto-tags/ajouter', name: 'auto_tag_add', methods: ['POST'])]
    public function autoTagAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createAutoTag($request->toArray());
            return $this->json(['ok' => $result !== null, 'autoTag' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr autoTagAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/auto-tags/{id}/modifier', name: 'auto_tag_update', methods: ['POST'])]
    public function autoTagUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateAutoTag($id, $request->toArray());
            return $this->json(['ok' => $result !== null, 'autoTag' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr autoTagUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    #[Route('/tags', name: 'tags')]
    public function tags(): Response
    {
        $tags  = [];
        $error = false;
        try {
            $tags = $this->radarr->getTags();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr tags failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/tags.html.twig', ['tags' => $tags, 'error' => $error]);
    }

    #[Route('/tags/ajouter', name: 'tag_add', methods: ['POST'])]
    public function tagAdd(Request $request): JsonResponse
    {
        try {
            $label = $request->toArray()['label'] ?? '';
            $tag   = $this->radarr->createTag($label);
            return $this->json(['ok' => $tag !== null, 'tag' => $tag]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr tagAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/tags/{id}/supprimer', name: 'tag_delete', methods: ['POST'])]
    public function tagDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteTag($id);
            if (!$ok) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.tag_still_used')]);
            }
            return $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr tagDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $msg = $e->getMessage();
            if (str_contains($msg, 'still in use') || str_contains($msg, 'cannot be deleted')) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.tag_still_used')]);
            }
            return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.delete_error')]);
        }
    }

    #[Route('/tags/{id}/renommer', name: 'tag_rename', methods: ['POST'])]
    public function tagRename(int $id, Request $request): JsonResponse
    {
        try {
            $label = $request->toArray()['label'] ?? '';
            if ($label === '') {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.label_required')], 400);
            }
            $tag = $this->radarr->updateTag($id, $label);
            return $this->json(['ok' => $tag !== null, 'tag' => $tag]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr tagRename failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    #[Route('/parametres', name: 'settings')]
    public function settings(): Response
    {
        $hostConfig          = null;
        $indexerConfig       = null;
        $downloadClientConfig = null;
        $importListConfig    = null;
        $error               = false;
        try {
            $hostConfig           = $this->radarr->getHostConfig();
            $indexerConfig        = $this->radarr->getIndexerConfig();
            $downloadClientConfig = $this->radarr->getDownloadClientConfig();
            $importListConfig     = $this->radarr->getImportListConfig();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr settings failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/settings.html.twig', [
            'hostConfig'           => $hostConfig,
            'indexerConfig'        => $indexerConfig,
            'downloadClientConfig' => $downloadClientConfig,
            'importListConfig'     => $importListConfig,
            'error'                => $error,
        ]);
    }

    #[Route('/parametres/host/sauvegarder', name: 'settings_host_save', methods: ['POST'])]
    public function settingsHostSave(Request $request): JsonResponse
    {
        try {
            $current = $this->radarr->getHostConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $config = $this->radarr->updateHostConfig($merged);
            return $this->json(['ok' => $config !== null, 'config' => $config]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr settingsHostSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── UI ───────────────────────────────────────────────────────────────────

    #[Route('/ui', name: 'ui')]
    public function ui(): Response
    {
        $uiConfig = null;
        $error    = false;
        try {
            $uiConfig  = $this->radarr->getUiConfig();
            $languages = $this->radarr->getLanguages();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr ui failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/ui.html.twig', [
            'uiConfig'  => $uiConfig,
            'languages' => $languages ?? [],
            'error'     => $error,
        ]);
    }

    #[Route('/ui/sauvegarder', name: 'ui_save', methods: ['POST'])]
    public function uiSave(Request $request): JsonResponse
    {
        try {
            $current = $this->radarr->getUiConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $result = $this->radarr->updateUiConfig($merged);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr uiSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Indexers (Radarr native) ─────────────────────────────────────────────

    #[Route('/indexeurs', name: 'indexers')]
    public function indexers(): Response
    {
        $indexers = [];
        $error    = false;
        try {
            $indexers = $this->radarr->getRadarrIndexers();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr indexers failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/indexers.html.twig', ['indexers' => $indexers, 'error' => $error]);
    }

    #[Route('/indexeurs/schema', name: 'indexers_schema', methods: ['GET'])]
    public function indexersSchema(): JsonResponse
    {
        try {
            return $this->json($this->radarr->getIndexerSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr indexersSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/indexeurs/ajouter', name: 'indexer_add', methods: ['POST'])]
    public function indexerAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createIndexer($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr indexerAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/indexeurs/{id}/modifier', name: 'indexer_update', methods: ['POST'])]
    public function indexerUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateIndexer($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr indexerUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/indexeurs/{id}/supprimer', name: 'indexer_delete', methods: ['POST'])]
    public function indexerDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteIndexer($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr indexerDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/indexeurs/tester', name: 'indexer_test', methods: ['POST'])]
    public function indexerTest(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->testIndexer($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr indexerTest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Download clients ──────────────────────────────────────────────────────

    #[Route('/clients-telechargement', name: 'download_clients')]
    public function downloadClients(): Response
    {
        $clients = [];
        $error   = false;
        try {
            $clients = $this->radarr->getDownloadClients();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr downloadClients failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/download_clients.html.twig', ['clients' => $clients, 'error' => $error]);
    }

    #[Route('/clients-telechargement/schema', name: 'download_client_schema', methods: ['GET'])]
    public function downloadClientSchema(): JsonResponse
    {
        try {
            return $this->json($this->radarr->getDownloadClientSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr downloadClientSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/clients-telechargement/ajouter', name: 'download_client_add', methods: ['POST'])]
    public function downloadClientAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createDownloadClient($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr downloadClientAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/clients-telechargement/{id}/modifier', name: 'download_client_update', methods: ['POST'])]
    public function downloadClientUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateDownloadClient($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr downloadClientUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/clients-telechargement/{id}/supprimer', name: 'download_client_delete', methods: ['POST'])]
    public function downloadClientDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteDownloadClient($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr downloadClientDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/clients-telechargement/tester', name: 'download_client_test', methods: ['POST'])]
    public function downloadClientTest(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->testDownloadClient($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr downloadClientTest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Import lists ───────────────────────────────────────────────────────

    #[Route('/listes-import', name: 'import_lists')]
    public function importLists(): Response
    {
        $lists = [];
        $error = false;
        try {
            $lists = $this->radarr->getImportLists();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr importLists failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/import_lists.html.twig', ['lists' => $lists, 'error' => $error]);
    }

    #[Route('/listes-import/schema', name: 'import_list_schema', methods: ['GET'])]
    public function importListSchema(): JsonResponse
    {
        try {
            return $this->json($this->radarr->getImportListSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr importListSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/listes-import/ajouter', name: 'import_list_add', methods: ['POST'])]
    public function importListAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createImportList($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr importListAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/listes-import/{id}/modifier', name: 'import_list_update', methods: ['POST'])]
    public function importListUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateImportList($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr importListUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/listes-import/{id}/supprimer', name: 'import_list_delete', methods: ['POST'])]
    public function importListDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteImportList($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr importListDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Commands (global monitoring) ─────────────────────────────────────────

    #[Route('/commandes', name: 'commands')]
    public function commands(): Response
    {
        $commands = [];
        $error    = false;
        try {
            $commands = $this->radarr->getAllCommands();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr commands failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/commands.html.twig', ['commands' => $commands, 'error' => $error]);
    }

    #[Route('/command/{cmdId}/status', name: 'command_status', methods: ['GET'])]
    public function commandStatus(int $cmdId): JsonResponse
    {
        try {
            return $this->json($this->radarr->getCommandStatus($cmdId) ?? ['status' => 'unknown']);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr commandStatus failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['status' => 'unknown']);
        }
    }

    #[Route('/command/{cmdId}/annuler', name: 'command_cancel', methods: ['POST'])]
    public function commandCancel(int $cmdId): JsonResponse
    {
        try {
            $ok = $this->radarr->cancelCommand($cmdId);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr commandCancel failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── System tasks ──────────────────────────────────────────────────────────

    #[Route('/taches', name: 'tasks')]
    public function tasks(): Response
    {
        $tasks = [];
        $error = false;
        try {
            $tasks = $this->radarr->getSystemTasks();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr tasks failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/tasks.html.twig', ['tasks' => $tasks, 'error' => $error]);
    }

    #[Route('/taches/executer', name: 'task_run', methods: ['POST'])]
    public function taskRun(Request $request): JsonResponse
    {
        try {
            $commandName = $request->toArray()['commandName'] ?? '';
            if ($commandName === '') {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.command_name_required')], 400);
            }
            $result = $this->radarr->sendCommand($commandName);
            return $this->json(['ok' => $result !== null, 'command' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr taskRun failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Logs ─────────────────────────────────────────────────────────────────

    #[Route('/logs', name: 'logs')]
    public function logs(Request $request): Response
    {
        $page     = $request->query->getInt('page', 1);
        $pageSize = 100;
        $files    = [];
        $logs     = [];
        $total    = 0;
        $error    = false;
        try {
            $files    = $this->radarr->getLogFiles();
            $logsData = $this->radarr->getLogs($page, $pageSize);
            $logs     = $logsData['records'] ?? $logsData;
            $total    = $logsData['totalRecords'] ?? count($logs);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr logs failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/logs.html.twig', [
            'files'    => $files,
            'logs'     => $logs,
            'page'     => $page,
            'pageSize' => $pageSize,
            'total'    => $total,
            'error'    => $error,
        ]);
    }

    // ── Movie stats ───────────────────────────────────────────────────────────

    #[Route('/stats', name: 'stats')]
    public function stats(): Response
    {
        $error = false;
        $stats = [];
        try {
            $movies   = $this->radarr->getMovies();
            $disk     = $this->radarr->getDiskSpace();
            $missing  = $this->radarr->getMissing(1, 1);

            $total      = count($movies);
            $hasFile    = 0;
            $monitored  = 0;
            $sizeOnDisk = 0;
            $qualities  = [];
            $genres     = [];
            $years      = [];

            foreach ($movies as $m) {
                if ($m['hasFile'] ?? false) $hasFile++;
                if ($m['monitored'] ?? false) $monitored++;
                $size = $m['sizeOnDisk'] ?? 0;
                $sizeOnDisk += $size;

                // Quality breakdown
                $qName = $m['quality'] ?? null;
                if ($qName) {
                    if (!isset($qualities[$qName])) $qualities[$qName] = ['count' => 0, 'size' => 0];
                    $qualities[$qName]['count']++;
                    $qualities[$qName]['size'] += $size;
                }

                // Genre breakdown
                foreach (($m['genres'] ?? []) as $g) {
                    $genres[$g] = ($genres[$g] ?? 0) + 1;
                }

                // Year breakdown
                $y = $m['year'] ?? 0;
                if ($y > 0) {
                    $decade = (string)(intdiv($y, 10) * 10) . 's';
                    $years[$decade] = ($years[$decade] ?? 0) + 1;
                }
            }

            // Sort qualities by count desc
            arsort($qualities);
            arsort($genres);
            ksort($years);

            $stats = [
                'total'      => $total,
                'hasFile'    => $hasFile,
                'monitored'  => $monitored,
                'missing'    => $missing['totalRecords'] ?? ($total - $hasFile),
                'sizeOnDisk' => $sizeOnDisk,
                'disk'       => $disk,
                'qualities'  => $qualities,
                'genres'     => $genres,
                'years'      => $years,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr stats failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/stats.html.twig', ['stats' => $stats, 'error' => $error]);
    }

    // ── Title parser ──────────────────────────────────────────────────────────

    #[Route('/parser', name: 'parse')]
    public function parse(Request $request): Response
    {
        $title  = $request->query->getString('title', '');
        $result = null;
        $error  = false;
        if ($title !== '') {
            try {
                $result = $this->radarr->parseTitle($title);
            } catch (\Throwable $e) {
                $this->logger->warning('Radarr parse failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                $error = true;
            }
        }
        return $this->render('radarr/parse.html.twig', ['title' => $title, 'result' => $result, 'error' => $error]);
    }

    // ── Root folders ──────────────────────────────────────────────────────────

    #[Route('/dossiers-racine', name: 'root_folders')]
    public function rootFolders(): Response
    {
        $folders = [];
        $error   = false;
        try {
            $folders = $this->radarr->getRootFolders();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr rootFolders failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/root_folders.html.twig', ['folders' => $folders, 'error' => $error]);
    }

    #[Route('/dossiers-racine/ajouter', name: 'root_folder_add', methods: ['POST'])]
    public function rootFolderAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->addRootFolder($request->toArray()['path'] ?? '');
            return $this->json(['ok' => $result !== null, 'folder' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr rootFolderAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/dossiers-racine/{id}/supprimer', name: 'root_folder_delete', methods: ['POST'])]
    public function rootFolderDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteRootFolder($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr rootFolderDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Media management ────────────────────────────────────────────────

    #[Route('/gestion-medias', name: 'media_management')]
    public function mediaManagement(): Response
    {
        $namingConfig = null;
        $mmConfig     = null;
        $error        = false;
        try {
            $namingConfig = $this->radarr->getNamingConfig();
            $mmConfig     = $this->radarr->getMediaManagementConfig();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr mediaManagement failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/media_management.html.twig', [
            'namingConfig' => $namingConfig,
            'mmConfig'     => $mmConfig,
            'error'        => $error,
        ]);
    }

    #[Route('/gestion-medias/naming', name: 'media_management_naming_save', methods: ['POST'])]
    public function mediaManagementNamingSave(Request $request): JsonResponse
    {
        try {
            $current = $this->radarr->getNamingConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $result = $this->radarr->updateNamingConfig($merged);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr mediaManagementNamingSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/gestion-medias/mediamanagement', name: 'media_management_mm_save', methods: ['POST'])]
    public function mediaManagementMmSave(Request $request): JsonResponse
    {
        try {
            $current = $this->radarr->getMediaManagementConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $result = $this->radarr->updateMediaManagementConfig($merged);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr mediaManagementMmSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/gestion-medias/naming/examples', name: 'media_management_naming_examples', methods: ['GET'])]
    public function mediaManagementNamingExamples(): JsonResponse
    {
        $examples = $this->radarr->getNamingExamples();
        return $this->json($examples ?? []);
    }

    // ── Bulk films ────────────────────────────────────────────────────────────

    #[Route('/films/bulk-update', name: 'movies_bulk_update', methods: ['POST'])]
    public function moviesBulkUpdate(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
            $ids  = $data['movieIds'] ?? [];
            unset($data['movieIds']);
            $ok = $this->radarr->bulkUpdateMovies($ids, $data);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr moviesBulkUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Remote Path Mappings ──────────────────────────────────────────────────

    #[Route('/chemins-distants', name: 'remote_path_mappings')]
    public function remotePathMappings(): Response
    {
        $mappings = [];
        $error    = false;
        try {
            $mappings = $this->radarr->getRemotePathMappings();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr remotePathMappings failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/remote_path_mappings.html.twig', ['mappings' => $mappings, 'error' => $error]);
    }

    #[Route('/chemins-distants/ajouter', name: 'remote_path_mapping_add', methods: ['POST'])]
    public function remotePathMappingAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createRemotePathMapping($request->toArray());
            return $this->json(['ok' => $result !== null, 'mapping' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr remotePathMappingAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/chemins-distants/{id}/modifier', name: 'remote_path_mapping_update', methods: ['POST'])]
    public function remotePathMappingUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $data   = $request->toArray();
            $result = $this->radarr->updateRemotePathMapping($id, $data);
            return $this->json(['ok' => $result !== null, 'mapping' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr remotePathMappingUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/chemins-distants/{id}/supprimer', name: 'remote_path_mapping_delete', methods: ['POST'])]
    public function remotePathMappingDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteRemotePathMapping($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr remotePathMappingDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    #[Route('/metadata', name: 'metadata')]
    public function metadata(): Response
    {
        $metadata = [];
        $error    = false;
        try {
            $metadata = $this->radarr->getMetadata();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr metadata failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/metadata.html.twig', ['metadata' => $metadata, 'error' => $error]);
    }

    #[Route('/metadata/{id}/modifier', name: 'metadata_update', methods: ['POST'])]
    public function metadataUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateMetadata($id, $request->toArray());
            return $this->json(['ok' => $result !== null, 'metadata' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr metadataUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/metadata/{id}/supprimer', name: 'metadata_delete', methods: ['POST'])]
    public function metadataDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteMetadata($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr metadataDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/metadata/schema', name: 'metadata_schema', methods: ['GET'])]
    public function metadataSchema(): JsonResponse
    {
        return $this->json($this->radarr->getMetadataSchema());
    }

    #[Route('/metadata/config', name: 'metadata_config', methods: ['GET'])]
    public function metadataConfig(): JsonResponse
    {
        return $this->json($this->radarr->getMetadataConfig() ?? []);
    }

    #[Route('/metadata/config/sauvegarder', name: 'metadata_config_save', methods: ['POST'])]
    public function metadataConfigSave(Request $request): JsonResponse
    {
        try {
            $current = $this->radarr->getMetadataConfig();
            if ($current === null) return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            $merged = array_merge($current, $request->toArray());
            return $this->json($this->radarr->updateMetadataConfig($merged));
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr metadataConfigSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    // ── Custom Filters ────────────────────────────────────────────────────────

    #[Route('/filtres-personnalises', name: 'custom_filters')]
    public function customFilters(): Response
    {
        $filters = [];
        $error   = false;
        try {
            $filters = $this->radarr->getCustomFilters();
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFilters failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('radarr/custom_filters.html.twig', ['filters' => $filters, 'error' => $error]);
    }

    #[Route('/filtres-personnalises/ajouter', name: 'custom_filter_add', methods: ['POST'])]
    public function customFilterAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->createCustomFilter($request->toArray());
            return $this->json(['ok' => $result !== null, 'filter' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFilterAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/filtres-personnalises/{id}/modifier', name: 'custom_filter_update', methods: ['POST'])]
    public function customFilterUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->radarr->updateCustomFilter($id, $request->toArray());
            return $this->json(['ok' => $result !== null, 'filter' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFilterUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }

    #[Route('/filtres-personnalises/{id}/supprimer', name: 'custom_filter_delete', methods: ['POST'])]
    public function customFilterDelete(int $id): JsonResponse
    {
        try {
            $ok = $this->radarr->deleteCustomFilter($id);
            return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Radarr', $this->radarr);
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr customFilterDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->jsonClientError('Radarr', $this->radarr, $e->getMessage());
        }
    }
}
