<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
class SetupControllerTest extends TestCase
{
    private function newController(
        UserRepository $users,
        EntityManagerInterface $em,
    ): SetupController {
        $controller = new SetupController(
            $users,
            $this->createMock(SettingRepository::class),
            $this->createMock(ConfigService::class),
            $em,
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
