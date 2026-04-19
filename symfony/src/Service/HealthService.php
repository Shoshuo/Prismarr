<?php

namespace App\Service;

use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;

/**
 * Tests third-party service availability.
 *
 * Short TTL memory cache (CACHE_TTL seconds): amortize HTTP checks within
 * a request burst but still notice a service coming back online within a
 * few seconds, instead of staying stuck for the whole FrankenPHP worker
 * lifetime (10–30 minutes).
 */
class HealthService
{
    private const CACHE_TTL = 10;

    /** @var array<string, array{ok: bool, at: int}> */
    private array $cache = [];

    public function __construct(
        private readonly RadarrClient      $radarr,
        private readonly SonarrClient      $sonarr,
        private readonly ProwlarrClient    $prowlarr,
        private readonly JellyseerrClient  $jellyseerr,
        private readonly QBittorrentClient $qbittorrent,
        private readonly TmdbClient        $tmdb,
    ) {}

    public function isHealthy(string $service): bool
    {
        $now = time();
        if (isset($this->cache[$service]) && ($now - $this->cache[$service]['at']) < self::CACHE_TTL) {
            return $this->cache[$service]['ok'];
        }

        $ok = match ($service) {
            'radarr'      => $this->radarr->ping(),
            'sonarr'      => $this->sonarr->ping(),
            'prowlarr'    => $this->prowlarr->ping(),
            'jellyseerr'  => $this->jellyseerr->ping(),
            'qbittorrent' => $this->qbittorrent->ping(),
            'tmdb'        => $this->tmdb->ping(),
            default       => true,
        };

        $this->cache[$service] = ['ok' => $ok, 'at' => $now];
        return $ok;
    }

    /** Invalidate the cache — useful after a reconfiguration via admin. */
    public function invalidate(?string $service = null): void
    {
        if ($service === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$service]);
        }
    }
}
