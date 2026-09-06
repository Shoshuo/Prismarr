<?php

namespace App\Tests\Service\Media\Usenet;

use App\Service\ConfigService;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\Usenet\NzbgetClient;
use App\Service\Media\Usenet\UsenetDownload;
use App\Service\Media\Usenet\UsenetStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins the NZBGet → normalized mapping. The defining quirk: NZBGet splits
 * every 64-bit byte count into a 32-bit Lo/Hi pair, so a 5 GiB job arrives as
 * (Lo, Hi=1) and must recombine to exactly 5 * 2^30 bytes — and fall back to
 * the deprecated `<field>MB` int when the pair is missing.
 */
#[AllowMockObjectsWithoutExpectations]
class NzbgetClientTest extends TestCase
{
    private function makeClient(): NzbgetClient
    {
        $cfg = $this->createMock(ConfigService::class);
        $cfg->method('get')->willReturn('http://localhost');
        return new NzbgetClient($cfg, new NullLogger(), $this->createMock(ServiceHealthCache::class));
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(NzbgetClient::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->makeClient(), ...$args);
    }

    public function testCombineBytesRecombinesLoHiPair(): void
    {
        // 5 GiB = 5 * 2^30 = 5368709120 = Hi 1 * 2^32 + Lo 1073741824
        $bytes = $this->call('combineBytes', ['FileSizeLo' => 1073741824, 'FileSizeHi' => 1], 'FileSize');
        self::assertSame(5368709120, $bytes);
    }

    public function testCombineBytesHandlesUnsignedLowHalf(): void
    {
        // NZBGet sends the low half as a SIGNED 32-bit int: 0xFFFFFFFF arrives
        // as -1; it must recombine to the unsigned value, not undercount by ~4GB.
        $bytes = $this->call('combineBytes', ['FileSizeLo' => -1, 'FileSizeHi' => 1], 'FileSize');
        self::assertSame(4294967296 + 4294967295, $bytes);
    }

    public function testCombineBytesFallsBackToMbWhenPairAbsent(): void
    {
        $bytes = $this->call('combineBytes', ['FileSizeMB' => 100], 'FileSize');
        self::assertSame(100 * 1024 * 1024, $bytes);
    }

    public function testNormalizeGroupClampsNegativePercentage(): void
    {
        // NZBGet can report RemainingSize > FileSize during post-processing,
        // which would otherwise produce a negative percentage.
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeGroup', [
            'NZBID' => 1, 'NZBName' => 'x', 'Status' => 'DOWNLOADING',
            'FileSizeLo' => 1000, 'FileSizeHi' => 0,
            'RemainingSizeLo' => 2000, 'RemainingSizeHi' => 0,
        ]);

        self::assertSame(0.0, $d->percentage);
    }

    public function testNormalizeGroupComputesPercentageFromRemaining(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeGroup', [
            'NZBID'           => 42,
            'NZBName'         => 'Prismarr.Test',
            'Status'          => 'DOWNLOADING',
            'Category'        => 'movies',
            'FileSizeLo'      => 1000,
            'FileSizeHi'      => 0,
            'RemainingSizeLo' => 250,
            'RemainingSizeHi' => 0,
            'DownloadRate'    => 5000,
        ]);

        self::assertSame('42', $d->id);
        self::assertSame('Prismarr.Test', $d->name);
        self::assertSame(UsenetStatus::DOWNLOADING, $d->status);
        self::assertSame(1000, $d->sizeBytes);
        self::assertSame(250, $d->remainingBytes);
        self::assertSame(75.0, $d->percentage); // (1000-250)/1000
        self::assertSame(5000, $d->speedBytes);
        self::assertFalse($d->isHistory);
    }

    public function testNormalizeHistoryFailureKeepsRawStatusAsMessage(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeHistory', [
            'NZBID'      => 7,
            'Name'       => 'Broken.Release',
            'Status'     => 'FAILURE/PAR',
            'FileSizeMB' => 700,
        ]);

        self::assertSame(UsenetStatus::FAILED, $d->status);
        self::assertSame(700 * 1024 * 1024, $d->sizeBytes);
        self::assertSame('FAILURE/PAR', $d->failMessage);
        self::assertSame(0.0, $d->percentage);
        self::assertTrue($d->isHistory);
    }

    public function testNormalizeHistorySuccessIsHundredPercentNoMessage(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeHistory', [
            'NZBID'  => 8,
            'Name'   => 'Good.Release',
            'Status' => 'SUCCESS/ALL',
            'FileSizeMB' => 10,
        ]);

        self::assertSame(UsenetStatus::COMPLETED, $d->status);
        self::assertSame(100.0, $d->percentage);
        self::assertNull($d->failMessage);
    }

    public function testNormalizeHistoryExposesHistoryTimeAsCompletedAt(): void
    {
        // NZBGet stamps each history entry with HistoryTime (unix epoch) — the
        // moment it landed in history, i.e. when the job finished (#47).
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeHistory', [
            'NZBID'       => 9,
            'Name'        => 'Timed.Release',
            'Status'      => 'SUCCESS/ALL',
            'FileSizeMB'  => 10,
            'HistoryTime' => 1755800000,
        ]);

        self::assertSame(1755800000, $d->completedAt);
    }

    public function testNormalizeHistoryWithoutHistoryTimeIsNull(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeHistory', [
            'NZBID'  => 10,
            'Name'   => 'Undated.Release',
            'Status' => 'SUCCESS/ALL',
        ]);

        self::assertNull($d->completedAt);
    }

    public function testGetKind(): void
    {
        self::assertSame('nzbget', $this->makeClient()->getKind());
    }
}
