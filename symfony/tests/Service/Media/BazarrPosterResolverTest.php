<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Maps Bazarr's radarrId/sonarrSeriesId to OUR Radarr/Sonarr library poster
 * URLs. Same multi-instance fail-closed gate as BazarrSubtitleIndex: a bare
 * *arr id is only unambiguous when its owning service pairs with exactly one
 * enabled instance.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrPosterResolverTest extends TestCase
{
    /** ServiceInstanceProvider reporting $radarr enabled Radarr / $sonarr enabled Sonarr instances. */
    private function instances(int $radarr = 1, int $sonarr = 1): ServiceInstanceProvider
    {
        $make = static fn (string $type, int $n) => $n > 0
            ? array_map(
                static fn (int $i) => new ServiceInstance($type, $type . '-' . $i, ucfirst($type) . ' ' . $i, 'http://x', 'k'),
                range(1, $n),
            )
            : [];

        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturnCallback(
            static fn (string $type) => $type === ServiceInstance::TYPE_RADARR
                ? $make(ServiceInstance::TYPE_RADARR, $radarr)
                : $make(ServiceInstance::TYPE_SONARR, $sonarr),
        );
        $provider->method('hasExactlyOneEnabled')->willReturnCallback(
            static fn (string $type) => ($type === ServiceInstance::TYPE_RADARR ? $radarr : $sonarr) === 1,
        );

        return $provider;
    }

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope { return new Envelope($message); }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function resolver(
        ?RadarrClient $radarr = null,
        ?SonarrClient $sonarr = null,
        ?ServiceInstanceProvider $instances = null,
        ?MediaLibraryCache $libraryCache = null,
    ): BazarrPosterResolver {
        $radarr ??= $this->createMock(RadarrClient::class);
        $sonarr ??= $this->createMock(SonarrClient::class);

        return new BazarrPosterResolver(
            $instances ?? $this->instances(),
            $radarr,
            $sonarr,
            $libraryCache ?? new MediaLibraryCache($this->swr(new ArrayAdapter())),
        );
    }

    public function testMoviePostersOmitsNullPosterRows(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->method('getMovies')->willReturn([
            ['id' => 5, 'poster' => '/p/5.jpg'],
            ['id' => 6, 'poster' => null],
        ]);

        $resolver = $this->resolver(radarr: $radarr);

        $this->assertSame([5 => '/p/5.jpg'], $resolver->postersFor('movie'));
    }

    public function testSeriesPostersOmitEmptyPosterRows(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('withInstance')->willReturnSelf();
        $sonarr->method('getSeries')->willReturn([
            ['id' => 10, 'poster' => '/p/10.jpg'],
            ['id' => 11, 'poster' => ''],
        ]);

        $resolver = $this->resolver(sonarr: $sonarr);

        $this->assertSame([10 => '/p/10.jpg'], $resolver->postersFor('series'));
    }

    public function testMultipleEnabledRadarrInstancesReturnsEmptyMap(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getMovies');

        $resolver = $this->resolver(radarr: $radarr, instances: $this->instances(radarr: 2));

        $this->assertSame([], $resolver->postersFor('movie'));
    }

    public function testZeroEnabledRadarrInstancesReturnsEmptyMap(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getMovies');

        $resolver = $this->resolver(radarr: $radarr, instances: $this->instances(radarr: 0));

        $this->assertSame([], $resolver->postersFor('movie'));
    }

    public function testMultipleEnabledSonarrInstancesReturnsEmptyMap(): void
    {
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->never())->method('getSeries');

        $resolver = $this->resolver(sonarr: $sonarr, instances: $this->instances(sonarr: 2));

        $this->assertSame([], $resolver->postersFor('series'));
    }

    public function testUnknownKindReturnsEmptyMap(): void
    {
        $resolver = $this->resolver();

        $this->assertSame([], $resolver->postersFor('episode'));
    }

    public function testFailedLibraryFetchReturnsEmptyMap(): void
    {
        // RadarrClient::getMovies() already fails closed to [] on a transport
        // error / circuit breaker trip (never throws) — the resolver must
        // surface that as an empty poster map, not an exception.
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->method('getMovies')->willReturn([]);

        $resolver = $this->resolver(radarr: $radarr);

        $this->assertSame([], $resolver->postersFor('movie'));
    }

    public function testLibraryCacheIsReusedAcrossCalls(): void
    {
        // Backed by the shared MediaLibraryCache — a second postersFor() call
        // for the same instance within the TTL must not re-fetch.
        $pool = new ArrayAdapter();
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->expects($this->once())->method('getMovies')->willReturn([
            ['id' => 5, 'poster' => '/p/5.jpg'],
        ]);

        $resolver = $this->resolver($radarr, libraryCache: new MediaLibraryCache($this->swr($pool)));

        $this->assertSame([5 => '/p/5.jpg'], $resolver->postersFor('movie'));
        $this->assertSame([5 => '/p/5.jpg'], $resolver->postersFor('movie'));
    }
}
