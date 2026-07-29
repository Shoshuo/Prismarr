<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

final class ThemeRenderTest extends AbstractWebTestCase
{
    public function testDefaultThemeInjectsVariablesAndDropsToggle(): void
    {
        $this->client->request('GET', '/tableau-de-bord');
        $html = $this->client->getResponse()->getContent();

        self::assertStringContainsString('--tblr-body-bg: hsl(0, 0%, 6.5%)', $html);
        self::assertStringContainsString('data-bs-theme="dark"', $html);
        self::assertStringNotContainsString('id="theme-toggle"', $html);
    }
}
