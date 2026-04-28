<?php

namespace App\Service;

use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;

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
class HealthService
{
    private const CACHE_TTL = 10;

    /** @var array<string, array{ok: ?bool, at: int}> */
    private array $cache = [];

    public function __construct(
        private readonly RadarrClient      $radarr,
        private readonly SonarrClient      $sonarr,
        private readonly ProwlarrClient    $prowlarr,
        private readonly JellyseerrClient  $jellyseerr,
        private readonly QBittorrentClient $qbittorrent,
        private readonly TmdbClient        $tmdb,
        private readonly ?ConfigService    $config = null,
        private readonly ?ServiceHealthCache $serviceHealthCache = null,
    ) {}

    /**
     * Returns true (up), false (down), or null (not configured — no URL/key
     * in DB). Cached for CACHE_TTL seconds so the topbar can poll every few
     * seconds without hammering upstreams. Unconfigured services are NOT
     * pinged at all — that avoids 4 s timeouts and warning-log spam every
     * poll for users who only enabled a subset of the stack.
     */
    public function isHealthy(string $service): ?bool
    {
        $now = time();
        if (isset($this->cache[$service]) && ($now - $this->cache[$service]['at']) < self::CACHE_TTL) {
            return $this->cache[$service]['ok'];
        }

        // Short-circuit on unconfigured services: don't ping, don't log.
        // Skipped when no ConfigService is wired (legacy test paths).
        if ($this->config !== null && !$this->isConfigured($service)) {
            $this->cache[$service] = ['ok' => null, 'at' => $now];
            return null;
        }

        $ok = match ($service) {
            'radarr'      => $this->radarr->ping(),
            'sonarr'      => $this->sonarr->ping(),
            'prowlarr'    => $this->prowlarr->ping(),
            'jellyseerr'  => $this->jellyseerr->ping(),
            'qbittorrent' => $this->qbittorrent->ping(),
            'tmdb'        => $this->tmdb->ping(),
            default       => true,
        };

        $this->cache[$service] = ['ok' => $ok, 'at' => $now];
        return $ok;
    }

    /**
     * True if the given service has all the credentials it needs to be
     * pinged. Used by isHealthy() to skip uncconfigured services and by the
     * topbar / dashboard to render a "not configured" state. Returns true
     * when no ConfigService is wired, so the legacy test setup keeps
     * working without each test having to provide a fake config.
     */
    public function isConfigured(string $service): bool
    {
        if ($this->config === null) {
            return true;
        }
        return match ($service) {
            'radarr', 'sonarr', 'prowlarr' =>
                $this->config->has($service . '_url') && $this->config->has($service . '_api_key'),
            'jellyseerr' =>
                $this->config->has('jellyseerr_url') && $this->config->has('jellyseerr_api_key'),
            'tmdb' =>
                $this->config->has('tmdb_api_key'),
            'qbittorrent' =>
                $this->config->has('qbittorrent_url')
                && $this->config->has('qbittorrent_user')
                && $this->config->has('qbittorrent_password'),
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
        if ($service === null) {
            $this->cache = [];
            if ($this->serviceHealthCache !== null) {
                foreach (['radarr', 'sonarr', 'prowlarr', 'jellyseerr', 'qbittorrent', 'tmdb'] as $svc) {
                    $this->serviceHealthCache->clear($svc);
                }
            }
        } else {
            unset($this->cache[$service]);
            $this->serviceHealthCache?->clear($service);
        }
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

        $probe = $this->probeFor($service, $overrides);
        if ($probe === null) {
            return ['ok' => false, 'category' => 'unconfigured', 'http' => null];
        }

        $resp = $this->httpProbe(
            $probe['url'],
            $probe['headers'] ?? [],
            $probe['method'] ?? 'GET',
            $probe['body']    ?? null,
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
     * @return ?array{url: string, headers?: array<int,string>, method?: string, body?: string}
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

        switch ($service) {
            case 'radarr':
            case 'sonarr':
            case 'prowlarr': {
                $url = $get($service . '_url');
                $key = $get($service . '_api_key');
                if ($url === '' || $key === '') return null;
                $version = $service === 'prowlarr' ? 'v1' : 'v3';
                return [
                    'url'     => rtrim($url, '/') . '/api/' . $version . '/system/status',
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
                if ($url === '' || $user === '' || $pass === '') return null;
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
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'scheme';
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 'host';
        }

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

    /**
     * Issue the curl request and return a normalized response array. Kept
     * private so we can swap the implementation later (e.g. Symfony's
     * HttpClient) without changing diagnose() callers.
     *
     * @param array<int, string> $headers
     * @return array{http: ?int, body: ?string, err: string}
     */
    private function httpProbe(string $url, array $headers, string $method, ?string $body): array
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
