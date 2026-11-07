<?php

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Review 2026-11-06 (TQ-8): WorkerModeResetTest covered 5 of ~35
 * ResetInterface services. Under FrankenPHP worker mode every one of them
 * is reset between requests by the services_resetter; each must be
 * buildable from the container and survive reset() twice.
 */
class ResetInterfaceSweepTest extends KernelTestCase
{
    /** @return list<class-string> */
    public static function resettableServices(): array
    {
        $root = realpath(__DIR__ . '/../../src');
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns)
                || !preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)\b/m', $src, $m)) {
                continue;
            }
            $fqcn = $ns[1] . '\\' . $m[1];
            // Final review #3: resolve through the real class hierarchy, so an
            // implementer that reaches ResetInterface via another interface
            // (UsenetClientInterface) or a parent class is found too.
            if (!class_exists($fqcn) || !is_subclass_of($fqcn, ResetInterface::class) || (new \ReflectionClass($fqcn))->isAbstract()) {
                continue;
            }
            $out[] = $fqcn;
        }
        sort($out);
        return $out;
    }

    public function testEveryResetInterfaceServiceCanBeBuiltAndResetFromTheContainer(): void
    {
        self::bootKernel();
        $classes = self::resettableServices();
        $this->assertGreaterThanOrEqual(15, count($classes), 'the scan must find the services');
        // Final review, Important #3: ResetInterface reached through another
        // interface (UsenetClientInterface extends it) must be found too —
        // TQ-8 named SABnzbd/NZBGet explicitly.
        $this->assertContains(\App\Service\Media\Usenet\SabnzbdClient::class, $classes);
        $this->assertContains(\App\Service\Media\Usenet\NzbgetClient::class, $classes);

        foreach ($classes as $class) {
            $this->assertTrue(is_subclass_of($class, ResetInterface::class), $class);
            $svc = self::getContainer()->get($class);
            $this->assertInstanceOf($class, $svc);
            $svc->reset();
            $svc->reset(); // idempotent
        }
    }
}
