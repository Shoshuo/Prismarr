<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Review 2026-11-06 — source guards for the *arr write paths that live in
 * inline template JS (H1, H3, H4, H5, AC-10). The behaviour is covered by
 * MediaArrWritePathsTest / ArrClientWriteContractTest; these pin the JS
 * shape that feeds those controllers.
 */
class ArrWriteTemplateGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../templates/';

    private static function src(string $tpl): string
    {
        $s = file_get_contents(self::ROOT . $tpl);
        self::assertNotFalse($s, $tpl);
        return $s;
    }

    // H1 — the Sonarr library import must send the scanned folder
    public function testSonarrLibraryImportSendsTheScannedFolderPath(): void
    {
        $s = self::src('sonarr/library_import.html.twig');
        $this->assertMatchesRegularExpression('/path:\s*tr\.dataset\.path/', $s, 'the POST /series/add body must carry the scanned folder as `path` (Radarr twin does)');
    }

    // H4 — no literal language table; the file managers read /languages
    public function testFileManagersDoNotShipTheWrongLiteralLanguageTable(): void
    {
        foreach (['media/films.html.twig', 'media/series.html.twig'] as $tpl) {
            $s = self::src($tpl);
            $this->assertStringNotContainsString("id: 13, name: 'Japanese'", $s, "$tpl: id 13 is Vietnamese in both *arr apps");
            $this->assertStringNotContainsString("id: 17, name: 'Korean'", $s, "$tpl: id 17 is Turkish");
            $this->assertStringNotContainsString("id: 7,  name: 'Portuguese'", $s, "$tpl: id 7 is Dutch");
        }
        $this->assertStringContainsString("'/films/languages'", self::src('media/films.html.twig'));
        $this->assertStringContainsString("'/series/languages'", self::src('media/series.html.twig'));
    }

    // H5 — every stored value must be representable in the row's selects
    public function testSeriesFileManagerOffersEveryReleaseTypeSonarrStores(): void
    {
        $s = self::src('media/series.html.twig');
        $this->assertStringContainsString("value: 'unknown'", $s, 'Sonarr ReleaseType.Unknown (0) is a stored value');
        $this->assertStringContainsString("value: 'multiSeasonPack'", $s, 'Sonarr ReleaseType.MultiSeasonPack (4) is a stored value');
        $this->assertMatchesRegularExpression('/fm_indexer_flag_combined/', $s, 'a combined indexerFlags bitmask needs its own option or the row is dirty from render');
        $this->assertMatchesRegularExpression('/LANG_LIST\.slice\(\)[\s\S]{0,200}f\.languages/', $s, 'the file\'s own languages are merged into the option list');
        $this->assertMatchesRegularExpression('/LANG_LIST\.slice\(\)[\s\S]{0,200}f\.languages/', self::src('media/films.html.twig'));
    }

    // AC-10 — path / root-folder edits expose "Move files"
    public function testEditFormsExposeMoveFiles(): void
    {
        $films = self::src('media/films.html.twig');
        $this->assertStringContainsString('id="edit-move-files"', $films);
        $this->assertStringContainsString('id="bulk-edit-move-files"', $films);
        $this->assertMatchesRegularExpression('/moveFiles:\s*document\.getElementById\(\'edit-move-files\'\)\.checked/', $films);
        $this->assertMatchesRegularExpression('/payload\.moveFiles\s*=\s*document\.getElementById\(\'bulk-edit-move-files\'\)\.checked/', $films);

        $series = self::src('media/series.html.twig');
        $this->assertStringContainsString('id="edit-move-files"', $series);
        $this->assertStringContainsString('id="bulk-s-move-files"', $series);
        $this->assertMatchesRegularExpression('/moveFiles:\s*document\.getElementById\(\'edit-move-files\'\)\.checked/', $series);
        $this->assertMatchesRegularExpression('/payload\.moveFiles\s*=\s*document\.getElementById\(\'bulk-s-move-files\'\)\.checked/', $series);
    }

    // H3 — the queue "Import" only removes the item once Radarr reports it imported
    public function testFilmsQueueImportConfirmsTheImportBeforeDeletingTheQueueItem(): void
    {
        $s = self::src('media/films.html.twig');
        $this->assertMatchesRegularExpression('/data\.imported/', $s, 'the preview result (imported/skipped/reasons) drives the UI');
        $this->assertMatchesRegularExpression('/trackedState[^;\n]{0,40}===\s*\'imported\'/', $s, 'delete the queue item only when Radarr reports it imported');
        $this->assertStringContainsString('q_import_unconfirmed', $s);
    }
}
