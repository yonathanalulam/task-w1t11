<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnalyticsControllerTest extends WebTestCase
{
    public function testAnalyticsWorkbenchRequiresAnalyticsPermissions(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/workbench/options');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/analytics/workbench/options');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACCESS_DENIED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testAnalystCanRunQueryAndReceiveComplianceDashboard(): void
    {
        $client = static::createClient();
        $this->login($client, 'analyst_user');
        $csrf = $this->fetchCsrfToken($client);

        $client->request('GET', '/api/analytics/workbench/options');
        self::assertResponseIsSuccessful();
        $optionsPayload = $this->json($client->getResponse()->getContent());

        $datasetId = (int) (($optionsPayload['data']['sampleDatasets'][0]['id'] ?? 0));
        self::assertGreaterThan(0, $datasetId);

        $queryAuditBefore = $this->auditCount($client, 'analytics.query_run');

        $client->request('POST', '/api/analytics/query', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'fromDate' => '2026-01-01',
            'toDate' => '2026-12-31',
            'orgUnits' => ['North Region'],
            'featureIds' => [],
            'datasetIds' => [$datasetId],
            'includeLiveData' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertIsArray($payload['data']['rows'] ?? null);
        self::assertGreaterThan(0, (int) ($payload['data']['summary']['rowCount'] ?? 0));
        self::assertCount(6, $payload['data']['complianceDashboard']['kpis'] ?? []);
        self::assertCount(6, $payload['data']['complianceDashboard']['promptKpis'] ?? []);
        $kpiLabels = array_map(
            static fn (array $kpi): string => (string) ($kpi['label'] ?? ''),
            (array) ($payload['data']['complianceDashboard']['kpis'] ?? []),
        );
        self::assertContains('Rescue volume', $kpiLabels);
        self::assertContains('Recovery rate', $kpiLabels);
        self::assertContains('Adoption conversion', $kpiLabels);
        self::assertContains('Average shelter stay', $kpiLabels);
        self::assertContains('Donation mix', $kpiLabels);
        self::assertContains('Supply turnover', $kpiLabels);
        $implementationLabels = array_map(
            static fn (array $kpi): string => (string) ($kpi['implementationLabel'] ?? ''),
            (array) ($payload['data']['complianceDashboard']['kpis'] ?? []),
        );
        self::assertContains('Regulatory Intervention Volume', $implementationLabels);
        self::assertContains('Revenue/Compliance Fee Mix', $implementationLabels);
        foreach ((array) ($payload['data']['complianceDashboard']['kpis'] ?? []) as $kpi) {
            self::assertNotSame('', (string) ($kpi['promptAlias'] ?? ''));
            self::assertNotSame('', (string) ($kpi['promptLabel'] ?? ''));
            self::assertNotSame('', (string) ($kpi['implementationLabel'] ?? ''));
        }
        self::assertArrayHasKey('reviewHoursVsBreachRate', $payload['data']['dashboard']['correlation'] ?? []);
        self::assertGreaterThan($queryAuditBefore, $this->auditCount($client, 'analytics.query_run'));
    }

    public function testExportEndpointsProduceCsvAndAuditEvents(): void
    {
        $client = static::createClient();
        $this->login($client, 'analyst_user');
        $csrf = $this->fetchCsrfToken($client);

        $payload = json_encode([
            'fromDate' => '2026-01-01',
            'toDate' => '2026-12-31',
            'orgUnits' => [],
            'featureIds' => [],
            'datasetIds' => [],
            'includeLiveData' => true,
        ], JSON_THROW_ON_ERROR);

        $queryExportAuditBefore = $this->auditCount($client, 'analytics.query_exported');
        $client->request('POST', '/api/analytics/query/export', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: $payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('occurredAtUtc,orgUnit,source', (string) $client->getResponse()->getContent());
        self::assertGreaterThan($queryExportAuditBefore, $this->auditCount($client, 'analytics.query_exported'));

        $reportExportAuditBefore = $this->auditCount($client, 'analytics.audit_report_exported');
        $client->request('POST', '/api/analytics/audit-report/export', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: $payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Compliance KPI', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Rescue volume', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('implementation: Regulatory Intervention Volume', (string) $client->getResponse()->getContent());
        self::assertGreaterThan($reportExportAuditBefore, $this->auditCount($client, 'analytics.audit_report_exported'));
    }

    public function testFeatureDefinitionCrudSupportsAnalystAndEnforcesValidation(): void
    {
        $client = static::createClient();
        $this->login($client, 'analyst_user');
        $csrf = $this->fetchCsrfToken($client);

        $createAuditBefore = $this->auditCount($client, 'analytics.feature_created');

        $client->request('POST', '/api/analytics/features', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'name' => 'Litigation Breach Sentinel',
            'description' => 'Flags clusters that combine litigation pressure with elevated breach incidence.',
            'tags' => ['live'],
            'formulaExpression' => 'breachCount > 999999',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $createdPayload = $this->json($client->getResponse()->getContent());
        $featureId = (int) ($createdPayload['data']['feature']['id'] ?? 0);
        self::assertGreaterThan(0, $featureId);
        self::assertGreaterThan($createAuditBefore, $this->auditCount($client, 'analytics.feature_created'));

        $client->request('POST', '/api/analytics/query', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'fromDate' => '2026-01-01',
            'toDate' => '2026-12-31',
            'orgUnits' => [],
            'featureIds' => [$featureId],
            'datasetIds' => [],
            'includeLiveData' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $preUpdateFilterPayload = $this->json($client->getResponse()->getContent());
        self::assertSame(0, (int) ($preUpdateFilterPayload['data']['summary']['rowCount'] ?? 0));

        $updateAuditBefore = $this->auditCount($client, 'analytics.feature_updated');
        $client->request('PUT', sprintf('/api/analytics/features/%d', $featureId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'name' => 'Litigation Breach Sentinel v2',
            'description' => 'Updated definition for litigation and escalation posture drift.',
            'tags' => ['live'],
            'formulaExpression' => 'breachCount >= 0',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $updatedPayload = $this->json($client->getResponse()->getContent());
        self::assertSame('Litigation Breach Sentinel v2', $updatedPayload['data']['feature']['name'] ?? null);
        self::assertGreaterThan($updateAuditBefore, $this->auditCount($client, 'analytics.feature_updated'));

        $client->request('POST', '/api/analytics/query', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'fromDate' => '2026-01-01',
            'toDate' => '2026-12-31',
            'orgUnits' => [],
            'featureIds' => [$featureId],
            'datasetIds' => [],
            'includeLiveData' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $filteredPayload = $this->json($client->getResponse()->getContent());
        self::assertGreaterThan(0, (int) ($filteredPayload['data']['summary']['rowCount'] ?? 0));
        self::assertNotEmpty($filteredPayload['data']['rows'][0]['matchedFeatures'] ?? []);

        $client->request('POST', '/api/analytics/features', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'name' => '',
            'description' => 'invalid',
            'tags' => [],
            'formulaExpression' => '',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_ERROR', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $client->request('POST', '/api/analytics/features', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'name' => 'Invalid Formula Sentinel',
            'description' => 'invalid syntax guard',
            'tags' => ['live'],
            'formulaExpression' => 'breachCount >',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_ERROR', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
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

        $value = $entityManager->getConnection()->fetchOne('SELECT COUNT(id) FROM audit_logs WHERE action_type = ?', [$actionType]);

        return (int) $value;
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
