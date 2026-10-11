<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Behind a reverse proxy that mounts Prismarr under a sub-path, a JS URL
 * starting with "/medias/..." or "/bazarr/..." escapes the prefix and 404s.
 * Every app URL built in a template must go through path() / BASE /
 * window.BASE_PATH / getBasePath() (see the reverse-proxy prefix fix).
 */
final class HardcodedRootPathTest extends TestCase
{
    private const APP_PREFIXES = 'admin|api|bazarr|calendrier|decouverte|deluge|emby|films|jellyseerr|medias|prowlarr|qbittorrent|series|tableau-de-bord|tautulli|tmdb|tracearr|transmission|usenet';

    public function testNoTemplateBuildsAnUnprefixedAppUrl(): void
    {
        $pattern = '#[\'"]/(?:' . self::APP_PREFIXES . ')(?:/|[\'"?])#';
        $offenders = [];

        $finder = (new Finder())->files()->in(__DIR__ . '/../../templates')->name('*.twig');
        foreach ($finder as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (!preg_match($pattern, $line)) {
                    continue;
                }
                if (preg_match('#BASE|getBasePath|path\(|url\(|\{\{|https?://#', $line)) {
                    continue;
                }
                $offenders[] = $file->getRelativePathname() . ':' . ($i + 1) . '  ' . trim($line);
            }
        }

        self::assertSame([], $offenders, "Unprefixed app URLs (use window.BASE_PATH or path()):\n" . implode("\n", $offenders));
    }
}
