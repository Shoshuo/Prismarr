<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Review 2026-11-06 §5 hygiene — the cheap template/JS fixes that have no
 * PHP seam: the settings Clear flag resets when the user types again
 * (AS-11), the series queue sizes honour the byte-unit preference (MH-3),
 * the series history overlay understands Sonarr v4's string enums (MT-11),
 * the films backdrop does not reset the background shorthand (MT-12), the
 * missing-films poll regex is permissive like films/series (MT-13), the
 * AssetMapper welcome log is gone (MH-12) and the Seerr confirm key points
 * at its real namespace (MH-5).
 */
class ReviewHygieneGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private static function src(string $rel): string
    {
        $s = file_get_contents(self::ROOT . $rel);
        self::assertNotFalse($s, $rel);
        return $s;
    }

    public function testClearFlagIsResetWhenTheUserTypesANewValue(): void
    {
        $s = self::src('templates/admin/settings.html.twig');
        $this->assertStringContainsString("flag.value = '0';", $s, 'Clear → type → Save must store the typed value, not the wipe');
    }

    public function testSeriesQueueSizesUseTheByteUnitPreference(): void
    {
        $s = self::src('templates/media/series.html.twig');
        $this->assertMatchesRegularExpression('/function fmtSize\(bytes\) \{\s*if \(window\.prismarrBytes\) return window\.prismarrBytes\(bytes\);/', $s);
    }

    public function testSeriesHistoryOverlayMapsSonarrV4StringEnums(): void
    {
        $s = self::src('templates/media/series.html.twig');
        $this->assertSame(2, substr_count($s, 'downloadFolderImported: {{'), 'both history overlays');
        $this->assertSame(2, substr_count($s, "downloadFolderImported:'primary'"));
    }

    public function testFilmsBackdropDoesNotResetTheBackgroundShorthand(): void
    {
        $s = self::src('templates/media/films.html.twig');
        $this->assertStringNotContainsString("backdrop.style.background = 'var(--tblr-bg-surface)';", $s);
        $this->assertStringContainsString("backdrop.style.backgroundColor = 'var(--tblr-bg-surface)';", $s);
    }

    public function testMissingFilmsPollRegexIsPermissive(): void
    {
        $s = self::src('templates/media/films_missing.html.twig');
        $this->assertStringNotContainsString('/(\d+) reports downloaded/', $s);
        $this->assertSame(2, substr_count($s, '/(\d+)\s+reports?\s+(?:downloaded|grabbed)/i'));
    }

    public function testAssetMapperWelcomeLogIsGone(): void
    {
        $this->assertStringNotContainsString('console.log', self::src('assets/app.js'));
    }

    public function testSeerrDeleteConfirmUsesTheCommonNamespace(): void
    {
        $s = self::src('templates/jellyseerr/user_detail.html.twig');
        $this->assertStringContainsString("'jellyseerr.common.confirm_delete_user_irreversible'|trans", $s);
        $this->assertStringNotContainsString("'jellyseerr.user_detail.confirm_delete_user_irreversible'", $s);
    }
}
