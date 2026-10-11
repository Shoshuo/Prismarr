<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the bulk-delete wire contract against the real *arr editor APIs:
 *  - Radarr: DELETE /api/v3/movie/editor {movieIds, deleteFiles, addImportExclusion}
 *    (/api/v3/movie/bulk does not exist and answers 404).
 *  - Sonarr: DELETE /api/v3/series/editor {seriesIds, deleteFiles, addImportListExclusion}
 *    (an unknown key is silently ignored, so the exclusion box did nothing).
 */
#[AllowMockObjectsWithoutExpectations]
class ArrBulkDeletePayloadTest extends TestCase
{
    public function testRadarrBulkDeleteTargetsTheMovieEditorEndpoint(): void
    {
        $client = $this->getMockBuilder(RadarrClient::class)
            ->setConstructorArgs([
                $this->createMock(ServiceInstanceProvider::class),
                new NullLogger(),
                $this->createMock(ServiceHealthCache::class),
            ])
            ->onlyMethods(['deleteWithBody'])
            ->getMock();

        $client->expects($this->once())
            ->method('deleteWithBody')
            ->with('/api/v3/movie/editor', [
                'movieIds'           => [3, 7],
                'deleteFiles'        => true,
                'addImportExclusion' => true,
            ])
            ->willReturn(true);

        $this->assertTrue($client->bulkDeleteMovies([3, 7], true, true));
    }

    public function testSonarrBulkDeleteSendsTheImportListExclusionFlag(): void
    {
        $client = $this->getMockBuilder(SonarrClient::class)
            ->setConstructorArgs([
                $this->createMock(ServiceInstanceProvider::class),
                new NullLogger(),
                $this->createMock(ServiceHealthCache::class),
            ])
            ->onlyMethods(['deleteWithBody'])
            ->getMock();

        $client->expects($this->once())
            ->method('deleteWithBody')
            ->with('/api/v3/series/editor', [
                'seriesIds'              => [12],
                'deleteFiles'            => false,
                'addImportListExclusion' => true,
            ])
            ->willReturn(true);

        $this->assertTrue($client->bulkDeleteSeries([12], false, true));
    }
}
