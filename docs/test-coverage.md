# Regulatory Operations & Analytics Portal — Coverage Evidence Snapshot

## 1) Current coverage posture

Status legend:
- **Complete**: implemented and exercised in current verification cadence.
- **Partial**: implemented with meaningful tests, but explicit edge/risk gaps remain.
- **Planned**: not yet implemented as a concrete automated test.

Current overall status: **Partial**.

Implemented slices have real backend integration tests, frontend workflow tests, and Playwright coverage. Broad convergence is currently based on:
- `./run_tests.sh` (backend + frontend + e2e)
- `docker compose up --build` runtime readiness

## 2) Actual test surfaces in this codebase

Backend (PHPUnit):
- `backend/tests/Integration/Security/ApiAuthBoundaryTest.php`
- `backend/tests/Integration/Security/AuthSecurityHardeningTest.php`
- `backend/tests/Integration/Controller/PractitionerCredentialWorkflowTest.php`
- `backend/tests/Integration/Controller/SchedulingControllerTest.php`
- `backend/tests/Integration/Controller/QuestionBankControllerTest.php`
- `backend/tests/Integration/Controller/AnalyticsControllerTest.php`
- `backend/tests/Integration/Controller/AdminGovernanceControllerTest.php`
- `backend/tests/Integration/Controller/HealthControllerTest.php`
- `backend/tests/Integration/Command/GovernanceLogRetentionCommandTest.php`
- `backend/tests/Unit/Security/PermissionRegistryTest.php`

Frontend (Vitest + RTL):
- `frontend/src/App.test.tsx`
- `frontend/src/App.workflow.test.tsx`
- `frontend/src/app/permissionRegistry.test.ts`

E2E (Playwright):
- `frontend/e2e/portal.spec.ts`

## 3) Requirement/risk mapping

| Requirement / risk cluster | Evidence tests | Key assertions (concrete) | Status | Real remaining gap |
|---|---|---|---|---|
| Auth boundary and role-permission alignment | `ApiAuthBoundaryTest.php`, `PermissionRegistryTest.php`, `App.workflow.test.tsx` | Anonymous denied on protected routes; role-derived permissions drive access; admin/reviewer/practitioner views align with backend permission model | Complete | None identified for current scope |
| Practitioner profile + credential submission/review | `PractitionerCredentialWorkflowTest.php`, `App.workflow.test.tsx`, `portal.spec.ts` | Profile persistence and validation, upload flow, reviewer decision actions, status/history rendering, audit event flow | Partial | Add explicit invalid MIME / oversize upload rejection assertions in a dedicated backend test method |
| Scheduling policy and contention correctness | `SchedulingControllerTest.php`, `App.workflow.test.tsx`, `portal.spec.ts` | Horizon limit, reschedule/cancel policy, hold lifecycle, overlap rejection, contention guard behavior | Partial | Add timezone/DST boundary assertions around 24-hour cancellation policy |
| Question-bank lifecycle/versioning/duplicate gate | `QuestionBankControllerTest.php`, `App.workflow.test.tsx`, `portal.spec.ts` | Draft/edit version increments, publish/offline transitions, duplicate-review block, override publish, CSV/XLSX import/export | Partial | Add deterministic near-threshold duplicate corpus tests (currently high-similarity path only) |
| Analytics query + compliance KPI contract | `AnalyticsControllerTest.php`, `App.workflow.test.tsx`, `portal.spec.ts` | Query/filter behavior, export paths, 6-KPI set surfaced with prompt-traceability fields (`promptAlias`, `promptLabel`, `implementationLabel`) | Complete | Reviewer should still manually confirm wording parity against owner prompt text |
| Analytics feature formula semantics | `AnalyticsControllerTest.php` | Feature formulas are syntax-validated at create/update; `featureIds` query filtering now reflects formula truth (false formula yields 0 rows, permissive formula yields matches) | Complete | Formula language is arithmetic/comparison only (no complex functions) |
| Governance admin controls (audit/sensitive/anomaly/rollback/reset) | `AdminGovernanceControllerTest.php`, `App.workflow.test.tsx`, `portal.spec.ts` | Immutable evidence views, sensitive-read reason + logging, anomaly refresh/ack, rollback step-up + justification, admin-only password reset, and explicit non-admin denial on sensitive reveal | Complete | None material for current slice |
| Governance retention enforcement (7-year floor) | `GovernanceLogRetentionCommandTest.php`, `AdminGovernanceControllerTest.php` | Dry-run reports eligible counts; purge deletes only rows older than seven years; governance read APIs include retention policy metadata | Complete | Archive-tier/offline cold-storage workflow is not implemented |
| Auth lockout/CAPTCHA and CSRF negatives | `AuthSecurityHardeningTest.php`, `ApiAuthBoundaryTest.php` | CAPTCHA required after repeated failures; lockout triggered after threshold; mutating API rejects missing and invalid CSRF tokens | Complete | No browser-level CAPTCHA UX assertions in backend suite |
| Health readiness success/failure paths | `HealthControllerTest.php` | `/api/health/ready` returns healthy dependency payload; invalid keyring yields `503 NOT_READY` | Complete | Failure simulation currently keyring-focused (not DB outage simulation) |
| Runtime/test harness stability (integrated verification hardening) | `init_db.sh`, `run_tests.sh`, compose startup checks used by broad gates | Isolated `${DB_NAME}_test` reset on init; backend suite points at test DB; DB readiness gate before tests; runtime readiness on host port | Complete | Monitor for host resource-driven e2e timing variance |

## 4) Known non-blocking risks before evaluation

1. **Symfony validator deprecations remain** in PHPUnit output (non-fatal); they do not currently block gates.
2. **Scheduling e2e runtime variance** exists; timeout is increased to reduce flakes, but slower hosts may still need extra headroom.
3. **Coverage depth gaps** listed above are risk-focused improvements, not correctness blockers for currently implemented prompt scope.

## 5) Verification commands used for this coverage model

Primary broad gate:
- `./run_tests.sh`

Primary runtime gate:
- `docker compose up --build`

Targeted verification examples used during hardening:
- `./init_db.sh`
- `docker compose run --rm --no-deps api bash -lc '... php bin/phpunit tests/Integration/Controller/QuestionBankControllerTest.php --filter testDuplicateSimilarityBlocksPublishUntilOverrideReview'`
- `docker compose run --rm --no-deps api bash -lc '... php bin/phpunit tests/Integration/Security/ApiAuthBoundaryTest.php tests/Integration/Controller/AdminGovernanceControllerTest.php tests/Integration/Controller/PractitionerCredentialWorkflowTest.php tests/Unit/Security/PermissionRegistryTest.php'`
- `docker compose run --rm --no-deps web bash -lc '/workspace/scripts/dev/web_test.sh'`
- `docker compose --profile test run --rm --no-deps e2e`
