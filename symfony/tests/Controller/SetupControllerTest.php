<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\EventSubscriber\LocaleSubscriber;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Unit tests on the first-run wizard: welcome (language) → admin account →
 * hand-over to the Services section of /admin/settings. The wizard never
 * touches service configuration.
 */
#[AllowMockObjectsWithoutExpectations]
class SetupControllerTest extends TestCase
{
    private const SERVICES_URL = '/_route/admin_settings_index#section-services';

    /** @var array<string, string> settings written through SettingRepository::set() */
    private array $written = [];

    /** @var array<string, mixed> context of the last rendered template */
    private array $rendered = [];

    private function newController(
        UserRepository $users,
        EntityManagerInterface $em,
        ?SettingRepository $settings = null,
        ?Request $request = null,
        bool $csrfValid = true,
    ): SetupController {
        if ($settings === null) {
            $settings = $this->createMock(SettingRepository::class);
            $settings->method('set')->willReturnCallback(function (string $k, ?string $v) {
                $this->written[$k] = (string) $v;
            });
        }

        $controller = new SetupController(
            $users,
            $settings,
            $this->createMock(ConfigService::class),
            $em,
            $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );

        // AbstractController needs a container for the helpers the wizard uses
        // (router, CSRF, Twig, flash bag via the request stack).
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn(string $name) => '/_route/' . $name);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturnCallback(function (string $tpl, array $ctx = []) {
            $this->rendered = $ctx;
            return '<html>rendered</html>';
        });

        $stack = new RequestStack();
        $stack->push($request ?? $this->getRequest());

        $services = [
            'router'                      => $router,
            'security.csrf.token_manager' => $csrf,
            'twig'                        => $twig,
            'request_stack'               => $stack,
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn(string $id) => isset($services[$id]));
        $container->method('get')->willReturnCallback(fn(string $id) => $services[$id] ?? null);

        $controller->setContainer($container);
        return $controller;
    }

    private function getRequest(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    private function postRequest(array $data): Request
    {
        $request = new Request([], array_merge(['_csrf_token' => 'dummy'], $data), [], [], [], ['REQUEST_METHOD' => 'POST']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    private function validAdminPost(): Request
    {
        return $this->postRequest([
            'email'            => 'joshua@example.com',
            'display_name'     => 'Joshua',
            'password'         => 'secret-enough',
            'password_confirm' => 'secret-enough',
        ]);
    }

    private function usersCount(int $n): UserRepository
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('count')->willReturn($n);
        return $users;
    }

    private function settingsWithSetupDone(): SettingRepository
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(fn(string $k) => $k === SetupController::SETUP_DONE_KEY ? '1' : null);
        return $settings;
    }

    // ─── Admin step ────────────────────────────────────────────────────────

    public function testAdminSuccessCompletesSetupAndOpensServices(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $request = $this->validAdminPost();
        $request->getSession()->set(LocaleSubscriber::SESSION_KEY, 'fr');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');
        $security = $this->createMock(Security::class);
        $security->expects($this->once())->method('login');

        $response = $this->newController($this->usersCount(0), $em, request: $request)
            ->admin($request, $hasher, $security);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::SERVICES_URL, $response->getTargetUrl());
        $this->assertSame('1', $this->written[SetupController::SETUP_DONE_KEY] ?? null);
        // The wizard language becomes the permanent pref and leaves the session.
        $this->assertSame('fr', $this->written['display_language'] ?? null);
        $this->assertFalse($request->getSession()->has(LocaleSubscriber::SESSION_KEY));
        $this->assertNotEmpty($request->getSession()->getFlashBag()->peek('success'));
    }

    public function testAdminRedirectsWhenUserAlreadyExists(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $response = $this->newController($this->usersCount(1), $em)->admin(
            $this->getRequest(),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(Security::class),
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/_route/app_setup_root', $response->getTargetUrl());
    }

    public function testAdminRaceRedirectsToLoginOnUniqueConstraint(): void
    {
        // By the time flush() runs, another request already committed the admin.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('flush')
            ->willThrowException($this->createMock(UniqueConstraintViolationException::class));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $request = $this->validAdminPost();
        $response = $this->newController($this->usersCount(0), $em, request: $request)
            ->admin($request, $hasher, $this->createMock(Security::class));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/_route/app_login', $response->getTargetUrl());
        $this->assertArrayNotHasKey(SetupController::SETUP_DONE_KEY, $this->written);
    }

    public function testAdminRejectsInvalidInputWithoutCreatingAnything(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $request = $this->postRequest([
            'email'            => 'not-an-email',
            'password'         => 'short',
            'password_confirm' => 'different',
        ]);
        $response = $this->newController($this->usersCount(0), $em, request: $request)
            ->admin($request, $this->createMock(UserPasswordHasherInterface::class), $this->createMock(Security::class));

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->written);
    }

    public function testAdminRejectsInvalidCsrf(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $request = $this->validAdminPost();
        $response = $this->newController($this->usersCount(0), $em, request: $request, csrfValid: false)
            ->admin($request, $this->createMock(UserPasswordHasherInterface::class), $this->createMock(Security::class));

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertSame([], $this->written);
    }

    // ─── Root / welcome ────────────────────────────────────────────────────

    public function testRootSendsAFreshInstallToWelcome(): void
    {
        $response = $this->newController($this->usersCount(0), $this->createMock(EntityManagerInterface::class))
            ->root($this->getRequest());

        $this->assertSame('/_route/app_setup_welcome', $response->getTargetUrl());
        $this->assertSame([], $this->written);
    }

    /** An install left mid-way by the old multi-step wizard is closed, not stuck. */
    public function testRootClosesAHalfDoneInstallAndOpensServices(): void
    {
        $response = $this->newController($this->usersCount(1), $this->createMock(EntityManagerInterface::class))
            ->root($this->getRequest());

        $this->assertSame(self::SERVICES_URL, $response->getTargetUrl());
        $this->assertSame('1', $this->written[SetupController::SETUP_DONE_KEY] ?? null);
    }

    public function testRootAndWelcomeAreClosedOnceSetupIsDone(): void
    {
        $settings = $this->settingsWithSetupDone();
        $controller = $this->newController($this->usersCount(1), $this->createMock(EntityManagerInterface::class), $settings);

        $this->assertSame('/_route/app_home', $controller->root($this->getRequest())->getTargetUrl());
        $this->assertSame('/_route/app_home', $controller->welcome($this->getRequest())->getTargetUrl());
    }

    public function testAdminStepShowsWelcomeAsDone(): void
    {
        $this->newController($this->usersCount(0), $this->createMock(EntityManagerInterface::class))->admin(
            $this->getRequest(),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(Security::class),
        );

        $this->assertSame('admin', $this->rendered['active_step'] ?? null);
        $this->assertSame(['welcome'], $this->rendered['completed_steps'] ?? null);
    }

    /** Closing a half-done install must not adopt a (possibly anonymous) visitor's language. */
    public function testRootDoesNotPromoteTheVisitorLocaleWhenClosingAHalfDoneInstall(): void
    {
        $request = $this->getRequest();
        $request->getSession()->set(LocaleSubscriber::SESSION_KEY, 'fr');

        $this->newController($this->usersCount(1), $this->createMock(EntityManagerInterface::class), request: $request)
            ->root($request);

        $this->assertSame('1', $this->written[SetupController::SETUP_DONE_KEY] ?? null);
        $this->assertArrayNotHasKey('display_language', $this->written);
    }

    /** After setup, /setup/locale must not let a session override the saved language. */
    public function testLocaleIsClosedOnceSetupIsDone(): void
    {
        $request = $this->postRequest(['locale' => 'fr']);
        $response = $this->newController($this->usersCount(1), $this->createMock(EntityManagerInterface::class), $this->settingsWithSetupDone(), $request)
            ->locale($request);

        $this->assertSame('/_route/app_home', $response->getTargetUrl());
        $this->assertFalse($request->getSession()->has(LocaleSubscriber::SESSION_KEY));
    }
}
