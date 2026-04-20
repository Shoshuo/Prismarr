<?php

namespace App\Tests\EventSubscriber;

use App\Controller\SetupController;
use App\EventSubscriber\SetupRedirectSubscriber;
use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SetupRedirectSubscriberTest extends TestCase
{
    private function event(string $path, bool $mainRequest = true): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);
        return new RequestEvent(
            $kernel,
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST
        );
    }

    private function subscriber(bool $setupDone): SetupRedirectSubscriber
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY && $setupDone ? '1' : null
        );

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/setup');

        return new SetupRedirectSubscriber($settings, $urls);
    }

    public function testSubRequestIsIgnored(): void
    {
        $event = $this->event('/medias/films', mainRequest: false);
        ($this->subscriber(false))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testAppRouteRedirectsToSetupWhenSetupNotDone(): void
    {
        $event = $this->event('/medias/films');
        ($this->subscriber(false))->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/setup', $response->getTargetUrl());
    }

    public function testAppRouteLetsThroughWhenSetupDone(): void
    {
        $event = $this->event('/medias/films');
        ($this->subscriber(true))->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testSetupPathWhitelisted(): void
    {
        $event = $this->event('/setup/admin');
        ($this->subscriber(false))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testLoginPathWhitelisted(): void
    {
        $event = $this->event('/login');
        ($this->subscriber(false))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testApiHealthPathWhitelisted(): void
    {
        $event = $this->event('/api/health');
        ($this->subscriber(false))->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testAssetsWhitelisted(): void
    {
        foreach (['/assets/app.js', '/static/tabler.css', '/img/logo.png', '/favicon.ico'] as $path) {
            $event = $this->event($path);
            ($this->subscriber(false))->onKernelRequest($event);
            $this->assertFalse($event->hasResponse(), "Expected {$path} to be whitelisted");
        }
    }

    public function testSetupDoneIsCachedPerInstance(): void
    {
        // Simulate 2 consecutive requests on the same worker. SettingRepository
        // should be queried once (the second call uses the memoized result).
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())->method('get')->willReturn('1');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $sub = new SetupRedirectSubscriber($settings, $urls);

        $sub->onKernelRequest($this->event('/medias/films'));
        $sub->onKernelRequest($this->event('/medias/series'));
    }

    public function testResetClearsCache(): void
    {
        // After reset(), the next request re-reads the DB.
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->exactly(2))->method('get')->willReturn('1');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $sub = new SetupRedirectSubscriber($settings, $urls);

        $sub->onKernelRequest($this->event('/medias/films'));
        $sub->reset();
        $sub->onKernelRequest($this->event('/medias/films'));
    }

    public function testSchemaErrorLetsRequestThrough(): void
    {
        // When DB schema isn't applied yet, SettingRepository throws. The
        // subscriber must let the request through (init.sh needs access to
        // create the tables) rather than redirect to /setup forever.
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willThrowException(new \RuntimeException('no such table'));

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $sub = new SetupRedirectSubscriber($settings, $urls);

        $event = $this->event('/medias/films');
        $sub->onKernelRequest($event);
        $this->assertFalse($event->hasResponse());
    }
}
