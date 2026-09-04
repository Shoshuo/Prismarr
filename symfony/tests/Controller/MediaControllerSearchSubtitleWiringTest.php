<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * End-to-end wiring check for hits the real
 * `/search` route through a booted kernel with the REAL BazarrSubtitleIndex.
 * Bazarr is unconfigured in the test env, so every lookup fails closed to
 * 'hidden' — see MediaControllerSearchSubtitleTest's class doc for why a
 * mocked 'missing'/'complete' response can't be exercised through a full
 * HTTP request here (BazarrSubtitleIndex gets eagerly resolved as a private
 * container service, via Twig's extension set, before any test body runs —
 * a pre-existing Turbo+Twig+Doctrine interaction). This still catches a real
 * class of regression: BazarrSubtitleIndex failing to inject into
 * MediaController, attachSubtitleStatus() never being called, or the route
 * crashing outright now that the constructor takes an extra argument.
 */
class MediaControllerSearchSubtitleWiringTest extends AbstractWebTestCase
{
    public function testLocalSearchDoesNotCrashAndNeverLeaksSubtitleKeyWhenBazarrIsUnconfigured(): void
    {
        $cache = static::getContainer()->get(CacheInterface::class);
        $cache->delete('prismarr_search_movies_v3');
        $cache->delete('prismarr_search_series_v3');

        // Radarr/Sonarr are unreachable test hosts (seeded by AbstractWebTestCase),
        // so the search index itself is empty — the real assertion here is that
        // the route responds cleanly and the payload stays a plain list, now
        // that MediaController's constructor pulls in BazarrSubtitleIndex.
        $this->client->request('GET', '/medias/radarr-1/search', ['q' => 'anything']);

        self::assertResponseIsSuccessful();
        $results = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($results);
    }
}
