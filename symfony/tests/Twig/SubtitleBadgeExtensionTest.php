<?php

namespace App\Tests\Twig;

use App\Service\Media\BazarrSubtitleIndex;
use App\Twig\SubtitleBadgeExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SubtitleBadgeExtensionTest extends TestCase
{
    public function testStatusDispatchesToIndexByKind(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->method('movieStatus')->willReturn(['state' => 'missing', 'count' => 2]);
        $index->method('seriesStatus')->willReturn(['state' => 'complete', 'count' => 0]);
        $ext = new SubtitleBadgeExtension($index);
        $this->assertSame('missing', $ext->status('movie', 1)['state']);
        $this->assertSame('complete', $ext->status('series', 5)['state']);
    }

    public function testUnknownKindIsHidden(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $ext = new SubtitleBadgeExtension($index);
        $this->assertSame('hidden', $ext->status('bogus', 1)['state']);
    }

    public function testGetFunctionsRegistersSubtitleStatus(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $ext = new SubtitleBadgeExtension($index);
        $names = array_map(static fn ($f) => $f->getName(), $ext->getFunctions());
        $this->assertContains('subtitle_status', $names);
    }

    public function testGetFunctionsRegistersSubtitleStatusSingle(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $ext = new SubtitleBadgeExtension($index);
        $names = array_map(static fn ($f) => $f->getName(), $ext->getFunctions());
        $this->assertContains('subtitle_status_single', $names);
    }

    public function testStatusSingleDispatchesToIndexByKind(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->method('movieStatusSingle')->willReturn(['state' => 'missing', 'count' => 2]);
        $index->method('seriesStatus')->willReturn(['state' => 'complete', 'count' => 0]);
        $ext = new SubtitleBadgeExtension($index);
        $this->assertSame('missing', $ext->statusSingle('movie', 1)['state']);
        $this->assertSame('complete', $ext->statusSingle('series', 5)['state']);
    }

    public function testStatusSingleUnknownKindIsHidden(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $ext = new SubtitleBadgeExtension($index);
        $this->assertSame('hidden', $ext->statusSingle('nope', 1)['state']);
    }

    /**
     * statusSingle() reaches a live Bazarr call
     * (movieStatusSingle()'s per-id fallback) straight from a Twig render —
     * the dashboard quick-look — with no controller action in between to
     * catch a surprise exception. A throwing index double must not escape
     * this Twig function; it must degrade to the same `hidden` shape the
     * badge already renders for a gated/absent item.
     */
    public function testStatusSingleSwallowsAThrowingIndexAndAnswersHidden(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->method('movieStatusSingle')->willThrowException(new \RuntimeException('Bazarr blew up'));
        $ext = new SubtitleBadgeExtension($index);

        $this->assertSame(['state' => 'hidden', 'count' => 0], $ext->statusSingle('movie', 1));
    }
}
