<?php

namespace App\Service;

use App\Entity\ServiceInstance;
use App\Service\Media\DelugeClient;
use App\Service\Media\HoundarrClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\TransmissionClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\UnifiClient;
use App\Service\Media\UnraidClient;
use App\Service\Media\Usenet\NzbgetClient;
use App\Service\Media\Usenet\SabnzbdClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tests third-party service availability.
 *
 * Two flavors:
 *  - isHealthy() returns a cached ?bool — used by topbar/dashboard widgets
 *    that poll often. Null means "not configured" (no URL/key in DB), so
 *    the UI can render a neutral state instead of a fake "down" badge.
 *  - diagnose() probes the URL directly to categorize WHY the service is
 *    down (network / auth / forbidden / not_found / server_error / ...) so
 *    the admin "Test connection" button can return an actionable hint
 *    without leaking internal stack traces.
 */
class HealthService implements ResetInterface
{
    private const CACHE_TTL = 10;

    /**
     * Pool key of the cache "generation" — a random token mixed into every
     * status key. invalidate() just drops it: the next read mints a new one,
     * which orphans every previous entry at once (they expire by TTL anyway)
     * without having to enumerate per-instance slugs.
     */
    private const GEN_KEY = 'health.status.gen';

    /** @var array<string, array{result: array{status: ?string, latencyMs: ?int}, at: int}> */
    private array $statusCache = [];

    /** Per-request memo of the pool generation token. */
    private ?string $generation = null;

    public function __construct(
        private readonly RadarrClient      $radarr,
        private readonly SonarrClient      $sonarr,
        private readonly ProwlarrClient    $prowlarr,
        private readonly JellyseerrClient  $jellyseerr,
        private readonly QBittorrentClient $qbittorrent,
        private readonly TmdbClient        $tmdb,
        private readonly ?ConfigService    $config = null,
        private readonly ?ServiceHealthCache $serviceHealthCache = null,
        private readonly ?ServiceInstanceProvider $instances = null,
        // #20 — Usenet download clients. Nullable + last so the legacy test
        // constructors (which pass the six core clients positionally) keep
        // working without each having to provide a fake SAB/NZBGet.
        private readonly ?SabnzbdClient    $sabnzbd = null,
        private readonly ?NzbgetClient     $nzbget = null,
        // Tautulli (current Plex activity) — nullable + last for the same
        // legacy-test-constructor reason as the Usenet clients above.
        private readonly ?TautulliClient   $tautulli = null,
        // Unraid (server monitoring widget) — nullable + last, same
        // legacy-test-constructor reason as the Usenet clients above.
        private readonly ?UnraidClient     $unraid = null,
        // Shared status cache (cache.app). Without it the 10 s memo lives
        // only in $statusCache, which classic-mode FrankenPHP discards with
        // the request — every topbar poll then re-pings every service.
        // Nullable + last for the legacy-test-constructor reason above.
        private readonly ?CacheInterface   $statusPool = null,
        // Houndarr (dashboard stat tile) — nullable + last, same
        // legacy-test-constructor reason as the clients above.
        private readonly ?HoundarrClient   $houndarr = null,
        // Deluge (#deluge-tab) — nullable + last, same legacy-test-constructor
        // reason as the clients above.
        private readonly ?DelugeClient     $deluge = null,
        // UniFi (network monitoring widget) — nullable + last, same
        // legacy-positional-constructor stance as Unraid/Houndarr.
        private readonly ?UnifiClient      $unifi = null,
        // Transmission — nullable + last for the same legacy-test-constructor
        // reason as the clients above.
        private readonly ?TransmissionClient $transmission = null,
    ) {}

    /**
     * Bucket a successful ping's round-trip (ms) into a latency status.
     * Thresholds: <=750 up, <=2000 slow, otherwise very_slow. The 2000 edge
     * counts as slow so the boundary is unambiguous.
     */
    public static function classifyLatency(int $ms): string
    {
        return match (true) {
            $ms <= 750  => 'up',
            $ms <= 2000 => 'slow',
            default     => 'very_slow',
        };
    }

    /**
     * Returns true (up), false (down), or null (not configured — no URL/key
     * in DB). Cached for CACHE_TTL seconds so the topbar can poll every few
     * seconds without hammering upstreams. Unconfigured services are NOT
     * pinged at all — that avoids 4 s timeouts and warning-log spam every
     * poll for users who only enabled a subset of the stack.
     *
     * v1.1.0 — $instanceSlug scopes the cache + ping to a specific
     * Radarr/Sonarr instance. Without it we ping the autowired client's
     * current binding (= the default instance, or whatever the request
     * subscriber bound). With it, we briefly bind the named instance for
     * the ping so two instances of the same type cache independently
     * (otherwise a broken Radarr 4K would mark Radarr 1 down too).
     */
    public function isHealthy(string $service, ?string $instanceSlug = null): ?bool
    {
        return match ($this->statusFor($service, $instanceSlug)['status']) {
            'up', 'slow', 'very_slow' => true,
            'down', 'degraded'        => false,
            default                   => null, // not configured / unknown
        };
    }

    /**
     * Richer health probe for the dashboard widget: returns a status word plus
     * a latency reading. Cached CACHE_TTL seconds in-process, same as the
     * legacy bool path (which now delegates here).
     *
     *  - not configured        → ['status' => null, ...]  (caller drops the chip)
     *  - circuit breaker open   → 'degraded' (stale cached verdict, no live probe)
     *  - live ping fails        → 'down' (timeout / refused / auth all surface here)
     *  - live ping succeeds     → up / slow / very_slow by round-trip latency
     *
     * @return array{status: ?string, latencyMs: ?int}
     */
    public function statusFor(string $service, ?string $instanceSlug = null): array
    {
        $key = $instanceSlug !== null ? $service . ':' . $instanceSlug : $service;
        $now = time();
        if (isset($this->statusCache[$key]) && ($now - $this->statusCache[$key]['at']) < self::CACHE_TTL) {
            return $this->statusCache[$key]['result'];
        }

        // Shared pool first (cache.app) — one probe sweep per TTL for the
        // whole install instead of per request/tab. Falls back to a direct
        // probe when no pool is wired (legacy test constructors).
        $result = $this->statusPool !== null
            ? $this->statusPool->get($this->poolKey($key), function (ItemInterface $item) use ($service, $instanceSlug): array {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->computeStatus($service, $instanceSlug);
            })
            : $this->computeStatus($service, $instanceSlug);

        return $this->remember($key, $result, $now);
    }

    /**
     * The uncached probe: configured check → circuit breaker → live ping,
     * classified by round-trip latency. Extracted from statusFor() so the
     * shared-pool path and the pool-less legacy path run the same logic.
     *
     * @return array{status: ?string, latencyMs: ?int}
     */
    private function computeStatus(string $service, ?string $instanceSlug): array
    {
        // Unconfigured services are never pinged (issue #9). Skipped when no
        // ConfigService is wired (legacy test paths).
        if ($this->config !== null && !$this->isConfigured($service)) {
            return ['status' => null, 'latencyMs' => null];
        }

        // Circuit breaker open → we'd serve a stale cached-down verdict without
        // a live probe. That's "cached stale data", not a freshly confirmed
        // outage → degraded. radarr/sonarr mark the breaker WITH the instance
        // slug, so per-instance degraded detection lines up here.
        if ($this->serviceHealthCache?->isDown($service, $instanceSlug)) {
            return ['status' => 'degraded', 'latencyMs' => null];
        }

        // Live probe — time it with a monotonic clock.
        $start = hrtime(true);
        try {
            $ok = $this->pingFor($service, $instanceSlug);
        } catch (\Throwable) {
            $ok = false;
        }
        $latencyMs = (int) round((hrtime(true) - $start) / 1e6);

        if ($ok === null) {
            return ['status' => null, 'latencyMs' => null];
        }
        if ($ok === false) {
            return ['status' => 'down', 'latencyMs' => null];
        }

        return ['status' => self::classifyLatency($latencyMs), 'latencyMs' => $latencyMs];
    }

    /**
     * Pool key for one service/instance status. Namespaced by the current
     * generation token; ':' (PSR-6 reserved) in instance-scoped keys becomes
     * '.'. Only called when $statusPool is non-null.
     */
    private function poolKey(string $key): string
    {
        $this->generation ??= $this->statusPool->get(self::GEN_KEY, fn (): string => bin2hex(random_bytes(4)));

        return 'health.status.' . $this->generation . '.' . str_replace(':', '.', $key);
    }

    /**
     * @param array{status: ?string, latencyMs: ?int} $result
     * @return array{status: ?string, latencyMs: ?int}
     */
    private function remember(string $key, array $result, int $now): array
    {
        $this->statusCache[$key] = ['result' => $result, 'at' => $now];
        return $result;
    }

    private function pingFor(string $service, ?string $instanceSlug): ?bool
    {
        if ($instanceSlug !== null && $this->instances !== null) {
            $type = match ($service) {
                'radarr' => ServiceInstance::TYPE_RADARR,
                'sonarr' => ServiceInstance::TYPE_SONARR,
                default  => null,
            };
            if ($type !== null) {
                $instance = $this->instances->getBySlug($type, $instanceSlug);
                if ($instance === null) {
                    return null; // unknown slug → not_configured-ish
                }
                $client = $service === 'radarr'
                    ? $this->radarr->withInstance($instance)
                    : $this->sonarr->withInstance($instance);
                return $client->ping();
            }
        }
        return match ($service) {
            'radarr'      => $this->radarr->ping(),
            'sonarr'      => $this->sonarr->ping(),
            'prowlarr'    => $this->prowlarr->ping(),
            'jellyseerr'  => $this->jellyseerr->ping(),
            'qbittorrent' => $this->qbittorrent->ping(),
            'deluge'      => $this->deluge?->ping() ?? false,
            'tmdb'        => $this->tmdb->ping(),
            // SABnzbd's ping() now probes mode=queue (key-aware) and runs
            // through the client's circuit breaker, so a downed SABnzbd
            // short-circuits instead of re-timing-out on every poll. The page
            // banner still uses diagnose() to tell auth vs host_whitelist apart.
            'sabnzbd'     => $this->sabnzbd?->ping() ?? false,
            'nzbget'      => $this->nzbget?->ping() ?? false,
            'tautulli'    => $this->tautulli?->ping() ?? false,
            'unraid'      => $this->unraid?->ping() ?? false,
            'houndarr'    => $this->houndarr?->ping() ?? false,
            'unifi'       => $this->unifi?->ping() ?? false,
            'transmission' => $this->transmission?->ping() ?? false,
            default       => true,
        };
    }

    /**
     * True if the given service has all the credentials it needs to be
     * pinged. Used by isHealthy() to skip uncconfigured services and by the
     * topbar / dashboard to render a "not configured" state. Returns true
     * when no ConfigService is wired, so the legacy test setup keeps
     * working without each test having to provide a fake config.
     */
    /**
     * Services that expose an explicit on/off switch in /admin/settings
     * (issue #15). Radarr/Sonarr are absent on purpose — they enable/disable
     * per instance via the `enabled` flag on `service_instance`.
     */
    public const TOGGLEABLE_SERVICES = ['prowlarr', 'jellyseerr', 'qbittorrent', 'deluge', 'transmission', 'tmdb', 'sabnzbd', 'nzbget', 'tautulli', 'unraid', 'houndarr', 'unifi'];

    /** Brand colors for the health chips — single source for dashboard + topbar. */
    private const SERVICE_COLORS = [
        'radarr'      => '#FFC230',
        'sonarr'      => '#00CCFF',
        'prowlarr'    => '#E88D1A',
        'jellyseerr'  => '#a259ff',
        'qbittorrent' => '#2f67ba',
        'deluge'      => '#3e7bbf',
        'transmission' => '#d7302f',
        'sabnzbd'     => '#fbc531',
        'nzbget'      => '#54c754',
        'tmdb'        => '#01B4E4',
        'tautulli'    => '#e5a00d',
        'unraid'      => '#f15a2c',
        'houndarr'    => '#c2703d',
        'unifi'       => '#006fff',
    ];

    /**
     * Services-health chip list — the ONE list both the dashboard section and
     * the topbar popover render (they drifted apart when built separately).
     * Radarr/Sonarr expand to one chip per enabled instance; unconfigured
     * services (status null) are dropped; Unraid is admin-gated by the caller.
     *
     * @return list<array{id: string, name: string, status: string, latencyMs: ?int, color: string}>
     */
    public function chips(bool $includeUnraid = false): array
    {
        $chips = [];

        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR] as $type) {
            foreach ($this->instances?->getEnabled($type) ?? [] as $inst) {
                try {
                    $s = $this->statusFor($type, $inst->getSlug());
                } catch (\Throwable) {
                    $s = ['status' => 'down', 'latencyMs' => null];
                }
                if ($s['status'] === null) continue;
                $chips[] = ['id' => $type, 'name' => $inst->getName(), 'status' => $s['status'], 'latencyMs' => $s['latencyMs'], 'color' => self::SERVICE_COLORS[$type]];
            }
        }

        $labels = ['prowlarr' => 'Prowlarr', 'jellyseerr' => 'Seerr', 'qbittorrent' => 'qBittorrent', 'deluge' => 'Deluge', 'transmission' => 'Transmission', 'sabnzbd' => 'SABnzbd', 'nzbget' => 'NZBGet', 'tmdb' => 'TMDb', 'tautulli' => 'Tautulli', 'houndarr' => 'Houndarr'];
        if ($includeUnraid) {
            $labels['unraid'] = 'Unraid';
            $labels['unifi']  = 'UniFi';
        }
        foreach ($labels as $service => $label) {
            try {
                $s = $this->statusFor($service);
            } catch (\Throwable) {
                $s = ['status' => null, 'latencyMs' => null];
            }
            if ($s['status'] === null) continue;
            $chips[] = ['id' => $service, 'name' => $label, 'status' => $s['status'], 'latencyMs' => $s['latencyMs'], 'color' => self::SERVICE_COLORS[$service]];
        }

        return $chips;
    }

    public function isConfigured(string $service): bool
    {
        if ($this->config === null) {
            return true;
        }
        // Explicit kill switch. A missing `<service>_enabled` row means the
        // toggle was never touched → fall through to the credential check, so
        // existing installs are unaffected. Only an explicit '0' disables.
        if (in_array($service, self::TOGGLEABLE_SERVICES, true)
            && $this->config->get($service . '_enabled') === '0') {
            return false;
        }
        return match ($service) {
            // v1.1.0 — radarr/sonarr moved to service_instance. "Configured"
            // means at least one enabled instance exists.
            'radarr' =>
                $this->instances?->hasAnyEnabled(ServiceInstance::TYPE_RADARR) ?? false,
            'sonarr' =>
                $this->instances?->hasAnyEnabled(ServiceInstance::TYPE_SONARR) ?? false,
            'prowlarr' =>
                $this->config->has('prowlarr_url') && $this->config->has('prowlarr_api_key'),
            'jellyseerr' =>
                $this->config->has('jellyseerr_url') && $this->config->has('jellyseerr_api_key'),
            'tmdb' =>
                $this->config->has('tmdb_api_key'),
            // qBittorrent — only the URL is required. Empty user/password
            // is a legitimate "reverse proxy" setup (qui, traefik forward
            // auth, …) where the proxy injects credentials on every call,
            // so the upstream qBit doesn't need a /auth/login round-trip.
            // See issue #10.
            'qbittorrent' =>
                $this->config->has('qbittorrent_url'),
            // Deluge — URL only, same reverse-proxy stance as qBittorrent:
            // an empty password is a legitimate "the proxy injects the
            // session" setup, not a misconfiguration.
            'deluge' =>
                $this->config->has('deluge_url'),
            // SABnzbd needs URL + API key. NZBGet only needs the URL — user /
            // password are optional (reverse-proxy or auth-disabled LAN setup),
            // mirroring qBittorrent's reverse-proxy stance.
            'sabnzbd' =>
                $this->config->has('sabnzbd_url') && $this->config->has('sabnzbd_api_key'),
            'nzbget' =>
                $this->config->has('nzbget_url'),
            // Tautulli needs both the URL and the API key (every command,
            // including get_activity, is apikey-authenticated).
            'tautulli' =>
                $this->config->has('tautulli_url') && $this->config->has('tautulli_api_key'),
            // Unraid needs both the URL and a (read-only scoped) API key.
            'unraid' =>
                $this->config->has('unraid_url') && $this->config->has('unraid_api_key'),
            // Houndarr needs both the URL and the (single, widget-scoped) API key.
            'houndarr' =>
                $this->config->has('houndarr_url') && $this->config->has('houndarr_api_key'),
            // UniFi needs the console URL and a local API key (Network 9.0+).
            'unifi' =>
                $this->config->has('unifi_url') && $this->config->has('unifi_api_key'),
            // Transmission — URL-only, same reverse-proxy-friendly stance as
            // qBittorrent/Deluge (empty user/password is a legitimate setup).
            'transmission' =>
                $this->config->has('transmission_url'),
            default => true,
        };
    }

    /**
     * Invalidate the cache — useful after a reconfiguration via admin.
     *
     * Clears both the in-process isHealthy() cache and the cross-request
     * filesystem "service down" cache (ServiceHealthCache) so that a manual
     * "Test connection" click recovers instantly from a flagged-down state.
     */
    public function invalidate(?string $service = null): void
    {
        // Shared pool: rotate the generation token — every pooled status
        // (including per-instance ones we can't enumerate here) becomes
        // unreachable at once. Cheaper than tracking keys, and the orphans
        // expire on their own within CACHE_TTL. Scoped invalidation isn't
        // worth the bookkeeping at a 10 s TTL.
        if ($this->statusPool !== null) {
            $this->statusPool->delete(self::GEN_KEY);
            $this->generation = null;
        }

        if ($service === null) {
            $this->statusCache = [];
            if ($this->serviceHealthCache !== null) {
                foreach (['radarr', 'sonarr', 'prowlarr', 'jellyseerr', 'qbittorrent', 'deluge', 'transmission', 'tmdb', 'sabnzbd', 'nzbget', 'tautulli', 'unraid', 'houndarr', 'unifi'] as $svc) {
                    $this->serviceHealthCache->clear($svc);
                }
            }
        } else {
            unset($this->statusCache[$service]);
            $this->serviceHealthCache?->clear($service);
        }
    }

    /**
     * FrankenPHP worker mode — Symfony's services_resetter calls reset()
     * between requests (this service is auto-tagged kernel.reset via the
     * ResetInterface autoconfiguration). Drop the per-request in-process
     * memo so one request's health verdicts and generation token can't bleed
     * into the next. The cross-request shared pool (cache.app) is left intact
     * on purpose — that's the whole point of the shared 10 s cache; it self-
     * expires by TTL and rotates via invalidate() on reconfiguration.
     */
    public function reset(): void
    {
        $this->statusCache = [];
        $this->generation  = null;
    }

    /**
     * Probe a service directly and return a categorized diagnosis the admin
     * UI can show. Returns ['ok' => bool, 'category' => string, 'http' => ?int].
     * Categories: ok / unconfigured / network / auth / forbidden / not_found
     * / server_error / unknown.
     *
     * $overrides lets the caller test values that aren't yet in DB — typical
     * use case: the admin types a new URL/key in the form and clicks "Test"
     * before saving. Empty/missing overrides fall back to ConfigService so
     * a non-edited password keeps its stored value.
     *
     * @param array<string, ?string>|null $overrides
     */
    public function diagnose(string $service, ?array $overrides = null): array
    {
        if ($this->config === null && $overrides === null) {
            return ['ok' => false, 'category' => 'unknown', 'http' => null];
        }

        // Passive diagnosis (no overrides = not a "Test connection" click):
        // honour the circuit breaker. If a failed call already flagged this
        // client down this window, don't re-probe — it would just wait out the
        // connect timeout and lag the page render. A "Test connection" always
        // probes fresh (the admin just changed the config).
        if ($overrides === null && $this->serviceHealthCache?->isDown($service)) {
            return ['ok' => false, 'category' => 'network', 'http' => null];
        }

        $probe = $this->probeFor($service, $overrides);
        if ($probe === null) {
            return ['ok' => false, 'category' => 'unconfigured', 'http' => null];
        }

        $resp = $this->httpProbe(
            $probe['url'],
            $probe['headers'] ?? [],
            $probe['method'] ?? 'GET',
            $probe['body']    ?? null,
            $probe['insecure'] ?? false,
        );

        return $this->diagnoseFromResponse($resp, $service);
    }

    /**
     * Pure mapping from a (http, curlError, body) tuple to a diagnosis.
     * Public for testability — the curl-side is harder to mock.
     *
     * @param array{http: ?int, body: ?string, err: string} $resp
     * @return array{ok: bool, category: string, http: ?int}
     */
    public function diagnoseFromResponse(array $resp, string $service): array
    {
        $http = $resp['http'] ?? null;
        $err  = $resp['err']  ?? '';
        $body = $resp['body'] ?? null;

        if ($err !== '') {
            return ['ok' => false, 'category' => 'network', 'http' => null];
        }
        // qBittorrent: a wrong username/password returns HTTP 200 with the
        // literal body "Fails." — without this special case we'd mistake an
        // auth failure for a healthy response.
        if ($service === 'qbittorrent' && $http === 200 && is_string($body) && trim($body) === 'Fails.') {
            return ['ok' => false, 'category' => 'auth', 'http' => $http];
        }
        // Deluge: deluge-web answers HTTP 200 for everything — the outcome is
        // in the JSON-RPC envelope. auth.login with a wrong password returns
        // {"result": false}; an error object also means failure. In
        // reverse-proxy mode the probe calls web.get_config, whose success
        // result is a (truthy) dict.
        if ($service === 'deluge' && $http === 200 && is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if (($decoded['error'] ?? null) !== null) {
                    return ['ok' => false, 'category' => 'auth', 'http' => $http];
                }
                if (($decoded['result'] ?? null) === false) {
                    return ['ok' => false, 'category' => 'auth', 'http' => $http];
                }
                return ['ok' => true, 'category' => 'ok', 'http' => $http];
            }
            return ['ok' => false, 'category' => 'unknown', 'http' => $http];
        }
        // SABnzbd answers 403 for BOTH a wrong API key and a host that isn't in
        // its host_whitelist (anti DNS-rebinding). Tell them apart so the admin
        // gets an actionable hint instead of a bare "forbidden".
        if ($service === 'sabnzbd' && $http === 403 && is_string($body)) {
            if (stripos($body, 'hostname') !== false) {
                return ['ok' => false, 'category' => 'host_whitelist', 'http' => $http];
            }
            if (stripos($body, 'api key') !== false) {
                return ['ok' => false, 'category' => 'auth', 'http' => $http];
            }
        }
        // Tautulli always answers HTTP 200, even on a bad apikey — the real
        // status lives in the JSON envelope ({"response":{"result":"error",
        // "message":"Invalid apikey"}}). Treat any non-"success" result as an
        // auth failure so the admin gets an actionable hint instead of a
        // misleading green check.
        if ($service === 'tautulli' && $http === 200 && is_string($body)) {
            $decoded = json_decode($body, true);
            $result  = is_array($decoded) && is_array($decoded['response'] ?? null)
                ? ($decoded['response']['result'] ?? null)
                : null;
            if ($result !== 'success') {
                return ['ok' => false, 'category' => 'auth', 'http' => $http];
            }
            return ['ok' => true, 'category' => 'ok', 'http' => $http];
        }
        // Transmission: a fresh/expired session always answers 409 with the
        // real X-Transmission-Session-Id in the response header — that IS a
        // healthy, reachable daemon, not a failure. A bad RPC password is a
        // separate 401, independent of the session-id handshake.
        if ($service === 'transmission' && $http === 409) {
            return ['ok' => true, 'category' => 'ok', 'http' => $http];
        }
        if ($http !== null && $http >= 200 && $http < 300) {
            return ['ok' => true, 'category' => 'ok', 'http' => $http];
        }
        if ($http === 401) return ['ok' => false, 'category' => 'auth',         'http' => $http];
        if ($http === 403) return ['ok' => false, 'category' => 'forbidden',    'http' => $http];
        if ($http === 404) return ['ok' => false, 'category' => 'not_found',    'http' => $http];
        if ($http !== null && $http >= 500) {
            return ['ok' => false, 'category' => 'server_error', 'http' => $http];
        }
        return ['ok' => false, 'category' => 'unknown', 'http' => $http];
    }

    /**
     * Build the probe parameters (URL, headers, method, body) for a given
     * service. Reads from $overrides first (form values not yet saved), then
     * falls back to ConfigService (last saved values). Returns null when the
     * service has no URL/credentials configured at all.
     *
     * @param array<string, ?string>|null $overrides
     * @return ?array{url: string, headers?: array<int,string>, method?: string, body?: string, insecure?: bool}
     */
    private function probeFor(string $service, ?array $overrides = null): ?array
    {
        // Pull the value from $overrides if present and non-empty, otherwise
        // from the saved config. This way the admin can type a new URL/key
        // and click Test without saving — and an empty override (browser
        // dropping a password field) gracefully falls back to DB.
        $get = function (string $key) use ($overrides): string {
            if (is_array($overrides) && array_key_exists($key, $overrides)) {
                $v = trim((string) ($overrides[$key] ?? ''));
                if ($v !== '') return $v;
            }
            return (string) ($this->config?->get($key) ?? '');
        };

        // For radarr / sonarr the saved URL + key live on the default
        // service_instance, not in `setting`. Build a per-service fallback
        // that walks overrides → instance → null.
        $getInstanceField = function (string $service, string $field) use ($overrides): string {
            $key = $service . '_' . $field;
            if (is_array($overrides) && array_key_exists($key, $overrides)) {
                $v = trim((string) ($overrides[$key] ?? ''));
                if ($v !== '') return $v;
            }
            $type = $service === 'radarr' ? ServiceInstance::TYPE_RADARR : ServiceInstance::TYPE_SONARR;
            $instance = $this->instances?->getDefault($type);
            if ($instance === null) return '';
            return $field === 'url' ? $instance->getUrl() : ($instance->getApiKey() ?? '');
        };

        switch ($service) {
            case 'radarr':
            case 'sonarr': {
                $url = $getInstanceField($service, 'url');
                $key = $getInstanceField($service, 'api_key');
                if ($url === '' || $key === '') return null;
                return [
                    'url'     => rtrim($url, '/') . '/api/v3/system/status',
                    'headers' => ['X-Api-Key: ' . $key, 'Accept: application/json'],
                ];
            }
            case 'prowlarr': {
                $url = $get('prowlarr_url');
                $key = $get('prowlarr_api_key');
                if ($url === '' || $key === '') return null;
                return [
                    'url'     => rtrim($url, '/') . '/api/v1/system/status',
                    'headers' => ['X-Api-Key: ' . $key, 'Accept: application/json'],
                ];
            }
            case 'jellyseerr': {
                $url = $get('jellyseerr_url');
                $key = $get('jellyseerr_api_key');
                if ($url === '' || $key === '') return null;
                return [
                    'url'     => rtrim($url, '/') . '/api/v1/settings/about',
                    'headers' => ['X-Api-Key: ' . $key, 'Accept: application/json'],
                ];
            }
            case 'tmdb': {
                $key = $get('tmdb_api_key');
                if ($key === '') return null;
                return [
                    'url'     => 'https://api.themoviedb.org/3/configuration?api_key=' . urlencode($key),
                    'headers' => ['Accept: application/json'],
                ];
            }
            case 'qbittorrent': {
                $url  = $get('qbittorrent_url');
                $user = $get('qbittorrent_user');
                $pass = $get('qbittorrent_password');
                if ($url === '') return null;

                // Reverse-proxy mode (issue #10): missing user OR password
                // means we shouldn't probe /auth/login because the body
                // would be empty and qBit would answer "Fails." A lightweight
                // GET /api/v2/app/version is enough to confirm reachability,
                // and the proxy will inject auth transparently. If qBit is
                // NOT actually behind a bypass-auth proxy, the call returns
                // 403 → diagnosed as `forbidden`, which is the right hint
                // for the user (they meant to fill creds and forgot one).
                if ($user === '' || $pass === '') {
                    return [
                        'url'     => rtrim($url, '/') . '/api/v2/app/version',
                        'headers' => ['Referer: ' . rtrim($url, '/')],
                    ];
                }

                return [
                    'url'     => rtrim($url, '/') . '/api/v2/auth/login',
                    'headers' => [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Referer: ' . rtrim($url, '/'),
                    ],
                    'method'  => 'POST',
                    'body'    => http_build_query(['username' => $user, 'password' => $pass]),
                ];
            }
            case 'deluge': {
                $url  = $get('deluge_url');
                $pass = $get('deluge_password');
                if ($url === '') return null;

                // Reverse-proxy mode (empty password): auth.login('') would
                // return result:false even when the proxy setup is fine, so
                // probe a session-authenticated method instead — the proxy
                // injects the session. Success result is a dict (truthy);
                // without a proxy deluge-web answers an error envelope,
                // which diagnoseFromResponse() maps to auth.
                $method = $pass === '' ? 'web.get_config' : 'auth.login';
                $params = $pass === '' ? [] : [$pass];
                return [
                    'url'     => rtrim($url, '/') . '/json',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json'],
                    'method'  => 'POST',
                    'body'    => json_encode(['method' => $method, 'params' => $params, 'id' => 1]),
                ];
            }
            case 'sabnzbd': {
                $url = $get('sabnzbd_url');
                $key = $get('sabnzbd_api_key');
                if ($url === '' || $key === '') return null;
                // mode=queue actually validates the key — mode=version does NOT
                // (SABnzbd answers 200 for any key on version). With queue: good
                // key → 200; bad key → 403 "API Key Incorrect"; a host not in
                // SABnzbd's host_whitelist → 403 "Access denied - hostname …".
                // diagnoseFromResponse() tells those two 403s apart by body.
                return [
                    'url' => rtrim($url, '/') . '/api?mode=queue&output=json&apikey=' . urlencode($key),
                ];
            }
            case 'tautulli': {
                $url = $get('tautulli_url');
                $key = $get('tautulli_api_key');
                if ($url === '' || $key === '') return null;
                // get_activity is the same read-only command the widget uses.
                // It also validates the key: a bad apikey returns HTTP 200 with
                // result:"error", which diagnoseFromResponse() maps to `auth`.
                return [
                    'url'     => rtrim($url, '/') . '/api/v2?' . http_build_query(['apikey' => $key, 'cmd' => 'get_activity']),
                    'headers' => ['Accept: application/json'],
                ];
            }
            case 'nzbget': {
                $url  = $get('nzbget_url');
                $user = $get('nzbget_user');
                $pass = $get('nzbget_password');
                if ($url === '') return null;
                $headers = ['Content-Type: application/json'];
                if ($user !== '' || $pass !== '') {
                    $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
                }
                return [
                    'url'     => rtrim($url, '/') . '/jsonrpc',
                    'headers' => $headers,
                    'method'  => 'POST',
                    'body'    => (string) json_encode(['version' => '1.1', 'id' => 1, 'method' => 'version', 'params' => []]),
                ];
            }
            case 'unraid': {
                $url = $get('unraid_url');
                $key = $get('unraid_api_key');
                if ($url === '' || $key === '') return null;
                // Trivial read-only GraphQL query; a bad key gets a 401/403.
                return [
                    'url'      => rtrim($url, '/') . '/graphql',
                    'headers'  => ['x-api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
                    'method'   => 'POST',
                    'body'     => (string) json_encode(['query' => UnraidClient::QUERY_PING]),
                    // LAN Unraid GUIs commonly run self-signed certs.
                    'insecure' => $get('unraid_skip_tls_verify') === '1',
                ];
            }
            case 'houndarr': {
                $url = $get('houndarr_url');
                $key = $get('houndarr_api_key');
                if ($url === '' || $key === '') return null;
                // The single endpoint the Houndarr key authorizes; a bad or
                // revoked key answers 401 → diagnosed as `auth`.
                return [
                    'url'     => rtrim($url, '/') . '/api/v1/widget',
                    'headers' => ['X-Api-Key: ' . $key, 'Accept: application/json'],
                ];
            }
            case 'unifi': {
                $url  = $get('unifi_url');
                $key  = $get('unifi_api_key');
                $site = trim($get('unifi_site'));
                if ($url === '' || $key === '') return null;
                // Same read-only endpoint the widget uses; a bad key answers
                // 401 → diagnosed as `auth`.
                return [
                    'url'      => rtrim($url, '/') . '/proxy/network/api/s/'
                        . rawurlencode($site !== '' ? $site : 'default') . UnifiClient::PATH_HEALTH,
                    'headers'  => ['X-API-KEY: ' . $key, 'Accept: application/json'],
                    // UniFi OS consoles ship a self-signed cert by default.
                    'insecure' => $get('unifi_skip_tls_verify') === '1',
                ];
            }
            case 'transmission': {
                $url  = $get('transmission_url');
                $user = $get('transmission_user');
                $pass = $get('transmission_password');
                if ($url === '') return null;
                // Deliberately no X-Transmission-Session-Id header: a fresh
                // client always gets HTTP 409 back with the real token, and
                // that 409 IS the "reachable" signal diagnoseFromResponse()
                // is looking for — no retry needed just to test connectivity.
                $headers = ['Content-Type: application/json'];
                if ($user !== '') {
                    $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
                }
                return [
                    'url'     => rtrim($url, '/') . '/transmission/rpc',
                    'headers' => $headers,
                    'method'  => 'POST',
                    'body'    => (string) json_encode(['method' => 'session-get', 'arguments' => ['fields' => ['version']], 'tag' => 1]),
                ];
            }
            default:
                return null;
        }
    }

    /**
     * Validate a target URL before issuing the probe. Reject anything that
     * isn't HTTP(S), then resolve the hostname and reject link-local IPs
     * (169.254.0.0/16) which are how AWS / GCP / Azure expose unauthenticated
     * cloud-metadata endpoints. Returns null when the URL is acceptable, or
     * a short reason string when it must be blocked.
     *
     * Important: we deliberately do NOT block the rest of RFC1918 — Prismarr
     * legitimately talks to LAN services like Radarr on 192.168.x or 10.x.
     * The SSRF surface here is mostly an admin-supplied URL, so the goal is
     * "bar the violent exploits" (file://, gopher://, cloud-metadata) rather
     * than "fully prevent intra-LAN scanning".
     */
    public static function urlBlockedReason(string $url): ?string
    {
        // parse_url returns false on a seriously malformed URL — notably a
        // port outside 0-65535 (PHP 7+). Surface that as its own reason so
        // the admin sees "malformed" instead of a misleading "scheme".
        if (parse_url($url) === false) {
            return 'malformed';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'scheme';
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 'host';
        }
        // Normalize evasions: a trailing dot (rooted FQDN / numeric) and IPv6
        // literal brackets, both of which slip past FILTER_VALIDATE_IP yet still
        // resolve at curl time.
        $host = rtrim($host, '.');
        $host = trim($host, '[]');

        // Resolve hostname to IPs. gethostbynamel returns the A records
        // (IPv4); IPv6 link-local literals are caught further down via the
        // IP-literal short-circuit.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolved = gethostbynamel($host);
            $ips = is_array($resolved) ? $resolved : [];
        }
        foreach ($ips as $ip) {
            // An IPv4-mapped IPv6 literal (::ffff:169.254.169.254) must be
            // checked as the embedded IPv4, or the metadata block is bypassed.
            $ip = self::unmapIpv4($ip);
            if (str_starts_with($ip, '169.254.')) {
                return 'link-local';
            }
            // IPv6 link-local addresses (fe80::/10) and IPv6 cloud metadata
            // (fd00:ec2::254 on AWS) — blocked too. Coarse check is enough
            // because RFC1918 equivalent ULAs are not common for LAN services.
            $lower = strtolower($ip);
            if (str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fd00:ec2')) {
                return 'link-local';
            }
        }
        return null;
    }

    /** ::ffff:a.b.c.d (IPv4-mapped IPv6) → a.b.c.d, so it can't dodge the checks. */
    private static function unmapIpv4(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16
            && str_starts_with($bin, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $v4 = @inet_ntop(substr($bin, 12));
            return $v4 !== false ? $v4 : $ip;
        }
        return $ip;
    }

    /**
     * Issue the curl request and return a normalized response array. Kept
     * private so we can swap the implementation later (e.g. Symfony's
     * HttpClient) without changing diagnose() callers.
     *
     * @param array<int, string> $headers
     * @return array{http: ?int, body: ?string, err: string}
     */
    private function httpProbe(string $url, array $headers, string $method, ?string $body, bool $insecure = false): array
    {
        // SSRF guard #1 — reject before opening the socket. Cuts off
        // file:// / gopher:// / dict:// schemes that curl would otherwise
        // happily honour, plus link-local IPs used by AWS/GCP/Azure metadata.
        if (($reason = self::urlBlockedReason($url)) !== null) {
            return ['http' => null, 'body' => null, 'err' => 'blocked: ' . $reason];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['http' => null, 'body' => null, 'err' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // SSRF guard #2 — even if a follow-up redirect somehow flips
            // the protocol or destination, curl is locked to HTTP(S) only.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $resBody = curl_exec($ch);
        $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        return [
            'http' => $http > 0 ? $http : null,
            'body' => is_string($resBody) ? $resBody : null,
            'err'  => $err,
        ];
    }
}
