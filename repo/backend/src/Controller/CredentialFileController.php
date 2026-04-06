<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CredentialSubmissionVersion;
use App\Entity\User;
use App\Http\ApiResponse;
use App\Repository\CredentialSubmissionVersionRepository;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Service\AuditLogger;
use App\Service\CredentialFileStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/credentials/versions')]
final class CredentialFileController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly CredentialSubmissionVersionRepository $versions,
        private readonly CredentialFileStorageService $fileStorage,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/{versionId}/download', name: 'api_credentials_version_download', methods: ['GET'])]
    public function download(int $versionId, Request $request)
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication required.', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $version = $this->resolveAuthorizedVersion($versionId, $user);
        if (!$version instanceof CredentialSubmissionVersion) {
            return ApiResponse::error('NOT_FOUND', 'Credential version not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $absolutePath = $this->fileStorage->resolveAbsolutePath($version->getStoragePath());
        } catch (\RuntimeException) {
            return ApiResponse::error('NOT_FOUND', 'Credential file not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log('credential.file_downloaded', $user->getUsername(), [
            'versionId' => $version->getId(),
            'submissionId' => $version->getSubmission()->getId(),
        ]);

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $version->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $version->getOriginalFilename());

        return $response;
    }

    private function resolveAuthorizedVersion(int $versionId, User $user): ?CredentialSubmissionVersion
    {
        if ($this->authorization->hasPermission($user, 'credential.review')) {
            $version = $this->versions->find($versionId);

            return $version instanceof CredentialSubmissionVersion ? $version : null;
        }

        if (!$this->authorization->hasPermission($user, 'credential.upload.self')) {
            return null;
        }

        return $this->versions->findOneOwnedById($versionId, $user);
    }
}
