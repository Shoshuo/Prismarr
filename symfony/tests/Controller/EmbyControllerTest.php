<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * Smoke tests for the Emby widget endpoints.
 *
 * The test env uses a fresh SQLite DB with no Emby config rows, so every test
 * runs the "unconfigured" path: the JSON endpoints fail open (200 + neutral
 * shape), the image proxy answers 404, and the dashboard fragment is empty.
 */
class EmbyControllerTest extends AbstractWebTestCase
{
    public function testActivityReturnsNeutralJsonWhenUnconfigured(): void
    {
        $this->client->request('GET', '/emby/api/activity');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertJson($content);

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true);
        self::assertFalse($data['configured']);
        self::assertFalse($data['connected']);
        self::assertSame('unconfigured', $data['error']);
        self::assertSame(0, $data['streamCount']);
        self::assertSame([], $data['sessions']);
    }

    public function testQuickLookReturnsNullShapeWhenUnconfigured(): void
    {
        $this->client->request('GET', '/emby/api/quicklook/167872');

        self::assertResponseIsSuccessful();
        self::assertSame(['type' => null, 'id' => null], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    /** Non-id keys never reach the client — 404 by route requirement. */
    public function testQuickLookRejectsNonIdKeys(): void
    {
        $this->client->request('GET', '/emby/api/quicklook/not-an-id');
        self::assertResponseStatusCodeSame(404);
    }

    public function testImageProxyIs404WhenUnconfiguredOrMalformed(): void
    {
        foreach (['/emby/api/image?item=167872', '/emby/api/image?item=../Users', '/emby/api/image'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testDashboardWidgetFragmentIsEmptyWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tableau-de-bord/widget/emby');

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }
}
