<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    /** Single-instance services — kept on the legacy flat ping API. */
    private const FLAT_SERVICES = ['prowlarr', 'jellyseerr', 'qbittorrent', 'transmission', 'sabnzbd', 'nzbget', 'tmdb'];

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
     * service) plus an `instances` map detailing each Radarr/Sonarr
     * instance individually, so the topbar can render one row per
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
        $instancesMap = ['radarr' => [], 'sonarr' => []];

        // Flat single-instance services first.
        foreach (self::FLAT_SERVICES as $service) {
            try {
                $state = $health->isHealthy($service);
            } catch (\Throwable) {
                $state = null;
            }
            $services[$service] = $state;
        }

        // Multi-instance Radarr / Sonarr — ping each enabled instance and
        // aggregate. Service-level state = AND of every instance state
        // (so the dot turns red as soon as any one is down). null when
        // no instance is configured / enabled.
        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR] as $type) {
            $aggregate = null;
            foreach ($instances->getEnabled($type) as $instance) {
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
                    $aggregate = ($aggregate === null) ? $state : ($aggregate && $state);
                }
            }
            $services[$type] = $aggregate;
        }

        // Unified chip list — same rows the dashboard section renders, so the
        // popover is a true mirror. Unconfigured services are absent (no more
        // "Not configured" rows); Unraid is admin-only like on the dashboard.
        $chips = $health->chips($this->isGranted('ROLE_ADMIN'));
        $okChips = count(array_filter($chips, static fn(array $c): bool => in_array($c['status'], ['up', 'slow', 'very_slow'], true)));

        return new JsonResponse([
            // Legacy shape — kept for upstream-diff hygiene and stale cached JS.
            'services'  => $services,
            'instances' => $instancesMap,
            // v1.2 — the topbar renders from `chips`; ok/total mirror the chip list.
            'chips'     => $chips,
            'ok'        => $okChips,
            'total'     => count($chips),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
