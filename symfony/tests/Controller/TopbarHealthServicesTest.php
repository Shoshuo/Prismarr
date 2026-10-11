<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * The topbar health indicator must list every optional service the
 * dashboard knows about: the API reports each one (null = not configured)
 * and the dropdown JS renders a row for it.
 */
final class TopbarHealthServicesTest extends AbstractWebTestCase
{
    private const SERVICES = ['deluge', 'bazarr', 'emby'];

    public function testApiReportsTheOptionalServices(): void
    {
        $this->client->request('GET', '/api/health/services');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        foreach (self::SERVICES as $service) {
            self::assertArrayHasKey($service, $data['services']);
            self::assertNull($data['services'][$service], $service . ' is not configured in the test kernel');
        }
    }

    public function testDropdownRendersARowForEachService(): void
    {
        $this->client->request('GET', '/admin/settings');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(1, preg_match("/\\/\\/ Remaining flat services\\.\\s*\\[([^\\]]+)\\]\\.forEach/", $html, $m));
        foreach (self::SERVICES as $service) {
            self::assertStringContainsString("'" . $service . "'", $m[1]);
            self::assertMatchesRegularExpression('/\b' . $service . ": '/", $html, 'missing SERVICE_LABELS entry for ' . $service);
        }
    }
}
