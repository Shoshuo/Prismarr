<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SessionLockReleaseSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[AllowMockObjectsWithoutExpectations]
class SessionLockReleaseSubscriberTest extends TestCase
{
    private static function request(string $method, string $route, bool $withStartedSession): Request
    {
        $request = Request::create('/whatever', $method);
        $request->attributes->set('_route', $route);

        if ($withStartedSession) {
            $session = new Session(new MockArraySessionStorage());
            $session->start();
            $request->setSession($session);
        }

        return $request;
    }

    /**
     * @return iterable<string, array{Request, bool}>
     */
    public static function cases(): iterable
    {
        // The whole point: a GET fragment with a live session releases the lock.
        yield 'GET dashboard widget, session started' => [
            self::request('GET', 'app_dashboard_widget_health', true),
            true,
        ];
        yield 'GET films page, session started' => [
            self::request('GET', 'app_media_films', true),
            true,
        ];
        // POST keeps the session open (CSRF mutation, flash messages, login).
        yield 'POST keeps the lock' => [
            self::request('POST', 'admin_instances_create', true),
            false,
        ];
        // Long-running Bazarr POSTs that never write the session: a 45 s
        // provider download / ~86 s inline refresh must not hold the lock and
        // freeze every other tab of that admin.
        foreach (['app_bazarr_api_download_movie', 'app_bazarr_api_download_episode', 'app_bazarr_api_refresh'] as $route) {
            yield 'POST ' . $route . ' releases the lock' => [self::request('POST', $route, true), true];
        }
        yield 'other Bazarr POSTs keep the lock' => [
            self::request('POST', 'app_bazarr_api_auto_movie', true),
            false,
        ];
        // No session started yet → nothing to release (and we must not start one).
        yield 'GET without a started session' => [
            self::request('GET', 'app_dashboard', false),
            false,
        ];
        // The setup wizard writes `_locale` to the session on GET.
        yield 'GET setup wizard is excluded' => [
            self::request('GET', 'app_setup_language', true),
            false,
        ];
        // Symfony internals (_profiler, _wdt) are skipped.
        yield 'GET profiler is excluded' => [
            self::request('GET', '_wdt', true),
            false,
        ];
    }

    #[DataProvider('cases')]
    public function testShouldRelease(Request $request, bool $expected): void
    {
        $this->assertSame($expected, SessionLockReleaseSubscriber::shouldRelease($request));
    }

    public function testOnKernelControllerClosesTheSessionOnAGetFragment(): void
    {
        $request = self::request('GET', 'app_dashboard_widget_health', true);
        $session = $request->getSession();
        $this->assertTrue($session->isStarted());

        $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
        $event = new \Symfony\Component\HttpKernel\Event\ControllerEvent(
            $kernel,
            fn () => null,
            $request,
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
        );

        (new SessionLockReleaseSubscriber())->onKernelController($event);

        // save() releases the lock and marks the session closed/not-started.
        $this->assertFalse($session->isStarted());
    }
}
