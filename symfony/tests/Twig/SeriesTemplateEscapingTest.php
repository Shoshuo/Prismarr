<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Source sentinel: the Series page builds its download-queue rows and the
 * series-detail header line as HTML strings from Sonarr data. Release names
 * (indexer-controlled), episode titles, status messages and metadata must
 * go through esc() — the Films queue already escapes the same fields.
 */
class SeriesTemplateEscapingTest extends TestCase
{
    public function testQueueAndHeaderStringsAreEscaped(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/media/series.html.twig');

        foreach ([
            "+ (item.seriesTitle || '—') +",
            "+ (item.episode || '—') +",
            "+ (item.quality || '—') +",
            "+ statusLabel +",
            "+ firstMsg +",
            "allMsgs.replace(",
            "+ card.dataset.certification +",
            "+ card.dataset.network +",
            "+ g + '</span>'",
        ] as $raw) {
            $this->assertStringNotContainsString($raw, $src, 'unescaped sink: ' . $raw);
        }
        foreach ([
            "esc(item.seriesTitle || '—')",
            "esc(item.episode || '—')",
            "esc(item.quality || '—')",
            'esc(statusLabel)',
            'esc(firstMsg)',
            'esc(allMsgs)',
            'esc(card.dataset.certification)',
            'esc(card.dataset.network)',
            'esc(g)',
        ] as $escaped) {
            $this->assertStringContainsString($escaped, $src);
        }
    }
}
