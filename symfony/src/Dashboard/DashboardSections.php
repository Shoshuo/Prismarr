<?php
namespace App\Dashboard;

/**
 * Canonical registry of the dashboard's reorderable/hideable content
 * sections. The Hero is intentionally absent — it is pinned at the top and
 * never reordered or hidden. This is the single source of truth shared by
 * DashboardLayoutService, the dashboard template, and the admin settings page.
 */
final class DashboardSections
{
    /** Section keys in their default top-to-bottom order. */
    public const DEFAULT_ORDER = [
        'upcoming', 'requests', 'health', 'houndarr', 'plex', 'watchlist', 'trending', 'recent', 'server', 'network',
    ];

    /**
     * key => metadata for the settings list. `label` reuses the existing
     * dashboard section-title translation keys (no new label strings needed).
     * @var array<string, array{label: string}>
     */
    public const META = [
        'upcoming'  => ['label' => 'dashboard.upcoming.title'],
        'requests'  => ['label' => 'dashboard.requests.title'],
        'health'    => ['label' => 'dashboard.health.title'],
        'houndarr'  => ['label' => 'dashboard.houndarr.title'],
        'plex'      => ['label' => 'dashboard.plex.title'],
        'watchlist' => ['label' => 'dashboard.watchlist.title'],
        'trending'  => ['label' => 'dashboard.trending.title'],
        'recent'    => ['label' => 'dashboard.recent.title'],
        'server'    => ['label' => 'dashboard.server.title'],
        'network'   => ['label' => 'dashboard.network.title'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return self::DEFAULT_ORDER;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::DEFAULT_ORDER, true);
    }
}
