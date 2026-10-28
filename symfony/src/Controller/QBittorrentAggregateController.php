<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The qBittorrent routes that are NOT scoped to one instance (issue #131):
 *
 *  - the global poll behind the sidebar badge + completion toasts, which now
 *    sums every enabled instance (same URL and route name as before, so a
 *    page cached by an older Prismarr keeps polling successfully);
 *  - the pre-#131 slug-less URLs (`/qbittorrent`, `/qbittorrent/api/…`),
 *    307-redirected to the default instance — same treatment as
 *    LegacyMediaRedirectController gave Radarr/Sonarr in v1.1.0.
 */
#[IsGranted('ROLE_ADMIN')]
final class QBittorrentAggregateController extends AbstractController
{
    public function __construct(
        private readonly QBittorrentClient $qbt,
        private readonly ServiceInstanceProvider $instances,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Lightweight endpoint for the global poll (sidebar + toasts) — returns
     * only the states the toasts watch, across every enabled instance.
     *
     * `key` (`<slug>:<hash>`) is what the JS dedupes on: the same torrent can
     * sit on several instances (cross-seeding), and each copy completes on
     * its own. `instance` (display name) is only set when 2+ instances are
     * enabled, so a single-instance toast reads exactly as before.
     * `active_by_instance` feeds the per-instance sidebar badges. An
     * unreachable instance just contributes no torrents (the client returns
     * [] as before #131); one that throws is skipped, and the error shape is
     * only returned when none is left to answer (the JS then backs off).
     */
    #[Route('/qbittorrent/api/poll-summary', name: 'app_qbittorrent_api_poll_summary', methods: ['GET'], priority: 10)]
    public function apiPollSummary(): JsonResponse
    {
        // Issue #15 kill switch — mirrors QBittorrentClient::ensureConfig().
        if ($this->config->get('qbittorrent_enabled') === '0') {
            return $this->json(['error' => 'disabled'], 503);
        }

        $instances = $this->instances->getEnabled(ServiceInstance::TYPE_QBITTORRENT);
        $multi     = count($instances) > 1;
        $active    = 0;
        $bySlug    = [];
        $items     = [];
        $failed    = 0;

        foreach ($instances as $instance) {
            try {
                $torrents = $this->qbt->withInstance($instance)->getTorrents();
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning('QBittorrent poll-summary failed', [
                    'instance'  => $instance->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            $bySlug[$instance->getSlug()] = 0;
            foreach ($torrents as $t) {
                $state = $t['state'] ?? '';
                if ($state === 'downloading') {
                    $active++;
                    $bySlug[$instance->getSlug()]++;
                }
                // Only the states watched for toasts: completed + error
                if (in_array($state, ['completed', 'error', 'downloading', 'seeding'], true)) {
                    $item = [
                        'key'   => $instance->getSlug() . ':' . ($t['hash'] ?? ''),
                        'hash'  => $t['hash'] ?? '',
                        'state' => $state,
                        'name'  => $t['name'] ?? '—',
                        'size'  => (int)($t['size'] ?? 0),
                    ];
                    if ($multi) {
                        $item['instance'] = $instance->getName();
                    }
                    $items[] = $item;
                }
            }
        }

        if ($instances === [] || $failed === count($instances)) {
            return $this->json(['error' => 'unavailable'], 500);
        }

        return $this->json(['active' => $active, 'active_by_instance' => $bySlug, 'items' => $items]);
    }

    /**
     * Pre-#131 URLs → the default instance, method + query string preserved
     * (307), so bookmarks and a browser still running an older page's JS keep
     * working after `docker compose pull`. Not 308: the target moves if the
     * admin renames or swaps the default instance. A real route always wins
     * first (priority -100), so `/qbittorrent/{slug}/…` is never shadowed.
     */
    #[Route(
        '/qbittorrent/{rest}',
        name: 'legacy_qbittorrent_redirect',
        requirements: ['rest' => '|api(?:/.*)?'],
        defaults: ['rest' => ''],
        priority: -100,
    )]
    public function redirectLegacy(string $rest, Request $request): Response
    {
        $default = $this->instances->getDefault(ServiceInstance::TYPE_QBITTORRENT);
        if ($default === null) {
            return $this->redirectToRoute('app_home');
        }

        $target = $this->generateUrl('app_qbittorrent_index', ['slug' => $default->getSlug()]);
        if ($rest !== '') {
            $target .= '/' . ltrim($rest, '/');
        }
        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            $target .= '?' . $query;
        }

        return new RedirectResponse($target, Response::HTTP_TEMPORARY_REDIRECT);
    }
}
