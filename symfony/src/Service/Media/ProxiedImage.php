<?php

namespace App\Service\Media;

/**
 * Content-type gate shared by the server-side poster proxies (Tautulli,
 * Emby, Tracearr). Those responses are served from Prismarr's own origin,
 * whose CSP allows inline script, so only raster formats are passed
 * through: an SVG (or anything else that can carry markup) is refused.
 */
final class ProxiedImage
{
    private const RASTER_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp'];

    /** Normalized raster content type, or null when the upstream type must not be proxied. */
    public static function rasterContentType(string $header): ?string
    {
        $type = strtolower(trim(explode(';', $header)[0]));
        if ($type === 'image/jpg') {
            $type = 'image/jpeg';
        }

        return in_array($type, self::RASTER_TYPES, true) ? $type : null;
    }
}
