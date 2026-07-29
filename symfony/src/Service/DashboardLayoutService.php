<?php
namespace App\Service;

use App\Dashboard\DashboardSections;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the admin-chosen dashboard section order + per-section visibility
 * (stored in the flat `setting` table) into an ordered list the dashboard and
 * the settings page render from.
 *
 * Mirrors ThemeService: caches the resolved list per request and implements
 * ResetInterface so an admin's layout change takes effect on the next request
 * without waiting for a FrankenPHP worker recycle.
 */
final class DashboardLayoutService implements ResetInterface
{
    /** @var list<array{key: string, visible: bool}>|null */
    private ?array $cache = null;

    public function __construct(private readonly ConfigService $config) {}

    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * @return list<array{key: string, visible: bool}>
     */
    public function resolve(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $order = $this->orderedKeys();

        $out = [];
        foreach ($order as $key) {
            $out[] = [
                'key'     => $key,
                'visible' => $this->config->get('dashboard_hide_' . $key) !== '1',
            ];
        }

        return $this->cache = $out;
    }

    /**
     * Stored order, sanitized: unknown/duplicate keys dropped, then any known
     * section missing from the stored order appended in default order. So a
     * section added in a future release auto-appears instead of vanishing.
     *
     * @return list<string>
     */
    private function orderedKeys(): array
    {
        $stored = (string) $this->config->get('dashboard_section_order');
        $keys = [];
        foreach (explode(',', $stored) as $raw) {
            $k = trim($raw);
            if ($k !== '' && DashboardSections::isValid($k) && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        foreach (DashboardSections::DEFAULT_ORDER as $k) {
            if (!in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        return $keys;
    }
}
