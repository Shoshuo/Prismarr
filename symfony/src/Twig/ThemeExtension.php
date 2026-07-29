<?php
namespace App\Twig;

use App\Service\ThemeService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the resolved theme to templates:
 *   {{ theme().light }}                 {# bool #}
 *   {{ theme().primary_hex }}           {# "#6366f1" #}
 *   {% for name, value in theme().css %}{{ name }}: {{ value }};{% endfor %}
 */
final class ThemeExtension extends AbstractExtension
{
    public function __construct(private readonly ThemeService $theme) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('theme', [$this->theme, 'resolve']),
        ];
    }
}
