<?php

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three torrent client pages each poll on a 3s setInterval. Turbo Drive
 * re-executes the incoming page's <script> with a fresh closure while the
 * outgoing page's closure — and its live interval — survive. Because all three
 * pages render the SAME element IDs (qbt-list, qbt-stat-total, ...), an orphaned
 * poller's updateList()/updateStats() guards still pass on its successor's DOM,
 * so it keeps writing its own client's torrents over the page the user is
 * actually looking at. Two pollers then fight at 3s each and it reads as data
 * corruption ("Transmission is showing my qBittorrent torrents").
 *
 * The handle therefore may NOT live in the page closure: it has to sit on a
 * single shared global so the incoming page — and base.html.twig's
 * turbo:before-render cleanup — can reach the outgoing page's timer and kill it.
 * One name serves all three because only one torrent page is ever mounted.
 */
class TorrentPagePollerTest extends TestCase
{
    private const TIMER = '_prismarrTorrentPagePollTimer';

    private const TEMPLATES = [
        'qbittorrent'  => __DIR__ . '/../../templates/qbittorrent/index.html.twig',
        'deluge'       => __DIR__ . '/../../templates/deluge/index.html.twig',
        'transmission' => __DIR__ . '/../../templates/transmission/index.html.twig',
    ];

    private const BASE_TWIG = __DIR__ . '/../../templates/base.html.twig';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function templateProvider(): iterable
    {
        foreach (array_keys(self::TEMPLATES) as $page) {
            yield $page => [$page];
        }
    }

    #[DataProvider('templateProvider')]
    public function testPollTimerHandleIsNotHeldInThePageClosure(string $page): void
    {
        // A closure-scoped `refreshTimer` is exactly the bug: clearInterval on it
        // can only ever reach THIS script instance's timer, never the orphan left
        // behind by the page Turbo just replaced.
        self::assertDoesNotMatchRegularExpression(
            '/\brefreshTimer\b/',
            $this->template($page),
            "{$page}/index.html.twig still references a closure-scoped refreshTimer; "
                . 'the handle must live on window.' . self::TIMER . '.',
        );
    }

    #[DataProvider('templateProvider')]
    public function testPollTimerIsStoredOnTheSharedGlobal(string $page): void
    {
        self::assertStringContainsString(
            'window.' . self::TIMER . ' = setInterval(refreshData, REFRESH_INTERVAL);',
            $this->template($page),
            "{$page}/index.html.twig must publish its poll interval on the shared global.",
        );
    }

    #[DataProvider('templateProvider')]
    public function testStartAndStopRefreshBothClearTheSharedGlobal(string $page): void
    {
        $tpl = $this->template($page);

        // Both entry points must go through the shared handle. startRefresh()
        // clearing it is what makes a torrent -> torrent navigation self-healing
        // even before the base.html.twig cleanup runs.
        foreach (['startRefresh', 'stopRefresh'] as $fn) {
            self::assertMatchesRegularExpression(
                '/function ' . $fn . '\(\)\s*\{[^}]*clearPollTimer\(\);/',
                $this->excerpt($tpl, 'function ' . $fn . '()'),
                "{$page}/index.html.twig: {$fn}() must clear the shared poll timer.",
            );
        }

        self::assertMatchesRegularExpression(
            '/clearInterval\(window\.' . self::TIMER . '\);\s*window\.' . self::TIMER . ' = null;/',
            $this->excerpt($tpl, 'function clearPollTimer()'),
            "{$page}/index.html.twig: clearPollTimer() must clear AND null the shared global.",
        );
    }

    public function testTurboBeforeRenderClearsTheTorrentPagePollTimer(): void
    {
        // Navigating a torrent page -> a NON-torrent page runs no torrent script,
        // so nothing else would ever clear the interval. This cleanup is the only
        // thing standing between that navigation and a permanently orphaned poller.
        self::assertStringContainsString(
            "'" . self::TIMER . "'",
            $this->cleanupArray(),
            'base.html.twig turbo:before-render cleanup must list ' . self::TIMER . '.',
        );
    }

    public function testCleanupArrayStillListsThePreviouslyRegisteredTimers(): void
    {
        // Guards the same way TransmissionRegistrationTest does: adding the new
        // shared handle must not drop any handle already being cleaned up.
        $array = $this->cleanupArray();

        foreach ([
            '_prismarrQbtPollTimer',
            '_prismarrQbtVpnTimer',
            '_prismarrDelugePollTimer',
            '_prismarrUnifiPollTimer',
            '_prismarrTransmissionPollTimer',
        ] as $timer) {
            self::assertStringContainsString("'{$timer}'", $array, "cleanup array dropped {$timer}.");
        }
    }

    private function template(string $page): string
    {
        return file_get_contents(self::TEMPLATES[$page]);
    }

    /**
     * Asserting against a whole 168 KB template dumps the entire file into the
     * failure message. Narrow to the region of interest so a regression reads.
     */
    private function excerpt(string $haystack, string $needle, int $len = 400): string
    {
        $pos = strpos($haystack, $needle);
        self::assertNotFalse($pos, "expected to find \"{$needle}\" in the template.");

        return substr($haystack, $pos, $len);
    }

    private function cleanupArray(): string
    {
        $base = file_get_contents(self::BASE_TWIG);
        // The turbo:before-render cleanup array — the single `[...]` literal that
        // is forEach'd over and clearInterval'd, ~line 2357.
        self::assertSame(
            1,
            preg_match('/\[\s*\'_prismarrQbtPollTimer\'[^\]]*\]/', $base, $m),
            'could not locate the turbo:before-render cleanup array in base.html.twig.',
        );

        return $m[0];
    }
}
