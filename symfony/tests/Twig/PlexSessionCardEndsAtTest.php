<?php

namespace App\Tests\Twig;

use App\Service\DisplayPreferencesService;
use App\Service\Media\TautulliClient;
use App\Tests\AbstractWebTestCase;

/**
 * The live Plex session card shows "Ends at <time>" when the session reports
 * a position (Tautulli 2.19 renamed its own ETA to "Ends At"), formatted in
 * the user's display time zone + clock format, and nothing when the position
 * is unknown.
 */
class PlexSessionCardEndsAtTest extends AbstractWebTestCase
{
    /** @param array<string, mixed> $extra raw get_activity session fields */
    private function session(array $extra): array
    {
        return TautulliClient::normalizeActivity(['sessions' => [[
            'session_key'        => '1',
            'state'              => 'playing',
            'full_title'         => 'Dune',
            'media_type'         => 'movie',
            'friendly_name'      => 'Viewer',
            'transcode_decision' => 'direct play',
            'progress_percent'   => '40',
        ] + $extra]])['sessions'][0];
    }

    private function render(array $s): string
    {
        return static::getContainer()->get('twig')
            ->render('dashboard/_plex_session_card.html.twig', ['s' => $s]);
    }

    public function testShowsEndsAtInDisplayTimeFormat(): void
    {
        $prefs = static::getContainer()->get(DisplayPreferencesService::class);
        $before = $prefs->formatTime(new \DateTimeImmutable('+3600 seconds'));
        $html = $this->render($this->session(['stream_duration' => '5400000', 'view_offset' => '1800000']));
        $after = $prefs->formatTime(new \DateTimeImmutable('+3600 seconds'));

        self::assertStringContainsString('plex-ends-at', $html);
        // A minute boundary can tick over mid-render: accept either side.
        self::assertTrue(
            str_contains($html, 'Ends at ' . $before) || str_contains($html, 'Ends at ' . $after),
            'expected "Ends at ' . $before . '" in: ' . $html,
        );
    }

    public function testNoEndsAtWhenPositionUnknown(): void
    {
        $html = $this->render($this->session([]));
        self::assertStringNotContainsString('plex-ends-at', $html);
        self::assertStringNotContainsString('Ends at', $html);
    }

    public function testCardWithoutTheKeyStillRenders(): void
    {
        // Older/foreign session shapes (no remainingSeconds key) must not
        // trip strict_variables.
        $s = $this->session([]);
        unset($s['remainingSeconds']);
        self::assertStringNotContainsString('plex-ends-at', $this->render($s));
    }
}
