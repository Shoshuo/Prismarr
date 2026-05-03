<?php

namespace App\EventSubscriber;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\ServiceInstanceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the Content-Security-Policy header on every HTML response.
 *
 * img-src is built dynamically from the configured service URLs
 * (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, Gluetun) so
 * that self-hosters on arbitrary IPs/ports see their service-hosted
 * images (e.g. Jellyseerr /avatarproxy/*) load correctly.
 *
 * script-src / connect-src / frame-ancestors stay strict — that is
 * where the real XSS/exfiltration protection lives.
 *
 * v1.1.0 — radarr/sonarr origins are aggregated across every enabled
 * instance (a multi-instance install with a 1080p + 4K Radarr needs
 * both whitelisted), the other services still use their flat setting.
 */
final class CspHeaderSubscriber implements EventSubscriberInterface
{
    /** Services still on flat settings (radarr/sonarr migrated to service_instance). */
    private const SERVICE_URL_KEYS = [
        'prowlarr_url',
        'jellyseerr_url',
        'qbittorrent_url',
        'gluetun_url',
    ];

    private const STATIC_IMG_HOSTS = [
        'https://image.tmdb.org',
        'https://ui-avatars.com',
        'https://artworks.thetvdb.com',
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly ServiceInstanceProvider $instances,
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
        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }

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
        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR] as $type) {
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
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "object-src 'none'",
            implode(' ', $imgHosts)
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
