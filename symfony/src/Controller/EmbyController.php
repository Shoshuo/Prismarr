<?php

namespace App\Controller;

use App\Service\Media\EmbyClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only internal API for the "Current Emby activity" dashboard widget,
 * backed by EmbyClient (Emby Server's own Sessions API).
 *
 * Every Emby call is made server-side: the API key never reaches the browser,
 * and the JSON returned here is the already-sanitized, normalized shape (no
 * IPs, tokens, device ids, file paths or raw payload). The endpoints always
 * answer 200 with an `error` code in the body for the disabled / unconfigured
 * / unreachable / auth cases, so the widget never breaks the page. Nothing
 * here can control playback or change anything on the Emby server.
 */
#[IsGranted('ROLE_USER')]
#[Route('/emby', name: 'app_emby_')]
class EmbyController extends AbstractController
{
    public function __construct(
        private readonly EmbyClient $emby,
    ) {}

    /**
     * GET /emby/api/activity — sanitized current playback as JSON. Same shape
     * as /tautulli/api/activity. Fails open: a thrown client returns the
     * neutral shape with error:"unreachable" rather than a 500 + stack trace.
     */
    #[Route('/api/activity', name: 'api_activity', methods: ['GET'])]
    public function apiActivity(): JsonResponse
    {
        try {
            return $this->json($this->emby->getActivity());
        } catch (\Throwable) {
            return $this->json([
                'enabled'    => true,
                'configured' => true,
                'connected'  => false,
                'error'      => 'unreachable',
                'streamCount'       => 0,
                'directPlayCount'   => 0,
                'directStreamCount' => 0,
                'transcodeCount'    => 0,
                'bandwidth'         => [
                    'totalKbps' => 0, 'lanKbps' => 0, 'wanKbps' => 0,
                    'totalMbps' => 0.0, 'lanMbps' => 0.0, 'wanMbps' => 0.0,
                ],
                'sessions'          => [],
            ]);
        }
    }

    /**
     * GET /emby/api/image?item=12345 — streams an item's primary image
     * (poster) fetched server-side with the API key. The `item` value is
     * allow-listed to Emby item ids inside EmbyClient::fetchImage (SSRF /
     * open-relay guard). A miss returns 404 so the widget's CSS placeholder
     * shows through rather than a broken image. Privately cacheable for a
     * day — the id is stable, so the browser reuses it across the 10s polls.
     */
    #[Route('/api/image', name: 'api_image', methods: ['GET'])]
    public function apiImage(Request $request): Response
    {
        $item = (string) $request->query->get('item', '');
        $image = $item !== '' ? $this->emby->fetchImage($item) : null;
        if ($image === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($image['body']);
        $response->headers->set('Content-Type', $image['contentType']);
        $response->setMaxAge(86400);
        $response->setPrivate();

        return $response;
    }

    /**
     * GET /emby/api/quicklook/{itemId} — resolves an Emby item to the TMDb
     * {type, id} the app-global quick-look modal opens with. Returns nulls
     * (not an error status) when the item has no TMDb provider id, so the
     * click is a no-op instead of a broken modal. Fails open.
     */
    #[Route('/api/quicklook/{itemId}', name: 'api_quicklook', methods: ['GET'], requirements: ['itemId' => '[0-9a-fA-F]{1,32}'])]
    public function apiQuickLook(string $itemId): JsonResponse
    {
        try {
            $resolved = $this->emby->resolveTmdbId($itemId);
        } catch (\Throwable) {
            $resolved = null;
        }

        return $this->json($resolved ?? ['type' => null, 'id' => null]);
    }
}
