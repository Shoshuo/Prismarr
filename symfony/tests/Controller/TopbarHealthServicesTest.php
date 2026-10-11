<?php

namespace App\Tests\Controller;

use App\Service\HealthCatalog;
use App\Tests\AbstractWebTestCase;

/**
 * The topbar health indicator and its API must cover every service of
 * HealthCatalog, so the topbar, the API and the dashboard widget never drift.
 */
final class TopbarHealthServicesTest extends AbstractWebTestCase
{
    public function testApiReportsEveryCatalogService(): void
    {
        $this->client->request('GET', '/api/health/services');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        foreach (array_keys(HealthCatalog::FLAT_SERVICES) as $service) {
            self::assertArrayHasKey($service, $data['services']);
            self::assertNull($data['services'][$service], $service . ' is not configured in the test kernel');
        }
    }

    public function testDropdownLabelsComeFromTheCatalog(): void
    {
        $this->client->request('GET', '/admin/settings');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(1, preg_match('/var FLAT_LABELS = (\{[^;]*\});/', $html, $m));
        self::assertSame(HealthCatalog::FLAT_SERVICES, json_decode($m[1], true));
    }
}
