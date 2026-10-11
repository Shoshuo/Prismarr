<?php

namespace App\Tests\Controller;

use App\Service\HealthService;
use App\Tests\AbstractWebTestCase;

/**
 * Saving /admin/settings without touching anything must not disable any
 * service. An unchecked kill switch is simply not POSTed and reads as
 * "disabled", so every enable checkbox has to be in the submitted form,
 * including those of services that are not configured yet (rendered
 * hidden behind their "Add" button).
 */
final class AdminSettingsSaveKeepsServicesEnabledTest extends AbstractWebTestCase
{
    public function testUntouchedSaveDisablesNothing(): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/admin/settings"]')->form();
        $values = $form->getPhpValues();
        foreach (HealthService::TOGGLEABLE_SERVICES as $service) {
            self::assertSame('1', $values[$service . '_enabled'] ?? null, $service . '_enabled is not submitted checked');
        }

        $this->client->submit($form);
        self::assertResponseRedirects('/admin/settings');

        $this->client->request('GET', '/admin/settings');
        $html = (string) $this->client->getResponse()->getContent();
        foreach (HealthService::TOGGLEABLE_SERVICES as $service) {
            self::assertMatchesRegularExpression(
                '/name="' . $service . '_enabled" value="1"\s+checked/',
                $html,
                $service . ' was disabled by an untouched save',
            );
        }
    }
}
