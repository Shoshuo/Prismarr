<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * Upstream #112 — the hero greeting must follow the admin display timezone
 * (the zone the hero clock beside it uses), not PHP's process zone.
 */
final class DashboardGreetingTimezoneTest extends AbstractWebTestCase
{
    private const CANDIDATE_ZONES = [
        'Pacific/Kiritimati', 'Asia/Tokyo', 'Asia/Kolkata', 'Europe/Moscow',
        'America/Sao_Paulo', 'America/New_York', 'America/Denver', 'Pacific/Pago_Pago',
    ];

    public function testGreetingFollowsDisplayTimezoneNotProcessTimezone(): void
    {
        // Pick a zone whose time-of-day bucket differs from the process zone's
        // right now, so the old process-zone code would render the wrong word.
        $processKey = self::greetingKey(date_default_timezone_get());
        $zone = null;
        foreach (self::CANDIDATE_ZONES as $candidate) {
            if (self::greetingKey($candidate) !== $processKey) {
                $zone = $candidate;
                break;
            }
        }
        self::assertNotNull($zone, 'No candidate zone falls in a different greeting bucket.');

        $em = $this->em();
        $em->persist(new Setting('display_timezone', $zone));
        $em->flush();

        $before = self::greetingKey($zone);
        $this->client->request('GET', '/tableau-de-bord');
        $after = self::greetingKey($zone);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('#<h1 class="hero-greeting">(.*?),#s', $html, $m));

        $translator = static::getContainer()->get('translator');
        // Accept either side of an hour boundary crossed mid-request.
        self::assertContains(trim($m[1]), array_unique([
            $translator->trans($before),
            $translator->trans($after),
        ]));
    }

    private static function greetingKey(string $zone): string
    {
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('G');

        return match (true) {
            $hour < 5  => 'dashboard.greeting.evening',
            $hour < 12 => 'dashboard.greeting.morning',
            $hour < 18 => 'dashboard.greeting.afternoon',
            default    => 'dashboard.greeting.evening',
        };
    }
}
