<?php

namespace App\EventSubscriber;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\ServiceInstanceProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the Content-Security-Policy + X-Frame-Options headers on every
 * HTML response. (X-Frame-Options used to be a static Caddy header — it
 * moved here so the iframe-embedding opt-in below can control both in one
 * place; static assets served straight by Caddy no longer carry it, which
 * is harmless since clickjacking needs an interactive HTML page.)
 *
 * img-src is built dynamically from the configured service URLs
 * (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, Gluetun) so
 * that self-hosters on arbitrary IPs/ports see their service-hosted
 * images (e.g. Jellyseerr /avatarproxy/*) load correctly.
 *
 * script-src / connect-src stay strict — that is where the real
 * XSS/exfiltration protection lives. frame-ancestors is 'self' by default;
 * set PRISMARR_FRAME_ANCESTORS to a space-separated origin list (e.g.
 * "https://organizr.example.com") to allow embedding Prismarr in an iframe
 * there (issue #25). When that env is set X-Frame-Options is dropped, since
 * its only "allow" value (ALLOW-FROM) is ignored by modern browsers anyway.
 *
 * v1.1.0 — radarr/sonarr origins are aggregated across every enabled
 * instance (a multi-instance install with a 1080p + 4K Radarr needs
 * both whitelisted); qBittorrent and Gluetun joined them in #131. The
 * other services still use their flat setting.
 */
final class CspHeaderSubscriber implements EventSubscriberInterface
{
    /** Services still on flat settings (radarr/sonarr/qbittorrent/gluetun migrated to service_instance). */
    private const SERVICE_URL_KEYS = [
        'prowlarr_url',
        'jellyseerr_url',
    ];

    private const STATIC_IMG_HOSTS = [
        'https://image.tmdb.org',
        'https://ui-avatars.com',
        'https://artworks.thetvdb.com',
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly ServiceInstanceProvider $instances,
        // `default::VAR` yields null (not '') when the env is unset, so accept
        // ?string and coalesce in onResponse().
        #[Autowire(env: 'default::PRISMARR_FRAME_ANCESTORS')]
        private readonly ?string $frameAncestors = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // Strip control chars (CR/LF would smuggle a second header), `;`
        // (would close `frame-ancestors` and let a typo smuggle another CSP
        // directive) and `,` (browsers split comma-separated CSPs into
        // multiple policies and *intersect* them — a stray comma can't
        // weaken the policy but silently breaks the app for the admin who
        // typo'd it, and it's never legitimate in a value).
        $extraAncestors = trim(preg_replace('/[\x00-\x1F\x7F;,]/', '', $this->frameAncestors ?? '') ?? '');

        if ($extraAncestors === '') {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        } else {
            // ALLOW-FROM is dead in modern browsers, so the only way to allow
            // a foreign embedder is to drop this header and lean on CSP.
            $response->headers->remove('X-Frame-Options');
        }

        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }

        $frameAncestors = "'self'" . ($extraAncestors !== '' ? ' ' . $extraAncestors : '');

        $imgHosts = self::STATIC_IMG_HOSTS;
        foreach (self::SERVICE_URL_KEYS as $key) {
            $url = $this->config->get($key);
            if (!$url) {
                continue;
            }
            $origin = $this->extractOrigin($url);
            if ($origin !== null) {
                $imgHosts[] = $origin;
            }
        }
        foreach (ServiceInstance::TYPES as $type) {
            foreach ($this->instances->getEnabled($type) as $instance) {
                $origin = $this->extractOrigin($instance->getUrl());
                if ($origin !== null) {
                    $imgHosts[] = $origin;
                }
            }
        }
        $imgHosts = array_unique($imgHosts);

        $csp = sprintf(
            "default-src 'self'; "
            . "img-src 'self' data: blob: %s; "
            . "style-src 'self' 'unsafe-inline' https://rsms.me; "
            . "font-src 'self' https://rsms.me; "
            . "script-src 'self' 'unsafe-inline' data:; "
            . "connect-src 'self'; "
            . "frame-src https://www.youtube.com https://www.youtube-nocookie.com; "
            . "frame-ancestors %s; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "object-src 'none'",
            implode(' ', $imgHosts),
            $frameAncestors,
        );

        $response->headers->set('Content-Security-Policy', $csp);
    }

    /**
     * Extract scheme://host[:port] from a URL. Returns null if invalid.
     */
    private function extractOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'http';
        $origin = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
