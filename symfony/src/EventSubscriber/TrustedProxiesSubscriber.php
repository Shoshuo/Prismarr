<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Re-resolves the `REMOTE_ADDR` placeholder of TRUSTED_PROXIES on every
 * request (review 2026-11-06, S1).
 *
 * Symfony turns the placeholder into `$_SERVER['REMOTE_ADDR']` exactly once,
 * in Kernel::preBoot(). In classic one-process-per-request mode that IS the
 * current peer, but under FrankenPHP worker mode (PRISMARR_WORKER) the
 * kernel stays resident, so each worker trusted whichever peer hit it first
 * — usually the Docker healthcheck on 127.0.0.1 — and re-pinned every
 * worker_loop_max requests. A later request through cloudflared / Traefik
 * on the Docker network was then NOT trusted on that worker: client ip =
 * the proxy, isSecure() = false (Secure cookie flag dropped, http://
 * redirects), login throttling collapsed onto the proxy's ip.
 *
 * Runs before the router (32) and the firewall (8) so every consumer of
 * getClientIp() / isSecure() / getHost() sees the per-request answer. A
 * static list (no placeholder) is left exactly as the kernel set it.
 */
final class TrustedProxiesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.trusted_proxies%')]
        private readonly string $trustedProxies,
        #[Autowire('%kernel.trusted_headers%')]
        private readonly array|int|string $trustedHeaders,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!str_contains($this->trustedProxies, 'REMOTE_ADDR')) {
            return; // static list: nothing to re-resolve
        }

        $peer = $event->getRequest()->server->get('REMOTE_ADDR');
        $proxies = [];
        foreach (explode(',', $this->trustedProxies) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === 'REMOTE_ADDR') {
                if (is_string($peer) && $peer !== '') {
                    $proxies[] = $peer;
                }
                continue;
            }
            $proxies[] = $entry; // PRIVATE_SUBNETS and CIDRs are expanded by Symfony
        }

        Request::setTrustedProxies($proxies, $this->trustedHeaderSet());
    }

    /**
     * Same conversion Kernel::preBoot() applies to kernel.trusted_headers
     * (a header-name list in framework.yaml, or an already-resolved bit set).
     *
     * @return int<0, 63>
     */
    private function trustedHeaderSet(): int
    {
        $headers = $this->trustedHeaders;
        if (is_string($headers)) {
            $headers = array_map('trim', explode(',', $headers));
        }
        if (is_int($headers)) {
            return $headers & 63;
        }
        $set = 0;
        foreach ($headers as $header) {
            $const = Request::class . '::HEADER_' . strtr(strtoupper((string) $header), '-', '_');
            if (defined($const)) {
                $set |= (int) constant($const);
            }
        }
        return $set & 63;
    }
}
