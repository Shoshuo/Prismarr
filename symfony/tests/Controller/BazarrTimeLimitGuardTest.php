<?php

namespace App\Tests\Controller;

use App\Controller\BazarrController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source sentinel: the Bazarr actions that wait on a
 * long BazarrClient budget must raise PHP's execution limit past it, or a
 * user-lowered PHP_MAX_EXECUTION_TIME (FrankenPHP's limit is wall-clock)
 * kills a slow search/download/rebuild mid-flight with an HTML fatal page.
 */
class BazarrTimeLimitGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function longActions(): iterable
    {
        foreach (['apiSearchMovie', 'apiSearchEpisode', 'apiDownloadMovie', 'apiDownloadEpisode', 'apiRefresh'] as $m) {
            yield $m => [$m];
        }
    }

    #[DataProvider('longActions')]
    public function testLongBazarrActionRaisesTheExecutionLimit(string $method): void
    {
        $ref   = new \ReflectionMethod(BazarrController::class, $method);
        $lines = file((string) $ref->getFileName());
        $this->assertNotFalse($lines);
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $this->assertMatchesRegularExpression('/set_time_limit\(self::\w+\)/', $body, $method . ' must call set_time_limit() with its budget constant');
    }
}
