<?php

namespace App\Tests\Controller;

use App\Controller\AdminSettingsController;
use App\Entity\ServiceInstance;
use App\Repository\SettingRepository;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Review 2026-11-06 (S2 + S3): a settings import used to write ANY
 * non-secret key, including every `*_url`, while the stored secrets stayed
 * in place — so a hostile "backup" file repointed each integration at an
 * attacker host and the next health sweep sent the API keys there. The
 * import now (a) only accepts keys the app itself knows, (b) refuses
 * URLs that fail the shared validator (incl. CSP-metacharacter hosts),
 * (c) clears a service's stored secrets when its URL changes host, and
 * (d) normalizes display preferences like the form does (AS-10).
 */
#[AllowMockObjectsWithoutExpectations]
class AdminSettingsImportHardeningTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $saved = [];

    /** @var list<string> */
    private array $flashes = [];

    /** @param array<string, ?string> $currentConfig */
    private function controller(array $currentConfig = [], ?ServiceInstanceProvider $instances = null): AdminSettingsController
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('setMany')->willReturnCallback(function (array $p): void { $this->saved = $p; });
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $currentConfig[$k] ?? null);
        $config->method('has')->willReturnCallback(fn(string $k) => isset($currentConfig[$k]));

        $appVersion = $this->createMock(\App\Service\AppVersion::class);
        $controller = new AdminSettingsController(
            $settings,
            $config,
            $instances ?? $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(HealthService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\Symfony\Component\Cache\Adapter\AdapterInterface::class),
            $appVersion,
            new \App\Service\DashboardLayoutService($config),
            projectDir: sys_get_temp_dir(),
            environment: 'test',
        );

        $csrf = $this->createMock(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $requestStack = new \Symfony\Component\HttpFoundation\RequestStack();
        $session = new Session(new MockArraySessionStorage());
        $sessionRequest = Request::create('/');
        $sessionRequest->setSession($session);
        $requestStack->push($sessionRequest);
        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn(string $name) => '/_route/' . $name);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn(string $id) => in_array($id, ['security.csrf.token_manager', 'request_stack', 'router'], true));
        $container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
            'security.csrf.token_manager' => $csrf,
            'request_stack'               => $requestStack,
            'router'                      => $router,
            default                       => null,
        });
        $controller->setContainer($container);
        return $controller;
    }

    /** @param array<string, mixed> $payload */
    private function importRequest(array $payload): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'prismarr-import-');
        file_put_contents($tmp, json_encode($payload));
        $file = new UploadedFile($tmp, 'export.json', 'application/json', test: true);
        $request = Request::create('/admin/settings/import', 'POST', ['_csrf_token' => 'valid'], [], ['config' => $file]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    public function testUnknownKeysAreNotImported(): void
    {
        $this->controller()->import($this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => ['evil_unknown_key' => 'x', 'display_language' => 'en', 'sidebar_hide_bazarr' => '1', 'prowlarr_enabled' => '0'],
        ]));

        $this->assertArrayNotHasKey('evil_unknown_key', $this->saved);
        $this->assertSame('en', $this->saved['display_language']);
        $this->assertSame('1', $this->saved['sidebar_hide_bazarr']);
        $this->assertSame('0', $this->saved['prowlarr_enabled']);
    }

    public function testAUrlThatFailsTheValidatorIsSkipped(): void
    {
        $this->controller()->import($this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => [
                'prowlarr_url'   => "http://x.lan;script-src * 'unsafe-inline';x/",
                'jellyseerr_url' => 'http://169.254.169.254/',
                'bazarr_url'     => 'ftp://bazarr.lan/',
                'tautulli_url'   => 'http://tautulli.lan:8181',
            ],
        ]));

        $this->assertArrayNotHasKey('prowlarr_url', $this->saved, 'CSP directive injection host');
        $this->assertArrayNotHasKey('jellyseerr_url', $this->saved, 'link-local / metadata ip');
        $this->assertArrayNotHasKey('bazarr_url', $this->saved, 'non-http scheme');
        $this->assertSame('http://tautulli.lan:8181', $this->saved['tautulli_url']);
    }

    public function testChangingAServiceHostClearsItsStoredSecrets(): void
    {
        $this->controller([
            'prowlarr_url'         => 'http://prowlarr.lan:9696',
            'prowlarr_api_key'     => 'secret-p',
            'qbittorrent_url'      => 'http://qbit.lan:8080',
            'qbittorrent_password' => 'secret-q',
            'tautulli_url'         => 'http://tautulli.lan:8181',
            'tautulli_api_key'     => 'secret-t',
        ])->import($this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => [
                'prowlarr_url'    => 'http://attacker.example:9696',   // host changed
                'qbittorrent_url' => 'http://qbit.lan:9090/',          // same host, other port/path
                'tautulli_url'    => 'http://tautulli.lan:8181',       // unchanged
            ],
        ]));

        $this->assertSame('http://attacker.example:9696', $this->saved['prowlarr_url']);
        $this->assertArrayHasKey('prowlarr_api_key', $this->saved);
        $this->assertNull($this->saved['prowlarr_api_key'], 'the key must not travel to the new host');
        $this->assertArrayNotHasKey('qbittorrent_password', $this->saved, 'same host → secret kept');
        $this->assertArrayNotHasKey('tautulli_api_key', $this->saved);
    }

    public function testChangingAnInstanceHostClearsItsApiKey(): void
    {
        $existing = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://radarr.lan:7878', 'secret-r');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')->willReturn($existing);
        $instances->method('update')->willReturn($existing);
        $instances->expects($this->once())->method('clearApiKey')->with($existing);

        $this->controller([], $instances)->import($this->importRequest([
            'prismarr_export_version' => 2,
            'settings'  => [],
            'instances' => [['type' => 'radarr', 'slug' => 'radarr-1', 'name' => 'Radarr', 'url' => 'http://evil.example:7878', 'enabled' => true, 'position' => 0]],
        ]));
    }

    public function testSameInstanceHostKeepsTheApiKey(): void
    {
        $existing = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://radarr.lan:7878', 'secret-r');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getBySlug')->willReturn($existing);
        $instances->method('update')->willReturn($existing);
        $instances->expects($this->never())->method('clearApiKey');

        $this->controller([], $instances)->import($this->importRequest([
            'prismarr_export_version' => 2,
            'settings'  => [],
            'instances' => [['type' => 'radarr', 'slug' => 'radarr-1', 'name' => 'Radarr 4K', 'url' => 'http://radarr.lan:7879', 'enabled' => true, 'position' => 1]],
        ]));
    }

    public function testDisplayPreferencesAreNormalizedLikeTheForm(): void
    {
        // AS-10: a raw display_metadata_language with cache-key reserved
        // characters made every TmdbClient::cachedGet() throw.
        $this->controller()->import($this->importRequest([
            'prismarr_export_version' => 1,
            'settings' => ['display_metadata_language' => '{}()/\\@:', 'display_home_page' => 'films', 'display_toasts' => 'yes'],
        ]));

        $this->assertNull($this->saved['display_metadata_language'] ?? null, 'invalid value → default (null), never the raw string');
        $this->assertSame('films', $this->saved['display_home_page']);
        $this->assertSame('0', $this->saved['display_toasts'], 'switches normalize to 1/0');
    }

    public function testSaveRefusesAUrlThatFailsTheValidator(): void
    {
        $request = Request::create('/admin/settings', 'POST', [
            '_csrf_token'  => 'x',
            'prowlarr_url' => "http://x.lan;script-src * 'unsafe-inline';x/",
            'tautulli_url' => 'http://tautulli.lan:8181',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->controller()->index($request);

        $this->assertArrayNotHasKey('prowlarr_url', $this->saved, 'the injected host is not stored');
        $this->assertSame('http://tautulli.lan:8181', $this->saved['tautulli_url']);
    }
}
