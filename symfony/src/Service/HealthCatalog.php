<?php

namespace App\Service;

/**
 * Single list of the single-instance services shown by every health view:
 * the /api/health/services snapshot (topbar dropdown) and the dashboard
 * "Services health" widget. Radarr / Sonarr are reported per instance and
 * are not listed here. Adding a service here makes it appear everywhere.
 */
final class HealthCatalog
{
    /** Service id => display label, in display order. */
    public const FLAT_SERVICES = [
        'tmdb'         => 'TMDb',
        'prowlarr'     => 'Prowlarr',
        'jellyseerr'   => 'Seerr',
        'qbittorrent'  => 'qBittorrent',
        'deluge'       => 'Deluge',
        'transmission' => 'Transmission',
        'sabnzbd'      => 'SABnzbd',
        'nzbget'       => 'NZBGet',
        'tautulli'     => 'Tautulli',
        'tracearr'     => 'Tracearr',
        'emby'         => 'Emby',
        'bazarr'       => 'Bazarr',
    ];
}
