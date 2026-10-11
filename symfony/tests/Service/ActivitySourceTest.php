<?php

namespace App\Tests\Service;

use App\Service\ActivitySource;
use PHPUnit\Framework\TestCase;

class ActivitySourceTest extends TestCase
{
    public function testAutoPrefersTautulli(): void
    {
        self::assertSame('tautulli', ActivitySource::resolve(null, fn() => true));
        self::assertSame('tautulli', ActivitySource::resolve('auto', fn() => true));
    }

    public function testPreferenceReordersCandidates(): void
    {
        self::assertSame('tracearr', ActivitySource::resolve('tracearr', fn() => true));
    }

    public function testUnusablePreferenceFallsBack(): void
    {
        self::assertSame('tautulli', ActivitySource::resolve('tracearr', fn(string $s) => $s === 'tautulli'));
        self::assertSame('tracearr', ActivitySource::resolve('tautulli', fn(string $s) => $s === 'tracearr'));
    }

    public function testNothingUsable(): void
    {
        self::assertNull(ActivitySource::resolve('tracearr', fn() => false));
        self::assertNull(ActivitySource::resolve('garbage', fn() => false));
    }
}
