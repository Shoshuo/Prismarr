<?php

namespace App\Tests\Service\Cache;

use App\Service\Cache\StaleWhileRevalidateCache;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Review 2026-11-06 (HC-1): every settings save runs `cache.app->clear()`,
 * which also wiped the stale-while-revalidate datasets (library lists,
 * Bazarr index, search vocabulary, their `.invalidated_at` stamps) — the
 * next page paid the cold fan-out and the lost-update window reopened.
 * The SWR datasets live in their own `cache.swr` pool now.
 */
class SwrPoolIsolationTest extends KernelTestCase
{
    public function testClearingCacheAppLeavesTheSwrDatasetsAlone(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var StaleWhileRevalidateCache $swr */
        $swr = $container->get(StaleWhileRevalidateCache::class);
        $pool = $swr->getPool();
        $this->assertNotSame($container->get('cache.app'), $pool, 'the SWR datasets must not share the response-cache pool');

        $item = $pool->getItem('review_hc1_probe');
        $item->set('kept');
        $pool->save($item);

        $container->get('cache.app')->clear();

        $this->assertTrue($pool->getItem('review_hc1_probe')->isHit(), 'a settings save (cache.app clear) must not drop the SWR pool');
        $pool->deleteItem('review_hc1_probe');
    }

    public function testTheSwrPoolExistsAsAService(): void
    {
        self::bootKernel();
        $this->assertTrue(self::getContainer()->has('cache.swr'));
    }
}
