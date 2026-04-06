<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuestionBankAsset;
use App\Entity\QuestionBankEntry;
use App\Entity\QuestionBankEntryVersion;
use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Exception\QuestionBankFlowException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\QuestionBankAssetRepository;
use App\Repository\QuestionBankEntryRepository;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Service\AuditLogger;
use App\Service\QuestionBankAssetStorageService;
use App\Service\QuestionBankSpreadsheetService;
use App\Service\QuestionBankService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/question-bank')]
final class QuestionBankController extends AbstractController
{
    private const MAX_IMAGE_BYTES = 5_242_880;
    private const MAX_IMPORT_BYTES = 8_388_608;

    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly QuestionBankService $questions,
        private readonly QuestionBankEntryRepository $entries,
        private readonly QuestionBankAssetRepository $assets,
        private readonly QuestionBankAssetStorageService $assetStorage,
        private readonly QuestionBankSpreadsheetService $spreadsheet,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/questions', name: 'api_question_bank_list', methods: ['GET'])]
    public function listQuestions(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $statusFilter = strtoupper(trim((string) $request->query->get('status', 'ALL')));
        if (!in_array($statusFilter, ['ALL', QuestionBankEntry::STATUS_DRAFT, QuestionBankEntry::STATUS_PUBLISHED, QuestionBankEntry::STATUS_OFFLINE], true)) {
            throw new ApiValidationException('Invalid status filter.', [['field' => 'status', 'issue' => 'invalid']]);
        }

        $entries = $this->questions->listEntries($statusFilter);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'statusFilter' => $statusFilter,
            'entries' => array_map(fn (QuestionBankEntry $entry): array => $this->serializeEntrySummary($entry), $entries),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/questions/{entryId}', name: 'api_question_bank_detail', methods: ['GET'])]
    public function questionDetail(int $entryId, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $entry = $this->entries->find($entryId);
        if (!$entry instanceof QuestionBankEntry) {
            return ApiResponse::error('NOT_FOUND', 'Question entry not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $versions = $this->questions->versionsForEntry($entry);
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'entry' => $this->serializeEntryDetail($entry, $versions),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/questions', name: 'api_question_bank_create', methods: ['POST'])]
    public function createQuestion(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $payload = $this->jsonBodyParser->parse($request);
        $normalized = $this->normalizeQuestionPayload($payload, false);
        $assetEntities = $this->resolveAssets($normalized['embeddedAssetIds']);

        $entry = $this->questions->createEntry(
            $normalized['title'],
            $normalized['plainTextContent'],
            $normalized['richTextContent'],
            $normalized['tags'],
            $normalized['difficulty'],
            $normalized['formulas'],
            $assetEntities,
            $user->getUsername(),
            $normalized['changeNote'],
        );

        $this->auditLogger->log('question.created', $user->getUsername(), [
            'entryId' => $entry->getId(),
            'status' => $entry->getStatus(),
            'difficulty' => $entry->getDifficulty(),
            'tags' => $entry->getTags(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'entry' => $this->serializeEntryDetail($entry, $this->questions->versionsForEntry($entry)),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/questions/{entryId}', name: 'api_question_bank_update', methods: ['PUT'])]
    public function editQuestion(int $entryId, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $entry = $this->entries->find($entryId);
        if (!$entry instanceof QuestionBankEntry) {
            return ApiResponse::error('NOT_FOUND', 'Question entry not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->jsonBodyParser->parse($request);
        $normalized = $this->normalizeQuestionPayload($payload, true);
        $assetEntities = $this->resolveAssets($normalized['embeddedAssetIds']);

        $entry = $this->questions->editEntry(
            $entry,
            $normalized['title'],
            $normalized['plainTextContent'],
            $normalized['richTextContent'],
            $normalized['tags'],
            $normalized['difficulty'],
            $normalized['formulas'],
            $assetEntities,
            $user->getUsername(),
            $normalized['changeNote'],
        );

        $this->auditLogger->log('question.edited', $user->getUsername(), [
            'entryId' => $entry->getId(),
            'version' => $entry->getCurrentVersionNumber(),
            'status' => $entry->getStatus(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'entry' => $this->serializeEntryDetail($entry, $this->questions->versionsForEntry($entry)),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/questions/{entryId}/publish', name: 'api_question_bank_publish', methods: ['POST'])]
    public function publishQuestion(int $entryId, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.publish');

        $entry = $this->entries->find($entryId);
        if (!$entry instanceof QuestionBankEntry) {
            return ApiResponse::error('NOT_FOUND', 'Question entry not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->jsonBodyParser->parse($request);
        $overrideDuplicateReview = (bool) ($payload['overrideDuplicateReview'] ?? false);
        $reviewComment = trim((string) ($payload['reviewComment'] ?? ''));
        if ($overrideDuplicateReview && $reviewComment === '') {
            throw new ApiValidationException('reviewComment is required when overrideDuplicateReview is true.', [
                ['field' => 'reviewComment', 'issue' => 'required_with_override'],
            ]);
        }

        $similarityMatches = [];
        try {
            $similarityMatches = $this->questions->publishEntry($entry, $user->getUsername(), $overrideDuplicateReview);
        } catch (QuestionBankFlowException $e) {
            if ($e->apiCode() === 'DUPLICATE_REVIEW_REQUIRED') {
                $this->auditLogger->log('question.duplicate_review_required', $user->getUsername(), [
                    'entryId' => $entry->getId(),
                    'matches' => $e->details(),
                ]);
            }

            throw $e;
        }

        if ($overrideDuplicateReview && $similarityMatches !== []) {
            $this->auditLogger->log('question.duplicate_review_overridden', $user->getUsername(), [
                'entryId' => $entry->getId(),
                'reviewComment' => $reviewComment,
                'matches' => $similarityMatches,
            ]);
        }

        $this->auditLogger->log('question.published', $user->getUsername(), [
            'entryId' => $entry->getId(),
            'status' => $entry->getStatus(),
            'duplicateOverrideUsed' => $overrideDuplicateReview,
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'entry' => $this->serializeEntryDetail($entry, $this->questions->versionsForEntry($entry)),
            'similarityMatches' => $similarityMatches,
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/questions/{entryId}/offline', name: 'api_question_bank_offline', methods: ['POST'])]
    public function offlineQuestion(int $entryId, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $entry = $this->entries->find($entryId);
        if (!$entry instanceof QuestionBankEntry) {
            return ApiResponse::error('NOT_FOUND', 'Question entry not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->questions->offlineEntry($entry, $user->getUsername());

        $this->auditLogger->log('question.offlined', $user->getUsername(), [
            'entryId' => $entry->getId(),
            'status' => $entry->getStatus(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'entry' => $this->serializeEntryDetail($entry, $this->questions->versionsForEntry($entry)),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/assets', name: 'api_question_bank_asset_upload', methods: ['POST'])]
    public function uploadAsset(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ApiValidationException('Image file is required.', [['field' => 'file', 'issue' => 'required']]);
        }

        $this->validateAssetUpload($file);
        $stored = $this->assetStorage->storeUploadedFile($file);

        $asset = new QuestionBankAsset(
            $stored['storagePath'],
            $stored['originalFilename'],
            $stored['mimeType'],
            $stored['sizeBytes'],
            $user->getUsername(),
        );
        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        $this->auditLogger->log('question.asset_uploaded', $user->getUsername(), [
            'assetId' => $asset->getId(),
            'filename' => $asset->getOriginalFilename(),
            'sizeBytes' => $asset->getSizeBytes(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'asset' => $this->serializeAsset($asset),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/assets/{assetId}/download', name: 'api_question_bank_asset_download', methods: ['GET'])]
    public function downloadAsset(int $assetId): Response
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.manage');

        $asset = $this->assets->find($assetId);
        if (!$asset instanceof QuestionBankAsset) {
            return ApiResponse::error('NOT_FOUND', 'Question asset not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $absolutePath = $this->assetStorage->resolveAbsolutePath($asset->getStoragePath());
        } catch (\RuntimeException) {
            return ApiResponse::error('NOT_FOUND', 'Question asset file not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $asset->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $asset->getOriginalFilename());

        return $response;
    }

    #[Route('/import', name: 'api_question_bank_import', methods: ['POST'])]
    public function importQuestions(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.importExport');

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ApiValidationException('Import file is required.', [['field' => 'file', 'issue' => 'required']]);
        }

        if (!$file->isValid()) {
            throw new ApiValidationException('Import file upload failed.', [['field' => 'file', 'issue' => 'upload_failed']]);
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_IMPORT_BYTES) {
            throw new ApiValidationException('Import file must be between 1 byte and 8 MB.', [['field' => 'file', 'issue' => 'invalid_size']]);
        }

        $parsed = $this->spreadsheet->parseImportFile($file);
        $rows = $parsed['rows'];
        if ($rows === []) {
            throw new ApiValidationException('Import file is empty.', [['field' => 'file', 'issue' => 'empty']]);
        }

        $headerMap = array_map(static fn (string $header): string => trim($header), $rows[0]);
        $dataRows = array_slice($rows, 1);

        $requiredHeaders = ['title', 'plainTextContent', 'richTextContent', 'difficulty', 'tags'];
        foreach ($requiredHeaders as $requiredHeader) {
            if (!in_array($requiredHeader, $headerMap, true)) {
                throw new ApiValidationException('Import file header is invalid.', [['field' => $requiredHeader, 'issue' => 'missing_column']]);
            }
        }

        $created = 0;
        $published = 0;
        $duplicateFlagged = 0;
        $errors = [];

        foreach ($dataRows as $lineNumber => $columns) {
            $rowLine = implode('', $columns);
            if (trim($rowLine) === '') {
                continue;
            }

            $rowPayload = [];
            foreach ($headerMap as $index => $header) {
                $rowPayload[$header] = $columns[$index] ?? '';
            }

            try {
                $normalized = $this->normalizeQuestionPayload([
                    'title' => $rowPayload['title'] ?? '',
                    'plainTextContent' => $rowPayload['plainTextContent'] ?? '',
                    'richTextContent' => $rowPayload['richTextContent'] ?? '',
                    'difficulty' => (int) ($rowPayload['difficulty'] ?? 0),
                    'tags' => array_values(array_filter(array_map('trim', explode('|', (string) ($rowPayload['tags'] ?? ''))))),
                    'formulas' => array_map(
                        static fn (string $formula): array => ['expression' => trim($formula), 'label' => ''],
                        array_values(array_filter(array_map('trim', explode('||', (string) ($rowPayload['formulas'] ?? ''))))),
                    ),
                    'embeddedAssetIds' => [],
                    'changeNote' => trim((string) ($rowPayload['changeNote'] ?? 'Imported in bulk')),
                ], false);

                $entry = $this->questions->createEntry(
                    $normalized['title'],
                    $normalized['plainTextContent'],
                    $normalized['richTextContent'],
                    $normalized['tags'],
                    $normalized['difficulty'],
                    $normalized['formulas'],
                    [],
                    $user->getUsername(),
                    $normalized['changeNote'],
                );
                ++$created;

                $status = strtoupper(trim((string) ($rowPayload['status'] ?? 'DRAFT')));
                if ($status === QuestionBankEntry::STATUS_PUBLISHED) {
                    try {
                        $this->questions->publishEntry($entry, $user->getUsername(), false);
                        ++$published;
                    } catch (QuestionBankFlowException $e) {
                        if ($e->apiCode() === 'DUPLICATE_REVIEW_REQUIRED') {
                            ++$duplicateFlagged;
                        }
                    }
                } elseif ($status === QuestionBankEntry::STATUS_OFFLINE) {
                    $this->questions->offlineEntry($entry, $user->getUsername());
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'line' => $lineNumber + 2,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->auditLogger->log('question.imported', $user->getUsername(), [
            'format' => $parsed['format'],
            'created' => $created,
            'published' => $published,
            'duplicateFlagged' => $duplicateFlagged,
            'errorCount' => count($errors),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'created' => $created,
            'published' => $published,
            'duplicateFlagged' => $duplicateFlagged,
            'errors' => array_slice($errors, 0, 25),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/export', name: 'api_question_bank_export', methods: ['GET'])]
    public function exportQuestions(Request $request): Response
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'question.importExport');

        $format = strtolower(trim((string) $request->query->get('format', 'csv')));
        if (!in_array($format, ['csv', 'excel'], true)) {
            throw new ApiValidationException('Export format must be csv or excel.', [['field' => 'format', 'issue' => 'invalid']]);
        }

        $rows = [];
        $rows[] = ['id', 'title', 'status', 'difficulty', 'tags', 'formulas', 'plainTextContent', 'richTextContent', 'version', 'duplicateReviewState'];

        foreach ($this->questions->listEntries('ALL') as $entry) {
            $rows[] = [
                (string) $entry->getId(),
                $entry->getTitle(),
                $entry->getStatus(),
                (string) $entry->getDifficulty(),
                implode('|', $entry->getTags()),
                implode(' || ', array_map(static fn (array $formula): string => (string) ($formula['expression'] ?? ''), $entry->getFormulas())),
                $entry->getPlainTextContent(),
                $entry->getRichTextContent(),
                (string) $entry->getCurrentVersionNumber(),
                $entry->getDuplicateReviewState(),
            ];
        }

        $exported = $this->spreadsheet->exportRows(array_map(static fn (array $row): array => array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $row), $rows), $format);
        $fileName = sprintf('question-bank-export-%s.%s', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His'), $exported['extension']);
        $response = new Response($exported['content']);
        $response->headers->set('Content-Type', $exported['contentType']);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $fileName));

        $this->auditLogger->log('question.exported', $user->getUsername(), [
            'format' => $format,
            'rowCount' => max(0, count($rows) - 1),
        ]);

        return $response;
    }

    /** @return array<string, mixed> */
    private function normalizeQuestionPayload(array $payload, bool $allowMissingChangeNote): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $plainTextContent = trim((string) ($payload['plainTextContent'] ?? ''));
        $richTextContent = trim((string) ($payload['richTextContent'] ?? ''));
        $difficulty = (int) ($payload['difficulty'] ?? 0);
        $rawTags = $payload['tags'] ?? [];
        $rawFormulas = $payload['formulas'] ?? [];
        $rawAssetIds = $payload['embeddedAssetIds'] ?? [];
        $changeNote = trim((string) ($payload['changeNote'] ?? ''));

        if ($title === '' || mb_strlen($title) > 240) {
            throw new ApiValidationException('Question title is invalid.', [['field' => 'title', 'issue' => 'required_max_240']]);
        }

        if ($plainTextContent === '') {
            throw new ApiValidationException('Plain text question content is required.', [['field' => 'plainTextContent', 'issue' => 'required']]);
        }

        if ($richTextContent === '') {
            throw new ApiValidationException('Rich text question content is required.', [['field' => 'richTextContent', 'issue' => 'required']]);
        }

        if ($difficulty < 1 || $difficulty > 5) {
            throw new ApiValidationException('Difficulty must be between 1 and 5.', [['field' => 'difficulty', 'issue' => 'range_1_5']]);
        }

        if (!is_array($rawTags) || $rawTags === []) {
            throw new ApiValidationException('At least one tag is required.', [['field' => 'tags', 'issue' => 'required']]);
        }

        $tags = [];
        foreach ($rawTags as $tag) {
            $normalizedTag = trim((string) $tag);
            if ($normalizedTag === '') {
                continue;
            }
            if (mb_strlen($normalizedTag) > 60) {
                throw new ApiValidationException('Tag values must be 60 characters or less.', [['field' => 'tags', 'issue' => 'max_60']]);
            }
            $tags[$normalizedTag] = true;
        }

        if ($tags === []) {
            throw new ApiValidationException('At least one non-empty tag is required.', [['field' => 'tags', 'issue' => 'required']]);
        }

        if (!is_array($rawFormulas)) {
            throw new ApiValidationException('formulas must be an array.', [['field' => 'formulas', 'issue' => 'must_be_array']]);
        }

        if (!is_array($rawAssetIds)) {
            throw new ApiValidationException('embeddedAssetIds must be an array.', [['field' => 'embeddedAssetIds', 'issue' => 'must_be_array']]);
        }

        $assetIds = [];
        foreach ($rawAssetIds as $assetId) {
            if (!is_int($assetId) && !(is_string($assetId) && preg_match('/^\d+$/', $assetId) === 1)) {
                throw new ApiValidationException('embeddedAssetIds contains an invalid value.', [['field' => 'embeddedAssetIds', 'issue' => 'must_be_integer']]);
            }

            $id = (int) $assetId;
            if ($id > 0) {
                $assetIds[$id] = true;
            }
        }

        if (!$allowMissingChangeNote && $changeNote === '') {
            $changeNote = 'Initial draft';
        }

        if (mb_strlen($changeNote) > 500) {
            throw new ApiValidationException('changeNote must be 500 characters or less.', [['field' => 'changeNote', 'issue' => 'max_500']]);
        }

        return [
            'title' => $title,
            'plainTextContent' => $plainTextContent,
            'richTextContent' => $richTextContent,
            'difficulty' => $difficulty,
            'tags' => array_keys($tags),
            'formulas' => $rawFormulas,
            'embeddedAssetIds' => array_keys($assetIds),
            'changeNote' => $changeNote === '' ? null : $changeNote,
        ];
    }

    /** @param list<int> $assetIds @return list<QuestionBankAsset> */
    private function resolveAssets(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $assets = $this->assets->findByIds($assetIds);
        $foundIds = [];
        foreach ($assets as $asset) {
            $id = $asset->getId();
            if ($id !== null) {
                $foundIds[$id] = true;
            }
        }

        foreach ($assetIds as $assetId) {
            if (!isset($foundIds[$assetId])) {
                throw new ApiValidationException('embeddedAssetIds contains unknown asset IDs.', [['field' => 'embeddedAssetIds', 'issue' => sprintf('asset_%d_not_found', $assetId)]]);
            }
        }

        return $assets;
    }

    private function validateAssetUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ApiValidationException('Image upload failed.', [['field' => 'file', 'issue' => 'upload_failed']]);
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) {
            throw new ApiValidationException('Image size must be between 1 byte and 5 MB.', [['field' => 'file', 'issue' => 'invalid_size']]);
        }

        $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        $mimeType = strtolower((string) ($file->getClientMimeType() ?: ''));
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedMimeTypes, true) || !in_array($extension, $allowedExtensions, true)) {
            throw new ApiValidationException('Image must be PNG, JPG, GIF, or WEBP.', [['field' => 'file', 'issue' => 'invalid_type']]);
        }
    }

    /** @return array<string, mixed> */
    private function serializeEntrySummary(QuestionBankEntry $entry): array
    {
        return [
            'id' => $entry->getId(),
            'title' => $entry->getTitle(),
            'status' => $entry->getStatus(),
            'difficulty' => $entry->getDifficulty(),
            'tags' => $entry->getTags(),
            'currentVersionNumber' => $entry->getCurrentVersionNumber(),
            'duplicateReviewState' => $entry->getDuplicateReviewState(),
            'updatedAtUtc' => $entry->getUpdatedAtUtc()->format(DATE_ATOM),
        ];
    }

    /** @param list<QuestionBankEntryVersion> $versions @return array<string, mixed> */
    private function serializeEntryDetail(QuestionBankEntry $entry, array $versions): array
    {
        return [
            'id' => $entry->getId(),
            'title' => $entry->getTitle(),
            'plainTextContent' => $entry->getPlainTextContent(),
            'richTextContent' => $entry->getRichTextContent(),
            'difficulty' => $entry->getDifficulty(),
            'tags' => $entry->getTags(),
            'formulas' => $entry->getFormulas(),
            'embeddedImages' => $entry->getEmbeddedImages(),
            'status' => $entry->getStatus(),
            'duplicateReviewState' => $entry->getDuplicateReviewState(),
            'currentVersionNumber' => $entry->getCurrentVersionNumber(),
            'publishedAtUtc' => $entry->getPublishedAtUtc()?->format(DATE_ATOM),
            'publishedByUsername' => $entry->getPublishedByUsername(),
            'updatedAtUtc' => $entry->getUpdatedAtUtc()->format(DATE_ATOM),
            'versions' => array_map(fn (QuestionBankEntryVersion $version): array => [
                'id' => $version->getId(),
                'versionNumber' => $version->getVersionNumber(),
                'title' => $version->getTitle(),
                'plainTextContent' => $version->getPlainTextContent(),
                'richTextContent' => $version->getRichTextContent(),
                'difficulty' => $version->getDifficulty(),
                'tags' => $version->getTags(),
                'formulas' => $version->getFormulas(),
                'embeddedImages' => $version->getEmbeddedImages(),
                'changeNote' => $version->getChangeNote(),
                'createdByUsername' => $version->getCreatedByUsername(),
                'createdAtUtc' => $version->getCreatedAtUtc()->format(DATE_ATOM),
            ], $versions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAsset(QuestionBankAsset $asset): array
    {
        return [
            'id' => $asset->getId(),
            'originalFilename' => $asset->getOriginalFilename(),
            'mimeType' => $asset->getMimeType(),
            'sizeBytes' => $asset->getSizeBytes(),
            'downloadPath' => sprintf('/api/question-bank/assets/%d/download', $asset->getId()),
            'uploadedAtUtc' => $asset->getCreatedAtUtc()->format(DATE_ATOM),
        ];
    }

    private function currentUser(): User
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            throw new QuestionBankFlowException('UNAUTHENTICATED', JsonResponse::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        return $user;
    }
}
