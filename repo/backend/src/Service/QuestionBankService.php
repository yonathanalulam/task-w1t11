<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuestionBankAsset;
use App\Entity\QuestionBankEntry;
use App\Entity\QuestionBankEntryVersion;
use App\Exception\QuestionBankFlowException;
use App\Repository\QuestionBankEntryRepository;
use App\Repository\QuestionBankEntryVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class QuestionBankService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuestionBankEntryRepository $entries,
        private readonly QuestionBankEntryVersionRepository $versions,
    ) {
    }

    /**
     * @param list<string> $tags
     * @param list<array<string, mixed>> $formulas
     * @param list<QuestionBankAsset> $assets
     */
    public function createEntry(
        string $title,
        string $plainTextContent,
        string $richTextContent,
        array $tags,
        int $difficulty,
        array $formulas,
        array $assets,
        string $actorUsername,
        ?string $changeNote,
    ): QuestionBankEntry {
        $entry = new QuestionBankEntry(
            $title,
            $plainTextContent,
            $richTextContent,
            $tags,
            $difficulty,
            $this->normalizeFormulaPayload($formulas),
            $this->serializeAssets($assets),
            $actorUsername,
        );

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->createVersion($entry, 1, $changeNote, $actorUsername);

        return $entry;
    }

    /**
     * @param list<string> $tags
     * @param list<array<string, mixed>> $formulas
     * @param list<QuestionBankAsset> $assets
     */
    public function editEntry(
        QuestionBankEntry $entry,
        string $title,
        string $plainTextContent,
        string $richTextContent,
        array $tags,
        int $difficulty,
        array $formulas,
        array $assets,
        string $actorUsername,
        ?string $changeNote,
    ): QuestionBankEntry {
        if ($entry->getStatus() === QuestionBankEntry::STATUS_OFFLINE) {
            throw new QuestionBankFlowException(
                'OFFLINE_ENTRY_LOCKED',
                Response::HTTP_CONFLICT,
                'Offline entries cannot be edited. Create a replacement draft instead.',
            );
        }

        $entry->edit(
            $title,
            $plainTextContent,
            $richTextContent,
            $tags,
            $difficulty,
            $this->normalizeFormulaPayload($formulas),
            $this->serializeAssets($assets),
            $actorUsername,
        );

        $this->entityManager->flush();
        $this->createVersion($entry, $entry->getCurrentVersionNumber(), $changeNote, $actorUsername);

        return $entry;
    }

    /** @return array<int, array<string, mixed>> */
    public function publishEntry(QuestionBankEntry $entry, string $actorUsername, bool $overrideDuplicateReview): array
    {
        $duplicates = $this->findSimilarityMatches($entry);
        $highSimilarity = array_filter($duplicates, static fn (array $match): bool => (float) ($match['similarity'] ?? 0.0) >= 0.82);

        if ($highSimilarity !== [] && !$overrideDuplicateReview) {
            $entry->markDuplicateReviewRequired($actorUsername);
            $this->entityManager->flush();

            throw new QuestionBankFlowException(
                'DUPLICATE_REVIEW_REQUIRED',
                Response::HTTP_CONFLICT,
                'High textual similarity detected. Duplicate review is required before publish.',
                array_map(
                    static fn (array $match): array => [
                        'entryId' => $match['entryId'],
                        'title' => $match['title'],
                        'similarity' => $match['similarity'],
                    ],
                    array_values($highSimilarity),
                ),
            );
        }

        $entry->publish($actorUsername, $overrideDuplicateReview && $highSimilarity !== []);
        $this->entityManager->flush();

        return array_values($highSimilarity);
    }

    public function offlineEntry(QuestionBankEntry $entry, string $actorUsername): void
    {
        if ($entry->getStatus() === QuestionBankEntry::STATUS_OFFLINE) {
            throw new QuestionBankFlowException(
                'ALREADY_OFFLINE',
                Response::HTTP_CONFLICT,
                'Question is already offline.',
            );
        }

        $entry->offline($actorUsername);
        $this->entityManager->flush();
    }

    /** @return list<QuestionBankEntry> */
    public function listEntries(string $statusFilter): array
    {
        return $this->entries->listByStatus($statusFilter);
    }

    /** @return list<QuestionBankEntryVersion> */
    public function versionsForEntry(QuestionBankEntry $entry): array
    {
        return $this->versions->findByEntry($entry);
    }

    /** @return array<int, array<string, mixed>> */
    public function findSimilarityMatches(QuestionBankEntry $entry): array
    {
        $candidates = $this->entries->findForSimilarityScan((int) ($entry->getId() ?? 0));
        if ($candidates === []) {
            return [];
        }

        $reference = $this->normalizedTokenSet($entry->getTitle().' '.$entry->getPlainTextContent().' '.strip_tags($entry->getRichTextContent()));
        if ($reference === []) {
            return [];
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            $candidateTokens = $this->normalizedTokenSet($candidate->getTitle().' '.$candidate->getPlainTextContent().' '.strip_tags($candidate->getRichTextContent()));
            if ($candidateTokens === []) {
                continue;
            }

            $similarity = $this->jaccard($reference, $candidateTokens);
            if ($similarity < 0.55) {
                continue;
            }

            $matches[] = [
                'entryId' => $candidate->getId(),
                'title' => $candidate->getTitle(),
                'status' => $candidate->getStatus(),
                'similarity' => round($similarity, 4),
            ];
        }

        usort($matches, static fn (array $a, array $b): int => (float) $b['similarity'] <=> (float) $a['similarity']);

        return array_slice($matches, 0, 8);
    }

    /** @param list<array<string, mixed>> $formulas @return list<array<string, mixed>> */
    private function normalizeFormulaPayload(array $formulas): array
    {
        $normalized = [];
        foreach ($formulas as $index => $formula) {
            if (!is_array($formula)) {
                continue;
            }

            $expression = trim((string) ($formula['expression'] ?? ''));
            if ($expression === '') {
                continue;
            }

            $normalized[] = [
                'id' => sprintf('f_%d_%s', $index, substr(hash('sha1', $expression), 0, 8)),
                'expression' => $expression,
                'label' => trim((string) ($formula['label'] ?? '')),
            ];
        }

        return $normalized;
    }

    /** @param list<QuestionBankAsset> $assets @return list<array<string, mixed>> */
    private function serializeAssets(array $assets): array
    {
        $serialized = [];
        foreach ($assets as $asset) {
            $assetId = $asset->getId();
            if ($assetId === null) {
                continue;
            }

            $serialized[] = [
                'assetId' => $assetId,
                'filename' => $asset->getOriginalFilename(),
                'mimeType' => $asset->getMimeType(),
                'sizeBytes' => $asset->getSizeBytes(),
                'downloadPath' => sprintf('/api/question-bank/assets/%d/download', $assetId),
            ];
        }

        return $serialized;
    }

    private function createVersion(QuestionBankEntry $entry, int $versionNumber, ?string $changeNote, string $actorUsername): void
    {
        $version = new QuestionBankEntryVersion($entry, $versionNumber, $changeNote, $actorUsername);
        $this->entityManager->persist($version);
        $this->entityManager->flush();
    }

    /** @return array<string, true> */
    private function normalizedTokenSet(string $text): array
    {
        $clean = mb_strtolower($text);
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', (string) $clean);
        $clean = trim((string) $clean);

        if ($clean === '') {
            return [];
        }

        $tokens = explode(' ', $clean);
        $set = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }
            $set[$token] = true;
        }

        return $set;
    }

    /** @param array<string, true> $a @param array<string, true> $b */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = array_intersect_key($a, $b);
        $union = $a + $b;
        if (count($union) === 0) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }
}
