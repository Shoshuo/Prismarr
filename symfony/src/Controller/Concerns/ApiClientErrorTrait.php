<?php

namespace App\Controller\Concerns;

use App\Service\Media\DelugeClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TransmissionClient;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Helpers for surfacing upstream API errors (Radarr/Sonarr/Prowlarr/Jellyseerr/qBittorrent)
 * to the UI in a consistent way.
 *
 * The matching media client now exposes a getLastError() method that returns
 * `{code, method, path, message}` when the most recent HTTP call failed. This
 * trait lets controllers turn that into:
 *
 *   - a flash message ready to drop into addFlash('error', ...)
 *   - a structured JSON payload for AJAX endpoints (so the JS can render a toast
 *     instead of silently reloading the page)
 *
 * Hot-fix introduced in v1.0.3: prior to this, every mutating action that
 * failed simply returned `{ok: false}` (or 500) with no detail, hiding the
 * actual upstream error from the user.
 */
trait ApiClientErrorTrait
{
    /**
     * Build a flash-ready message such as:
     *   "Radarr: DELETE /api/v3/qualityprofile/1 — HTTP 500 — QualityProfile [1] is in use."
     *
     * Returns a generic fallback if the client did not record a structured error
     * (e.g. exception thrown before any HTTP call).
     */
    private function buildClientErrorFlash(string $service, RadarrClient|SonarrClient|ProwlarrClient|JellyseerrClient|QBittorrentClient|DelugeClient|TransmissionClient $client, ?string $fallback = null): string
    {
        $err = $client->getLastError();
        if ($err === null) {
            return $fallback ?? sprintf('%s: unknown error', $service);
        }

        return sprintf(
            '%s: %s %s — HTTP %d — %s',
            $service,
            $err['method'] ?? '?',
            $err['path']   ?? '?',
            (int) ($err['code'] ?? 0),
            $err['message'] ?? 'unknown error'
        );
    }

    /**
     * Build a structured JSON 500 response that surfaces upstream API context to the
     * frontend (so JS can display a toast with the actual error instead of swallowing it).
     *
     * Output shape:
     *   { ok: false, error: string, http_code: int, service: string, path: string, method: string }
     */
    private function jsonClientError(string $service, RadarrClient|SonarrClient|ProwlarrClient|JellyseerrClient|QBittorrentClient|DelugeClient|TransmissionClient $client, ?string $fallback = null, int $statusCode = 500): JsonResponse
    {
        $err = $client->getLastError();
        if ($err === null) {
            return new JsonResponse([
                'ok'        => false,
                'error'     => $fallback ?? 'unknown error',
                'http_code' => 0,
                'service'   => $service,
                'path'      => null,
                'method'    => null,
            ], $statusCode);
        }

        return new JsonResponse([
            'ok'        => false,
            'error'     => $err['message'] ?? ($fallback ?? 'unknown error'),
            'http_code' => (int) ($err['code'] ?? 0),
            'service'   => $service,
            'path'      => $err['path']   ?? null,
            'method'    => $err['method'] ?? null,
        ], $statusCode);
    }

    /**
     * Build a JSON payload from a result of `request*WithError()` style helpers,
     * which already return `{ok: bool, error: string, ...}`. Just enriches the
     * payload with `service` + `http_code` keys so the frontend can render
     * a unified toast.
     *
     * @param array{ok: bool, error?: mixed, code?: int, data?: mixed} $result
     */
    private function jsonWithErrorContext(string $service, array $result, int $failureStatus = 200): JsonResponse
    {
        if ($result['ok'] ?? false) {
            return new JsonResponse($result);
        }

        $payload = $result + [
            'service'   => $service,
            'http_code' => (int) ($result['code'] ?? 0),
        ];

        return new JsonResponse($payload, $failureStatus);
    }
}
