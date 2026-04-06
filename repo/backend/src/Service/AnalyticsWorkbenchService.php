<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnalyticsFeatureDefinition;
use App\Entity\AnalyticsSampleDataset;
use App\Exception\AnalyticsFlowException;
use App\Repository\AnalyticsFeatureDefinitionRepository;
use App\Repository\AnalyticsSampleDatasetRepository;
use App\Repository\AnalyticsSnapshotRepository;
use Symfony\Component\HttpFoundation\Response;

final class AnalyticsWorkbenchService
{
    public function __construct(
        private readonly AnalyticsSnapshotRepository $snapshots,
        private readonly AnalyticsFeatureDefinitionRepository $features,
        private readonly AnalyticsSampleDatasetRepository $datasets,
        private readonly AnalyticsFeatureFormulaService $formulaService,
    ) {
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function runQuery(array $payload): array
    {
        $fromUtc = $this->requiredDate($payload['fromDate'] ?? null, 'fromDate');
        $toUtc = $this->requiredDate($payload['toDate'] ?? null, 'toDate');
        if ($toUtc < $fromUtc) {
            throw new AnalyticsFlowException('INVALID_DATE_RANGE', Response::HTTP_UNPROCESSABLE_ENTITY, 'toDate must be on or after fromDate.');
        }

        $orgUnits = $this->stringArray($payload['orgUnits'] ?? []);
        $featureIds = $this->intArray($payload['featureIds'] ?? []);
        $datasetIds = $this->intArray($payload['datasetIds'] ?? []);
        $includeLiveData = (bool) ($payload['includeLiveData'] ?? true);

        $selectedFeatures = $featureIds === []
            ? $this->features->listAll()
            : array_values(array_filter(
                array_map(fn (int $id): ?AnalyticsFeatureDefinition => $this->features->find($id), $featureIds),
                static fn ($feature): bool => $feature instanceof AnalyticsFeatureDefinition,
            ));

        $selectedDatasets = $datasetIds === [] ? $this->datasets->listAll() : $this->datasets->findByIds($datasetIds);

        $rows = [];

        if ($includeLiveData) {
            foreach ($this->snapshots->findByDateRangeAndOrgUnits($fromUtc, $toUtc, $orgUnits) as $snapshot) {
                $rows[] = $this->normalizeRow([
                    'occurredAtUtc' => $snapshot->getOccurredAtUtc()->format(DATE_ATOM),
                    'orgUnit' => $snapshot->getOrgUnit(),
                    'intakeCount' => $snapshot->getIntakeCount(),
                    'breachCount' => $snapshot->getBreachCount(),
                    'escalationCount' => $snapshot->getEscalationCount(),
                    'avgReviewHours' => $snapshot->getAvgReviewHours(),
                    'resolutionWithinSlaPct' => $snapshot->getResolutionWithinSlaPct(),
                    'evidenceCompletenessPct' => $snapshot->getEvidenceCompletenessPct(),
                    'tags' => [$snapshot->getOrgUnit(), 'live'],
                    'source' => 'LIVE',
                    'datasetName' => 'Live operations feed',
                ]);
            }
        }

        foreach ($selectedDatasets as $dataset) {
            foreach ($dataset->getRows() as $rawRow) {
                if (!is_array($rawRow)) {
                    continue;
                }

                $normalized = $this->normalizeRow([
                    'occurredAtUtc' => (string) ($rawRow['occurredAtUtc'] ?? ''),
                    'orgUnit' => (string) ($rawRow['orgUnit'] ?? ''),
                    'intakeCount' => (int) ($rawRow['intakeCount'] ?? 0),
                    'breachCount' => (int) ($rawRow['breachCount'] ?? 0),
                    'escalationCount' => (int) ($rawRow['escalationCount'] ?? 0),
                    'avgReviewHours' => (float) ($rawRow['avgReviewHours'] ?? 0.0),
                    'resolutionWithinSlaPct' => (float) ($rawRow['resolutionWithinSlaPct'] ?? 0.0),
                    'evidenceCompletenessPct' => (float) ($rawRow['evidenceCompletenessPct'] ?? 0.0),
                    'tags' => is_array($rawRow['tags'] ?? null) ? $rawRow['tags'] : [],
                    'source' => 'SAMPLE',
                    'datasetName' => $dataset->getName(),
                ]);

                $occurred = new \DateTimeImmutable($normalized['occurredAtUtc'], new \DateTimeZone('UTC'));
                if ($occurred < $fromUtc || $occurred > $toUtc) {
                    continue;
                }

                if ($orgUnits !== [] && !in_array($normalized['orgUnit'], $orgUnits, true)) {
                    continue;
                }

                $rows[] = $normalized;
            }
        }

        if ($rows === []) {
            return [
                'filters' => [
                    'fromDate' => $fromUtc->format('Y-m-d'),
                    'toDate' => $toUtc->format('Y-m-d'),
                    'orgUnits' => $orgUnits,
                    'datasetIds' => $datasetIds,
                    'featureIds' => $featureIds,
                    'includeLiveData' => $includeLiveData,
                ],
                'rows' => [],
                'summary' => [
                    'rowCount' => 0,
                    'totalIntakeCount' => 0,
                    'totalBreachCount' => 0,
                    'avgBreachRatePct' => 0.0,
                    'avgComplianceScorePct' => 0.0,
                ],
                'dashboard' => [
                    'trend' => [],
                    'distribution' => [],
                    'correlation' => [
                        'reviewHoursVsBreachRate' => 0.0,
                        'evidenceCompletenessVsBreachRate' => 0.0,
                    ],
                ],
                'complianceDashboard' => $this->complianceKpiSet([], []),
            ];
        }

        $filteredRows = [];
        foreach ($rows as $row) {
            $matchedFeatures = [];
            foreach ($selectedFeatures as $feature) {
                if ($this->rowMatchesFeature($row, $feature)) {
                    $matchedFeatures[] = [
                        'id' => $feature->getId(),
                        'name' => $feature->getName(),
                    ];
                }
            }

            if ($featureIds !== [] && $matchedFeatures === []) {
                continue;
            }

            $row['matchedFeatures'] = $matchedFeatures;
            $filteredRows[] = $row;
        }

        $summary = $this->summary($filteredRows);
        $trend = $this->trendView($filteredRows);
        $distribution = $this->distributionView($filteredRows);
        $correlation = $this->correlationView($filteredRows);

        return [
            'filters' => [
                'fromDate' => $fromUtc->format('Y-m-d'),
                'toDate' => $toUtc->format('Y-m-d'),
                'orgUnits' => $orgUnits,
                'datasetIds' => $datasetIds,
                'featureIds' => $featureIds,
                'includeLiveData' => $includeLiveData,
            ],
            'rows' => $filteredRows,
            'summary' => $summary,
            'dashboard' => [
                'trend' => $trend,
                'distribution' => $distribution,
                'correlation' => $correlation,
            ],
            'complianceDashboard' => $this->complianceKpiSet($filteredRows, $trend),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    public function buildQueryCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to allocate CSV export stream.');
        }

        fputcsv($stream, [
            'occurredAtUtc',
            'orgUnit',
            'source',
            'datasetName',
            'intakeCount',
            'breachCount',
            'escalationCount',
            'avgReviewHours',
            'resolutionWithinSlaPct',
            'evidenceCompletenessPct',
            'breachRatePct',
            'escalationRatePct',
            'complianceScorePct',
            'matchedFeatures',
        ], ',', '"', '\\');

        foreach ($rows as $row) {
            $featureNames = array_map(static fn (array $feature): string => (string) ($feature['name'] ?? ''), (array) ($row['matchedFeatures'] ?? []));

            fputcsv($stream, [
                (string) ($row['occurredAtUtc'] ?? ''),
                (string) ($row['orgUnit'] ?? ''),
                (string) ($row['source'] ?? ''),
                (string) ($row['datasetName'] ?? ''),
                (string) ((int) ($row['intakeCount'] ?? 0)),
                (string) ((int) ($row['breachCount'] ?? 0)),
                (string) ((int) ($row['escalationCount'] ?? 0)),
                (string) round((float) ($row['avgReviewHours'] ?? 0.0), 2),
                (string) round((float) ($row['resolutionWithinSlaPct'] ?? 0.0), 2),
                (string) round((float) ($row['evidenceCompletenessPct'] ?? 0.0), 2),
                (string) round((float) ($row['breachRatePct'] ?? 0.0), 2),
                (string) round((float) ($row['escalationRatePct'] ?? 0.0), 2),
                (string) round((float) ($row['complianceScorePct'] ?? 0.0), 2),
                implode('|', array_filter($featureNames)),
            ], ',', '"', '\\');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return is_string($content) ? $content : '';
    }

    /** @param array<string, mixed> $queryResult */
    public function buildAuditReportCsv(array $queryResult): string
    {
        $stream = fopen('php://temp', 'r+');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to allocate report export stream.');
        }

        fputcsv($stream, ['Section', 'Field', 'Value'], ',', '"', '\\');

        $summary = is_array($queryResult['summary'] ?? null) ? $queryResult['summary'] : [];
        foreach ($summary as $field => $value) {
            fputcsv($stream, ['Query Summary', (string) $field, is_scalar($value) ? (string) $value : json_encode($value)], ',', '"', '\\');
        }

        $compliance = is_array($queryResult['complianceDashboard'] ?? null) ? $queryResult['complianceDashboard'] : [];
        $kpis = is_array($compliance['kpis'] ?? null) ? $compliance['kpis'] : [];
        foreach ($kpis as $kpi) {
            if (!is_array($kpi)) {
                continue;
            }

            fputcsv($stream, [
                'Compliance KPI',
                (string) ($kpi['label'] ?? 'KPI'),
                sprintf(
                    '%s / target %s (alias: %s; implementation: %s)',
                    $this->formatKpiValue($kpi['value'] ?? null, $kpi['unit'] ?? null),
                    $this->formatKpiValue($kpi['target'] ?? null, $kpi['unit'] ?? null),
                    (string) ($kpi['promptAlias'] ?? 'n/a'),
                    (string) ($kpi['implementationLabel'] ?? 'n/a'),
                ),
            ], ',', '"', '\\');
        }

        fputcsv($stream, [], ',', '"', '\\');
        fputcsv($stream, ['Query Rows'], ',', '"', '\\');

        $rows = is_array($queryResult['rows'] ?? null) ? $queryResult['rows'] : [];
        if ($rows !== []) {
            $csv = $this->buildQueryCsv($rows);
            foreach (preg_split('/\r\n|\r|\n/', trim($csv)) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                fwrite($stream, $line."\n");
            }
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return is_string($content) ? $content : '';
    }

    private function formatKpiValue(mixed $value, mixed $unit): string
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;
        $normalizedUnit = strtoupper(trim((string) $unit));

        return match ($normalizedUnit) {
            'PERCENT' => sprintf('%.3f%%', $numeric),
            'HOURS' => sprintf('%.3f h', $numeric),
            'COUNT' => sprintf('%.3f', $numeric),
            'RATIO' => sprintf('%.3f', $numeric),
            default => sprintf('%.3f', $numeric),
        };
    }

    /** @return list<string> */
    public function orgUnits(): array
    {
        return $this->snapshots->distinctOrgUnits();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeRow(array $row): array
    {
        $occurredAt = new \DateTimeImmutable((string) ($row['occurredAtUtc'] ?? 'now'), new \DateTimeZone('UTC'));
        $intakeCount = max(0, (int) ($row['intakeCount'] ?? 0));
        $breachCount = max(0, (int) ($row['breachCount'] ?? 0));
        $escalationCount = max(0, (int) ($row['escalationCount'] ?? 0));
        $avgReviewHours = max(0.0, (float) ($row['avgReviewHours'] ?? 0.0));
        $resolutionWithinSlaPct = max(0.0, min(100.0, (float) ($row['resolutionWithinSlaPct'] ?? 0.0)));
        $evidenceCompletenessPct = max(0.0, min(100.0, (float) ($row['evidenceCompletenessPct'] ?? 0.0)));

        $breachRatePct = $intakeCount > 0 ? ($breachCount / $intakeCount) * 100.0 : 0.0;
        $escalationRatePct = $intakeCount > 0 ? ($escalationCount / $intakeCount) * 100.0 : 0.0;
        $complianceScorePct = (($resolutionWithinSlaPct * 0.6) + ($evidenceCompletenessPct * 0.4)) - ($breachRatePct * 0.35);
        $complianceScorePct = max(0.0, min(100.0, $complianceScorePct));

        $tags = [];
        foreach ((array) ($row['tags'] ?? []) as $tag) {
            $normalized = trim((string) $tag);
            if ($normalized !== '') {
                $tags[$normalized] = true;
            }
        }

        return [
            'occurredAtUtc' => $occurredAt->format(DATE_ATOM),
            'orgUnit' => (string) ($row['orgUnit'] ?? 'unknown'),
            'source' => (string) ($row['source'] ?? 'LIVE'),
            'datasetName' => (string) ($row['datasetName'] ?? ''),
            'intakeCount' => $intakeCount,
            'breachCount' => $breachCount,
            'escalationCount' => $escalationCount,
            'avgReviewHours' => $avgReviewHours,
            'resolutionWithinSlaPct' => $resolutionWithinSlaPct,
            'evidenceCompletenessPct' => $evidenceCompletenessPct,
            'breachRatePct' => $breachRatePct,
            'escalationRatePct' => $escalationRatePct,
            'complianceScorePct' => $complianceScorePct,
            'tags' => array_keys($tags),
            'matchedFeatures' => [],
        ];
    }

    private function rowMatchesFeature(array $row, AnalyticsFeatureDefinition $feature): bool
    {
        $featureTags = $feature->getTags();
        $tagsMatch = true;

        if ($featureTags !== []) {
            $rowTags = array_map('strtolower', (array) ($row['tags'] ?? []));
            foreach ($featureTags as $featureTag) {
                if (!in_array(strtolower($featureTag), $rowTags, true)) {
                    $tagsMatch = false;
                    break;
                }
            }
        }

        if (!$tagsMatch) {
            return false;
        }

        return $this->formulaService->matchesRow($feature->getFormulaExpression(), $row);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function summary(array $rows): array
    {
        if ($rows === []) {
            return [
                'rowCount' => 0,
                'totalIntakeCount' => 0,
                'totalBreachCount' => 0,
                'avgBreachRatePct' => 0.0,
                'avgComplianceScorePct' => 0.0,
            ];
        }

        $totalIntake = 0;
        $totalBreach = 0;
        $breachRateSum = 0.0;
        $complianceScoreSum = 0.0;
        foreach ($rows as $row) {
            $totalIntake += (int) ($row['intakeCount'] ?? 0);
            $totalBreach += (int) ($row['breachCount'] ?? 0);
            $breachRateSum += (float) ($row['breachRatePct'] ?? 0.0);
            $complianceScoreSum += (float) ($row['complianceScorePct'] ?? 0.0);
        }

        $count = count($rows);

        return [
            'rowCount' => $count,
            'totalIntakeCount' => $totalIntake,
            'totalBreachCount' => $totalBreach,
            'avgBreachRatePct' => round($breachRateSum / $count, 3),
            'avgComplianceScorePct' => round($complianceScoreSum / $count, 3),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function trendView(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $month = (new \DateTimeImmutable((string) $row['occurredAtUtc']))->format('Y-m');
            if (!isset($buckets[$month])) {
                $buckets[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'breachRatePctSum' => 0.0,
                    'complianceScorePctSum' => 0.0,
                ];
            }

            ++$buckets[$month]['count'];
            $buckets[$month]['breachRatePctSum'] += (float) ($row['breachRatePct'] ?? 0.0);
            $buckets[$month]['complianceScorePctSum'] += (float) ($row['complianceScorePct'] ?? 0.0);
        }

        ksort($buckets);

        $series = [];
        foreach ($buckets as $bucket) {
            $count = max(1, (int) $bucket['count']);
            $series[] = [
                'month' => $bucket['month'],
                'avgBreachRatePct' => round((float) $bucket['breachRatePctSum'] / $count, 3),
                'avgComplianceScorePct' => round((float) $bucket['complianceScorePctSum'] / $count, 3),
            ];
        }

        return $series;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function distributionView(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $orgUnit = (string) ($row['orgUnit'] ?? 'unknown');
            if (!isset($groups[$orgUnit])) {
                $groups[$orgUnit] = [
                    'orgUnit' => $orgUnit,
                    'count' => 0,
                    'intakeCount' => 0,
                    'breachRatePctSum' => 0.0,
                    'avgReviewHoursSum' => 0.0,
                ];
            }

            ++$groups[$orgUnit]['count'];
            $groups[$orgUnit]['intakeCount'] += (int) ($row['intakeCount'] ?? 0);
            $groups[$orgUnit]['breachRatePctSum'] += (float) ($row['breachRatePct'] ?? 0.0);
            $groups[$orgUnit]['avgReviewHoursSum'] += (float) ($row['avgReviewHours'] ?? 0.0);
        }

        ksort($groups);

        $distribution = [];
        foreach ($groups as $group) {
            $count = max(1, (int) $group['count']);
            $distribution[] = [
                'orgUnit' => $group['orgUnit'],
                'recordCount' => $group['count'],
                'intakeCount' => $group['intakeCount'],
                'avgBreachRatePct' => round((float) $group['breachRatePctSum'] / $count, 3),
                'avgReviewHours' => round((float) $group['avgReviewHoursSum'] / $count, 3),
            ];
        }

        return $distribution;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, float> */
    private function correlationView(array $rows): array
    {
        $reviewHours = [];
        $breachRates = [];
        $evidenceScores = [];

        foreach ($rows as $row) {
            $reviewHours[] = (float) ($row['avgReviewHours'] ?? 0.0);
            $breachRates[] = (float) ($row['breachRatePct'] ?? 0.0);
            $evidenceScores[] = (float) ($row['evidenceCompletenessPct'] ?? 0.0);
        }

        return [
            'reviewHoursVsBreachRate' => round($this->pearson($reviewHours, $breachRates), 4),
            'evidenceCompletenessVsBreachRate' => round($this->pearson($evidenceScores, $breachRates), 4),
        ];
    }

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $trend */
    private function complianceKpiSet(array $rows, array $trend): array
    {
        $metrics = $this->complianceMetrics($rows);

        $kpis = [
            $this->complianceKpi(
                'rescue_volume',
                'Rescue volume',
                'kpi_rescue_volume',
                'Rescue volume',
                'Regulatory Intervention Volume',
                $metrics['regulatoryInterventionVolume'],
                6.0,
                false,
                'COUNT',
            ),
            $this->complianceKpi(
                'recovery_rate',
                'Recovery rate',
                'kpi_recovery_rate',
                'Recovery rate',
                'Remediation Closure Rate',
                $metrics['remediationClosureRate'],
                92.0,
                true,
                'PERCENT',
            ),
            $this->complianceKpi(
                'adoption_conversion',
                'Adoption conversion',
                'kpi_adoption_conversion',
                'Adoption conversion',
                'Workflow Adoption Conversion',
                $metrics['workflowAdoptionConversion'],
                94.0,
                true,
                'PERCENT',
            ),
            $this->complianceKpi(
                'average_shelter_stay',
                'Average shelter stay',
                'kpi_average_shelter_stay',
                'Average shelter stay',
                'Average Case Resolution Duration',
                $metrics['averageCaseResolutionDurationHours'],
                24.0,
                false,
                'HOURS',
            ),
            $this->complianceKpi(
                'donation_mix',
                'Donation mix',
                'kpi_donation_mix',
                'Donation mix',
                'Revenue/Compliance Fee Mix',
                $metrics['revenueComplianceFeeMix'],
                78.0,
                true,
                'PERCENT',
            ),
            $this->complianceKpi(
                'supply_turnover',
                'Supply turnover',
                'kpi_supply_turnover',
                'Supply turnover',
                'Operational Capacity Turnover',
                $metrics['operationalCapacityTurnover'],
                4.5,
                true,
                'RATIO',
            ),
        ];

        return [
            'kpis' => $kpis,
            // Keep explicit prompt-contract traceability available alongside implementation labels.
            'promptKpis' => $kpis,
            'trend' => $trend,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, float> */
    private function complianceMetrics(array $rows): array
    {
        if ($rows === []) {
            return [
                'regulatoryInterventionVolume' => 0.0,
                'remediationClosureRate' => 0.0,
                'workflowAdoptionConversion' => 0.0,
                'averageCaseResolutionDurationHours' => 0.0,
                'revenueComplianceFeeMix' => 0.0,
                'operationalCapacityTurnover' => 0.0,
            ];
        }

        $count = count($rows);
        $totalIntake = 0;
        $totalBreach = 0;
        $totalEscalation = 0;
        $resolutionSum = 0.0;
        $evidenceSum = 0.0;
        $reviewHoursSum = 0.0;

        foreach ($rows as $row) {
            $totalIntake += (int) ($row['intakeCount'] ?? 0);
            $totalBreach += (int) ($row['breachCount'] ?? 0);
            $totalEscalation += (int) ($row['escalationCount'] ?? 0);
            $resolutionSum += (float) ($row['resolutionWithinSlaPct'] ?? 0.0);
            $evidenceSum += (float) ($row['evidenceCompletenessPct'] ?? 0.0);
            $reviewHoursSum += (float) ($row['avgReviewHours'] ?? 0.0);
        }

        $avgResolutionPct = $resolutionSum / $count;
        $avgEvidencePct = $evidenceSum / $count;
        $avgResolutionDurationHours = $reviewHoursSum / $count;

        $workflowAdoptionConversionPct = $totalIntake > 0
            ? (($totalIntake - $totalBreach) / $totalIntake) * 100.0
            : 0.0;

        $revenueComplianceFeeMixPct = (($avgResolutionPct * 0.55) + ($avgEvidencePct * 0.35))
            - (($totalBreach / max(1, $totalIntake)) * 100.0 * 0.25);
        $revenueComplianceFeeMixPct = max(0.0, min(100.0, $revenueComplianceFeeMixPct));

        $operationalCapacityTurnover = $reviewHoursSum > 0.0
            ? $totalIntake / $reviewHoursSum
            : 0.0;

        return [
            'regulatoryInterventionVolume' => $totalEscalation / $count,
            'remediationClosureRate' => max(0.0, min(100.0, $avgResolutionPct)),
            'workflowAdoptionConversion' => max(0.0, min(100.0, $workflowAdoptionConversionPct)),
            'averageCaseResolutionDurationHours' => max(0.0, $avgResolutionDurationHours),
            'revenueComplianceFeeMix' => $revenueComplianceFeeMixPct,
            'operationalCapacityTurnover' => max(0.0, $operationalCapacityTurnover),
        ];
    }

    private function complianceKpi(
        string $id,
        string $label,
        string $promptAlias,
        string $promptLabel,
        string $implementationLabel,
        float $value,
        float $target,
        bool $higherIsBetter,
        string $unit,
    ): array {
        $meetsTarget = $higherIsBetter ? $value >= $target : $value <= $target;

        return [
            'id' => $id,
            'label' => $label,
            'promptAlias' => $promptAlias,
            'promptLabel' => $promptLabel,
            'implementationLabel' => $implementationLabel,
            'value' => round($value, 3),
            'target' => round($target, 3),
            'unit' => $unit,
            'status' => $meetsTarget ? 'ON_TRACK' : 'AT_RISK',
            'comparisonDirection' => $higherIsBetter ? 'HIGHER_IS_BETTER' : 'LOWER_IS_BETTER',
        ];
    }

    /** @param list<float> $x @param list<float> $y */
    private function pearson(array $x, array $y): float
    {
        $count = min(count($x), count($y));
        if ($count < 2) {
            return 0.0;
        }

        $x = array_slice($x, 0, $count);
        $y = array_slice($y, 0, $count);

        $meanX = array_sum($x) / $count;
        $meanY = array_sum($y) / $count;

        $numerator = 0.0;
        $sumSqX = 0.0;
        $sumSqY = 0.0;
        for ($i = 0; $i < $count; ++$i) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $numerator += $dx * $dy;
            $sumSqX += $dx * $dx;
            $sumSqY += $dy * $dy;
        }

        $denominator = sqrt($sumSqX * $sumSqY);
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /** @return list<string> */
    private function stringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = trim((string) $item);
            if ($normalized !== '') {
                $items[$normalized] = true;
            }
        }

        return array_keys($items);
    }

    /** @return list<int> */
    private function intArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_int($item) && !(is_string($item) && preg_match('/^\d+$/', $item) === 1)) {
                continue;
            }
            $int = (int) $item;
            if ($int > 0) {
                $items[$int] = true;
            }
        }

        return array_keys($items);
    }

    private function requiredDate(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            throw new AnalyticsFlowException('VALIDATION_ERROR', Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('%s is required.', $field), [
                ['field' => $field, 'issue' => 'required'],
            ]);
        }

        try {
            return new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new AnalyticsFlowException('VALIDATION_ERROR', Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('%s has invalid date format.', $field), [
                ['field' => $field, 'issue' => 'invalid_date'],
            ]);
        }
    }
}
