<?php

namespace App\Service;

use App\Exception\ServiceNotConfiguredException;
use App\Repository\SettingRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Provides access to settings stored in DB (table `setting`).
 *
 * Memory cache scoped to the request. Implements ResetInterface so the
 * cache is cleared between requests in FrankenPHP worker mode, where the
 * container is kept alive across requests and services would otherwise
 * retain stale state.
 */
class ConfigService implements ResetInterface
{
    /** @var array<string, ?string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingRepository $settings,
    ) {}

    public function reset(): void
    {
        $this->cache = null;
    }

    public function get(string $key): ?string
    {
        $this->loadCache();
        $value = $this->cache[$key] ?? null;
        return $value !== '' ? $value : null;
    }

    /**
     * Returns the value or throws ServiceNotConfiguredException if missing.
     */
    public function require(string $key, string $service): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new ServiceNotConfiguredException($service, $key);
        }
        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, ?string $value): void
    {
        $this->settings->set($key, $value);
        $this->cache = null;
    }

    /**
     * Invalidate the cache — useful after a bulk update (wizard, admin).
     */
    public function invalidate(): void
    {
        $this->cache = null;
    }

    private function loadCache(): void
    {
        if ($this->cache === null) {
            $this->cache = $this->settings->getAll();
        }
    }
}
