<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\Usenet\SabnzbdClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Les actions SABnzbd (pause/reprise/suppression/limite) doivent échouer
 * FERMÉ : une réponse 200 sans clé `status` est une anomalie, pas un
 * succès. NzbgetClient applique déjà `=== true` (cf. NzbgetClient:166-198).
 */
#[AllowMockObjectsWithoutExpectations]
class SabnzbdActionResultTest extends TestCase
{
    public function testActionWithoutStatusKeyIsNotReportedAsSuccess(): void
    {
        self::assertFalse(
            $this->clientReturning(['queue' => ['slots' => []]])->pauseAll(),
            'une réponse sans `status` ne doit pas valoir succès',
        );
    }

    public function testActionWithExplicitTrueSucceeds(): void
    {
        self::assertTrue($this->clientReturning(['status' => true])->pauseAll());
    }

    public function testActionWithExplicitFalseFails(): void
    {
        self::assertFalse($this->clientReturning(['status' => false])->pauseAll());
    }

    private function clientReturning(array $payload): SabnzbdClient
    {
        // La sous-classe anonyme n'appelle pas le constructeur parent par
        // défaut ; comme `action()` (non surchargé) touche désormais
        // `$this->logger` quand `status` est absent, on le construit avec de
        // vraies dépendances factices plutôt que de laisser les propriétés
        // typed/readonly non initialisées.
        return new class(
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $this->createMock(ServiceHealthCache::class),
            $payload,
        ) extends SabnzbdClient {
            public function __construct(
                ConfigService $config,
                LoggerInterface $logger,
                ServiceHealthCache $health,
                private array $payload,
            ) {
                parent::__construct($config, $logger, $health);
            }

            protected function call(array $params): array
            {
                return $this->payload;
            }
        };
    }
}
