<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Review 2026-11-06 §3 — source guards for the inline-JS fixes that have no
 * PHP seam: Turbo snapshot init markers (H6), torrent poll in-flight guard
 * (DC-3), usenet visibility lifecycle (DC-2), films queue-leaver check
 * (MT-6), command-poller terminal states / caps / lifecycle / safe reloads
 * (MT-7), hidden-tab pause of the films warnings poll (MT-8), stale-response
 * tokens (MT-9), Tautulli load-more (MS-12), Discover instance slugs (DD-5),
 * the 503 page block name (AS-8).
 */
class ReviewSection3TemplateGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../templates/';

    private static function src(string $tpl): string
    {
        $s = file_get_contents(self::ROOT . $tpl);
        self::assertNotFalse($s, $tpl);
        return $s;
    }

    // H6 — a cloned data-init attribute must not disable the restored search box / sidebar toggle
    public function testTopbarInitMarkersAreNotClonedWithTheTurboSnapshot(): void
    {
        $base = self::src('base.html.twig');
        $this->assertStringNotContainsString('input.dataset.init', $base, 'data-init survives cloneNode(true); listeners do not');
        $this->assertStringNotContainsString('btn.dataset.init', $base);
        $this->assertGreaterThanOrEqual(2, substr_count($base, 'window._prismarrBound'), 'a WeakSet marker on the live element');
        $this->assertStringContainsString('window._prismarrSearchDocHandlers', $base, 'the previous document-level pair is removed before a new one is bound');
    }

    // DC-3 — a timer tick must not abort a fetch that is still in flight
    public function testTorrentPagesSkipTheTickWhileAFetchIsInFlight(): void
    {
        foreach (['qbittorrent/index.html.twig', 'deluge/index.html.twig', 'transmission/index.html.twig'] as $tpl) {
            $s = self::src($tpl);
            $this->assertStringContainsString('function refreshData(force)', $s, $tpl);
            $this->assertStringContainsString('if (refreshInFlight && !force) return;', $s, $tpl);
            // Final review, Critical #1: Deluge got the in-flight half of the
            // port without the sequence counter it references — a
            // ReferenceError in the finally-callback left refreshInFlight stuck
            // at true and the page never auto-refreshed again. The parts of
            // the pattern must travel together.
            $this->assertStringContainsString('var refreshSeq = 0;', $s, $tpl);
            $this->assertStringContainsString('var seq = ++refreshSeq;', $s, $tpl);
            $this->assertStringContainsString('if (seq !== refreshSeq) return;', $s, $tpl);
            $this->assertStringContainsString('if (seq === refreshSeq) refreshInFlight = false;', $s, $tpl);
            $this->assertStringContainsString('setInterval(refreshData, REFRESH_INTERVAL)', $s, "$tpl keeps the lifecycle-tracked timer line");
            $this->assertStringNotContainsString("refreshData();\n", str_replace("\r\n", "\n", $s), "$tpl: user-driven calls pass force=true");
        }
    }

    // Final review, Important #4 — giving up on a poll is not a failure
    public function testCommandPollersDistinguishStalledFromFailed(): void
    {
        $pages = [
            'media/films.html.twig'         => ['FILM_I18N.w_cmd_stalled',   'w_cmd_stalled'],
            'media/series.html.twig'        => ['SERIES_I18N.w_cmd_stalled', 'w_cmd_stalled'],
            'media/films_missing.html.twig' => ['FM_I18N.cmd_stalled',       'cmd_stalled'],
        ];
        foreach ($pages as $tpl => [$use, $def]) {
            $s = self::src($tpl);
            $this->assertStringNotContainsString(">= CMD_POLL_CAP) return 'failed';", $s, "$tpl: the cap is a watchdog, not a verdict — a long MoviesSearch is not a failure");
            $this->assertStringContainsString(">= CMD_POLL_CAP) return 'stalled';", $s, $tpl);
            $this->assertStringNotContainsString("? 'failed' : 'unknown'", $s, "$tpl: five unknown answers are a Radarr blip, not a failure");
            $this->assertStringContainsString('_cmdUnknown[key]', $s, "$tpl: the unknown counter is per command, not shared by every poller on the page");
            $failed  = preg_match_all("/else if \\((?:st|status) === 'failed'/", $s);
            $stalled = preg_match_all("/else if \\((?:st|status) === 'failed' \\|\\| (?:st|status) === 'stalled'\\)/", $s);
            $this->assertGreaterThan(0, $failed, $tpl);
            $this->assertSame($failed, $stalled, "$tpl: every failed branch must also stop on 'stalled'");
            $this->assertMatchesRegularExpression("/\\b{$def}: \\{\\{ '[a-z_.]+'\\|trans\\|json_encode\\|raw \\}\\}/", $s, "$tpl: a neutral 'still running — stopped checking' string");
            $this->assertGreaterThanOrEqual($failed, substr_count($s, $use), "$tpl: every stopped branch shows the neutral string");
        }
        $films = self::src('media/films.html.twig');
        $this->assertStringNotContainsString("(st.status || '').toLowerCase()", $films, 'the queue command poller goes through cmdState() too');
    }

    // Final review, Important #2 — episode air times are instants, release dates are calendar days
    public function testQuickLookDateChipsHonourTheirFloatingFlag(): void
    {
        $s = self::src('dashboard/_quicklook_body.html.twig');
        $this->assertStringContainsString(
            'd.floating|default(true) ? d.date|prismarr_date(true) : d.date|prismarr_date',
            $s,
            "Sonarr's nextAiring/previousAiring are UTC instants: floating them shows the UTC day, off by one for an evening broadcast west of UTC"
        );
    }

    // DC-2 — the usenet visibility handler must belong to the current page instance
    public function testUsenetVisibilityHandlerIsBoundPerPageInstance(): void
    {
        $s = self::src('usenet/index.html.twig');
        $this->assertStringNotContainsString('_usenetVisBound', $s, 'the once-only binding captured the first instance\'s poll/QUEUE_URL/root');
        $this->assertStringContainsString("document.removeEventListener('visibilitychange', onVis)", $s);
        $this->assertStringContainsString('window.registerPageLifecycle(', $s);
        $this->assertMatchesRegularExpression('/function poll\(\) \{\s*if \(!root\.isConnected\) return;/', $s);
    }

    // MT-6 — leaving the queue is not "imported"
    public function testFilmsQueueLeaversAreVerifiedBeforeBeingPaintedDownloaded(): void
    {
        $s = self::src('media/films.html.twig');
        $this->assertMatchesRegularExpression(
            "/\\/films\\/' \\+ mid \\+ '\\/info'\\)\\.then\\(function\\(movie\\) \\{\\s*if \\(!movie \\|\\| !movie\\.id \\|\\| !movie\\.hasFile\\) return;/",
            $s,
            'ask Radarr for the file state before flipping the card'
        );
        $this->assertStringContainsString('if (!Array.isArray(items)) return;', $s, 'an error envelope must not look like an empty queue');
    }

    // MT-7 — command pollers end on every *arr terminal state, are capped, torn down on navigation, and never reload another page
    public function testCommandPollersAreBoundedAndTurboSafe(): void
    {
        foreach (['media/films.html.twig' => 'movies-grid', 'media/series.html.twig' => 'series-grid', 'media/films_missing.html.twig' => 'btn-search-all'] as $tpl => $root) {
            $s = self::src($tpl);
            $this->assertStringContainsString("var CMD_TERMINAL = ['failed', 'aborted', 'cancelled', 'orphaned'];", $s, $tpl);
            $this->assertStringContainsString('function cmdState(cmd)', $s, $tpl);
            $this->assertSame(1, substr_count($s, "(cmd.status || '').toLowerCase()"), "$tpl: every poller reads the status through cmdState()");
            $this->assertStringNotContainsString('setInterval(function', $s, "$tpl: anonymous pollers go through cmdInterval() so Turbo teardown clears them");
            $this->assertSame(1, substr_count($s, 'location.reload()'), "$tpl: reloads only through safeReload()");
            $this->assertStringContainsString("function safeReload() { if (document.getElementById('{$root}')) location.reload(); }", $s, $tpl);
        }
    }

    // MT-8 — the films warnings poll pauses while the tab is hidden (series already does)
    public function testFilmsWarningsPollPausesWhileHidden(): void
    {
        $s = self::src('media/films.html.twig');
        $this->assertMatchesRegularExpression('/function refreshWarnings\(\) \{\s*if \(document\.hidden\) return;/', $s);
    }

    // MT-9 — stale responses must not win
    public function testLookupsAndQuickLookCarrySequenceTokens(): void
    {
        $films = self::src('media/films.html.twig');
        $this->assertMatchesRegularExpression('/function doAddSearch\(\) \{[\s\S]{0,600}var seq = \+\+addSearchSeq;/', $films);
        $this->assertMatchesRegularExpression('/\.then\(function \(movies\) \{\s*if \(seq !== addSearchSeq\) return;/', $films);

        $series = self::src('media/series.html.twig');
        $this->assertStringContainsString('var seq = ++addLookupSeq;', $series);
        $this->assertMatchesRegularExpression('/\.then\(function\(results\) \{\s*if \(seq !== addLookupSeq\) return;/', $series);
        $this->assertMatchesRegularExpression('/\.catch\(function\(\) \{\s*if \(seq !== addLookupSeq\) return;/', $series, 'a failed lookup must not leave the spinner forever');
        $this->assertStringContainsString("(importInFlight ? ' disabled' : '')", $series, 'the re-rendered Import button stays disabled mid-POST');

        $ql = self::src('_quicklook.html.twig');
        $this->assertMatchesRegularExpression('/\.catch\(function \(\) \{\s*if \(seq !== qlSeq\) return;/', $ql, 'a stale failure must not navigate away');
        $this->assertMatchesRegularExpression('/\.then\(function \(html\) \{\s*if \(seq !== qlSeq\) return;/', $ql);

        $base = self::src('base.html.twig');
        $this->assertStringContainsString('var seq = ++qaSeq;', $base);
        $this->assertMatchesRegularExpression('/\.then\(function \(resolved\) \{\s*if \(seq !== qaSeq\) return;/', $base);
        $this->assertMatchesRegularExpression("/clearBtn\\.addEventListener\\('click', function \\(\\) \\{\\s*if \\(currentReq\\) \\{ currentReq\\.abort\\(\\);/", $base, 'Clear aborts the in-flight search like a typed clear does');
    }

    // MS-12 — the Tautulli "Load more" row count and double-click
    public function testTautulliLoadMoreCountsRowsNotCells(): void
    {
        $s = self::src('tautulli/index.html.twig');
        $this->assertStringContainsString('/class="plex-hist-cell(?:\s|")/g', $s, 'plex-hist-cell-title/-meta/-main must not count as rows');
        $this->assertStringContainsString('if (historyLoading) return;', $s);
    }

    // DD-5 — Discover links target the instance that owns the item
    public function testDiscoverLibraryLinksUseTheOwningInstanceSlug(): void
    {
        $s = self::src('decouverte/index.html.twig');
        $this->assertStringNotContainsString("BASE + '/medias/' + DEFAULT_RADARR_SLUG + '/films'", $s);
        $this->assertStringNotContainsString("BASE + '/medias/' + DEFAULT_SONARR_SLUG + '/series'", $s);
        $this->assertGreaterThanOrEqual(3, substr_count($s, 'lib_slug'));
        $this->assertStringContainsString("item.lib_slug|default(current_slug('radarr'))", $s);
        $php = (string) file_get_contents(__DIR__ . '/../../src/Controller/TmdbController.php');
        $this->assertGreaterThanOrEqual(3, substr_count($php, "'lib_slug'"), 'enrich/resolve/detail all carry the owning slug');
    }

    // AS-8 — the 503 page renders into base's `body` block
    public function testServiceNotConfiguredPageUsesTheBodyBlock(): void
    {
        $s = self::src('error/service_not_configured.html.twig');
        $this->assertStringContainsString('{% block body %}', $s);
        $this->assertStringNotContainsString('{% block content %}', $s);
    }
}
