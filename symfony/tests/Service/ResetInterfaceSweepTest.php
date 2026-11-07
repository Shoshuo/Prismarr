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
            if (!preg_match('/^(?:final\s+)?class\s+(\w+)\b[^{]*\bResetInterface\b/m', $src, $m)) {
                continue;
            }
            if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
                continue;
            }
            $out[] = $ns[1] . '\\' . $m[1];
        }
        sort($out);
        return $out;
    }

    public function testEveryResetInterfaceServiceCanBeBuiltAndResetFromTheContainer(): void
    {
        self::bootKernel();
        $classes = self::resettableServices();
        $this->assertGreaterThanOrEqual(15, count($classes), 'the scan must find the services');

        foreach ($classes as $class) {
            $this->assertTrue(is_subclass_of($class, ResetInterface::class), $class);
            $svc = self::getContainer()->get($class);
            $this->assertInstanceOf($class, $svc);
            $svc->reset();
            $svc->reset(); // idempotent
        }
    }
}
