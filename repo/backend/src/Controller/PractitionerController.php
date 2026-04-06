<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CredentialSubmission;
use App\Entity\CredentialSubmissionVersion;
use App\Entity\PractitionerProfile;
use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\CredentialSubmissionRepository;
use App\Repository\CredentialSubmissionVersionRepository;
use App\Repository\PractitionerProfileRepository;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Security\FieldEncryptionService;
use App\Service\AuditLogger;
use App\Service\CredentialFileStorageService;
use App\Service\LicenseNumberMasker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/practitioner')]
final class PractitionerController extends AbstractController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly ValidatorInterface $validator,
        private readonly PractitionerProfileRepository $profiles,
        private readonly CredentialSubmissionRepository $submissions,
        private readonly CredentialSubmissionVersionRepository $versions,
        private readonly FieldEncryptionService $encryption,
        private readonly LicenseNumberMasker $licenseMasker,
        private readonly CredentialFileStorageService $fileStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/profile', name: 'api_practitioner_profile_get', methods: ['GET'])]
    public function profile(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'practitioner.manage.self');

        $profile = $this->profiles->findOneByUser($user);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'profile' => $profile instanceof PractitionerProfile ? $this->serializeProfile($profile) : null,
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/profile', name: 'api_practitioner_profile_upsert', methods: ['PUT'])]
    public function upsertProfile(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'practitioner.manage.self');

        $payload = $this->jsonBodyParser->parse($request);
        $profile = $this->profiles->findOneByUser($user);
        $this->validateProfilePayload($payload, $profile instanceof PractitionerProfile);

        $lawyerFullName = trim((string) $payload['lawyerFullName']);
        $firmName = trim((string) $payload['firmName']);
        $barJurisdiction = trim((string) $payload['barJurisdiction']);
        $licenseNumber = isset($payload['licenseNumber']) ? trim((string) $payload['licenseNumber']) : '';
        if ($licenseNumber !== '' && mb_strlen($licenseNumber) < 4) {
            throw new ApiValidationException('License number must be at least 4 characters when provided.', [
                ['field' => 'licenseNumber', 'issue' => 'min_length_4'],
            ]);
        }

        $encryptedLicense = $profile instanceof PractitionerProfile ? $profile->encryptedLicensePayload() : null;
        $maskedLicense = $profile instanceof PractitionerProfile ? $profile->getLicenseNumberMasked() : '';

        if ($licenseNumber !== '') {
            $encryptedLicense = $this->encryption->encrypt($licenseNumber);
            $maskedLicense = $this->licenseMasker->mask($licenseNumber);
        }

        if (!is_array($encryptedLicense)) {
            throw new ApiValidationException('License number is required for profile creation.', [
                ['field' => 'licenseNumber', 'issue' => 'required'],
            ]);
        }

        if (!$profile instanceof PractitionerProfile) {
            $profile = new PractitionerProfile(
                $user,
                $lawyerFullName,
                $firmName,
                $barJurisdiction,
                $encryptedLicense,
                $maskedLicense,
            );
            $this->entityManager->persist($profile);
        } else {
            $profile->update($lawyerFullName, $firmName, $barJurisdiction, $encryptedLicense, $maskedLicense);
        }

        $this->entityManager->flush();

        $this->auditLogger->log('practitioner.profile_upserted', $user->getUsername(), [
            'profileId' => $profile->getId(),
            'barJurisdiction' => $profile->getBarJurisdiction(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'profile' => $this->serializeProfile($profile),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/credentials', name: 'api_practitioner_credentials_list', methods: ['GET'])]
    public function listCredentials(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'credential.upload.self');

        $profile = $this->profiles->findOneByUser($user);
        $requestId = $request->attributes->get('request_id');

        if (!$profile instanceof PractitionerProfile) {
            return ApiResponse::success([
                'profileRequired' => true,
                'submissions' => [],
            ], requestId: is_string($requestId) ? $requestId : null);
        }

        $submissions = $this->submissions->findByProfile($profile);

        return ApiResponse::success([
            'profileRequired' => false,
            'submissions' => array_map(
                fn (CredentialSubmission $submission): array => $this->serializeSubmission($submission, true),
                $submissions,
            ),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/credentials', name: 'api_practitioner_credentials_upload', methods: ['POST'])]
    public function uploadCredential(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'credential.upload.self');

        $profile = $this->profiles->findOneByUser($user);
        if (!$profile instanceof PractitionerProfile) {
            throw new ApiValidationException('Practitioner profile is required before credential upload.', [
                ['field' => 'profile', 'issue' => 'required'],
            ]);
        }

        $label = trim((string) $request->request->get('label', ''));
        if ($label === '' || mb_strlen($label) > 180) {
            throw new ApiValidationException('Credential label is invalid.', [
                ['field' => 'label', 'issue' => 'required_max_180'],
            ]);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ApiValidationException('Credential file is required.', [
                ['field' => 'file', 'issue' => 'required'],
            ]);
        }

        $this->validateCredentialFile($file);
        $storedFile = $this->fileStorage->storeUploadedFile($file);

        $submission = new CredentialSubmission($profile, $label);
        $submission->markPendingReview(1);

        $version = new CredentialSubmissionVersion(
            $submission,
            1,
            $storedFile['storagePath'],
            $storedFile['originalFilename'],
            $storedFile['mimeType'],
            $storedFile['sizeBytes'],
            $user->getUsername(),
        );

        $this->entityManager->persist($submission);
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $this->auditLogger->log('credential.uploaded', $user->getUsername(), [
            'submissionId' => $submission->getId(),
            'versionId' => $version->getId(),
            'fileName' => $version->getOriginalFilename(),
            'fileSizeBytes' => $version->getSizeBytes(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'submission' => $this->serializeSubmission($submission, true),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/credentials/{submissionId}/resubmit', name: 'api_practitioner_credentials_resubmit', methods: ['POST'])]
    public function resubmitCredential(int $submissionId, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'credential.upload.self');

        $submission = $this->submissions->findOneOwnedById($submissionId, $user);
        if (!$submission instanceof CredentialSubmission) {
            return ApiResponse::error('NOT_FOUND', 'Credential submission not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($submission->getStatus() !== CredentialSubmission::STATUS_RESUBMISSION_REQUIRED) {
            return ApiResponse::error(
                'INVALID_STATE',
                'Credential submission is not in resubmission-required state.',
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $label = trim((string) $request->request->get('label', ''));
        if ($label !== '') {
            if (mb_strlen($label) > 180) {
                throw new ApiValidationException('Credential label is invalid.', [
                    ['field' => 'label', 'issue' => 'required_max_180'],
                ]);
            }
            $submission->setLabel($label);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ApiValidationException('Credential file is required.', [
                ['field' => 'file', 'issue' => 'required'],
            ]);
        }

        $this->validateCredentialFile($file);
        $storedFile = $this->fileStorage->storeUploadedFile($file);

        $nextVersion = $submission->getCurrentVersionNumber() + 1;
        $submission->markPendingReview($nextVersion);

        $version = new CredentialSubmissionVersion(
            $submission,
            $nextVersion,
            $storedFile['storagePath'],
            $storedFile['originalFilename'],
            $storedFile['mimeType'],
            $storedFile['sizeBytes'],
            $user->getUsername(),
        );

        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $this->auditLogger->log('credential.resubmitted', $user->getUsername(), [
            'submissionId' => $submission->getId(),
            'versionId' => $version->getId(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'submission' => $this->serializeSubmission($submission, true),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    /** @param array<string, mixed> $payload */
    private function validateProfilePayload(array $payload, bool $allowEmptyLicenseForUpdate): void
    {
        $licenseConstraints = [new Assert\Type('string'), new Assert\Length(min: 0, max: 64)];
        if (!$allowEmptyLicenseForUpdate) {
            $licenseConstraints = [new Assert\NotBlank(), new Assert\Type('string'), new Assert\Length(min: 4, max: 64)];
        }

        $violations = $this->validator->validate($payload, new Assert\Collection(
            fields: [
                'lawyerFullName' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 180)],
                'firmName' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 180)],
                'barJurisdiction' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 120)],
                'licenseNumber' => $allowEmptyLicenseForUpdate ? [new Assert\Optional($licenseConstraints)] : $licenseConstraints,
            ],
            allowMissingFields: false,
            allowExtraFields: true,
        ));

        if (count($violations) === 0) {
            return;
        }

        $details = [];
        foreach ($violations as $violation) {
            $details[] = [
                'field' => trim((string) $violation->getPropertyPath(), '[]'),
                'issue' => $violation->getMessage(),
            ];
        }

        throw new ApiValidationException('Practitioner profile payload is invalid.', $details);
    }

    private function validateCredentialFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ApiValidationException('Credential file upload failed.', [
                ['field' => 'file', 'issue' => 'upload_failed'],
            ]);
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new ApiValidationException('Credential file size must be between 1 byte and 10 MB.', [
                ['field' => 'file', 'issue' => 'invalid_size'],
            ]);
        }

        $allowedMimeTypes = [
            'application/pdf',
            'application/x-pdf',
            'image/jpeg',
            'image/png',
        ];
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

        $mimeType = strtolower((string) ($file->getClientMimeType() ?: ''));
        $detectedMimeType = '';
        $realPath = $file->getRealPath();
        if (function_exists('finfo_open') && is_string($realPath) && $realPath !== '' && is_file($realPath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $realPath);
                finfo_close($finfo);
                $detectedMimeType = is_string($detected) ? strtolower($detected) : '';
            }
        }

        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $mimeAllowed = in_array($mimeType, $allowedMimeTypes, true) || in_array($detectedMimeType, $allowedMimeTypes, true);

        if (!$mimeAllowed || !in_array($extension, $allowedExtensions, true)) {
            throw new ApiValidationException('Credential file must be PDF, JPG, or PNG.', [
                ['field' => 'file', 'issue' => 'invalid_type'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function serializeProfile(PractitionerProfile $profile): array
    {
        return [
            'id' => $profile->getId(),
            'lawyerFullName' => $profile->getLawyerFullName(),
            'firmName' => $profile->getFirmName(),
            'barJurisdiction' => $profile->getBarJurisdiction(),
            'licenseNumberMasked' => $profile->getLicenseNumberMasked(),
            'updatedAtUtc' => $profile->getUpdatedAtUtc()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSubmission(CredentialSubmission $submission, bool $includeVersions): array
    {
        $latest = $this->versions->findLatestBySubmission($submission);
        $versions = $includeVersions ? $this->versions->findBySubmission($submission) : [];

        return [
            'id' => $submission->getId(),
            'label' => $submission->getLabel(),
            'status' => $submission->getStatus(),
            'currentVersionNumber' => $submission->getCurrentVersionNumber(),
            'updatedAtUtc' => $submission->getUpdatedAtUtc()->format(DATE_ATOM),
            'latestVersion' => $latest instanceof CredentialSubmissionVersion ? $this->serializeVersion($latest) : null,
            'versions' => array_map(fn (CredentialSubmissionVersion $version): array => $this->serializeVersion($version), $versions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeVersion(CredentialSubmissionVersion $version): array
    {
        return [
            'id' => $version->getId(),
            'versionNumber' => $version->getVersionNumber(),
            'originalFilename' => $version->getOriginalFilename(),
            'mimeType' => $version->getMimeType(),
            'sizeBytes' => $version->getSizeBytes(),
            'reviewStatus' => $version->getReviewStatus(),
            'reviewComment' => $version->getReviewComment(),
            'reviewedByUsername' => $version->getReviewedByUsername(),
            'reviewedAtUtc' => $version->getReviewedAtUtc()?->format(DATE_ATOM),
            'uploadedByUsername' => $version->getUploadedByUsername(),
            'uploadedAtUtc' => $version->getUploadedAtUtc()->format(DATE_ATOM),
            'downloadPath' => '/api/credentials/versions/'.$version->getId().'/download',
        ];
    }

    private function currentUser(): User
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is required.');
        }

        return $user;
    }
}
