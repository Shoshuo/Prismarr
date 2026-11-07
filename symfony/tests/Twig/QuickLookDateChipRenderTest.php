<?php

namespace App\Tests\Twig;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;
use Twig\Environment;

/**
 * Live check on :beta (2026-11-07): Treasure Island's next episode airs at
 * 03:00 UTC on Nov 9 — the evening of Nov 8 in the America/Chicago display
 * zone, and the dashboard calendar lists it under Sunday the 8th — yet the
 * quick-look chip read "Nov 9". The final-review fix #2 set `floating: false`
 * on the chip and the template read it through `|default(true)`, but Twig's
 * `default` filter treats `false` as empty and hands back the default, so
 * every chip floated. The controller test checked the flag, the guard test
 * checked the expression; nothing rendered the day. This does.
 */
final class QuickLookDateChipRenderTest extends AbstractWebTestCase
{
    /**
     * @param list<array<string, mixed>> $chips
     */
    private function renderChips(array $chips): array
    {
        $em = $this->em();
        $em->persist(new Setting('display_timezone', 'America/Chicago'));
        $em->persist(new Setting('display_date_format', 'iso'));
        $em->flush();

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $html = $twig->render('dashboard/_quicklook_body.html.twig', ['ql' => [
            'title'        => 'Treasure Island',
            'year'         => 2026,
            'poster'       => null,
            'backdrop'     => null,
            'overview'     => 'Young Jim Hawkins.',
            'genres'       => ['Adventure'],
            'rating'       => 7.1,
            'metaLine'     => 'MGM+',
            'statusBadge'  => null,
            'actionUrl'    => '/medias/sonarr-1/series?open=370',
            'actionLabel'  => 'Manage',
            'inLibrary'    => true,
            'radarrId'     => null,
            'sonarrId'     => 370,
            'airStatus'    => 'continuing',
            'releaseDates' => $chips,
        ]]);
        preg_match_all('#<span class="ql-date-val">([^<]+)</span>#', $html, $m);

        return array_map('trim', $m[1]);
    }

    public function testAnInstantChipShowsTheDisplayZoneDayAndACalendarDayFloats(): void
    {
        $days = $this->renderChips([
            // Sonarr airDateUtc: an evening broadcast west of UTC is "tomorrow" in UTC
            ['kind' => 'next_episode', 'label' => 'Next episode', 'date' => new \DateTimeImmutable('2026-11-09T03:00:00Z'), 'upcoming' => true, 'floating' => false],
            // Sonarr firstAired is a calendar day anchored at UTC midnight: it must NOT move back a day
            ['kind' => 'first_aired', 'label' => 'First aired', 'date' => new \DateTimeImmutable('2026-10-11T00:00:00Z'), 'upcoming' => false, 'floating' => true],
            // a chip without the key (older producers) keeps floating
            ['kind' => 'digital', 'label' => 'Digital', 'date' => new \DateTimeImmutable('2026-11-10T00:00:00Z'), 'upcoming' => true],
        ]);

        self::assertSame(['2026-11-08', '2026-10-11', '2026-11-10'], $days);
    }
}
