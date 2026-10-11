<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * Smoke tests for the Tracearr page endpoints.
 *
 * The test env uses a fresh SQLite DB with no tracearr_url / tracearr_api_key
 * rows, so every endpoint runs the "unconfigured" path:
 *  - JSON / fragment endpoints fail open → 200 + neutral shape.
 *  - The page route is caught by ServiceRouteGuardSubscriber → redirect.
 */
class TracearrControllerTest extends AbstractWebTestCase
{
    public function testIndexRedirectsWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tracearr');
        self::assertResponseRedirects('/admin/settings');
    }

    public function testActivityEndpointReportsUnconfigured(): void
    {
        $this->client->request('GET', '/tracearr/api/activity');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('unconfigured', $data['error']);
        self::assertSame([], $data['sessions']);
    }

    public function testChartsEndpointReturnsNeutralJson(): void
    {
        foreach (['/tracearr/api/charts', '/tracearr/api/charts?period=week', '/tracearr/api/charts?period=garbage'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();

            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertIsArray($data);
            self::assertSame(['categories' => [], 'series' => []], $data['plays'], $url);
            self::assertSame(0, $data['quality']['total'], $url);
        }
    }

    public function testFragmentsRenderEmptyStates(): void
    {
        $this->client->request('GET', '/tracearr/api/now-playing');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing is playing', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/tracearr/api/stats');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/tracearr/api/users');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No user activity', (string) $this->client->getResponse()->getContent());

        foreach (['/tracearr/api/history', '/tracearr/api/history?cursor=p2', '/tracearr/api/history?cursor=a%26b%3Dc'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame('', trim((string) $this->client->getResponse()->getContent()), $url);
        }
    }

    public function testImageEndpointRejectsUnsafeInput(): void
    {
        $this->client->request('GET', '/tracearr/api/image?server=not-a-uuid&img=/library/metadata/1/thumb/2');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/tracearr/api/image?server=3f1c2b9a-6d4e-4f7a-9b8c-1a2b3c4d5e6f&img=http://169.254.169.254/');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDashboardWidgetIsEmptyWhenNoActivitySource(): void
    {
        $this->client->request('GET', '/tableau-de-bord/widget/plex');
        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    /**
     * With only Tracearr configured the dashboard widget falls back to it and
     * names Tracearr (not Tautulli) in its fail-open error state. Port 1 on
     * loopback refuses instantly, so this stays fast and offline.
     */
    public function testDashboardWidgetFallsBackToTracearr(): void
    {
        $em = $this->em();
        $em->persist(new Setting('tracearr_url', 'http://127.0.0.1:1'));
        $em->persist(new Setting('tracearr_api_key', 'trr_pub_test'));
        $em->flush();

        $this->client->request('GET', '/tableau-de-bord/widget/plex');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Unable to reach Tracearr', $html);
        self::assertStringNotContainsString('Tautulli', $html);
    }

    /**
     * The admin preference picks Tracearr even though Tautulli is configured
     * too: the widget is fed (and titled) by Tracearr.
     */
    public function testActivitySourcePreferenceSelectsTracearr(): void
    {
        $em = $this->em();
        foreach ([
            'tautulli_url'            => 'http://127.0.0.1:1',
            'tautulli_api_key'        => 'k',
            'tracearr_url'            => 'http://127.0.0.1:1',
            'tracearr_api_key'        => 'trr_pub_test',
            'display_activity_source' => 'tracearr',
        ] as $k => $v) {
            $em->persist(new Setting($k, $v));
        }
        $em->flush();

        $this->client->request('GET', '/tableau-de-bord/widget/plex');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Unable to reach Tracearr', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/tableau-de-bord');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Current media activity', $html);
        self::assertStringContainsString('/tracearr/api/activity', $html);
    }

    /** The topbar health indicator lists Tracearr: null (not configured) here. */
    public function testTopbarHealthListsTracearr(): void
    {
        $this->client->request('GET', '/api/health/services');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('tracearr', $data['services']);
        self::assertNull($data['services']['tracearr']);
    }
}
