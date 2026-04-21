<?php

namespace App\Service;

use App\Controller\AdminSettingsController;
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
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ConfigService $config,
    ) {}

    public function reset(): void
    {
        $this->cache = null;
    }

    public function getHomePage(): string          { return $this->get('display_home_page'); }
    public function areToastsEnabled(): bool       { return $this->get('display_toasts') === '1'; }
    public function getTimezone(): string          { return $this->get('display_timezone'); }
    public function getDateFormat(): string        { return $this->get('display_date_format'); }
    public function getTimeFormat(): string        { return $this->get('display_time_format'); }
    public function getThemeColor(): string        { return $this->get('display_theme_color'); }
    public function getDefaultView(): string       { return $this->get('display_default_view'); }
    public function getQbitRefreshSeconds(): int   { return (int) $this->get('display_qbit_refresh'); }
    public function getUiDensity(): string         { return $this->get('display_ui_density'); }

    /**
     * Resolves the chosen theme color name (e.g. "indigo") to its hex code
     * so it can be injected into CSS variables. Falls back to the default
     * palette entry if the stored value is unknown.
     */
    public function getThemeColorHex(): string
    {
        $spec = AdminSettingsController::DISPLAY_OPTIONS['display_theme_color'];
        $chosen = $this->getThemeColor();

        return $spec['options'][$chosen] ?? $spec['options'][$spec['default']];
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
     *   default_view: string,
     *   qbit_refresh_seconds: int,
     *   ui_density: string,
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
            'default_view'         => $this->getDefaultView(),
            'qbit_refresh_seconds' => $this->getQbitRefreshSeconds(),
            'ui_density'           => $this->getUiDensity(),
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
