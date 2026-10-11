<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * Every service card that renders a Test-connection button must have an
 * entry in the inline TEST_FIELDS map. A missing entry makes the button
 * send no override and answer "Fill in the URL and credentials first"
 * whatever the admin typed (hit by Tautulli, then by Tracearr).
 */
final class AdminSettingsTestFieldsTest extends AbstractWebTestCase
{
    public function testEveryTestButtonHasItsFieldsMapped(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame(1, preg_match('/var TEST_FIELDS = \{(.*?)\};/s', $html, $m), 'TEST_FIELDS map not found');
        preg_match_all('/^\s*([a-z]+):\s*\[/m', $m[1], $keys);
        $mapped = $keys[1];

        $buttons = $crawler->filter('.admin-settings-test[data-service]')->each(
            fn($node) => $node->attr('data-service'),
        );
        $this->assertNotEmpty($buttons);
        $this->assertContains('tracearr', $buttons);

        foreach (array_unique($buttons) as $service) {
            $this->assertContains($service, $mapped, sprintf('Test button for "%s" has no TEST_FIELDS entry', $service));
        }
    }
}
