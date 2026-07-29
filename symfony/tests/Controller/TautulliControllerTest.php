<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * Smoke tests for the Tautulli activity-page endpoints.
 *
 * The test env uses a fresh SQLite DB with no Tautulli config rows
 * (AbstractWebTestCase seeds: admin user, setup-done flag, default
 * Radarr/Sonarr instances — but NO tautulli_url / tautulli_api_key).
 *
 * As a result every test operates under the "unconfigured" path:
 *   - JSON API endpoints (apiPlays) fail open → 200 + neutral shape.
 *   - Page route (app_tautulli_index) is caught by
 *     ServiceRouteGuardSubscriber → redirect to admin_settings_index.
 */
class TautulliControllerTest extends AbstractWebTestCase
{
    /**
     * /tautulli/api/plays returns HTTP 200 with the neutral
     * {categories:[], series:[]} shape when Tautulli is not configured.
     *
     * TautulliClient::getPlaysByDate() fails open: missing config rows →
     * normalizePlaysByDate([]) → {categories:[], series:[]}.
     * The controller also wraps in a try/catch that returns the same shape.
     */
    public function testPlaysEndpointReturnsNeutralJsonWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli/api/plays?range=30');

        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertJson($content);

        /** @var array{categories: mixed, series: mixed} $data */
        $data = json_decode($content, true);
        self::assertSame([], $data['categories']);
        self::assertSame([], $data['series']);
    }

    /**
     * An out-of-range ?range= value must not cause a 500; the endpoint
     * clamps the value server-side and returns 200.
     */
    public function testRangeParamOutOfBoundsDoesNotError(): void
    {
        $this->client->request('GET', '/tautulli/api/plays?range=9999');

        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertJson($content);

        /** @var array{categories: mixed, series: mixed} $data */
        $data = json_decode($content, true);
        self::assertSame([], $data['categories']);
        self::assertSame([], $data['series']);
    }

    /**
     * The page route /tautulli is guarded by ServiceRouteGuardSubscriber.
     * With no tautulli_url / tautulli_api_key in DB the guard fires
     * before the controller and redirects to admin_settings_index.
     */
    public function testActivityPageGuardedWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli');

        self::assertResponseRedirects('/admin/settings');
    }

    /**
     * /tautulli/api/stats accepts metric= and user= params without erroring.
     * Unconfigured → empty stats → the "no data" copy renders, no 500.
     */
    public function testStatsEndpointAcceptsMetricAndUser(): void
    {
        $this->client->request('GET', '/tautulli/api/stats?range=30&metric=duration&user=99');
        self::assertResponseIsSuccessful();
        // Unconfigured -> empty stats -> the "no data" copy renders, no 500.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Exception', $html);
        // Verify the full normalizer shape was iterated: all 7 stat groups
        // empty → _stats.html.twig emits the empty-state div.
        // The tautulli.stats.empty key resolves to "No statistics for this period"
        // in the test env (translations are already registered).
        self::assertStringContainsString('No statistics for this period', $html);
    }

    /**
     * The new chart endpoints fail open to the neutral {categories:[],series:[]}
     * JSON when Tautulli is unconfigured, and the plays endpoint accepts the
     * stream-type mode without erroring.
     */
    public function testChartEndpointsReturnNeutralJsonWhenUnconfigured(): void
    {
        foreach ([
            '/tautulli/api/plays?range=30&mode=stream',
            '/tautulli/api/activity-hour?range=30',
            '/tautulli/api/activity-dow?range=30',
            '/tautulli/api/clients-stream-type?range=30',
            '/tautulli/api/plays-source-res?range=30&metric=duration',
            '/tautulli/api/plays-stream-res?range=30',
            '/tautulli/api/users-stream-type?range=30&user=99',
            '/tautulli/api/concurrent?range=30',
            // metric/user param coverage — valid and invalid values must still return 200+neutral
            '/tautulli/api/plays?range=30&mode=stream&metric=duration&user=99',
            '/tautulli/api/plays?range=30&metric=garbage&user=abc',
            '/tautulli/api/activity-hour?range=30&metric=duration&user=99',
            '/tautulli/api/activity-dow?range=30&metric=garbage&user=notdigits',
            '/tautulli/api/clients-stream-type?range=30&metric=duration&user=99',
            '/tautulli/api/users-stream-type?range=30&metric=garbage&user=abc',
            '/tautulli/api/concurrent?range=30&user=99',
            '/tautulli/api/concurrent?range=30&user=xyz',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $content = $this->client->getResponse()->getContent();
            self::assertNotFalse($content);
            self::assertJson($content);
            /** @var array{categories: mixed, series: mixed} $data */
            $data = json_decode($content, true);
            self::assertSame([], $data['categories'], $url);
            self::assertSame([], $data['series'], $url);
        }
    }

    /**
     * /tautulli/api/user-names returns HTTP 200 + an empty JSON array when
     * Tautulli is not configured. getUserNames() fails open: no config rows →
     * returns []. The controller also wraps in a try/catch → $this->json([]).
     */
    public function testUserNamesEndpointReturnsEmptyJsonWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli/api/user-names');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertJson($content);
        self::assertSame([], json_decode($content, true));
    }

    /**
     * /tautulli/api/users returns HTTP 200 and renders the users fragment
     * without errors when Tautulli is not configured. getUsersTable() fails
     * open → users:[]. The fragment renders the empty-state div, no Exception.
     */
    public function testUsersFragmentRendersWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli/api/users');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Exception', (string) $this->client->getResponse()->getContent());
    }

    /**
     * /tautulli/api/history accepts a ?user= filter without erroring.
     *
     * The unconfigured client returns [] for any call to getHistory(), so the
     * history fragment renders an empty state — no 500, no Exception output.
     * This guards against regressions where wiring the user param causes a
     * server error instead of failing open.
     */
    public function testHistoryEndpointAcceptsUserFilter(): void
    {
        $this->client->request('GET', '/tautulli/api/history?length=25&start=0&user=99');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Exception', (string) $this->client->getResponse()->getContent());
    }

    /**
     * /tautulli/api/quicklook/{ratingKey} fails open to {type:null, id:null}
     * when Tautulli is not configured — the frontend then falls back to the
     * legacy Plex metadata modal instead of a broken quick-look.
     */
    public function testQuickLookResolveReturnsNullShapeWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli/api/quicklook/12345');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertJson($content);
        self::assertSame(['type' => null, 'id' => null], json_decode($content, true));
    }

    /** Non-numeric rating keys never reach the client — 404 by route requirement. */
    public function testQuickLookResolveRejectsNonNumericKeys(): void
    {
        $this->client->request('GET', '/tautulli/api/quicklook/abc');
        self::assertResponseStatusCodeSame(404);
    }
}
