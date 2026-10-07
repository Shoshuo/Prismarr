<?php

namespace App\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ItalianTranslationTest extends TestCase
{
    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $path = dirname(__DIR__, 2) . '/translations/messages+intl-icu.' . $locale . '.yaml';

        return $this->flatten(Yaml::parseFile($path));
    }

    /** @return array<string, string> */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }

    public function testItalianCatalogMatchesEnglishKeysAndPreservesArguments(): void
    {
        $english = $this->catalog('en');
        $italian = $this->catalog('it');
        $this->assertSame(array_keys($english), array_keys($italian));

        foreach ($english as $key => $message) {
            // ICU argument names and JS replacement tokens must stay unchanged.
            $pattern = '/\{([a-zA-Z_][\w]*)(?=\s*[,}])|__[A-Z_]+__|%[\w]+%/';
            preg_match_all($pattern, $message, $sourceTokens);
            preg_match_all($pattern, $italian[$key], $translatedTokens);
            sort($sourceTokens[0]);
            sort($translatedTokens[0]);
            $this->assertSame($sourceTokens[0], $translatedTokens[0], $key);

            preg_match_all('/<\/?[a-zA-Z][^>]*>/', $message, $sourceTags);
            preg_match_all('/<\/?[a-zA-Z][^>]*>/', $italian[$key], $translatedTags);
            $this->assertSame($sourceTags[0], $translatedTags[0], $key);
        }
    }

    public function testAllItalianMessagesCompileWithIcu(): void
    {
        foreach ($this->catalog('it') as $key => $message) {
            $this->assertNotFalse(\MessageFormatter::create('it', $message), $key);
        }
    }

    public function testItalianPluralMessagesRender(): void
    {
        $catalog = $this->catalog('it');
        $formatter = new \MessageFormatter('it', $catalog['topbar.health.summary_partial']);

        $this->assertSame('1 servizio non disponibile', $formatter->format(['count' => 1]));
        $this->assertSame('2 servizi non disponibili', $formatter->format(['count' => 2]));
    }
}
