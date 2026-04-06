<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CredentialSubmission;
use App\Entity\CredentialSubmissionVersion;
use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\CredentialSubmissionRepository;
use App\Repository\CredentialSubmissionVersionRepository;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/reviewer/credentials')]
final class CredentialReviewController extends AbstractController
{
    /** @var array<string, string> */
    private const ACTION_TO_STATUS = [
        'approve' => CredentialSubmission::STATUS_APPROVED,
        'reject' => CredentialSubmission::STATUS_REJECTED,
        'request_resubmission' => CredentialSubmission::STATUS_RESUBMISSION_REQUIRED,
    ];

    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly CredentialSubmissionRepository $submissions,
        private readonly CredentialSubmissionVersionRepository $versions,
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/queue', name: 'api_reviewer_credentials_queue', methods: ['GET'])]
    public function queue(Request $request): JsonResponse
    {
        $reviewer = $this->currentReviewer();

        $statusRaw = strtoupper(trim((string) $request->query->get('status', CredentialSubmission::STATUS_PENDING_REVIEW)));
        $status = $this->parseQueueStatus($statusRaw);

        $items = $this->submissions->findReviewQueue($status);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'statusFilter' => $statusRaw,
            'queue' => array_map(
                fn (CredentialSubmission $submission): array => $this->serializeSubmission($submission, false),
                $items,
            ),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/{submissionId}', name: 'api_reviewer_credentials_detail', methods: ['GET'])]
    public function detail(int $submissionId, Request $request): JsonResponse
    {
        $this->currentReviewer();

        $submission = $this->submissions->find($submissionId);
        if (!$submission instanceof CredentialSubmission) {
            return ApiResponse::error('NOT_FOUND', 'Credential submission not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'submission' => $this->serializeSubmission($submission, true),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/{submissionId}/decision', name: 'api_reviewer_credentials_decision', methods: ['POST'])]
    public function decision(int $submissionId, Request $request): JsonResponse
    {
        $reviewer = $this->currentReviewer();

        $payload = $this->jsonBodyParser->parse($request);
        $this->validateDecisionPayload($payload);

        $action = strtolower(trim((string) $payload['action']));
        $comment = isset($payload['comment']) ? trim((string) $payload['comment']) : '';

        if (!array_key_exists($action, self::ACTION_TO_STATUS)) {
            throw new ApiValidationException('Decision action is invalid.', [
                ['field' => 'action', 'issue' => 'invalid_option'],
            ]);
        }

        if (in_array($action, ['reject', 'request_resubmission'], true) && $comment === '') {
            throw new ApiValidationException('Comment is required for reject/resubmission decisions.', [
                ['field' => 'comment', 'issue' => 'required'],
            ]);
        }

        $submission = $this->submissions->find($submissionId);
        if (!$submission instanceof CredentialSubmission) {
            return ApiResponse::error('NOT_FOUND', 'Credential submission not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($submission->getStatus() !== CredentialSubmission::STATUS_PENDING_REVIEW) {
            return ApiResponse::error(
                'INVALID_STATE',
                'Only pending submissions can be decided.',
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $latest = $this->versions->findLatestBySubmission($submission);
        if (!$latest instanceof CredentialSubmissionVersion) {
            return ApiResponse::error('INVALID_STATE', 'Submission has no credential version.', JsonResponse::HTTP_CONFLICT);
        }

        $newStatus = self::ACTION_TO_STATUS[$action];
        $latest->applyDecision($newStatus, $comment !== '' ? $comment : null, $reviewer->getUsername());
        $submission->applyDecision($newStatus);

        $this->entityManager->flush();

        $this->auditLogger->log('credential.review_decision', $reviewer->getUsername(), [
            'submissionId' => $submission->getId(),
            'versionId' => $latest->getId(),
            'action' => $action,
            'status' => $newStatus,
            'hasComment' => $comment !== '',
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'submission' => $this->serializeSubmission($submission, true),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    /** @param array<string, mixed> $payload */
    private function validateDecisionPayload(array $payload): void
    {
        $violations = $this->validator->validate($payload, new Assert\Collection(
            fields: [
                'action' => [new Assert\NotBlank(), new Assert\Type('string')],
                'comment' => [new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 2000)])],
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

        throw new ApiValidationException('Decision payload is invalid.', $details);
    }

    private function parseQueueStatus(string $statusRaw): ?string
    {
        if ($statusRaw === 'ALL') {
            return null;
        }

        $allowed = [
            CredentialSubmission::STATUS_PENDING_REVIEW,
            CredentialSubmission::STATUS_APPROVED,
            CredentialSubmission::STATUS_REJECTED,
            CredentialSubmission::STATUS_RESUBMISSION_REQUIRED,
        ];

        if (!in_array($statusRaw, $allowed, true)) {
            throw new ApiValidationException('Queue status filter is invalid.', [
                ['field' => 'status', 'issue' => 'invalid_option'],
            ]);
        }

        return $statusRaw;
    }

    /** @return array<string, mixed> */
    private function serializeSubmission(CredentialSubmission $submission, bool $includeVersions): array
    {
        $profile = $submission->getPractitionerProfile();
        $latest = $this->versions->findLatestBySubmission($submission);
        $versions = $includeVersions ? $this->versions->findBySubmission($submission) : [];

        return [
            'id' => $submission->getId(),
            'label' => $submission->getLabel(),
            'status' => $submission->getStatus(),
            'currentVersionNumber' => $submission->getCurrentVersionNumber(),
            'updatedAtUtc' => $submission->getUpdatedAtUtc()->format(DATE_ATOM),
            'practitioner' => [
                'username' => $profile->getUser()->getUsername(),
                'lawyerFullName' => $profile->getLawyerFullName(),
                'firmName' => $profile->getFirmName(),
                'barJurisdiction' => $profile->getBarJurisdiction(),
                'licenseNumberMasked' => $profile->getLicenseNumberMasked(),
            ],
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

    private function currentReviewer(): User
    {
        $user = $this->authSession->currentUser();
        $this->authorization->assertPermission($user, 'credential.review');

        if (!$user instanceof User) {
            throw new \LogicException('Authenticated reviewer is required.');
        }

        return $user;
    }
}
