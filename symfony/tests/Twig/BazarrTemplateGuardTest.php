<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Structural sentinels for the Bazarr templates: regressions that live in
 * inline JS or in Twig structure, where neither lint:twig nor a functional
 * test can see them.
 */
class BazarrTemplateGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    /**
     * A real regression: two detail-modal inline scripts opened a JS block
     * comment with a slash-star but closed it with `#}`. lint:twig cannot see
     * it (a bare `#}` with no `{#` opener is literal text to Twig) and no test
     * executes the JS, so the stray `#}` swallowed the modal's populate
     * functions into the comment and shipped an empty modal shell.
     *
     * A symmetric substr_count('{#') === substr_count('#}') check is NOT
     * enough: it is fooled by a comment whose own text quotes each delimiter
     * once (the counts stay balanced while the nesting is broken) - exactly
     * how _warming.html.twig once leaked its own comment onto the page. So we
     * model Twig's real lexing instead: comments do NOT nest, so strip the
     * shortest `{# ... #}` spans (non-greedy = first opener to first closer)
     * and assert no orphan delimiter survives.
     */
    public function testTwigCommentDelimitersAreBalanced(): void
    {
        foreach ([
            'media/films.html.twig',
            'media/series.html.twig',
            'media/_subtitle_badge.html.twig',
            'bazarr/index.html.twig',
            'bazarr/_shell.html.twig',
            'bazarr/_bare.html.twig',
            'bazarr/_nav.html.twig',
            'bazarr/_grid.html.twig',
            'bazarr/_warming.html.twig',
            'bazarr/_search_modal.html.twig',
            'bazarr/history.html.twig',
            'bazarr/series_detail.html.twig',
        ] as $relPath) {
            $src = file_get_contents(self::TEMPLATE_ROOT . $relPath);
            $this->assertNotFalse($src, $relPath . ' is missing');
            $stripped = (string) preg_replace('/\{#.*?#\}/s', '', $src);
            $this->assertStringNotContainsString(
                '#}',
                $stripped,
                $relPath . ': orphan `#}` outside a Twig comment - a JS `/* ... */` closed with `#}`, '
                    . 'or a comment quoting `#}` as literal text and closing itself early.'
            );
            $this->assertStringNotContainsString(
                '{#',
                $stripped,
                $relPath . ': orphan `{#` after stripping balanced comments - an unclosed Twig comment.'
            );
        }
    }

    /**
     * The per-id Bazarr fallback is a SINGLE-ITEM affordance. Reachable from a
     * grid template it becomes one Bazarr call per card per page render. Only
     * the quick-look body, which renders exactly one badge, may use it.
     */
    public function testTheSingleItemSubtitleLookupIsUsedOnlyByTheQuickLookBody(): void
    {
        $this->assertSame(
            1,
            substr_count(
                (string) file_get_contents(self::TEMPLATE_ROOT . 'dashboard/_quicklook_body.html.twig'),
                'subtitle_status_single(',
            ),
            'the quick-look body renders exactly one badge and must use the per-id lookup',
        );

        foreach ([
            'media/films.html.twig',
            'media/series.html.twig',
            'media/_subtitle_badge.html.twig',
            'bazarr/index.html.twig',
            'bazarr/_shell.html.twig',
            'bazarr/_bare.html.twig',
            'bazarr/_grid.html.twig',
            'bazarr/history.html.twig',
            'bazarr/series_detail.html.twig',
        ] as $file) {
            $this->assertStringNotContainsString(
                'subtitle_status_single(',
                (string) file_get_contents(self::TEMPLATE_ROOT . $file),
                $file . ' renders many badges - the per-id lookup would be an N+1 against Bazarr',
            );
        }
    }

    public function testFilmsAndSeriesGridsIncludeTheSubtitleBadge(): void
    {
        foreach (['media/films.html.twig', 'media/series.html.twig'] as $file) {
            $src = (string) file_get_contents(self::TEMPLATE_ROOT . $file);
            $this->assertStringContainsString('_subtitle_badge.html.twig', $src, $file);
            // The poster-overlaid badge must carry the solid-backed
            // .poster-chip treatment so it cannot blend into a same-hue
            // poster; the flat table/list-row badge deliberately does not.
            $this->assertStringContainsString('poster-chip', $src, $file);
        }
    }

    public function testQuicklookBodyIncludesTheSubtitleBadge(): void
    {
        $body = (string) file_get_contents(self::TEMPLATE_ROOT . 'dashboard/_quicklook_body.html.twig');
        $this->assertStringContainsString('_subtitle_badge.html.twig', $body);
        $this->assertStringContainsString('ql.radarrId', $body);
        $this->assertStringContainsString('ql.sonarrId', $body);
    }

    public function testBaseMountsTheSearchModalAndRendersTheSearchResultBadge(): void
    {
        $base = (string) file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig');
        $this->assertStringContainsString('bazarr/_search_modal.html.twig', $base);
        // The dropdown renders results client-side from a JSON payload, so
        // the badge is built in JS from `item.subtitle`, not via an include.
        $this->assertStringContainsString('item.subtitle', $base);
        // The click-to-search affordance is admin-only and must read the same
        // is_granted()-derived flag, not invent a separate auth signal.
        $this->assertStringContainsString('window.PRISMARR_IS_ADMIN', $base);
        $this->assertStringContainsString("is_granted('ROLE_ADMIN') ? 'true' : 'false'", $base);
    }

    /**
     * Every link inside #bazarr-view targets the frame by default. A link to a
     * page that is NOT one of the frame's own views must escape with
     * data-turbo-frame="_top", or Turbo tries to satisfy the frame-scoped
     * fetch by finding id="bazarr-view" in that other page's response, fails,
     * and replaces the view with "Content missing".
     */
    public function testFrameScopedLinksEscapeWhereTheyMust(): void
    {
        $grid = (string) file_get_contents(self::TEMPLATE_ROOT . 'bazarr/_grid.html.twig');
        $this->assertStringContainsString(
            "el.setAttribute('data-turbo-frame', '_top')",
            $grid,
            '_grid.html.twig: the series-card link built in buildCard() must escape the frame',
        );

        $banner = (string) file_get_contents(self::TEMPLATE_ROOT . 'error/_service_banner.html.twig');
        $this->assertMatchesRegularExpression(
            '/<a href="\{\{ path\(_target_route\) \}\}" data-turbo-frame="_top"/',
            $banner,
            '_service_banner.html.twig: the CTA anchor must carry data-turbo-frame="_top" - it renders inside the Bazarr frame',
        );

        $landing = (string) file_get_contents(self::TEMPLATE_ROOT . 'bazarr/index.html.twig');
        $this->assertSame(
            2,
            substr_count($landing, 'data-turbo-frame="bazarr-view" data-turbo-action="advance"'),
            'index.html.twig: the two "all items" buttons stay inside the frame and advance history',
        );
        $this->assertStringContainsString(
            'data-bazarr-nav data-turbo-frame="_top"',
            $landing,
            'index.html.twig: the series-detail link is not one of the frame views, so it must escape',
        );
    }

    /**
     * A frame swap fires neither turbo:before-render nor turbo:render, so a
     * document-only binding leaks the observer/timer and accumulates one dead
     * listener per view switch.
     */
    public function testFrameViewsTearDownOnBothTheFrameAndTheDocumentEvent(): void
    {
        foreach (['bazarr/_grid.html.twig', 'bazarr/_warming.html.twig'] as $file) {
            $src = (string) file_get_contents(self::TEMPLATE_ROOT . $file);
            $this->assertSame(1, substr_count($src, "addEventListener('turbo:before-frame-render', teardown)"), $file);
            $this->assertSame(1, substr_count($src, "addEventListener('turbo:before-render', teardown)"), $file);
            $this->assertSame(1, substr_count($src, "removeEventListener('turbo:before-frame-render', teardown)"), $file);
            $this->assertSame(1, substr_count($src, "removeEventListener('turbo:before-render', teardown)"), $file);
        }
    }

    /**
     * The server always re-renders the warming markup with no memory of prior
     * retries (the cache is still cold, so there is nothing to read back),
     * which makes the retry marker out-of-band state keyed by path.
     * sessionStorage is the primary store because the fallback reload path is
     * a full document navigation that wipes any plain `window` property - a
     * window-only marker would forget how far the retry schedule had advanced
     * and restart it forever against a Bazarr that never comes back. And
     * frame.reload() is a no-op on a direct hit (the shell ships the frame
     * with no `src`), so the reload goes through Turbo.visit(url, {frame}),
     * which assigns `src` either way.
     *
     * The marker is a bounded-backoff attempt COUNT (`{n, t}`), not a
     * one-shot boolean: this partial only renders when Bazarr is reachable
     * (the controller pings first; a down Bazarr shows the error banner), so
     * a healthy-but-still-warming index heals across a few automatic retries,
     * then the schedule caps and the manual button appears for the
     * dead-worker case.
     */
    public function testWarmingReloadsViaTurboVisitWithABoundedBackoff(): void
    {
        $src = (string) file_get_contents(self::TEMPLATE_ROOT . 'bazarr/_warming.html.twig');

        $this->assertStringContainsString("window.Turbo.visit(path, { frame: 'bazarr-view' })", $src);
        $this->assertStringContainsString("'prismarr:bazarr-warm-attempts:' + path", $src);
        $this->assertStringContainsString('sessionStorage', $src);
        $this->assertStringContainsString('window.__bzWarmAttempts', $src);
        // The auto-retry must be a BOUNDED backoff schedule, not a single
        // retry, and must stop once the schedule length is reached (so a dead
        // worker cannot drive an unbounded poll).
        $this->assertStringContainsString('var SCHEDULE = [', $src);
        $this->assertStringContainsString('state.n < SCHEDULE.length', $src);
    }
}
