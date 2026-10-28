<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Service\HealthCatalog;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(Connection $db): JsonResponse
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        try {
            $db->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'db' => 'unreachable', 'timestamp' => $timestamp],
                503
            );
        }

        return new JsonResponse(
            ['status' => 'ok', 'db' => 'ok', 'timestamp' => $timestamp],
            200
        );
    }

    /**
     * Per-service health snapshot for the topbar indicator. Returns a flat
     * `services` map (kept for back-compat with older JS — `bool|null` per
     * service) plus an `instances` map detailing each Radarr/Sonarr/
     * qBittorrent instance individually, so the topbar can render one row per
     * configured instance ("Radarr 4K — Up", "Radarr 1080p — Down")
     * instead of a single aggregate "Radarr" entry.
     *
     * Cached 10 s server-side via HealthService — polling every few
     * seconds is cheap.
     */
    #[Route('/api/health/services', name: 'api_health_services', methods: ['GET'])]
    public function servicesHealth(
        HealthService $health,
        ServiceInstanceProvider $instances,
    ): JsonResponse {
        $services = [];
        $instancesMap = ['radarr' => [], 'sonarr' => [], 'qbittorrent' => []];
        $ok    = 0;
        $total = 0;

        // Flat single-instance services first. qBittorrent keeps its catalog
        // slot (the kill switch is still service-level) but is probed per
        // instance below (issue #131).
        foreach (array_keys(HealthCatalog::FLAT_SERVICES) as $service) {
            if ($service === ServiceInstance::TYPE_QBITTORRENT) {
                continue;
            }
            try {
                $state = $health->isHealthy($service);
            } catch (\Throwable) {
                $state = null;
            }
            $services[$service] = $state;
            if ($state !== null) {
                $total++;
                if ($state) $ok++;
            }
        }

        // Multi-instance Radarr / Sonarr / qBittorrent — ping each enabled
        // instance and aggregate. Service-level state = AND of every
        // instance state (so the dot turns red as soon as any one is down).
        // null when no instance is configured / enabled.
        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR, ServiceInstance::TYPE_QBITTORRENT] as $type) {
            $aggregate = null;
            // The qBittorrent kill switch (issue #15) hides every instance.
            $enabled = $type === ServiceInstance::TYPE_QBITTORRENT && !$health->isConfigured($type)
                ? []
                : $instances->getEnabled($type);
            foreach ($enabled as $instance) {
                try {
                    $state = $health->isHealthy($type, $instance->getSlug());
                } catch (\Throwable) {
                    $state = null;
                }
                $instancesMap[$type][] = [
                    'slug'  => $instance->getSlug(),
                    'name'  => $instance->getName(),
                    'state' => $state,
                ];
                if ($state !== null) {
                    $total++;
                    if ($state) $ok++;
                    $aggregate = ($aggregate === null) ? $state : ($aggregate && $state);
                }
            }
            $services[$type] = $aggregate;
        }

        return new JsonResponse([
            'services'  => $services,
            'instances' => $instancesMap,
            'ok'        => $ok,
            'total'     => $total,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
