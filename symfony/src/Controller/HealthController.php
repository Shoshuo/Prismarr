<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(Connection $db): JsonResponse
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        try {
            $db->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'db' => 'unreachable', 'timestamp' => $timestamp],
                503
            );
        }

        return new JsonResponse(
            ['status' => 'ok', 'db' => 'ok', 'timestamp' => $timestamp],
            200
        );
    }
}
