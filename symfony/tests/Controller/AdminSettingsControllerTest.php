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
        ?\App\Service\DashboardLayoutService $layout = null,
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
            $layout ?? new \App\Service\DashboardLayoutService($config),
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

    public function testPostPersistsPerServiceEnabledFlag(): void
    {
        // Issue #15 — same unchecked-box semantics as the sidebar toggles:
        // checkbox checked = '1' sent → drop the row (null); unchecked = not
        // sent → store an explicit '0' so the kill switch is on.
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())
            ->method('setMany')
            ->with($this->callback(function (array $payload) {
                return $payload['qbittorrent_enabled'] === null   // checked
                    && $payload['prowlarr_enabled']    === '0'    // unchecked
                    && $payload['jellyseerr_enabled']  === '0'    // unchecked
                    && $payload['tmdb_enabled']        === '0';   // unchecked
            }));

        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $request = Request::create(
            '/admin/settings',
            'POST',
            [
                '_csrf_token'         => 'valid',
                'qbittorrent_enabled' => '1', // only qBit's switch is on
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

    /**
     * Phase D #2 — the export must include a v2 'instances' section so a
     * user backing up their multi-instance config in v1.1.0 can re-import
     * it after a fresh install. Pre-Phase-D the dump only carried the flat
     * `setting` rows and silently lost every Radarr/Sonarr instance the
     * user had configured (URLs and api keys had moved to service_instance
     * with the multi-instance migration).
     */
    public function testExportPayloadIncludesV2InstancesSection(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('findAll')->willReturn([]);
        $config   = $this->createMock(ConfigService::class);
        $health   = $this->createMock(HealthService::class);

        $radarrInst = new \App\Entity\ServiceInstance(
            \App\Entity\ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://r:7878', 'secret-key'
        );
        $radarrInst->setIsDefault(true);
        $radarrInst->setEnabled(true);
        $radarrInst->setPosition(0);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getAll')->willReturnCallback(
            fn(string $type) => $type === \App\Entity\ServiceInstance::TYPE_RADARR ? [$radarrInst] : []
        );

        $response = $this->controller($settings, $config, $health, $instances)->export();
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(2, $payload['prismarr_export_version']);
        $this->assertIsArray($payload['instances']);
        $this->assertCount(1, $payload['instances']);
        $this->assertSame('radarr', $payload['instances'][0]['type']);
        $this->assertSame('radarr-1', $payload['instances'][0]['slug']);
        $this->assertSame('http://r:7878', $payload['instances'][0]['url']);
        $this->assertTrue($payload['instances'][0]['is_default']);
        // Critical: api keys must NOT leak through the export.
        $this->assertArrayNotHasKey('api_key', $payload['instances'][0]);
        $this->assertArrayNotHasKey('apiKey',  $payload['instances'][0]);
        $this->assertStringNotContainsString('secret-key', $response->getContent());
    }

    public function testImportV2RestoresInstancesViaProvider(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config   = $this->createMock(ConfigService::class);
        $health   = $this->createMock(HealthService::class);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')->willReturn(null); // every imported slug is new
        // The controller must call create() exactly once per instance row,
        // with api_key=null (the export never carries the secret).
        $createCalls = [];
        $instances->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (string $type, string $name, string $url, ?string $apiKey, ?string $slug, bool $enabled) use (&$createCalls) {
                $createCalls[] = compact('type', 'name', 'url', 'apiKey', 'slug', 'enabled');
                return new \App\Entity\ServiceInstance($type, $slug ?? 'auto', $name, $url, $apiKey);
            });

        $payload = json_encode([
            'prismarr_export_version' => 2,
            'settings'  => [],
            'instances' => [
                ['type' => 'radarr', 'slug' => 'radarr-1', 'name' => 'Radarr', 'url' => 'http://r:7878', 'enabled' => true,  'position' => 0, 'is_default' => true],
                ['type' => 'sonarr', 'slug' => 'sonarr-1', 'name' => 'Sonarr', 'url' => 'http://s:8989', 'enabled' => true,  'position' => 0, 'is_default' => true],
            ],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'prismarr-export-');
        file_put_contents($tmp, $payload);
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'export.json', 'application/json', test: true);

        $request = Request::create('/admin/settings/import', 'POST', ['_csrf_token' => 'valid'], [], ['config' => $file]);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->controller($settings, $config, $health, $instances)->import($request);

        $this->assertCount(2, $createCalls);
        $this->assertSame('radarr-1', $createCalls[0]['slug']);
        $this->assertNull($createCalls[0]['apiKey'], 'api key must NOT be passed by the import — the admin retypes it');
        $this->assertSame('sonarr-1', $createCalls[1]['slug']);
    }

    public function testImportV1StaysSupportedForBackwardsCompat(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $config   = $this->createMock(ConfigService::class);
        $health   = $this->createMock(HealthService::class);
        $instances = $this->createMock(ServiceInstanceProvider::class);
        // v1 doesn't carry instances — the provider must NOT be touched.
        $instances->expects($this->never())->method('create');
        $instances->expects($this->never())->method('update');

        $payload = json_encode([
            'prismarr_export_version' => 1,
            'settings' => ['display_language' => 'en'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'prismarr-export-');
        file_put_contents($tmp, $payload);
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'export.json', 'application/json', test: true);

        $request = Request::create('/admin/settings/import', 'POST', ['_csrf_token' => 'valid'], [], ['config' => $file]);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $response = $this->controller($settings, $config, $health, $instances)->import($request);
        $this->assertSame(302, $response->getStatusCode(), 'v1 imports must still redirect cleanly');
    }

    /**
     * Key a mocked provider call's positional arguments by parameter name,
     * so assertions read `['username']` whatever the signature order.
     *
     * @return array<string, mixed>
     */
    private static function named(string $method, array $args): array
    {
        $names = array_map(
            fn(\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(ServiceInstanceProvider::class, $method))->getParameters(),
        );
        return array_combine(array_slice($names, 0, count($args)), $args);
    }

    /** Build an UploadedFile + POST request for import(). */
    private function importRequest(array $payload): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'prismarr-export-');
        file_put_contents($tmp, json_encode($payload));
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'export.json', 'application/json', test: true);
        $request = Request::create('/admin/settings/import', 'POST', ['_csrf_token' => 'valid'], [], ['config' => $file]);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));
        return $request;
    }

    private static function withId(\App\Entity\ServiceInstance $i, int $id): \App\Entity\ServiceInstance
    {
        (new \ReflectionProperty($i, 'id'))->setValue($i, $id);
        return $i;
    }

    public function testExportIncludesQbittorrentAndGluetunInstancesWithoutSecrets(): void
    {
        // Issue #131 — qBittorrent rows carry the non-secret username and the
        // slug of their linked Gluetun; Gluetun rows are exported like
        // Radarr/Sonarr. The WebUI password and every API key stay out.
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('findAll')->willReturn([]);
        $gluetun = self::withId(new \App\Entity\ServiceInstance('gluetun', 'vpn-tv', 'VPN TV', 'http://10.0.0.3:8000', 'gluetun-secret-key'), 7);
        $qbt = new \App\Entity\ServiceInstance('qbittorrent', 'qbittorrent-1', 'Seedbox', 'http://10.0.0.2:8080');
        $qbt->setUsername('admin');
        $qbt->setPassword('qbt-secret-pass');
        $qbt->setGluetunInstanceId(7);
        $unlinked = new \App\Entity\ServiceInstance('qbittorrent', 'qbittorrent-2', 'Books', 'http://10.0.0.4:8080');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getAll')->willReturnCallback(fn(string $type) => match ($type) {
            'qbittorrent' => [$qbt, $unlinked],
            'gluetun'     => [$gluetun],
            default       => [],
        });

        $response = $this->controller($settings, $this->createMock(ConfigService::class), $this->createMock(HealthService::class), $instances)->export();
        $payload = json_decode($response->getContent(), true);

        $rows = array_column($payload['instances'], null, 'slug');
        $this->assertSame(['qbittorrent-1', 'qbittorrent-2', 'vpn-tv'], array_keys($rows));
        $this->assertSame('admin', $rows['qbittorrent-1']['username']);
        $this->assertSame('vpn-tv', $rows['qbittorrent-1']['gluetun'], 'link exported by slug');
        $this->assertNull($rows['qbittorrent-2']['gluetun']);
        $this->assertSame('gluetun', $rows['vpn-tv']['type']);
        $this->assertSame('http://10.0.0.3:8000', $rows['vpn-tv']['url']);
        $this->assertArrayNotHasKey('password', $rows['qbittorrent-1']);
        $this->assertArrayNotHasKey('api_key', $rows['vpn-tv']);
        $this->assertStringNotContainsString('qbt-secret-pass', $response->getContent());
        $this->assertStringNotContainsString('gluetun-secret-key', $response->getContent());
    }

    public function testImportTurnsLegacyFlatSettingsIntoLinkedDefaultInstances(): void
    {
        // An export made before #131 carries qbittorrent_url / _user and
        // gluetun_url as flat settings, which nothing reads anymore. Same
        // result as the DB migration: gluetun-1, then qbittorrent-1 linked.
        $settings = $this->createMock(SettingRepository::class);
        $settings->expects($this->once())->method('setMany')->with(['display_language' => 'en']);
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasAny')->willReturn(false);
        $calls = [];
        $instances->expects($this->exactly(2))->method('create')->willReturnCallback(
            function (...$args) use (&$calls) {
                $calls[] = $named = self::named('create', $args);
                return self::withId(new \App\Entity\ServiceInstance($named['type'], $named['slug'], $named['name'], $named['url']), count($calls) === 1 ? 42 : 43);
            }
        );

        $request = $this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => [
                'display_language' => 'en',
                'qbittorrent_url'  => 'http://10.0.0.2:8080',
                'qbittorrent_user' => 'admin',
                'gluetun_url'      => 'http://10.0.0.3:8000',
            ],
        ]);
        $this->controller($settings, $this->createMock(ConfigService::class), $this->createMock(HealthService::class), $instances)->import($request);

        [$gluetun, $qbt] = $calls;
        $this->assertSame('gluetun', $gluetun['type']);
        $this->assertSame('gluetun-1', $gluetun['slug']);
        $this->assertSame('http://10.0.0.3:8000', $gluetun['url']);
        $this->assertNull($gluetun['apiKey']);
        $this->assertSame('qbittorrent', $qbt['type']);
        $this->assertSame('qbittorrent-1', $qbt['slug']);
        $this->assertSame('http://10.0.0.2:8080', $qbt['url']);
        $this->assertNull($qbt['apiKey']);
        $this->assertSame('admin', $qbt['username']);
        $this->assertSame(42, $qbt['gluetunInstanceId'], 'qbittorrent-1 links to the imported gluetun-1');
    }

    public function testImportKeepsALegacyStandaloneGluetun(): void
    {
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasAny')->willReturn(false);
        $calls = [];
        $instances->expects($this->once())->method('create')->willReturnCallback(
            function (...$args) use (&$calls) {
                $calls[] = $named = self::named('create', $args);
                return new \App\Entity\ServiceInstance($named['type'], $named['slug'], $named['name'], $named['url']);
            }
        );

        $request = $this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => ['gluetun_url' => 'http://10.0.0.3:8000'],
        ]);
        $this->controller($this->createMock(SettingRepository::class), $this->createMock(ConfigService::class), $this->createMock(HealthService::class), $instances)->import($request);

        $this->assertSame('gluetun', $calls[0]['type']);
        $this->assertSame('gluetun-1', $calls[0]['slug']);
    }

    public function testImportIgnoresLegacyFlatQbittorrentWhenAnInstanceExists(): void
    {
        $settings  = $this->createMock(SettingRepository::class);
        $settings->expects($this->never())->method('setMany');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasAny')->willReturn(true);
        $instances->expects($this->never())->method('create');

        $request = $this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => ['qbittorrent_url' => 'http://10.0.0.2:8080', 'gluetun_url' => 'http://10.0.0.3:8000'],
        ]);
        $this->controller($settings, $this->createMock(ConfigService::class), $this->createMock(HealthService::class), $instances)->import($request);
    }

    public function testImportV2PassesQbittorrentFieldsAndRestoresGluetunLinks(): void
    {
        $existing = new \App\Entity\ServiceInstance('qbittorrent', 'qbittorrent-1', 'Home', 'http://10.0.0.2:8080');
        $seedbox  = new \App\Entity\ServiceInstance('qbittorrent', 'qbittorrent-2', 'Seedbox', 'http://10.0.0.5:8080');
        $vpn      = self::withId(new \App\Entity\ServiceInstance('gluetun', 'vpn', 'VPN', 'http://10.0.0.6:8000'), 9);
        $created  = [];
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')->willReturnCallback(
            function (string $type, string $slug) use ($existing, $seedbox, $vpn, &$created) {
                if ($type === 'qbittorrent' && $slug === 'qbittorrent-1') return $existing;
                // Created during the loop → resolvable when links are applied.
                if ($type === 'qbittorrent' && $slug === 'qbittorrent-2' && isset($created['qbittorrent'])) return $seedbox;
                if ($type === 'gluetun' && $slug === 'vpn' && isset($created['gluetun'])) return $vpn;
                return null;
            }
        );
        $updated = [];
        $instances->expects($this->once())->method('update')->willReturnCallback(
            function (...$args) use (&$updated, $existing) { $updated = self::named('update', $args); return $existing; }
        );
        $instances->expects($this->exactly(2))->method('create')->willReturnCallback(
            function (...$args) use (&$created, $seedbox, $vpn) {
                $named = self::named('create', $args);
                $created[$named['type']] = $named;
                return $named['type'] === 'gluetun' ? $vpn : $seedbox;
            }
        );
        $links = [];
        $instances->method('linkGluetun')->willReturnCallback(
            function (\App\Entity\ServiceInstance $qbt, ?\App\Entity\ServiceInstance $g) use (&$links) { $links[$qbt->getSlug()] = $g?->getSlug(); }
        );

        $request = $this->importRequest([
            'prismarr_export_version' => 2,
            'settings'  => [],
            'instances' => [
                // A row without the #131 keys leaves username and link alone.
                ['type' => 'qbittorrent', 'slug' => 'qbittorrent-1', 'name' => 'Home', 'url' => 'http://10.0.0.2:8080', 'enabled' => true, 'position' => 0, 'is_default' => true],
                // Comes BEFORE its Gluetun in the payload: resolved after the loop.
                ['type' => 'qbittorrent', 'slug' => 'qbittorrent-2', 'name' => 'Seedbox', 'url' => 'http://10.0.0.5:8080', 'enabled' => true, 'position' => 1, 'is_default' => false,
                 'username' => 'seed', 'gluetun' => 'vpn'],
                ['type' => 'gluetun', 'slug' => 'vpn', 'name' => 'VPN', 'url' => 'http://10.0.0.6:8000', 'enabled' => true, 'position' => 0, 'is_default' => true],
            ],
        ]);
        $this->controller($this->createMock(SettingRepository::class), $this->createMock(ConfigService::class), $this->createMock(HealthService::class), $instances)->import($request);

        $this->assertFalse($updated['username'], 'absent username must leave the stored one alone');
        $this->assertSame('seed', $created['qbittorrent']['username']);
        $this->assertNull($created['gluetun']['apiKey'], 'no API key in an export');
        $this->assertSame(['qbittorrent-2' => 'vpn'], $links, 'only rows carrying `gluetun` are (re)linked');
    }

    public function testDashboardLayoutEndpointPersistsOrderAndHidden(): void
    {
        $saved = [];
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('setMany')->willReturnCallback(function (array $p) use (&$saved) { $saved = $p; });
        $config = $this->createMock(ConfigService::class);
        $config->expects(self::once())->method('invalidate');
        $health = $this->createMock(HealthService::class);
        $controller = $this->controller($settings, $config, $health);

        $request = Request::create('/admin/settings/dashboard-layout', 'POST', [
            '_csrf_token' => 'x',
            'order'       => 'recent,plex',
            'hidden'      => 'health,trending',
        ]);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $response = $controller->dashboardLayout($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode($response->getContent(), true));
        self::assertSame('recent,plex', $saved['dashboard_section_order']);
        self::assertSame('1', $saved['dashboard_hide_health']);
        self::assertSame('1', $saved['dashboard_hide_trending']);
        self::assertNull($saved['dashboard_hide_plex']);
    }

    public function testSavePersistsDashboardOrderAndHiddenFlags(): void
    {
        $saved = [];
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('setMany')->willReturnCallback(function (array $p) use (&$saved) { $saved = $p; });
        $config = $this->createMock(ConfigService::class);
        $health = $this->createMock(HealthService::class);

        $controller = $this->controller($settings, $config, $health);

        $request = Request::create('/admin/settings', 'POST', [
            '_csrf_token'             => 'x',
            'dashboard_section_order' => 'recent,plex,upcoming,bogus',
            // health checkbox omitted => hidden; others present => visible
            'dashboard_visible_upcoming'  => '1',
            'dashboard_visible_requests'  => '1',
            'dashboard_visible_plex'      => '1',
            'dashboard_visible_watchlist' => '1',
            'dashboard_visible_trending'  => '1',
            'dashboard_visible_recent'    => '1',
        ]);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $controller->index($request);

        // Unknown 'bogus' dropped; order preserved; missing keys NOT appended on save.
        self::assertSame('recent,plex,upcoming', $saved['dashboard_section_order']);
        self::assertSame('1', $saved['dashboard_hide_health']);   // unchecked => hidden
        self::assertNull($saved['dashboard_hide_plex']);          // checked => visible
    }
}
