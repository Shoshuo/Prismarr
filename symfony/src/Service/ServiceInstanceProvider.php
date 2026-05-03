<?php

namespace App\Service;

use App\Entity\ServiceInstance;
use App\Repository\ServiceInstanceRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Centralised, request-cached access to ServiceInstance rows.
 *
 * Replaces the legacy ConfigService::get('radarr_url') / get('sonarr_url')
 * pattern from v1.0. Read paths go through this provider so we hit Doctrine
 * at most once per request per type, regardless of how many call sites
 * (sidebar, dashboard widgets, controllers, twig extensions) ask for the
 * same information.
 *
 * Request-scoped cache is reset by Symfony's container between requests in
 * FrankenPHP worker mode (ResetInterface). Mutations (create, update, delete)
 * MUST call invalidate() so the next read picks up the change.
 */
class ServiceInstanceProvider implements ResetInterface
{
    /** @var array<string, list<ServiceInstance>>|null */
    private ?array $byType = null;

    public function __construct(
        private readonly ServiceInstanceRepository $repository,
    ) {}

    public function reset(): void
    {
        $this->byType = null;
    }

    /**
     * Drop the in-request cache. Call this after persisting a new instance,
     * editing one, deleting one, or reordering.
     */
    public function invalidate(): void
    {
        $this->byType = null;
    }

    /**
     * Default instance for $type, with a safe fallback to the first instance
     * if no row is flagged is_default (which can happen if the user deletes
     * the previous default without nominating a replacement).
     */
    public function getDefault(string $type): ?ServiceInstance
    {
        $all = $this->getAll($type);
        if ($all === []) {
            return null;
        }
        foreach ($all as $instance) {
            if ($instance->isDefault()) {
                return $instance;
            }
        }
        return $all[0];
    }

    public function getBySlug(string $type, string $slug): ?ServiceInstance
    {
        foreach ($this->getAll($type) as $instance) {
            if ($instance->getSlug() === $slug) {
                return $instance;
            }
        }
        return null;
    }

    /**
     * Resolve a slug to an instance or throw 404. Use this in controllers
     * where the slug comes from the URL — Symfony will render the standard
     * not-found page automatically.
     */
    public function requireBySlug(string $type, string $slug): ServiceInstance
    {
        $instance = $this->getBySlug($type, $slug);
        if ($instance === null) {
            throw new NotFoundHttpException(sprintf('No %s instance with slug "%s".', $type, $slug));
        }
        return $instance;
    }

    /**
     * @return list<ServiceInstance>
     */
    public function getAll(string $type): array
    {
        $this->load();
        return $this->byType[$type] ?? [];
    }

    /**
     * @return list<ServiceInstance>
     */
    public function getEnabled(string $type): array
    {
        return array_values(array_filter(
            $this->getAll($type),
            static fn (ServiceInstance $i) => $i->isEnabled(),
        ));
    }

    public function count(string $type): int
    {
        return count($this->getAll($type));
    }

    public function hasAny(string $type): bool
    {
        return $this->count($type) > 0;
    }

    /**
     * True if the type has at least one enabled instance — i.e. the service
     * should show in the sidebar / dashboard. Mirrors the v1.0 hasAny check
     * on `radarr_api_key` / `sonarr_api_key`.
     */
    public function hasAnyEnabled(string $type): bool
    {
        return $this->getEnabled($type) !== [];
    }

    /**
     * Persist the URL + API key for the default instance of $type. Used by
     * the setup wizard and /admin/settings to mirror the v1.0 single-config
     * UX: the user types one URL + one API key per service, the provider
     * decides whether to update the existing default instance or create the
     * very first one.
     *
     * If $url is empty, removes the default instance entirely (the user
     * cleared the field to "unconfigure" the service); other instances of
     * the same type stay untouched and one of them gets promoted to default.
     */
    public function saveDefault(string $type, ?string $url, ?string $apiKey): ?ServiceInstance
    {
        $url    = $url !== null ? trim($url) : '';
        $apiKey = $apiKey !== null ? trim($apiKey) : null;

        $existing = $this->repository->findDefaultForType($type);

        if ($url === '') {
            if ($existing !== null) {
                $this->repository->remove($existing);
                $this->promoteFirstToDefault($type);
            }
            $this->invalidate();
            return null;
        }

        if ($existing !== null) {
            $existing->setUrl($url);
            $existing->setApiKey($apiKey !== '' ? $apiKey : null);
            $this->repository->save($existing);
            $this->invalidate();
            return $existing;
        }

        $instance = new ServiceInstance(
            type:   $type,
            slug:   $type . '-1',
            name:   ucfirst($type) . ' 1',
            url:    $url,
            apiKey: $apiKey !== '' ? $apiKey : null,
        );
        $instance->setIsDefault(true);
        $instance->setEnabled(true);
        $instance->setPosition(0);
        $this->repository->save($instance);
        $this->invalidate();
        return $instance;
    }

    /**
     * After deleting the default instance of $type, promote the next one
     * (lowest position) to default so the type still has a sensible default.
     * No-op if no other instance exists.
     */
    private function promoteFirstToDefault(string $type): void
    {
        $remaining = $this->repository->findByType($type);
        if ($remaining === []) {
            return;
        }
        $remaining[0]->setIsDefault(true);
        $this->repository->save($remaining[0]);
    }

    public function findById(int $id): ?ServiceInstance
    {
        return $this->repository->find($id);
    }

    /**
     * Create a brand-new instance for $type. Slug is auto-generated from
     * $name if $slug is null/empty, then deduplicated against existing
     * instances of the same type. The first instance of a given type is
     * automatically flagged is_default.
     *
     * @throws \InvalidArgumentException on empty name/url or duplicate slug.
     */
    public function create(
        string $type,
        string $name,
        string $url,
        ?string $apiKey,
        ?string $slug = null,
        bool $enabled = true,
    ): ServiceInstance {
        $name = trim($name);
        $url  = trim($url);
        if ($name === '') {
            throw new \InvalidArgumentException('Instance name cannot be empty.');
        }
        if ($url === '') {
            throw new \InvalidArgumentException('Instance URL cannot be empty.');
        }
        if (!in_array($type, ServiceInstance::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown instance type "%s".', $type));
        }

        $finalSlug = $this->normalizeSlug($type, $slug, $name);
        if ($this->repository->findOneBySlug($type, $finalSlug) !== null) {
            throw new \InvalidArgumentException(sprintf(
                'A %s instance with slug "%s" already exists.',
                $type,
                $finalSlug,
            ));
        }

        $existing = $this->repository->findByType($type);
        $isFirst  = $existing === [];
        $position = $isFirst ? 0 : (max(array_map(fn ($i) => $i->getPosition(), $existing)) + 1);

        $apiKey = $apiKey !== null ? trim($apiKey) : null;
        $instance = new ServiceInstance(
            type:   $type,
            slug:   $finalSlug,
            name:   $name,
            url:    $url,
            apiKey: $apiKey !== '' ? $apiKey : null,
        );
        $instance->setEnabled($enabled);
        $instance->setIsDefault($isFirst); // first instance is automatically default
        $instance->setPosition($position);
        $this->repository->save($instance);
        $this->invalidate();
        return $instance;
    }

    /**
     * Update an existing instance. Slug change is honored but the caller
     * MUST have warned the user that bookmarks may break — the controller
     * is responsible for the UI confirmation.
     *
     * @throws \InvalidArgumentException on empty name/url or duplicate slug.
     */
    public function update(
        ServiceInstance $instance,
        string $name,
        string $url,
        ?string $apiKey,
        ?string $slug = null,
        ?bool $enabled = null,
    ): ServiceInstance {
        $name = trim($name);
        $url  = trim($url);
        if ($name === '') {
            throw new \InvalidArgumentException('Instance name cannot be empty.');
        }
        if ($url === '') {
            throw new \InvalidArgumentException('Instance URL cannot be empty.');
        }

        $instance->setName($name);
        $instance->setUrl($url);

        if ($slug !== null && $slug !== '' && $slug !== $instance->getSlug()) {
            $newSlug = ServiceInstance::slugify($slug);
            $clash = $this->repository->findOneBySlug($instance->getType(), $newSlug);
            if ($clash !== null && $clash->getId() !== $instance->getId()) {
                throw new \InvalidArgumentException(sprintf(
                    'A %s instance with slug "%s" already exists.',
                    $instance->getType(),
                    $newSlug,
                ));
            }
            $instance->setSlug($newSlug);
        }

        // Empty submission of the API key field means "unchanged" (Firefox
        // strips type=password autofill — same regression as v1.0 settings).
        if ($apiKey !== null) {
            $apiKey = trim($apiKey);
            if ($apiKey !== '') {
                $instance->setApiKey($apiKey);
            }
        }

        if ($enabled !== null) {
            $instance->setEnabled($enabled);
        }

        $this->repository->save($instance);
        $this->invalidate();
        return $instance;
    }

    /**
     * Delete an instance. If it was the default, promote the next one of
     * the same type. Returns true if a row was actually deleted.
     */
    public function delete(ServiceInstance $instance): void
    {
        $wasDefault = $instance->isDefault();
        $type = $instance->getType();
        $this->repository->remove($instance);
        if ($wasDefault) {
            $this->promoteFirstToDefault($type);
        }
        $this->invalidate();
    }

    /**
     * Make $instance the default for its type, demoting the previous default.
     * Idempotent: a no-op if $instance is already the default.
     */
    public function setDefault(ServiceInstance $instance): void
    {
        if ($instance->isDefault()) {
            return;
        }
        foreach ($this->repository->findByType($instance->getType()) as $sibling) {
            if ($sibling->isDefault() && $sibling->getId() !== $instance->getId()) {
                $sibling->setIsDefault(false);
                $this->repository->save($sibling, flush: false);
            }
        }
        $instance->setIsDefault(true);
        $this->repository->save($instance);
        $this->invalidate();
    }

    /**
     * Auto-generate a unique slug from $explicitSlug (if provided) or $name.
     * If the resulting slug clashes, append `-2`, `-3`, … until unique.
     */
    private function normalizeSlug(string $type, ?string $explicitSlug, string $fallbackFromName): string
    {
        $base = $explicitSlug !== null && trim($explicitSlug) !== ''
            ? ServiceInstance::slugify($explicitSlug)
            : ServiceInstance::slugify($fallbackFromName);

        if ($this->repository->findOneBySlug($type, $base) === null) {
            return $base;
        }
        $n = 2;
        while ($this->repository->findOneBySlug($type, $base . '-' . $n) !== null) {
            $n++;
        }
        return $base . '-' . $n;
    }

    private function load(): void
    {
        if ($this->byType !== null) {
            return;
        }
        $this->byType = [];
        foreach (ServiceInstance::TYPES as $type) {
            $this->byType[$type] = $this->repository->findByType($type);
        }
    }
}
