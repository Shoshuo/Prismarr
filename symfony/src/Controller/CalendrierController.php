<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class CalendrierController extends AbstractController
{
    private const ICAL_DAYS_BEFORE = 30;
    private const ICAL_DAYS_AHEAD  = 180;


    public function __construct(
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly ConfigService $config,
        private readonly ServiceInstanceProvider $instances,
    ) {}

    /**
     * "Configured" = at least one enabled instance exists for radarr/sonarr
     * (v1.1.0 — moved from the legacy radarr_url/sonarr_url settings).
     * Used to tell "service down" (banner) apart from "service deliberately
     * not used" (silent — no banner).
     */
    private function isConfigured(string $service): bool
    {
        return match ($service) {
            'radarr' => $this->instances->hasAnyEnabled(ServiceInstance::TYPE_RADARR),
            'sonarr' => $this->instances->hasAnyEnabled(ServiceInstance::TYPE_SONARR),
            default  => false,
        };
    }

    #[Route('/calendrier', name: 'app_calendrier')]
    public function index(): Response
    {
        $radarrCal = [];
        $sonarrCal = [];
        // Track whether each fetch crashed (vs. simply returned no events) so
        // the template can show a "service unreachable" banner. Without this,
        // a Radarr outage and an empty library look identical to the user.
        $radarrFailed = false;
        $sonarrFailed = false;

        try {
            $radarrMovies = $this->radarr->getCalendar(90, 90);
            foreach ($radarrMovies as $m) {
                $base = [
                    'type'      => 'film',
                    'title'     => $m['title'] ?? '—',
                    'year'      => $m['year'] ?? null,
                    'poster'    => $m['poster'] ?? null,
                    'fanart'    => $m['fanart'] ?? null,
                    'overview'  => $m['overview'] ?? null,
                    'status'    => $m['status'] ?? null,
                    'hasFile'   => (bool) ($m['hasFile'] ?? false),
                    'monitored' => (bool) ($m['monitored'] ?? false),
                    'id'        => $m['id'] ?? null,
                    'runtime'   => $m['runtime'] ?? null,
                    'studio'    => $m['studio'] ?? null,
                    'genres'    => $m['genres'] ?? [],
                    'certification' => $m['certification'] ?? null,
                ];
                if ($m['inCinemasAt'] ?? null) {
                    $radarrCal[] = array_merge($base, ['date' => $m['inCinemasAt'], 'releaseType' => 'cinema']);
                }
                if ($m['digitalAt'] ?? null) {
                    $radarrCal[] = array_merge($base, ['date' => $m['digitalAt'], 'releaseType' => 'digital']);
                }
                if ($m['physicalAt'] ?? null) {
                    $radarrCal[] = array_merge($base, ['date' => $m['physicalAt'], 'releaseType' => 'physical']);
                }
                if (!($m['inCinemasAt'] ?? null) && !($m['digitalAt'] ?? null) && !($m['physicalAt'] ?? null)) {
                    $radarrCal[] = array_merge($base, ['date' => null, 'releaseType' => 'unknown']);
                }
            }
        } catch (\Throwable $e) {
            $radarrFailed = true;
            $this->logger->warning('Radarr calendar failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
        // Silent failure path: getCalendar() returns [] both when Radarr has
        // genuinely nothing scheduled AND when the HTTP request bailed out
        // (timeout, 401, network error). The latter sets a non-null
        // getLastError(), so we use that to tell "service down" apart from
        // "library quiet" without having to throw inside the client.
        if ($radarrCal === [] && $this->radarr->getLastError() !== null) {
            $radarrFailed = true;
        }

        try {
            $sonarrEpisodes = $this->sonarr->getCalendar(90, 90);
            foreach ($sonarrEpisodes as $e) {
                $sonarrCal[] = [
                    'type'        => 'episode',
                    'seriesTitle' => $e['seriesTitle'] ?? '—',
                    'title'       => $e['title'] ?? '—',
                    'overview'    => $e['overview'] ?? null,
                    'season'      => $e['season'] ?? 0,
                    'episode'     => $e['episode'] ?? 0,
                    'date'        => $e['airDate'] ?? null,
                    'poster'      => $e['poster'] ?? null,
                    'fanart'      => $e['fanart'] ?? null,
                    'hasFile'     => (bool) ($e['hasFile'] ?? false),
                    'monitored'   => (bool) ($e['monitored'] ?? false),
                    'seriesId'    => $e['seriesId'] ?? null,
                    'runtime'     => $e['runtime'] ?? null,
                    'network'     => $e['network'] ?? null,
                    'genres'      => $e['genres'] ?? [],
                    'releaseType' => 'episode',
                ];
            }
        } catch (\Throwable $e) {
            $sonarrFailed = true;
            $this->logger->warning('Sonarr calendar failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
        // Same silent-failure detection as Radarr above.
        if ($sonarrCal === [] && $this->sonarr->getLastError() !== null) {
            $sonarrFailed = true;
        }

        // Merge and sort by date
        $events = [];
        foreach ($radarrCal as $item) {
            $d = $item['date'];
            if ($d instanceof \DateTimeInterface) $d = $d->format('Y-m-d');
            $events[] = array_merge($item, ['sortDate' => $d ?? '9999-12-31']);
        }
        foreach ($sonarrCal as $item) {
            $d = $item['date'];
            if ($d instanceof \DateTimeInterface) $d = $d->format('Y-m-d');
            $events[] = array_merge($item, ['sortDate' => $d ?? '9999-12-31']);
        }
        usort($events, fn($a, $b) => $a['sortDate'] <=> $b['sortDate']);

        // Convert dates for JSON
        $eventsJson = array_map(function ($ev) {
            $d = $ev['date'] ?? $ev['sortDate'] ?? null;
            if ($d instanceof \DateTimeInterface) $d = $d->format('Y-m-d');
            $ev['dateStr'] = $d;
            unset($ev['date']);
            return $ev;
        }, $events);

        // Suppress the "unreachable" banner when the service simply isn't
        // configured. Otherwise users who legitimately run only Radarr (no
        // Sonarr) would get a warning every time they open the calendar.
        $radarrConfigured = $this->isConfigured('radarr');
        $sonarrConfigured = $this->isConfigured('sonarr');
        if (!$radarrConfigured) { $radarrFailed = false; }
        if (!$sonarrConfigured) { $sonarrFailed = false; }

        return $this->render('calendrier/index.html.twig', [
            'eventsJson'        => $eventsJson,
            'totalFilms'        => count($radarrCal),
            'totalEpisodes'     => count($sonarrCal),
            'radarrFailed'      => $radarrFailed,
            'sonarrFailed'      => $sonarrFailed,
            'radarrConfigured'  => $radarrConfigured,
            'sonarrConfigured'  => $sonarrConfigured,
        ]);
    }

    /**
     * iCalendar export for Apple Calendar / Google Calendar / Thunderbird
     * subscribers. Drop the URL into your calendar app as a subscribed
     * feed and Prismarr events stay in sync.
     *
     * Each Radarr release (cinema / digital / physical) and Sonarr episode
     * becomes its own VEVENT with a stable UID so calendar clients update
     * in place instead of duplicating.
     */
    #[Route('/calendrier.ics', name: 'app_calendrier_ical', methods: ['GET'])]
    public function ical(): Response
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Prismarr//Calendrier//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Prismarr',
            'X-WR-TIMEZONE:Europe/Paris',
        ];

        $now = (new \DateTimeImmutable())->format('Ymd\THis\Z');

        try {
            foreach ($this->radarr->getCalendar(self::ICAL_DAYS_AHEAD, self::ICAL_DAYS_BEFORE) as $m) {
                $slots = [
                    ['at' => $m['inCinemasAt'] ?? null, 'label' => $this->translator->trans('calendar.event.type_cinema'),   'suffix' => 'cinema'],
                    ['at' => $m['digitalAt']   ?? null, 'label' => $this->translator->trans('calendar.event.type_digital'),  'suffix' => 'digital'],
                    ['at' => $m['physicalAt']  ?? null, 'label' => $this->translator->trans('dashboard.release_badge.bluray'), 'suffix' => 'physical'],
                ];
                foreach ($slots as $s) {
                    if (!$s['at'] instanceof \DateTimeInterface) continue;
                    $lines = array_merge($lines, $this->movieEventLines($m, $s['at'], $s['label'], $s['suffix'], $now));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('iCal Radarr export failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        try {
            foreach ($this->sonarr->getCalendar(self::ICAL_DAYS_AHEAD, self::ICAL_DAYS_BEFORE) as $e) {
                if (!($e['airDate'] ?? null) instanceof \DateTimeInterface) continue;
                $lines = array_merge($lines, $this->episodeEventLines($e, $now));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('iCal Sonarr export failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF is required per RFC 5545 — plain \n breaks strict parsers.
        $body = implode("\r\n", $lines) . "\r\n";

        return new Response(
            $body,
            Response::HTTP_OK,
            [
                'Content-Type'        => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="prismarr.ics"',
            ],
        );
    }

    /**
     * @param array<string, mixed> $movie
     * @return list<string>
     */
    private function movieEventLines(array $movie, \DateTimeInterface $at, string $label, string $suffix, string $nowUtc): array
    {
        $uid      = sprintf('radarr-%d-%s@prismarr.local', $movie['id'] ?? 0, $suffix);
        $title    = sprintf('🎬 %s — %s', $movie['title'] ?? '—', $label);
        $desc     = $movie['overview'] ?? '';
        $allDay   = $at->format('Ymd');

        return [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $nowUtc,
            'DTSTART;VALUE=DATE:' . $allDay,
            'DTEND;VALUE=DATE:' . (new \DateTimeImmutable($allDay))->modify('+1 day')->format('Ymd'),
            'SUMMARY:' . $this->escapeIcal($title),
            'DESCRIPTION:' . $this->escapeIcal($desc),
            'CATEGORIES:Prismarr,' . $this->translator->trans('dashboard.type.film') . ',' . $label,
            'END:VEVENT',
        ];
    }

    /**
     * @param array<string, mixed> $episode
     * @return list<string>
     */
    private function episodeEventLines(array $episode, string $nowUtc): array
    {
        /** @var \DateTimeInterface $at */
        $at       = $episode['airDate'];
        $runtime  = (int) ($episode['runtime'] ?? 30);
        $start    = $at instanceof \DateTimeImmutable ? $at : \DateTimeImmutable::createFromInterface($at);
        $end      = $start->modify("+{$runtime} minutes");
        $uid      = sprintf(
            'sonarr-%d-s%02de%02d@prismarr.local',
            $episode['seriesId'] ?? 0,
            $episode['season']   ?? 0,
            $episode['episode']  ?? 0,
        );
        $sxe      = sprintf('S%02dE%02d', $episode['season'] ?? 0, $episode['episode'] ?? 0);
        $title    = sprintf('📺 %s · %s', $episode['seriesTitle'] ?? '—', $sxe);
        $desc     = trim(($episode['title'] ?? '') . "\n\n" . ($episode['overview'] ?? ''));

        return [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $nowUtc,
            'DTSTART:' . $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'DTEND:'   . $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escapeIcal($title),
            'DESCRIPTION:' . $this->escapeIcal($desc),
            'CATEGORIES:Prismarr,' . $this->translator->trans('dashboard.type.series') . ',' . $this->translator->trans('calendar.event.type_episode'),
            'END:VEVENT',
        ];
    }

    /**
     * RFC 5545 §3.3.11 — escape comma, semicolon, backslash, and newlines
     * inside TEXT values so calendar apps render long summaries/descriptions
     * correctly.
     */
    private function escapeIcal(string $s): string
    {
        $s = str_replace(['\\', "\r\n", "\n", "\r", ',', ';'], ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'], $s);
        return $s;
    }
}
