<?php

namespace App\Message;

/**
 * "Please refresh the cache entry under this key, off the request path."
 *
 * The key is always constructed by the application (a class constant or a
 * constant prefix + an instance slug) — never taken from user input.
 */
final readonly class RefreshCacheKey
{
    public function __construct(public string $key) {}
}
