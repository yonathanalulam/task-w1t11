<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AnalyticsFeatureDefinition;
use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\AnalyticsFeatureDefinitionRepository;
use App\Repository\AnalyticsSampleDatasetRepository;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Service\AnalyticsFeatureFormulaService;
use App\Service\AnalyticsWorkbenchService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/analytics')]
final class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly AnalyticsWorkbenchService $workbench,
        private readonly AnalyticsFeatureDefinitionRepository $features,
        private readonly AnalyticsSampleDatasetRepository $datasets,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AnalyticsFeatureFormulaService $formulaService,
    ) {
    }

    #[Route('/workbench/options', name: 'api_analytics_workbench_options', methods: ['GET'])]
    public function workbenchOptions(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.query');

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'orgUnits' => $this->workbench->orgUnits(),
            'features' => array_map(fn (AnalyticsFeatureDefinition $feature): array => $this->serializeFeature($feature), $this->features->listAll()),
            'sampleDatasets' => array_map(fn ($dataset): array => [
                'id' => $dataset->getId(),
                'name' => $dataset->getName(),
                'description' => $dataset->getDescription(),
                'rowCount' => count($dataset->getRows()),
                'createdAtUtc' => $dataset->getCreatedAtUtc()->format(DATE_ATOM),
            ], $this->datasets->listAll()),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/query', name: 'api_analytics_query', methods: ['POST'])]
    public function runQuery(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.query');

        $payload = $this->jsonBodyParser->parse($request);
        $result = $this->workbench->runQuery($payload);

        $this->auditLogger->log('analytics.query_run', $user->getUsername(), [
            'filters' => $result['filters'] ?? [],
            'rowCount' => is_array($result['rows'] ?? null) ? count($result['rows']) : 0,
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success($result, requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/query/export', name: 'api_analytics_query_export', methods: ['POST'])]
    public function exportQuery(Request $request): Response
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.export');

        $payload = $this->jsonBodyParser->parse($request);
        $result = $this->workbench->runQuery($payload);
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];

        $csv = $this->workbench->buildQueryCsv($rows);
        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="analytics-query-%s.csv"', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His')));

        $this->auditLogger->log('analytics.query_exported', $user->getUsername(), [
            'filters' => $result['filters'] ?? [],
            'rowCount' => count($rows),
        ]);

        return $response;
    }

    #[Route('/audit-report/export', name: 'api_analytics_audit_report_export', methods: ['POST'])]
    public function exportAuditReport(Request $request): Response
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.export');

        $payload = $this->jsonBodyParser->parse($request);
        $result = $this->workbench->runQuery($payload);

        $csv = $this->workbench->buildAuditReportCsv($result);
        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="analytics-audit-report-%s.csv"', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His')));

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $this->auditLogger->log('analytics.audit_report_exported', $user->getUsername(), [
            'filters' => $result['filters'] ?? [],
            'rowCount' => count($rows),
            'kpiCount' => count((array) (($result['complianceDashboard']['kpis'] ?? null))),
        ]);

        return $response;
    }

    #[Route('/features', name: 'api_analytics_features_list', methods: ['GET'])]
    public function listFeatures(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.query');

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'features' => array_map(fn (AnalyticsFeatureDefinition $feature): array => $this->serializeFeature($feature), $this->features->listAll()),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/features', name: 'api_analytics_features_create', methods: ['POST'])]
    public function createFeature(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.feature.manage');

        $payload = $this->jsonBodyParser->parse($request);
        $normalized = $this->normalizeFeaturePayload($payload);

        $feature = new AnalyticsFeatureDefinition(
            $normalized['name'],
            $normalized['description'],
            $normalized['tags'],
            $normalized['formulaExpression'],
            $user->getUsername(),
        );
        $this->entityManager->persist($feature);
        $this->entityManager->flush();

        $this->auditLogger->log('analytics.feature_created', $user->getUsername(), [
            'featureId' => $feature->getId(),
            'name' => $feature->getName(),
            'tags' => $feature->getTags(),
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'feature' => $this->serializeFeature($feature),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/features/{featureId}', name: 'api_analytics_features_update', methods: ['PUT'])]
    public function updateFeature(int $featureId, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->authorization->assertPermission($user, 'analytics.feature.manage');

        $feature = $this->features->find($featureId);
        if (!$feature instanceof AnalyticsFeatureDefinition) {
            return ApiResponse::error('NOT_FOUND', 'Analytics feature not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->jsonBodyParser->parse($request);
        $normalized = $this->normalizeFeaturePayload($payload);

        $feature->update(
            $normalized['name'],
            $normalized['description'],
            $normalized['tags'],
            $normalized['formulaExpression'],
            $user->getUsername(),
        );

        $this->entityManager->flush();

        $this->auditLogger->log('analytics.feature_updated', $user->getUsername(), [
            'featureId' => $feature->getId(),
            'name' => $feature->getName(),
            'tags' => $feature->getTags(),
        ]);

        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'feature' => $this->serializeFeature($feature),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    private function currentUser(): User
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $user;
    }

    /** @param array<string, mixed> $payload @return array{name: string, description: string, tags: list<string>, formulaExpression: string} */
    private function normalizeFeaturePayload(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $formulaExpression = trim((string) ($payload['formulaExpression'] ?? ''));

        if ($name === '' || mb_strlen($name) > 160) {
            throw new ApiValidationException('Feature name is required and must be 160 characters or fewer.', [
                ['field' => 'name', 'issue' => 'required_max_160'],
            ]);
        }

        if ($description === '' || mb_strlen($description) > 4000) {
            throw new ApiValidationException('Feature description is required and must be 4000 characters or fewer.', [
                ['field' => 'description', 'issue' => 'required_max_4000'],
            ]);
        }

        if ($formulaExpression === '' || mb_strlen($formulaExpression) > 2000) {
            throw new ApiValidationException('Feature formulaExpression is required and must be 2000 characters or fewer.', [
                ['field' => 'formulaExpression', 'issue' => 'required_max_2000'],
            ]);
        }

        try {
            $this->formulaService->validate($formulaExpression);
        } catch (\InvalidArgumentException $e) {
            throw new ApiValidationException('Feature formulaExpression is invalid.', [
                ['field' => 'formulaExpression', 'issue' => 'invalid_syntax'],
            ]);
        }

        $rawTags = $payload['tags'] ?? null;
        if (!is_array($rawTags)) {
            throw new ApiValidationException('tags must be an array of non-empty values.', [
                ['field' => 'tags', 'issue' => 'must_be_array'],
            ]);
        }

        $tags = [];
        foreach ($rawTags as $tag) {
            $normalizedTag = trim((string) $tag);
            if ($normalizedTag === '') {
                continue;
            }

            if (mb_strlen($normalizedTag) > 60) {
                throw new ApiValidationException('Tag values must be 60 characters or fewer.', [
                    ['field' => 'tags', 'issue' => 'tag_max_60'],
                ]);
            }

            $tags[$normalizedTag] = true;
        }

        if ($tags === []) {
            throw new ApiValidationException('At least one non-empty feature tag is required.', [
                ['field' => 'tags', 'issue' => 'required'],
            ]);
        }

        return [
            'name' => $name,
            'description' => $description,
            'tags' => array_keys($tags),
            'formulaExpression' => $formulaExpression,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeFeature(AnalyticsFeatureDefinition $feature): array
    {
        return [
            'id' => $feature->getId(),
            'name' => $feature->getName(),
            'description' => $feature->getDescription(),
            'tags' => $feature->getTags(),
            'formulaExpression' => $feature->getFormulaExpression(),
            'updatedAtUtc' => $feature->getUpdatedAtUtc()->format(DATE_ATOM),
        ];
    }
}
