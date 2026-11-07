<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Review 2026-11-06 (TQ-2): no request-level test existed for ANY write
 * route of the download-client and *arr controllers (≈ 200 POST routes),
 * so a renamed route (404) or a dropped method restriction stayed green.
 * For every POST route of those controllers: anonymous → /login,
 * same-origin JSON →
 * the route is wired (anything but 404/405).
 */
class WriteRouteTableTest extends AbstractWebTestCase
{
    private const CONTROLLERS = [
        'App\\Controller\\MediaController',
        'App\\Controller\\RadarrController',
        'App\\Controller\\SonarrController',
        'App\\Controller\\ProwlarrController',
        'App\\Controller\\JellyseerrController',
        'App\\Controller\\QBittorrentController',
        'App\\Controller\\DelugeController',
        'App\\Controller\\TransmissionController',
        'App\\Controller\\UsenetController',
    ];

    public function testEveryWriteRouteIsBehindTheFirewallTheCrossSiteGuardAndWired(): void
    {
        $paths = $this->writePaths();
        $this->assertGreaterThan(150, count($paths), 'the table must cover the integration controllers');

        $offenders = [];

        // 1. Anonymous → the firewall answers first.
        $this->asAnonymous();
        foreach ($paths as $name => $path) {
            $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
            $status   = $this->client->getResponse()->getStatusCode();
            $location = (string) $this->client->getResponse()->headers->get('Location');
            if ($status !== 302 || !preg_match('#/login(\?|$)#', $location)) {
                $offenders[] = "$name ($path): anonymous POST must redirect to login, got $status $location";
            }
        }

        // 2. Logged in, same-origin → the route exists and accepts POST.
        $this->client->loginUser($this->admin);
        foreach ($paths as $name => $path) {
            $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], '{}');
            $status = $this->client->getResponse()->getStatusCode();
            if (in_array($status, [404, 405], true)) {
                $offenders[] = "$name ($path) is not wired (HTTP $status)";
            }
        }

        $this->assertSame([], $offenders, "write-route violations:\n" . implode("\n", $offenders));
    }

    /** @return array<string, string> route name → sample path */
    private function writePaths(): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $out = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            $class = explode('::', $controller)[0];
            if (!in_array($class, self::CONTROLLERS, true)) {
                continue;
            }
            if (!in_array('POST', $route->getMethods(), true)) {
                continue;
            }
            $path = self::samplePath($route);
            if ($path !== null) {
                $out[$name] = $path;
            }
        }
        return $out;
    }

    private static function samplePath(Route $route): ?string
    {
        $path = $route->getPath();
        foreach ($route->compile()->getPathVariables() as $var) {
            $value = match (true) {
                $var === 'slug'    => (str_contains($path, 'series') || str_contains($path, 'sonarr')) ? 'sonarr-1' : 'radarr-1',
                $var === 'client'  => 'sabnzbd',
                $var === 'type'    => 'movie',
                $var === 'hash'    => 'abcdef0123456789abcdef0123456789abcdef01',
                $var === 'service' => 'radarr',
                (bool) preg_match('/id$|Id$|^page$|^season$|^episode$|^number$/i', $var) => '1',
                default            => 'x',
            };
            $requirement = $route->getRequirement($var);
            if ($requirement !== null && !preg_match('#^(?:' . $requirement . ')$#', $value)) {
                return null; // no sample satisfies the requirement — leave it to the unit tests
            }
            $path = str_replace('{' . $var . '}', $value, $path);
        }
        return str_contains($path, '{') ? null : $path;
    }
}
