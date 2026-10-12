<?php

namespace App\Service\Media;

/**
 * Ranking and filtering for the Search page, on raw TMDb items (movie or tv
 * entries from /search/multi). Pure functions so they can be unit-tested.
 *
 * Relevance favours, in order: an exact title match, a title starting with
 * the query, a title containing every query word; then popularity, the vote
 * count, a year typed in the query ("dune 2021") and finally TMDb's own order.
 */
final class SearchRanker
{
    public const SORTS = ['relevance', 'popularity', 'vote', 'newest'];

    private static ?\Transliterator $translit = null;

    /** Lower-case, accent-free, punctuation-free, single-spaced. */
    public static function normalize(string $s): string
    {
        self::$translit ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $s = self::$translit ? (string) self::$translit->transliterate($s) : mb_strtolower($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private static ?\Transliterator $plainTranslit = null;

    /** Accents removed, case and spacing kept: "Pokémo" → "Pokemo". */
    public static function stripAccents(string $s): string
    {
        self::$plainTranslit ??= \Transliterator::create('Any-Latin; Latin-ASCII');

        return self::$plainTranslit ? (string) self::$plainTranslit->transliterate($s) : $s;
    }

    /**
     * Splits a trailing year off the query: "dune 2021" → ["dune", 2021].
     *
     * @return array{0: string, 1: ?int}
     */
    public static function splitYear(string $query): array
    {
        $query = trim($query);
        // Only a plausible release year counts: "blade runner 2049" keeps its 2049.
        if (preg_match('/^(.+?)\s+\(?((?:19|20)\d{2})\)?$/u', $query, $m)
            && (int) $m[2] <= (int) date('Y') + 5) {
            return [trim($m[1]), (int) $m[2]];
        }

        return [$query, null];
    }

    /** @param array<string, mixed> $item raw TMDb movie/tv item */
    public static function year(array $item): ?int
    {
        $date = $item['release_date'] ?? $item['first_air_date'] ?? null;

        return is_string($date) && strlen($date) >= 4 ? (int) substr($date, 0, 4) : null;
    }

    /** @param array<string, mixed> $item */
    public static function type(array $item): ?string
    {
        $t = $item['media_type'] ?? null;
        if ($t !== null) {
            return $t === 'movie' || $t === 'tv' ? $t : null; // person, collection…
        }

        return isset($item['title']) ? 'movie' : (isset($item['name']) ? 'tv' : null);
    }

    /**
     * @param array<string, mixed> $item
     * @param array{type?: string, genre?: ?int, year_min?: ?int, year_max?: ?int, vote_min?: float} $f
     */
    public static function matches(array $item, array $f): bool
    {
        $type = self::type($item);
        if ($type === null) {
            return false;
        }
        if (($f['type'] ?? 'all') !== 'all' && $type !== $f['type']) {
            return false;
        }
        if (!empty($f['genre']) && !in_array((int) $f['genre'], array_map('intval', $item['genre_ids'] ?? []), true)) {
            return false;
        }
        $year = self::year($item);
        if (!empty($f['year_min']) && ($year === null || $year < $f['year_min'])) {
            return false;
        }
        if (!empty($f['year_max']) && ($year === null || $year > $f['year_max'])) {
            return false;
        }
        if (!empty($f['vote_min']) && (float) ($item['vote_average'] ?? 0) < (float) $f['vote_min']) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $item */
    public static function score(array $item, string $normalizedQuery, ?int $yearHint, int $tmdbIndex): float
    {
        $titles = array_filter([
            $item['title'] ?? $item['name'] ?? null,
            $item['original_title'] ?? $item['original_name'] ?? null,
        ], 'is_string');
        $words = $normalizedQuery === '' ? [] : explode(' ', $normalizedQuery);

        $best = 0;
        foreach ($titles as $t) {
            $nt = self::normalize($t);
            $s = match (true) {
                $nt === $normalizedQuery                         => 1000,
                str_starts_with($nt, $normalizedQuery . ' ')     => 450,
                str_starts_with($nt, $normalizedQuery)           => 400,
                $words !== [] && self::hasAllWords($nt, $words)  => 200,
                str_contains($nt, $normalizedQuery)              => 150,
                default                                          => 0,
            };
            $best = max($best, $s);
        }

        $popularity = (float) ($item['popularity'] ?? 0);
        $votes = (int) ($item['vote_count'] ?? 0);
        $score = $best
            + log10(1 + $popularity) * 60
            + log10(1 + $votes) * 25
            - $tmdbIndex * 2;
        // A title almost nobody knows (a 2-vote short called "Poke") must not
        // outrank a famous one that merely starts the same way (Pokémon).
        if ($votes < 10 && $popularity < 3) {
            $score -= 500;
        }

        if ($yearHint !== null) {
            $year = self::year($item);
            if ($year === $yearHint) {
                $score += 300;
            } elseif ($year !== null && abs($year - $yearHint) > 1) {
                $score -= 100;
            }
        }

        return $score;
    }

    /**
     * @param list<array<string, mixed>> $items raw TMDb items, in TMDb order
     * @return list<array<string, mixed>>
     */
    public static function sort(array $items, string $mode, string $normalizedQuery = '', ?int $yearHint = null): array
    {
        $keyed = [];
        foreach (array_values($items) as $i => $item) {
            $keyed[] = ['i' => $i, 'item' => $item];
        }

        usort($keyed, function (array $a, array $b) use ($mode, $normalizedQuery, $yearHint): int {
            $x = $a['item'];
            $y = $b['item'];
            $cmp = match ($mode) {
                'popularity' => ($y['popularity'] ?? 0) <=> ($x['popularity'] ?? 0),
                // Well-rated with enough votes first; a 10/10 from 3 votes is noise.
                'vote'       => [($y['vote_count'] ?? 0) >= 50, $y['vote_average'] ?? 0] <=> [($x['vote_count'] ?? 0) >= 50, $x['vote_average'] ?? 0],
                'newest'     => (self::year($y) ?? 0) <=> (self::year($x) ?? 0)
                                ?: strcmp((string) ($y['release_date'] ?? $y['first_air_date'] ?? ''), (string) ($x['release_date'] ?? $x['first_air_date'] ?? '')),
                default      => self::score($y, $normalizedQuery, $yearHint, $b['i']) <=> self::score($x, $normalizedQuery, $yearHint, $a['i']),
            };

            return $cmp !== 0 ? $cmp : $a['i'] <=> $b['i'];
        });

        return array_map(fn(array $k) => $k['item'], $keyed);
    }

    /**
     * "Did you mean": the best title for a mistyped query, Google style.
     *
     * Candidates are compared on normalized text by edit distance (a 4–7
     * letter query tolerates one typo, 8–11 two, longer three), on the whole
     * title and on its first words only ("the mtrix" → "The Matrix" from "The
     * Matrix Reloaded"). With $allowCompletion, a query that is the start of a
     * candidate also counts ("pokemo" → "Pokémon"), for half-typed words.
     * Closer wins, then more popular.
     *
     * When the query is itself a real title, a suggestion is still made if
     * the close candidate is far more popular (an obscure "Interstelar" does
     * not hide "Interstellar"); otherwise null.
     *
     * @param list<string|array{label: string, popularity?: float|int}> $candidates
     */
    public static function suggest(string $query, array $candidates, bool $allowCompletion = false): ?string
    {
        $nq = self::normalize($query);
        if (strlen($nq) < 4) {
            return null;
        }
        $maxDistance = max(1, intdiv(strlen($nq), 4));
        $queryWords = count(explode(' ', $nq));

        $best = null;
        $bestKey = null;
        $bestPopularity = 0.0;
        $exactPopularity = null;
        foreach ($candidates as $candidate) {
            $label = is_array($candidate) ? ($candidate['label'] ?? '') : $candidate;
            $popularity = is_array($candidate) ? (float) ($candidate['popularity'] ?? 0) : 0.0;
            if (!is_string($label) || trim($label) === '') {
                continue;
            }
            $words = preg_split('/\s+/u', trim($label)) ?: [];
            $variants = [$label];
            if (count($words) > $queryWords) {
                $variants[] = rtrim(implode(' ', array_slice($words, 0, $queryWords)), " :,.-");
            }
            foreach ($variants as $variant) {
                $nv = self::normalize($variant);
                if ($nv === '') {
                    continue;
                }
                if ($nv === $nq) {
                    $exactPopularity = max($exactPopularity ?? 0.0, $popularity);
                    continue;
                }
                if ($allowCompletion && str_starts_with($nv, $nq)) {
                    $distance = 0.5; // the query is the start of this title
                } elseif (abs(strlen($nv) - strlen($nq)) <= $maxDistance) {
                    $distance = levenshtein($nq, $nv);
                    if ($distance > $maxDistance) {
                        continue;
                    }
                } else {
                    continue;
                }
                $key = [$distance, -$popularity];
                if ($bestKey === null || $key < $bestKey) {
                    $bestKey = $key;
                    $best = $variant;
                    $bestPopularity = $popularity;
                }
            }
        }

        if ($best === null) {
            return null;
        }
        if ($exactPopularity !== null && $bestPopularity < 10 * max($exactPopularity, 0.5)) {
            return null; // the query is a real title and nothing much better is close
        }

        return $best;
    }

    /** @param list<string> $words */
    private static function hasAllWords(string $haystack, array $words): bool
    {
        $have = explode(' ', $haystack);
        foreach ($words as $w) {
            if (!in_array($w, $have, true)) {
                return false;
            }
        }

        return true;
    }
}
