<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ServiceInstanceProvider;

/**
 * Maps Bazarr's radarrId / sonarrSeriesId to OUR OWN Radarr/Sonarr library
 * poster URLs, so the Bazarr tab can show posters without proxying Bazarr's
 * own images (Bazarr serves posters from its local cache of the *arr
 * artwork, which is one more thing that can be stale/missing/slow — the
 * library already has the canonical URL).
 *
 * Bazarr pairs with exactly ONE Radarr and one Sonarr instance, but the ids
 * it hands back (`radarrId` / `sonarrSeriesId`) are bare per-instance ids.
 * With two enabled instances of the same type, "movie 42" means a different
 * film in each, so a poster lookup keyed on that bare id must fail closed
 * instead of guessing — same rule, same predicate
 * (`ServiceInstanceProvider::hasExactlyOneEnabled`), as BazarrSubtitleIndex's
 * badge gate.
 *
 * Backed by MediaLibraryCache (the same short-TTL cache the dashboard and
 * /medias/{slug}/films|series pages use), so opening the Bazarr tab reuses
 * an already-warm library fetch instead of paying for a fresh one on every
 * render.
 */
class BazarrPosterResolver
{
    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly MediaLibraryCache $libraryCache,
    ) {
    }

    /**
     * @return array<int, string> Radarr movie id / Sonarr series id -> poster URL.
     *     Empty when $kind is unknown, the multi-instance gate fails (not
     *     exactly one enabled instance of the paired type), or the library
     *     fetch fails. Rows with a null/empty poster are omitted.
     */
    public function postersFor(string $kind): array
    {
        return match ($kind) {
            'movie'  => $this->moviePosters(),
            'series' => $this->seriesPosters(),
            default  => [],
        };
    }

    /** @return array<int, string> */
    private function moviePosters(): array
    {
        $instance = $this->pairedInstance(ServiceInstance::TYPE_RADARR);
        if ($instance === null) {
            return [];
        }

        $rows = $this->libraryCache->movies(
            $instance->getSlug(),
            fn () => $this->radarr->withInstance($instance)->getMovies(),
        );

        return $this->posterMap($rows);
    }

    /** @return array<int, string> */
    private function seriesPosters(): array
    {
        $instance = $this->pairedInstance(ServiceInstance::TYPE_SONARR);
        if ($instance === null) {
            return [];
        }

        $rows = $this->libraryCache->series(
            $instance->getSlug(),
            fn () => $this->sonarr->withInstance($instance)->getSeries(),
        );

        return $this->posterMap($rows);
    }

    /**
     * The single enabled instance of $type, or null when the multi-instance
     * gate fails (zero or multiple enabled instances).
     */
    private function pairedInstance(string $type): ?ServiceInstance
    {
        if (!$this->instances->hasExactlyOneEnabled($type)) {
            return null;
        }

        return $this->instances->getEnabled($type)[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function posterMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id     = $row['id'] ?? null;
            $poster = $row['poster'] ?? null;
            if ($id === null || $poster === null || $poster === '') {
                continue;
            }
            $map[(int) $id] = (string) $poster;
        }

        return $map;
    }
}
