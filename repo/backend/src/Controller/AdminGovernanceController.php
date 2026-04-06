<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminAnomalyAlert;
use App\Entity\CredentialSubmission;
use App\Entity\CredentialSubmissionVersion;
use App\Entity\PractitionerProfile;
use App\Entity\QuestionBankEntry;
use App\Entity\QuestionBankEntryVersion;
use App\Entity\SensitiveAccessLog;
use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\AuditLogRepository;
use App\Repository\CredentialSubmissionRepository;
use App\Repository\CredentialSubmissionVersionRepository;
use App\Repository\PractitionerProfileRepository;
use App\Repository\QuestionBankEntryRepository;
use App\Repository\QuestionBankEntryVersionRepository;
use App\Repository\SensitiveAccessLogRepository;
use App\Repository\UserRepository;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Security\FieldEncryptionService;
use App\Service\AdminGovernanceService;
use App\Service\AuditLogger;
use App\Service\GovernanceLogRetentionService;
use App\Service\SensitiveAccessLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/governance')]
final class AdminGovernanceController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly AuditLogRepository $auditLogs,
        private readonly SensitiveAccessLogRepository $sensitiveLogs,
        private readonly SensitiveAccessLogger $sensitiveAccessLogger,
        private readonly FieldEncryptionService $encryption,
        private readonly PractitionerProfileRepository $profiles,
        private readonly CredentialSubmissionRepository $credentialSubmissions,
        private readonly CredentialSubmissionVersionRepository $credentialVersions,
        private readonly QuestionBankEntryRepository $questionEntries,
        private readonly QuestionBankEntryVersionRepository $questionVersions,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminGovernanceService $governance,
        private readonly AuditLogger $auditLogger,
        private readonly GovernanceLogRetentionService $retention,
    ) {
    }

    #[Route('/audit-logs', name: 'api_admin_governance_audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.audit.read');

        $actor = trim((string) $request->query->get('actor', ''));
        $actionContains = trim((string) $request->query->get('actionContains', ''));
        $sinceHours = (int) $request->query->get('sinceHours', 72);
        $limit = (int) $request->query->get('limit', 120);

        $sinceUtc = null;
        if ($sinceHours > 0) {
            $sinceUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('-%d hours', min($sinceHours, 720)));
        }

        $logs = $this->auditLogs->listRecent(
            $actor !== '' ? $actor : null,
            $actionContains !== '' ? $actionContains : null,
            $sinceUtc,
            $limit,
        );

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'immutable' => true,
            'retentionPolicy' => $this->retention->policyMetadata(),
            'logs' => array_map(fn ($entry): array => [
                'id' => $entry->getId(),
                'actorUsername' => $entry->getActorUsername(),
                'actionType' => $entry->getActionType(),
                'payload' => $entry->getPayload(),
                'createdAtUtc' => $entry->getCreatedAtUtc()->format(DATE_ATOM),
            ], $logs),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/sensitive-access-logs', name: 'api_admin_governance_sensitive_logs', methods: ['GET'])]
    public function sensitiveAccessLogs(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.audit.read');

        $actor = trim((string) $request->query->get('actor', ''));
        $entityType = trim((string) $request->query->get('entityType', ''));
        $sinceHours = (int) $request->query->get('sinceHours', 72);
        $limit = (int) $request->query->get('limit', 120);

        $sinceUtc = null;
        if ($sinceHours > 0) {
            $sinceUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('-%d hours', min($sinceHours, 720)));
        }

        $logs = $this->sensitiveLogs->listRecent(
            $actor !== '' ? $actor : null,
            $entityType !== '' ? $entityType : null,
            $sinceUtc,
            $limit,
        );

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'retentionPolicy' => $this->retention->policyMetadata(),
            'logs' => array_map(fn (SensitiveAccessLog $entry): array => [
                'id' => $entry->getId(),
                'actorUsername' => $entry->getActorUsername(),
                'entityType' => $entry->getEntityType(),
                'entityId' => $entry->getEntityId(),
                'fieldName' => $entry->getFieldName(),
                'reason' => $entry->getReason(),
                'createdAtUtc' => $entry->getCreatedAtUtc()->format(DATE_ATOM),
            ], $logs),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/sensitive/practitioner-profiles/{profileId}/license', name: 'api_admin_governance_sensitive_license_read', methods: ['POST'])]
    public function readSensitiveLicense(int $profileId, Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.audit.read');

        $payload = $this->jsonBodyParser->parse($request);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) < 8 || mb_strlen($reason) > 255) {
            throw new ApiValidationException('reason is required and must be 8-255 characters.', [
                ['field' => 'reason', 'issue' => 'required_length_8_255'],
            ]);
        }

        $profile = $this->profiles->find($profileId);
        if (!$profile instanceof PractitionerProfile) {
            return ApiResponse::error('NOT_FOUND', 'Practitioner profile not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $licenseNumber = $this->encryption->decrypt($profile->encryptedLicensePayload());

        $this->sensitiveAccessLogger->log(
            $admin->getUsername(),
            'practitioner_profile',
            (string) ($profile->getId() ?? ''),
            'license_number',
            $reason,
        );

        $this->auditLogger->log('admin.sensitive_field_read', $admin->getUsername(), [
            'entityType' => 'practitioner_profile',
            'entityId' => $profile->getId(),
            'fieldName' => 'license_number',
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'profileId' => $profile->getId(),
            'lawyerFullName' => $profile->getLawyerFullName(),
            'firmName' => $profile->getFirmName(),
            'barJurisdiction' => $profile->getBarJurisdiction(),
            'licenseNumberMasked' => $profile->getLicenseNumberMasked(),
            'licenseNumber' => $licenseNumber,
            'reason' => $reason,
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/anomalies', name: 'api_admin_governance_anomalies_list', methods: ['GET'])]
    public function listAnomalies(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.audit.read');

        $statusRaw = strtoupper(trim((string) $request->query->get('status', 'OPEN')));
        $status = null;
        if ($statusRaw !== '' && $statusRaw !== 'ALL') {
            if (!in_array($statusRaw, [AdminAnomalyAlert::STATUS_OPEN, AdminAnomalyAlert::STATUS_ACKNOWLEDGED, AdminAnomalyAlert::STATUS_RESOLVED], true)) {
                throw new ApiValidationException('status filter is invalid.', [
                    ['field' => 'status', 'issue' => 'invalid_option'],
                ]);
            }
            $status = $statusRaw;
        }

        $alerts = $this->governance->listAnomalyAlerts($status, 160);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'statusFilter' => $statusRaw,
            'alerts' => array_map(fn (AdminAnomalyAlert $alert): array => $this->serializeAlert($alert), $alerts),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/anomalies/refresh', name: 'api_admin_governance_anomalies_refresh', methods: ['POST'])]
    public function refreshAnomalies(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.anomaly.manage');

        $alerts = $this->governance->refreshAnomalyAlerts();
        $this->auditLogger->log('admin.anomaly.refresh', $admin->getUsername(), [
            'alertCount' => count($alerts),
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'alerts' => array_map(fn (AdminAnomalyAlert $alert): array => $this->serializeAlert($alert), $alerts),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/anomalies/{alertId}/acknowledge', name: 'api_admin_governance_anomalies_acknowledge', methods: ['POST'])]
    public function acknowledgeAnomaly(int $alertId, Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.anomaly.manage');

        $target = $this->entityManager->find(AdminAnomalyAlert::class, $alertId);

        if (!$target instanceof AdminAnomalyAlert) {
            return ApiResponse::error('NOT_FOUND', 'Anomaly alert not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->jsonBodyParser->parse($request);
        $note = trim((string) ($payload['note'] ?? ''));
        if ($note === '' || mb_strlen($note) < 8 || mb_strlen($note) > 2000) {
            throw new ApiValidationException('note is required and must be 8-2000 characters.', [
                ['field' => 'note', 'issue' => 'required_length_8_2000'],
            ]);
        }

        $this->governance->acknowledgeAlert($target, $admin->getUsername(), $note);
        $this->auditLogger->log('admin.anomaly.acknowledged', $admin->getUsername(), [
            'alertId' => $target->getId(),
            'alertType' => $target->getAlertType(),
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'alert' => $this->serializeAlert($target),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/rollback/credential-submissions', name: 'api_admin_governance_rollback_credentials_catalog', methods: ['GET'])]
    public function rollbackCredentialCatalog(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.rollback');

        $submissions = $this->credentialSubmissions->listRecentForAdmin(40);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'submissions' => array_map(fn (CredentialSubmission $submission): array => $this->serializeCredentialSubmissionForRollback($submission), $submissions),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/rollback/question-entries', name: 'api_admin_governance_rollback_questions_catalog', methods: ['GET'])]
    public function rollbackQuestionCatalog(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.rollback');

        $entries = $this->questionEntries->listRecentForAdmin(40);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'entries' => array_map(fn (QuestionBankEntry $entry): array => $this->serializeQuestionEntryForRollback($entry), $entries),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/rollback/credentials', name: 'api_admin_governance_rollback_credentials_execute', methods: ['POST'])]
    public function rollbackCredential(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.rollback');

        $payload = $this->jsonBodyParser->parse($request);
        $submissionId = $this->requiredInt($payload['submissionId'] ?? null, 'submissionId');
        $targetVersionNumber = $this->requiredInt($payload['targetVersionNumber'] ?? null, 'targetVersionNumber');
        $justification = $this->stepUpAndJustification($admin, $payload);

        $submission = $this->credentialSubmissions->find($submissionId);
        if (!$submission instanceof CredentialSubmission) {
            return ApiResponse::error('NOT_FOUND', 'Credential submission not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $targetVersion = $this->credentialVersions->findOneBySubmissionAndVersionNumber($submission, $targetVersionNumber);
        if (!$targetVersion instanceof CredentialSubmissionVersion) {
            return ApiResponse::error('NOT_FOUND', 'Target credential version not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $newVersion = $this->governance->rollbackCredentialSubmission($submission, $targetVersion, $admin->getUsername(), $justification);
        } catch (\DomainException $e) {
            return ApiResponse::error('INVALID_ROLLBACK_TARGET', $e->getMessage(), JsonResponse::HTTP_CONFLICT);
        }

        $this->auditLogger->log('admin.rollback.credential', $admin->getUsername(), [
            'submissionId' => $submission->getId(),
            'rolledBackFromVersion' => $targetVersionNumber,
            'newVersionNumber' => $newVersion->getVersionNumber(),
            'justification' => $justification,
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'submission' => $this->serializeCredentialSubmissionForRollback($submission),
            'rolledBackFromVersion' => $targetVersionNumber,
            'newVersionNumber' => $newVersion->getVersionNumber(),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/rollback/questions', name: 'api_admin_governance_rollback_questions_execute', methods: ['POST'])]
    public function rollbackQuestion(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.rollback');

        $payload = $this->jsonBodyParser->parse($request);
        $entryId = $this->requiredInt($payload['entryId'] ?? null, 'entryId');
        $targetVersionNumber = $this->requiredInt($payload['targetVersionNumber'] ?? null, 'targetVersionNumber');
        $justification = $this->stepUpAndJustification($admin, $payload);

        $entry = $this->questionEntries->find($entryId);
        if (!$entry instanceof QuestionBankEntry) {
            return ApiResponse::error('NOT_FOUND', 'Question entry not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $targetVersion = $this->questionVersions->findOneByEntryAndVersionNumber($entry, $targetVersionNumber);
        if (!$targetVersion instanceof QuestionBankEntryVersion) {
            return ApiResponse::error('NOT_FOUND', 'Target question version not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $newVersion = $this->governance->rollbackQuestionEntry($entry, $targetVersion, $admin->getUsername(), $justification);
        } catch (\DomainException $e) {
            return ApiResponse::error('INVALID_ROLLBACK_TARGET', $e->getMessage(), JsonResponse::HTTP_CONFLICT);
        }

        $this->auditLogger->log('admin.rollback.question', $admin->getUsername(), [
            'entryId' => $entry->getId(),
            'rolledBackFromVersion' => $targetVersionNumber,
            'newVersionNumber' => $newVersion->getVersionNumber(),
            'justification' => $justification,
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'entry' => $this->serializeQuestionEntryForRollback($entry),
            'rolledBackFromVersion' => $targetVersionNumber,
            'newVersionNumber' => $newVersion->getVersionNumber(),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/users/password-reset', name: 'api_admin_governance_password_reset', methods: ['POST'])]
    public function passwordReset(Request $request): JsonResponse
    {
        $admin = $this->currentAdmin();
        $this->authorization->assertPermission($admin, 'admin.passwordReset');

        $payload = $this->jsonBodyParser->parse($request);
        $targetUsername = trim((string) ($payload['targetUsername'] ?? ''));
        $newPassword = (string) ($payload['newPassword'] ?? '');

        if ($targetUsername === '' || mb_strlen($targetUsername) > 180) {
            throw new ApiValidationException('targetUsername is required and must be 180 characters or fewer.', [
                ['field' => 'targetUsername', 'issue' => 'required_max_180'],
            ]);
        }

        if (mb_strlen($newPassword) < 12 || mb_strlen($newPassword) > 255) {
            throw new ApiValidationException('newPassword must be between 12 and 255 characters.', [
                ['field' => 'newPassword', 'issue' => 'length_12_255'],
            ]);
        }

        $justification = $this->stepUpAndJustification($admin, $payload);

        $target = $this->users->findOneByUsername($targetUsername);
        if (!$target instanceof User) {
            return ApiResponse::error('NOT_FOUND', 'Target user not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($target->getUsername() === $admin->getUsername()) {
            return ApiResponse::error('INVALID_OPERATION', 'System-admin self password reset through governance endpoint is not allowed.', JsonResponse::HTTP_CONFLICT);
        }

        $target->setPasswordHash($this->passwordHasher->hashPassword($target, $newPassword));
        $target->clearLockoutState();
        $this->entityManager->flush();

        $this->auditLogger->log('admin.password_reset', $admin->getUsername(), [
            'targetUsername' => $target->getUsername(),
            'justification' => $justification,
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'status' => 'PASSWORD_RESET',
            'targetUsername' => $target->getUsername(),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    private function currentAdmin(): User
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is required.');
        }

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function stepUpAndJustification(User $actor, array $payload): string
    {
        $stepUpPassword = (string) ($payload['stepUpPassword'] ?? '');
        $justification = trim((string) ($payload['justificationNote'] ?? ''));

        if ($stepUpPassword === '') {
            throw new ApiValidationException('stepUpPassword is required for high-risk admin action.', [
                ['field' => 'stepUpPassword', 'issue' => 'required'],
            ]);
        }

        if ($justification === '' || mb_strlen($justification) < 8 || mb_strlen($justification) > 2000) {
            throw new ApiValidationException('justificationNote is required and must be 8-2000 characters.', [
                ['field' => 'justificationNote', 'issue' => 'required_length_8_2000'],
            ]);
        }

        if (!$this->passwordHasher->isPasswordValid($actor, $stepUpPassword)) {
            $this->auditLogger->log('admin.step_up_failed', $actor->getUsername(), [
                'action' => 'governance_high_risk',
            ]);

            throw new ApiValidationException('Step-up authentication failed.', [
                ['field' => 'stepUpPassword', 'issue' => 'invalid_credentials'],
            ]);
        }

        return $justification;
    }

    private function requiredInt(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            throw new ApiValidationException(sprintf('%s must be an integer.', $field), [
                ['field' => $field, 'issue' => 'must_be_integer'],
            ]);
        }

        $int = (int) $value;
        if ($int <= 0) {
            throw new ApiValidationException(sprintf('%s must be greater than 0.', $field), [
                ['field' => $field, 'issue' => 'must_be_positive'],
            ]);
        }

        return $int;
    }

    /** @return array<string, mixed> */
    private function serializeCredentialSubmissionForRollback(CredentialSubmission $submission): array
    {
        $profile = $submission->getPractitionerProfile();
        $versions = $this->credentialVersions->findBySubmission($submission);

        return [
            'id' => $submission->getId(),
            'label' => $submission->getLabel(),
            'status' => $submission->getStatus(),
            'currentVersionNumber' => $submission->getCurrentVersionNumber(),
            'updatedAtUtc' => $submission->getUpdatedAtUtc()->format(DATE_ATOM),
            'practitionerProfile' => [
                'id' => $profile->getId(),
                'username' => $profile->getUser()->getUsername(),
                'lawyerFullName' => $profile->getLawyerFullName(),
                'firmName' => $profile->getFirmName(),
                'barJurisdiction' => $profile->getBarJurisdiction(),
                'licenseNumberMasked' => $profile->getLicenseNumberMasked(),
            ],
            'versions' => array_map(fn (CredentialSubmissionVersion $version): array => [
                'id' => $version->getId(),
                'versionNumber' => $version->getVersionNumber(),
                'reviewStatus' => $version->getReviewStatus(),
                'reviewComment' => $version->getReviewComment(),
                'reviewedAtUtc' => $version->getReviewedAtUtc()?->format(DATE_ATOM),
                'uploadedAtUtc' => $version->getUploadedAtUtc()->format(DATE_ATOM),
                'originalFilename' => $version->getOriginalFilename(),
            ], $versions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeQuestionEntryForRollback(QuestionBankEntry $entry): array
    {
        $versions = $this->questionVersions->findByEntry($entry);

        return [
            'id' => $entry->getId(),
            'title' => $entry->getTitle(),
            'status' => $entry->getStatus(),
            'currentVersionNumber' => $entry->getCurrentVersionNumber(),
            'updatedAtUtc' => $entry->getUpdatedAtUtc()->format(DATE_ATOM),
            'versions' => array_map(fn (QuestionBankEntryVersion $version): array => [
                'id' => $version->getId(),
                'versionNumber' => $version->getVersionNumber(),
                'title' => $version->getTitle(),
                'difficulty' => $version->getDifficulty(),
                'tags' => $version->getTags(),
                'createdByUsername' => $version->getCreatedByUsername(),
                'createdAtUtc' => $version->getCreatedAtUtc()->format(DATE_ATOM),
                'changeNote' => $version->getChangeNote(),
            ], $versions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAlert(AdminAnomalyAlert $alert): array
    {
        return [
            'id' => $alert->getId(),
            'alertType' => $alert->getAlertType(),
            'scopeKey' => $alert->getScopeKey(),
            'status' => $alert->getStatus(),
            'payload' => $alert->getPayload(),
            'createdAtUtc' => $alert->getCreatedAtUtc()->format(DATE_ATOM),
            'updatedAtUtc' => $alert->getUpdatedAtUtc()->format(DATE_ATOM),
            'lastDetectedAtUtc' => $alert->getLastDetectedAtUtc()->format(DATE_ATOM),
            'acknowledgedAtUtc' => $alert->getAcknowledgedAtUtc()?->format(DATE_ATOM),
            'acknowledgedByUsername' => $alert->getAcknowledgedByUsername(),
            'acknowledgementNote' => $alert->getAcknowledgementNote(),
            'resolvedAtUtc' => $alert->getResolvedAtUtc()?->format(DATE_ATOM),
        ];
    }
}
