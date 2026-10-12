<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Turbo re-runs the Search page script on every visit, so a listener bound
 * straight on `document` piles up one copy per visit. Every document-level
 * listener of the page must go through bindDoc(), which swaps the old copy.
 */
final class SearchPageListenersTest extends TestCase
{
    public function testDocumentListenersGoThroughBindDoc(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/decouverte/index.html.twig');

        self::assertStringContainsString('function bindDoc(type, name, fn)', $src);
        preg_match_all("/document\.addEventListener\(/", $src, $raw);
        self::assertCount(1, $raw[0], 'only bindDoc() itself may call document.addEventListener');

        preg_match_all("/bindDoc\('[a-z]+', '([a-z0-9]+)'/", $src, $names);
        self::assertNotEmpty($names[1]);
        self::assertSame(count($names[1]), count(array_unique($names[1])), 'each bindDoc() slot name must be unique');
    }
}
