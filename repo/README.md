Project Type: fullstack

# Regulatory Operations & Analytics Portal

This repository is a Docker-first **on-premise, offline-capable** Regulatory Operations & Analytics Portal with implemented practitioner credential-review, scheduling, controlled question-bank, and analyst analytics/compliance workflows.

## Stack

- **Frontend:** React + Vite + TypeScript
- **Backend:** Symfony 7 (REST-style JSON APIs)
- **Database:** MySQL 8.4
- **Worker:** Symfony Messenger worker service
- **E2E:** Playwright

## Primary runtime contract

Supported startup commands (both work; use whichever matches your local Docker CLI):

```bash
docker-compose up
```

```bash
docker compose up --build
```

Default runtime includes a one-shot local bootstrap step and then starts (`db`, `api`, `worker`, `web`).
The Playwright container is test-only and excluded from default runtime via Docker Compose profile.

Host exposure by default:

- `127.0.0.1:4280` → frontend app (Vite dev server)

If port `4280` is already in use, set `WEB_HOST_PORT` before runtime/test commands (for example `WEB_HOST_PORT=4380 docker compose up --build`).

Internal services (`db`, `api`, `worker`) are not exposed to host ports by default.

## Primary test contract

Broad, Dockerized test wrapper:

```bash
./run_tests.sh
```

This runs:

1. DB initialization via `./init_db.sh`
2. Backend PHPUnit suite in container
3. Frontend Vitest suite in container
4. Playwright E2E suite in container (via compose `test` profile)

`./run_tests.sh` waits for DB health before backend tests and executes backend tests against isolated `${DB_NAME}_test`.

## Database initialization contract

Use this path for project-standard DB setup:

```bash
./init_db.sh
```

This initializes schema/migrations for dev + test app environments and seeds local role users for development.

`./init_db.sh` provisions two local databases from runtime bootstrap values:

- dev app DB: `${DB_NAME}`
- test app DB: `${DB_NAME}_test`

The test suite uses the isolated `${DB_NAME}_test` database so repeated runs do not accumulate state in the dev DB.

## Local runtime bootstrap (dev-only)

The Docker startup path automatically invokes:

`scripts/dev/bootstrap_runtime.sh`

It creates local runtime values and keyring material in Docker volumes (DB creds, app secret, field-encryption keyring).

**Important:** this bootstrap is for **local development only** and is **not production secret management**.

No `.env` files are used or required.

## Baseline behavior implemented in scaffold

### Backend foundations

- API auth boundary is enforced by default:
  - public routes: `/api/health/*`, `/api/auth/login`, `/api/auth/register`, `/api/auth/captcha`, `/api/auth/csrf-token`
  - all other `/api/*` routes require an authenticated session
  - review anchors: `backend/config/packages/security.yaml`, `backend/src/Security/ApiRouteAccessPolicy.php`, `backend/src/Security/ApiSessionAuthenticator.php`
- Session-based auth shell with JSON endpoints:
  - `POST /api/auth/register`
  - `POST /api/auth/login`
  - `POST /api/auth/logout`
  - `GET /api/auth/me`
  - `GET /api/auth/csrf-token`
  - `GET /api/auth/captcha`
- CSRF enforcement on mutating API routes (header `X-CSRF-Token`, scoped exclusions)
- Explicit bcrypt password hasher configuration (`security.yaml`)
- Lockout baseline: 5 failed attempts → 15-minute lockout, local CAPTCHA challenge path
- Permission registry foundation (role → permissions/navigation)
- Frontend permission-gated controls are driven from backend-resolved permissions (`/api/permissions/me`), not duplicated frontend permission maps
- Normalized JSON error envelope and request ID propagation
- Audit logging path (DB + monolog `audit` channel)
- Sensitive-access log entity/service foundation
- AES-256-GCM field encryption service + local keyring provider
- Messenger worker + async transport foundation
- Health endpoints:
  - `GET /api/health/live`
  - `GET /api/health/ready`
- Practitioner profile and credential workflow endpoints:
  - `GET /api/practitioner/profile`
  - `PUT /api/practitioner/profile`
  - `GET /api/practitioner/credentials`
  - `POST /api/practitioner/credentials` (multipart form upload)
  - `POST /api/practitioner/credentials/{submissionId}/resubmit` (multipart form upload)
  - `GET /api/reviewer/credentials/queue`
  - `GET /api/reviewer/credentials/{submissionId}`
  - `POST /api/reviewer/credentials/{submissionId}/decision`
  - `GET /api/credentials/versions/{versionId}/download`
  - reviewer/admin oversight actions are guarded by `credential.review` (granted to both `ROLE_CREDENTIAL_REVIEWER` and `ROLE_SYSTEM_ADMIN`)
- License-number handling:
  - encrypted at rest with AES-256-GCM keyring-backed field encryption
  - masked by default in API/UI responses
- Credential file handling:
  - accepts PDF/JPG/PNG up to 10 MB
  - persisted in local runtime storage under `/workspace/runtime/storage/credentials` (Docker volume-backed for local dev)
  - upload/resubmission and review decisions are audit-logged
- Scheduling workflow endpoints:
  - `GET /api/scheduling/configuration` (`scheduling.admin`)
  - `PUT /api/scheduling/configuration` (`scheduling.admin`)
  - `POST /api/scheduling/slots/generate` (`scheduling.admin`)
  - `GET /api/scheduling/slots` (`appointment.book.self` or `scheduling.admin`)
  - `POST /api/scheduling/slots/{slotId}/hold`
  - `POST /api/scheduling/holds/{holdId}/release`
  - `POST /api/scheduling/holds/{holdId}/book`
  - `GET /api/scheduling/bookings/me`
  - `POST /api/scheduling/bookings/{bookingId}/reschedule`
  - `POST /api/scheduling/bookings/{bookingId}/cancel`
  - enforced scheduling rules:
    - weekly availability config + slot generation (default 30-minute duration)
    - 10-minute hold lifecycle with expiry enforcement
    - no booking more than 90 days ahead
    - max 2 reschedules per appointment
    - cancellation inside 24 hours blocked unless system-admin override
    - overlapping booking conflict checks for the same practitioner + location
    - transaction + row-lock (`FOR UPDATE`) critical sections for hold/book/reschedule/cancel
    - audit events for configuration changes, slot generation, holds, bookings, reschedules, cancellations, and overrides
- Question-bank workflow endpoints:
  - `GET /api/question-bank/questions`
  - `GET /api/question-bank/questions/{entryId}`
  - `POST /api/question-bank/questions`
  - `PUT /api/question-bank/questions/{entryId}`
  - `POST /api/question-bank/questions/{entryId}/publish`
  - `POST /api/question-bank/questions/{entryId}/offline`
  - `POST /api/question-bank/assets` (embedded-image upload)
  - `GET /api/question-bank/assets/{assetId}/download`
  - `POST /api/question-bank/import` (bulk CSV / Excel `.xlsx` upload)
  - `GET /api/question-bank/export?format=csv|excel` (CSV + Excel `.xlsx` export)
  - enforced question-bank rules:
    - lifecycle states: `DRAFT` / `PUBLISHED` / `OFFLINE`
    - difficulty range enforcement: `1..5`
    - tagging is mandatory for each question entry
    - rich text, formula list, and embedded image metadata are persisted in the content model
    - edit operations create content versions and reset active draft state
    - publish runs textual-similarity duplicate screening and blocks publish until duplicate review override is explicitly provided
    - audit events for create/edit/publish/offline/import/export and duplicate-review actions
- Analytics + compliance workbench endpoints:
  - `GET /api/analytics/workbench/options`
  - `POST /api/analytics/query`
  - `POST /api/analytics/query/export` (CSV export of query result rows)
  - `POST /api/analytics/audit-report/export` (one-click compliance audit report CSV)
  - `GET /api/analytics/features`
  - `POST /api/analytics/features`
  - `PUT /api/analytics/features/{featureId}`
  - enforced analytics/compliance behaviors:
    - date-range + org-unit + feature + dataset query filtering
    - reusable feature/tag definitions that analysts can create and update
    - live snapshot + sample dataset blending in the same query surface
    - dashboard calculations for trend, distribution, and correlation views
    - compliance dashboard exposes the approved 6-KPI prompt contract (prompt names are primary labels) and keeps explicit prompt-to-implementation traceability in API/export output (`promptAlias`, `promptLabel`, `implementationLabel`):
      - Rescue volume (implementation label: Regulatory Intervention Volume)
      - Recovery rate (implementation label: Remediation Closure Rate)
      - Adoption conversion (implementation label: Workflow Adoption Conversion)
      - Average shelter stay (implementation label: Average Case Resolution Duration)
      - Donation mix (implementation label: Revenue/Compliance Fee Mix)
      - Supply turnover (implementation label: Operational Capacity Turnover)
    - audit events for query runs, query exports, audit-report exports, and feature-definition changes
- Governance admin endpoints (system-admin controls):
  - `GET /api/admin/governance/audit-logs`
  - `GET /api/admin/governance/sensitive-access-logs`
  - `POST /api/admin/governance/sensitive/practitioner-profiles/{profileId}/license`
  - `GET /api/admin/governance/anomalies`
  - `POST /api/admin/governance/anomalies/refresh`
  - `POST /api/admin/governance/anomalies/{alertId}/acknowledge`
  - `GET /api/admin/governance/rollback/credential-submissions`
  - `GET /api/admin/governance/rollback/question-entries`
  - `POST /api/admin/governance/rollback/credentials`
  - `POST /api/admin/governance/rollback/questions`
  - `POST /api/admin/governance/users/password-reset`
  - enforced governance/admin behaviors:
    - immutable audit and sensitive-access ledger inspection
    - seven-year minimum retention policy metadata on audit/sensitive log endpoints
    - sensitive-field reads require reason and create audit + sensitive-access log records
    - local anomaly detection includes threshold rule `> 5` rejected credentials in `24h` for same firm
    - rollback creates a new active version from prior target (immutable history preserved)
    - rollback/password reset require step-up password confirmation and justification note
    - admin password reset is system-admin only and blocks self-reset through governance endpoint

### Governance log retention implementation

- Minimum retention floor is **7 years** for:
  - `audit_logs`
  - `sensitive_access_logs`
- Retention metadata is visible directly in governance read APIs:
  - `GET /api/admin/governance/audit-logs`
  - `GET /api/admin/governance/sensitive-access-logs`
- Operational enforcement command:

```bash
docker compose run --rm --no-deps api php bin/console app:governance:retention-enforce --dry-run
docker compose run --rm --no-deps api php bin/console app:governance:retention-enforce
```

- Enforcement behavior:
  - rows newer than retention cutoff are never purge-eligible
  - only rows older than the seven-year cutoff are deleted
  - purge execution is audit-logged (`admin.governance_retention_purge`)

### Frontend foundations

- Role-aware portal shell for baseline navigation/permission display
- Auth shell (register/sign-in/sign-out) with inline validation and status feedback
- Runtime health panel
- API client envelope handling
- Practitioner workflow surface (standard user):
  - profile management for lawyer identity, firm affiliation, jurisdiction, and masked license state
  - credential upload and resubmission with status/history feedback
- Reviewer workflow surface (credential reviewer + system admin oversight):
  - queue filtering and submission drill-down
  - approve/reject/request-resubmission decisions with required comments where applicable
- Scheduling workbench surface (standard user + system admin):
  - admin configures practitioner/location, weekly template, slot duration/capacity, and generates slots
  - calendar-style slot grid by day/time for hold/book actions
  - users place/release 10-minute holds and confirm bookings with immediate conflict/status feedback
  - users can reschedule up to limit and cancel bookings (subject to 24-hour policy)
- Question-bank workbench surface (content admin):
  - create/edit/view controlled intake/internal-assessment questions with status and version cues
  - rich text + formula + embedded image authoring flow
  - lifecycle actions for publish/offline with duplicate-review override handling
  - bulk CSV/Excel (`.xlsx`) import and CSV/Excel (`.xlsx`) export controls
- Analytics + compliance workbench surface (analyst + system admin):
  - filterable analytics query UI for date range, org units, feature definitions, and sample datasets
  - KPI dashboard with trend/distribution/correlation summaries and row previews
  - one-click CSV export for query rows and audit report export for compliance handoff
  - in-app feature-definition editor (name/description/tags/formula expression)
- Governance admin console surface (system admin):
  - immutable evidence panels for audit and sensitive-access ledgers
  - anomaly alert refresh/acknowledge controls in console
  - high-risk rollback and password-reset forms with explicit step-up + justification requirements
  - sensitive-field reveal action that records operator reason and access evidence
- Frontend production build output path is `frontend/build/` (Vite `outDir`)
- Vitest + Testing Library setup
- Playwright E2E baseline test

## How to verify it works

After `docker-compose up` (or `docker compose up --build`), the stack is ready once `db`, `api`, `worker`, and `web` are all up and the web service reports healthy on port 4280.

### Backend / API verification

```bash
# 1. API liveness (public route, no auth)
curl -sS http://127.0.0.1:4280/api/health/live
# → {"data":{"status":"live"},"meta":{...}}

# 2. API readiness (DB + keyring) (public route, no auth)
curl -sS http://127.0.0.1:4280/api/health/ready
# → {"data":{"status":"ready","database":"ok","keyring":{"activeKeyId":"..."}},"meta":{...}}

# 3. Authenticated round-trip using the demo credentials (see below)
DEV_PASSWORD=$(grep DEV_BOOTSTRAP_PASSWORD runtime/dev/runtime.env | cut -d= -f2)
curl -sS -c /tmp/regops.jar -H 'Content-Type: application/json' \
  -d "{\"username\":\"standard_user\",\"password\":\"${DEV_PASSWORD}\"}" \
  http://127.0.0.1:4280/api/auth/login
curl -sS -b /tmp/regops.jar http://127.0.0.1:4280/api/auth/me
# → {"data":{"username":"standard_user","roles":["ROLE_STANDARD_USER"]},"meta":{...}}
```

Higher-fidelity coverage is available via dedicated scripts:

```bash
./scripts/dev/docker_preflight.sh      # verifies docker/daemon/public images before tests
./scripts/dev/real_http_smoke.sh       # boots api, runs real-HTTP PHPUnit smoke suites, enforces coverage gate
./scripts/dev/http_coverage_check.sh   # standalone real-HTTP coverage gate (>= 90% required)
./run_tests.sh                         # full backend + frontend + smoke + coverage gate + Playwright
```

### True real-HTTP coverage gate

A hard gate in CI (and in `./run_tests.sh`) enforces that at least **90 %** of the 57-endpoint API surface is exercised by **real-HTTP** smoke tests — i.e. tests that speak HTTP to `http://api:8000` via `stream_context_create`, not Symfony `WebTestCase::createClient()`.

`scripts/dev/http_coverage_check.php` statically parses `backend/src/Controller/*.php` for the route inventory and `backend/tests/Smoke/*.php` for real-HTTP call sites, then reports:

```
[http-coverage] True real-HTTP API coverage gate
  Total endpoints:  57
  HTTP-covered:     57
  Coverage:         100.00%
  Threshold:        90.00%
```

Run it standalone:

```bash
./scripts/dev/http_coverage_check.sh                 # default threshold 90%
./scripts/dev/http_coverage_check.sh --threshold=95  # custom threshold
./scripts/dev/http_coverage_check.sh --json          # JSON for CI parsing
```

### Frontend verification (UI)

1. Open <http://127.0.0.1:4280/> — expect the portal shell with heading "Regulatory Operations & Analytics Portal".
2. In the **Session & Authentication** panel, sign in with one of the demo users below (e.g. `standard_user`) and the shared `DEV_BOOTSTRAP_PASSWORD`.
3. The header should report "Signed in as standard_user" and role-scoped navigation buttons (e.g. "Practitioner Workflow", "Scheduling Workbench") should become visible.
4. Reload the page — session should persist via real `/api/auth/me` cookie round-trip (no re-login required).

## Demo credentials

All seeded roles share one runtime-bootstrap password for local dev:

| Role                   | Username               | Password                         |
|------------------------|------------------------|----------------------------------|
| Standard user          | `standard_user`        | `${DEV_BOOTSTRAP_PASSWORD}`      |
| Content admin          | `content_admin`        | `${DEV_BOOTSTRAP_PASSWORD}`      |
| Credential reviewer    | `credential_reviewer`  | `${DEV_BOOTSTRAP_PASSWORD}`      |
| Analyst                | `analyst_user`         | `${DEV_BOOTSTRAP_PASSWORD}`      |
| System admin           | `system_admin`         | `${DEV_BOOTSTRAP_PASSWORD}`      |

`${DEV_BOOTSTRAP_PASSWORD}` is a 16-hex-character value generated the first time Docker boots the stack (see `scripts/dev/bootstrap_runtime.sh`).

### Where to read the demo password

After the stack is up, the password is persisted on disk at `runtime/dev/runtime.env`:

```bash
# explicit path (preferred)
grep DEV_BOOTSTRAP_PASSWORD runtime/dev/runtime.env

# running ./init_db.sh directly also prints it
./init_db.sh
# ...
# Seeded local users. Shared dev password: <your-value>
```

### Deterministic testing credential path

If you need a known, reproducible password for automated testing or documentation, pre-seed `runtime/dev/runtime.env` **before the first container boot**:

```bash
mkdir -p runtime/dev runtime/secrets/field-encryption
cat > runtime/dev/runtime.env <<'EOF'
DB_NAME=regops_local
DB_USER=regops_local_user
DB_PASSWORD=local-db-password
DB_ROOT_PASSWORD=local-db-root-password
APP_SECRET=local-app-secret-value-for-tests-only
DEV_BOOTSTRAP_PASSWORD=local-dev-password-123
FIELD_ENCRYPTION_KEYRING_PATH=/run/secrets/field-encryption/keyring.json
EOF
chmod 600 runtime/dev/runtime.env
```

With that file in place, `scripts/dev/bootstrap_runtime.sh` sees an existing `runtime.env` and skips regeneration, so `DEV_BOOTSTRAP_PASSWORD=local-dev-password-123` is used for every seeded demo user. Delete the file to fall back to auto-generated values.

**Security reminder:** these credentials exist only for local, offline development. They are never deployed, and runtime startup paths redact the password from container logs.

## Seeded development users

`./init_db.sh` seeds users for each role:

- `standard_user`
- `content_admin`
- `credential_reviewer`
- `analyst_user`
- `system_admin`

Shared bootstrap password is generated by runtime bootstrap and printed by explicit `./init_db.sh` runs.
Runtime service startup paths redact password output from seed logs.

## Repository layout

- `docker-compose.yml` — Docker-first runtime topology
- `init_db.sh` — only standard DB initialization path
- `run_tests.sh` — broad Dockerized test wrapper
- `scripts/dev/` — dev runtime bootstrap/start scripts
- `infra/mysql/` — MySQL wrapper image + entrypoint
- `backend/` — Symfony API + worker foundations
- `frontend/` — React UI + unit/e2e tests

Frontend workflow composition is split into:

- `frontend/src/workflows/Panels.tsx` (render surfaces)
- `frontend/src/workflows/hooks/useCredentialWorkflow.ts`
- `frontend/src/workflows/hooks/useSchedulingWorkflow.ts`
- `frontend/src/workflows/hooks/useQuestionBankWorkflow.ts`
- `frontend/src/workflows/hooks/useAnalyticsWorkflow.ts`
- `frontend/src/workflows/hooks/useGovernanceWorkflow.ts`
- `frontend/src/workflows/types.ts`

`frontend/src/App.tsx` now serves as a lean composition layer (session/permissions/view selection + panel wiring), with workflow-specific state and API behavior isolated in dedicated hooks for static review.

## Scope notes

Implemented end-to-end slices in this repo are:

- practitioner profile + credential submission/review workflow
- scheduling configuration + hold/booking/reschedule/cancel workflow
- controlled content question-bank workflow (authoring/lifecycle/versioning/duplicate-screening/import-export)
- analytics/compliance workbench workflow (query/filter/dashboard/export/feature-management)

Governance admin console (audit/sensitive evidence, anomaly handling, rollback, and admin password reset) is implemented end-to-end.

Question-bank bulk import accepts `.csv` and `.xlsx`; export supports `csv` and `excel` (`.xlsx`) formats.
