<?php

namespace App\Tests\Service\Media;

use App\Service\Media\BazarrLangs;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the shared subtitle-language extractor. episode
 * drill-down controller calls BazarrLangs::extract() directly on the same raw
 * Bazarr dict shape, so the field-name tolerance below is the contract both
 * consumers rely on.
 */
class BazarrLangsTest extends TestCase
{
    public function testExtractReadsPresentAndMissingArrays(): void
    {
        $r = BazarrLangs::extract([
            'subtitles'         => [['name' => 'English', 'code2' => 'en', 'hi' => false, 'forced' => false]],
            'missing_subtitles' => [['name' => 'French', 'code2' => 'fr', 'hi' => false, 'forced' => false]],
        ]);

        $this->assertSame([['lang' => 'en', 'hi' => false, 'forced' => false]], $r['present']);
        $this->assertSame([['lang' => 'fr', 'hi' => false, 'forced' => false]], $r['missing']);
    }

    public function testLabelPrefersCode2ThenCode3ThenName(): void
    {
        $r = BazarrLangs::extract([
            'subtitles' => [
                ['name' => 'English', 'code2' => 'en', 'code3' => 'eng'],
                ['name' => 'French', 'code3' => 'fre'],
                ['name' => 'Spanish'],
            ],
        ]);

        $this->assertSame(['en', 'fre', 'Spanish'], array_column($r['present'], 'lang'));
    }

    public function testHearingImpairedAndForcedFlagAliases(): void
    {
        $r = BazarrLangs::extract([
            'subtitles' => [
                ['code2' => 'en', 'hi' => true, 'forced' => false],
                ['code2' => 'de', 'hearing_impaired' => true],
                ['code2' => 'fr', 'forced' => true],
            ],
        ]);

        $this->assertTrue($r['present'][0]['hi']);
        $this->assertTrue($r['present'][1]['hi'], 'hearing_impaired must be honoured as an alias of hi');
        $this->assertFalse($r['present'][2]['hi']);
        $this->assertTrue($r['present'][2]['forced']);
    }

    public function testTupleShapeFallsBackToFirstElementAsLang(): void
    {
        // Some Bazarr versions answer `subtitles` as [code2, path] tuples
        // rather than dicts — degrade to a label-only row, never throw.
        $r = BazarrLangs::extract([
            'subtitles' => [['en', '/movies/x.en.srt'], ['fr', null]],
        ]);

        $this->assertSame(['en', 'fr'], array_column($r['present'], 'lang'));
        $this->assertFalse($r['present'][0]['hi']);
        $this->assertFalse($r['present'][0]['forced']);
    }

    public function testUnusableEntriesAreDroppedNotThrown(): void
    {
        $r = BazarrLangs::extract([
            'subtitles'         => [['foo' => 'bar'], [], null, 42, ['code2' => 'en']],
            'missing_subtitles' => 'not-an-array',
        ]);

        $this->assertSame([['lang' => 'en', 'hi' => false, 'forced' => false]], $r['present']);
        $this->assertSame([], $r['missing']);
    }

    public function testMissingArraysDefaultToEmpty(): void
    {
        $this->assertSame(['present' => [], 'missing' => []], BazarrLangs::extract([]));
    }
}
