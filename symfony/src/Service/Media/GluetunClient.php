<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Client for the Gluetun control API (HTTP Control Server).
 * Optional service — if no Gluetun URL is configured, calls return null
 * without exception (Gluetun is not required to use Prismarr).
 *
 * Issue #131 — Gluetun is a multi-instance service (`service_instance` rows
 * of type `gluetun`, URL + optional API key) and each qBittorrent instance
 * may link to one. MultiInstanceBinderSubscriber binds the autowired client
 * to the Gluetun linked to the qBittorrent instance of the current
 * `/qbittorrent/{slug}/…` route — bound to null when it has none, which
 * gives the VPN card its empty state. Unbound, the client follows the
 * Gluetun linked to the default qBittorrent instance, as the single flat
 * Gluetun did. withInstance() returns an independent clone.
 * Docs: https://github.com/qdm12/gluetun-wiki/blob/main/setup/advanced/control-server.md
 */
class GluetunClient implements ResetInterface
{
    /** Short cache for /publicip/ip (rarely changes, but it's a light call). */
    private ?array $publicIpCache = null;
    private float  $publicIpCacheAt = 0.0;
    private const PUBLIC_IP_TTL = 30.0;

    /** VPN status cache (running/stopped) — stable across VPN reconnections. */
    private ?string $statusCache = null;
    private float   $statusCacheAt = 0.0;
    private const STATUS_TTL = 10.0;

    /** Forwarded port cache (may change on every VPN reconnection but rarely does). */
    private ?int $portCache = null;
    private float $portCacheAt = 0.0;
    private const PORT_TTL = 10.0;

    private ?string $baseUrl = null;
    private string $apiKey = '';
    private bool $configLoaded = false;

    /** Gluetun instance this client talks to (meaningful once $bound). */
    private ?ServiceInstance $instance = null;
    private bool $bound = false;

    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly LoggerInterface $logger,
    ) {}

    /** Clone bound to Gluetun instance $instance (null = no Gluetun). */
    public function withInstance(?ServiceInstance $instance): self
    {
        $clone = clone $this;
        $clone->bindInstance($instance);
        return $clone;
    }

    /** Bind this client to $instance for the rest of the request (null = no Gluetun). */
    public function bindInstance(?ServiceInstance $instance): void
    {
        if ($instance !== null && $instance->getType() !== ServiceInstance::TYPE_GLUETUN) {
            throw new \InvalidArgumentException(sprintf(
                'GluetunClient expects a gluetun instance, got "%s".',
                $instance->getType(),
            ));
        }
        $this->reset();
        $this->instance = $instance;
        $this->bound    = true;
    }

    /** Gluetun instance targeted by this client, or null when there is none. */
    public function getInstance(): ?ServiceInstance
    {
        if ($this->bound) {
            return $this->instance;
        }
        $qbittorrent = $this->instances->getDefault(ServiceInstance::TYPE_QBITTORRENT);
        return $qbittorrent !== null ? $this->instances->getGluetunFor($qbittorrent) : null;
    }

    /** True when a Gluetun control server is targeted. */
    public function isConfigured(): bool
    {
        $this->ensureConfig();
        return $this->baseUrl !== null && $this->baseUrl !== '';
    }

    private function ensureConfig(): void
    {
        if ($this->configLoaded) return;
        $instance = $this->getInstance();
        $this->baseUrl  = $instance?->getUrl();
        $this->apiKey   = $instance?->getApiKey() ?? '';
        $this->configLoaded = true;
    }

    public function reset(): void
    {
        $this->instance        = null;
        $this->bound           = false;
        $this->configLoaded    = false;
        $this->baseUrl         = null;
        $this->apiKey          = '';
        $this->publicIpCache   = null;
        $this->publicIpCacheAt = 0.0;
        $this->statusCache     = null;
        $this->statusCacheAt   = 0.0;
        $this->portCache       = null;
        $this->portCacheAt     = 0.0;
    }

    /**
     * Returns public IP + location + organization (VPN provider).
     * Format: { public_ip, region, country, city, organization, ... }
     */
    public function getPublicIp(): ?array
    {
        $now = microtime(true);
        if ($this->publicIpCache !== null && ($now - $this->publicIpCacheAt) < self::PUBLIC_IP_TTL) {
            return $this->publicIpCache;
        }

        $data = $this->get('/v1/publicip/ip');
        if ($data === null) return $this->publicIpCache;

        $this->publicIpCache   = $data;
        $this->publicIpCacheAt = $now;
        return $data;
    }

    /**
     * VPN status — 'running', 'stopped', 'crashed'.
     * Uses the unified /v1/vpn/status (protocol-agnostic, Gluetun v3.40+), then
     * falls back to the legacy /v1/openvpn/status for pre-v3.40 OpenVPN installs.
     * 10s cache (avoids sequential cURL requests on every /api/vpn).
     */
    public function getVpnStatus(): ?string
    {
        $now = microtime(true);
        if ($this->statusCache !== null && ($now - $this->statusCacheAt) < self::STATUS_TTL) {
            return $this->statusCache;
        }

        foreach ($this->statusPaths() as $path) {
            $data = $this->get($path);
            if ($data !== null && isset($data['status'])) {
                $this->statusCache   = (string)$data['status'];
                $this->statusCacheAt = $now;
                return $this->statusCache;
            }
        }
        return $this->statusCache;
    }

    private function statusPaths(): array
    {
        return ['/v1/vpn/status', '/v1/openvpn/status'];
    }

    /**
     * Port forwarded by the VPN provider (the one Gluetun should push to qBit via port-update).
     * Gluetun v3.40+ exposes the unified /v1/portforward (protected by default — HTTP_CONTROL_SERVER_AUTH_CONFIG_FILEPATH config required).
     * Falls back to the legacy /v1/openvpn/portforwarded endpoint.
     * 10s cache.
     */
    public function getForwardedPort(): ?int
    {
        $now = microtime(true);
        if ($this->portCache !== null && ($now - $this->portCacheAt) < self::PORT_TTL) {
            return $this->portCache;
        }

        foreach ($this->portPaths() as $path) {
            $data = $this->get($path);
            if ($data !== null && isset($data['port'])) {
                $this->portCache   = (int)$data['port'];
                $this->portCacheAt = $now;
                return $this->portCache;
            }
        }
        return $this->portCache;
    }

    private function portPaths(): array
    {
        return ['/v1/portforward', '/v1/openvpn/portforwarded'];
    }

    /**
     * Full aggregate ready for the UI.
     */
    public function getSummary(): array
    {
        $ip     = $this->getPublicIp();
        $status = $this->getVpnStatus();
        $port   = $this->getForwardedPort();
        return [
            'ok'             => $ip !== null,
            'status'         => $status,
            'public_ip'      => $ip['public_ip'] ?? null,
            'country'        => $ip['country'] ?? null,
            'city'           => $ip['city'] ?? null,
            'region'         => $ip['region'] ?? null,
            'organization'   => $ip['organization'] ?? null,
            'timezone'       => $ip['timezone'] ?? null,
            'forwarded_port' => $port,
        ];
    }

    private function get(string $path): ?array
    {
        $this->ensureConfig();
        if ($this->baseUrl === null || $this->baseUrl === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . $path;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true, // /v1/openvpn/portforwarded redirects to /v1/portforward
            // Only follow redirects within http(s) — blocks file:// / gopher:// / dict:// SSRF.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        $headers = $this->authHeaders();
        if ($headers !== []) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            $this->logger->debug('GluetunClient GET failed', ['path' => $path, 'code' => $code]);
            return null;
        }
        return json_decode($body, true) ?: null;
    }

    /**
     * cURL header list carrying the Gluetun API key, or [] when no key is set.
     */
    private function authHeaders(): array
    {
        $this->ensureConfig();
        return $this->apiKey !== '' ? ['X-API-Key: ' . $this->apiKey] : [];
    }
}
