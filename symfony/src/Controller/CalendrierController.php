<?php

namespace App\Controller;

use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CalendrierController extends AbstractController
{
    public function __construct(
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/calendrier', name: 'app_calendrier')]
    public function index(): Response
    {
        $radarrCal = [];
        $sonarrCal = [];

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
            $this->logger->warning('Radarr calendar failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
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
            $this->logger->warning('Sonarr calendar failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
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

        return $this->render('calendrier/index.html.twig', [
            'eventsJson'     => $eventsJson,
            'totalFilms'     => count($radarrCal),
            'totalEpisodes'  => count($sonarrCal),
        ]);
    }
}
