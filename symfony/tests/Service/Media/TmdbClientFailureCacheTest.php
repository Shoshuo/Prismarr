<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A failed TMDb call must not be cached as an empty answer: the next call
 * retries, while a successful answer is served from cache.
 */
#[AllowMockObjectsWithoutExpectations]
final class TmdbClientFailureCacheTest extends TestCase
{
    public function testAFailureIsRetriedAndASuccessIsCached(): void
    {
        $client = $this->getMockBuilder(TmdbClient::class)
            ->setConstructorArgs([
                $this->createMock(ConfigService::class),
                new ArrayAdapter(),
                new NullLogger(),
                $this->createMock(DisplayPreferencesService::class),
            ])
            ->onlyMethods(['request', 'getLocale'])
            ->getMock();
        $client->method('getLocale')->willReturn('fr-FR');
        $client->expects(self::exactly(2))->method('request')
            ->willReturnOnConsecutiveCalls(null, ['results' => [['id' => 157336]]]);

        self::assertSame([], $client->searchMulti('interstellar'), 'the failure surfaces as empty');
        self::assertSame(157336, $client->searchMulti('interstellar')['results'][0]['id'], 'retried, not served the cached failure');
        self::assertSame(157336, $client->searchMulti('interstellar')['results'][0]['id'], 'the success is cached (no third request)');
    }
}
