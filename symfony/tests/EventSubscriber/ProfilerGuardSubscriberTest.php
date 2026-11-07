<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ProfilerGuardSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class ProfilerGuardSubscriberTest extends TestCase
{
    private function event(string $path, string $clientIp): RequestEvent
    {
        $request = Request::create($path);
        $request->server->set('REMOTE_ADDR', $clientIp);
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function subscriber(string $env = 'dev'): ProfilerGuardSubscriber
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Access denied message');
        return new ProfilerGuardSubscriber($env, $translator);
    }

    public function testNoOpInProdEnvEvenForRemoteProfilerAccess(): void
    {
        $event = $this->event('/_profiler/abc', '8.8.8.8');

        ($this->subscriber('prod'))->onKernelRequest($event);
        // Never throws in prod: the route doesn't exist anyway (no profiler bundle loaded).
        $this->assertFalse($event->hasResponse());
    }

    public function testLocalhostCanAccessProfilerInDev(): void
    {
        $event = $this->event('/_profiler/abc', '127.0.0.1');
        ($this->subscriber('dev'))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testPrivateLanIpCanAccessProfiler(): void
    {
        $event = $this->event('/_profiler/abc', '192.168.1.5');
        ($this->subscriber('dev'))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testDockerBridgeIpCanAccessProfiler(): void
    {
        $event = $this->event('/_profiler/abc', '172.17.0.1');
        ($this->subscriber('dev'))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testIpv6LoopbackCanAccessProfiler(): void
    {
        $event = $this->event('/_profiler/abc', '::1');
        ($this->subscriber('dev'))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testPublicIpCannotAccessProfilerInDev(): void
    {
        $event = $this->event('/_profiler/abc', '8.8.8.8');

        $this->expectException(AccessDeniedHttpException::class);
        ($this->subscriber('dev'))->onKernelRequest($event);
    }

    public function testPublicIpCannotAccessWdt(): void
    {
        $event = $this->event('/_wdt/xyz', '1.2.3.4');

        $this->expectException(AccessDeniedHttpException::class);
        ($this->subscriber('dev'))->onKernelRequest($event);
    }

    public function testRegularRouteIsLetThroughFromAnyIp(): void
    {
        $event = $this->event('/medias/films', '1.2.3.4');
        ($this->subscriber('dev'))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testSubRequestIsIgnored(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/_profiler/abc');
        $request->server->set('REMOTE_ADDR', '8.8.8.8');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        ($this->subscriber('dev'))->onKernelRequest($event);
        // No AccessDeniedHttpException thrown even though IP is public.
        $this->assertFalse($event->hasResponse());
    }

    public function testASpoofedForwardedHeaderCannotUnlockTheProfiler(): void
    {
        // Review 2026-11-06 (S1): with the REMOTE_ADDR placeholder every
        // direct peer is a trusted proxy, so a public client could forward a
        // private ip and pass. The raw REMOTE_ADDR must be private too.
        Request::setTrustedProxies(['203.0.113.9'], Request::HEADER_X_FORWARDED_FOR);
        try {
            $event = $this->event('/_profiler/abc', '203.0.113.9');
            $event->getRequest()->headers->set('X-Forwarded-For', '192.168.1.5');
            $this->assertSame('192.168.1.5', $event->getRequest()->getClientIp(), 'precondition: the forwarded ip is believed');

            $this->expectException(AccessDeniedHttpException::class);
            ($this->subscriber('dev'))->onKernelRequest($event);
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }
}
