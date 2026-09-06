<?php

namespace App\Tests\Service\Media;

use App\Service\Media\ProwlarrClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers ProwlarrClient::safeInfoUrl() — the guard search() applies to the
 * indexer-supplied `infoUrl` before it's ever handed to the frontend as an
 * <a href>. Upstream (this becomes a standalone PR — #71) has no CSP to
 * fall back on, so a `javascript:`/`data:` scheme in a hostile indexer's
 * response must be neutralized here, not just relied on client-side.
 *
 * The method is private static; invoked via reflection rather than
 * widening the production API (same convention as ClientErrorExtractionTest).
 */
class ProwlarrSafeInfoUrlTest extends TestCase
{
    private function call(mixed $url): ?string
    {
        $ref = new \ReflectionClass(ProwlarrClient::class);
        $method = $ref->getMethod('safeInfoUrl');
        $method->setAccessible(true);

        return $method->invoke(null, $url);
    }

    public function testJavascriptSchemeIsRejected(): void
    {
        $this->assertNull($this->call('javascript:alert(1)'));
    }

    public function testDataSchemeIsRejected(): void
    {
        $this->assertNull($this->call('data:text/html,<script>alert(1)</script>'));
    }

    public function testHttpUrlIsKept(): void
    {
        $this->assertSame('http://tracker.example.com/details?id=1', $this->call('http://tracker.example.com/details?id=1'));
    }

    public function testHttpsUrlIsKept(): void
    {
        $this->assertSame('https://tracker.example.com/details?id=1', $this->call('https://tracker.example.com/details?id=1'));
    }

    public function testUppercaseSchemeIsKept(): void
    {
        $this->assertSame('HTTPS://tracker.example.com/details?id=1', $this->call('HTTPS://tracker.example.com/details?id=1'));
    }

    public function testNonStringInputIsRejected(): void
    {
        $this->assertNull($this->call(null));
        $this->assertNull($this->call(123));
        $this->assertNull($this->call(['not' => 'a string']));
    }

    public function testEmptyStringIsRejected(): void
    {
        $this->assertNull($this->call(''));
    }

    public function testSchemeRelativeUrlIsRejected(): void
    {
        $this->assertNull($this->call('//tracker.example.com/details?id=1'));
    }
}
