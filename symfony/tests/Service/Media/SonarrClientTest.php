<?php

namespace App\Tests\Service\Media;

use App\Service\Media\SonarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SonarrClientTest extends TestCase
{
    /**
     * Builds a SonarrClient with stubbed deps and only the public methods we
     * want to override (getManualImportPreview + sendCommand). Lets us
     * exercise manualImportFromQueueItems() end-to-end without hitting the wire.
     *
     * @param list<string> $methodsToMock
     */
    private function clientMock(array $methodsToMock): SonarrClient
    {
        return $this->getMockBuilder(SonarrClient::class)
            ->setConstructorArgs([
                $this->createMock(ServiceInstanceProvider::class),
                $this->createMock(LoggerInterface::class),
                $this->createMock(ServiceHealthCache::class),
            ])
            ->onlyMethods($methodsToMock)
            ->getMock();
    }

    public function testManualImportForwardsEnrichedPayloadFromPreview(): void
    {
        $preview = [
            [
                'path'         => '/dl/Show.S01E01.mkv',
                'folderName'   => 'Show.S01',
                'series'       => ['id' => 42],
                'episodes'     => [['id' => 1001], ['id' => 1002]],
                'releaseGroup' => 'GROUP',
                'quality'      => ['quality' => ['id' => 7, 'name' => 'WEBDL-1080p'], 'revision' => ['version' => 1]],
                'languages'    => [['id' => 5, 'name' => 'French']],
                'rejections'   => [],
            ],
        ];

        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->method('getManualImportPreview')->willReturn($preview);
        $client->expects($this->once())
            ->method('sendCommand')
            ->with('ManualImport', $this->callback(function (array $body) {
                if (($body['importMode'] ?? null) !== 'auto') return false;
                if (count($body['files'] ?? []) !== 1) return false;
                $f = $body['files'][0];
                return $f['seriesId']     === 42
                    && $f['episodeIds']   === [1001, 1002]
                    && $f['releaseGroup'] === 'GROUP'
                    && ($f['quality']['quality']['id'] ?? null) === 7
                    && ($f['languages'][0]['name'] ?? null) === 'French';
            }))
            ->willReturn(['id' => 999]);

        $r = $client->manualImportFromQueueItems([['path' => '/dl/Show.S01', 'downloadId' => '']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(999, $r['cmdId']);
        $this->assertSame(1, $r['imported']);
        $this->assertSame(0, $r['skipped']);
    }

    /**
     * downloadId mode: when a torrent hash is available (queue items always
     * carry one), Sonarr resolves files in the original grab context, so the
     * preview returns episodes pre-matched even for filenames without a
     * SxxEyy marker. Verifies we forward downloadId to getManualImportPreview
     * and to the final command payload.
     */
    public function testManualImportPrefersDownloadIdOverFolder(): void
    {
        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->expects($this->once())
            ->method('getManualImportPreview')
            ->with(null, 'abc123', false)
            ->willReturn([
                [
                    'path'        => '/dl/Show/01.Soiree.des.debutants.mkv',
                    'series'      => ['id' => 5],
                    'episodes'    => [['id' => 333]],
                    'rejections'  => [],
                    'downloadId'  => 'abc123',
                ],
            ]);
        $client->expects($this->once())
            ->method('sendCommand')
            ->with('ManualImport', $this->callback(function (array $body) {
                return ($body['files'][0]['downloadId'] ?? null) === 'abc123';
            }))
            ->willReturn(['id' => 7]);

        $r = $client->manualImportFromQueueItems([['path' => '', 'downloadId' => 'abc123']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['imported']);
    }

    /**
     * Real-world case Joshua hit: Sonarr v4 splits a season into one queue
     * item per episode, but they all share the torrent's downloadId. Without
     * dedup, calling preview 7 times for the same hash returns 7 × 7 = 49
     * duplicate candidates and the user sees "0/49 imported, 49× Invalid
     * season or episode". One preview call per unique downloadId is enough.
     */
    public function testManualImportDedupesItemsSharingDownloadId(): void
    {
        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->expects($this->once())  // ← 7 items, but only 1 preview call
            ->method('getManualImportPreview')
            ->willReturn([
                ['path' => '/dl/E01.mkv', 'series' => ['id' => 5], 'episodes' => [['id' => 101]], 'rejections' => []],
                ['path' => '/dl/E02.mkv', 'series' => ['id' => 5], 'episodes' => [['id' => 102]], 'rejections' => []],
            ]);
        $client->expects($this->once())
            ->method('sendCommand')
            ->willReturn(['id' => 1]);

        $items = array_fill(0, 7, ['path' => '/dl/Show.S01', 'downloadId' => 'shared-hash']);
        $r = $client->manualImportFromQueueItems($items);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['imported']);
    }

    public function testManualImportSkipsRejectedFilesAndReturnsReason(): void
    {
        $preview = [
            [
                'path'        => '/dl/Bad.mkv',
                'episodes'    => [['id' => 1]],
                'rejections'  => [['reason' => 'Existing file on disk']],
            ],
        ];

        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->method('getManualImportPreview')->willReturn($preview);
        $client->expects($this->never())->method('sendCommand');

        $r = $client->manualImportFromQueueItems([['path' => '/dl/Bad', 'downloadId' => '']]);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['imported']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(['Bad.mkv: Existing file on disk'], $r['reasons']);
    }

    public function testManualImportSkipsFilesWithNoEpisodeMatch(): void
    {
        $preview = [
            [
                'path'       => '/dl/Unknown.mkv',
                'episodes'   => [],
                'rejections' => [],
            ],
        ];

        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->method('getManualImportPreview')->willReturn($preview);
        $client->expects($this->never())->method('sendCommand');

        $r = $client->manualImportFromQueueItems([['path' => '/dl/Unknown', 'downloadId' => '']]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no episode match', $r['reasons'][0]);
    }

    public function testManualImportHandlesEmptyPreview(): void
    {
        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->method('getManualImportPreview')->willReturn([]);
        $client->expects($this->never())->method('sendCommand');

        $r = $client->manualImportFromQueueItems([['path' => '/dl/Empty', 'downloadId' => '']]);
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $r['skipped']);
        $this->assertStringContainsString('no preview for Empty', $r['reasons'][0]);
    }

    public function testManualImportIgnoresEmptyItems(): void
    {
        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->expects($this->never())->method('getManualImportPreview');
        $client->expects($this->never())->method('sendCommand');

        $r = $client->manualImportFromQueueItems([
            ['path' => '',    'downloadId' => ''],
            ['path' => '   ', 'downloadId' => ''],
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame(2, $r['skipped']);
    }

    public function testManualImportReturnsNotOkWhenSendCommandFails(): void
    {
        $preview = [[
            'path'       => '/dl/Show.S01E01.mkv',
            'episodes'   => [['id' => 1]],
            'rejections' => [],
            'series'     => ['id' => 1],
        ]];

        $client = $this->clientMock(['getManualImportPreview', 'sendCommand']);
        $client->method('getManualImportPreview')->willReturn($preview);
        $client->method('sendCommand')->willReturn(null); // Sonarr rejected

        $r = $client->manualImportFromQueueItems([['path' => '/dl/Show.S01', 'downloadId' => '']]);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['cmdId']);
        $this->assertSame(0, $r['imported']);
        $this->assertContains('Sonarr command rejected', $r['reasons']);
    }
}
