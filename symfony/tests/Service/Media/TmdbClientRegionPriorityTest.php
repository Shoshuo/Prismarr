<?php
namespace App\Tests\Service\Media;

use App\Service\Media\TmdbClient;
use PHPUnit\Framework\TestCase;

/**
 * TmdbClient::regionPriority — the locale-led country ordering that replaced
 * the old hardcoded FR-first lists for release dates / certifications /
 * watch providers.
 */
final class TmdbClientRegionPriorityTest extends TestCase
{
    public function testFrenchLeadsWithFrenchRegions(): void
    {
        $order = TmdbClient::regionPriority('fr');
        self::assertSame('FR', $order[0]);
        self::assertContains('US', $order);
        // BE comes before US for a French user.
        self::assertLessThan(array_search('US', $order, true), array_search('BE', $order, true));
    }

    public function testEnglishLeadsWithUsThenGb(): void
    {
        $order = TmdbClient::regionPriority('en');
        self::assertSame('US', $order[0]);
        self::assertContains('GB', $order);
        // US comes before FR for an English user (the whole point of the fix).
        self::assertLessThan(array_search('FR', $order, true), array_search('US', $order, true));
    }

    public function testLocaleWithRegionSuffixHandled(): void
    {
        self::assertSame('US', TmdbClient::regionPriority('en_US')[0]);
        self::assertSame('FR', TmdbClient::regionPriority('fr-FR')[0]);
    }

    public function testAppendedCountriesIncludedAndDeduped(): void
    {
        $order = TmdbClient::regionPriority('en', ['JP', 'US', 'KR']);
        self::assertContains('JP', $order);
        self::assertContains('KR', $order);
        // Order-preserving with no duplicates, even though 'US' was appended
        // while already present in the lead/fallback chain.
        self::assertSame(array_values(array_unique($order)), $order);
        self::assertCount(1, array_keys($order, 'US', true));
    }

    public function testUnknownLocaleStillHasCommonFallbackChain(): void
    {
        $order = TmdbClient::regionPriority('xx');
        self::assertContains('FR', $order);
        self::assertContains('US', $order);
        self::assertContains('GB', $order);
    }

    public function testEmptyLocaleProducesCommonChainWithoutBlanks(): void
    {
        $order = TmdbClient::regionPriority('');
        self::assertContains('US', $order);
        self::assertContains('FR', $order);
        self::assertNotContains('', $order);
    }
}
