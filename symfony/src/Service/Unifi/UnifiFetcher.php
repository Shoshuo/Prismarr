<?php

namespace App\Service\Unifi;

/**
 * Transport seam over the classic UniFi Network API. Exists so the readers in
 * this namespace can be unit-tested against canned payloads with no cURL and
 * no network, while the single real implementation (UnifiClient) keeps sole
 * ownership of config, TLS policy, headers and the ok-envelope contract.
 *
 * Read-only by construction: callers pass a stat/rest/list path. $body turns
 * the call into a POST, which the classic API requires for report queries —
 * it is never used to mutate.
 */
interface UnifiFetcher
{
    /**
     * One API call. Returns the ok-envelope's `data` list, or null on
     * transport error / non-200 / error envelope — never throws.
     *
     * @param  ?array $body  when non-null, sent as a JSON POST body
     * @return ?array<mixed>
     */
    public function fetch(string $path, ?array $body = null): ?array;

    /**
     * True when the MOST RECENT fetch() failed at the transport layer
     * (connect refused, DNS failure, timeout) rather than returning an HTTP
     * or application-level error. Readers use this to skip their remaining
     * calls against a dead console instead of burning one connect timeout
     * per endpoint. Reset at the start of every fetch().
     */
    public function transportFailed(): bool;
}
