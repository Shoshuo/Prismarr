<?php

namespace App\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Review 2026-11-06 (MH-note): whole-catalog EN/FR parity, plus "every
 * literal key the templates and controllers ask for exists" — the two
 * checks that would have caught the raw Seerr confirm key (MH-5) and a
 * missing English `errors` catalog (MH-6).
 */
class CatalogParityTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public function testMessagesCatalogsHaveIdenticalKeySets(): void
    {
        $en = self::flatten(Yaml::parseFile(self::ROOT . 'translations/messages+intl-icu.en.yaml'));
        $fr = self::flatten(Yaml::parseFile(self::ROOT . 'translations/messages+intl-icu.fr.yaml'));

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($fr))), 'keys only in EN');
        $this->assertSame([], array_values(array_diff(array_keys($fr), array_keys($en))), 'keys only in FR');
    }

    public function testErrorsCatalogExistsInBothLocalesWithTheSameKeys(): void
    {
        $this->assertFileExists(self::ROOT . 'translations/errors.en.yaml', 'EN dev users saw the raw profiler.access_denied_remote key (MH-6)');
        $en = self::flatten(Yaml::parseFile(self::ROOT . 'translations/errors.en.yaml'));
        $fr = self::flatten(Yaml::parseFile(self::ROOT . 'translations/errors.fr.yaml'));
        $this->assertSame(array_keys($fr), array_keys($en));
    }

    public function testEveryLiteralKeyUsedByTemplatesAndControllersExists(): void
    {
        $messages = self::flatten(Yaml::parseFile(self::ROOT . 'translations/messages+intl-icu.en.yaml'));
        $errors   = self::flatten(Yaml::parseFile(self::ROOT . 'translations/errors.en.yaml'));
        $known    = $messages + $errors;

        $missing = [];
        foreach (self::files(self::ROOT . 'templates', 'twig') as $file) {
            preg_match_all("/['\"]([a-z0-9_]+(?:\\.[a-z0-9_]+)+)['\"]\\s*\\|\\s*trans\\b/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                if (!array_key_exists($key, $known)) {
                    $missing[$key] = substr($file, strlen(self::ROOT));
                }
            }
        }
        foreach (self::files(self::ROOT . 'src', 'php') as $file) {
            preg_match_all("/->trans\\(\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                if (!array_key_exists($key, $known)) {
                    $missing[$key] = substr($file, strlen(self::ROOT));
                }
            }
        }

        $this->assertSame([], $missing, 'translation keys referenced but absent from the EN catalogs');
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function files(string $dir, string $ext): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.' . $ext)) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}
