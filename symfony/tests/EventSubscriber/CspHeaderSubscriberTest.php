<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CspHeaderSubscriber;
use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CspHeaderSubscriberTest extends TestCase
{
    private function event(Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function configWithUrls(array $urls): ConfigService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $key) => $urls[$key] ?? null);
        return $config;
    }

    public function testSetsHeaderOnMainRequest(): void
    {
        $sub = new CspHeaderSubscriber($this->configWithUrls([]));
        $response = new Response('<html></html>');
        $sub->onResponse($this->event($response));

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
    }

    public function testStaticHostsAlwaysIncluded(): void
    {
        $sub = new CspHeaderSubscriber($this->configWithUrls([]));
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://image.tmdb.org', $csp);
        $this->assertStringContainsString('https://ui-avatars.com', $csp);
        $this->assertStringContainsString('https://artworks.thetvdb.com', $csp);
    }

    public function testConfiguredRadarrUrlIsAddedToImgSrc(): void
    {
        $sub = new CspHeaderSubscriber($this->configWithUrls([
            'radarr_url' => 'http://192.168.10.220:7878',
        ]));
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://192.168.10.220:7878', $csp);
    }

    public function testUrlWithPathIsReducedToOrigin(): void
    {
        // Input has a path (/api/v1), CSP must only contain scheme://host:port.
        $sub = new CspHeaderSubscriber($this->configWithUrls([
            'sonarr_url' => 'http://localhost:8989/api/v3',
        ]));
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://localhost:8989', $csp);
        $this->assertStringNotContainsString('/api/v3', $csp);
    }

    public function testInvalidUrlIsIgnored(): void
    {
        $sub = new CspHeaderSubscriber($this->configWithUrls([
            'radarr_url' => 'not a url',
        ]));
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The static hosts are still present but the bad url is not propagated.
        $this->assertStringNotContainsString('not a url', $csp);
    }

    public function testExistingHeaderIsPreserved(): void
    {
        $sub = new CspHeaderSubscriber($this->configWithUrls([]));
        $response = new Response();
        $response->headers->set('Content-Security-Policy', 'default-src none');
        $sub->onResponse($this->event($response));

        $this->assertSame('default-src none', $response->headers->get('Content-Security-Policy'));
    }

    public function testStrictDirectivesArePresent(): void
    {
        $sub = new CspHeaderSubscriber($this->configWithUrls([]));
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }
}
