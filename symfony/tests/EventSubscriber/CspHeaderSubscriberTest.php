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
    private function subscriberWithUrls(array $urls): CspHeaderSubscriber
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

        return new CspHeaderSubscriber($config, $instances);
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
}
