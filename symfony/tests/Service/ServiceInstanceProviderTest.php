<?php

namespace App\Tests\Service;

use App\Entity\ServiceInstance;
use App\Repository\ServiceInstanceRepository;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AllowMockObjectsWithoutExpectations]
class ServiceInstanceProviderTest extends TestCase
{
    private function makeInstance(
        string $type,
        string $slug,
        bool $isDefault = false,
        bool $enabled = true,
        int $position = 0,
        ?int $id = null,
    ): ServiceInstance {
        $i = new ServiceInstance($type, $slug, ucfirst($type) . ' ' . $slug, 'http://localhost', 'k');
        $i->setIsDefault($isDefault);
        $i->setEnabled($enabled);
        $i->setPosition($position);
        if ($id !== null) {
            // Bypass the missing public setter — Doctrine assigns the id
            // post-flush in real life. Tests need a fixed id to compare
            // identity in code paths like setDefault() and update() that
            // rely on getId() to tell siblings apart.
            $r = new \ReflectionClass($i);
            $p = $r->getProperty('id');
            $p->setAccessible(true);
            $p->setValue($i, $id);
        }
        return $i;
    }

    private function provider(ServiceInstanceRepository $repo): ServiceInstanceProvider
    {
        return new ServiceInstanceProvider($repo);
    }

    // ── getDefault ─────────────────────────────────────────────────────────

    public function testGetDefaultReturnsNullWhenNoInstances(): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([]);
        $this->assertNull($this->provider($repo)->getDefault(ServiceInstance::TYPE_RADARR));
    }

    public function testGetDefaultReturnsTheFlaggedInstance(): void
    {
        $a = $this->makeInstance('radarr', 'radarr-1', isDefault: false);
        $b = $this->makeInstance('radarr', 'radarr-2', isDefault: true);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $this->assertSame($b, $this->provider($repo)->getDefault(ServiceInstance::TYPE_RADARR));
    }

    public function testGetDefaultFallsBackToFirstWhenNoneFlagged(): void
    {
        // Edge case: previous default was deleted without nominating a successor.
        $a = $this->makeInstance('radarr', 'radarr-1', isDefault: false);
        $b = $this->makeInstance('radarr', 'radarr-2', isDefault: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $this->assertSame($a, $this->provider($repo)->getDefault(ServiceInstance::TYPE_RADARR));
    }

    public function testGetDefaultSkipsADisabledFlaggedInstance(): void
    {
        // Issue #15 — disabling the default instance must not leave it as the
        // fallback target for clients and getDefault() callers.
        $a = $this->makeInstance('radarr', 'on',  isDefault: false, enabled: true);
        $b = $this->makeInstance('radarr', 'off', isDefault: true,  enabled: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $this->assertSame($a, $this->provider($repo)->getDefault(ServiceInstance::TYPE_RADARR));
    }

    public function testGetDefaultReturnsNullWhenEveryInstanceIsDisabled(): void
    {
        $a = $this->makeInstance('radarr', 'off1', isDefault: true,  enabled: false);
        $b = $this->makeInstance('radarr', 'off2', isDefault: false, enabled: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $this->assertNull($this->provider($repo)->getDefault(ServiceInstance::TYPE_RADARR));
    }

    // ── getBySlug / requireBySlug ──────────────────────────────────────────

    public function testGetBySlugReturnsMatchOrNull(): void
    {
        $a = $this->makeInstance('radarr', 'main');
        $b = $this->makeInstance('radarr', '4k');
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $p = $this->provider($repo);
        $this->assertSame($b, $p->getBySlug(ServiceInstance::TYPE_RADARR, '4k'));
        $this->assertNull($p->getBySlug(ServiceInstance::TYPE_RADARR, 'unknown'));
    }

    public function testRequireBySlugThrows404WhenNotFound(): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([]);
        $this->expectException(NotFoundHttpException::class);
        $this->provider($repo)->requireBySlug(ServiceInstance::TYPE_RADARR, 'ghost');
    }

    // ── getEnabled / hasAnyEnabled ─────────────────────────────────────────

    public function testGetEnabledFiltersDisabledInstances(): void
    {
        $a = $this->makeInstance('radarr', 'on',  enabled: true);
        $b = $this->makeInstance('radarr', 'off', enabled: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $enabled = $this->provider($repo)->getEnabled(ServiceInstance::TYPE_RADARR);
        $this->assertCount(1, $enabled);
        $this->assertSame('on', $enabled[0]->getSlug());
    }

    public function testHasAnyEnabledIsFalseWhenAllDisabled(): void
    {
        $a = $this->makeInstance('radarr', 'off1', enabled: false);
        $b = $this->makeInstance('radarr', 'off2', enabled: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $this->assertFalse($this->provider($repo)->hasAnyEnabled(ServiceInstance::TYPE_RADARR));
    }

    // ── saveDefault ────────────────────────────────────────────────────────

    public function testSaveDefaultCreatesFirstInstanceWhenNoneExists(): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findDefaultForType')->willReturn(null);
        $captured = null;
        $repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured = $i; });

        $result = $this->provider($repo)->saveDefault(ServiceInstance::TYPE_RADARR, 'http://r:7878', 'k');

        $this->assertNotNull($result);
        $this->assertNotNull($captured);
        $this->assertSame('radarr-1', $captured->getSlug());
        $this->assertTrue($captured->isDefault());
        $this->assertTrue($captured->isEnabled());
        $this->assertSame('http://r:7878', $captured->getUrl());
        $this->assertSame('k', $captured->getApiKey());
    }

    public function testSaveDefaultUpdatesExistingDefault(): void
    {
        $existing = $this->makeInstance('radarr', 'main', isDefault: true);
        $existing->setUrl('http://old:7878');
        $existing->setApiKey('old-key');
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findDefaultForType')->willReturn($existing);
        $repo->expects($this->once())->method('save')->with($this->identicalTo($existing));

        $result = $this->provider($repo)->saveDefault(ServiceInstance::TYPE_RADARR, 'http://new:7878', 'new-key');

        $this->assertSame($existing, $result);
        $this->assertSame('http://new:7878', $existing->getUrl());
        $this->assertSame('new-key', $existing->getApiKey());
    }

    public function testSaveDefaultRemovesDefaultWhenUrlIsEmpty(): void
    {
        $existing = $this->makeInstance('radarr', 'main', isDefault: true);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findDefaultForType')->willReturn($existing);
        $repo->expects($this->once())->method('remove')->with($this->identicalTo($existing));
        $repo->method('findByType')->willReturn([]); // no successor to promote
        $repo->expects($this->never())->method('save');

        $result = $this->provider($repo)->saveDefault(ServiceInstance::TYPE_RADARR, '', null);

        $this->assertNull($result);
    }

    public function testSaveDefaultPromotesNextWhenDefaultRemoved(): void
    {
        $existing = $this->makeInstance('radarr', 'main', isDefault: true);
        $next     = $this->makeInstance('radarr', '4k',   isDefault: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findDefaultForType')->willReturn($existing);
        $repo->method('findByType')->willReturn([$next]);
        $repo->expects($this->once())->method('remove')->with($this->identicalTo($existing));
        $repo->expects($this->once())->method('save')->with($this->identicalTo($next));

        $this->provider($repo)->saveDefault(ServiceInstance::TYPE_RADARR, '', null);

        $this->assertTrue($next->isDefault(), '4k should have been promoted to default');
    }

    // ── create ─────────────────────────────────────────────────────────────

    public function testCreateRejectsEmptyNameOrUrl(): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $p = $this->provider($repo);

        $this->expectException(\InvalidArgumentException::class);
        $p->create(ServiceInstance::TYPE_RADARR, '', 'http://r', 'k');
    }

    public function testCreateRejectsUnknownType(): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->provider($repo)->create('jellyseerr', 'name', 'http://j', 'k');
    }

    /**
     * Defense in depth — even though every cURL call is pinned to
     * CURLPROTO_HTTP|HTTPS, an admin form that accepted file:// or
     * javascript: into the DB would be a footgun for any future code path
     * that didn't go through cURL. Provider-level validation catches it
     * at write time.
     *
     * @dataProvider blockedUrls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockedUrls')]
    public function testCreateRejectsBlockedUrlScheme(string $url): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid instance URL/');
        $this->provider($repo)->create(ServiceInstance::TYPE_RADARR, 'Bad', $url, 'k');
    }

    /** @return list<array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            ['file:///etc/passwd'],
            ['javascript:alert(1)'],
            ['gopher://attacker.example/'],
            ['ftp://internal-only/'],
            ['http:///no-host-here'],
            ['http://192.168.1.50:89000/'], // port out of range → malformed
        ];
    }

    public function testCreateAutoRenamesDuplicateSlug(): void
    {
        // When the caller-provided slug already exists, normalizeSlug() walks
        // -2 / -3 / ... until it finds a free one. We assert the actual final
        // slug so a regression that drops the dedupe loop is caught.
        $clash = $this->makeInstance('radarr', 'radarr-4k', id: 1);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findOneBySlug')->willReturnCallback(
            fn(string $type, string $slug) => $slug === 'radarr-4k' ? $clash : null
        );
        $repo->method('findByType')->willReturn([$clash]);
        $captured = null;
        $repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured = $i; });

        $this->provider($repo)->create(ServiceInstance::TYPE_RADARR, 'Radarr 4K', 'http://r', 'k', slug: 'radarr-4k');

        $this->assertNotNull($captured);
        $this->assertSame('radarr-4k-2', $captured->getSlug(), 'duplicate slug must be deduped with -2 suffix');
    }

    public function testCreateFlagsFirstInstanceAsDefault(): void
    {
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findOneBySlug')->willReturn(null);
        $repo->method('findByType')->willReturn([]); // none yet
        $captured = null;
        $repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured = $i; });

        $this->provider($repo)->create(ServiceInstance::TYPE_RADARR, 'My Radarr', 'http://r:7878', 'k');

        $this->assertNotNull($captured);
        $this->assertTrue($captured->isDefault(), 'first instance must be default');
        $this->assertSame(0, $captured->getPosition());
    }

    public function testCreateLeavesSecondInstanceAsNonDefault(): void
    {
        $existing = $this->makeInstance('radarr', 'main', isDefault: true, position: 0);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findOneBySlug')->willReturn(null);
        $repo->method('findByType')->willReturn([$existing]);
        $captured = null;
        $repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured = $i; });

        $this->provider($repo)->create(ServiceInstance::TYPE_RADARR, 'Radarr 4K', 'http://r2:7878', 'k2');

        $this->assertNotNull($captured);
        $this->assertFalse($captured->isDefault());
        $this->assertSame(1, $captured->getPosition(), 'position must be max+1');
    }

    // ── update ─────────────────────────────────────────────────────────────

    public function testUpdatePreservesApiKeyWhenSubmittedEmpty(): void
    {
        // Mirrors the v1.0 password-empty regression: an admin saving the
        // form with the api_key field stripped by Firefox/Chrome must NOT
        // wipe the stored key.
        $instance = $this->makeInstance('radarr', 'main');
        $instance->setApiKey('preserved-key');
        $repo = $this->createMock(ServiceInstanceRepository::class);

        $this->provider($repo)->update($instance, 'New name', 'http://new:7878', '');

        $this->assertSame('preserved-key', $instance->getApiKey(), 'empty api_key submission must preserve the existing one');
        $this->assertSame('New name', $instance->getName());
    }

    public function testUpdateSetsDefaultQualityProfileId(): void
    {
        // Issue #5 — an explicit int selects that profile as the instance default.
        $instance = $this->makeInstance('radarr', 'main');
        $repo = $this->createMock(ServiceInstanceRepository::class);

        $this->provider($repo)->update($instance, 'Radarr', 'http://r:7878', null, defaultQualityProfileId: 7);

        $this->assertSame(7, $instance->getDefaultQualityProfileId());
    }

    public function testUpdateClearsDefaultQualityProfileIdWithNull(): void
    {
        // Explicit "None" resets to the first-profile fallback.
        $instance = $this->makeInstance('radarr', 'main');
        $instance->setDefaultQualityProfileId(7);
        $repo = $this->createMock(ServiceInstanceRepository::class);

        $this->provider($repo)->update($instance, 'Radarr', 'http://r:7878', null, defaultQualityProfileId: null);

        $this->assertNull($instance->getDefaultQualityProfileId());
    }

    public function testUpdateLeavesDefaultQualityProfileIdUnchangedWhenAbsent(): void
    {
        // The `false` sentinel (field not submitted) must not wipe an existing
        // default — same "absent means unchanged" contract as the api key.
        $instance = $this->makeInstance('radarr', 'main');
        $instance->setDefaultQualityProfileId(7);
        $repo = $this->createMock(ServiceInstanceRepository::class);

        $this->provider($repo)->update($instance, 'Radarr', 'http://r:7878', null);

        $this->assertSame(7, $instance->getDefaultQualityProfileId());
    }

    public function testCreateStoresDefaultQualityProfileId(): void
    {
        // Preserved across the v2 backup/restore path, which re-creates instances.
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findOneBySlug')->willReturn(null);
        $repo->method('findByType')->willReturn([]);

        $instance = $this->provider($repo)->create('radarr', 'Radarr 4K', 'http://r:7878', 'key', defaultQualityProfileId: 4);

        $this->assertSame(4, $instance->getDefaultQualityProfileId());
    }

    public function testUpdateRejectsSlugClashOnAnotherInstance(): void
    {
        // The instance under edit has id=1 and slug=main; another instance
        // with id=2 already owns the desired slug '4k'. update() must throw
        // rather than overwrite the unique-by-slug invariant.
        $instance = $this->makeInstance('radarr', 'main', id: 1);
        $clash    = $this->makeInstance('radarr', '4k',   id: 2);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findOneBySlug')->willReturn($clash);

        $this->expectException(\InvalidArgumentException::class);
        $this->provider($repo)->update($instance, 'Renamed', 'http://r', null, slug: '4k');
    }

    // ── delete ─────────────────────────────────────────────────────────────

    public function testDeletePromotesNextWhenDefaultRemoved(): void
    {
        $instance = $this->makeInstance('radarr', 'main', isDefault: true);
        $next     = $this->makeInstance('radarr', '4k',   isDefault: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$next]);
        $repo->expects($this->once())->method('remove')->with($this->identicalTo($instance));
        $repo->expects($this->once())->method('save')->with($this->identicalTo($next));

        $this->provider($repo)->delete($instance);

        $this->assertTrue($next->isDefault());
    }

    public function testDeleteDoesNotPromoteWhenInstanceWasNotDefault(): void
    {
        $instance = $this->makeInstance('radarr', '4k', isDefault: false);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->expects($this->once())->method('remove')->with($this->identicalTo($instance));
        $repo->expects($this->never())->method('save');

        $this->provider($repo)->delete($instance);
    }

    // ── setDefault ─────────────────────────────────────────────────────────

    public function testSetDefaultDemotesPreviousDefault(): void
    {
        // Distinct ids needed — setDefault() compares getId() to skip
        // demoting the target itself among siblings.
        $previous = $this->makeInstance('radarr', 'main', isDefault: true,  id: 1);
        $target   = $this->makeInstance('radarr', '4k',   isDefault: false, id: 2);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('findByType')->willReturn([$previous, $target]);

        $this->provider($repo)->setDefault($target);

        $this->assertFalse($previous->isDefault(), 'previous default must be demoted');
        $this->assertTrue($target->isDefault());
    }

    public function testSetDefaultIsNoOpWhenAlreadyDefault(): void
    {
        $instance = $this->makeInstance('radarr', 'main', isDefault: true);
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->expects($this->never())->method('save');

        $this->provider($repo)->setDefault($instance);
    }

    // ── qBittorrent fields + Gluetun links (issue #131) ───────────────────

    private function qbt(): ServiceInstance
    {
        $i = $this->withId(new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://10.0.0.2:8080'), 10);
        $i->setUsername('admin');
        $i->setPassword('old-pass');
        $i->setGluetunInstanceId(20);
        return $i;
    }

    private function gluetun(int $id, string $slug = 'gluetun-1', bool $enabled = true): ServiceInstance
    {
        $g = $this->withId(new ServiceInstance(ServiceInstance::TYPE_GLUETUN, $slug, ucfirst($slug), 'http://10.0.0.3:8000', 'old-key'), $id);
        $g->setEnabled($enabled);
        return $g;
    }

    private function withId(ServiceInstance $i, int $id): ServiceInstance
    {
        (new \ReflectionProperty($i, 'id'))->setValue($i, $id);
        return $i;
    }

    /** Repository whose find() knows $known (by id). */
    private function repoKnowing(ServiceInstance ...$known): ServiceInstanceRepository
    {
        $byId = [];
        foreach ($known as $i) {
            $byId[$i->getId()] = $i;
        }
        $repo = $this->createMock(ServiceInstanceRepository::class);
        $repo->method('find')->willReturnCallback(fn ($id) => $byId[$id] ?? null);
        $repo->method('findOneBySlug')->willReturn(null);
        $repo->method('findByType')->willReturnCallback(
            fn (string $type) => array_values(array_filter($known, fn (ServiceInstance $i) => $i->getType() === $type))
        );
        return $repo;
    }

    public function testCreateStoresQbittorrentCredentialsAndGluetunLink(): void
    {
        $repo = $this->repoKnowing($this->gluetun(20));
        $captured = null;
        $repo->method('save')->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured = $i; });

        $this->provider($repo)->create(
            ServiceInstance::TYPE_QBITTORRENT, 'Seedbox', 'http://10.0.0.2:8080', null,
            username: 'admin', password: 'pass', gluetunInstanceId: 20,
        );

        $this->assertNotNull($captured);
        $this->assertSame('admin', $captured->getUsername());
        $this->assertSame('pass', $captured->getPassword());
        $this->assertSame(20, $captured->getGluetunInstanceId());
        $this->assertFalse($captured->usesProxyAuth());
    }

    public function testCreateWithoutCredentialsIsReverseProxyMode(): void
    {
        // Issue #10 per instance — empty user/password stored as null.
        $repo = $this->repoKnowing();
        $captured = null;
        $repo->method('save')->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured = $i; });

        $this->provider($repo)->create(ServiceInstance::TYPE_QBITTORRENT, 'qBit', 'http://10.0.0.2:8080', null, username: '', password: '');

        $this->assertNull($captured->getUsername());
        $this->assertNull($captured->getPassword());
        $this->assertNull($captured->getGluetunInstanceId());
        $this->assertTrue($captured->usesProxyAuth());
    }

    public function testCreateStandaloneGluetunWithOptionalApiKey(): void
    {
        $repo = $this->repoKnowing();
        $captured = [];
        $repo->method('save')->willReturnCallback(function (ServiceInstance $i) use (&$captured) { $captured[] = $i; });
        $provider = $this->provider($repo);

        $provider->create(ServiceInstance::TYPE_GLUETUN, 'VPN TV', 'http://10.0.0.3:8000', ' ');
        $provider->create(ServiceInstance::TYPE_GLUETUN, 'VPN Movies', 'http://10.0.0.4:8000', 'key');

        $this->assertSame('vpn-tv', $captured[0]->getSlug());
        $this->assertNull($captured[0]->getApiKey(), 'Gluetun without control-server auth');
        $this->assertSame('key', $captured[1]->getApiKey());
    }

    public function testCreateRejectsALinkToAMissingOrNonGluetunInstance(): void
    {
        $sonarr = $this->withId(new ServiceInstance(ServiceInstance::TYPE_SONARR, 'sonarr-1', 'Sonarr 1', 'http://10.0.0.5:8989'), 30);
        $repo = $this->repoKnowing($sonarr);
        $repo->expects($this->never())->method('save');
        $provider = $this->provider($repo);

        foreach ([30, 99] as $bad) {
            try {
                $provider->create(ServiceInstance::TYPE_QBITTORRENT, 'qBit', 'http://10.0.0.2:8080', null, gluetunInstanceId: $bad);
                $this->fail("link to #$bad must be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Gluetun', $e->getMessage());
            }
        }
    }

    public function testOnlyQbittorrentCanLinkToGluetun(): void
    {
        $repo = $this->repoKnowing($this->gluetun(20));
        $repo->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->provider($repo)->create(ServiceInstance::TYPE_RADARR, 'Radarr', 'http://10.0.0.6:7878', 'k', gluetunInstanceId: 20);
    }

    public function testUpdateLeavesQbittorrentFieldsUnchangedWhenAbsent(): void
    {
        // Radarr/Sonarr forms (and the settings import) don't send these.
        $instance = $this->qbt();
        $this->provider($this->repoKnowing())
            ->update($instance, 'Renamed', 'http://10.0.0.2:8080', null);

        $this->assertSame('admin', $instance->getUsername());
        $this->assertSame('old-pass', $instance->getPassword());
        $this->assertSame(20, $instance->getGluetunInstanceId());
    }

    public function testUpdatePreservesPasswordWhenSubmittedEmpty(): void
    {
        // type=password input: empty = unchanged, same rule as api_key.
        $instance = $this->qbt();
        $this->provider($this->repoKnowing())
            ->update($instance, 'qBit', 'http://10.0.0.2:8080', null, username: 'admin', password: '');

        $this->assertSame('old-pass', $instance->getPassword());
    }

    public function testUpdateReplacesAndClearsPassword(): void
    {
        $instance = $this->qbt();
        $provider = $this->provider($this->repoKnowing());

        $provider->update($instance, 'qBit', 'http://10.0.0.2:8080', null, password: 'new-pass');
        $this->assertSame('new-pass', $instance->getPassword());

        // The clear flag wins even if the browser re-submitted the old value.
        $provider->update($instance, 'qBit', 'http://10.0.0.2:8080', null, password: 'new-pass', clearPassword: true);
        $this->assertNull($instance->getPassword());
        $this->assertTrue($instance->usesProxyAuth());
    }

    public function testUpdateEmptyUsernameClearsIt(): void
    {
        // Plain text input: an empty submission really means "clear".
        $instance = $this->qbt();
        $this->provider($this->repoKnowing())
            ->update($instance, 'qBit', 'http://10.0.0.2:8080', null, username: '');

        $this->assertNull($instance->getUsername());
        $this->assertTrue($instance->usesProxyAuth());
    }

    public function testUpdateRelinksAndUnlinksGluetun(): void
    {
        $instance = $this->qbt();
        $provider = $this->provider($this->repoKnowing($this->gluetun(20), $this->gluetun(21, 'gluetun-2')));

        $provider->update($instance, 'qBit', 'http://10.0.0.2:8080', null, gluetunInstanceId: 21);
        $this->assertSame(21, $instance->getGluetunInstanceId());

        $provider->update($instance, 'qBit', 'http://10.0.0.2:8080', null, gluetunInstanceId: null);
        $this->assertNull($instance->getGluetunInstanceId());
    }

    public function testUpdateRejectsABadLinkBeforeTouchingTheInstance(): void
    {
        $instance = $this->qbt();
        $repo = $this->repoKnowing();
        $repo->expects($this->never())->method('save');

        try {
            $this->provider($repo)->update($instance, 'Renamed', 'http://10.0.0.2:8080', null, gluetunInstanceId: 99);
            $this->fail('unknown Gluetun must be rejected');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame('qBittorrent 1', $instance->getName(), 'no half-applied update');
        $this->assertSame(20, $instance->getGluetunInstanceId());
    }

    public function testUpdateGluetunApiKeyCanBeCleared(): void
    {
        $gluetun  = $this->gluetun(20);
        $provider = $this->provider($this->repoKnowing($gluetun));

        $provider->update($gluetun, 'Gluetun', 'http://10.0.0.3:8000', '');
        $this->assertSame('old-key', $gluetun->getApiKey(), 'empty password input = unchanged');

        $provider->update($gluetun, 'Gluetun', 'http://10.0.0.3:8000', 'old-key', clearApiKey: true);
        $this->assertNull($gluetun->getApiKey());
    }

    public function testDeletingAGluetunUnlinksItsQbittorrentInstancesWithoutDeletingThem(): void
    {
        $gluetun = $this->gluetun(20);
        $linked  = $this->qbt();
        $other   = $this->withId(new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-2', 'qBittorrent 2', 'http://10.0.0.7:8080'), 11);
        $other->setGluetunInstanceId(21);
        $repo = $this->repoKnowing($gluetun, $linked, $other);
        $removed = [];
        $repo->method('remove')->willReturnCallback(function (ServiceInstance $i) use (&$removed) { $removed[] = $i; });

        $this->provider($repo)->delete($gluetun);

        $this->assertSame([$gluetun], $removed, 'never cascades to qBittorrent');
        $this->assertNull($linked->getGluetunInstanceId());
        $this->assertSame(21, $other->getGluetunInstanceId(), 'other links untouched');
    }

    public function testGetGluetunForResolvesOnlyEnabledLinkedInstances(): void
    {
        $qbt = $this->qbt(); // links to #20
        $this->assertSame(20, $this->provider($this->repoKnowing($this->gluetun(20)))->getGluetunFor($qbt)?->getId());
        $this->assertNull(
            $this->provider($this->repoKnowing($this->gluetun(20, enabled: false)))->getGluetunFor($qbt),
            'a disabled Gluetun reads as no Gluetun',
        );
        $this->assertNull($this->provider($this->repoKnowing($this->gluetun(21)))->getGluetunFor($qbt), 'dangling id');
        $qbt->setGluetunInstanceId(null);
        $this->assertNull($this->provider($this->repoKnowing($this->gluetun(20)))->getGluetunFor($qbt));
    }

    public function testLinkGluetunValidatesBothSides(): void
    {
        $qbt      = $this->qbt();
        $gluetun  = $this->gluetun(21, 'gluetun-2');
        $provider = $this->provider($this->repoKnowing($gluetun));

        $provider->linkGluetun($qbt, $gluetun);
        $this->assertSame(21, $qbt->getGluetunInstanceId());
        $provider->linkGluetun($qbt, null);
        $this->assertNull($qbt->getGluetunInstanceId());

        $this->expectException(\InvalidArgumentException::class);
        $provider->linkGluetun($qbt, $this->qbt());
    }
}
