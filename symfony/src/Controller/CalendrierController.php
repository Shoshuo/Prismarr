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
        // Phase D — fan out across every enabled Radarr/Sonarr instance and
        // dedupe identical entries (same movie tracked on 1080p + 4K would
        // otherwise show twice on the same date). The first instance to
        // surface an event wins and its slug is stamped onto the row so the
        // click handler can navigate to the right /medias/<slug>/films.
        // Failure tracking is per-type (any instance failing flips the
        // banner) so a Radarr 4K outage doesn't silently hide events.
        $radarrFailed = false;
        $sonarrFailed = false;
        $radarrSeen   = [];
        $sonarrSeen   = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
            $client = $this->radarr->withInstance($inst);
            try {
                $radarrMovies = $client->getCalendar(90, 90);
            } catch (\Throwable $e) {
                $radarrFailed = true;
                $this->logger->warning('Radarr calendar failed', [
                    'instance'  => $inst->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            // Same silent-failure detection as the legacy single-instance code.
            // getCalendar() returns [] both when Radarr has nothing scheduled
            // AND when the HTTP call bailed (timeout/401). getLastError() lets
            // us tell those apart without throwing inside the client.
            if ($radarrMovies === [] && $client->getLastError() !== null) {
                $radarrFailed = true;
            }
            foreach ($radarrMovies as $m) {
                // Dedup key: tmdbId (stable cross-instance) with title+year as
                // fallback. The Radarr internal id differs per instance so it
                // can't be the dedup key.
                $dedupBase = 'r:' . ($m['tmdbId'] ?? (($m['title'] ?? '?') . '|' . ($m['year'] ?? '?')));
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
                    '_instanceSlug' => $inst->getSlug(),
                    '_instanceName' => $inst->getName(),
                ];
                $candidates = [];
                if ($m['inCinemasAt'] ?? null) $candidates[] = ['date' => $m['inCinemasAt'], 'releaseType' => 'cinema'];
                if ($m['digitalAt']   ?? null) $candidates[] = ['date' => $m['digitalAt'],   'releaseType' => 'digital'];
                if ($m['physicalAt']  ?? null) $candidates[] = ['date' => $m['physicalAt'],  'releaseType' => 'physical'];
                if ($candidates === []) $candidates[] = ['date' => null, 'releaseType' => 'unknown'];
                foreach ($candidates as $c) {
                    $key = $dedupBase . '|' . $c['releaseType'] . '|' . ($c['date'] instanceof \DateTimeInterface ? $c['date']->format('Y-m-d') : 'none');
                    if (isset($radarrSeen[$key])) continue;
                    $radarrSeen[$key] = true;
                    $radarrCal[] = array_merge($base, $c);
                }
            }
        }

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
            $client = $this->sonarr->withInstance($inst);
            try {
                $sonarrEpisodes = $client->getCalendar(90, 90);
            } catch (\Throwable $e) {
                $sonarrFailed = true;
                $this->logger->warning('Sonarr calendar failed', [
                    'instance'  => $inst->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            if ($sonarrEpisodes === [] && $client->getLastError() !== null) {
                $sonarrFailed = true;
            }
            foreach ($sonarrEpisodes as $e) {
                // Dedup key for episodes: tvdbId+season+episode (stable across
                // instances). Series internal id differs per Sonarr so it
                // can't be the key. Fallback on seriesTitle+S/E.
                $dedupBase = 's:' . ($e['tvdbId'] ?? ($e['seriesTitle'] ?? '?'));
                $key = $dedupBase . '|S' . ($e['season'] ?? 0) . 'E' . ($e['episode'] ?? 0);
                if (isset($sonarrSeen[$key])) continue;
                $sonarrSeen[$key] = true;
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
                    '_instanceSlug' => $inst->getSlug(),
                    '_instanceName' => $inst->getName(),
                ];
            }
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

        // Phase D — fan out and dedupe so the subscribed iCal feed isn't
        // duplicated for users running the same library on multiple Radarr
        // instances (1080p + 4K). UID is rooted on tmdbId / tvdbId now so
        // it stays stable across instances; the previous radarr-{id} form
        // would have shifted across instances and produced ghost events.
        // Caveat: existing subscribers will see calendar apps re-create the
        // events under new UIDs once. Documented in CHANGELOG.
        $movieSeen   = [];
        $episodeSeen = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
            try {
                $movies = $this->radarr->withInstance($inst)->getCalendar(self::ICAL_DAYS_AHEAD, self::ICAL_DAYS_BEFORE);
            } catch (\Throwable $e) {
                $this->logger->warning('iCal Radarr export failed', [
                    'instance'  => $inst->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($movies as $m) {
                $slots = [
                    ['at' => $m['inCinemasAt'] ?? null, 'label' => $this->translator->trans('calendar.event.type_cinema'),   'suffix' => 'cinema'],
                    ['at' => $m['digitalAt']   ?? null, 'label' => $this->translator->trans('calendar.event.type_digital'),  'suffix' => 'digital'],
                    ['at' => $m['physicalAt']  ?? null, 'label' => $this->translator->trans('dashboard.release_badge.bluray'), 'suffix' => 'physical'],
                ];
                foreach ($slots as $s) {
                    if (!$s['at'] instanceof \DateTimeInterface) continue;
                    // Parenthesise the tmdbId fallback explicitly — `??` has
                    // lower precedence than `.`, so `$a ?? $b . $c` reads as
                    // `$a ?? ($b . $c)` and was technically correct, but the
                    // visual ambiguity has bitten reviewers more than once.
                    $stableId = $m['tmdbId'] ?? (($m['title'] ?? '?') . '|' . ($m['year'] ?? '?'));
                    $key      = $stableId . '|' . $s['suffix'] . '|' . $s['at']->format('Y-m-d');
                    if (isset($movieSeen[$key])) continue;
                    $movieSeen[$key] = true;
                    $lines = array_merge($lines, $this->movieEventLines($m, $s['at'], $s['label'], $s['suffix'], $now));
                }
            }
        }

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
            try {
                $episodes = $this->sonarr->withInstance($inst)->getCalendar(self::ICAL_DAYS_AHEAD, self::ICAL_DAYS_BEFORE);
            } catch (\Throwable $e) {
                $this->logger->warning('iCal Sonarr export failed', [
                    'instance'  => $inst->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($episodes as $e) {
                if (!($e['airDate'] ?? null) instanceof \DateTimeInterface) continue;
                $key = ($e['tvdbId'] ?? ($e['seriesTitle'] ?? '?')) . '|S' . ($e['season'] ?? 0) . 'E' . ($e['episode'] ?? 0);
                if (isset($episodeSeen[$key])) continue;
                $episodeSeen[$key] = true;
                $lines = array_merge($lines, $this->episodeEventLines($e, $now));
            }
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
        // UID rooted on tmdbId so the same release stays stable across
        // Radarr instances (1080p / 4K) and across reinstalls. Falls back
        // on the per-instance Radarr id when tmdbId is missing — pre-Phase-D
        // exports that referenced 'radarr-{id}' will be re-created under
        // 'radarr-tmdb-{tmdbId}' on the next sync (calendar apps drop the
        // old ones automatically).
        $uid      = $movie['tmdbId']
            ? sprintf('radarr-tmdb-%d-%s@prismarr.local', $movie['tmdbId'], $suffix)
            : sprintf('radarr-%d-%s@prismarr.local', $movie['id'] ?? 0, $suffix);
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
        // Same stable-UID pattern as Radarr — tvdbId is shared across Sonarr
        // instances, so subscribed apps don't see ghost duplicates when the
        // user switches the series between Sonarr default / Anime / etc.
        $uid      = isset($episode['tvdbId']) && $episode['tvdbId']
            ? sprintf(
                'sonarr-tvdb-%d-s%02de%02d@prismarr.local',
                $episode['tvdbId'],
                $episode['season']  ?? 0,
                $episode['episode'] ?? 0,
            )
            : sprintf(
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
