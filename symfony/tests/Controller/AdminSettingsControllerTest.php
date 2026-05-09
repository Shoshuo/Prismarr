<?php

namespace App\Tests\Controller;

use App\Controller\AdminSettingsController;
use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class AdminSettingsControllerTest extends TestCase
{
    private function controller(
        SettingRepository $settings,
        ConfigService $config,
        HealthService $health,
        ?ServiceInstanceProvider $instances = null,
        array $services = [],
    ): AdminSettingsController {
        $appVersion = $this->createMock(\App\Service\AppVersion::class);
        $appVersion->method('current')->willReturn('test');
        $appVersion->method('latest')->willReturn(null);
        $appVersion->method('isUpdateAvailable')->willReturn(false);
        $appVersion->method('releases')->willReturn([]);

        $controller = new AdminSettingsController(
            $settings,
            $config,
            $instances ?? $this->createMock(ServiceInstanceProvider::class),
            $health,
            $this->createMock(LoggerInterface::class),
            $this->createMock(\Symfony\Component\Cache\Adapter\AdapterInterface::class),
            $appVersion,
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
                || array_key_exists($id, $services)
        );
        $container->method('get')->willReturnCallback(function (string $id) use ($twig, $csrf, $requestStack, $router, $services) {
            return match (true) {
                $id === 'twig'                        => $twig,
                $id === 'security.csrf.token_manager' => $csrf,
                $id === 'request_stack'               => $requestStack,
                $id === 'router'                      => $router,
                array_key_exists($id, $services)      => $services[$id],
                default                               => null,
            };
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
        // v1.1.0 — radarr_url / radarr_api_key are no longer in `setting`,
        // they go through ServiceInstanceProvider::saveDefault(). The flat
        // setMany() path now only carries non-instance-backed fields.
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())
            ->method('setMany')
            ->with($this->callback(function (array $payload) {
                // Radarr & sonarr fields must be ABSENT from the flat payload.
                foreach (['radarr_url', 'radarr_api_key', 'sonarr_url', 'sonarr_api_key'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        return false;
                    }
                }
                // Empty sensitive submission (tmdb_api_key) must still be
                // skipped — see testEmptyPasswordFieldsAreNotWiped.
                return !array_key_exists('tmdb_api_key', $payload);
            }));

        $config = $this->createMock(ConfigService::class);
        $config->expects($this->once())->method('invalidate');

        $instances = $this->createMock(ServiceInstanceProvider::class);
        // Default instance has no existing api_key, so saveDefault gets the
        // submitted value verbatim. Capture every saveDefault() call and
        // assert on it after index() runs (PHPUnit's `with()` matcher only
        // takes one arg per position, callback is awkward for 3-arg calls).
        $instances->method('getDefault')->willReturn(null);
        $captured = [];
        $instances->expects($this->atLeastOnce())
            ->method('saveDefault')
            ->willReturnCallback(function (string $type, ?string $url, ?string $apiKey) use (&$captured) {
                $captured[$type] = ['url' => $url, 'apiKey' => $apiKey];
                return null;
            });

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

        $this->controller($settings, $config, $health, $instances)->index($request);

        $this->assertArrayHasKey(\App\Entity\ServiceInstance::TYPE_RADARR, $captured,
            'Radarr saveDefault must be invoked when radarr_url is submitted');
        $this->assertSame('http://new-radarr:7878', $captured[\App\Entity\ServiceInstance::TYPE_RADARR]['url']);
        $this->assertSame('new-key', $captured[\App\Entity\ServiceInstance::TYPE_RADARR]['apiKey']);
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
                // Radarr/sonarr fields go through the instance provider, never the flat payload.
                foreach (['radarr_url', 'radarr_api_key', 'sonarr_url', 'sonarr_api_key'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        return false;
                    }
                }
                // Every other sensitive field must be ABSENT from the payload, not null.
                $sensitive = [
                    'tmdb_api_key',
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
                'radarr_url'            => '',  // routed through instance provider
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

    /**
     * v1.1.0 regression — same Firefox/Chrome stripping issue on the new
     * service_instance write path. When the admin POSTs `radarr_url=X` with
     * an empty `radarr_api_key`, saveDefault() must receive the EXISTING api
     * key from the default instance, not null. Otherwise saving the form
     * after editing the URL alone wipes the API key (issue we already fixed
     * in v1.0 for the flat settings — must hold post-migration too).
     */
    public function testRadarrInstanceApiKeyPreservedWhenFormSendsEmpty(): void
    {
        $existing = new \App\Entity\ServiceInstance(
            \App\Entity\ServiceInstance::TYPE_RADARR,
            'radarr-1',
            'Radarr 1',
            'http://old-radarr:7878',
            'preserved-existing-key',
        );

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getDefault')->willReturnCallback(
            fn(string $type) => $type === \App\Entity\ServiceInstance::TYPE_RADARR ? $existing : null
        );

        // The crucial assertion: saveDefault must receive the preserved key,
        // never null/empty when the form left the password field blank.
        $captured = ['url' => null, 'apiKey' => null];
        $instances->expects($this->atLeastOnce())
            ->method('saveDefault')
            ->willReturnCallback(function (string $type, ?string $url, ?string $apiKey) use (&$captured, $existing) {
                if ($type === \App\Entity\ServiceInstance::TYPE_RADARR) {
                    $captured['url']    = $url;
                    $captured['apiKey'] = $apiKey;
                }
                return $existing;
            });

        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $request = Request::create(
            '/admin/settings',
            'POST',
            [
                '_csrf_token'    => 'valid',
                'radarr_url'     => 'http://new-radarr:7878', // user edited URL
                'radarr_api_key' => '',                       // browser stripped it
            ]
        );
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->controller($settings, $config, $health, $instances)->index($request);

        $this->assertSame('http://new-radarr:7878', $captured['url']);
        $this->assertSame('preserved-existing-key', $captured['apiKey'],
            'API key must be re-injected from the existing default instance, not wiped to null');
    }

    /**
     * v1.1.0 — CRITICAL regression: posting the main /admin/settings form
     * (Save button) MUST NOT touch the radarr/sonarr instances when the
     * form does not carry their fields. Their inputs moved to the modales
     * handled by AdminInstancesController, so the main form's POST body
     * never includes them. Treating "absent key" as "intent to clear"
     * would call saveDefault(type, null, null) and wipe the user's
     * configured Radarr/Sonarr in a single click of "Save".
     *
     * This test was added after the bug was witnessed live in dev.
     */
    public function testMainSaveDoesNotWipeRadarrSonarrInstancesWhenFieldsAbsent(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config   = $this->createMock(ConfigService::class);
        $health   = $this->createMock(HealthService::class);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        // Hard expectation: the main save must NEVER call saveDefault on
        // either type when the form did not carry their fields.
        $instances->expects($this->never())->method('saveDefault');

        $request = Request::create(
            '/admin/settings',
            'POST',
            [
                '_csrf_token'  => 'valid',
                // Simulate the user editing only the theme color (or any
                // other unrelated field) and clicking Save. NO radarr_url,
                // radarr_api_key, sonarr_url, or sonarr_api_key.
                'tmdb_api_key' => '',
            ]
        );
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->controller($settings, $config, $health, $instances)->index($request);
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
        $health->expects($this->once())->method('diagnose')->with('radarr')->willReturn([
            'ok' => true, 'category' => 'ok', 'http' => 200,
        ]);

        $response = $this->controller($settings, $config, $health)->test('radarr', Request::create('/admin/settings/test/radarr', 'POST'));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"ok":true', $response->getContent());
        $this->assertStringContainsString('"service":"Radarr"', $response->getContent());
        $this->assertStringContainsString('"category":"ok"', $response->getContent());
        $this->assertStringContainsString('"http":200', $response->getContent());
    }

    public function testTestEndpointReturnsFailureJsonWithCategoryAndHttp(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);
        $health->method('diagnose')->willReturn([
            'ok' => false, 'category' => 'auth', 'http' => 401,
        ]);

        $response = $this->controller($settings, $config, $health)->test('sonarr', Request::create('/admin/settings/test/sonarr', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"ok":false', $response->getContent());
        $this->assertStringContainsString('"category":"auth"', $response->getContent());
        $this->assertStringContainsString('"http":401', $response->getContent());
    }

    public function testTestEndpointRejectsUnknownService(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $response = $this->controller($settings, $config, $health)->test('bogus', Request::create('/admin/settings/test/bogus', 'POST'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Unknown', $response->getContent());
    }

    public function testTestEndpointForwardsFormOverridesToHealth(): void
    {
        // The user can type a new URL/key in the form and click "Test" without
        // saving — the controller must whitelist the relevant fields and pass
        // them as overrides to HealthService::diagnose.
        $settings = $this->createMock(SettingRepository::class);
        $config   = $this->createMock(ConfigService::class);
        $health   = $this->createMock(HealthService::class);
        $health->expects($this->once())
            ->method('diagnose')
            ->with('radarr', $this->callback(function (?array $overrides) {
                return is_array($overrides)
                    && ($overrides['radarr_url'] ?? null) === 'http://typed-but-not-saved:7878'
                    && ($overrides['radarr_api_key'] ?? null) === 'typed-key'
                    // Fields belonging to other services must NOT leak through.
                    && !array_key_exists('sonarr_url', $overrides)
                    && !array_key_exists('display_theme_color', $overrides);
            }))
            ->willReturn(['ok' => true, 'category' => 'ok', 'http' => 200]);

        $request = Request::create('/admin/settings/test/radarr', 'POST', [
            'radarr_url'          => 'http://typed-but-not-saved:7878',
            'radarr_api_key'      => 'typed-key',
            'sonarr_url'          => 'http://attacker:1234',  // ignored
            'display_theme_color' => 'orange',                // ignored
        ]);

        $this->controller($settings, $config, $health)->test('radarr', $request);
    }

    /**
     * v1.1.0 — Section Languages must update only the instance whose slug
     * appears in the form. With 2 enabled Radarr instances, posting
     * `radarr_ui[radarr-1]=2` (and nothing for `radarr-2`) must result in
     * exactly one updateUiConfig call, on `radarr-1`. Touching the other
     * would be a regression: a partial save would silently rewrite an
     * unrelated instance's UI language.
     */
    public function testLanguagesSaveOnlyUpdatesInstanceMentionedInPayload(): void
    {
        $inst1 = new \App\Entity\ServiceInstance(
            \App\Entity\ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr',    'http://r1:7878', 'k1'
        );
        $inst2 = new \App\Entity\ServiceInstance(
            \App\Entity\ServiceInstance::TYPE_RADARR, 'radarr-2', 'Radarr 4K', 'http://r2:7878', 'k2'
        );

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type) => $type === \App\Entity\ServiceInstance::TYPE_RADARR ? [$inst1, $inst2] : []
        );

        // Per-instance sub-clients. withInstance($inst1) returns $client1,
        // withInstance($inst2) returns $client2. We then assert update is
        // called on $client1 only.
        $client1 = $this->createMock(\App\Service\Media\RadarrClient::class);
        $client1->method('getUiConfig')->willReturn(['uiLanguage' => 1, 'movieInfoLanguage' => 1]);
        $client1->expects($this->once())
            ->method('updateUiConfig')
            ->with($this->callback(fn(array $ui) => ($ui['uiLanguage'] ?? null) === 2));

        $client2 = $this->createMock(\App\Service\Media\RadarrClient::class);
        $client2->expects($this->never())->method('getUiConfig');
        $client2->expects($this->never())->method('updateUiConfig');

        $radarrTemplate = $this->createMock(\App\Service\Media\RadarrClient::class);
        $radarrTemplate->method('withInstance')->willReturnCallback(
            fn(\App\Entity\ServiceInstance $i) => $i->getSlug() === 'radarr-1' ? $client1 : $client2
        );

        $settings = $this->createMock(SettingRepository::class);
        $config   = $this->createMock(ConfigService::class);
        $health   = $this->createMock(HealthService::class);

        $request = Request::create(
            '/admin/settings/languages/save',
            'POST',
            [
                '_csrf_token' => 'valid',
                'radarr_ui'   => ['radarr-1' => '2'],
            ]
        );
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $controller = $this->controller($settings, $config, $health, $instances, [
            \App\Service\Media\RadarrClient::class => $radarrTemplate,
        ]);

        $response = $controller->languagesSave($request);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testTestEndpointSwallowsExceptionsWithoutLeakingDetails(): void
    {
        // Security: the health check throwing must NOT propagate the exception
        // message into the JSON body. We return a generic message.
        $settings = $this->createMock(SettingRepository::class);
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);
        $health->method('diagnose')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000]: /var/www/.../internal/path leak')
        );

        $response = $this->controller($settings, $config, $health)->test('radarr', Request::create('/admin/settings/test/radarr', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringContainsString('"ok":false', $response->getContent());
        $this->assertStringContainsString('"category":"unknown"', $response->getContent());
    }
}
