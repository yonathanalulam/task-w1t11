# Regulatory Operations & Analytics Portal — API Specification Plan (v1)

## 1) API conventions

- Base path: `/api/v1`
- Format: JSON (`application/json`) unless file upload/download.
- Auth model: session cookie + CSRF token for mutating calls.
- No external-network dependencies; all services local.

### 1.1 Standard response envelope

Success:

```json
{
  "data": {},
  "meta": {
    "requestId": "uuid"
  }
}
```

Error:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Difficulty must be between 1 and 5",
    "details": [
      {"field": "difficulty", "issue": "out_of_range"}
    ]
  },
  "meta": {
    "requestId": "uuid"
  }
}
```

### 1.2 Common status codes

- `200` OK
- `201` Created
- `204` No Content
- `400` Malformed request / CAPTCHA failure
- `401` Unauthenticated / step-up invalid
- `403` Forbidden
- `404` Not found
- `409` Business conflict (overlap, policy conflict, duplicate-blocked publish)
- `422` Validation failure
- `423` Locked (account/resource lockout)
- `429` Too many requests

### 1.3 Role codes

- `ROLE_STANDARD_USER`
- `ROLE_CONTENT_ADMIN`
- `ROLE_CREDENTIAL_REVIEWER`
- `ROLE_ANALYST`
- `ROLE_SYSTEM_ADMIN`

Authorization policy must match frontend navigation policy.

---

## 2) Authentication and identity APIs

## 2.1 Register

- `POST /auth/register`
- Roles: public
- Body:
  - `username` (required, unique)
  - `password` (required, policy-compliant)
- Responses:
  - `201` created user
  - `422` validation/duplicate username

## 2.2 Login

- `POST /auth/login`
- Roles: public
- Body:
  - `username`
  - `password`
  - `captcha` (required when challenged)
- Behavior:
  - lockout after 5 failures for 15 minutes
  - CAPTCHA required on lockout challenge path (local generator)
- Responses:
  - `200` authenticated + session cookie
  - `401` invalid credentials
  - `423` account locked with `lockedUntil`
  - `400` invalid captcha when required

## 2.3 Logout

- `POST /auth/logout`
- Roles: authenticated
- Responses: `204`

## 2.4 Current session/permissions

- `GET /auth/me`
- `GET /permissions/me`
- Roles: authenticated
- Returns identity, effective roles, permissions for navigation/API actions.

## 2.5 CSRF token

- `GET /auth/csrf-token`
- Roles: public and authenticated (token issued against current local session)
- Returns CSRF token for mutating requests.

## 2.6 Admin password reset (admin-initiated only)

- `POST /admin/users/{userId}/password-reset`
- Roles: `ROLE_SYSTEM_ADMIN`
- Body:
  - `justification` (required)
- Responses:
  - `201` reset issued
  - `403` non-admin

## 2.7 Step-up verification (for rollback/high-risk actions)

- `POST /auth/step-up/verify`
- Roles: authenticated
- Body:
  - `password` (required)
  - `actionScope` (required, e.g., `ROLLBACK_QUESTION`)
- Responses:
  - `200` challenge token + expiry
  - `401` invalid password

Audit events: login success/failure/lockout/logout/password reset/step-up verify.

---

## 3) Practitioner and credential APIs

## 3.1 Practitioner profile

- `GET /practitioners/me`
- `PUT /practitioners/me`
- Roles: `ROLE_STANDARD_USER` (self), `ROLE_SYSTEM_ADMIN` (override endpoints)

Validation:
- required identity and firm fields
- cross-field checks (license jurisdiction + expiry consistency)

Sensitive fields:
- `licenseNumber` is encrypted at rest
- API default returns masked representation

## 3.2 Credential upload/version history

- `POST /practitioners/{id}/credentials`
- Roles: owner user, system admin
- Content-Type: `multipart/form-data`
- File constraints:
  - max size 10 MB
  - allowlist MIME: PDF/JPG/PNG

Responses:
- `201` new credential version submitted
- `422` invalid file size/type

## 3.3 Credential history and status

- `GET /practitioners/{id}/credentials`
- `GET /credentials/{credentialId}/versions`

## 3.4 Credential review queue

- `GET /credential-review-queue`
- Roles: `ROLE_CREDENTIAL_REVIEWER`, `ROLE_SYSTEM_ADMIN`

- `POST /credentials/{credentialId}/review`
  - Body:
    - `decision`: `APPROVE | REJECT | RESUBMIT_REQUIRED`
    - `comment`: required for `REJECT` and `RESUBMIT_REQUIRED`
  - Responses:
    - `200` workflow transitioned
    - `422` comment missing for required decisions
    - `409` invalid transition

Audit events: upload, status transition, review decision.

---

## 4) Scheduling APIs

## 4.1 Availability templates

- `GET /availability/templates`
- `POST /availability/templates`
- `PUT /availability/templates/{templateId}`
- Roles: `ROLE_SYSTEM_ADMIN` only

Body fields:
- `scopeType` (`PRACTITIONER` / `LOCATION`)
- `scopeId`
- `weekday`
- `startTime`, `endTime`
- `slotDurationMinutes` (default 30)

## 4.2 Slot listing

- `GET /slots?from=...&to=...&practitionerId=...&locationId=...`
- Roles: authenticated

## 4.3 Hold a slot

- `POST /slots/{slotId}/hold`
- Roles: authenticated booking actors
- Behavior:
  - starts DB transaction
  - acquires row lock (pessimistic write)
  - validates slot open + no overlap constraints
  - sets `HELD` with `holdExpiresAt = now + 10 minutes`

Responses:
- `200` hold created with expiry timestamp
- `409` conflict/overlap/already held/booked
- `423` locked during concurrent attempt

## 4.4 Confirm booking from hold

- `POST /slots/{slotId}/book`
- Roles: authenticated booking actors
- Validation:
  - hold belongs to caller or `ROLE_SYSTEM_ADMIN`
  - hold not expired
  - booking start <= now + 90 days

Responses:
- `201` appointment booked
- `409` hold expired/conflict
- `422` booking horizon violation

## 4.5 Reschedule appointment

- `POST /appointments/{appointmentId}/reschedule`
- Body: `newSlotId`, `reason`
- Rules:
  - max 2 reschedules per appointment
  - same conflict/locking checks as booking

Responses:
- `200` rescheduled
- `409` reschedule limit reached or slot conflict

## 4.6 Cancel appointment

- `POST /appointments/{appointmentId}/cancel`
- Body: `reason`
- Rules:
  - cancellation inside 24h blocked for non-system-admin

Responses:
- `200` cancelled
- `403` within 24h without override role

Audit events: holds/bookings/reschedules/cancellations/overrides.

---

## 5) Question bank and controlled content APIs

## 5.1 Question CRUD and lifecycle

- `POST /questions`
- `GET /questions/{id}`
- `PUT /questions/{id}`
- `GET /questions?status=&tag=&difficulty=&q=`
- Roles:
  - write: `ROLE_CONTENT_ADMIN` (and `ROLE_SYSTEM_ADMIN`)
  - read: based on policy/lifecycle status

Validation:
- required rich text payload
- difficulty range 1..5
- tags format and allowable values

## 5.2 Publish/offline transitions

- `POST /questions/{id}/publish`
- `POST /questions/{id}/offline`
- Publish guard:
  - duplicate similarity flags checked against threshold policy

Responses:
- `200` lifecycle transitioned
- `409` duplicate flag unresolved / invalid state transition

## 5.3 Version history and rollback

- `GET /questions/{id}/versions`
- `POST /questions/{id}/rollback`
  - Roles: `ROLE_SYSTEM_ADMIN`
  - Body:
    - `targetVersionId`
    - `stepUpToken`
    - `justification`

Responses:
- `200` rollback applied as new version
- `403` role denial
- `401` invalid/expired step-up
- `422` missing justification

## 5.4 Bulk import/export

- `POST /questions/imports` (CSV/XLSX upload)
- `GET /questions/imports/{jobId}`
- `GET /questions/imports/{jobId}/rows`
- `POST /questions/exports`
- `GET /questions/exports/{jobId}`
- `GET /questions/exports/{jobId}/download`

Behavior:
- import validates each row and cross-field constraints
- import/export actions auditable

## 5.5 Duplicate review queue

- `GET /questions/duplicates?status=OPEN`
- `POST /questions/duplicates/{flagId}/resolve`
  - Body: `resolution` (`ALLOW`/`MERGE_RECOMMENDED`/`BLOCK_PUBLISH`), `note`

Audit events: create/edit/publish/offline/import/export/duplicate resolution/rollback.

---

## 6) Analytics and compliance dashboard APIs

## 6.1 Unified analytics query execution

- `POST /analytics/queries/run`
- Roles: `ROLE_ANALYST`, `ROLE_SYSTEM_ADMIN`
- Body:
  - `baseDataset`
  - `filters` (date range, org unit)
  - `dimensions`, `measures`
  - `sample` options

Responses:
- `200` result preview + run metadata
- `422` invalid filter/query definition

## 6.2 Reusable features/tags

- `POST /analytics/features`
- `GET /analytics/features`
- `PUT /analytics/features/{id}`
- `POST /analytics/features/{id}/disable`

## 6.3 Dashboard views

- `GET /dashboard/kpis?from=&to=&orgUnitId=`
- `GET /dashboard/trends?...`
- `GET /dashboard/distributions?...`
- `GET /dashboard/correlations?...`

Legal/regulatory KPI canonical keys:
- `regulatoryInterventionVolume`
- `remediationClosureRate`
- `workflowAdoptionConversion`
- `avgCaseResolutionDuration`
- `revenueComplianceFeeMix`
- `operationalCapacityTurnover`

KPI alias contract:
- API responses expose **only** the canonical keys above.
- No legacy alias keys (including mismatched prompt terms) are emitted.

## 6.4 Export for audits

- `POST /dashboard/exports`
- `GET /dashboard/exports/{id}`
- `GET /dashboard/exports/{id}/download`

All exports produce auditable records including actor, filters, file hash, timestamp.

---

## 7) Governance, audit, anomaly, and admin APIs

## 7.1 Audit log access

- `GET /audit/events?from=&to=&actor=&action=&entity=`
- Roles: `ROLE_SYSTEM_ADMIN`
- Behavior: query spans hot MySQL tier and archived batches through a unified read API.

## 7.2 Sensitive field access logs

- `GET /audit/sensitive-access?from=&to=&field=&actor=`
- Roles: `ROLE_SYSTEM_ADMIN`
- Behavior: query spans hot + archive tiers with same pagination contract.

## 7.3 Anomaly console

- `GET /anomalies?status=&ruleCode=&orgUnitId=`
- `POST /anomalies/{id}/acknowledge`
- `POST /anomalies/{id}/resolve`

## 7.4 Rollback action ledger

- `GET /rollbacks?targetType=&targetId=`
- Roles: `ROLE_SYSTEM_ADMIN`

## 7.5 Human-verification integration point (disabled by default)

- `GET /admin/integrations/human-verification`
- `PUT /admin/integrations/human-verification`
- Roles: `ROLE_SYSTEM_ADMIN`

Constraints:
- default status `DISABLED`
- enabling must point only to approved local/on-prem endpoint
- no required internet dependency in default runtime

## 7.6 Retention and archival behavior (API-facing contract)

- Retention floor: audit and sensitive-access events are retained for at least 7 years.
- Hot tier (<=18 months) and archive tier (>18 months) are both queryable through `/audit/*` endpoints.
- Archive rows remain immutable and are returned as read-only records.
- Any archive maintenance operation must emit audit events (not exposed as a regular non-admin API flow).

---

## 8) Failure-path matrix (prompt-critical)

| Flow | Failure condition | API behavior | User-facing message intent |
|---|---|---|---|
| Login | 5+ failures in window | `423` with `lockedUntil` | Account temporarily locked |
| Login | CAPTCHA required but wrong | `400` | Human verification failed |
| Credential review | reject/resubmit missing comment | `422` | Reviewer comment required |
| Booking | slot overlap for practitioner+location | `409` | Selected time conflicts |
| Booking | hold expired | `409` | Hold expired; choose slot again |
| Booking | >90 days ahead | `422` | Booking window exceeded |
| Reschedule | attempt >2 | `409` | Reschedule limit reached |
| Cancel | <24h and non-admin | `403` | Cancellation window closed |
| Question publish | duplicate unresolved | `409` | Duplicate review needed |
| Rollback | no step-up or no justification | `401/422` | Reconfirm password and provide reason |

---

## 9) Transaction and locking requirements by endpoint

- Must be transactional:
  - `/slots/{id}/hold`
  - `/slots/{id}/book`
  - `/appointments/{id}/reschedule`
  - `/appointments/{id}/cancel`
  - `/credentials/{id}/review`
  - `/questions/{id}/rollback`

- Locking strategy for scheduling endpoints:
  - `PESSIMISTIC_WRITE` lock on slot row(s)
  - conflict query for overlapping booked/held states scoped by practitioner+location
  - deterministic lock ordering to reduce deadlocks
  - retry policy for transient deadlocks with safe idempotency key where needed

---

## 10) Security requirements mapped to API contracts

- Server-side input validation on all endpoints.
- Prepared statements / parameterized ORM queries only.
- CSRF token required on mutating endpoints.
- Upload guards: allowlist MIME + extension + max 10MB.
- Password hashing: Symfony hasher is configured to **bcrypt explicitly** (`algorithm: bcrypt`, not `auto`).
- Field encryption: AES-256-GCM for license numbers with `keyId` metadata.
- Masking default in output; explicit reveal operations logged.
- No self-service password recovery endpoint.
- Key provider is local-only (`/run/secrets/field-encryption/keyring.json`); no external KMS/network dependency.
- Keyring bootstrap is Docker-startup driven via local runtime bootstrap script; no `.env*` key material is used or committed.
- Missing key material at startup in non-dev mode is a hard failure (`503` for dependent endpoints until resolved).
- Rotation expectation: APIs accept/decrypt prior `keyId` versions while encrypting new writes with active key; rotation/re-encryption operations are auditable.

---

## 11) API-layer verification contract (Docker-first planning)

Planned API verification commands during implementation:
- `docker compose run --rm api php bin/phpunit tests/Api`
- `docker compose run --rm api php bin/phpunit tests/Integration`
- `docker compose run --rm e2e npm run test:e2e -- e2e/appointment-policy.spec.ts`

Project-level runtime/test/init contracts this API plan assumes:
- Runtime: `docker compose up --build`
- Broad test gate: `./run_tests.sh`
- DB init/migration path: `./init_db.sh`

## 12) Out-of-scope for v1 API spec
