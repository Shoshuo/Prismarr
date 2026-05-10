<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ServiceInstanceProvider;

/**
 * Resolves a torrent release name (e.g. "Dune.Part.Two.2024.2160p.BluRay")
 * to a Radarr movie or a Sonarr series already in the library.
 * Logic extracted from QBittorrentController for testability + reuse.
 */
class TorrentResolverService
{
    /** Minimum matching score required to consider a result valid. */
    public const MIN_SCORE = 70;

    public function __construct(
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly ServiceInstanceProvider $instances,
    ) {}

    /**
     * @return array{found: bool, id?: int, title?: string, url?: string, error?: string, parsed?: array}
     */
    public function resolve(string $pipeline, string $torrentName): array
    {
        $parsed = self::parseReleaseName($torrentName);
        $needle = self::normalizeTitle($parsed['title']);
        if ($needle === '') {
            return ['found' => false, 'error' => 'Title cannot be parsed', 'parsed' => $parsed];
        }

        if ($pipeline === 'radarr') {
            return $this->resolveMovie($needle, $parsed);
        }
        if ($pipeline === 'sonarr') {
            return $this->resolveSeries($needle, $parsed);
        }
        return ['found' => false, 'error' => 'Unknown pipeline'];
    }

    private function resolveMovie(string $needle, array $parsed): array
    {
        // Phase D — iterate over every enabled Radarr instance and keep the
        // best score globally. This matches the user's mental model: a
        // torrent should be linkable to whichever instance has the movie
        // (Radarr 1080p OR Radarr 4K OR Radarr Anime), not just the default.
        // The per-instance call is wrapped so a single unreachable instance
        // doesn't kill the whole resolve — its movies are simply skipped and
        // the badge falls through to the default-unavailable answer if no
        // instance returned anything.
        $enabled = $this->instances->getEnabled(ServiceInstance::TYPE_RADARR);
        if ($enabled === []) {
            return ['found' => false, 'error' => 'No Radarr instance', 'parsed' => $parsed];
        }

        $best = null;
        $bestScore = 0;
        $bestSlug = null;
        $reachedAny = false;
        foreach ($enabled as $instance) {
            try {
                $movies = $this->radarr->withInstance($instance)->getRawMovies();
                $reachedAny = true;
            } catch (\Throwable) {
                continue; // instance unreachable, skip but keep going
            }
            foreach ($movies as $m) {
                // Scoring is done against every known title for the movie:
                //   - `title`         (configured Radarr language, often FR)
                //   - `originalTitle` (TMDb original, often EN)
                //   - `alternateTitles[*].title` (regional + studio variants)
                // A torrent named after the EN title must still match a Radarr
                // movie stored under its FR translation, and vice-versa.
                foreach ($this->collectMovieTitles($m) as $candidate) {
                    $score = $this->scoreMatch($candidate, $needle, $m['year'] ?? null, $parsed['year']);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best      = $m;
                        $bestSlug  = $instance->getSlug();
                    }
                }
            }
        }
        if (!$reachedAny) {
            return ['found' => false, 'error' => 'Radarr unavailable', 'parsed' => $parsed];
        }
        if ($best !== null && $bestSlug !== null && $bestScore >= self::MIN_SCORE) {
            return [
                'found' => true,
                'id'    => (int)$best['id'],
                'title' => (string)($best['title'] ?? ''),
                'url'   => '/medias/' . $bestSlug . '/films?open=' . (int)$best['id'],
            ];
        }
        return ['found' => false, 'error' => 'No match in library', 'parsed' => $parsed];
    }

    private function resolveSeries(string $needle, array $parsed): array
    {
        // Same iteration pattern as resolveMovie — see comments there.
        $enabled = $this->instances->getEnabled(ServiceInstance::TYPE_SONARR);
        if ($enabled === []) {
            return ['found' => false, 'error' => 'No Sonarr instance', 'parsed' => $parsed];
        }

        $best = null;
        $bestScore = 0;
        $bestSlug = null;
        $reachedAny = false;
        foreach ($enabled as $instance) {
            try {
                $series = $this->sonarr->withInstance($instance)->getRawAllSeries();
                $reachedAny = true;
            } catch (\Throwable) {
                continue;
            }
            foreach ($series as $s) {
                foreach ($this->collectSeriesTitles($s) as $candidate) {
                    $score = $this->scoreMatch($candidate, $needle, $s['year'] ?? null, $parsed['year']);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best      = $s;
                        $bestSlug  = $instance->getSlug();
                    }
                }
            }
        }
        if (!$reachedAny) {
            return ['found' => false, 'error' => 'Sonarr unavailable', 'parsed' => $parsed];
        }
        if ($best !== null && $bestSlug !== null && $bestScore >= self::MIN_SCORE) {
            return [
                'found' => true,
                'id'    => (int)$best['id'],
                'title' => (string)($best['title'] ?? ''),
                'url'   => '/medias/' . $bestSlug . '/series?open=' . (int)$best['id'],
            ];
        }
        return ['found' => false, 'error' => 'No match in library', 'parsed' => $parsed];
    }

    /**
     * Build the list of candidate titles for a Radarr movie row.
     * Returns title + originalTitle + every alternateTitles[].title, deduped.
     *
     * @param array<string, mixed> $m raw Radarr movie payload
     * @return list<string>
     */
    private function collectMovieTitles(array $m): array
    {
        $titles = [];
        if (!empty($m['title']))         $titles[] = (string) $m['title'];
        if (!empty($m['originalTitle'])) $titles[] = (string) $m['originalTitle'];
        foreach (($m['alternateTitles'] ?? []) as $alt) {
            if (is_array($alt) && !empty($alt['title'])) {
                $titles[] = (string) $alt['title'];
            }
        }
        return array_values(array_unique($titles));
    }

    /**
     * Same as collectMovieTitles() but for Sonarr series payloads.
     *
     * @param array<string, mixed> $s raw Sonarr series payload
     * @return list<string>
     */
    private function collectSeriesTitles(array $s): array
    {
        $titles = [];
        if (!empty($s['title']))         $titles[] = (string) $s['title'];
        if (!empty($s['originalTitle'])) $titles[] = (string) $s['originalTitle'];
        foreach (($s['alternateTitles'] ?? []) as $alt) {
            if (is_array($alt) && !empty($alt['title'])) {
                $titles[] = (string) $alt['title'];
            }
        }
        return array_values(array_unique($titles));
    }

    /** Minimum needle length to accept a `contains` match (avoids "It" matching "Split"). */
    private const MIN_CONTAINS_LEN = 4;

    /**
     * Matching score between a library title and a parsed title.
     * 100 = exact, 70 = contains (if needle >= 4 chars), +20 if same year, 0 otherwise.
     */
    private function scoreMatch(string $libTitle, string $needle, ?int $libYear, ?int $parsedYear): int
    {
        $titleNorm = self::normalizeTitle($libTitle);
        if ($titleNorm === '') return 0;

        $score = 0;
        if ($titleNorm === $needle) {
            $score = 100;
        } elseif (
            mb_strlen($needle) >= self::MIN_CONTAINS_LEN
            && mb_strlen($titleNorm) >= self::MIN_CONTAINS_LEN
            && (str_contains($titleNorm, $needle) || str_contains($needle, $titleNorm))
        ) {
            $score = 70;
        }
        if ($score > 0 && $parsedYear !== null && (int)$libYear === $parsedYear) {
            $score += 20;
        }
        return $score;
    }

    /**
     * Extract title + year from a torrent release name.
     * @return array{title: string, year: int|null}
     */
    public static function parseReleaseName(string $raw): array
    {
        // Replace separators with spaces
        $clean = preg_replace('/[\._]+/', ' ', $raw);
        $year  = null;

        // Find the LAST year preceding a release marker (1080p, BluRay, S01E01, etc.)
        // — avoids cutting at a year that is part of the title (e.g. "1917 2019 1080p" → year=2019, not 1917).
        if (preg_match('/\b(19\d{2}|20\d{2})\b(?=[^0-9]*(?:\b(?:2160p|1080p|720p|480p|BluRay|WEBRip|WEB-DL|HDRip|DVDRip|BDRip|REMUX|DV|HDR|x264|x265|H\.?264|H\.?265|HEVC|S\d{2}E?\d*|COMPLETE)\b|$))/i', $clean, $m, PREG_OFFSET_CAPTURE)) {
            $year  = (int)$m[1][0];
            $clean = trim(substr($clean, 0, $m[1][1]));
        } else {
            // No detectable year: cut at the first quality/source token
            $clean = preg_split('/\b(2160p|1080p|720p|480p|BluRay|WEBRip|WEB-DL|HDRip|DVDRip|BDRip|S\d{2}E\d{2})\b/i', $clean)[0] ?? $clean;
        }
        $clean = trim(preg_replace('/\s+/', ' ', (string)$clean));
        return ['title' => $clean, 'year' => $year];
    }

    public static function normalizeTitle(string $s): string
    {
        // Use intl Transliterator instead of iconv ASCII//TRANSLIT, which on
        // Alpine/musl inserts a stray space before transliterated chars
        // ("Pokémon" → "pok emon"). Transliterator gives a clean ASCII fold,
        // so a release named "La.Traversee" matches a Radarr title stored as
        // "La traversée" — the regression Joshua flagged at session 13.
        static $translit = null;
        if ($translit === null) {
            $translit = \Transliterator::create('Any-Latin; Latin-ASCII');
        }
        if ($translit !== null) {
            $folded = $translit->transliterate($s);
            if ($folded !== false) $s = $folded;
        }
        $s = mb_strtolower($s);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }
}
