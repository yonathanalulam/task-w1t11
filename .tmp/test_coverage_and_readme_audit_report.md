# Test Coverage Audit

## Scope and Method
- Mode: static inspection only (no command/test execution for validation outcomes).
- Project type declaration check: `Project Type: fullstack` is present at top of `README.md:1`.
- Inferred type: **fullstack** (also consistent with stack declaration in `README.md:9-13`).

## Backend Endpoint Inventory
Total unique endpoints: **57** (`METHOD + resolved PATH`, controller prefixes included), based on route attributes in `backend/src/Controller/*.php`.

### API Test Mapping Table
Legend:
- `TRUE_HTTP`: real network-style HTTP in smoke tests (`backend/tests/Smoke/*`, `SMOKE_BASE_URL`)
- `KERNEL_HTTP`: Symfony kernel client (`WebTestCase::createClient` + `->request`)

Note: the table captures direct exemplar evidence per endpoint; true-HTTP final coverage is additionally determined by static route-to-smoke mapping in `scripts/dev/http_coverage_check.php` and enforced in `run_tests.sh:75-79`.

| Endpoint | Covered | Type | Evidence |
|---|---|---|---|
| GET /api/auth/csrf-token | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiHttpSmokeTest.php::testSessionLifecycleWithRealCookieAndCsrfRoundtrip`; `backend/tests/Integration/Security/ApiAuthBoundaryTest.php::testPublicApiRoutesRemainAccessibleWithoutAuthentication` |
| GET /api/auth/captcha | yes | KERNEL_HTTP | `backend/tests/Integration/Security/AuthSecurityHardeningTest.php::testCaptchaRequiredAfterThreeFailedAttemptsAndLockoutAfterFive` |
| POST /api/auth/register | yes | KERNEL_HTTP | `backend/tests/Integration/Security/AuthSecurityHardeningTest.php::testCaptchaRequiredAfterThreeFailedAttemptsAndLockoutAfterFive` |
| POST /api/auth/login | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiHttpSmokeTest.php::testSessionLifecycleWithRealCookieAndCsrfRoundtrip`; `backend/tests/Integration/Security/ApiAuthBoundaryTest.php::testProtectedRouteSucceedsAfterSessionLogin` |
| POST /api/auth/logout | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiHttpSmokeTest.php::testSessionLifecycleWithRealCookieAndCsrfRoundtrip`; `backend/tests/Integration/Security/AuthSecurityHardeningTest.php::testMutatingApiRejectsMissingAndInvalidCsrfTokens` |
| GET /api/auth/me | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiHttpSmokeTest.php::testSessionLifecycleWithRealCookieAndCsrfRoundtrip`; `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetAuthMeReturnsUsernameAndRolesAfterLogin` |
| GET /api/permissions/me | yes | KERNEL_HTTP | `backend/tests/Integration/Security/ApiAuthBoundaryTest.php::testProtectedRouteSucceedsAfterSessionLogin` |
| GET /api/health/live | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiHttpSmokeTest.php::testHealthLiveReturnsTwoHundredOverRealHttp`; `backend/tests/Integration/Controller/HealthControllerTest.php::testLiveEndpointReturnsSuccess` |
| GET /api/health/ready | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/HealthControllerTest.php::testReadyEndpointReturnsDependencyStatusWhenHealthy` |
| GET /api/practitioner/profile | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testPractitionerProfileUpsertAndReadRoundtripOverRealHttp`; `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetPractitionerProfileReturnsNullWhenAbsentAndDataAfterUpsert` |
| PUT /api/practitioner/profile | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testPractitionerProfileUpsertAndReadRoundtripOverRealHttp`; `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php::testStandardUserCanMaintainProfileAndUploadCredential` |
| GET /api/practitioner/credentials | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testPractitionerCredentialListReturnsEnvelopeOverRealHttp`; `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetPractitionerCredentialsReportsProfileRequiredBeforeUpsert` |
| POST /api/practitioner/credentials | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php::testStandardUserCanMaintainProfileAndUploadCredential` |
| POST /api/practitioner/credentials/{submissionId}/resubmit | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php::testReviewerDecisionFlowEnforcesCommentsAndObjectAuthorization` |
| GET /api/reviewer/credentials/queue | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testReviewerQueueReturnsEnvelopeForReviewerRoleOverRealHttp`; `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php::testReviewerDecisionFlowEnforcesCommentsAndObjectAuthorization` |
| GET /api/reviewer/credentials/{submissionId} | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetReviewerCredentialDetailRequiresReviewerAndResolvesExistingSubmission` |
| POST /api/reviewer/credentials/{submissionId}/decision | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php::testReviewerDecisionFlowEnforcesCommentsAndObjectAuthorization` |
| GET /api/credentials/versions/{versionId}/download | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php::testSystemAdminCanPerformCredentialOversightReviewAndDownload` |
| GET /api/scheduling/configuration | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testSchedulingConfigurationRoundtripForAdminOverRealHttp`; `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetSchedulingConfigurationRequiresSchedulingAdmin` |
| PUT /api/scheduling/configuration | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testSchedulingConfigurationRoundtripForAdminOverRealHttp`; `backend/tests/Integration/Controller/SchedulingControllerTest.php::testAdminCanConfigureAndGenerateSlots` |
| POST /api/scheduling/slots/generate | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/SchedulingControllerTest.php::testAdminCanConfigureAndGenerateSlots` |
| GET /api/scheduling/slots | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/SchedulingControllerTest.php::testListSlotsRequiresAuthentication` |
| POST /api/scheduling/slots/{slotId}/hold | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/SchedulingControllerTest.php::testHoldAndBookFlowProvidesConflictFeedback` |
| POST /api/scheduling/holds/{holdId}/release | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testReleaseHoldEndpointMovesHoldToReleasedState` |
| POST /api/scheduling/holds/{holdId}/book | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/SchedulingControllerTest.php::testHoldAndBookFlowProvidesConflictFeedback` |
| GET /api/scheduling/bookings/me | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetSchedulingBookingsMeReturnsCurrentUserBookings` |
| POST /api/scheduling/bookings/{bookingId}/reschedule | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/SchedulingControllerTest.php::testRescheduleLimitAndCancelWindowRules` |
| POST /api/scheduling/bookings/{bookingId}/cancel | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/SchedulingControllerTest.php::testRescheduleLimitAndCancelWindowRules` |
| GET /api/question-bank/questions | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testQuestionBankListIsVisibleToContentAdminOverRealHttp`; `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testQuestionBankEndpointsRequireQuestionPermissions` |
| GET /api/question-bank/questions/{entryId} | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testContentAdminCanCreateEditAndViewVersionHistory` |
| POST /api/question-bank/questions | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testContentAdminCanCreateEditAndViewVersionHistory` |
| PUT /api/question-bank/questions/{entryId} | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testContentAdminCanCreateEditAndViewVersionHistory` |
| POST /api/question-bank/questions/{entryId}/publish | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testDuplicateSimilarityBlocksPublishUntilOverrideReview` |
| POST /api/question-bank/questions/{entryId}/offline | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testOfflineTransitionAndBulkImportExportAreSupported` |
| POST /api/question-bank/assets | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testQuestionContentSupportsEmbeddedImageAssetsAndFormulaModel` |
| GET /api/question-bank/assets/{assetId}/download | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testQuestionContentSupportsEmbeddedImageAssetsAndFormulaModel` |
| POST /api/question-bank/import | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testOfflineTransitionAndBulkImportExportAreSupported` |
| GET /api/question-bank/export | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/QuestionBankControllerTest.php::testOfflineTransitionAndBulkImportExportAreSupported` |
| GET /api/analytics/workbench/options | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AnalyticsControllerTest.php::testAnalyticsWorkbenchRequiresAnalyticsPermissions` |
| POST /api/analytics/query | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AnalyticsControllerTest.php::testAnalystCanRunQueryAndReceiveComplianceDashboard` |
| POST /api/analytics/query/export | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiHttpSmokeTest.php::testAnalystCsvExportDeliversAttachmentOverRealHttp`; `backend/tests/Integration/Controller/AnalyticsControllerTest.php::testExportEndpointsProduceCsvAndAuditEvents` |
| POST /api/analytics/audit-report/export | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AnalyticsControllerTest.php::testExportEndpointsProduceCsvAndAuditEvents` |
| GET /api/analytics/features | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetAnalyticsFeaturesRequiresAnalyticsPermission` |
| POST /api/analytics/features | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AnalyticsControllerTest.php::testFeatureDefinitionCrudSupportsAnalystAndEnforcesValidation` |
| PUT /api/analytics/features/{featureId} | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AnalyticsControllerTest.php::testFeatureDefinitionCrudSupportsAnalystAndEnforcesValidation` |
| GET /api/admin/governance/audit-logs | yes | TRUE_HTTP + KERNEL_HTTP | `backend/tests/Smoke/ApiWorkflowSmokeTest.php::testGovernanceAuditLogsReadableBySystemAdminOverRealHttp`; `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testAuditAndSensitiveLogAccessIsSystemAdminOnly` |
| GET /api/admin/governance/sensitive-access-logs | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testAuditAndSensitiveLogAccessIsSystemAdminOnly` |
| POST /api/admin/governance/sensitive/practitioner-profiles/{profileId}/license | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testAuditAndSensitiveLogAccessIsSystemAdminOnly` |
| GET /api/admin/governance/anomalies | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetAdminAnomaliesRequiresAuditRead` |
| POST /api/admin/governance/anomalies/refresh | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testAnomalyRefreshDetectsRejectedCredentialSpikeAndSupportsAcknowledgement` |
| POST /api/admin/governance/anomalies/{alertId}/acknowledge | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testAnomalyRefreshDetectsRejectedCredentialSpikeAndSupportsAcknowledgement` |
| GET /api/admin/governance/rollback/credential-submissions | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetRollbackCatalogsRequireAdminRollbackPermission` |
| GET /api/admin/governance/rollback/question-entries | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetRollbackCatalogsRequireAdminRollbackPermission` |
| POST /api/admin/governance/rollback/credentials | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testCredentialRollbackRequiresStepUpAndJustificationAndCreatesNewVersion` |
| POST /api/admin/governance/rollback/questions | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testQuestionRollbackAndAdminPasswordResetWorkflow` |
| POST /api/admin/governance/users/password-reset | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php::testQuestionRollbackAndAdminPasswordResetWorkflow` |
| GET /api/admin/integrations/human-verification | yes | KERNEL_HTTP | `backend/tests/Integration/Controller/ApiRouteCoverageTest.php::testGetAdminHumanVerificationStatusRequiresSystemAdminRole` |

## API Test Classification
1. **True No-Mock HTTP**
   - `backend/tests/Smoke/ApiHttpSmokeTest.php`
   - `backend/tests/Smoke/ApiWorkflowSmokeTest.php`
   - `backend/tests/Smoke/ApiAuthPermissionsHealthSmokeTest.php`
   - `backend/tests/Smoke/ApiSchedulingFullSmokeTest.php`
   - `backend/tests/Smoke/ApiCredentialsLifecycleSmokeTest.php`
   - `backend/tests/Smoke/ApiAnalyticsFullSmokeTest.php`
   - `backend/tests/Smoke/ApiGovernanceFullSmokeTest.php`
   - `backend/tests/Smoke/ApiQuestionBankFullSmokeTest.php`
   - Shared transport harness: `backend/tests/Smoke/AbstractHttpSmokeTestCase.php`
2. **HTTP with Mocking**
   - Frontend UI unit tests with mocked `fetch`: `frontend/src/App.workflow.test.tsx` and global stub in `frontend/src/test/setup.ts`
3. **Non-HTTP (unit/integration without real network transport)**
   - Backend integration suite built on kernel client (`backend/tests/Integration/**`, `WebTestCase::createClient`)
   - Backend unit tests (`backend/tests/Unit/**`)

## Mock Detection
- `frontend/src/test/setup.ts:11-23`: `vi.stubGlobal('fetch', ...)` (global transport stub).
- `frontend/src/App.workflow.test.tsx:20-21`, `247-248`, `391-392`, `573-574`, `908-909`, `1196-1197`: large route-switch fetch mocks.
- `frontend/src/api/client.test.ts:18` and `frontend/src/workflows/hooks/useAnalyticsWorkflow.test.tsx:18`: per-test narrow fetch mocks.
- Backend API tests: no DI override or controller/service mocking evidence in `backend/tests/Integration/**` or `backend/tests/Smoke/**`.

## Coverage Summary
- Total endpoints: **57**
- Endpoints with request-based HTTP route coverage (kernel and/or real HTTP): **57/57**
- Endpoints with true no-mock real-HTTP evidence: **57/57** (static parser gate: `scripts/dev/http_coverage_check.php`, threshold enforced by `run_tests.sh:75-79`)
- HTTP coverage %: **100.00%**
- True API coverage %: **100.00%**

## Unit Test Summary

### Backend Unit Tests
- Files:
  - `backend/tests/Unit/Security/PermissionRegistryTest.php`
  - `backend/tests/Unit/Security/KeyringProviderTest.php`
  - `backend/tests/Unit/Security/PasswordHasherConfigTest.php`
  - `backend/tests/Unit/Service/LicenseNumberMaskerTest.php`
- Covered module categories:
  - security mapping/configuration
  - keyring provider
  - masking helper service
- Important backend modules not unit-tested directly:
  - controller layer (`backend/src/Controller/*`)
  - core domain services (`SchedulingService`, `AdminGovernanceService`, `AnalyticsWorkbenchService`)
  - repository query behavior (outside integration-level assertions)

### Frontend Unit Tests (STRICT)
- Frontend test files detected:
  - `frontend/src/App.test.tsx`
  - `frontend/src/App.workflow.test.tsx`
  - `frontend/src/app/permissionRegistry.test.ts`
  - `frontend/src/api/client.test.ts`
  - `frontend/src/workflows/hooks/useCredentialWorkflow.test.tsx`
  - `frontend/src/workflows/hooks/useSchedulingWorkflow.test.tsx`
  - `frontend/src/workflows/hooks/useQuestionBankWorkflow.test.tsx`
  - `frontend/src/workflows/hooks/useAnalyticsWorkflow.test.tsx`
  - `frontend/src/workflows/hooks/useGovernanceWorkflow.test.tsx`
  - `frontend/src/workflows/utils.test.ts`
- Framework evidence:
  - Vitest config (`frontend/vite.config.ts:22-27`)
  - Testing Library imports (`frontend/src/App.test.tsx`, `frontend/src/App.workflow.test.tsx`, `frontend/src/workflows/hooks/useAnalyticsWorkflow.test.tsx`)
- Covered frontend modules/components:
  - `App` scaffold and workflow UI paths
  - `permissionRegistry`
  - `api/client` transport envelope functions
  - `useCredentialWorkflow` state/validation paths
  - `useSchedulingWorkflow` state/validation/request paths
  - `useQuestionBankWorkflow` state/load/publish-guard paths
  - `useAnalyticsWorkflow` state transitions + API interaction paths
  - `useGovernanceWorkflow` state/validation/request paths
  - `workflows/utils` helpers
- Important frontend modules not directly unit-tested:
  - `frontend/src/workflows/Panels.tsx` (direct tests absent)
- **Mandatory verdict:** **Frontend unit tests: PRESENT**

### Cross-Layer Observation
- Backend route coverage is comprehensive.
- Frontend unit breadth is now balanced across API client and all workflow hooks, but `App.workflow.test.tsx` remains heavily fetch-mock driven.
- Real high-fidelity layers are now stronger via backend smoke over real HTTP and existing Playwright e2e.

## API Observability Check
- Strong evidence in backend tests for method/path + payload + response:
  - Kernel examples: `backend/tests/Integration/Controller/ApiRouteCoverageTest.php`
  - Real HTTP examples: `backend/tests/Smoke/ApiHttpSmokeTest.php`, `backend/tests/Smoke/ApiWorkflowSmokeTest.php`
- Weak area: some frontend UI assertions still validate mocked routes rather than observed backend responses.

## Test Quality & Sufficiency
- Strengths:
  - broad success/failure/authorization/validation coverage in integration tests
  - true HTTP smoke spans all critical endpoint families and is gate-checked by static route-to-smoke mapping (`scripts/dev/http_coverage_check.php`)
  - Docker-first test orchestration includes preflight + smoke + true-HTTP coverage gate + e2e (`run_tests.sh:4-6`, `59-79`, `96-97`)
- Remaining sufficiency gap:
  - large frontend workflow test file still uses broad route-switch fetch mocks (`frontend/src/App.workflow.test.tsx`)

## Tests Check
- `run_tests.sh` is Docker-based and includes backend, frontend, smoke, true-HTTP coverage gate, and e2e stages.
- `scripts/dev/docker_preflight.sh` detects Docker credential-helper and public image pull problems.
- `scripts/dev/http_coverage_check.sh` and `scripts/dev/http_coverage_check.php` provide a dedicated real-HTTP coverage gate interface.

## Test Coverage Score (0-100)
- **96 / 100**

## Score Rationale
- + Full route-level request coverage (57/57)
- + Meaningful negative-path and permission assertions
- + Static true-HTTP gate indicates full endpoint mapping coverage (57/57, 100%)
- + Frontend unit suite covers API client + all workflow hooks
- - Heavy mock concentration remains in `App.workflow.test.tsx`
- - Static audit did not execute the gate script; runtime success is inferred from source wiring, not observed command output

## Key Gaps
- Refactor `frontend/src/App.workflow.test.tsx` into smaller scenario tests with narrower per-test mocks to reduce brittleness.
- Add direct tests for `frontend/src/workflows/Panels.tsx` render contracts (currently covered only indirectly through `App` tests).

## Confidence & Assumptions
- Confidence: **high** (route definitions and test evidence are explicit in source).
- Assumption: kernel-client tests count for handler coverage but not true transport coverage.

---

# README Audit

## README Location
- Present at required path: `README.md`.

## Hard Gate Review

### Formatting
- PASS: structured markdown with clear sections and command blocks.

### Startup Instructions (fullstack/backend gate)
- PASS: includes literal `docker-compose up` (`README.md:19-21`).
- Also includes modern CLI variant `docker compose up --build` (`README.md:23-25`).

### Access Method
- PASS: URL+port provided (`README.md:32`, `README.md:301`).

### Verification Method
- PASS: explicit API verification commands + UI flow verification steps (`README.md:267-305`).

### Environment Rules (strict Docker-contained)
- PASS: no forbidden runtime-install instructions detected (`npm install`, `pip install`, `apt-get`, manual DB setup absent from README instructions).

### Demo Credentials (auth conditional)
- PASS with caveat:
  - role usernames listed in table (`README.md:310-317`)
  - password mechanism documented (`${DEV_BOOTSTRAP_PASSWORD}` retrieval at `README.md:322-332`)
  - deterministic explicit dev password path provided (`README.md:336-352`, value `local-dev-password-123`).

## Engineering Quality
- Strong:
  - clear stack and architecture coverage
  - comprehensive endpoint/workflow documentation
  - explicit test/preflight/smoke command paths
- Minor weakness:
  - some duplication/overlap in credential wording between `Demo credentials` and later `Seeded development users` sections.

## High Priority Issues
- None.

## Medium Priority Issues
- Credential documentation has overlapping dynamic vs deterministic messaging that could be consolidated for clarity.

## Low Priority Issues
- README length is high; some sections could link to focused docs for maintainability.

## Hard Gate Failures
- None detected.

## README Verdict
- **PASS**
