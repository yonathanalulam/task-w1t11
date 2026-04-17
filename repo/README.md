# Regulatory Operations & Analytics Portal

A Docker-first, offline-capable full-stack portal for regulatory operations. It covers practitioner credentialing, scheduling, controlled question-bank workflows, analytics/compliance reporting, and governance/audit operations.

## Architecture & Tech Stack

- Frontend: React + Vite + TypeScript
- Backend: PHP 8 + Symfony 7 (REST-style JSON APIs)
- Database: MySQL 8.4
- Containerization: Docker & Docker Compose (Required)

## Project Structure

Below is the project structure used in this repository.

```text
.
├── backend/                # Symfony API source code and Dockerfile
├── frontend/               # React frontend source code and Dockerfile
├── infra/                  # Infrastructure assets (including MySQL image files)
├── e2e/                    # End-to-end Playwright test container setup
├── scripts/                # Dev bootstrap and helper scripts
├── runtime/                # Generated local runtime state/secrets (dev only)
├── init_db.sh              # Database initialization and seeding script
├── docker-compose.yml      # Multi-container orchestration - MANDATORY
├── run_tests.sh            # Standardized test execution script - MANDATORY
└── README.md               # Project documentation - MANDATORY
```

Note: This project does not use a committed `.env.example`. Runtime values are generated into `runtime/dev/runtime.env` by `scripts/dev/bootstrap_runtime.sh`.

## Prerequisites

To ensure a consistent environment, this project is designed to run entirely within containers. You must have the following installed:

- Docker
- Docker Compose

## Running the Application

Build and start containers:

```bash
docker-compose up --build -d
```

Equivalent modern CLI command:

```bash
docker compose up --build -d
```

Runtime bootstrap values are auto-generated on first run (including DB credentials, app secret, and seeded-user password) in `runtime/dev/runtime.env`.

Access the app:

- Frontend: http://localhost:4280
- Backend API: http://localhost:4280/api
- API Documentation: Not configured in this repository

Stop the application:

```bash
docker-compose down -v
```

## Testing

All unit, integration, smoke, and E2E tests run through one standardized script:

```bash
chmod +x run_tests.sh
./run_tests.sh
```

`run_tests.sh` exits with standard codes (`0` success, non-zero failure), which makes it CI/CD friendly.

## Seeded Credentials

The database is seeded by `backend/src/Command/SeedDevUsersCommand.php` with role users below. All seeded users share the same password value from `DEV_BOOTSTRAP_PASSWORD`.

| Role | Username | Password | Notes |
|---|---|---|---|
| Standard User | `standard_user` | `local-dev-password-123` | Base practitioner workflow access. |
| Content Admin | `content_admin` | `local-dev-password-123` | Question-bank management permissions. |
| Credential Reviewer | `credential_reviewer` | `local-dev-password-123` | Credential review queue and decision access. |
| Analyst | `analyst_user` | `local-dev-password-123` | Analytics/compliance workbench access. |
| System Admin | `system_admin` | `local-dev-password-123` | Full governance/admin access. |

How to get the current seeded password:

```bash
grep DEV_BOOTSTRAP_PASSWORD runtime/dev/runtime.env
```

Important: `DEV_BOOTSTRAP_PASSWORD` is now pinned by bootstrap logic to `local-dev-password-123` for local development.
