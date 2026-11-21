<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\TrustedProxiesSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Review 2026-11-06 (S1): `TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR` means
 * "trust the immediate upstream peer". Symfony resolves the REMOTE_ADDR
 * placeholder once, in Kernel::preBoot(), so under FrankenPHP worker mode
 * every worker stayed pinned to whoever hit it first (usually the Docker
 * healthcheck on 127.0.0.1): a later cloudflared request was not trusted
 * (client ip = the tunnel, secure = false, login throttling collapsed onto
 * the tunnel's ip). This listener re-resolves the placeholder per request.
 */
#[AllowMockObjectsWithoutExpectations]
class TrustedProxiesSubscriberTest extends TestCase
{
    private const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PREFIX;

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], self::HEADERS);
    }

    private function event(string $peer, array $headers = [], int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create('/login', 'GET', [], [], [], ['REMOTE_ADDR' => $peer]);
        foreach ($headers as $k => $v) {
            $request->headers->set($k, $v);
        }
        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }

    public function testRunsBeforeTheRouter(): void
    {
        $events = TrustedProxiesSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertGreaterThan(32, $events[KernelEvents::REQUEST][1], 'must run before the RouterListener (32) and the firewall (8)');
    }

    public function testReResolvesTheRemoteAddrPlaceholderForEveryRequest(): void
    {
        // A worker that served the Docker healthcheck first is pinned to it.
        Request::setTrustedProxies(['127.0.0.1', '127.0.0.1'], self::HEADERS);
        $sub = new TrustedProxiesSubscriber('127.0.0.1,REMOTE_ADDR', self::HEADERS);

        $event = $this->event('172.18.0.5', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https']);
        $this->assertSame('172.18.0.5', $event->getRequest()->getClientIp(), 'precondition: the pinned worker does not trust cloudflared');

        $sub->onKernelRequest($event);

        $this->assertSame(['127.0.0.1', '172.18.0.5'], Request::getTrustedProxies());
        $this->assertSame('203.0.113.9', $event->getRequest()->getClientIp());
        $this->assertTrue($event->getRequest()->isSecure());

        // Next request on the same worker comes from a LAN client: re-resolved again.
        $next = $this->event('192.168.1.20');
        $sub->onKernelRequest($next);
        $this->assertSame(['127.0.0.1', '192.168.1.20'], Request::getTrustedProxies());
    }

    public function testAStaticProxyListIsLeftAlone(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], self::HEADERS);
        $sub = new TrustedProxiesSubscriber('10.0.0.0/8', self::HEADERS);

        $sub->onKernelRequest($this->event('172.18.0.5'));

        $this->assertSame(['10.0.0.0/8'], Request::getTrustedProxies(), 'no placeholder → nothing to re-resolve');
    }

    public function testPrivateSubnetsPlaceholderStillExpands(): void
    {
        $sub = new TrustedProxiesSubscriber('PRIVATE_SUBNETS,REMOTE_ADDR', self::HEADERS);

        $sub->onKernelRequest($this->event('198.51.100.7'));

        $proxies = Request::getTrustedProxies();
        $this->assertContains('198.51.100.7', $proxies);
        $this->assertContains('10.0.0.0/8', $proxies, 'Symfony expands PRIVATE_SUBNETS');
    }

    public function testSubRequestsAreIgnored(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], self::HEADERS);
        $sub = new TrustedProxiesSubscriber('127.0.0.1,REMOTE_ADDR', self::HEADERS);

        $sub->onKernelRequest($this->event('172.18.0.5', [], HttpKernelInterface::SUB_REQUEST));

        $this->assertSame(['127.0.0.1'], Request::getTrustedProxies());
    }

    public function testEmptyConfigurationIsANoOp(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], self::HEADERS);
        $sub = new TrustedProxiesSubscriber('', self::HEADERS);

        $sub->onKernelRequest($this->event('172.18.0.5'));

        $this->assertSame(['127.0.0.1'], Request::getTrustedProxies());
    }
}
