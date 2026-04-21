<?php

namespace App\Twig;

use App\Service\DisplayPreferencesService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the `display_*` preferences to templates. Usage:
 *
 *   {% set prefs = display_prefs() %}
 *   {{ prefs.theme_color_hex }}   {# e.g. "#6366f1" #}
 *   {{ display_pref('timezone') }}   {# "Europe/Paris" #}
 */
class DisplayPreferencesExtension extends AbstractExtension
{
    public function __construct(
        private readonly DisplayPreferencesService $prefs,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_prefs', [$this->prefs, 'all']),
            new TwigFunction('display_pref', [$this, 'pref']),
        ];
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
            'default_view'         => $this->prefs->getDefaultView(),
            'qbit_refresh_seconds' => $this->prefs->getQbitRefreshSeconds(),
            'ui_density'           => $this->prefs->getUiDensity(),
            default                => null,
        };
    }
}
