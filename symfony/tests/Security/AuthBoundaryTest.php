<?php

namespace App\Tests\Security;

use App\Tests\AbstractWebTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Review 2026-11-06 (TQ-2/TQ-4): the authentication boundary was never
 * exercised — every web test used loginUser(), there was no anonymous
 * sweep and no form-login test, so reordering access_control, or a
 * subscriber answering before the firewall (S5), passed the suite.
 */
class AuthBoundaryTest extends AbstractWebTestCase
{
    /** Routes that are public by design. */
    private const PUBLIC_ROUTES = ['app_login', 'api_health'];

    public function testEveryApplicationRouteIsClosedToAnonymousVisitors(): void
    {
        $this->asAnonymous();
        /** @var RouterInterface $router */
        $router  = static::getContainer()->get('router');
        $checked = 0;

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, 'App\\Controller\\')) {
                continue;
            }
            if (in_array($name, self::PUBLIC_ROUTES, true) || str_starts_with($name, 'app_setup') || str_starts_with($route->getPath(), '/_')) {
                continue;
            }
            if (preg_match('#^/api/health/?$#', $route->getPath())) {
                continue;
            }
            $methods = $route->getMethods();
            if ($methods !== [] && !in_array('GET', $methods, true)) {
                continue;
            }
            $path = self::samplePath($route);
            if ($path === null) {
                continue;
            }

            $this->client->request('GET', $path);
            $status = $this->client->getResponse()->getStatusCode();
            $checked++;

            if ($status === 200) {
                $offenders[] = "$name ($path) answered 200 to an anonymous visitor";
            } elseif ($status >= 500) {
                $offenders[] = "$name ($path) crashed ($status) for an anonymous visitor";
            } elseif ($status === 302) {
                $location = (string) $this->client->getResponse()->headers->get('Location');
                if (!preg_match('#/login(\?|$)#', $location)) {
                    $offenders[] = "$name ($path) sent an anonymous visitor to $location — the firewall must answer before any service guard";
                }
            }
        }

        $this->assertGreaterThan(80, $checked, 'the sweep must actually cover the app');
        $this->assertSame([], $offenders ?? [], "anonymous access boundary violations:\n" . implode("\n", $offenders ?? []));
    }

    public function testLoginWithTheRightPasswordLandsOnHome(): void
    {
        $this->asAnonymous();
        $this->loginAttempt('admin@test.local', 'admin-password');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertMatchesRegularExpression('#^(https?://[^/]+)?/$#', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', '/tableau-de-bord');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'the session is authenticated after the form login');
    }

    public function testLoginWithAWrongPasswordStaysAnonymous(): void
    {
        $this->asAnonymous();
        $this->loginAttempt('admin@test.local', 'not-the-password');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertMatchesRegularExpression('#/login(\?|$)#', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', '/tableau-de-bord');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->client->request('GET', '/tableau-de-bord');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'precondition: logged in by the harness');

        $this->client->request('GET', '/logout');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/tableau-de-bord');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertMatchesRegularExpression('#/login(\?|$)#', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testFiveFailedAttemptsLockTheFormEvenForTheRightPassword(): void
    {
        $this->asAnonymous();
        for ($i = 0; $i < 5; $i++) {
            $this->loginAttempt('admin@test.local', 'wrong-' . $i);
        }

        $this->loginAttempt('admin@test.local', 'admin-password');

        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertMatchesRegularExpression('#/login(\?|$)#', (string) $this->client->getResponse()->headers->get('Location'), 'login_throttling (5 per 15 min) must refuse the sixth attempt');
        $this->client->request('GET', '/tableau-de-bord');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    private function loginAttempt(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'the login page renders anonymously');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->assertNotEmpty($token);

        $this->client->request('POST', '/login', [
            '_username'   => $email,
            '_password'   => $password,
            '_csrf_token' => $token,
        ]);
    }

    private static function samplePath(Route $route): ?string
    {
        $path = $route->getPath();
        foreach ($route->compile()->getPathVariables() as $var) {
            $value       = self::sample($var, $path);
            $requirement = $route->getRequirement($var);
            if ($requirement !== null && !preg_match('#^(?:' . $requirement . ')$#', $value)) {
                return null; // no sample satisfies the requirement — a router 404 proves nothing
            }
            $path = str_replace('{' . $var . '}', $value, $path);
        }
        return str_contains($path, '{') ? null : $path;
    }

    private static function sample(string $var, string $path): string
    {
        return match (true) {
            $var === 'slug'   => (str_contains($path, 'series') || str_contains($path, 'sonarr')) ? 'sonarr-1' : 'radarr-1',
            $var === 'client' => 'sabnzbd',
            $var === 'type'   => 'movie',
            $var === 'hash'   => 'abcdef0123456789abcdef0123456789abcdef01',
            $var === 'service' => 'radarr',
            (bool) preg_match('/id$|Id$|^page$|^season$|^episode$|^number$/i', $var) => '1',
            default           => 'x',
        };
    }
}
