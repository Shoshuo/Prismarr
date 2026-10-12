<?php

namespace App\Tests\Controller;

use App\Controller\BazarrController;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * The episode download now carries the Sonarr series id
 * (Bazarr requires it), so a successful download can patch that series'
 * badge in place instead of queueing a bulk rebuild — which no-ops while the
 * series map is still fresh, leaving the badge wrong for a minute or more.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrDownloadEpisodeRefreshTest extends TestCase
{
    private function controller(BazarrClient $client, BazarrSubtitleIndex $index): BazarrController
    {
        $c = new BazarrController(
            $client,
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $index,
            $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(BazarrPosterResolver::class),
        );
        $c->setContainer(new Container());

        return $c;
    }

    private function okClient(): BazarrClient
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('downloadEpisode')->willReturn(true);

        return $client;
    }

    public function testASuccessfulEpisodeDownloadPatchesThatSeriesInPlace(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->expects($this->once())->method('refreshItem')->with('series', 12);
        $index->expects($this->once())->method('requestRefresh')->with(BazarrSubtitleIndex::KEY_BADGES);

        $response = $this->controller($this->okClient(), $index)->apiDownloadEpisode(
            Request::create('/bazarr/api/download/episode', 'POST', ['seriesid' => '12', 'episodeid' => '345']),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testWithoutAUsableSeriesIdItFallsBackToTheBulkRebuild(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->expects($this->never())->method('refreshItem');
        $requested = [];
        $index->method('requestRefresh')->willReturnCallback(static function (string $key) use (&$requested): void {
            $requested[] = $key;
        });

        $this->controller($this->okClient(), $index)->apiDownloadEpisode(
            Request::create('/bazarr/api/download/episode', 'POST', ['episodeid' => '345']),
        );

        self::assertSame([BazarrSubtitleIndex::KEY_SERIES, BazarrSubtitleIndex::KEY_BADGES], $requested);
    }

    public function testASuccessfulMovieDownloadAlsoQueuesTheBadgeCounts(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('downloadMovie')->willReturn(true);
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->expects($this->once())->method('refreshItem')->with('movie', 42);
        $index->expects($this->once())->method('requestRefresh')->with(BazarrSubtitleIndex::KEY_BADGES);

        $this->controller($client, $index)->apiDownloadMovie(
            Request::create('/bazarr/api/download/movie', 'POST', ['radarrid' => '42']),
        );
    }
}
