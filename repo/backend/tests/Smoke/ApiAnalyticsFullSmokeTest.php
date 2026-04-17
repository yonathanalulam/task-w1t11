<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Real-HTTP analytics workbench + compliance export + features CRUD.
 *
 * Endpoints exercised:
 *   GET  /api/analytics/workbench/options
 *   POST /api/analytics/query
 *   POST /api/analytics/audit-report/export
 *   GET  /api/analytics/features
 *   POST /api/analytics/features
 *   PUT  /api/analytics/features/{featureId}
 *
 * (POST /api/analytics/query/export is covered by ApiHttpSmokeTest.)
 */
final class ApiAnalyticsFullSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testWorkbenchOptionsReturnsOrgUnitsFeaturesDatasetsOverRealHttp(): void
    {
        $this->loginAs('analyst_user');

        $options = $this->request('GET', '/api/analytics/workbench/options');
        self::assertSame(200, $options['status']);
        $data = $this->json($options['body'])['data'] ?? [];
        self::assertIsArray($data['orgUnits'] ?? null);
        self::assertIsArray($data['features'] ?? null);
        self::assertIsArray($data['sampleDatasets'] ?? null);
    }

    public function testAnalyticsQueryReturnsRowsSummaryAndDashboardOverRealHttp(): void
    {
        $csrf = $this->loginAs('analyst_user');

        $query = $this->request('POST', '/api/analytics/query', [
            'json' => [
                'fromDate' => '2026-01-01',
                'toDate' => '2026-12-31',
                'orgUnits' => [],
                'featureIds' => [],
                'datasetIds' => [],
                'includeLiveData' => true,
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $query['status'], 'query body: ' . $query['body']);

        $data = $this->json($query['body'])['data'] ?? [];
        self::assertArrayHasKey('rows', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('dashboard', $data);
        self::assertArrayHasKey('complianceDashboard', $data);
        self::assertGreaterThanOrEqual(0, (int) ($data['summary']['rowCount'] ?? -1));
    }

    public function testAuditReportExportDeliversComplianceCsvOverRealHttp(): void
    {
        $csrf = $this->loginAs('analyst_user');

        $export = $this->request('POST', '/api/analytics/audit-report/export', [
            'json' => [
                'fromDate' => '2026-01-01',
                'toDate' => '2026-12-31',
                'orgUnits' => [],
                'featureIds' => [],
                'datasetIds' => [],
                'includeLiveData' => true,
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $export['status']);
        self::assertStringContainsString('text/csv', strtolower($export['headers']['content-type'] ?? ''));
        self::assertStringContainsString('attachment;', strtolower($export['headers']['content-disposition'] ?? ''));
        self::assertStringContainsString('Compliance KPI', $export['body']);
    }

    public function testFeaturesCrudRoundtripOverRealHttp(): void
    {
        $csrf = $this->loginAs('analyst_user');

        $create = $this->request('POST', '/api/analytics/features', [
            'json' => [
                'name' => 'Smoke Feature ' . bin2hex(random_bytes(3)),
                'description' => 'Created from the analytics smoke suite to validate POST + PUT contract.',
                'tags' => ['smoke'],
                'formulaExpression' => 'breachCount >= 0',
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(201, $create['status'], 'create body: ' . $create['body']);
        $featureId = (int) ($this->json($create['body'])['data']['feature']['id'] ?? 0);
        self::assertGreaterThan(0, $featureId);

        $list = $this->request('GET', '/api/analytics/features');
        self::assertSame(200, $list['status']);
        $features = $this->json($list['body'])['data']['features'] ?? [];
        self::assertNotEmpty($features);
        $featureIds = array_map(static fn (array $f): int => (int) ($f['id'] ?? 0), $features);
        self::assertContains($featureId, $featureIds);

        $update = $this->request('PUT', sprintf('/api/analytics/features/%d', $featureId), [
            'json' => [
                'name' => 'Smoke Feature Renamed',
                'description' => 'Updated from analytics smoke suite.',
                'tags' => ['smoke', 'renamed'],
                'formulaExpression' => 'breachCount > 1',
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $update['status'], 'update body: ' . $update['body']);
        self::assertSame('Smoke Feature Renamed', $this->json($update['body'])['data']['feature']['name'] ?? null);
    }
}
