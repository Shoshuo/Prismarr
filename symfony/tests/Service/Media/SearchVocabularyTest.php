<?php

namespace App\Tests\Service\Media;

use App\Message\RefreshCacheKey;
use App\Service\Cache\SearchVocabularyRefresher;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\SearchVocabulary;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class SearchVocabularyTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class($this->dispatched) implements MessageBusInterface {
            public function __construct(private array &$sink) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function tmdb(): TmdbClient
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getLocale')->willReturn('fr-FR');
        $tmdb->method('getPopularTv')->willReturnCallback(fn(int $p) => $p === 1
            ? ['results' => [['name' => 'Pokémon', 'original_name' => 'ポケットモンスター', 'popularity' => 152]]]
            : ['results' => []]);
        $tmdb->method('getPopularMovies')->willReturnCallback(fn(int $p) => $p === 1
            ? ['results' => [['title' => 'Matrix', 'original_title' => 'The Matrix', 'popularity' => 70]]]
            : ['results' => []]);
        $tmdb->method('getTopRatedMovies')->willReturn(['results' => [['title' => 'Matrix', 'original_title' => 'The Matrix', 'popularity' => 75]]]);
        $tmdb->method('getTopRatedTv')->willReturn(['results' => []]);
        $tmdb->method('getPopularPeople')->willReturn(['results' => [['name' => 'Christopher Nolan', 'popularity' => 25]]]);
        $tmdb->method('getTrendingAll')->willReturn(['results' => []]);

        return $tmdb;
    }

    public function testBuildCollectsTitlesOriginalTitlesAndPeopleOnce(): void
    {
        $vocab = new SearchVocabulary($this->swr(new ArrayAdapter()), $this->tmdb(), new NullLogger());
        $byLabel = array_column($vocab->build(), 'popularity', 'label');

        self::assertSame(152.0, $byLabel['Pokémon']);
        self::assertSame(75.0, $byLabel['The Matrix'], 'duplicates keep the highest popularity');
        self::assertArrayHasKey('Matrix', $byLabel);
        self::assertArrayHasKey('Christopher Nolan', $byLabel);
        self::assertSame('tmdb.search_vocab.fr-FR', $vocab->key());
    }

    public function testBuildReturnsNothingWhenTmdbFails(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getPopularMovies')->willThrowException(new \RuntimeException('down'));
        $vocab = new SearchVocabulary($this->swr(new ArrayAdapter()), $tmdb, new NullLogger());

        self::assertSame([], $vocab->build());
    }

    public function testBuildReturnsNothingWhenTooManyPagesComeBackEmpty(): void
    {
        // TmdbClient answers [] (no throw) on a failed call: a mostly-failed build must not be cached.
        $tmdb = $this->createMock(TmdbClient::class);
        foreach (['getPopularTv', 'getTopRatedMovies', 'getTopRatedTv', 'getPopularPeople', 'getTrendingAll'] as $m) {
            $tmdb->method($m)->willReturn([]);
        }
        $tmdb->method('getPopularMovies')->willReturnCallback(fn(int $p) => $p === 1
            ? ['results' => [['title' => 'Matrix', 'popularity' => 70]]]
            : []);
        $vocab = new SearchVocabulary($this->swr(new ArrayAdapter()), $tmdb, new NullLogger());

        self::assertSame([], $vocab->build());
    }

    public function testReadNeverFetchesAndQueuesARebuildOnAMiss(): void
    {
        $tmdb = $this->tmdb();
        $tmdb->expects(self::never())->method('getPopularMovies');
        $vocab = new SearchVocabulary($this->swr(new ArrayAdapter()), $tmdb, new NullLogger());

        self::assertSame([], $vocab->read());
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(RefreshCacheKey::class, $this->dispatched[0]);
        self::assertSame('tmdb.search_vocab.fr-FR', $this->dispatched[0]->key);
    }

    public function testRefresherWritesAFreshVocabularyThenStaysIdle(): void
    {
        $pool = new ArrayAdapter();
        $swr = $this->swr($pool);
        $vocab = new SearchVocabulary($swr, $this->tmdb(), new NullLogger());
        $refresher = new SearchVocabularyRefresher($vocab, $swr);

        self::assertTrue($refresher->supports('tmdb.search_vocab.fr-FR'));
        self::assertFalse($refresher->supports('media.movies.radarr-1'));

        $refresher->refresh('tmdb.search_vocab.fr-FR');
        $labels = array_column($vocab->read(), 'label');
        self::assertContains('Pokémon', $labels);
        self::assertSame([], $this->dispatched, 'a fresh entry queues nothing');
    }

    public function testRefresherKeepsTheOldValueOnAFailedBuild(): void
    {
        $pool = new ArrayAdapter();
        $swr = $this->swr($pool);
        $swr->write('tmdb.search_vocab.fr-FR', [['label' => 'Kept', 'popularity' => 1]], SearchVocabulary::HARD_TTL, time() - 2 * SearchVocabulary::SOFT_TTL);

        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->method('getLocale')->willReturn('fr-FR');
        $tmdb->method('getPopularMovies')->willThrowException(new \RuntimeException('down'));
        $vocab = new SearchVocabulary($swr, $tmdb, new NullLogger());

        (new SearchVocabularyRefresher($vocab, $swr))->refresh('tmdb.search_vocab.fr-FR');
        self::assertSame('Kept', $swr->read('tmdb.search_vocab.fr-FR', SearchVocabulary::SOFT_TTL)['value'][0]['label']);
    }
}
