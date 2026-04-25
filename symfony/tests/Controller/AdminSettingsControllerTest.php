<?php

namespace App\Tests\Controller;

use App\Controller\AdminSettingsController;
use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminSettingsControllerTest extends TestCase
{
    private function controller(
        SettingRepository $settings,
        ConfigService $config,
        HealthService $health,
    ): AdminSettingsController {
        $controller = new AdminSettingsController(
            $settings,
            $config,
            $health,
            $this->createMock(LoggerInterface::class),
            $this->createMock(\Symfony\Component\Cache\Adapter\AdapterInterface::class),
            projectDir: sys_get_temp_dir(),
            environment: 'test',
        );

        $container = $this->createMock(ContainerInterface::class);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>settings</html>');

        // CSRF manager always validates in these unit tests — we test
        // the validity flow elsewhere.
        $csrf = $this->createMock(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $requestStack = new \Symfony\Component\HttpFoundation\RequestStack();
        $sessionRequest = Request::create('/');
        $sessionRequest->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));
        $requestStack->push($sessionRequest);

        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            fn(string $name) => '/_route/' . $name
        );

        $container->method('has')->willReturnCallback(
            fn(string $id) => in_array($id, ['twig', 'security.csrf.token_manager', 'request_stack', 'router'], true)
        );
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'twig'                        => $twig,
            'security.csrf.token_manager' => $csrf,
            'request_stack'               => $requestStack,
            'router'                      => $router,
            default                       => null,
        });

        $controller->setContainer($container);
        return $controller;
    }

    public function testGetRendersTemplateWithValuesFromConfig(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => match ($k) {
            'radarr_url'     => 'http://example:7878',
            'radarr_api_key' => 'secret',
            default          => null,
        });
        $health = $this->createMock(HealthService::class);

        // GET should not persist or invalidate anything
        $settings->expects($this->never())->method('setMany');
        $config->expects($this->never())->method('invalidate');

        $response = $this->controller($settings, $config, $health)->index(Request::create('/admin/settings'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPostWithValidCsrfSavesAndInvalidatesCaches(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())
            ->method('setMany')
            ->with($this->callback(function (array $payload) {
                return $payload['radarr_url']     === 'http://new-radarr:7878'
                    && $payload['radarr_api_key'] === 'new-key'
                    // Empty submission of a password/api_key field must be
                    // skipped entirely (no key in payload) to preserve the
                    // existing value in DB — see testEmptyPasswordFieldsAreNotWiped.
                    && !array_key_exists('tmdb_api_key', $payload);
            }));

        $config = $this->createMock(ConfigService::class);
        $config->expects($this->once())->method('invalidate');

        $health = $this->createMock(HealthService::class);
        $health->expects($this->once())->method('invalidate')->with(null);

        $request = Request::create(
            '/admin/settings',
            'POST',
            [
                '_csrf_token'    => 'valid',
                'radarr_url'     => 'http://new-radarr:7878',
                'radarr_api_key' => 'new-key',
                'tmdb_api_key'   => '',
            ]
        );
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->controller($settings, $config, $health)->index($request);
    }

    public function testEmptyPasswordFieldsAreNotWiped(): void
    {
        // Regression: a save triggered from an unrelated section (e.g. user
        // clicks "Save" after changing the theme color) used to wipe every
        // api_key/password in DB because Firefox/Chrome strip pre-filled
        // values from type="password" inputs with autocomplete="off". Any
        // sensitive field arriving empty must be skipped, never persisted
        // as null.
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())
            ->method('setMany')
            ->with($this->callback(function (array $payload) {
                // Plain-text URL fields still allowed to be cleared via empty submit.
                if (!array_key_exists('radarr_url', $payload) || $payload['radarr_url'] !== null) {
                    return false;
                }
                // Every sensitive field must be ABSENT from the payload, not null.
                $sensitive = [
                    'tmdb_api_key', 'radarr_api_key', 'sonarr_api_key',
                    'prowlarr_api_key', 'jellyseerr_api_key',
                    'qbittorrent_password', 'gluetun_api_key',
                ];
                foreach ($sensitive as $k) {
                    if (array_key_exists($k, $payload)) {
                        return false;
                    }
                }
                return true;
            }));

        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $request = Request::create(
            '/admin/settings',
            'POST',
            [
                '_csrf_token'           => 'valid',
                'radarr_url'            => '',  // plain text → cleared (null)
                'tmdb_api_key'          => '',  // sensitive → preserved (skipped)
                'radarr_api_key'        => '',
                'sonarr_api_key'        => '',
                'prowlarr_api_key'      => '',
                'jellyseerr_api_key'    => '',
                'qbittorrent_password'  => '',
                'gluetun_api_key'       => '',
            ]
        );
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->controller($settings, $config, $health)->index($request);
    }

    public function testPostPersistsSidebarHideFlagForUncheckedServices(): void
    {
        // Checkbox unchecked = not sent by the browser → hide flag = '1'.
        // Checkbox checked = sent with value "1" → hide flag = null.
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())
            ->method('setMany')
            ->with($this->callback(function (array $payload) {
                return $payload['sidebar_hide_radarr'] === '1'       // unchecked
                    && $payload['sidebar_hide_sonarr'] === null      // checked
                    && $payload['sidebar_hide_tmdb']   === '1';      // unchecked
            }));

        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $request = Request::create(
            '/admin/settings',
            'POST',
            [
                '_csrf_token'             => 'valid',
                'sidebar_visible_sonarr'  => '1', // only sonarr's toggle is on
                // radarr, tmdb, etc. absent on purpose (checkbox unchecked)
            ]
        );
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->controller($settings, $config, $health)->index($request);
    }

    public function testTestEndpointReturnsOkJson(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);
        $health->expects($this->once())->method('invalidate')->with('radarr');
        $health->method('isHealthy')->with('radarr')->willReturn(true);

        $response = $this->controller($settings, $config, $health)->test('radarr');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"ok":true', $response->getContent());
        $this->assertStringContainsString('"service":"Radarr"', $response->getContent());
    }

    public function testTestEndpointReturnsFailureJsonWhenUnhealthy(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);
        $health->method('isHealthy')->willReturn(false);

        $response = $this->controller($settings, $config, $health)->test('sonarr');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"ok":false', $response->getContent());
    }

    public function testTestEndpointRejectsUnknownService(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $response = $this->controller($settings, $config, $health)->test('bogus');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('inconnu', $response->getContent());
    }

    public function testTestEndpointSwallowsExceptionsWithoutLeakingDetails(): void
    {
        // Security: the health check throwing must NOT propagate the exception
        // message into the JSON body. We return a generic message.
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);
        $health->method('isHealthy')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000]: /var/www/.../internal/path leak')
        );

        $response = $this->controller($settings, $config, $health)->test('radarr');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringContainsString('"ok":false', $response->getContent());
    }
}
