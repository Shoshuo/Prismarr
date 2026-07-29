<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le template Transmission est dérivé de celui de Deluge : ses appels fetch()
 * ne fonctionnent que si le jeu de routes est identique au préfixe près.
 */
class TransmissionRouteParityTest extends KernelTestCase
{
    public function testEveryDelugeRouteHasATransmissionTwin(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $all = array_keys($router->getRouteCollection()->all());

        $deluge = array_filter($all, fn($n) => str_starts_with($n, 'app_deluge_'));
        self::assertNotEmpty($deluge, 'sanity: Deluge routes must exist');

        foreach ($deluge as $name) {
            $twin = str_replace('app_deluge_', 'app_transmission_', $name);
            self::assertContains($twin, $all, "missing Transmission twin for {$name}");
        }
    }

    /**
     * Réciproque de testEveryDelugeRouteHasATransmissionTwin(). Les deux
     * contrôleurs déclarent le même nombre de routes (21 chacun, vérifié
     * manuellement) : la relation est donc une bijection, pas seulement
     * une inclusion à sens unique. Une route Transmission ajoutée plus
     * tard sans son pendant Deluge romprait cette garantie silencieusement
     * si on ne testait que le sens Deluge → Transmission.
     */
    public function testNoTransmissionRouteIsOrphaned(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        $all = array_keys($router->getRouteCollection()->all());

        $transmission = array_filter($all, fn($n) => str_starts_with($n, 'app_transmission_'));
        self::assertNotEmpty($transmission, 'sanity: Transmission routes must exist');

        foreach ($transmission as $name) {
            $twin = str_replace('app_transmission_', 'app_deluge_', $name);
            self::assertContains($twin, $all, "orphaned Transmission route with no Deluge counterpart: {$name}");
        }
    }
}
