<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiResponse;
use App\Security\KeyringProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/health')]
final class HealthController extends AbstractController
{
    #[Route('/live', name: 'api_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return ApiResponse::success(['status' => 'live']);
    }

    #[Route('/ready', name: 'api_health_ready', methods: ['GET'])]
    public function ready(EntityManagerInterface $entityManager, KeyringProvider $keyringProvider): JsonResponse
    {
        try {
            $entityManager->getConnection()->executeQuery('SELECT 1')->fetchOne();
            $key = $keyringProvider->activeKey();

            return ApiResponse::success([
                'status' => 'ready',
                'database' => 'ok',
                'keyring' => [
                    'activeKeyId' => $key['keyId'],
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'NOT_READY',
                'Service dependencies are not ready.',
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
