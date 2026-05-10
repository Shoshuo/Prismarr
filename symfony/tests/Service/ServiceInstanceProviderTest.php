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
}
