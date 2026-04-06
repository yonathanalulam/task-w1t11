<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class QuestionBankControllerTest extends WebTestCase
{
    public function testQuestionBankEndpointsRequireQuestionPermissions(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/question-bank/questions');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/question-bank/questions');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACCESS_DENIED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testContentAdminCanCreateEditAndViewVersionHistory(): void
    {
        $client = static::createClient();
        $this->login($client, 'content_admin');
        $csrf = $this->fetchCsrfToken($client);

        $createAuditBefore = $this->auditCount($client, 'question.created');

        $client->request('POST', '/api/question-bank/questions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'title' => 'Escalation Intake: Bankruptcy Indicators',
            'plainTextContent' => 'Collect counterparty signals that indicate potential bankruptcy exposure and timing.',
            'richTextContent' => '<p>Collect counterparty signals with weighted scoring.</p><p><strong>Escalate</strong> if risk score exceeds threshold.</p>',
            'difficulty' => 3,
            'tags' => ['bankruptcy', 'escalation'],
            'formulas' => [
                ['expression' => 'risk_score = liabilities / assets', 'label' => 'Core ratio'],
            ],
            'embeddedAssetIds' => [],
            'changeNote' => 'Initial controlled draft',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $createdPayload = $this->json($client->getResponse()->getContent());
        $entryId = (int) ($createdPayload['data']['entry']['id'] ?? 0);
        self::assertGreaterThan(0, $entryId);
        self::assertSame('DRAFT', $createdPayload['data']['entry']['status'] ?? null);
        self::assertSame(1, (int) ($createdPayload['data']['entry']['currentVersionNumber'] ?? 0));
        self::assertCount(1, $createdPayload['data']['entry']['versions'] ?? []);
        self::assertGreaterThan($createAuditBefore, $this->auditCount($client, 'question.created'));

        $editAuditBefore = $this->auditCount($client, 'question.edited');

        $client->request('PUT', sprintf('/api/question-bank/questions/%d', $entryId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'title' => 'Escalation Intake: Bankruptcy Indicators v2',
            'plainTextContent' => 'Collect counterparty insolvency indicators and capture timeline confidence for compliance escalation.',
            'richTextContent' => '<p>Collect insolvency indicators and confidence.</p><p>Escalate when <em>risk_score &gt;= 0.72</em>.</p>',
            'difficulty' => 4,
            'tags' => ['bankruptcy', 'escalation', 'compliance'],
            'formulas' => [
                ['expression' => 'risk_score = (liabilities / assets) * confidence', 'label' => 'Weighted ratio'],
            ],
            'embeddedAssetIds' => [],
            'changeNote' => 'Expanded risk formula and escalation language',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $editedPayload = $this->json($client->getResponse()->getContent());
        self::assertSame('DRAFT', $editedPayload['data']['entry']['status'] ?? null);
        self::assertSame(2, (int) ($editedPayload['data']['entry']['currentVersionNumber'] ?? 0));
        self::assertCount(2, $editedPayload['data']['entry']['versions'] ?? []);
        self::assertGreaterThan($editAuditBefore, $this->auditCount($client, 'question.edited'));

        $client->request('GET', sprintf('/api/question-bank/questions/%d', $entryId));
        self::assertResponseIsSuccessful();
        $detailPayload = $this->json($client->getResponse()->getContent());
        self::assertCount(2, $detailPayload['data']['entry']['versions'] ?? []);

        $client->request('POST', '/api/question-bank/questions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'title' => 'Invalid Difficulty Question',
            'plainTextContent' => 'invalid',
            'richTextContent' => '<p>invalid</p>',
            'difficulty' => 9,
            'tags' => ['invalid'],
            'formulas' => [],
            'embeddedAssetIds' => [],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_ERROR', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testQuestionContentSupportsEmbeddedImageAssetsAndFormulaModel(): void
    {
        $client = static::createClient();
        $this->login($client, 'content_admin');
        $csrf = $this->fetchCsrfToken($client);

        $assetPath = $this->createTinyPngFixture();
        $uploaded = new UploadedFile($assetPath, 'formula-plot.png', 'image/png', null, true);

        $client->request('POST', '/api/question-bank/assets', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], files: [
            'file' => $uploaded,
        ]);
        self::assertResponseStatusCodeSame(201);

        $assetPayload = $this->json($client->getResponse()->getContent());
        $assetId = (int) ($assetPayload['data']['asset']['id'] ?? 0);
        self::assertGreaterThan(0, $assetId);
        self::assertStringContainsString('/api/question-bank/assets/', (string) ($assetPayload['data']['asset']['downloadPath'] ?? ''));

        $client->request('POST', '/api/question-bank/questions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'title' => 'Formula and Evidence Artifact Intake',
            'plainTextContent' => 'Capture formula assumptions and supporting embedded chart image.',
            'richTextContent' => '<p>Capture assumptions and include inline chart.</p>',
            'difficulty' => 2,
            'tags' => ['formula', 'evidence'],
            'formulas' => [
                ['expression' => 'expected_loss = probability * impact', 'label' => 'Expected loss'],
            ],
            'embeddedAssetIds' => [$assetId],
            'changeNote' => 'Created with image embed and formula metadata',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $entryPayload = $this->json($client->getResponse()->getContent());
        $embedded = $entryPayload['data']['entry']['embeddedImages'] ?? [];
        self::assertCount(1, $embedded);
        self::assertSame($assetId, (int) ($embedded[0]['assetId'] ?? 0));
        self::assertSame('expected_loss = probability * impact', $entryPayload['data']['entry']['formulas'][0]['expression'] ?? null);

        $client->request('GET', sprintf('/api/question-bank/assets/%d/download', $assetId));
        self::assertResponseIsSuccessful();
    }

    public function testDuplicateSimilarityBlocksPublishUntilOverrideReview(): void
    {
        $client = static::createClient();
        $this->login($client, 'content_admin');
        $csrf = $this->fetchCsrfToken($client);
        $suffix = $this->uniqueSuffix();

        $baseText = sprintf(
            'Controlled duplicate baseline %s %s %s %s',
            $suffix,
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(8)),
        );

        $firstEntryId = $this->createQuestion(
            $client,
            $csrf,
            sprintf('Client Solvency Intake Matrix %s', $suffix),
            $baseText,
            sprintf('<p>%s</p>', $baseText),
            ['solvency', 'intake'],
            3,
        );

        $publishAuditBefore = $this->auditCount($client, 'question.published');
        $client->request('POST', sprintf('/api/question-bank/questions/%d/publish', $firstEntryId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($publishAuditBefore, $this->auditCount($client, 'question.published'));

        $secondEntryId = $this->createQuestion(
            $client,
            $csrf,
            sprintf('Client Solvency Intake Matrix Duplicate Candidate %s', $suffix),
            $baseText,
            sprintf('<p>%s</p>', $baseText),
            ['solvency', 'dup-check'],
            3,
        );

        $duplicateRequiredBefore = $this->auditCount($client, 'question.duplicate_review_required');
        $client->request('POST', sprintf('/api/question-bank/questions/%d/publish', $secondEntryId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('DUPLICATE_REVIEW_REQUIRED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
        self::assertGreaterThan($duplicateRequiredBefore, $this->auditCount($client, 'question.duplicate_review_required'));

        $duplicateOverrideBefore = $this->auditCount($client, 'question.duplicate_review_overridden');
        $client->request('POST', sprintf('/api/question-bank/questions/%d/publish', $secondEntryId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'overrideDuplicateReview' => true,
            'reviewComment' => 'Reviewed by content-admin; duplicate retained intentionally for controlled branch.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $publishOverridePayload = $this->json($client->getResponse()->getContent());
        self::assertSame('PUBLISHED', $publishOverridePayload['data']['entry']['status'] ?? null);
        self::assertSame('OVERRIDDEN', $publishOverridePayload['data']['entry']['duplicateReviewState'] ?? null);
        self::assertGreaterThan($duplicateOverrideBefore, $this->auditCount($client, 'question.duplicate_review_overridden'));
    }

    public function testOfflineTransitionAndBulkImportExportAreSupported(): void
    {
        $client = static::createClient();
        $this->login($client, 'content_admin');
        $csrf = $this->fetchCsrfToken($client);

        $entryId = $this->createQuestion(
            $client,
            $csrf,
            'Escalation Lifecycle Transition Test',
            'Lifecycle transitions should support draft, publish, and offline states.',
            '<p>Lifecycle transitions should support draft, publish, and offline states.</p>',
            ['lifecycle', 'state'],
            2,
        );

        $offlineAuditBefore = $this->auditCount($client, 'question.offlined');
        $client->request('POST', sprintf('/api/question-bank/questions/%d/offline', $entryId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('OFFLINE', $this->json($client->getResponse()->getContent())['data']['entry']['status'] ?? null);
        self::assertGreaterThan($offlineAuditBefore, $this->auditCount($client, 'question.offlined'));

        $csvPath = $this->createImportFixture();
        $importFile = new UploadedFile($csvPath, 'question-bulk-import.csv', 'text/csv', null, true);

        $importAuditBefore = $this->auditCount($client, 'question.imported');
        $client->request('POST', '/api/question-bank/import', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], files: [
            'file' => $importFile,
        ]);
        self::assertResponseIsSuccessful();
        $importPayload = $this->json($client->getResponse()->getContent());
        self::assertGreaterThanOrEqual(2, (int) ($importPayload['data']['created'] ?? 0));
        self::assertGreaterThan($importAuditBefore, $this->auditCount($client, 'question.imported'));

        $exportAuditBefore = $this->auditCount($client, 'question.exported');
        $client->request('GET', '/api/question-bank/export?format=csv');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));

        $client->request('GET', '/api/question-bank/export?format=excel');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
        $xlsxBody = $client->getResponse()->getContent();
        self::assertIsString($xlsxBody);
        self::assertStringStartsWith('PK', $xlsxBody);
        self::assertGreaterThan($exportAuditBefore, $this->auditCount($client, 'question.exported'));

        $xlsxImportPath = tempnam(sys_get_temp_dir(), 'qbank_import_xlsx_');
        self::assertIsString($xlsxImportPath);
        file_put_contents($xlsxImportPath, $xlsxBody);
        $xlsxImportFile = new UploadedFile(
            $xlsxImportPath,
            'question-bulk-import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $xlsxImportAuditBefore = $this->auditCount($client, 'question.imported');
        $client->request('POST', '/api/question-bank/import', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], files: [
            'file' => $xlsxImportFile,
        ]);

        if (is_file($xlsxImportPath)) {
            unlink($xlsxImportPath);
        }

        self::assertResponseIsSuccessful();
        $xlsxImportPayload = $this->json($client->getResponse()->getContent());
        self::assertGreaterThan(0, (int) ($xlsxImportPayload['data']['created'] ?? 0));
        self::assertGreaterThan($xlsxImportAuditBefore, $this->auditCount($client, 'question.imported'));
    }

    private function createQuestion(
        $client,
        string $csrf,
        string $title,
        string $plainText,
        string $richText,
        array $tags,
        int $difficulty,
    ): int {
        $client->request('POST', '/api/question-bank/questions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'title' => $title,
            'plainTextContent' => $plainText,
            'richTextContent' => $richText,
            'difficulty' => $difficulty,
            'tags' => $tags,
            'formulas' => [],
            'embeddedAssetIds' => [],
            'changeNote' => 'Integration test create',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = $this->json($client->getResponse()->getContent());

        return (int) ($payload['data']['entry']['id'] ?? 0);
    }

    private function createTinyPngFixture(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qbank_png_');
        self::assertIsString($tmp);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAtEB9U7H5qkAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        file_put_contents($tmp, $png);

        return $tmp;
    }

    private function createImportFixture(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qbank_import_');
        self::assertIsString($tmp);

        $csv = implode("\n", [
            'title,plainTextContent,richTextContent,difficulty,tags,formulas,status,changeNote',
            'Bulk Intake A,"Collect sanctions exposure signals for intake.","<p>Collect sanctions exposure signals for intake.</p>",2,"sanctions|intake","score = risk * weight",DRAFT,"Bulk import row A"',
            'Bulk Intake B,"Collect sanctions exposure signals for intake and branch routing.","<p>Collect sanctions exposure signals for intake and branch routing.</p>",3,"sanctions|routing","branch_score = risk + pressure",PUBLISHED,"Bulk import row B"',
        ]);

        file_put_contents($tmp, $csv);

        return $tmp;
    }

    private function login($client, string $username): void
    {
        $devPassword = getenv('DEV_BOOTSTRAP_PASSWORD');
        self::assertIsString($devPassword);
        self::assertNotSame('', $devPassword);

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => $devPassword,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
    }

    private function fetchCsrfToken($client): string
    {
        $client->request('GET', '/api/auth/csrf-token');
        self::assertResponseIsSuccessful();

        return (string) ($this->json($client->getResponse()->getContent())['data']['csrfToken'] ?? '');
    }

    private function auditCount($client, string $actionType): int
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $value = $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(id) FROM audit_logs WHERE action_type = ?',
            [$actionType],
        );

        return (int) $value;
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }
}
