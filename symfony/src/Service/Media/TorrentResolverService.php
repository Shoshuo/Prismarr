<?php

namespace App\Service\Media;

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
        $best = null;
        $bestScore = 0;
        foreach ($this->radarr->getRawMovies() as $m) {
            $score = $this->scoreMatch($m['title'] ?? '', $needle, $m['year'] ?? null, $parsed['year']);
            if ($score > $bestScore) { $bestScore = $score; $best = $m; }
        }
        if ($best && $bestScore >= self::MIN_SCORE) {
            return [
                'found' => true,
                'id'    => (int)$best['id'],
                'title' => (string)($best['title'] ?? ''),
                'url'   => '/medias/films?open=' . (int)$best['id'],
            ];
        }
        return ['found' => false, 'error' => 'No match in library', 'parsed' => $parsed];
    }

    private function resolveSeries(string $needle, array $parsed): array
    {
        $best = null;
        $bestScore = 0;
        foreach ($this->sonarr->getRawAllSeries() as $s) {
            $score = $this->scoreMatch($s['title'] ?? '', $needle, $s['year'] ?? null, $parsed['year']);
            if ($score > $bestScore) { $bestScore = $score; $best = $s; }
        }
        if ($best && $bestScore >= self::MIN_SCORE) {
            return [
                'found' => true,
                'id'    => (int)$best['id'],
                'title' => (string)($best['title'] ?? ''),
                'url'   => '/medias/series?open=' . (int)$best['id'],
            ];
        }
        return ['found' => false, 'error' => 'No match in library', 'parsed' => $parsed];
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
        $s = mb_strtolower($s);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }
}
