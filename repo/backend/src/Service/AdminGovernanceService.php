<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminAnomalyAlert;
use App\Entity\CredentialSubmission;
use App\Entity\CredentialSubmissionVersion;
use App\Entity\QuestionBankEntry;
use App\Entity\QuestionBankEntryVersion;
use App\Repository\AdminAnomalyAlertRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AdminGovernanceService
{
    public const ALERT_TYPE_CREDENTIAL_REJECTION_SPIKE = 'CREDENTIAL_REJECTION_SPIKE';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAnomalyAlertRepository $alerts,
    ) {
    }

    /** @return list<AdminAnomalyAlert> */
    public function refreshAnomalyAlerts(): array
    {
        $rows = $this->findCredentialRejectionSpikeCandidates();
        $activeScopeKeys = [];

        foreach ($rows as $row) {
            $firmName = (string) ($row['firmName'] ?? 'unknown-firm');
            $rejectedCount = (int) ($row['rejectedCount'] ?? 0);
            $scopeKey = 'firm:'.$firmName;
            $activeScopeKeys[$scopeKey] = true;

            $payload = [
                'firmName' => $firmName,
                'thresholdRejectedCount' => 5,
                'windowHours' => 24,
                'rejectedCount' => $rejectedCount,
            ];

            $existing = $this->alerts->findOneByTypeAndScopeKey(self::ALERT_TYPE_CREDENTIAL_REJECTION_SPIKE, $scopeKey);
            if (!$existing instanceof AdminAnomalyAlert) {
                $existing = new AdminAnomalyAlert(self::ALERT_TYPE_CREDENTIAL_REJECTION_SPIKE, $scopeKey, $payload);
                $this->entityManager->persist($existing);
            }

            $existing->refreshOpen($payload);
        }

        foreach ($this->alerts->findActiveByType(self::ALERT_TYPE_CREDENTIAL_REJECTION_SPIKE) as $alert) {
            if (!isset($activeScopeKeys[$alert->getScopeKey()])) {
                $alert->resolve();
            }
        }

        $this->entityManager->flush();

        return $this->alerts->listRecent(null, 120);
    }

    /** @return list<AdminAnomalyAlert> */
    public function listAnomalyAlerts(?string $status, int $limit = 120): array
    {
        return $this->alerts->listRecent($status, $limit);
    }

    public function acknowledgeAlert(AdminAnomalyAlert $alert, string $actorUsername, string $acknowledgementNote): void
    {
        $alert->acknowledge($actorUsername, $acknowledgementNote);
        $this->entityManager->flush();
    }

    public function rollbackCredentialSubmission(
        CredentialSubmission $submission,
        CredentialSubmissionVersion $targetVersion,
        string $actorUsername,
        string $justification,
    ): CredentialSubmissionVersion {
        if ($targetVersion->getVersionNumber() === $submission->getCurrentVersionNumber()) {
            throw new \DomainException('Target credential version is already active.');
        }

        $nextVersionNumber = $submission->getCurrentVersionNumber() + 1;

        $newVersion = new CredentialSubmissionVersion(
            $submission,
            $nextVersionNumber,
            $targetVersion->getStoragePath(),
            $targetVersion->getOriginalFilename(),
            $targetVersion->getMimeType(),
            $targetVersion->getSizeBytes(),
            $actorUsername,
        );

        $newVersion->applyDecision(
            $targetVersion->getReviewStatus(),
            sprintf('Rollback copy of version %d. Justification: %s', $targetVersion->getVersionNumber(), $justification),
            $actorUsername,
        );

        $submission->markPendingReview($nextVersionNumber);
        $submission->applyDecision($targetVersion->getReviewStatus());

        $this->entityManager->persist($newVersion);
        $this->entityManager->flush();

        return $newVersion;
    }

    public function rollbackQuestionEntry(
        QuestionBankEntry $entry,
        QuestionBankEntryVersion $targetVersion,
        string $actorUsername,
        string $justification,
    ): QuestionBankEntryVersion {
        if ($targetVersion->getVersionNumber() === $entry->getCurrentVersionNumber()) {
            throw new \DomainException('Target question version is already active.');
        }

        $entry->rollbackContent(
            $targetVersion->getTitle(),
            $targetVersion->getPlainTextContent(),
            $targetVersion->getRichTextContent(),
            $targetVersion->getTags(),
            $targetVersion->getDifficulty(),
            $targetVersion->getFormulas(),
            $targetVersion->getEmbeddedImages(),
            $actorUsername,
        );

        $newVersion = new QuestionBankEntryVersion(
            $entry,
            $entry->getCurrentVersionNumber(),
            sprintf('Rollback copy of version %d. Justification: %s', $targetVersion->getVersionNumber(), $justification),
            $actorUsername,
        );

        $this->entityManager->persist($newVersion);
        $this->entityManager->flush();

        return $newVersion;
    }

    /** @return list<array{firmName: string, rejectedCount: int}> */
    private function findCredentialRejectionSpikeCandidates(): array
    {
        $connection = $this->entityManager->getConnection();
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-24 hours')->format('Y-m-d H:i:s');

        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT p.firm_name AS firmName, COUNT(v.id) AS rejectedCount
             FROM credential_submission_versions v
             INNER JOIN credential_submissions s ON s.id = v.submission_id
             INNER JOIN practitioner_profiles p ON p.id = s.practitioner_profile_id
             WHERE v.review_status = :rejectedStatus
               AND v.reviewed_at_utc IS NOT NULL
               AND v.reviewed_at_utc >= :sinceUtc
             GROUP BY p.firm_name
             HAVING COUNT(v.id) > 5
             ORDER BY rejectedCount DESC',
            [
                'rejectedStatus' => CredentialSubmission::STATUS_REJECTED,
                'sinceUtc' => $since,
            ],
        );

        $normalized = [];
        foreach ($rows as $row) {
            $firmName = trim((string) ($row['firmName'] ?? ''));
            $rejectedCount = (int) ($row['rejectedCount'] ?? 0);

            if ($firmName === '' || $rejectedCount <= 5) {
                continue;
            }

            $normalized[] = [
                'firmName' => $firmName,
                'rejectedCount' => $rejectedCount,
            ];
        }

        return $normalized;
    }
}
