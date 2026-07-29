<?php

namespace App\Twig;

use App\Service\DisplayPreferencesService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Exposes the `display_*` preferences to templates. Usage:
 *
 *   {% set prefs = display_prefs() %}
 *   {{ prefs.theme_color_hex }}              {# "#6366f1" #}
 *   {{ display_pref('timezone') }}           {# "Europe/Paris" #}
 *   {{ item.date|prismarr_date }}            {# "21/04/2026" (per user format) #}
 *   {{ item.date|prismarr_time }}            {# "14:30" or "2:30 PM" #}
 *   {{ item.date|prismarr_datetime }}        {# "21/04/2026 · 14:30" #}
 */
class DisplayPreferencesExtension extends AbstractExtension
{
    public function __construct(
        private readonly DisplayPreferencesService $prefs,
        private readonly RequestStack $requestStack,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_prefs', [$this->prefs, 'all']),
            new TwigFunction('display_pref', [$this, 'pref']),
            // Exposes the locale-resolved byte unit suffixes so JS-side
            // helpers (qBit polling, film/series cards) format sizes
            // identically to server-rendered Twig output.
            new TwigFunction('prismarr_byte_units', [$this, 'getByteUnits']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('prismarr_date',     [$this, 'filterDate']),
            new TwigFilter('prismarr_time',     [$this, 'filterTime']),
            new TwigFilter('prismarr_datetime', [$this, 'filterDateTime']),
            new TwigFilter('prismarr_bytes',    [$this, 'filterBytes']),
            new TwigFilter('prismarr_speed',    [$this, 'filterSpeed']),
        ];
    }

    /**
     * Format a byte count with locale-aware unit suffix:
     *   en → "1.5 GB" / "950 MB" / "12 KB"
     *   fr → "1.5 Go" / "950 Mo" / "12 Ko"
     * Uses 1024-based units (matches what Sonarr / Radarr / qBittorrent
     * report and what the rest of Prismarr was already doing).
     *
     * @param int|float|string|null $bytes
     */
    public function filterBytes(mixed $bytes, int $precision = 1): string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes)) {
            return '—';
        }
        return $this->formatBytes((float) $bytes, $precision, perSecond: false);
    }

    /**
     * Format a transfer rate. Same as filterBytes but with "/s" suffix
     * (e.g. "MB/s" or "Mo/s").
     *
     * @param int|float|string|null $bytesPerSecond
     */
    public function filterSpeed(mixed $bytesPerSecond, int $precision = 1): string
    {
        if ($bytesPerSecond === null || $bytesPerSecond === '' || !is_numeric($bytesPerSecond)) {
            return '—';
        }
        return $this->formatBytes((float) $bytesPerSecond, $precision, perSecond: true);
    }

    /**
     * Returns the per-locale unit table. JS-side helpers grab this same
     * mapping via the `prismarr_byte_units` Twig function so server- and
     * client-rendered sizes use identical labels.
     *
     * @return array{B: string, KB: string, MB: string, GB: string, TB: string}
     */
    private function unitsForLocale(string $locale): array
    {
        // French uses "octets" (Go / Mo / Ko / To). English uses the SI-ish
        // GB / MB / KB / TB naming common in consumer apps (Windows,
        // qBittorrent, Sonarr/Radarr UIs) — strictly speaking 1024-based
        // would be GiB / MiB / KiB / TiB, but no end-user calls them that.
        if (str_starts_with($locale, 'fr')) {
            return ['B' => 'o', 'KB' => 'Ko', 'MB' => 'Mo', 'GB' => 'Go', 'TB' => 'To'];
        }
        return ['B' => 'B', 'KB' => 'KB', 'MB' => 'MB', 'GB' => 'GB', 'TB' => 'TB'];
    }

    private function formatBytes(float $bytes, int $precision, bool $perSecond): string
    {
        $units = $this->unitsForLocale($this->currentLocale());
        $abs = abs($bytes);
        if ($abs >= 1099511627776.0)  { $value = $bytes / 1099511627776.0; $unit = $units['TB']; }
        elseif ($abs >= 1073741824.0) { $value = $bytes / 1073741824.0;    $unit = $units['GB']; }
        elseif ($abs >= 1048576.0)    { $value = $bytes / 1048576.0;       $unit = $units['MB']; }
        elseif ($abs >= 1024.0)       { $value = $bytes / 1024.0;          $unit = $units['KB']; }
        else                          { $value = $bytes;                   $unit = $units['B']; }

        // Drop the decimal for the bytes-only case to avoid "0.0 B".
        $p = ($unit === $units['B']) ? 0 : $precision;
        $formatted = number_format($value, $p, '.', '');
        return $formatted . ' ' . $unit . ($perSecond ? '/s' : '');
    }

    public function getByteUnits(): array
    {
        return $this->unitsForLocale($this->currentLocale());
    }

    private function currentLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
    }

    public function filterDate(mixed $dt): ?string
    {
        return $this->prefs->formatDate($this->asDateTime($dt));
    }

    public function filterTime(mixed $dt): ?string
    {
        return $this->prefs->formatTime($this->asDateTime($dt));
    }

    public function filterDateTime(mixed $dt): ?string
    {
        return $this->prefs->formatDateTime($this->asDateTime($dt));
    }

    public function pref(string $key): mixed
    {
        return match ($key) {
            'home_page'            => $this->prefs->getHomePage(),
            'toasts'               => $this->prefs->areToastsEnabled(),
            'timezone'             => $this->prefs->getTimezone(),
            'date_format'          => $this->prefs->getDateFormat(),
            'time_format'          => $this->prefs->getTimeFormat(),
            'theme_color'          => $this->prefs->getThemeColor(),
            'theme_color_hex'      => $this->prefs->getThemeColorHex(),
            'theme_color_rgb'      => $this->prefs->getThemeColorRgb(),
            'qbit_refresh_seconds' => $this->prefs->getQbitRefreshSeconds(),
            'deluge_refresh_seconds' => $this->prefs->getDelugeRefreshSeconds(),
            'transmission_refresh_seconds' => $this->prefs->getTransmissionRefreshSeconds(),
            'ui_density'           => $this->prefs->getUiDensity(),
            default                => null,
        };
    }

    /**
     * Accept DateTimeInterface, ISO string, or Unix timestamp — mirrors
     * Twig's native `|date` filter tolerance so callers can swap `|date`
     * for `|prismarr_date` without touching their data payloads.
     */
    private function asDateTime(mixed $dt): ?\DateTimeInterface
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        if ($dt instanceof \DateTimeInterface) {
            return $dt;
        }
        try {
            if (is_int($dt)) {
                return (new \DateTimeImmutable('@' . $dt));
            }
            return new \DateTimeImmutable((string) $dt);
        } catch (\Throwable) {
            return null;
        }
    }
}
