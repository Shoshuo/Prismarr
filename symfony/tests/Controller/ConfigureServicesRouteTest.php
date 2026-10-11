<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\AbstractWebTestCase;

/**
 * Every "configure this service" link goes through app_configure_services:
 * admins land on the Services settings, other users go home instead of
 * hitting a 403 on /admin/settings.
 */
final class ConfigureServicesRouteTest extends AbstractWebTestCase
{
    public function testAdminLandsOnTheServicesSettings(): void
    {
        $this->client->request('GET', '/services/configurer');
        self::assertResponseRedirects('/admin/settings#section-services');
    }

    public function testNonAdminGoesHome(): void
    {
        $user = new User();
        $user->setEmail('viewer@example.test');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('unused-hash');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/services/configurer');
        self::assertResponseRedirects('/');
    }

    /** An unconfigured ROLE_USER page (Tautulli) no longer dead-ends on a 403 for non-admins. */
    public function testNonAdminOnAnUnconfiguredServiceIsNotForbidden(): void
    {
        $user = new User();
        $user->setEmail('viewer2@example.test');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('unused-hash');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/tautulli');
        self::assertResponseRedirects('/services/configurer');
        $this->client->followRedirect();
        self::assertResponseRedirects('/');
    }
}
