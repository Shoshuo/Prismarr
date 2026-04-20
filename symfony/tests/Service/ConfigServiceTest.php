<?php

namespace App\Tests\Service;

use App\Exception\ServiceNotConfiguredException;
use App\Repository\SettingRepository;
use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    public function testGetReturnsValue(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn(['radarr_url' => 'http://localhost:7878']);

        $svc = new ConfigService($repo);
        $this->assertSame('http://localhost:7878', $svc->get('radarr_url'));
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);

        $svc = new ConfigService($repo);
        $this->assertNull($svc->get('unknown_key'));
    }

    public function testGetTreatsEmptyStringAsNull(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn(['radarr_url' => '']);

        $svc = new ConfigService($repo);
        $this->assertNull($svc->get('radarr_url'));
    }

    public function testHasReturnsTrueWhenPresent(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn(['tmdb_api_key' => 'abc123']);

        $svc = new ConfigService($repo);
        $this->assertTrue($svc->has('tmdb_api_key'));
    }

    public function testHasReturnsFalseWhenMissing(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);

        $svc = new ConfigService($repo);
        $this->assertFalse($svc->has('tmdb_api_key'));
    }

    public function testRequireReturnsValue(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn(['radarr_api_key' => 'xyz']);

        $svc = new ConfigService($repo);
        $this->assertSame('xyz', $svc->require('radarr_api_key', 'Radarr'));
    }

    public function testRequireThrowsIfMissing(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);

        $svc = new ConfigService($repo);
        $this->expectException(ServiceNotConfiguredException::class);
        $svc->require('radarr_api_key', 'Radarr');
    }

    public function testCacheAvoidsSecondRepositoryCall(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        // expects exactly 1 call — the second get() must hit the cache
        $repo->expects($this->once())->method('getAll')->willReturn(['x' => '1']);

        $svc = new ConfigService($repo);
        $svc->get('x');
        $svc->get('x');
    }

    public function testResetClearsCache(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        // expects 2 calls: one for initial load, one after reset()
        $repo->expects($this->exactly(2))->method('getAll')->willReturn(['x' => '1']);

        $svc = new ConfigService($repo);
        $svc->get('x');
        $svc->reset();
        $svc->get('x');
    }

    public function testInvalidateClearsCache(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->expects($this->exactly(2))->method('getAll')->willReturn(['x' => '1']);

        $svc = new ConfigService($repo);
        $svc->get('x');
        $svc->invalidate();
        $svc->get('x');
    }

    public function testSetPersistsToRepositoryAndInvalidatesCache(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->expects($this->once())->method('set')->with('tmdb_api_key', 'new-value');
        // After set(), cache invalidated → getAll called again on next get()
        $repo->expects($this->once())->method('getAll')->willReturn(['tmdb_api_key' => 'new-value']);

        $svc = new ConfigService($repo);
        $svc->set('tmdb_api_key', 'new-value');
        $this->assertSame('new-value', $svc->get('tmdb_api_key'));
    }
}
