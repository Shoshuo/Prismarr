<?php

namespace App\Controller;

use App\Service\Media\SonarrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/sonarr', name: 'sonarr_')]
class SonarrController extends AbstractController
{
    public function __construct(
        private readonly SonarrClient $sonarr,
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
            $updates = $this->sonarr->getUpdates();
            $status = $this->sonarr->getSystemStatus();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr updates failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/updates.html.twig', ['updates' => $updates, 'status' => $status, 'error' => $error]);
    }

    #[Route('/mises-a-jour/installer', name: 'install_update', methods: ['POST'])]
    public function installUpdate(): JsonResponse
    {
        try {
            $cmdId = $this->sonarr->installUpdate();
            return $this->json(['ok' => true, 'cmdId' => $cmdId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr installUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Backups ───────────────────────────────────────────────────────────

    #[Route('/sauvegardes', name: 'backups')]
    public function backups(): Response
    {
        $backups = [];
        $error   = false;
        try {
            $backups = $this->sonarr->getBackups();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr backups failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/backups.html.twig', ['backups' => $backups, 'error' => $error]);
    }

    #[Route('/sauvegardes/creer', name: 'backup_create', methods: ['POST'])]
    public function backupCreate(): JsonResponse
    {
        try {
            $cmdId = $this->sonarr->createBackup();
            return $this->json(['ok' => true, 'cmdId' => $cmdId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr backupCreate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/sauvegardes/{id}/supprimer', name: 'backup_delete', methods: ['POST'])]
    public function backupDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteBackup($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr backupDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/sauvegardes/{id}/restaurer', name: 'backup_restore', methods: ['POST'])]
    public function backupRestore(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->restoreBackup($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr backupRestore failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    #[Route('/notifications', name: 'notifications')]
    public function notifications(): Response
    {
        $notifications = [];
        $error         = false;
        try {
            $notifications = $this->sonarr->getNotifications();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notifications failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/notifications.html.twig', ['notifications' => $notifications, 'error' => $error]);
    }

    #[Route('/notifications/{id}/supprimer', name: 'notification_delete', methods: ['POST'])]
    public function notificationDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteNotification($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notificationDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/notifications/{id}/tester', name: 'notification_test', methods: ['POST'])]
    public function notificationTest(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->testNotification($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notificationTest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/notifications/schema', name: 'notifications_schema', methods: ['GET'])]
    public function notificationsSchema(): JsonResponse
    {
        try {
            return $this->json($this->sonarr->getNotificationSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notificationsSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/notifications/ajouter', name: 'notification_add', methods: ['POST'])]
    public function notificationAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createNotification($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notificationAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/notifications/{id}/modifier', name: 'notification_update', methods: ['POST'])]
    public function notificationUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateNotification($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notificationUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/notifications/tester-payload', name: 'notification_test_payload', methods: ['POST'])]
    public function notificationTestPayload(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->testNotificationPayload($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr notificationTestPayload failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
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
            $exclusions = $this->sonarr->getImportListExclusionsPaged($page, 50);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr exclusions failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/exclusions.html.twig', [
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
            $exclusion = $this->sonarr->createImportListExclusion($data);
            return $this->json(['ok' => ($exclusion['ok'] ?? false), 'exclusion' => $exclusion]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr exclusionAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/exclusions/{id}/supprimer', name: 'exclusion_delete', methods: ['POST'])]
    public function exclusionDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteImportListExclusion($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr exclusionDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/exclusions/bulk-supprimer', name: 'exclusion_bulk_delete', methods: ['POST'])]
    public function exclusionBulkDelete(Request $request): JsonResponse
    {
        try {
            $ids = $request->toArray()['ids'] ?? [];
            return $this->json(['ok' => $this->sonarr->bulkDeleteImportListExclusions($ids)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr exclusionBulkDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Import List Series (suggestions) ──────────────────────────────────────

    #[Route('/suggestions', name: 'suggestions')]
    #[Route('/import-bibliotheque', name: 'sonarr_library_import')]
    public function libraryImport(): Response
    {
        $rootFolders = [];
        $qualityProfiles = [];
        $error = false;
        try {
            $rootFolders = $this->sonarr->getRootFolders();
            $qualityProfiles = $this->sonarr->getQualityProfiles();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr libraryImport failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/library_import.html.twig', [
            'rootFolders' => $rootFolders,
            'qualityProfiles' => $qualityProfiles,
            'error' => $error,
        ]);
    }

    #[Route('/import-bibliotheque/scan', name: 'sonarr_library_import_scan', methods: ['POST'])]
    public function libraryImportScan(Request $request): JsonResponse
    {
        try {
            $folder = rtrim($request->toArray()['folder'] ?? '', '/') . '/';
            $fs = $this->sonarr->getFilesystem($folder, false);
            $dirs = $fs['directories'] ?? [];

            $series = $this->sonarr->getSeries();
            $existingPaths = [];
            foreach ($series as $s) {
                $existingPaths[rtrim($s['path'] ?? '', '/')] = true;
            }

            $unmatched = [];
            foreach ($dirs as $d) {
                $dirPath = rtrim($d['path'] ?? '', '/');
                if (!isset($existingPaths[$dirPath])) {
                    $unmatched[] = [
                        'name' => $d['name'] ?? '',
                        'path' => $dirPath,
                    ];
                }
            }

            return $this->json(['ok' => true, 'folders' => $unmatched]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr libraryImportScan failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
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
        $error         = false;
        $limits = null;
        try {
            $profiles      = $this->sonarr->getQualityProfiles();
            $definitions   = $this->sonarr->getQualityDefinitions();
            $customFormats = $this->sonarr->getCustomFormats();
            $languages     = $this->sonarr->getLanguages();
            $limits        = $this->sonarr->getQualityDefinitionLimits();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr quality failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/quality.html.twig', [
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
            $result = $this->sonarr->createQualityProfile($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr qualityProfileCreate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/qualite/profils/{id}/modifier', name: 'quality_profile_update', methods: ['POST'])]
    public function qualityProfileUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateQualityProfile($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr qualityProfileUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/qualite/profils/{id}/supprimer', name: 'quality_profile_delete', methods: ['POST'])]
    public function qualityProfileDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteQualityProfile($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr qualityProfileDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/qualite/definitions/sauvegarder', name: 'quality_definitions_save', methods: ['POST'])]
    public function qualityDefinitionsSave(Request $request): JsonResponse
    {
        try {
            $definitions = $request->toArray();
            return $this->json(['ok' => $this->sonarr->bulkUpdateQualityDefinitions($definitions)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr qualityDefinitionsSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/qualite/definitions/limites', name: 'quality_definitions_limits', methods: ['GET'])]
    public function qualityDefinitionsLimits(): JsonResponse
    {
        $limits = $this->sonarr->getQualityDefinitionLimits();
        return $this->json($limits ?? []);
    }

    // ── Delay profiles ────────────────────────────────────────────────────────

    #[Route('/profils-delai', name: 'delay_profiles')]
    public function delayProfiles(): Response
    {
        $profiles = [];
        $error    = false;
        try {
            $profiles = $this->sonarr->getDelayProfiles();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr delayProfiles failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/delay_profiles.html.twig', ['profiles' => $profiles, 'error' => $error]);
    }

    #[Route('/profils-delai/{id}/supprimer', name: 'delay_profile_delete', methods: ['POST'])]
    public function delayProfileDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteDelayProfile($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr delayProfileDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/profils-delai/ajouter', name: 'delay_profile_add', methods: ['POST'])]
    public function delayProfileAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createDelayProfile($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr delayProfileAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/profils-delai/{id}/modifier', name: 'delay_profile_update', methods: ['POST'])]
    public function delayProfileUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateDelayProfile($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr delayProfileUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Formats custom ────────────────────────────────────────────────────────

    #[Route('/formats', name: 'custom_formats')]
    public function customFormats(): Response
    {
        $formats = [];
        $error   = false;
        try {
            $formats = $this->sonarr->getCustomFormats();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFormats failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/custom_formats.html.twig', ['formats' => $formats, 'error' => $error]);
    }

    #[Route('/formats/schema', name: 'custom_format_schema', methods: ['GET'])]
    public function customFormatSchema(): JsonResponse
    {
        try {
            return $this->json($this->sonarr->getCustomFormatSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFormatSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/formats/ajouter', name: 'custom_format_add', methods: ['POST'])]
    public function customFormatAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createCustomFormat($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFormatAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/formats/{id}/modifier', name: 'custom_format_update', methods: ['POST'])]
    public function customFormatUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateCustomFormat($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFormatUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/formats/{id}/supprimer', name: 'custom_format_delete', methods: ['POST'])]
    public function customFormatDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteCustomFormat($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFormatDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Auto-Tagging ──────────────────────────────────────────────────────────

    #[Route('/auto-tags', name: 'auto_tags')]
    public function autoTags(): Response
    {
        $tags  = [];
        $error = false;
        try {
            $tags = $this->sonarr->getAutoTags();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr autoTags failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/auto_tags.html.twig', ['tags' => $tags, 'error' => $error]);
    }

    #[Route('/auto-tags/{id}/supprimer', name: 'auto_tag_delete', methods: ['POST'])]
    public function autoTagDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteAutoTag($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr autoTagDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/auto-tags/ajouter', name: 'auto_tag_add', methods: ['POST'])]
    public function autoTagAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createAutoTag($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr autoTagAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/auto-tags/{id}/modifier', name: 'auto_tag_update', methods: ['POST'])]
    public function autoTagUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateAutoTag($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr autoTagUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    #[Route('/tags', name: 'tags')]
    public function tags(): Response
    {
        $tags  = [];
        $error = false;
        try {
            $tags = $this->sonarr->getTags();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr tags failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/tags.html.twig', ['tags' => $tags, 'error' => $error]);
    }

    #[Route('/tags/ajouter', name: 'tag_add', methods: ['POST'])]
    public function tagAdd(Request $request): JsonResponse
    {
        try {
            $label = $request->toArray()['label'] ?? '';
            $tag   = $this->sonarr->createTag(['label' => $label]);
            return $this->json($tag);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr tagAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/tags/{id}/supprimer', name: 'tag_delete', methods: ['POST'])]
    public function tagDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteTag($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr tagDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
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
            $tag = $this->sonarr->updateTag($id, ['id' => $id, 'label' => $label]);
            return $this->json($tag);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr tagRename failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    #[Route('/parametres', name: 'settings')]
    public function settings(): Response
    {
        $hostConfig           = null;
        $indexerConfig        = null;
        $downloadClientConfig = null;
        $importListConfig     = null;
        $error                = false;
        try {
            $hostConfig           = $this->sonarr->getHostConfig();
            $indexerConfig        = $this->sonarr->getIndexerConfig();
            $downloadClientConfig = $this->sonarr->getDownloadClientConfig();
            $importListConfig     = $this->sonarr->getImportListConfig();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr settings failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/settings.html.twig', [
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
            $current = $this->sonarr->getHostConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $config = $this->sonarr->updateHostConfig($merged);
            return $this->json(['ok' => $config !== null, 'config' => $config]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr settingsHostSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── UI ───────────────────────────────────────────────────────────────────

    #[Route('/ui', name: 'ui')]
    public function ui(): Response
    {
        $uiConfig  = null;
        $languages = [];
        $error     = false;
        try {
            $uiConfig  = $this->sonarr->getUiConfig();
            $languages = $this->sonarr->getLanguages();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr ui failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/ui.html.twig', [
            'uiConfig'  => $uiConfig,
            'languages' => $languages,
            'error'     => $error,
        ]);
    }

    #[Route('/ui/sauvegarder', name: 'ui_save', methods: ['POST'])]
    public function uiSave(Request $request): JsonResponse
    {
        try {
            $current = $this->sonarr->getUiConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $result = $this->sonarr->updateUiConfig($merged);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr uiSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Indexers ─────────────────────────────────────────────────────────────

    #[Route('/indexeurs', name: 'indexers')]
    public function indexers(): Response
    {
        $indexers = [];
        $error    = false;
        try {
            $indexers = $this->sonarr->getIndexers();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr indexers failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/indexers.html.twig', ['indexers' => $indexers, 'error' => $error]);
    }

    #[Route('/indexeurs/schema', name: 'indexers_schema', methods: ['GET'])]
    public function indexersSchema(): JsonResponse
    {
        try {
            return $this->json($this->sonarr->getIndexerSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr indexersSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/indexeurs/ajouter', name: 'indexer_add', methods: ['POST'])]
    public function indexerAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createIndexer($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr indexerAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/indexeurs/{id}/modifier', name: 'indexer_update', methods: ['POST'])]
    public function indexerUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateIndexer($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr indexerUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/indexeurs/{id}/supprimer', name: 'indexer_delete', methods: ['POST'])]
    public function indexerDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteIndexer($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr indexerDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/indexeurs/tester', name: 'indexer_test', methods: ['POST'])]
    public function indexerTest(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->testIndexer($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr indexerTest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Download clients ──────────────────────────────────────────────────────

    #[Route('/clients-telechargement', name: 'download_clients')]
    public function downloadClients(): Response
    {
        $clients = [];
        $error   = false;
        try {
            $clients = $this->sonarr->getDownloadClients();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr downloadClients failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/download_clients.html.twig', ['clients' => $clients, 'error' => $error]);
    }

    #[Route('/clients-telechargement/schema', name: 'download_client_schema', methods: ['GET'])]
    public function downloadClientSchema(): JsonResponse
    {
        try {
            return $this->json($this->sonarr->getDownloadClientSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr downloadClientSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/clients-telechargement/ajouter', name: 'download_client_add', methods: ['POST'])]
    public function downloadClientAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createDownloadClient($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr downloadClientAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/clients-telechargement/{id}/modifier', name: 'download_client_update', methods: ['POST'])]
    public function downloadClientUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateDownloadClient($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr downloadClientUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/clients-telechargement/{id}/supprimer', name: 'download_client_delete', methods: ['POST'])]
    public function downloadClientDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteDownloadClient($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr downloadClientDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/clients-telechargement/tester', name: 'download_client_test', methods: ['POST'])]
    public function downloadClientTest(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->testDownloadClient($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr downloadClientTest failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Import lists ───────────────────────────────────────────────────────

    #[Route('/listes-import', name: 'import_lists')]
    public function importLists(): Response
    {
        $lists = [];
        $error = false;
        try {
            $lists = $this->sonarr->getImportLists();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr importLists failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/import_lists.html.twig', ['lists' => $lists, 'error' => $error]);
    }

    #[Route('/listes-import/schema', name: 'import_list_schema', methods: ['GET'])]
    public function importListSchema(): JsonResponse
    {
        try {
            return $this->json($this->sonarr->getImportListSchema());
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr importListSchema failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json([]);
        }
    }

    #[Route('/listes-import/ajouter', name: 'import_list_add', methods: ['POST'])]
    public function importListAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createImportList($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr importListAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/listes-import/{id}/modifier', name: 'import_list_update', methods: ['POST'])]
    public function importListUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateImportList($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr importListUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/listes-import/{id}/supprimer', name: 'import_list_delete', methods: ['POST'])]
    public function importListDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteImportList($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr importListDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Commands ─────────────────────────────────────────────────────────────

    #[Route('/commandes', name: 'commands')]
    public function commands(): Response
    {
        $commands = [];
        $error    = false;
        try {
            $commands = $this->sonarr->getCommands();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr commands failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/commands.html.twig', ['commands' => $commands, 'error' => $error]);
    }

    #[Route('/command/{cmdId}/status', name: 'command_status', methods: ['GET'])]
    public function commandStatus(int $cmdId): JsonResponse
    {
        try {
            return $this->json($this->sonarr->getCommandStatus($cmdId) ?? ['status' => 'unknown']);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr commandStatus failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['status' => 'unknown']);
        }
    }

    #[Route('/command/{cmdId}/annuler', name: 'command_cancel', methods: ['POST'])]
    public function commandCancel(int $cmdId): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->cancelCommand($cmdId)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr commandCancel failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── System tasks ──────────────────────────────────────────────────────────

    #[Route('/taches', name: 'tasks')]
    public function tasks(): Response
    {
        $tasks = [];
        $error = false;
        try {
            $tasks = $this->sonarr->getTasks();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr tasks failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/tasks.html.twig', ['tasks' => $tasks, 'error' => $error]);
    }

    #[Route('/taches/executer', name: 'task_run', methods: ['POST'])]
    public function taskRun(Request $request): JsonResponse
    {
        try {
            $commandName = $request->toArray()['commandName'] ?? '';
            if ($commandName === '') {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.command_name_required')], 400);
            }
            $result = $this->sonarr->sendCommand($commandName);
            return $this->json(['ok' => $result !== null, 'command' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr taskRun failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
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
            $files    = $this->sonarr->getLogFiles();
            $logsData = $this->sonarr->getLogs($page, $pageSize);
            $logs     = $logsData['records'] ?? $logsData;
            $total    = $logsData['totalRecords'] ?? count($logs);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr logs failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/logs.html.twig', [
            'files'    => $files,
            'logs'     => $logs,
            'page'     => $page,
            'pageSize' => $pageSize,
            'total'    => $total,
            'error'    => $error,
        ]);
    }

    // ── Series stats ──────────────────────────────────────────────────────────

    #[Route('/stats', name: 'stats')]
    public function stats(): Response
    {
        $error = false;
        $stats = [];
        try {
            $series  = $this->sonarr->getSeries();
            $disk    = $this->sonarr->getDiskSpace();

            $total      = count($series);
            $continuing = 0;
            $ended      = 0;
            $monitored  = 0;
            $sizeOnDisk = 0;
            $genres     = [];
            $networks   = [];

            foreach ($series as $s) {
                if ($s['status'] === 'continuing') $continuing++;
                if ($s['status'] === 'ended') $ended++;
                if ($s['monitored']) $monitored++;
                $sizeOnDisk += ($s['sizeGb'] ?? 0);

                foreach ($s['genres'] ?? [] as $g) {
                    $genres[$g] = ($genres[$g] ?? 0) + 1;
                }
                $net = $s['network'] ?? null;
                if ($net) $networks[$net] = ($networks[$net] ?? 0) + 1;
            }

            arsort($genres);
            arsort($networks);

            $stats = [
                'total'      => $total,
                'continuing' => $continuing,
                'ended'      => $ended,
                'monitored'  => $monitored,
                'sizeOnDisk' => $sizeOnDisk,
                'disk'       => $disk,
                'genres'     => $genres,
                'networks'   => $networks,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr stats failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/stats.html.twig', ['stats' => $stats, 'error' => $error]);
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
                $result = $this->sonarr->parseTitle($title);
            } catch (\Throwable $e) {
                $this->logger->warning('Sonarr parse failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                $error = true;
            }
        }
        return $this->render('sonarr/parse.html.twig', ['title' => $title, 'result' => $result, 'error' => $error]);
    }

    // ── Root folders ──────────────────────────────────────────────────────────

    #[Route('/dossiers-racine', name: 'root_folders')]
    public function rootFolders(): Response
    {
        $folders = [];
        $error   = false;
        try {
            $folders = $this->sonarr->getRootFolders();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr rootFolders failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/root_folders.html.twig', ['folders' => $folders, 'error' => $error]);
    }

    #[Route('/dossiers-racine/ajouter', name: 'root_folder_add', methods: ['POST'])]
    public function rootFolderAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->addRootFolder($request->toArray()['path'] ?? '');
            return $this->json(['ok' => $result !== null, 'folder' => $result]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr rootFolderAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    #[Route('/dossiers-racine/{id}/supprimer', name: 'root_folder_delete', methods: ['POST'])]
    public function rootFolderDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteRootFolder($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr rootFolderDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
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
            $namingConfig = $this->sonarr->getNamingConfig();
            $mmConfig     = $this->sonarr->getMediaManagementConfig();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr mediaManagement failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/media_management.html.twig', [
            'namingConfig' => $namingConfig,
            'mmConfig'     => $mmConfig,
            'error'        => $error,
        ]);
    }

    #[Route('/gestion-medias/naming', name: 'media_management_naming_save', methods: ['POST'])]
    public function mediaManagementNamingSave(Request $request): JsonResponse
    {
        try {
            $current = $this->sonarr->getNamingConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $result = $this->sonarr->updateNamingConfig($merged);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr mediaManagementNamingSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/gestion-medias/naming/examples', name: 'media_management_naming_examples', methods: ['GET'])]
    public function mediaManagementNamingExamples(): JsonResponse
    {
        $examples = $this->sonarr->getNamingExamples();
        return $this->json($examples ?? []);
    }

    #[Route('/gestion-medias/mediamanagement', name: 'media_management_mm_save', methods: ['POST'])]
    public function mediaManagementMmSave(Request $request): JsonResponse
    {
        try {
            $current = $this->sonarr->getMediaManagementConfig();
            if ($current === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('media.api.config_not_found')], 404);
            }
            $merged = array_merge($current, $request->toArray());
            $result = $this->sonarr->updateMediaManagementConfig($merged);
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr mediaManagementMmSave failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Remote Path Mappings ──────────────────────────────────────────────────

    #[Route('/chemins-distants', name: 'remote_path_mappings')]
    public function remotePathMappings(): Response
    {
        $mappings = [];
        $error    = false;
        try {
            $mappings = $this->sonarr->getRemotePathMappings();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr remotePathMappings failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/remote_path_mappings.html.twig', ['mappings' => $mappings, 'error' => $error]);
    }

    #[Route('/chemins-distants/ajouter', name: 'remote_path_mapping_add', methods: ['POST'])]
    public function remotePathMappingAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createRemotePathMapping($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr remotePathMappingAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/chemins-distants/{id}/modifier', name: 'remote_path_mapping_update', methods: ['POST'])]
    public function remotePathMappingUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateRemotePathMapping($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr remotePathMappingUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/chemins-distants/{id}/supprimer', name: 'remote_path_mapping_delete', methods: ['POST'])]
    public function remotePathMappingDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteRemotePathMapping($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr remotePathMappingDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    #[Route('/metadata', name: 'metadata')]
    public function metadata(): Response
    {
        $metadata = [];
        $error    = false;
        try {
            $metadata = $this->sonarr->getMetadata();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr metadata failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/metadata.html.twig', ['metadata' => $metadata, 'error' => $error]);
    }

    #[Route('/metadata/{id}/modifier', name: 'metadata_update', methods: ['POST'])]
    public function metadataUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateMetadata($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr metadataUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/metadata/{id}/supprimer', name: 'metadata_delete', methods: ['POST'])]
    public function metadataDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteMetadata($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr metadataDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Custom Filters ────────────────────────────────────────────────────────

    #[Route('/filtres-personnalises', name: 'custom_filters')]
    public function customFilters(): Response
    {
        $filters = [];
        $error   = false;
        try {
            $filters = $this->sonarr->getCustomFilters();
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFilters failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/custom_filters.html.twig', ['filters' => $filters, 'error' => $error]);
    }

    #[Route('/filtres-personnalises/ajouter', name: 'custom_filter_add', methods: ['POST'])]
    public function customFilterAdd(Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->createCustomFilter($request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFilterAdd failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/filtres-personnalises/{id}/modifier', name: 'custom_filter_update', methods: ['POST'])]
    public function customFilterUpdate(int $id, Request $request): JsonResponse
    {
        try {
            $result = $this->sonarr->updateCustomFilter($id, $request->toArray());
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFilterUpdate failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/filtres-personnalises/{id}/supprimer', name: 'custom_filter_delete', methods: ['POST'])]
    public function customFilterDelete(int $id): JsonResponse
    {
        try {
            return $this->json(['ok' => $this->sonarr->deleteCustomFilter($id)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr customFilterDelete failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false], 500);
        }
    }

    // ── Activity routes (history, blocklist) — referenced by nav ──────────────

    #[Route('/historique', name: 'history')]
    public function history(Request $request): Response
    {
        $page    = $request->query->getInt('page', 1);
        $history = [];
        $total   = 0;
        $error   = false;
        try {
            $data    = $this->sonarr->getHistory($page, 50);
            $history = $data['records'] ?? $data;
            $total   = $data['totalRecords'] ?? count($history);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr history failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/commands.html.twig', [
            'commands' => $history,
            'error'    => $error,
        ]);
    }

    #[Route('/blocklist', name: 'blocklist')]
    public function blocklist(Request $request): Response
    {
        $page      = $request->query->getInt('page', 1);
        $blocklist = [];
        $total     = 0;
        $error     = false;
        try {
            $data      = $this->sonarr->getBlocklist($page, 50);
            $blocklist = $data['records'] ?? $data;
            $total     = $data['totalRecords'] ?? count($blocklist);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr blocklist failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/commands.html.twig', [
            'commands' => $blocklist,
            'error'    => $error,
        ]);
    }

    // ── Wanted routes — referenced by nav ────────────────────────────────────

    #[Route('/manquants', name: 'missing')]
    public function missing(Request $request): Response
    {
        $page    = $request->query->getInt('page', 1);
        $missing = [];
        $total   = 0;
        $error   = false;
        try {
            $data    = $this->sonarr->getMissing($page, 50);
            $missing = $data['records'] ?? $data;
            $total   = $data['totalRecords'] ?? count($missing);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr missing failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/commands.html.twig', [
            'commands' => $missing,
            'error'    => $error,
        ]);
    }

    #[Route('/cutoff', name: 'cutoff')]
    public function cutoff(Request $request): Response
    {
        $page   = $request->query->getInt('page', 1);
        $cutoff = [];
        $total  = 0;
        $error  = false;
        try {
            $data   = $this->sonarr->getCutoff($page, 50);
            $cutoff = $data['records'] ?? $data;
            $total  = $data['totalRecords'] ?? count($cutoff);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr cutoff failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/commands.html.twig', [
            'commands' => $cutoff,
            'error'    => $error,
        ]);
    }

    // ── Calendar ──────────────────────────────────────────────────────────────

    #[Route('/calendrier', name: 'calendar')]
    public function calendar(): Response
    {
        $calendar = [];
        $error    = false;
        try {
            $calendar = $this->sonarr->getCalendar(30);
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr calendar failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
        return $this->render('sonarr/commands.html.twig', [
            'commands' => $calendar,
            'error'    => $error,
        ]);
    }
}
