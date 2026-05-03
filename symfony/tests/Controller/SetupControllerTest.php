<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Focused unit tests on SetupController::admin() — primarily the race-condition
 * fix introduced in Session 8b. A full functional test with WebTestCase is
 * tracked in the v1.1 backlog.
 */
#[AllowMockObjectsWithoutExpectations]
class SetupControllerTest extends TestCase
{
    private function newController(
        UserRepository $users,
        EntityManagerInterface $em,
        ?SettingRepository $settings = null,
        ?ConfigService $config = null,
        ?ServiceInstanceProvider $instances = null,
    ): SetupController {
        $controller = new SetupController(
            $users,
            $settings ?? $this->createMock(SettingRepository::class),
            $config ?? $this->createMock(ConfigService::class),
            $instances ?? $this->createMock(ServiceInstanceProvider::class),
            $em,
            $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );

        // AbstractController needs a container to resolve helpers used in admin()
        // (CSRF manager, router, Twig, security). We only wire what admin() uses.
        $container = $this->createMock(ContainerInterface::class);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            fn(string $name) => '/_route/' . $name
        );

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>rendered</html>');

        $security = $this->createMock(Security::class);
        // Swallow the login() call silently.
        $security->method('login')->willReturn(null);

        $container->method('has')->willReturnCallback(fn(string $id) => in_array($id, [
            'router', 'security.csrf.token_manager', 'twig', 'security.helper',
        ], true));
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'router'                       => $router,
            'security.csrf.token_manager'  => $csrfManager,
            'twig'                         => $twig,
            'security.helper'              => $security,
            default                        => null,
        });

        $controller->setContainer($container);
        return $controller;
    }

    private function postRequest(array $data): Request
    {
        $request = new Request([], array_merge([
            '_csrf_token' => 'dummy',
        ], $data), [], [], [], ['REQUEST_METHOD' => 'POST']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    public function testAdminRedirectsWhenUserAlreadyExists(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('count')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $controller = $this->newController($users, $em);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $security = $this->createMock(Security::class);

        $response = $controller->admin(new Request(), $hasher, $security);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_setup_tmdb', $response->getTargetUrl());
    }

    public function testAdminRaceRedirectsToLoginOnUniqueConstraint(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('count')->willReturn(0);

        // Simulate the race: by the time flush() runs, another request has
        // already committed the admin → UniqueConstraintViolationException.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('flush')
            ->willThrowException($this->createMock(UniqueConstraintViolationException::class));

        $controller = $this->newController($users, $em);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');
        $security = $this->createMock(Security::class);

        $request = $this->postRequest([
            'email'            => 'joshua@example.com',
            'display_name'     => 'Joshua',
            'password'         => 'secret-enough',
            'password_confirm' => 'secret-enough',
        ]);

        $response = $controller->admin($request, $hasher, $security);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_login', $response->getTargetUrl());
    }

    public function testTmdbRedirectsToHomeWhenSetupCompleted(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null
        );

        $config = $this->createMock(ConfigService::class);
        // If guardSetupNotCompleted() does its job, prefill() must NEVER run,
        // i.e. ConfigService::get() must not be called for sensitive keys.
        $config->expects($this->never())->method('get');

        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings, $config);

        $response = $controller->tmdb(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_home', $response->getTargetUrl());
    }

    public function testManagersRedirectsToHomeWhenSetupCompleted(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null
        );

        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings);
        $response = $controller->managers(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_home', $response->getTargetUrl());
    }

    public function testIndexersRedirectsToHomeWhenSetupCompleted(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null
        );

        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings);
        $response = $controller->indexers(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_home', $response->getTargetUrl());
    }

    public function testDownloadsRedirectsToHomeWhenSetupCompleted(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null
        );

        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings);
        $response = $controller->downloads(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_home', $response->getTargetUrl());
    }

    public function testFinishRedirectsToHomeWhenSetupCompleted(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null
        );

        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings);
        $response = $controller->finish(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_home', $response->getTargetUrl());
    }

    public function testTmdbDoesNotPrefillSensitiveKeysDuringSetup(): void
    {
        // Setup NOT completed — so the guard lets us through.
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn(null); // no setup_completed flag

        // The DB has a stored tmdb_api_key value, but prefill() must SKIP it
        // because the key ends in "_api_key" (defense-in-depth: even if the
        // page renders, the secret is never injected into the HTML <input>).
        $config = $this->createMock(ConfigService::class);
        $config->expects($this->never())->method('get')->with('tmdb_api_key');

        $users = $this->createMock(UserRepository::class);
        $users->method('count')->willReturn(1); // admin exists, no guardAdminExists redirect

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings, $config);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $response = $controller->tmdb($request);

        // Page rendered (Twig mock returns HTML) — important: ConfigService::get()
        // was never called for the sensitive key.
        $this->assertInstanceOf(Response::class, $response);
    }

    // ─── /setup/test/{service} — connection-test endpoint (v1.0.6) ───

    /**
     * Build a real RateLimiterFactory with an in-memory storage. Symfony's
     * factory is `final` so we can't mock it — but a generous limit (999/min)
     * achieves the same goal of "let everything through during the happy
     * path tests" and an `n=1` factory in the rate-limit test reproduces the
     * exhaustion case after one consume.
     */
    private function makeLimiter(int $limit = 999): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'setup_test', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    private function passingLimiter(): RateLimiterFactory
    {
        return $this->makeLimiter(999);
    }

    public function testTestServiceReturns403WhenSetupCompleted(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(
            fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null
        );

        $health = $this->createMock(HealthService::class);
        // Critical: HealthService MUST NOT be probed once setup is done.
        // This is what stops a post-setup attacker from using the wizard
        // as a SSRF jumpbox into the operator's LAN.
        $health->expects($this->never())->method('diagnose');

        // The guard runs first so the limiter is never reached. Pass any
        // factory — the test asserts diagnose() is never called and the
        // response is 403, both of which prove we short-circuited early.
        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = $this->newController($users, $em, $settings);

        $response = $controller->testService('radarr', new Request(), $health, $this->passingLimiter());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(['ok' => false, 'category' => 'forbidden'], $payload);
    }

    public function testTestServiceReturns400OnInvalidCsrf(): void
    {
        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn(null); // setup not completed

        // Override the CSRF manager mock to reject this specific token id.
        $controller = $this->newController($users, $em, $settings);
        $container = $this->createMock(ContainerInterface::class);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);
        $container->method('has')->willReturnCallback(fn(string $id) => $id === 'security.csrf.token_manager');
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'security.csrf.token_manager' => $csrf,
            'router' => $router,
            default => null,
        });
        $controller->setContainer($container);

        $health = $this->createMock(HealthService::class);
        $health->expects($this->never())->method('diagnose');

        $response = $controller->testService('radarr', new Request(), $health, $this->passingLimiter());

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['ok']);
        $this->assertSame('csrf', $payload['category']);
    }

    public function testTestServiceHappyPathReturnsMinimalPayload(): void
    {
        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn(null);

        $health = $this->createMock(HealthService::class);
        $health->expects($this->once())
            ->method('diagnose')
            ->with('radarr', $this->callback(function (array $overrides): bool {
                // Only the whitelisted radarr fields are forwarded.
                return array_keys($overrides) === ['radarr_url', 'radarr_api_key'];
            }))
            ->willReturn(['ok' => true, 'category' => 'ok', 'http' => 200]);

        $controller = $this->newController($users, $em, $settings);
        $request = $this->postRequest([
            'radarr_url' => 'http://radarr.lan:7878',
            'radarr_api_key' => 'fake-key',
            // An attacker trying to inject a sonarr field should be ignored.
            'sonarr_api_key' => 'should-not-be-forwarded',
        ]);

        $response = $controller->testService('radarr', $request, $health, $this->passingLimiter());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        // Strict envelope: only ok + category, never the URL or key.
        $this->assertSame(['ok' => true, 'category' => 'ok'], $payload);
        // Cache headers: response must never be cached by an upstream proxy.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testTestServiceReturns429WhenRateLimited(): void
    {
        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn(null);

        $health = $this->createMock(HealthService::class);
        $health->method('diagnose')->willReturn(['ok' => true, 'category' => 'ok', 'http' => 200]);

        // Limit = 1, so the first call consumes the bucket and the second
        // call (same client IP × service) is rate-limited.
        $factory = $this->makeLimiter(1);
        $controller = $this->newController($users, $em, $settings);

        $first = $controller->testService('radarr', $this->postRequest([]), $health, $factory);
        $this->assertSame(200, $first->getStatusCode());

        $second = $controller->testService('radarr', $this->postRequest([]), $health, $factory);
        $this->assertSame(429, $second->getStatusCode());
        $payload = json_decode((string) $second->getContent(), true);
        $this->assertSame('rate_limited', $payload['category']);
    }

    public function testAdminSuccessRedirectsToNextStep(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('count')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $controller = $this->newController($users, $em);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');
        $security = $this->createMock(Security::class);

        $request = $this->postRequest([
            'email'            => 'joshua@example.com',
            'display_name'     => 'Joshua',
            'password'         => 'secret-enough',
            'password_confirm' => 'secret-enough',
        ]);

        $response = $controller->admin($request, $hasher, $security);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('app_setup_tmdb', $response->getTargetUrl());
    }
}
