<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Client for the Houndarr widget API (GET <url>/api/v1/widget, `X-Api-Key`
 * header). Houndarr's API key authorizes exactly that one read-only summary
 * endpoint — there is no richer surface, so the whole integration is the
 * dashboard stat tile + health chip (no dedicated page). Optional service —
 * unconfigured (or kill switch off) returns null without exception.
 *
 * widget() shape:
 *  [
 *    'totals' => ?['tracked' => int, 'eligible' => int, 'gated' => int,
 *                  'unreleased' => int, 'searches7d' => int],  // null on error='auth'
 *    'generatedAtEpoch' => ?int,
 *    'error' => ?string ('auth' — bad/revoked key; null otherwise),
 *  ]
 *  null → unconfigured, disabled, or unreachable (transport / non-2xx).
 */
class HoundarrClient implements ResetInterface
{
    public const SERVICE = 'houndarr';

    private bool   $configLoaded = false;
    private bool   $enabled = true;
    private string $baseUrl = '';
    private string $apiKey = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Allow-list normalization of the raw widget payload: the five documented
     * totals cast to int and clamped >= 0, generated_at parsed to an epoch,
     * everything else dropped. Tolerant of unknown `schema` values on purpose
     * (read totals if present) — a v2 bump shouldn't blank the tile.
     */
    public static function normalizeWidget(array $raw): array
    {
        $t = is_array($raw['totals'] ?? null) ? $raw['totals'] : [];
        $int = static fn(mixed $v): int => max(0, (int) (is_numeric($v) ? $v : 0));
        $epoch = isset($raw['generated_at']) ? strtotime((string) $raw['generated_at']) : false;

        return [
            'totals' => [
                'tracked'    => $int($t['tracked'] ?? 0),
                'eligible'   => $int($t['eligible'] ?? 0),
                'gated'      => $int($t['gated'] ?? 0),
                'unreleased' => $int($t['unreleased'] ?? 0),
                'searches7d' => $int($t['searches_7d'] ?? 0),
            ],
            'generatedAtEpoch' => $epoch !== false ? $epoch : null,
            'error' => null,
        ];
    }

    /** Totals move slowly; 45 s keeps widget refresh + health pings to ≤1 upstream GET per window. */
    private const WIDGET_TTL = 45.0;

    private ?array $widgetCache = null;
    private float  $widgetCacheAt = 0.0;

    private function ensureConfig(): void
    {
        if ($this->configLoaded) return;
        $this->baseUrl = (string) ($this->config->get('houndarr_url') ?? '');
        $this->apiKey  = (string) ($this->config->get('houndarr_api_key') ?? '');
        $this->enabled = $this->config->get('houndarr_enabled') !== '0';
        $this->configLoaded = true;
    }

    public function widget(): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }

        $now = microtime(true);
        if ($this->widgetCache !== null && ($now - $this->widgetCacheAt) < self::WIDGET_TTL) {
            return $this->widgetCache;
        }

        $resp = $this->request();
        if ($resp === null) {
            return null; // transport failure — don't cache, retry next poll
        }
        if ($resp['http'] === 401) {
            // Bad/revoked key. Cache the verdict for the full TTL: hammering a
            // 401 trips Houndarr's per-IP 429 lockout on every poll otherwise.
            $this->widgetCache   = ['totals' => null, 'generatedAtEpoch' => null, 'error' => 'auth'];
            $this->widgetCacheAt = $now;
            return $this->widgetCache;
        }
        if ($resp['http'] < 200 || $resp['http'] >= 300) {
            $this->logger->debug('HoundarrClient widget non-2xx', ['http' => $resp['http']]);
            return null; // 429/5xx — unreachable-ish, don't cache
        }

        $decoded = json_decode($resp['body'], true);
        if (!is_array($decoded)) {
            $this->logger->debug('HoundarrClient widget bad JSON', ['body' => substr($resp['body'], 0, 200)]);
            return null;
        }

        $this->widgetCache   = self::normalizeWidget($decoded);
        $this->widgetCacheAt = $now;
        return $this->widgetCache;
    }

    /** For HealthService::pingFor() — up only on a clean, key-accepted fetch. */
    public function ping(): bool
    {
        $w = $this->widget();
        return $w !== null && $w['error'] === null;
    }

    /**
     * One GET to the widget endpoint. Returns ['http' => int, 'body' => string],
     * or null when the request failed at the transport layer (connect refused /
     * timed out / DNS). Protected so tests can substitute canned responses.
     */
    protected function request(): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/widget';
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 3,
            // HTTP(S) only — same SSRF stance as the other clients.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER      => ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json'],
        ]);
        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $code === 0) {
            $this->logger->debug('HoundarrClient request failed', ['errno' => $errno]);
            return null;
        }
        return ['http' => $code, 'body' => (string) $body];
    }

    public function reset(): void
    {
        $this->configLoaded = false;
        $this->enabled = true;
        $this->baseUrl = '';
        $this->apiKey = '';
        $this->widgetCache = null;
        $this->widgetCacheAt = 0.0;
    }
}
