Fix all remaining gaps from the latest audit report, with one hard requirement:
HARD REQUIREMENT
- True no-mock real-HTTP API coverage must be > 90%.
- Current API surface is 57 endpoints, so true real-HTTP coverage must be at least 52/57 (target 57/57 if feasible).
Context from audit
- Route-level coverage is already 57/57 via Symfony kernel tests.
- True no-mock real-HTTP coverage is only 14/57.
- Real HTTP smoke infrastructure exists:
  - backend/tests/Smoke/AbstractHttpSmokeTestCase.php
  - backend/tests/Smoke/ApiHttpSmokeTest.php
  - backend/tests/Smoke/ApiWorkflowSmokeTest.php
  - scripts/dev/real_http_smoke.sh
  - run_tests.sh
- Remaining quality gaps:
  - Real-HTTP endpoint breadth is insufficient.
  - Frontend still has heavy fetch-mock concentration in App.workflow tests.
  - Some workflow hooks still lack direct focused unit tests.
What to do
1) Raise TRUE real-HTTP API coverage above 90%
- Add/expand backend smoke tests that execute requests over real HTTP transport only (SMOKE_BASE_URL path), not WebTestCase::createClient.
- Cover at least 52 unique endpoints (METHOD + PATH), ideally all 57.
- Include both read and mutating endpoints across all families:
  - auth, permissions, health
  - practitioner + credentials
  - reviewer queue/detail/decision
  - scheduling (config, slots, holds, bookings, reschedule, cancel)
  - question-bank (list/detail/create/update/publish/offline/assets/import/export)
  - analytics (options/query/export/audit-export/features CRUD)
  - governance (audit logs, sensitive logs/read, anomalies, refresh, acknowledge, rollback catalogs, rollback executes, password reset)
  - admin integrations human verification
- For mutating endpoints, include CSRF/session handling and meaningful payloads.
- Assertions must validate:
  - status code
  - key response contract fields
  - security behavior (401/403 where relevant)
  - header behavior for download/export endpoints when applicable.
2) Produce machine-verifiable evidence of true coverage
- Add a script that statically compares:
  - full endpoint inventory from backend controllers
  - endpoints hit by real-HTTP smoke tests only
- Output:
  - total endpoints
  - true HTTP-covered endpoints
  - true coverage %
  - uncovered endpoint list
- Fail CI/test command if true coverage < 90%.
3) Keep existing tests; do not regress
- Do not delete current integration/unit tests.
- Keep kernel integration tests as complementary.
- Keep smoke tests skippable when SMOKE_BASE_URL is not set for local non-stack runs.
4) Improve frontend test quality (non-blocking vs 90% API gate, but required)
- Reduce monolithic fetch route-switch patterns in:
  - frontend/src/App.workflow.test.tsx
- Add focused tests for remaining untested workflow hooks:
  - useCredentialWorkflow
  - useSchedulingWorkflow
  - useQuestionBankWorkflow
  - useGovernanceWorkflow
- Prefer narrow per-test mocks and module-level assertions.
5) Update README/test docs
- Document the new true-coverage gate command and expected threshold (>90%).
- Keep Docker-first instructions aligned with run_tests.sh and smoke scripts.
- Ensure verification steps include running the true-coverage check.
Constraints
- Minimal-risk, production-quality changes.
- Follow existing project conventions.
- No fake or superficial assertions.
- No destructive changes.
Deliverables
- Code changes implementing expanded real-HTTP smoke coverage and coverage gate.
- Updated scripts/docs.
- Final summary including:
  - exact true API coverage percentage achieved
  - covered endpoint count out of 57
  - list of any uncovered endpoints (if any) with reason
  - files changed
  - commands to run locally:
    - ./scripts/dev/real_http_smoke.sh
    - ./run_tests.sh
    - <new true coverage check command>