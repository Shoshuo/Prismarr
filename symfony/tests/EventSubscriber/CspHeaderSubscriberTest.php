<?php

namespace App\Tests\EventSubscriber;

use App\Entity\ServiceInstance;
use App\EventSubscriber\CspHeaderSubscriber;
use App\Service\ConfigService;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class CspHeaderSubscriberTest extends TestCase
{
    private function event(Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $response);
    }

    /**
     * Build a subscriber from a flat key=>url map. Keys "radarr_url" and
     * "sonarr_url" go through the instance provider (v1.1.0 source of truth);
     * other keys still use ConfigService::get().
     *
     * @param array<string, string> $urls
     */
    private function subscriberWithUrls(array $urls, string $frameAncestors = ''): CspHeaderSubscriber
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $key) => $urls[$key] ?? null);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(function (string $type) use ($urls): array {
            $key = match ($type) {
                ServiceInstance::TYPE_RADARR => 'radarr_url',
                ServiceInstance::TYPE_SONARR => 'sonarr_url',
                default => null,
            };
            if ($key === null || !isset($urls[$key])) {
                return [];
            }
            $instance = new ServiceInstance($type, $type . '-1', ucfirst($type) . ' 1', $urls[$key]);
            return [$instance];
        });

        return new CspHeaderSubscriber($config, $instances, $frameAncestors);
    }

    public function testSetsHeaderOnMainRequest(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        $sub->onResponse($this->event($response));

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
    }

    public function testStaticHostsAlwaysIncluded(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://image.tmdb.org', $csp);
        $this->assertStringContainsString('https://ui-avatars.com', $csp);
        $this->assertStringContainsString('https://artworks.thetvdb.com', $csp);
    }

    public function testConfiguredRadarrUrlIsAddedToImgSrc(): void
    {
        $sub = $this->subscriberWithUrls([
            'radarr_url' => 'http://192.0.2.10:7878',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://192.0.2.10:7878', $csp);
    }

    public function testUrlWithPathIsReducedToOrigin(): void
    {
        // Input has a path (/api/v1), CSP must only contain scheme://host:port.
        $sub = $this->subscriberWithUrls([
            'sonarr_url' => 'http://localhost:8989/api/v3',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://localhost:8989', $csp);
        $this->assertStringNotContainsString('/api/v3', $csp);
    }

    public function testAnInternationalisedServiceUrlLandsInImgSrcAsPunycode(): void
    {
        // Final review, minor #14: the same hostname regex as the SSRF guard
        // dropped the origin, so the posters of a service on an IDN host were
        // blocked by the policy. Browsers match CSP host-sources against the
        // ASCII serialisation, so the punycode form is what the policy needs.
        $sub = $this->subscriberWithUrls([
            'radarr_url' => 'http://bücher.example:7878',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://xn--bcher-kva.example:7878', $csp);
        $this->assertStringNotContainsString('bücher', $csp);
    }

    public function testInvalidUrlIsIgnored(): void
    {
        $sub = $this->subscriberWithUrls([
            'radarr_url' => 'not a url',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The static hosts are still present but the bad url is not propagated.
        $this->assertStringNotContainsString('not a url', $csp);
    }

    public function testExistingHeaderIsPreserved(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $response->headers->set('Content-Security-Policy', 'default-src none');
        $sub->onResponse($this->event($response));

        $this->assertSame('default-src none', $response->headers->get('Content-Security-Policy'));
    }

    public function testStrictDirectivesArePresent(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function testXFrameOptionsSameOriginByDefault(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self';", $response->headers->get('Content-Security-Policy'));
    }

    public function testFrameAncestorsWidenedAndXFrameOptionsDroppedWhenEnvSet(): void
    {
        // Issue #25 — embed Prismarr in Organizr/Heimdall.
        $sub = $this->subscriberWithUrls([], 'https://organizr.example.com https://dash.example.org');
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self' https://organizr.example.com https://dash.example.org;", $csp);
        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testFrameAncestorsStripsControlCharsToBlockHeaderInjection(): void
    {
        $sub = $this->subscriberWithUrls([], "https://evil.example\r\nSet-Cookie: x=1");
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The CR/LF is gone — the leftover text is harmless inside the directive.
        $this->assertStringNotContainsString("\r", $csp);
        $this->assertStringNotContainsString("\n", $csp);
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function testFrameAncestorsStripsCommasToAvoidMultiPolicyFootgun(): void
    {
        // A comma in a CSP header splits it into multiple policies which the
        // browser then enforces as their intersection. That can't weaken the
        // policy, but it can silently break the app for the admin who typo'd
        // it (e.g. `https://a.com, https://b.com` → second policy has only
        // frame-ancestors set, and default-src 'self' is *missing* there).
        // Stripping `,` removes the footgun.
        $sub = $this->subscriberWithUrls([], 'https://a.test, https://b.test');
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString(',', $csp);
        $this->assertStringContainsString('https://a.test', $csp);
        $this->assertStringContainsString('https://b.test', $csp);
    }

    public function testFrameAncestorsStripsSemicolonsToBlockDirectiveInjection(): void
    {
        // A `;` would close the frame-ancestors directive and let whatever
        // follows be parsed as a new CSP directive. The operator controls
        // the env so this isn't a web-attack surface, but stripping `;`
        // prevents a typo or a copy-paste accident from weakening the CSP.
        $sub = $this->subscriberWithUrls([], "https://a.test; default-src *");
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The `;` after the origin would have closed frame-ancestors and let
        // "default-src *" be parsed as a fresh directive — with it stripped,
        // the leftover tokens stay inside frame-ancestors as harmless
        // unknown source expressions, and the original default-src 'self'
        // is the only one a browser sees.
        $this->assertStringNotContainsString('a.test;', $csp);
        $this->assertStringNotContainsString('; default-src *', $csp);
        $this->assertStringContainsString("default-src 'self';", $csp);
        $this->assertStringContainsString('https://a.test', $csp);
    }

    public function testAHostCarryingCspMetacharactersIsNeverSpliced(): void
    {
        // Review 2026-11-06 (S3): parse_url() keeps `;` and spaces in the
        // host, and img-src precedes script-src, so an injected
        // `script-src * 'unsafe-inline'` (first occurrence wins) switched the
        // nonce policy off for every innerHTML sink.
        $sub = $this->subscriberWithUrls([
            'radarr_url' => "http://x.lan;script-src * 'unsafe-inline';x/",
            'sonarr_url' => 'javascript://sonarr.lan/',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('x.lan', $csp);
        $this->assertStringNotContainsString("'unsafe-inline';x", $csp);
        $this->assertStringNotContainsString('javascript:', $csp);
        $this->assertStringNotContainsString('sonarr.lan', $csp);
    }

    public function testIpv6LiteralsAndDockerHostnamesStillLandInImgSrc(): void
    {
        $sub = $this->subscriberWithUrls([
            'radarr_url' => 'http://[::1]:7878/',
            'sonarr_url' => 'http://host.docker.internal:8989',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://[::1]:7878', $csp);
        $this->assertStringContainsString('http://host.docker.internal:8989', $csp);
    }
}
