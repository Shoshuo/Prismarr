<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * window.BASE_PATH is what every JS-built URL is prefixed with. At the root
 * it must be "" (so prefixed URLs are byte-identical to the old ones), and
 * behind a reverse proxy sending X-Forwarded-Prefix it must carry the prefix.
 */
final class BasePathRenderTest extends AbstractWebTestCase
{
    public function testBasePathIsEmptyAtTheRoot(): void
    {
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('window.BASE_PATH = "";', (string) $this->client->getResponse()->getContent());
    }

    public function testBasePathCarriesTheProxyPrefix(): void
    {
        $this->client->request('GET', '/admin/settings', server: ['HTTP_X_FORWARDED_PREFIX' => '/prismarr']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('window.BASE_PATH = "\/prismarr";', $html);
        // Server-rendered links follow the same prefix.
        self::assertStringContainsString('href="/prismarr/', $html);
    }
}
