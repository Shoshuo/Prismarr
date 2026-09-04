<?php

namespace App\Service\Media;

/**
 * Pure mapper: turns a Bazarr movie/episode dict's subtitle arrays into the
 * present/missing language lists the detail modal renders.
 *
 * Kept as a standalone callable (NOT an index-private method) because two
 * consumers map the same raw dict shape: BazarrSubtitleIndex (movie badges +
 * the film-detail modal) and the episode drill-down controller.
 *
 * Field-name tolerant on purpose. Bazarr's language label appears as `code2`
 * (2-letter), `code3` (3-letter) or `name` across versions/endpoints, and the
 * hearing-impaired flag as `hi` or `hearing_impaired`; some versions answer
 * the arrays as `[code2, path]` tuples rather than dicts. An unrecognized
 * entry degrades to a label-only / dropped row rather than throwing — a
 * decorative detail panel must never break the modal that opens it.
 */
final class BazarrLangs
{
    /**
     * @param array<string, mixed> $dict A Bazarr movie or episode dict.
     * @return array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>}
     */
    public static function extract(array $dict): array
    {
        return [
            'present' => self::mapList($dict['subtitles'] ?? null),
            'missing' => self::mapList($dict['missing_subtitles'] ?? null),
        ];
    }

    /**
     * @return list<array{lang: string, hi: bool, forced: bool}>
     */
    private static function mapList(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            $lang = self::mapEntry($entry);
            if ($lang !== null) {
                $out[] = $lang;
            }
        }

        return $out;
    }

    /**
     * @return array{lang: string, hi: bool, forced: bool}|null null when the entry carries no usable label.
     */
    private static function mapEntry(mixed $entry): ?array
    {
        if (is_array($entry)) {
            // Dict form: prefer code2, then code3, then the display name.
            $label = $entry['code2'] ?? $entry['code3'] ?? $entry['name'] ?? null;
            // Tuple form ([code2, path]): fall back to the first element.
            if ($label === null && array_key_exists(0, $entry)) {
                $label = $entry[0] ?? null;
            }
            $label = is_string($label) ? trim($label) : '';
            if ($label === '') {
                return null;
            }

            return [
                'lang'   => $label,
                'hi'     => (bool) ($entry['hi'] ?? $entry['hearing_impaired'] ?? false),
                'forced' => (bool) ($entry['forced'] ?? false),
            ];
        }

        // Bare string form: just the language code.
        if (is_string($entry) && trim($entry) !== '') {
            return ['lang' => trim($entry), 'hi' => false, 'forced' => false];
        }

        return null;
    }
}
