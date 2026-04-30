<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\FatalErrorHandlerSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the issue #13 friendly-fatal page. We test the pure
 * decision tree (renderForError) — handleFatal()'s buffer cleanup and
 * header emission can't be exercised cleanly in PHPUnit because the
 * surrounding shutdown context only exists at real request teardown.
 */
class FatalErrorHandlerSubscriberTest extends TestCase
{
    public function testReturnsNullWhenNoErrorOccurred(): void
    {
        // Happy path — no error_get_last() to act on means we should keep
        // out of Symfony's way entirely.
        $this->assertNull(FatalErrorHandlerSubscriber::renderForError(null));
    }

    public function testReturnsNullForNonFatalErrors(): void
    {
        // E_WARNING / E_NOTICE / E_DEPRECATED stay handled by Symfony's
        // existing logger pipeline. We MUST NOT emit our HTML page on top
        // of them.
        $this->assertNull(FatalErrorHandlerSubscriber::renderForError([
            'type'    => E_WARNING,
            'message' => 'Undefined variable: foo',
        ]));
        $this->assertNull(FatalErrorHandlerSubscriber::renderForError([
            'type'    => E_NOTICE,
            'message' => 'Trying to access array offset',
        ]));
    }

    public function testReturnsNullForFatalErrorsWeDontOwn(): void
    {
        // A genuine code bug (Class not found, syntax error in a Twig
        // template, …) is best surfaced as the standard 500 — our friendly
        // page would mislead the user into bumping memory for nothing.
        $this->assertNull(FatalErrorHandlerSubscriber::renderForError([
            'type'    => E_ERROR,
            'message' => "Uncaught Error: Class 'Foo\\Bar' not found",
        ]));
    }

    public function testRendersPageOnMemoryExhaustion(): void
    {
        // The exact wording PHP emits — we substring-match on it inside
        // renderForError. Issue #13 reports show this text verbatim.
        $html = FatalErrorHandlerSubscriber::renderForError([
            'type'    => E_ERROR,
            'message' => 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes) in /var/www/html/src/Service/Media/RadarrClient.php on line 1380',
        ]);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Prismarr ran out of memory', $html);
        // Surface the env-var workaround so the user has an actionable fix.
        $this->assertStringContainsString('PHP_MEMORY_LIMIT', $html);
        // Self-contained: the page MUST NOT depend on assets (CSS/JS) that
        // could themselves fail to render in the post-fatal context.
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertStringNotContainsString('<script src=', $html);
    }

    public function testRendersPageOnExecutionTimeExceeded(): void
    {
        $html = FatalErrorHandlerSubscriber::renderForError([
            'type'    => E_ERROR,
            'message' => 'Maximum execution time of 60 seconds exceeded in /var/www/html/src/Controller/MediaController.php on line 56',
        ]);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Prismarr request timed out', $html);
        // Workaround instructions surface the env-var name the user can
        // bump in their compose file.
        $this->assertStringContainsString('PHP_MAX_EXECUTION_TIME', $html);
    }

    /**
     * @return iterable<string, array{0: array{type:int,message:string}}>
     */
    public static function fatalLevelsProvider(): iterable
    {
        // PHP can shutdown with several "fatal" type codes; we recognize
        // all of them so a parse / compile error in a hot-reloaded class
        // doesn't bypass the page.
        $msg = 'Allowed memory size of 268435456 bytes exhausted';
        yield 'E_ERROR'         => [['type' => E_ERROR,         'message' => $msg]];
        yield 'E_PARSE'         => [['type' => E_PARSE,         'message' => $msg]];
        yield 'E_CORE_ERROR'    => [['type' => E_CORE_ERROR,    'message' => $msg]];
        yield 'E_COMPILE_ERROR' => [['type' => E_COMPILE_ERROR, 'message' => $msg]];
    }

    #[DataProvider('fatalLevelsProvider')]
    public function testHandlesAllFatalLevels(array $err): void
    {
        $this->assertNotNull(FatalErrorHandlerSubscriber::renderForError($err));
    }
}
