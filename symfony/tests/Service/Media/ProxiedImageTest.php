<?php

namespace App\Tests\Service\Media;

use App\Service\Media\ProxiedImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxiedImageTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function types(): iterable
    {
        yield 'jpeg'              => ['image/jpeg', 'image/jpeg'];
        yield 'jpg alias'         => ['image/jpg', 'image/jpeg'];
        yield 'png with charset'  => ['image/png; charset=binary', 'image/png'];
        yield 'upper-case webp'   => ['IMAGE/WEBP', 'image/webp'];
        yield 'svg refused'       => ['image/svg+xml', null];
        yield 'svg with params'   => ['image/svg+xml; charset=utf-8', null];
        yield 'html refused'      => ['text/html', null];
        yield 'json refused'      => ['application/json', null];
        yield 'empty refused'     => ['', null];
    }

    #[DataProvider('types')]
    public function testOnlyRasterTypesPass(string $header, ?string $expected): void
    {
        self::assertSame($expected, ProxiedImage::rasterContentType($header));
    }
}
