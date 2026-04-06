# Regulatory Operations & Analytics Portal — System Design Plan

## 1) Scope and settled requirements

This plan is the implementation baseline for an **on-premise, no-external-network** Regulatory Operations & Analytics Portal for a professional services organization managing credentialed legal practitioners.

Settled requirements carried forward:

- Domain remains legal/regulatory for credentialed practitioners.
- Prompt KPI label mismatch is resolved via legal/regulatory KPI equivalents while preserving dashboard/reporting intent.
- Appointment hold expiration default is **10 minutes**.
- Role-based authorization must be aligned across **navigation and API enforcement**.
- Scheduling administration authority is frozen to **`ROLE_SYSTEM_ADMIN`**.
- Compliance KPI APIs expose **canonical legal/regulatory keys only** (no legacy alias keys).
- Auditability, transactional integrity, version history, rollback restrictions, encryption, masking, and operator/admin workflows are required behavior.

---

## 2) System overview and architecture reasoning

## 2.1 Deployment model (on-prem only)

- **Frontend**: React SPA served by local web tier.
- **Backend**: Symfony REST-style API (session cookie auth + CSRF).
- **Database**: MySQL 8 (InnoDB) for transactional data, row-level locking, audit retention.
- **File store**: local filesystem or on-prem mounted storage for credential/question assets.
- **Async workers**: Symfony Messenger workers (local transport only) for heavy processing.
- **External connectivity**: disabled by design; no outbound internet calls.

Reasoning:
- On-prem and offline requirements favor a tightly controlled local stack.
- Symfony + MySQL gives strong transaction semantics and mature security primitives.
- Async workers keep UI responsive for import/dedup/export/anomaly jobs without cloud dependencies.

## 2.2 Architectural style

- **Modular monolith** backend organized by bounded contexts:
  - Identity & Access
  - Practitioner & Credentialing
  - Scheduling
  - Question Bank
  - Analytics & Reporting
  - Governance (Audit/Anomaly/Retention)
- React client organized by role-specific workbenches sharing common UI infrastructure.

Reasoning:
- Single deployable unit simplifies on-prem operations and audit review.
- Strong module boundaries preserve maintainability and future extraction options.

## 2.3 Cross-cutting technical contracts

- All mutating endpoints enforce:
  1. authentication,
  2. authorization,
  3. input validation,
  4. transaction boundary,
  5. audit emission,
  6. deterministic error shape.
- Sensitive fields (e.g., license numbers) are encrypted at rest and masked by default in UI/API responses.
- Password hashing contract is explicit **bcrypt** (Symfony hasher algorithm set to `bcrypt`, not `auto`).
- Rollback operations require step-up confirmation (password re-entry) + justification + System Admin role.

---

## 3) Major modules and responsibilities

| Module | Core responsibilities | Key invariants |
|---|---|---|
| Identity & Access | Register/login, lockout, CAPTCHA, role resolution, admin-initiated password reset | 5 failed attempts => 15-min lockout; no self-service recovery |
| Practitioner Registry | Practitioner profile, legal identity, firm affiliation, license lifecycle | Required fields validated on save/import; license encrypted |
| Credentialing Workflow | Upload credential docs, version history, review queue decisions | Reject/resubmission require comments; full immutable decision history |
| Scheduling | Availability setup, slot generation, hold/book/reschedule/cancel | Hold expiry 10 min; max 2 reschedules; no >90-day booking; 24h cancel block unless System Admin |
| Question Bank | Authoring, versioning, publish workflow, import/export, dedup checks | Difficulty 1–5; duplicate flags reviewed before publish |
| Analytics Workbench | Unified query execution, reusable features/tags, dashboard filters, CSV export | Export actions auditable with filter footprints |
| Governance & Audit | Immutable audit trails, sensitive access logs, anomaly alerts, retention | 7-year retention, append-only audit behavior |
| Security Services | CSRF, input validation, upload controls, encryption/masking, prepared statements | No external dependency; strong server-side validation |

---

## 4) Domain/data model (implementation blueprint)

## 4.1 Identity and access

- `users(id, username unique, password_hash, status, failed_attempt_count, locked_until, created_at, updated_at)`
- `roles(id, code unique)`
- `user_roles(user_id, role_id)`
- `login_attempts(id, username, success, attempted_at, requires_captcha, source_ip_local)`
- `admin_password_resets(id, target_user_id, initiated_by, temp_credential_hash, expires_at, used_at)`
- `step_up_challenges(id, user_id, action_scope, verified_at, expires_at)`

## 4.2 Organization and practitioner

- `org_units(id, name, parent_id nullable)`
- `firms(id, org_unit_id, name, status)`
- `practitioners(id, owner_user_id nullable, firm_id, legal_name, email, phone, license_number_ciphertext, license_number_last4, license_jurisdiction, license_expiry_date, status)`
- `practitioner_versions(id, practitioner_id, version_no, snapshot_json, changed_by, change_reason, created_at)`

## 4.3 Credentialing

- `credential_records(id, practitioner_id, current_version_id, workflow_status)`
- `credential_versions(id, credential_record_id, version_no, file_path, file_sha256, mime_type, size_bytes, uploaded_by, created_at)`
- `credential_reviews(id, credential_record_id, credential_version_id, reviewer_id, decision, comment, created_at)`
- `credential_review_queue(id, credential_record_id, queue_status, last_transition_at)`

## 4.4 Scheduling

- `locations(id, name, org_unit_id, status)`
- `availability_templates(id, scope_type, scope_id, weekday, start_time, end_time, slot_duration_minutes default 30, active)`
- `appointment_slots(id, practitioner_id, location_id, start_at, end_at, status, hold_owner_user_id nullable, hold_expires_at nullable, version)`
- `appointments(id, slot_id, practitioner_id, location_id, booked_by_user_id, status, booked_at, cancelled_at, cancellation_reason)`
- `appointment_reschedules(id, appointment_id, from_slot_id, to_slot_id, actor_id, reason, created_at)`

## 4.5 Question bank / controlled content

- `questions(id, current_version_id, lifecycle_status, difficulty, created_by, created_at)`
- `question_versions(id, question_id, version_no, rich_text_payload, formula_payload, embedded_asset_refs_json, change_note, authored_by, created_at)`
- `question_tags(id, name unique)`
- `question_tag_map(question_id, tag_id)`
- `question_similarity_flags(id, question_id, matched_question_id, score, status, reviewed_by, reviewed_at)`
- `content_import_jobs(id, started_by, source_type, status, summary_json, created_at)`
- `content_import_rows(id, import_job_id, row_number, payload_json, validation_errors_json, accepted)`
- `content_export_jobs(id, requested_by, filter_json, file_path, status, created_at)`

## 4.6 Analytics/governance/audit

- `analytics_saved_queries(id, owner_id, name, query_definition_json, shared_scope)`
- `analytics_feature_defs(id, created_by, name, expression_json, status)`
- `analytics_query_runs(id, run_by, query_ref_id nullable, filters_json, sampled, row_count, output_file_path nullable, started_at, completed_at)`
- `dashboard_exports(id, exported_by, dashboard_type, filters_json, file_path, file_sha256, created_at)`
- `audit_logs(id, actor_id, action_type, entity_type, entity_id, request_id, before_hash, after_hash, payload_json, created_at)`
- `sensitive_access_logs(id, actor_id, entity_type, entity_id, field_name, reason, created_at)`
- `rollback_actions(id, actor_id, target_type, target_id, from_version_id, to_version_id, justification, step_up_challenge_id, created_at)`
- `anomaly_alerts(id, rule_code, org_scope, payload_json, severity, status, created_at, acknowledged_by, acknowledged_at)`

## 4.7 Data constraints and indexing

- Unique index on usernames, role codes, tags.
- Difficulty constrained to 1–5.
- File upload constrained by type and size (<=10MB).
- Booking horizon validated server-side against now + 90 days.
- Overlap prevention query uses transactional lock and indexed fields `(practitioner_id, location_id, start_at, end_at, status)`.
- Audit/sensitive logs are append-only from application role permissions.

---

## 5) Frontend/backend crosswalk

| Frontend surface | Primary backend modules | Critical API groups |
|---|---|---|
| Auth screens | Identity & Access | `/auth/*` |
| Role navigation shell | Identity & Access | `/auth/me`, `/permissions/me` |
| Practitioner profile page | Practitioner Registry | `/practitioners/*` |
| Credential upload/history | Credentialing | `/credentials/*`, `/credential-reviews/*` |
| Reviewer work queue | Credentialing | `/credential-review-queue/*` |
| Scheduling workbench | Scheduling | `/availability/*`, `/slots/*`, `/appointments/*` |
| Question management | Question Bank | `/questions/*`, `/imports/*`, `/exports/*`, `/duplicates/*` |
| Analytics workbench | Analytics | `/analytics/*`, `/dashboard/*` |
| Admin governance console | Governance | `/audit/*`, `/anomalies/*`, `/rollbacks/*`, `/admin/*` |

UX contract:
- Every action must provide immediate inline validation and explicit success/error status.
- UI menu visibility is derived from the same permission map enforced by APIs.

---

## 6) Permission model and security boundary inventory

## 6.1 Role model

- **Standard User**: own profile + credentials + own appointment lifecycle within policy.
- **Content Admin**: question authoring/import/export/publish lifecycle.
- **Credential Reviewer**: review queue decisions.
- **Analyst**: analytics and dashboard exports.
- **System Admin**: full administrative controls, rollback authority, password reset, policy overrides.

Scheduling authority freeze:
- Availability template management, slot policy configuration, and policy override actions are restricted to `ROLE_SYSTEM_ADMIN`.

## 6.2 Security boundaries

1. **Authentication boundary**: session established only after credential + lockout/CAPTCHA checks.
2. **Authorization boundary**: policy checks at router/controller/service layers for every endpoint.
3. **Data sensitivity boundary**: encrypted fields decrypted only by authorized services; masked by default in responses.
4. **Step-up boundary**: rollback and high-risk actions require fresh password verification token.
5. **Upload boundary**: MIME/type/size validation before persistence.
6. **Audit boundary**: immutable write path for security-sensitive events.

## 6.3 Authorization consistency contract

- A single permission registry defines:
  - navigation entries,
  - endpoint guards,
  - action-level checks.
- CI tests must assert navigation permission map does not drift from backend endpoint policy map.

---

## 7) Contracts: audit/logging, validation, versioning, rollback, anomaly, encryption

## 7.1 Audit and sensitive access logging

Mandatory audit events:
- login success/failure/lockout,
- credential submit/review decision,
- question publish/offline/rollback,
- appointment hold/book/reschedule/cancel,
- import/export lifecycle,
- admin overrides and password resets,
- configuration changes.

Sensitive access logs required for:
- license number reveal/decrypt,
- raw export of sensitive datasets,
- rollback artifact access.

Retention: 7 years minimum.

## 7.2 Validation contract

- Validate on individual save and bulk import.
- Enforce required fields, ranges (difficulty 1–5), cross-field consistency.
- Bulk import rows carry explicit per-row error reports.

## 7.3 Versioning contract

- Practitioner profile, credential documents, and question content are versioned.
- New version created on each meaningful edit/upload.
- History cannot be deleted via regular user flows.

## 7.4 Rollback contract

- Only System Admin may rollback question/credential versioned entities.
- Requires step-up password confirmation and justification note.
- Rollback creates a new version referencing prior snapshot (no destructive rewind).

## 7.5 Anomaly contract

Initial rule examples:
- `CREDENTIAL_REJECT_SPIKE`: >5 rejected credentials in 24h for same firm.
- `IMPORT_ERROR_SPIKE`: import row error rate exceeds configured threshold.
- `FAILED_LOGIN_SPIKE`: abnormal lockout concentration per org unit.

Alerts are local-only and visible in admin console.

## 7.6 Encryption and masking contract

- License numbers encrypted at rest with AES-256-GCM via local key provider.
- API defaults return masked values (e.g., `****1234`).
- Unmasked access requires explicit authorized operation and sensitive-access log entry.

## 7.7 On-prem key management contract (implementation-frozen)

- **Key source**: local filesystem secret mount only (no external KMS, no internet dependency).
- **Runtime key path**: `/run/secrets/field-encryption/keyring.json` mounted into `api` and `worker` containers.
- **Keyring format**: JSON containing `activeKeyId` and one or more AES-256 keys by id (base64-encoded 32-byte values).
- **Bootstrap path**:
  - Docker startup invokes `./scripts/dev/bootstrap_runtime.sh` automatically before API/worker boot.
  - In local dev mode, script creates keyring on first run if missing.
  - In non-dev mode, missing keyring is a hard startup failure.
- **No checked-in env files**: key material is never sourced from `.env*` and never committed.
- **Encryption metadata**: encrypted fields store `key_id`, `nonce`, `ciphertext`, `auth_tag`.
- **Rotation minimum**:
  - Support adding a new active key id without downtime.
  - New writes use active key immediately.
  - Background re-encryption job migrates old rows.
  - Old keys remain read-only until migration completion is verified, then can be retired.
- **Operational controls**: keyring file permissions `0600`, owned by runtime user; all key rotation/re-encryption events are audited.

## 7.8 7-year retention and archival contract (implementation-frozen)

- Applies to `audit_logs` and `sensitive_access_logs`.
- **Hot tier** (MySQL): rolling 18 months, append-only.
- **Archive tier** (local on-prem storage mount): immutable compressed monthly batches for data older than 18 months.
- **Archival job** (monthly):
  1. selects closed monthly partitions older than 18 months,
  2. writes archive batch file + manifest (`row_count`, `sha256`),
  3. verifies manifest against DB counts/checksum,
  4. records immutable archive index row,
  5. drops hot partition only after successful verification.
- **Retention floor**: archived data is retained for at least 7 years from event time.
- **Purge behavior**: automatic purge is disabled by default; a manual purge command is available, enforces `event_age > 7 years`, requires System Admin execution context, and emits an audit event.
- **Immutability expectations**:
  - application DB role has no `UPDATE/DELETE` on audit/sensitive log tables,
  - archive files are written once then set read-only,
  - archive index is append-only.

---

## 8) Prompt-critical state models and explicit failure paths

## 8.1 Authentication state model

States: `ANONYMOUS -> AUTHENTICATED` or `LOCKED_OUT`.

Failures:
- Invalid credentials => 401 + remaining attempts message.
- Lockout reached => 423 + lockout expiration timestamp.
- CAPTCHA failed when required => 400 validation error.

## 8.2 Credential review state model

States: `DRAFT -> SUBMITTED -> IN_REVIEW -> APPROVED | REJECTED | RESUBMIT_REQUIRED`.

Failures:
- Upload invalid MIME/size => 422.
- Reviewer reject/resubmit without comment => 422.
- Unauthorized review action => 403.

## 8.3 Appointment state model

Slot states: `OPEN -> HELD -> BOOKED` and `OPEN/HELD/BOOKED -> CANCELLED` (policy constrained).

Hold policy:
- Default hold TTL: **10 minutes**.
- Expired holds auto-release to OPEN.

Failures:
- Conflict overlap same practitioner+location => 409.
- Attempt booking >90 days => 422.
- Reschedule count >2 => 409.
- Cancellation within 24h by non-System Admin => 403.
- Concurrent hold/booking race => one succeeds, others return 409/423.

## 8.4 Question lifecycle model

States: `DRAFT -> PUBLISHED -> OFFLINE` (with version updates in each state).

Failures:
- Difficulty out of range => 422.
- Duplicate flag unresolved where policy blocks publish => 409.
- Non-admin rollback attempt => 403.

## 8.5 Rollback state model

Prerequisite: `STEP_UP_VERIFIED` token with unexpired TTL.

Failures:
- Missing/invalid step-up => 401/403.
- Missing justification => 422.
- Target version not found => 404.

---

## 9) Compliance dashboard KPI equivalence mapping

To preserve reporting intent while keeping legal/regulatory domain consistency:

| Prompt label (mismatched) | Legal/regulatory KPI equivalent |
|---|---|
| Rescue volume | **Regulatory intervention volume** (count of escalated credential/compliance cases) |
| Recovery rate | **Remediation closure rate** (resolved compliance findings / total findings) |
| Adoption conversion | **Workflow adoption conversion** (eligible practitioners using standardized intake/workflows) |
| Average shelter stay | **Average case resolution duration** (open-to-close compliance case time) |
| Donation mix | **Revenue/compliance fee mix** (service line or billing/compliance contribution composition) |
| Supply turnover | **Operational capacity turnover** (appointment slot utilization and turnaround rate) |

The dashboard UI should present canonical legal/regulatory terms while preserving trend/distribution/correlation and export functionality.
No legacy/mismatched KPI aliases are exposed in API payloads.

---

## 10) Runtime/test contract planning

Runtime and verification contracts to carry into implementation and README:

- Primary runtime command: `docker compose up --build` (docker-first).
- Broad test command: `./run_tests.sh`.
- DB initialization path: `./init_db.sh`.

Planned runtime composition:
- web (React static serving layer)
- api (Symfony)
- db (MySQL)
- worker (Symfony Messenger)
- e2e (Playwright runner container)

Planned concrete test execution path (Docker-first):
- Backend targeted tests:
  - `docker compose run --rm api php bin/phpunit tests/Unit`
  - `docker compose run --rm api php bin/phpunit tests/Integration`
  - `docker compose run --rm api php bin/phpunit tests/Api`
- Frontend targeted tests:
  - `docker compose run --rm web npm run test -- --run src/features/scheduling`
  - `docker compose run --rm web npm run test -- --run src/features/dashboard`
- E2E targeted tests:
  - `docker compose run --rm e2e npm run test:e2e -- e2e/*.spec.ts`

`./run_tests.sh` will orchestrate the broad full-suite path using the same containerized runners.
`./init_db.sh` will initialize both dev and test schemas/seeds in MySQL for local and CI usage.

No runtime path may require internet connectivity.

README implications (to implement later in repo README):
- Clearly document on-prem/offline constraints.
- Document role model and major workflows.
- Document any mock/debug/feature-flag defaults if present.
- Document runtime/test/init commands above as primary contracts.

---

## 11) Authoritative framework notes used in planning

Symfony docs references used for planning assumptions:
- Security password hashers support explicit bcrypt algorithm configuration.
- Login throttling configuration (`maxAttempts`, `interval`) supports lockout behavior.
- CSRF support for login/forms with session-based security.

Doctrine ORM references used for planning assumptions:
- Pessimistic locking requires explicit transaction and supports `PESSIMISTIC_WRITE` for concurrency-safe booking logic.
