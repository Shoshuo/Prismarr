<?php

namespace App\Tests\Service;

use App\Controller\DashboardController;
use App\Service\HealthService;
use App\Service\Media\RadarrClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * FrankenPHP worker-mode regression suite for the two services the readiness
 * audit flagged as missing ResetInterface (HealthService, DashboardController)
 * plus a re-assertion of the highest-severity reset (RadarrClient's per-request
 * instance binding).
 *
 * In worker mode Symfony boots the kernel once and keeps every service alive
 * across requests; Symfony's services_resetter calls reset() on each
 * kernel.reset-tagged service between requests. Any service implementing
 * ResetInterface is auto-tagged, so implementing it here is what wires the
 * reset in. These tests assert (a) the contract (implements ResetInterface, so
 * the container tags it) and (b) that reset() actually clears the documented
 * per-request state — without which a first request's data would bleed into
 * every later request served by the same worker.
 *
 * NOTE: the end-to-end firing of the container's services_resetter under a live
 * FrankenPHP worker is not exercised here (a WebTestCase reboots the kernel per
 * test, so it cannot observe cross-request bleed). That must be confirmed by
 * the manual beta soak with PRISMARR_WORKER=1.
 */
class WorkerModeResetTest extends TestCase
{
    /**
     * @return list<array{0: class-string}>
     */
    public static function resettableProvider(): array
    {
        return [
            [HealthService::class],
            [DashboardController::class],
            // Highest-severity path (audit): clears the per-request Radarr
            // instance binding so a non-default instance can't leak to the
            // next request. Already covered behaviourally by ClientResetTest;
            // re-asserted here as part of the worker-mode contract set.
            [RadarrClient::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('resettableProvider')]
    public function testServiceImplementsResetInterface(string $class): void
    {
        $this->assertTrue(
            is_subclass_of($class, ResetInterface::class),
            "$class must implement ResetInterface so Symfony auto-tags it kernel.reset and clears its per-request state between worker requests"
        );
    }

    public function testHealthServiceResetClearsInProcessMemoAndGeneration(): void
    {
        // reset() only touches two private properties; skip the heavy
        // constructor (13 collaborators) via newInstanceWithoutConstructor.
        $ref     = new \ReflectionClass(HealthService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $statusCache = $ref->getProperty('statusCache');
        $statusCache->setAccessible(true);
        $generation = $ref->getProperty('generation');
        $generation->setAccessible(true);

        // Simulate a request having populated the 10 s in-process memo and the
        // pool generation token.
        $statusCache->setValue($service, [
            'radarr:radarr-4k' => ['result' => ['status' => 'up', 'latencyMs' => 12], 'at' => time()],
        ]);
        $generation->setValue($service, 'deadbeef');

        $this->assertNotSame([], $statusCache->getValue($service));
        $this->assertNotNull($generation->getValue($service));

        $service->reset();

        $this->assertSame([], $statusCache->getValue($service), 'statusCache must be emptied on reset');
        $this->assertNull($generation->getValue($service), 'generation token must be dropped on reset');
    }

    public function testDashboardControllerResetNullsLibraryMemos(): void
    {
        $ref        = new \ReflectionClass(DashboardController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $movies = $ref->getProperty('moviesCache');
        $movies->setAccessible(true);
        $series = $ref->getProperty('seriesCache');
        $series->setAccessible(true);

        // Prime the per-request memo as the first dashboard paint would.
        $movies->setValue($controller, [['id' => 1, 'title' => 'Dune']]);
        $series->setValue($controller, [['id' => 2, 'title' => 'Severance']]);

        $this->assertNotNull($movies->getValue($controller));
        $this->assertNotNull($series->getValue($controller));

        $controller->reset();

        $this->assertNull($movies->getValue($controller), 'moviesCache must be nulled on reset');
        $this->assertNull($series->getValue($controller), 'seriesCache must be nulled on reset');
    }
}
