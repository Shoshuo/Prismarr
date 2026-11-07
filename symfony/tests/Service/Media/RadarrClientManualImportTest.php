<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Review 2026-11-06 (H3): the queue "Import" must go through Radarr's
 * manual-import PREVIEW (GET /api/v3/manualimport?downloadId=…) so each
 * file is matched to the movie with quality/languages resolved by Radarr,
 * exactly like SonarrClient::manualImportFromQueueItems. Posting the
 * queue item's outputPath (a folder for multi-file releases) made Radarr
 * "complete" the command with a failed per-file import — and the UI then
 * deleted the queue item.
 */
#[AllowMockObjectsWithoutExpectations]
class RadarrClientManualImportTest extends TestCase
{
    /** @param list<string> $methodsToMock */
    private function clientMock(array $methodsToMock): RadarrClient
    {
        return $this->getMockBuilder(RadarrClient::class)
            ->setConstructorArgs([
                $this->createMock(ServiceInstanceProvider::class),
                $this->createMock(LoggerInterface::class),
                $this->createMock(ServiceHealthCache::class),
            ])
            ->onlyMethods($methodsToMock)
            ->getMock();
    }

    public function testQueueItemImportForwardsTheEnrichedPreviewAndSkipsRejections(): void
    {
        $preview = [
            [
                'path'         => '/dl/Movie.2024.1080p/Movie.2024.1080p.mkv',
                'folderName'   => 'Movie.2024.1080p',
                'movie'        => ['id' => 7, 'title' => 'Movie'],
                'quality'      => ['quality' => ['id' => 7, 'name' => 'Bluray-1080p'], 'revision' => ['version' => 1]],
                'languages'    => [['id' => 18, 'name' => 'Dutch']],
                'releaseGroup' => 'GRP',
                'indexerFlags' => 0,
                'downloadId'   => 'ABCDEF0123',
                'rejections'   => [],
            ],
            [
                'path'       => '/dl/Movie.2024.1080p/sample.mkv',
                'movie'      => ['id' => 7],
                'rejections' => [['reason' => 'Sample', 'type' => 'permanent']],
            ],
            [
                'path'       => '/dl/Movie.2024.1080p/extras.mkv',
                'movie'      => null,
                'rejections' => [],
            ],
        ];

        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->expects($this->once())
            ->method('getManualImportPreview')
            ->with('/dl/Movie.2024.1080p', false, 'ABCDEF0123')
            ->willReturn($preview);
        $client->expects($this->once())
            ->method('sendCommand')
            ->with('ManualImport', $this->callback(function (array $body) {
                if (($body['importMode'] ?? null) !== 'auto') return false;
                if (count($body['files'] ?? []) !== 1) return false;
                $f = $body['files'][0];
                return $f['path']       === '/dl/Movie.2024.1080p/Movie.2024.1080p.mkv'
                    && $f['movieId']    === 7
                    && $f['downloadId'] === 'ABCDEF0123'
                    && ($f['quality']['quality']['id'] ?? null) === 7
                    && ($f['languages'][0]['id'] ?? null) === 18
                    && $f['releaseGroup'] === 'GRP';
            }))
            ->willReturn(['id' => 99]);

        $r = $client->manualImportFromQueueItem('/dl/Movie.2024.1080p', 'ABCDEF0123');

        $this->assertTrue($r['ok']);
        $this->assertSame(99, $r['cmdId']);
        $this->assertSame(1, $r['imported']);
        $this->assertSame(2, $r['skipped']);
        $this->assertStringContainsString('Sample', $r['reasons'][0]);
        $this->assertStringContainsString('no movie match', $r['reasons'][1]);
    }

    public function testQueueItemImportFallsBackToTheFolderWhenThereIsNoDownloadId(): void
    {
        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->expects($this->once())
            ->method('getManualImportPreview')
            ->with('/dl/Movie.2024.1080p', false, null)
            ->willReturn([]);
        $client->expects($this->never())->method('sendCommand');

        $r = $client->manualImportFromQueueItem('/dl/Movie.2024.1080p', null);

        $this->assertFalse($r['ok'], 'nothing importable → no command, no "done" to the UI');
        $this->assertNull($r['cmdId']);
        $this->assertSame(0, $r['imported']);
    }

    public function testQueueItemImportReportsARejectedCommand(): void
    {
        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->method('getManualImportPreview')->willReturn([
            ['path' => '/dl/a.mkv', 'movie' => ['id' => 7], 'rejections' => []],
        ]);
        $client->method('sendCommand')->willReturn(null);

        $r = $client->manualImportFromQueueItem('/dl', 'HASH');

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['imported']);
        $this->assertContains('Radarr command rejected', $r['reasons']);
    }

    public function testPreviewByDownloadIdQueriesTheHashNotTheFolder(): void
    {
        $instance = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://radarr.test:7878', 'k');
        $instance->setEnabled(true);
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getDefault')->willReturn($instance);
        $client = $this->getMockBuilder(RadarrClient::class)
            ->setConstructorArgs([$instances, $this->createMock(LoggerInterface::class), new ServiceHealthCache(new ArrayAdapter())])
            ->onlyMethods(['exec'])
            ->getMock();
        $urls = [];
        $client->method('exec')->willReturnCallback(function (\CurlHandle $ch, array $opts) use (&$urls): array {
            $urls[] = $opts[CURLOPT_URL];
            curl_close($ch);
            return ['[]', 200, '', false];
        });

        $client->getManualImportPreview('/dl/ignored', false, 'HASH');

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('downloadId=HASH', $urls[0]);
        $this->assertStringContainsString('filterExistingFiles=false', $urls[0]);
        $this->assertStringNotContainsString('folder=', $urls[0], 'downloadId mode must not also scan the folder');
    }
}
