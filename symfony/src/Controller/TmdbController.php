<?php

namespace App\Controller;

use App\Entity\Media\WatchlistItem;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\ConfigService;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class TmdbController extends AbstractController
{
    public function __construct(
        private readonly TmdbClient                $tmdb,
        private readonly RadarrClient              $radarr,
        private readonly SonarrClient              $sonarr,
        private readonly WatchlistItemRepository   $watchlistRepo,
        private readonly EntityManagerInterface     $em,
        private readonly ConfigService             $config,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/decouverte', name: 'tmdb_index')]
    public function index(): Response
    {
        $error = false;
        $trending = $trendingMovies = $trendingTv = [];
        $popMovies = $popTv = $upcoming = $onAir = $topMovies = $topTv = [];
        $library = [];

        try {
            $library = $this->buildLibraryIndex();

            $trendingRaw = $this->tmdb->getTrendingAll('week');
            if ($trendingRaw === null || ($trendingRaw['results'] ?? null) === null) {
                $error = true;
            } else {
                $trending       = $this->enrich($trendingRaw['results'] ?? [], $library);
                $trendingMovies = $this->enrich($this->tmdb->getTrendingMovies('week')['results'] ?? [], $library, 'movie');
                $trendingTv     = $this->enrich($this->tmdb->getTrendingTv('week')['results'] ?? [],     $library, 'tv');
                $popMovies      = $this->enrich($this->tmdb->getPopularMovies()['results'] ?? [],        $library, 'movie');
                $popTv          = $this->enrich($this->tmdb->getPopularTv()['results'] ?? [],            $library, 'tv');
                $upcoming       = $this->enrich($this->tmdb->getUpcomingMovies()['results'] ?? [],       $library, 'movie');
                $onAir          = $this->enrich($this->tmdb->getOnTheAirTv()['results'] ?? [],           $library, 'tv');
                $topMovies      = $this->enrich($this->tmdb->getTopRatedMovies()['results'] ?? [],       $library, 'movie');
                $topTv          = $this->enrich($this->tmdb->getTopRatedTv()['results'] ?? [],           $library, 'tv');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TMDb index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('decouverte/index.html.twig', [
            'heroMovie' => $trendingMovies[0] ?? null,
            'heroTv'    => $trendingTv[0] ?? null,
            'trending'  => $trending,
            'popMovies' => $popMovies,
            'popTv'     => $popTv,
            'upcoming'  => $upcoming,
            'onAir'     => $onAir,
            'topMovies' => $topMovies,
            'topTv'     => $topTv,
            'error'     => $error,
            'service_url' => $this->config->get('tmdb_api_key') !== null ? 'api.themoviedb.org' : null,
        ]);
    }

    #[Route('/decouverte/section/{type}', name: 'tmdb_section', requirements: ['type' => 'trending|trending-movies|trending-tv|popular-movies|popular-tv|upcoming|on-air|top-movies|top-tv|now-playing|airing-today'])]
    public function section(string $type, Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $library = $this->buildLibraryIndex();

        [$data, $mediaType] = match ($type) {
            'trending'        => [$this->tmdb->getTrendingAll('week'),         null],
            'trending-movies' => [$this->tmdb->getTrendingMovies('week'),      'movie'],
            'trending-tv'     => [$this->tmdb->getTrendingTv('week'),          'tv'],
            'popular-movies'  => [$this->tmdb->getPopularMovies($page),        'movie'],
            'popular-tv'      => [$this->tmdb->getPopularTv($page),            'tv'],
            'upcoming'        => [$this->tmdb->getUpcomingMovies($page),       'movie'],
            'on-air'          => [$this->tmdb->getOnTheAirTv($page),           'tv'],
            'top-movies'      => [$this->tmdb->getTopRatedMovies($page),       'movie'],
            'top-tv'          => [$this->tmdb->getTopRatedTv($page),           'tv'],
            'now-playing'     => [$this->tmdb->getNowPlayingMovies($page),     'movie'],
            'airing-today'    => [$this->tmdb->getAiringTodayTv($page),        'tv'],
        };

        return $this->json([
            'results'       => $this->enrich($data['results'] ?? [], $library, $mediaType),
            'page'          => $data['page']          ?? $page,
            'total_pages'   => $data['total_pages']   ?? 1,
            'total_results' => $data['total_results'] ?? 0,
        ]);
    }

    #[Route('/decouverte/mes-recommandations', name: 'tmdb_my_recs')]
    public function myRecommendations(): JsonResponse
    {
        $library = $this->buildLibraryIndex();
        $seeds   = [];

        // Movie seeds: the 8 most recently added movies (no monitored filter, this is for recommendations)
        try {
            $movies = $this->radarr->getMovies();
            // Sort by added desc
            usort($movies, fn($a, $b) => strcmp($b['added'] ?? '', $a['added'] ?? ''));
            foreach (array_slice($movies, 0, 8) as $m) {
                if (!empty($m['tmdbId'])) $seeds[] = ['type' => 'movie', 'id' => (int) $m['tmdbId']];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TMDb myRecommendations failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        // Series seeds: the 8 most recently added
        try {
            $series = $this->sonarr->getRawAllSeries();
            usort($series, fn($a, $b) => strcmp($b['added'] ?? '', $a['added'] ?? ''));
            foreach (array_slice($series, 0, 8) as $s) {
                if (!empty($s['tmdbId'])) $seeds[] = ['type' => 'tv', 'id' => (int) $s['tmdbId']];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TMDb myRecommendations failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        if (empty($seeds)) {
            return $this->json(['results' => [], 'seeds' => 0]);
        }

        // Aggregation: fetch /recommendations for each seed, count occurrences weighted by rating
        $aggregated = [];
        foreach ($seeds as $seed) {
            $recs = $seed['type'] === 'movie'
                ? ($this->tmdb->getMovieRecommendations($seed['id'])['results'] ?? [])
                : ($this->tmdb->getTvRecommendations($seed['id'])['results'] ?? []);

            foreach (array_slice($recs, 0, 15) as $r) {
                $tmdbId = (int) ($r['id'] ?? 0);
                if (!$tmdbId) continue;
                $key = $seed['type'] . '_' . $tmdbId;

                // Skip if already in library
                if ($seed['type'] === 'movie' && isset($library['movie'][$tmdbId])) continue;
                if ($seed['type'] === 'tv' && isset($library['tv']['tmdb_' . $tmdbId])) continue;

                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = ['item' => $r, 'type' => $seed['type'], 'score' => 0, 'hits' => 0];
                }
                $aggregated[$key]['score'] += (float) ($r['vote_average'] ?? 0) + (float) ($r['popularity'] ?? 0) / 50;
                $aggregated[$key]['hits']  += 1;
            }
        }

        // Sort by (hits DESC, score DESC)
        $sortFn = function ($a, $b) {
            if ($a['hits'] !== $b['hits']) return $b['hits'] <=> $a['hits'];
            return $b['score'] <=> $a['score'];
        };
        usort($aggregated, $sortFn);

        // Split movies / series
        $moviesAgg = array_filter($aggregated, fn($x) => $x['type'] === 'movie');
        $tvAgg     = array_filter($aggregated, fn($x) => $x['type'] === 'tv');

        // Top 40 per category + top 40 mixed
        $mapItem = function (array $x) use ($library): array {
            $it = $x['item'];
            $type = $x['type'];
            $title = $type === 'movie' ? ($it['title'] ?? '') : ($it['name'] ?? '');
            $date  = $type === 'movie' ? ($it['release_date'] ?? null) : ($it['first_air_date'] ?? null);
            $year  = $date ? (int) substr($date, 0, 4) : null;
            $inLib = $type === 'movie'
                ? isset($library['movie'][(int) ($it['id'] ?? 0)])
                : isset($library['tv']['tmdb_' . (int) ($it['id'] ?? 0)]);
            return [
                'id'         => (int) ($it['id'] ?? 0),
                'type'       => $type,
                'title'      => $title,
                'year'       => $year,
                'overview'   => $it['overview'] ?? null,
                'poster'     => TmdbClient::posterUrl($it['poster_path'] ?? null, 'w342'),
                'backdrop'   => TmdbClient::backdropUrl($it['backdrop_path'] ?? null, 'w780'),
                'vote'       => isset($it['vote_average']) ? round((float) $it['vote_average'], 1) : null,
                'vote_count' => (int) ($it['vote_count'] ?? 0),
                'in_library' => $inLib,
            ];
        };

        $moviesList = array_map($mapItem, array_slice(array_values($moviesAgg), 0, 40));
        $tvList     = array_map($mapItem, array_slice(array_values($tvAgg),     0, 40));

        // If a category has < 20 items, top up with filtered /popular (not in library, not already included)
        if (count($moviesList) < 20) {
            $existingIds = array_flip(array_column($moviesList, 'id'));
            $popular     = $this->tmdb->getPopularMovies()['results'] ?? [];
            foreach ($popular as $p) {
                if (count($moviesList) >= 40) break;
                $pid = (int) ($p['id'] ?? 0);
                if (!$pid || isset($existingIds[$pid])) continue;
                if (isset($library['movie'][$pid])) continue;
                $moviesList[] = $mapItem(['item' => $p, 'type' => 'movie', 'score' => 0, 'hits' => 0]);
                $existingIds[$pid] = true;
            }
        }

        if (count($tvList) < 20) {
            $existingIds = array_flip(array_column($tvList, 'id'));
            $popular     = $this->tmdb->getPopularTv()['results'] ?? [];
            foreach ($popular as $p) {
                if (count($tvList) >= 40) break;
                $pid = (int) ($p['id'] ?? 0);
                if (!$pid || isset($existingIds[$pid])) continue;
                if (isset($library['tv']['tmdb_' . $pid])) continue;
                $tvList[] = $mapItem(['item' => $p, 'type' => 'tv', 'score' => 0, 'hits' => 0]);
                $existingIds[$pid] = true;
            }
        }

        return $this->json([
            'results' => array_map($mapItem, array_slice($aggregated, 0, 40)),
            'movies'  => $moviesList,
            'tv'      => $tvList,
            'seeds'   => count($seeds),
        ]);
    }

    #[Route('/decouverte/filter', name: 'tmdb_filter')]
    public function filter(Request $request): JsonResponse
    {
        $type  = $request->query->get('type', 'movie');
        if (!in_array($type, ['movie', 'tv'], true)) $type = 'movie';

        $params = [];
        if ($g = $request->query->get('genres')) $params['with_genres'] = $g;
        if ($y = $request->query->get('year_min')) {
            $params[$type === 'movie' ? 'primary_release_date.gte' : 'first_air_date.gte'] = $y . '-01-01';
        }
        if ($y = $request->query->get('year_max')) {
            $params[$type === 'movie' ? 'primary_release_date.lte' : 'first_air_date.lte'] = $y . '-12-31';
        }
        if ($v = $request->query->get('vote_min')) $params['vote_average.gte'] = (float) $v;
        if ($v = $request->query->get('vote_count_min')) $params['vote_count.gte'] = (int) $v;
        if ($s = $request->query->get('sort')) $params['sort_by'] = $s;
        $params['page'] = max(1, (int) $request->query->get('page', 1));

        $data = $type === 'movie'
            ? $this->tmdb->discoverMovies($params)
            : $this->tmdb->discoverTv($params);

        $library = $this->buildLibraryIndex();
        return $this->json([
            'results'       => $this->enrich($data['results'] ?? [], $library, $type),
            'page'          => $data['page']          ?? 1,
            'total_pages'   => $data['total_pages']   ?? 1,
            'total_results' => $data['total_results'] ?? 0,
        ]);
    }

    #[Route('/decouverte/genres/{type}', name: 'tmdb_genres', requirements: ['type' => 'movie|tv'])]
    public function genres(string $type): JsonResponse
    {
        $data = $type === 'movie' ? $this->tmdb->getGenresMovies() : $this->tmdb->getGenresTv();
        return $this->json(['genres' => $data['genres'] ?? []]);
    }

    #[Route('/decouverte/resolve/{type}/{id}', name: 'tmdb_resolve', requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function resolve(string $type, int $id): JsonResponse
    {
        $detail = $type === 'movie' ? $this->tmdb->getMovie($id) : $this->tmdb->getTv($id);
        if (!$detail || empty($detail['id'])) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.not_found_tmdb')], 404);
        }

        $library = $this->buildLibraryIndex();

        if ($type === 'movie') {
            $year   = !empty($detail['release_date']) ? (int) substr($detail['release_date'], 0, 4) : 0;
            $inLib  = isset($library['movie'][(int) $detail['id']]);
            return $this->json([
                'type'       => 'film',
                'id'         => (int) $detail['id'],
                'tmdbId'     => (int) $detail['id'],
                'title'      => $detail['title'] ?? '',
                'year'       => $year,
                'poster'     => TmdbClient::posterUrl($detail['poster_path'] ?? null, 'w342'),
                'inLibrary'  => $inLib,
            ]);
        }

        // TV — we need a tvdbId for Sonarr
        $tvdbId = $detail['external_ids']['tvdb_id'] ?? null;
        $year   = !empty($detail['first_air_date']) ? (int) substr($detail['first_air_date'], 0, 4) : 0;
        $inLib  = isset($library['tv']['tmdb_' . (int) $detail['id']])
               || ($tvdbId && isset($library['tv']['tvdb_' . (int) $tvdbId]));

        if (!$tvdbId) {
            return $this->json([
                'error' => $this->translator->trans('decouverte.error.not_found_thetvdb'),
            ], 422);
        }

        // Anime detection (TMDb genre 16 = Animation + Japanese origin)
        $genres   = array_column($detail['genres'] ?? [], 'id');
        $origin   = $detail['origin_country'] ?? [];
        $isAnime  = in_array(16, $genres, true) && in_array('JP', $origin, true);

        return $this->json([
            'type'       => 'serie',
            'id'         => (int) $tvdbId,
            'tvdbId'     => (int) $tvdbId,
            'tmdbId'     => (int) $detail['id'],
            'title'      => $detail['name'] ?? '',
            'year'       => $year,
            'poster'     => TmdbClient::posterUrl($detail['poster_path'] ?? null, 'w342'),
            'inLibrary'  => $inLib,
            'seriesType' => $isAnime ? 'anime' : 'standard',
        ]);
    }

    #[Route('/decouverte/detail/{type}/{id}', name: 'tmdb_detail', requirements: ['type' => 'movie|tv', 'id' => '\d+'])]
    public function detail(string $type, int $id): JsonResponse
    {
        $d = $type === 'movie' ? $this->tmdb->getMovie($id) : $this->tmdb->getTv($id);
        if (!$d || empty($d['id'])) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.not_found')], 404);
        }

        $library  = $this->buildLibraryIndex();
        $isMovie  = $type === 'movie';
        $title    = $isMovie ? ($d['title'] ?? '') : ($d['name'] ?? '');
        $date     = $isMovie ? ($d['release_date'] ?? null) : ($d['first_air_date'] ?? null);
        $year     = $date ? (int) substr($date, 0, 4) : null;
        $genres   = array_column($d['genres'] ?? [], 'name');
        $runtime  = $isMovie ? ($d['runtime'] ?? null) : ($d['episode_run_time'][0] ?? null);

        $libInfo = null;
        if ($isMovie) {
            $libInfo = $library['movie'][(int) $d['id']] ?? null;
        } else {
            $libInfo = $library['tv']['tmdb_' . (int) $d['id']] ?? null;
            if (!$libInfo && !empty($d['external_ids']['tvdb_id'])) {
                $libInfo = $library['tv']['tvdb_' . (int) $d['external_ids']['tvdb_id']] ?? null;
            }
        }
        $inLibrary = $libInfo !== null;

        // Cast top 8
        $cast = [];
        foreach (array_slice($d['credits']['cast'] ?? [], 0, 8) as $c) {
            $cast[] = [
                'name'      => $c['name'] ?? '',
                'character' => $c['character'] ?? '',
                'profile'   => TmdbClient::posterUrl($c['profile_path'] ?? null, 'w185'),
            ];
        }

        // Crew: director, creator (TV), key writers
        $crew = $this->pickCrew($d, $isMovie);

        // Trailer: priority Official EN > EN > Official FR > FR > Official any > any
        $trailer = $this->pickTrailer($d['videos']['results'] ?? []);
        // Additional trailers gallery (max 4)
        $gallery = $this->pickVideoGallery($d['videos']['results'] ?? [], $trailer['key'] ?? null);

        $similar = $isMovie
            ? ($this->tmdb->getMovieSimilar($id)['results'] ?? [])
            : ($this->tmdb->getTvSimilar($id)['results'] ?? []);
        $similar = $this->enrich(array_slice($similar, 0, 16), $library, $isMovie ? 'movie' : 'tv');

        // Watch providers (JustWatch via TMDb) — prefer FR, then US
        $providers = $this->pickProviders($d['watch/providers']['results'] ?? []);

        // Production companies + origin countries + languages
        $companies = [];
        foreach (array_slice($d['production_companies'] ?? [], 0, 6) as $c) {
            $companies[] = [
                'name' => $c['name'] ?? '',
                'logo' => !empty($c['logo_path']) ? TmdbClient::posterUrl($c['logo_path'], 'w154') : null,
            ];
        }
        $countries = array_column($d['production_countries'] ?? [], 'name');
        $spokenLanguages = array_column($d['spoken_languages'] ?? [], 'english_name');

        // Keywords / tags
        $keywords = [];
        $kwSource = $isMovie ? ($d['keywords']['keywords'] ?? []) : ($d['keywords']['results'] ?? []);
        foreach (array_slice($kwSource, 0, 10) as $k) {
            if (!empty($k['name'])) $keywords[] = $k['name'];
        }

        // Certification / rating per country (FR priority)
        $certification = $isMovie
            ? $this->pickMovieCertification($d['release_dates']['results'] ?? [])
            : $this->pickTvCertification($d['content_ratings']['results'] ?? []);

        // Movie-specific fields
        $budget  = $isMovie ? ($d['budget'] ?? 0)  : null;
        $revenue = $isMovie ? ($d['revenue'] ?? 0) : null;
        $collection = null;
        if ($isMovie && !empty($d['belongs_to_collection'])) {
            $col = $d['belongs_to_collection'];
            $collection = [
                'id'       => (int) ($col['id'] ?? 0),
                'name'     => $col['name'] ?? '',
                'poster'   => TmdbClient::posterUrl($col['poster_path'] ?? null, 'w342'),
                'backdrop' => TmdbClient::backdropUrl($col['backdrop_path'] ?? null, 'w780'),
            ];
        }

        // Series-specific fields
        $creators        = $isMovie ? [] : array_column($d['created_by'] ?? [], 'name');
        $nextEpisode     = !$isMovie && !empty($d['next_episode_to_air']) ? [
            'season'   => (int) ($d['next_episode_to_air']['season_number'] ?? 0),
            'episode'  => (int) ($d['next_episode_to_air']['episode_number'] ?? 0),
            'name'     => $d['next_episode_to_air']['name'] ?? '',
            'air_date' => $d['next_episode_to_air']['air_date'] ?? null,
        ] : null;
        $lastEpisode     = !$isMovie && !empty($d['last_episode_to_air']) ? [
            'season'   => (int) ($d['last_episode_to_air']['season_number'] ?? 0),
            'episode'  => (int) ($d['last_episode_to_air']['episode_number'] ?? 0),
            'name'     => $d['last_episode_to_air']['name'] ?? '',
            'air_date' => $d['last_episode_to_air']['air_date'] ?? null,
        ] : null;
        $networks        = $isMovie ? [] : array_map(fn($n) => [
            'name' => $n['name'] ?? '',
            'logo' => !empty($n['logo_path']) ? TmdbClient::posterUrl($n['logo_path'], 'w154') : null,
        ], $d['networks'] ?? []);

        // Seasons (series) — season 0 (specials) not featured on top but kept at the bottom
        $seasonsList = [];
        if (!$isMovie) {
            foreach ($d['seasons'] ?? [] as $s) {
                $seasonsList[] = [
                    'number'        => (int) ($s['season_number'] ?? 0),
                    'name'          => $s['name'] ?? '',
                    'episode_count' => (int) ($s['episode_count'] ?? 0),
                    'air_date'      => $s['air_date'] ?? null,
                    'overview'      => $s['overview'] ?? null,
                    'poster'        => TmdbClient::posterUrl($s['poster_path'] ?? null, 'w185'),
                    'vote'          => isset($s['vote_average']) ? round((float) $s['vote_average'], 1) : null,
                ];
            }
        }

        // Alternative titles (FR + EN priority)
        $altTitles = [];
        $altSource = $isMovie ? ($d['alternative_titles']['titles'] ?? []) : ($d['alternative_titles']['results'] ?? []);
        foreach ($altSource as $at) {
            $cc = $at['iso_3166_1'] ?? '';
            if (!in_array($cc, ['FR', 'US', 'GB', 'CA', 'BE'], true)) continue;
            $altTitles[] = ['country' => $cc, 'title' => $at['title'] ?? ''];
            if (count($altTitles) >= 6) break;
        }

        // Reviews (top 3, extrait court)
        $reviews = [];
        foreach (array_slice($d['reviews']['results'] ?? [], 0, 3) as $r) {
            $reviews[] = [
                'author'  => $r['author'] ?? 'Anonyme',
                'rating'  => $r['author_details']['rating'] ?? null,
                'content' => $r['content'] ?? '',
                'date'    => $r['created_at'] ?? null,
                'url'     => $r['url'] ?? null,
            ];
        }

        // Image gallery (TMDb backdrops, up to 8)
        $backdrops = [];
        foreach (array_slice($d['images']['backdrops'] ?? [], 0, 8) as $img) {
            $backdrops[] = [
                'thumb' => TmdbClient::backdropUrl($img['file_path'] ?? null, 'w300'),
                'full'  => TmdbClient::backdropUrl($img['file_path'] ?? null, 'w1280'),
            ];
        }

        return $this->json([
            'id'              => (int) $d['id'],
            'type'            => $type,
            'title'           => $title,
            'original'        => $isMovie ? ($d['original_title'] ?? '') : ($d['original_name'] ?? ''),
            'tagline'         => $d['tagline'] ?? null,
            'year'            => $year,
            'release'         => $date,
            'status'          => $d['status'] ?? null,
            'runtime'         => $runtime,
            'genres'          => $genres,
            'overview'        => $d['overview'] ?? null,
            'vote'            => isset($d['vote_average']) ? round((float) $d['vote_average'], 1) : null,
            'vote_count'      => (int) ($d['vote_count'] ?? 0),
            'popularity'      => isset($d['popularity']) ? round((float) $d['popularity'], 1) : null,
            'poster'          => TmdbClient::posterUrl($d['poster_path'] ?? null, 'w500'),
            'poster_path'     => $d['poster_path'] ?? null,
            'backdrop'        => TmdbClient::backdropUrl($d['backdrop_path'] ?? null, 'w1280'),
            'homepage'        => $d['homepage'] ?? null,
            'imdb_id'         => $d['imdb_id'] ?? ($d['external_ids']['imdb_id'] ?? null),
            'tvdb_id'         => $d['external_ids']['tvdb_id'] ?? null,
            'networks'        => $networks,
            'seasons'         => isset($d['number_of_seasons']) ? (int) $d['number_of_seasons'] : null,
            'episodes'        => isset($d['number_of_episodes']) ? (int) $d['number_of_episodes'] : null,
            'episode_runtime' => !$isMovie ? ($d['episode_run_time'][0] ?? null) : null,
            'in_production'   => !$isMovie ? (bool) ($d['in_production'] ?? false) : null,
            'last_air_date'   => !$isMovie ? ($d['last_air_date'] ?? null) : null,
            'next_episode'    => $nextEpisode,
            'last_episode'    => $lastEpisode,
            'creators'        => $creators,
            'original_language' => $d['original_language'] ?? null,
            'spoken_languages'  => $spokenLanguages,
            'countries'       => $countries,
            'companies'       => $companies,
            'collection'      => $collection,
            'budget'          => $budget,
            'revenue'         => $revenue,
            'keywords'        => $keywords,
            'certification'   => $certification,
            'providers'       => $providers,
            'cast'            => $cast,
            'crew'            => $crew,
            'trailer'         => $trailer,
            'video_gallery'   => $gallery,
            'seasons_list'    => $seasonsList,
            'alt_titles'      => $altTitles,
            'reviews'         => $reviews,
            'backdrops'       => $backdrops,
            'similar'         => $similar,
            'in_library'      => $inLibrary,
            'lib_status'      => $libInfo['status'] ?? null,
            'lib_id'          => $libInfo['id'] ?? null,
        ]);
    }

    private function pickTrailer(array $videos): ?array
    {
        $yt = array_filter($videos, fn($v) => ($v['site'] ?? '') === 'YouTube');

        $score = function (array $v): int {
            $s = 0;
            if (($v['type'] ?? '') === 'Trailer') $s += 100;
            elseif (($v['type'] ?? '') === 'Teaser') $s += 50;
            if (($v['official'] ?? false) === true) $s += 40;
            $lang = strtolower($v['iso_639_1'] ?? '');
            if ($lang === 'en') $s += 20;           // EN is more reliable in terms of availability
            elseif ($lang === 'fr') $s += 15;
            // Prefer the most recent
            if (!empty($v['published_at'])) {
                $ts = strtotime($v['published_at']);
                if ($ts) $s += (int) (($ts - strtotime('2000-01-01')) / 86400 / 365);
            }
            return $s;
        };

        usort($yt, fn($a, $b) => $score($b) <=> $score($a));
        $first = reset($yt);
        if (!$first) return null;
        return ['key' => $first['key'], 'name' => $first['name'] ?? 'Trailer'];
    }

    private function pickVideoGallery(array $videos, ?string $excludeKey = null): array
    {
        $out = [];
        foreach ($videos as $v) {
            if (($v['site'] ?? '') !== 'YouTube') continue;
            if ($excludeKey !== null && ($v['key'] ?? '') === $excludeKey) continue;
            if (!in_array($v['type'] ?? '', ['Trailer', 'Teaser', 'Featurette', 'Behind the Scenes'], true)) continue;
            $out[] = [
                'key'  => $v['key'],
                'name' => $v['name'] ?? '',
                'type' => $v['type'] ?? '',
            ];
            if (count($out) >= 4) break;
        }
        return $out;
    }

    private function pickCrew(array $d, bool $isMovie): array
    {
        $crew = $d['credits']['crew'] ?? [];
        $byJob = [];
        foreach ($crew as $c) {
            $byJob[$c['job'] ?? ''][] = $c['name'] ?? '';
        }
        if ($isMovie) {
            return [
                'directors'    => array_values(array_unique($byJob['Director'] ?? [])),
                'writers'      => array_values(array_unique(array_merge($byJob['Screenplay'] ?? [], $byJob['Writer'] ?? [], $byJob['Story'] ?? []))),
                'composers'    => array_values(array_unique($byJob['Original Music Composer'] ?? [])),
                'cinematographers' => array_values(array_unique($byJob['Director of Photography'] ?? [])),
            ];
        }
        return [
            'directors'        => [],
            'writers'          => array_values(array_unique(array_merge($byJob['Writer'] ?? [], $byJob['Story'] ?? []))),
            'composers'        => array_values(array_unique($byJob['Original Music Composer'] ?? [])),
            'cinematographers' => [],
        ];
    }

    private function pickProviders(array $byCountry): array
    {
        foreach (['FR', 'BE', 'LU', 'US', 'GB'] as $cc) {
            if (empty($byCountry[$cc])) continue;
            $p = $byCountry[$cc];
            $pack = [
                'country' => $cc,
                'link'    => $p['link'] ?? null,
                'flatrate' => array_map(fn($x) => [
                    'name' => $x['provider_name'] ?? '',
                    'logo' => !empty($x['logo_path']) ? TmdbClient::posterUrl($x['logo_path'], 'w92') : null,
                ], $p['flatrate'] ?? []),
                'rent'    => array_map(fn($x) => [
                    'name' => $x['provider_name'] ?? '',
                    'logo' => !empty($x['logo_path']) ? TmdbClient::posterUrl($x['logo_path'], 'w92') : null,
                ], $p['rent'] ?? []),
                'buy'     => array_map(fn($x) => [
                    'name' => $x['provider_name'] ?? '',
                    'logo' => !empty($x['logo_path']) ? TmdbClient::posterUrl($x['logo_path'], 'w92') : null,
                ], $p['buy'] ?? []),
            ];
            if ($pack['flatrate'] || $pack['rent'] || $pack['buy']) return $pack;
        }
        return [];
    }

    private function pickMovieCertification(array $results): ?string
    {
        foreach (['FR', 'US'] as $cc) {
            foreach ($results as $r) {
                if (($r['iso_3166_1'] ?? '') !== $cc) continue;
                foreach ($r['release_dates'] ?? [] as $rd) {
                    if (!empty($rd['certification'])) return "[$cc] " . $rd['certification'];
                }
            }
        }
        return null;
    }

    private function pickTvCertification(array $results): ?string
    {
        foreach (['FR', 'US'] as $cc) {
            foreach ($results as $r) {
                if (($r['iso_3166_1'] ?? '') !== $cc) continue;
                if (!empty($r['rating'])) return "[$cc] " . $r['rating'];
            }
        }
        return null;
    }

    #[Route('/decouverte/search', name: 'tmdb_search')]
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return $this->json(['results' => []]);
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $library = $this->buildLibraryIndex();
        $data    = $this->tmdb->searchMulti($q, $page);

        $results = array_values(array_filter(
            $data['results'] ?? [],
            fn($r) => in_array($r['media_type'] ?? '', ['movie', 'tv'], true)
        ));

        return $this->json([
            'results'     => $this->enrich($results, $library),
            'page'        => $data['page']        ?? 1,
            'total_pages' => $data['total_pages'] ?? 1,
        ]);
    }

    // ─── Enriched discovery ─────────────────────────────────

    #[Route('/decouverte/explorer', name: 'tmdb_explorer')]
    public function explorer(): Response
    {
        return $this->render('decouverte/explorer.html.twig');
    }

    #[Route('/decouverte/collection/search', name: 'tmdb_collection_search')]
    public function collectionSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (strlen($q) < 2) return $this->json(['results' => []]);

        $data = $this->tmdb->searchCollection($q);
        $results = [];
        foreach (array_slice($data['results'] ?? [], 0, 10) as $c) {
            $results[] = [
                'id'     => (int) ($c['id'] ?? 0),
                'name'   => $c['name'] ?? '',
                'poster' => TmdbClient::posterUrl($c['poster_path'] ?? null, 'w92'),
            ];
        }
        return $this->json(['results' => $results]);
    }

    #[Route('/decouverte/collection/{id}', name: 'tmdb_collection', requirements: ['id' => '\d+'])]
    public function collection(int $id): JsonResponse
    {
        $data = $this->tmdb->getCollection($id);
        if (!$data || empty($data['parts'])) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.collection_not_found')], 404);
        }

        $library = $this->buildLibraryIndex();
        return $this->json([
            'name'    => $data['name'] ?? '',
            'poster'  => TmdbClient::posterUrl($data['poster_path'] ?? null, 'w342'),
            'results' => $this->enrich($data['parts'] ?? [], $library, 'movie'),
        ]);
    }

    #[Route('/decouverte/person/search', name: 'tmdb_person_search')]
    public function personSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (strlen($q) < 2) return $this->json(['results' => []]);

        $data = $this->tmdb->searchPerson($q);
        $results = [];
        foreach (array_slice($data['results'] ?? [], 0, 8) as $p) {
            $results[] = [
                'id'      => (int) ($p['id'] ?? 0),
                'name'    => $p['name'] ?? '',
                'profile' => TmdbClient::posterUrl($p['profile_path'] ?? null, 'w185'),
                'known'   => $p['known_for_department'] ?? '',
            ];
        }
        return $this->json(['results' => $results]);
    }

    #[Route('/decouverte/person/{id}', name: 'tmdb_person', requirements: ['id' => '\d+'])]
    public function person(int $id): JsonResponse
    {
        $person  = $this->tmdb->getPerson($id);
        $credits = $this->tmdb->getPersonCombinedCredits($id);
        if (!$person || !$credits) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.person_not_found')], 404);
        }

        $library = $this->buildLibraryIndex();

        // Merge cast + crew, deduplicate by id+type
        $all = array_merge($credits['cast'] ?? [], $credits['crew'] ?? []);
        $seen = [];
        $items = [];
        foreach ($all as $c) {
            $mType = $c['media_type'] ?? '';
            if (!in_array($mType, ['movie', 'tv'], true)) continue;
            $key = $mType . '_' . ($c['id'] ?? 0);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = $c;
        }

        // Sort by popularity desc
        usort($items, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

        return $this->json([
            'name'    => $person['name'] ?? '',
            'profile' => TmdbClient::posterUrl($person['profile_path'] ?? null, 'w342'),
            'bio'     => $person['biography'] ?? null,
            'results' => $this->enrich(array_slice($items, 0, 40), $library),
        ]);
    }

    // ─── Watchlist ──────────────────────────────────────────

    #[Route('/decouverte/watchlist', name: 'tmdb_watchlist')]
    public function watchlist(): Response
    {
        $items = $this->watchlistRepo->findAllOrdered();
        $library = $this->buildLibraryIndex();

        $list = [];
        foreach ($items as $w) {
            $inLib = $w->getMediaType() === 'movie'
                ? isset($library['movie'][$w->getTmdbId()])
                : isset($library['tv']['tmdb_' . $w->getTmdbId()]);
            $list[] = [
                'id'        => $w->getId(),
                'tmdbId'    => $w->getTmdbId(),
                'type'      => $w->getMediaType(),
                'title'     => $w->getTitle(),
                'poster'    => TmdbClient::posterUrl($w->getPosterPath(), 'w342'),
                'vote'      => $w->getVote(),
                'year'      => $w->getYear(),
                'addedAt'   => $w->getAddedAt()->format('Y-m-d H:i'),
                'notes'     => $w->getNotes(),
                'in_library' => $inLib,
            ];
        }

        return $this->render('decouverte/watchlist.html.twig', [
            'items' => $list,
        ]);
    }

    // No CSRF token: internal app, routes protected by class-level #[IsGranted('ROLE_USER')].
    #[Route('/decouverte/watchlist/toggle', name: 'tmdb_watchlist_toggle', methods: ['POST'])]
    public function watchlistToggle(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $tmdbId   = (int) ($data['tmdb_id'] ?? 0);
        $type     = ($data['type'] ?? '');
        if (!$tmdbId || !in_array($type, ['movie', 'tv'], true)) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.invalid_params')], 400);
        }

        $existing = $this->watchlistRepo->findByTmdb($tmdbId, $type);
        if ($existing) {
            $this->em->remove($existing);
            $this->em->flush();
            return $this->json(['action' => 'removed', 'tmdb_id' => $tmdbId, 'type' => $type]);
        }

        $item = new WatchlistItem();
        $item->setTmdbId($tmdbId)
             ->setMediaType($type)
             ->setTitle($data['title'] ?? 'Sans titre')
             ->setPosterPath($data['poster_path'] ?? null)
             ->setVote(isset($data['vote']) ? (float) $data['vote'] : null)
             ->setYear(isset($data['year']) ? (int) $data['year'] : null);

        $this->em->persist($item);
        $this->em->flush();

        return $this->json(['action' => 'added', 'tmdb_id' => $tmdbId, 'type' => $type, 'id' => $item->getId()]);
    }

    // No CSRF token: internal app, routes protected by class-level #[IsGranted('ROLE_USER')].
    #[Route('/decouverte/watchlist/notes/{id}', name: 'tmdb_watchlist_notes', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function watchlistNotes(int $id, Request $request): JsonResponse
    {
        $item = $this->watchlistRepo->find($id);
        if (!$item) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.not_found')], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $item->setNotes($data['notes'] ?? null);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    // No CSRF token: internal app, routes protected by class-level #[IsGranted('ROLE_USER')].
    #[Route('/decouverte/watchlist/remove/{id}', name: 'tmdb_watchlist_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function watchlistRemove(int $id): JsonResponse
    {
        $item = $this->watchlistRepo->find($id);
        if (!$item) {
            return $this->json(['error' => $this->translator->trans('decouverte.error.not_found')], 404);
        }

        $this->em->remove($item);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/decouverte/watchlist/status', name: 'tmdb_watchlist_status')]
    public function watchlistStatus(): JsonResponse
    {
        return $this->json(['index' => $this->watchlistRepo->getWatchlistIndex()]);
    }

    /**
     * Build an index {movie:[tmdbIds], tv:[tvdbIds/tmdbIds]} to flag
     * "already in library" on TMDb cards.
     */
    private function buildLibraryIndex(): array
    {
        $movieIds = [];
        $tvIds    = [];

        try {
            foreach ($this->radarr->getMovies() as $m) {
                if (!empty($m['tmdbId'])) {
                    // Status: downloaded / missing / announced / inCinemas / unmonitored
                    $hasFile   = !empty($m['hasFile']);
                    $monitored = !empty($m['monitored']);
                    $status    = $m['status'] ?? 'released';

                    if (!$monitored) {
                        $libStatus = 'unmonitored';
                    } elseif ($hasFile) {
                        $libStatus = 'downloaded';
                    } elseif ($status === 'announced') {
                        $libStatus = 'announced';
                    } elseif ($status === 'inCinemas') {
                        $libStatus = 'inCinemas';
                    } else {
                        $libStatus = 'missing';
                    }

                    $movieIds[(int) $m['tmdbId']] = [
                        'id'     => (int) $m['id'],
                        'status' => $libStatus,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TMDb buildLibraryIndex failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            // Radarr down → carry on without the badge
        }

        try {
            foreach ($this->sonarr->getRawAllSeries() as $s) {
                $tvdbId = !empty($s['tvdbId']) ? (int) $s['tvdbId'] : null;
                $tmdbId = !empty($s['tmdbId']) ? (int) $s['tmdbId'] : null;
                $monitored = !empty($s['monitored']);
                $stats     = $s['statistics'] ?? [];
                $fileCount = (int) ($stats['episodeFileCount'] ?? 0);
                $totalEps  = (int) ($stats['episodeCount'] ?? 0);
                $seriesStatus = $s['status'] ?? '';

                if (!$monitored) {
                    $libStatus = 'unmonitored';
                } elseif ($totalEps > 0 && $fileCount >= $totalEps) {
                    $libStatus = 'downloaded';
                } elseif ($fileCount > 0) {
                    $libStatus = 'partial';
                } elseif ($seriesStatus === 'upcoming') {
                    $libStatus = 'announced';
                } else {
                    $libStatus = 'missing';
                }

                $info = [
                    'id'     => (int) $s['id'],
                    'status' => $libStatus,
                ];

                if ($tvdbId) $tvIds['tvdb_' . $tvdbId] = $info;
                if ($tmdbId) $tvIds['tmdb_' . $tmdbId] = $info;

                if ($tvdbId && !$tmdbId) {
                    $resolved = $this->tmdb->findTmdbIdByTvdbId($tvdbId);
                    if ($resolved) $tvIds['tmdb_' . $resolved] = $info;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TMDb buildLibraryIndex failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            // Sonarr down → same
        }

        return ['movie' => $movieIds, 'tv' => $tvIds];
    }

    /**
     * Enrich each TMDb item with: normalized type, title, year, poster URL,
     * in_library flag.
     */
    private function enrich(array $items, array $library, ?string $forceType = null): array
    {
        $out = [];
        foreach ($items as $it) {
            $type = $forceType ?? ($it['media_type'] ?? null);
            if (!in_array($type, ['movie', 'tv'], true)) {
                continue;
            }

            $title = $type === 'movie' ? ($it['title'] ?? '') : ($it['name'] ?? '');
            $date  = $type === 'movie' ? ($it['release_date'] ?? null) : ($it['first_air_date'] ?? null);
            $year  = $date ? (int) substr($date, 0, 4) : null;

            $libInfo = null;
            if ($type === 'movie') {
                $libInfo = $library['movie'][(int) ($it['id'] ?? 0)] ?? null;
            } else {
                $libInfo = $library['tv']['tmdb_' . (int) ($it['id'] ?? 0)] ?? null;
            }

            $out[] = [
                'id'          => (int) ($it['id'] ?? 0),
                'type'        => $type,
                'title'       => $title,
                'year'        => $year,
                'release'     => $date,
                'overview'    => $it['overview']      ?? null,
                'poster'      => TmdbClient::posterUrl($it['poster_path'] ?? null, 'w342'),
                'poster_hi'   => TmdbClient::posterUrl($it['poster_path'] ?? null, 'w500'),
                'backdrop'    => TmdbClient::backdropUrl($it['backdrop_path'] ?? null, 'w780'),
                'vote'        => isset($it['vote_average']) ? round((float) $it['vote_average'], 1) : null,
                'vote_count'  => (int) ($it['vote_count'] ?? 0),
                'popularity'  => $it['popularity'] ?? null,
                'in_library'  => $libInfo !== null,
                'lib_status'  => $libInfo['status'] ?? null,
                'lib_id'      => $libInfo['id'] ?? null,
            ];
        }
        return $out;
    }
}
