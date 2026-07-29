<?php

namespace App\Service;

use App\Controller\AdminSettingsController;
use App\Service\ThemeService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Typed read access to the `display_*` settings edited via /admin/settings.
 * Each getter falls back to the declared default (in AdminSettingsController::DISPLAY_OPTIONS)
 * when the DB value is null/empty.
 *
 * Implements ResetInterface so the in-request cache is cleared between
 * FrankenPHP worker requests — otherwise an admin changing a preference
 * via /admin/settings would not see it take effect until the worker
 * recycled.
 */
class DisplayPreferencesService implements ResetInterface
{
    /**
     * RGB components for each theme palette entry — used to build
     * `--tblr-primary-rgb` at runtime (Tabler needs it for rgba() mixes).
     * Stays in sync with AdminSettingsController::DISPLAY_OPTIONS['display_theme_color']['options'].
     */
    private const THEME_RGB = [
        'indigo' => '99, 102, 241',
        'red'    => '239, 68, 68',
        'green'  => '34, 197, 94',
        'orange' => '245, 158, 11',
        'pink'   => '236, 72, 153',
        'blue'   => '59, 130, 246',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ConfigService $config,
        private readonly ThemeService $theme,
    ) {}

    public function reset(): void
    {
        $this->cache = null;
    }

    public function getHomePage(): string           { return $this->get('display_home_page'); }
    public function areToastsEnabled(): bool        { return $this->get('display_toasts') === '1'; }

    /**
     * Issue #12 — when the admin hasn't picked a timezone via /admin/settings,
     * fall back to the system zone (which the init script wires to the
     * container's $TZ env var). Stops the UI from forcing Europe/Paris on
     * users who set TZ=Pacific/Honolulu (or any other zone) in their compose.
     */
    public function getTimezone(): string
    {
        $stored = $this->config->get('display_timezone');
        if ($stored !== null && $stored !== '') {
            return (string) $stored;
        }
        return date_default_timezone_get();
    }
    public function getDateFormat(): string         { return $this->get('display_date_format'); }
    public function getTimeFormat(): string         { return $this->get('display_time_format'); }
    public function getThemeColor(): string         { return $this->get('display_theme_color'); }
    public function getQbitRefreshSeconds(): int    { return (int) $this->get('display_qbit_refresh'); }
    public function getDelugeRefreshSeconds(): int  { return (int) $this->get('display_deluge_refresh'); }
    public function getTransmissionRefreshSeconds(): int { return (int) $this->get('display_transmission_refresh'); }
    public function getUiDensity(): string          { return $this->get('display_ui_density'); }

    /**
     * Films / series page size, used as the default value of `per_page`
     * when the user hasn't picked one in the URL. Constrained to
     * MovieLibraryQuery::ALLOWED_PER_PAGE at form validation time.
     */
    public function getPageSize(): int              { return (int) $this->get('display_page_size'); }
    public function getLanguage(): string           { return $this->get('display_language'); }
    public function getMetadataLanguage(): string   { return $this->get('display_metadata_language'); }

    /**
     * Resolves the chosen theme color name (e.g. "indigo") to its hex code
     * so it can be injected into CSS variables. Falls back to the default
     * palette entry if the stored value is unknown.
     */
    public function getThemeColorHex(): string
    {
        $spec = AdminSettingsController::DISPLAY_OPTIONS['display_theme_color'];
        $chosen = $this->getThemeColor();

        // 'theme_default' (and any unknown value, which resolves to the
        // default = theme_default) follows the active theme's primary.
        if ($chosen === 'theme_default' || !isset($spec['options'][$chosen])) {
            return $this->theme->resolve()['primary_hex'];
        }

        return $spec['options'][$chosen];
    }

    /**
     * Theme color as an "R, G, B" tuple for use in rgba() gradients.
     * Falls back to the default palette entry if the stored value is unknown.
     */
    public function getThemeColorRgb(): string
    {
        $chosen = $this->getThemeColor();

        if ($chosen === 'theme_default' || !isset(self::THEME_RGB[$chosen])) {
            return $this->theme->resolve()['primary_rgb'];
        }

        return self::THEME_RGB[$chosen];
    }

    /**
     * Formatted date string according to the user's chosen date format.
     * Null input → null output so callers can render a dash.
     */
    public function formatDate(?\DateTimeInterface $dt): ?string
    {
        if ($dt === null) {
            return null;
        }
        $dt = $this->toUserTimezone($dt);

        return match ($this->getDateFormat()) {
            'us'  => $dt->format('M j, Y'),
            'iso' => $dt->format('Y-m-d'),
            default => $dt->format('d/m/Y'),
        };
    }

    /**
     * Formatted time string according to the user's chosen time format.
     */
    public function formatTime(?\DateTimeInterface $dt): ?string
    {
        if ($dt === null) {
            return null;
        }
        $dt = $this->toUserTimezone($dt);

        return $this->getTimeFormat() === '12h' ? $dt->format('g:i A') : $dt->format('H:i');
    }

    /**
     * Date + time combined, honoring both format preferences.
     */
    public function formatDateTime(?\DateTimeInterface $dt): ?string
    {
        if ($dt === null) {
            return null;
        }

        return $this->formatDate($dt) . ' · ' . $this->formatTime($dt);
    }

    private function toUserTimezone(\DateTimeInterface $dt): \DateTimeImmutable
    {
        $immutable = $dt instanceof \DateTimeImmutable ? $dt : \DateTimeImmutable::createFromInterface($dt);

        try {
            return $immutable->setTimezone(new \DateTimeZone($this->getTimezone()));
        } catch (\Throwable) {
            // Invalid stored timezone → fall back to the raw datetime rather
            // than crash the render. The next admin save will re-normalize.
            return $immutable;
        }
    }

    /**
     * @return array{
     *   home_page: string,
     *   toasts: bool,
     *   timezone: string,
     *   date_format: string,
     *   time_format: string,
     *   theme_color: string,
     *   theme_color_hex: string,
     *   theme_color_rgb: string,
     *   qbit_refresh_seconds: int,
     *   deluge_refresh_seconds: int,
     *   transmission_refresh_seconds: int,
     *   ui_density: string,
     *   page_size: int,
     *   language: string,
     *   metadata_language: string,
     * }
     */
    public function all(): array
    {
        return [
            'home_page'            => $this->getHomePage(),
            'toasts'               => $this->areToastsEnabled(),
            'timezone'             => $this->getTimezone(),
            'date_format'          => $this->getDateFormat(),
            'time_format'          => $this->getTimeFormat(),
            'theme_color'          => $this->getThemeColor(),
            'theme_color_hex'      => $this->getThemeColorHex(),
            'theme_color_rgb'      => $this->getThemeColorRgb(),
            'qbit_refresh_seconds' => $this->getQbitRefreshSeconds(),
            'deluge_refresh_seconds' => $this->getDelugeRefreshSeconds(),
            'transmission_refresh_seconds' => $this->getTransmissionRefreshSeconds(),
            'ui_density'           => $this->getUiDensity(),
            'page_size'            => $this->getPageSize(),
            'language'             => $this->getLanguage(),
            'metadata_language'    => $this->getMetadataLanguage(),
        ];
    }

    private function get(string $key): string
    {
        if ($this->cache === null) {
            $this->cache = [];
        }
        if (!array_key_exists($key, $this->cache)) {
            $raw = $this->config->get($key);
            $default = AdminSettingsController::DISPLAY_OPTIONS[$key]['default'] ?? '';
            $this->cache[$key] = $raw !== null && $raw !== '' ? (string) $raw : $default;
        }

        return $this->cache[$key];
    }
}
