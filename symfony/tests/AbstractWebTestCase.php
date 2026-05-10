<?php

namespace App\Tests;

use App\Controller\SetupController;
use App\Entity\ServiceInstance;
use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for tests that boot a real Symfony kernel and need an
 * authenticated admin + a completed setup flag.
 *
 * Each test class gets an isolated SQLite file (see .env.test). We drop
 * and recreate the schema in setUp() so every test starts from a known
 * state — cheap with SQLite on a tmpfs/cache dir.
 */
abstract class AbstractWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $em = $this->em();
        $this->resetSchema($em);
        $this->admin = $this->seedAdmin($em);
        $this->seedSetupCompleted($em);
        $this->seedDefaultInstances($em);

        $this->client->loginUser($this->admin);
    }

    /**
     * v1.1.0 Phase A→C — every Radarr/Sonarr-backed route is now slug-aware
     * (`/medias/{slug}/films`). Smoke tests need at least one default
     * instance per type so the slug-prefixed routes resolve, otherwise
     * everything 404s and the smoke tests stop catching real regressions.
     *
     * The instances point at unreachable hosts (the dev container's loopback)
     * so the controllers still surface the "service not configured / down"
     * banner — the goal is to verify the controllers render cleanly, not
     * that the upstream services are reachable.
     */
    private function seedDefaultInstances(EntityManagerInterface $em): void
    {
        $radarr = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://radarr.invalid:7878', 'k');
        $radarr->setIsDefault(true);
        $radarr->setEnabled(true);
        $em->persist($radarr);

        $sonarr = new ServiceInstance(ServiceInstance::TYPE_SONARR, 'sonarr-1', 'Sonarr', 'http://sonarr.invalid:8989', 'k');
        $sonarr->setIsDefault(true);
        $sonarr->setEnabled(true);
        $em->persist($sonarr);

        $em->flush();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function resetSchema(EntityManagerInterface $em): void
    {
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function seedAdmin(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = new User();
        $admin->setEmail('admin@test.local');
        $admin->setDisplayName('Admin Test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'admin-password'));

        $em->persist($admin);
        $em->flush();

        return $admin;
    }

    private function seedSetupCompleted(EntityManagerInterface $em): void
    {
        $flag = new Setting(SetupController::SETUP_DONE_KEY, '1');

        $em->persist($flag);
        $em->flush();
    }

    /**
     * Assert that the response is not a crash. 2xx/3xx/4xx are fine — even
     * a 403 or 404 means the controller rendered cleanly. 503 is also
     * accepted because ServiceNotConfiguredSubscriber throws it on purpose
     * when a media client has no config in DB (expected in the test env).
     * Only 500/502/504 indicate an actual uncaught exception.
     */
    protected function assertDidNotCrash(string $path): void
    {
        $status = $this->client->getResponse()->getStatusCode();

        $this->assertTrue(
            $status < 500 || $status === 503,
            sprintf('GET %s returned %d; expected < 500 or 503 (service-not-configured).', $path, $status)
        );
    }
}
