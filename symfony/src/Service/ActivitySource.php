<?php

namespace App\Service;

/**
 * Picks the service feeding live media activity (dashboard "current
 * activity" widget + sidebar streaming pill) among Tautulli and Tracearr.
 *
 * The admin preference (`display_activity_source`: auto | tautulli |
 * tracearr) only reorders the candidates — a preferred service that isn't
 * usable falls back to the other one, so a stale preference never blanks
 * the widget. `auto` keeps the historical order (Tautulli first).
 */
final class ActivitySource
{
    public const SETTING  = 'display_activity_source';
    public const SERVICES = ['tautulli', 'tracearr'];

    /**
     * @param callable(string): bool $isUsable
     */
    public static function resolve(?string $preferred, callable $isUsable): ?string
    {
        $order = self::SERVICES;
        if ($preferred !== null && in_array($preferred, self::SERVICES, true)) {
            $order = array_values(array_unique([$preferred, ...self::SERVICES]));
        }
        foreach ($order as $service) {
            if ($isUsable($service)) {
                return $service;
            }
        }
        return null;
    }
}
