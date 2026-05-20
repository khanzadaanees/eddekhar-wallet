# Eddekhar Employee Wallet Service

## Project overview

Backend service that **manages employee wallets** and reflects activity from a **simulated payroll provider** (inbound) and **bank / payments partner** (outbound). Built as the **Eddekhar Backend & Integrations Engineer** take-home.

**What it does (in one pass):** create employees and multiple wallet types (`salary`, `savings`), keep balances **non-negative and safe under concurrency**, process **signed payroll webhooks** (onboarding, status, salary runs), initiate **withdrawals** with **reserved funds** until the bank confirms or fails async, expose **paginated, filterable** reads for dashboards, and keep a **full transaction ledger** per wallet.

**Stack:** PHP 8.3+, Laravel 13, MySQL.

**Queue:** `database` (local, recommended) · `redis` (production) · `sync` (tests, via `phpunit.xml`).

**Tests:** 44 tests (478 assertions) — `php artisan test` (see [Tests](#tests)).

### Deliverables

| Deliverable | Location |
|-------------|----------|
| Source code | Repository root (`app/`, `routes/`, …) |
| Schema | `database/migrations/` (8 migration files; no separate SQL dump) |
| Tests (critical paths) | `tests/Feature/`, `tests/Unit/` — **44** tests, `php artisan test` |
| README (this file) | Overview, architecture, setup, design, partners, improvements |
| Architecture sketch | [Architecture](#architecture) |
| API collection | `postman/eddekhar-wallet.postman_collection.json` + `postman/eddekhar-wallet-local.postman_environment.json` |

---

## Architecture

The service sits between HR/payroll, the bank, and internal readers (dashboards).

```
                    ┌─────────────────────┐
                    │  Payroll provider   │
                    │  (stub / webhook)   │
                    └──────────┬──────────┘
                               │ POST /api/v1/webhooks/payroll
                               │ (HMAC signed)
                               ▼
┌──────────────┐      ┌─────────────────────┐      ┌──────────────────┐
│ Internal     │◄────►│  Eddekhar Wallet    │◄────►│  Bank partner    │
│ dashboard    │ GET  │  API (Laravel)      │ POST │  (stub + webhook)│
│ (employees,  │      │                     │      └────────┬─────────┘
│  wallets,    │      │  WalletService      │               │
│  tx history) │      │  + ledger (DB)      │◄──────────────┘
└──────────────┘                            │  + queue jobs       │   async confirm / fail
                      └──────────┬──────────┘
                                 │
                    ┌────────────┴────────────┐
                    ▼                         ▼
         ┌──────────────────────────┐    ┌─────────────────────┐
         │  Queue backend          │    │  MySQL               │
         │  local: jobs table      │    │  employees, wallets, │
         │  prod: Redis            │    │  transactions,       │
         │  (ProcessPayrollJob, …) │    │  payroll_runs, jobs  │
         └──────────────────────────┘    └─────────────────────┘
```

**Request flows (simplified):**

1. **Payroll** pushes a signed event → webhook accepts → `PayrollRun` row → `ProcessPayrollEventJob` → `WalletService::credit` (idempotent per employee per run).
2. **Withdrawal** reserves balance when created → `InitiateBankPaymentJob` calls bank stub → stub schedules callback → `POST /api/v1/webhooks/bank` confirms or fails (**refund on fail**).
3. **Reads** (employees, wallets, paginated transaction history) go through API resources; **no balance mutation on GET** routes.

---

## Quick start (local)

### Prerequisites

- PHP 8.3+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`
- Composer 2.x
- MySQL 8+ (or MariaDB)
- **Redis 6+** — production queue only (optional locally if `QUEUE_CONNECTION=redis`)
- Optional: Postman / Bruno / Insomnia (see [Postman](#postman))

### 1. Install and configure

```bash
git clone <your-repo-url>
cd eddekhar-wallet

composer install

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
DB_DATABASE=eddekhar_wallet
DB_USERNAME=root
DB_PASSWORD=your_password

QUEUE_CONNECTION=database

PAYROLL_WEBHOOK_SECRET=your-payroll-secret   # must match Postman env
```

Create the database:

```sql
CREATE DATABASE eddekhar_wallet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Migrate

```bash
php artisan migrate
```

### 3. Run

**Terminal 1 — HTTP:**

```bash
php artisan serve
```

**Terminal 2 — queue worker (required for payroll credits and bank callbacks):**

```bash
php artisan queue:work
```

Uses the `jobs` table when `QUEUE_CONNECTION=database`. For production Redis, start Redis, set `QUEUE_CONNECTION=redis`, then run the same worker command.

### 4. Verify health

```bash
curl http://127.0.0.1:8000/up
```

---

## Queue setup

| Environment | `QUEUE_CONNECTION` | Extra setup |
|-------------|-------------------|-------------|
| **Local (recommended)** | `database` | MySQL only — jobs in `jobs` table; run `php artisan queue:work` |
| **Production** | `redis` | Redis 6+ + `php artisan queue:work` (or Horizon) |
| **Tests** | `sync` | Set in `phpunit.xml` — no worker, no Redis |

The same three jobs run everywhere: `ProcessPayrollEventJob`, `InitiateBankPaymentJob`, `SendBankWebhookCallbackJob`. Only the **queue backend** changes.

**Local `.env` (no Docker / no Redis):**

```env
QUEUE_CONNECTION=database
```

**Production `.env`:**

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Why async jobs?

All money-moving side effects after webhooks / withdrawals run in the **queue**, not in the HTTP thread.

| Job | Why async |
|-----|-----------|
| **`ProcessPayrollEventJob`** | Decouples the payroll webhook response from salary processing. Large runs must not block the request or risk timeouts. |
| **`InitiateBankPaymentJob`** | Bank payout initiation is modeled as async; the withdrawal API returns after **reserving** funds, not after the bank responds. |
| **`SendBankWebhookCallbackJob`** | Simulated bank callbacks are queued so stub timing and retries do not block the main thread. |

**Local → `database` queue:** no Redis; jobs live in MySQL (`jobs` table from migrations). Behaviour matches production for this take-home; throughput / latency differ at scale.

**Production → `redis` queue:** Redis is used **only** as the queue driver — not for cache (`CACHE_STORE=database`) or sessions (`SESSION_DRIVER=database`). Faster dispatch and lower DB load under many jobs. `predis/predis` is included; set `REDIS_CLIENT=phpredis` if the PHP `redis` extension is installed.

```bash
php artisan queue:work
```

---

## Design decisions

### Ledger and concurrency

- Every balance change creates an immutable **`transactions`** row with `balance_after` and a unique **`reference_id`** (idempotency).
- **`WalletService`** wraps updates in DB transactions with **`SELECT … FOR UPDATE`** on wallets so concurrent credits/debits cannot drive the balance negative.
- MySQL enforces **`balance >= 0`** on `wallets` via check constraint `wallets_balance_non_negative` (see migration `create_wallets_table`).
- Amounts use **`bcmath`** (4 decimal places internally; API formats to 2 for display).

### Money movement on the HTTP surface

Generic credit/debit are **not** exposed as public v1 routes. **Transfer** is available at `POST /api/v1/transfers` (same employee, same currency). Other money moves use domain flows:

| Flow | HTTP | Service |
|------|------|---------|
| Salary credit | `POST /api/v1/webhooks/payroll` | `ProcessPayrollEventJob` → `credit()` |
| Withdrawal reserve | `POST /api/v1/wallets/{wallet}/withdrawals` | `initiateWithdrawal()` |
| Bank failure refund | `POST /api/v1/webhooks/bank` (`failed`) | `credit()` type `withdrawal_refund` |

Internal building blocks: `credit()`, `debit()`, `transfer()` on `WalletService` (tested in `WalletServiceTest`).

### Wallets per employee

- Types: **`salary`**, **`savings`** — at most one of each per employee (DB unique + validation).
- Create employee → default **salary** wallet.
- Add **savings** later: `POST /api/v1/employees/{employee}/wallets`.

### Transfers

- **`transfer()`** only between wallets of the **same employee**, same currency.
- **Employer pool → employee** is modeled as **payroll credit**, not transfer (no company wallet table in v1).

### Payroll idempotency

- `salary_run.processed` **must** include stable **`run_id`** or **`id`**; otherwise `422` `MISSING_RUN_IDENTIFIER`.
- `PayrollRun.external_id` + credit reference `payroll_{runId}_{employeeExternalId}` prevent double-pay on retries. Optional HTTP **`Idempotency-Key`** (stored on `payroll_runs.idempotency_key`, unique when set) ties retries to one run before `external_id` resolution. **`processed_by`** records the queue job id / connection when `ProcessPayrollEventJob` starts processing (helps debug duplicate or stuck workers).

### Withdrawals

- Funds are **debited when the withdrawal is created** (`withdrawal_reserved`); they are not spendable while pending.
- Bank confirmation is **async** via webhook; failure **refunds** the wallet.

### Authentication

- **Out of scope** for the take-home: all endpoints are open. Production would use Sanctum/API keys and roles.

### API envelope

- Success: `{ success, message, data, meta?, links? }`
- Error: `{ success: false, message, error_code, errors? }`

---

## API overview (v1)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/up` | Health check |
| GET/POST | `/api/v1/employees` | List / create (+ salary wallet); list supports filtering + pagination |
| GET | `/api/v1/employees/{id}` | Show with wallets |
| GET/POST | `/api/v1/employees/{id}/wallets` | List / create wallet |
| GET | `/api/v1/wallets/{id}` | Wallet detail |
| GET | `/api/v1/wallets/{id}/transactions` | Paginated ledger (`type`, `date_from`, `date_to`, `per_page`, `page`) |
| POST | `/api/v1/transfers` | Move funds between two wallets (same employee, same currency) |
| POST | `/api/v1/wallets/{id}/withdrawals` | Start withdrawal |
| GET | `/api/v1/withdrawals/{id}` | Withdrawal status |
| POST | `/api/v1/webhooks/payroll` | Payroll events |
| POST | `/api/v1/webhooks/bank` | Bank confirmation |

### Transaction history (“what / when / why”)

`GET /api/v1/wallets/{wallet}/transactions` returns:

- **Chronological:** newest first
- **Paginated:** `meta` + `links` (same pattern as employee/wallet lists)
- **Fields:** `type`, `description`, `amount`, `balance_after`, `reference_id`, `created_at`

| Movement | How to interpret |
|----------|------------------|
| Payroll credit | `reference_id` = `payroll_{runId}_{employeeExternalId}` → join `payroll_runs.external_id` |
| Withdrawal reserve | `withdrawal_reserved` + `withdrawal_reserve:{uuid}` |
| Refund | `withdrawal_refund:{withdrawalId}` |
| Internal transfer | `transfer_out` / `transfer_in` with shared base `reference_id` |

---

## Simulated partners

### Payroll provider

**Inbound webhook:** `POST /api/v1/webhooks/payroll`

- Headers: `X-Payroll-Timestamp`, `X-Payroll-Signature` (HMAC-SHA256 over `{timestamp}.{raw_body}` with `PAYROLL_WEBHOOK_SECRET`); optional **`Idempotency-Key`** (max 255 chars) — same key maps to one `PayrollRun` row (`idempotency_key`, unique when set) so retries do not enqueue duplicate jobs while a run is **pending** or **processing**
- Events: `employee.onboarded`, `employee.status_changed`, `salary_run.processed`
- Processing is **queued** (`ProcessPayrollEventJob`); webhook returns **quick acceptance** (202-style)

**Local stub (no external HTTP):** `POST /stubs/payroll/trigger`

- Body: `{ "event_type": "...", "payload": { ... } }`
- Forwards to the webhook via an internal sub-request (avoids loopback issues on `php artisan serve`)

### Payroll integration (inbound) — design

**Approach: push only.** The provider calls our webhook; we do not poll their API.

**Event payloads** (inside `payload` for stubs, or top-level for some envelopes — see `PayrollWebhookPayload::eventData`):

| Event | Required fields | Effect |
|-------|-------------------|--------|
| `employee.onboarded` | `employee_id` (maps to `employees.external_id`), `name`, `email`, `company_id`, optional `currency` | Upsert employee + ensure **salary** wallet exists (`currency` default `SAR`) |
| `employee.status_changed` | `employee_id`, `status` (`active` \| `inactive` \| `terminated`) | Update status; **`terminated` locks all wallets**; salary run **skips** terminated employees; `active` / `inactive` unlocks |
| `salary_run.processed` | Stable `run_id` or `id`, `employees[]` with `employee_id` + `amount`; optional `period_start` / `period_end` | Credit each employee’s **salary** wallet |

**Important:** Use **`employee_id`** in JSON (HR’s external key), not `external_id` — that is our DB column name for the same value.

**Salary run currency:** Credits use the **wallet’s stored currency** (set at onboard). Per-line `currency` on each employee in the payload is **ignored** in v1 (would need validation + FX to honor).

**Resilience:**

- **Late events:** No “must be current month” gate; we process when received. Timestamp header must be within **5 minutes** (anti-replay); payroll should **resend with a fresh timestamp** for legitimately late batches.
- **Duplicates:** `PayrollRun.external_id` = `run_id` (or `id`); completed runs are no-ops. Wallet credits use idempotent `reference_id` = `payroll_{runId}_{employee_id}`. Send the same optional **`Idempotency-Key`** header on safe retries so the HTTP edge maps to a single `PayrollRun` even under races.
- **Brief failures:** `ProcessPayrollEventJob` uses **`$tries = 3`** and **`backoff()`** `[30, 60, 120]` seconds, then Laravel can record **failed_jobs** if still failing. (`InitiateBankPaymentJob` has its own tries/backoff for bank calls.)

### Bank / payments partner

**Outbound:** `InitiateBankPaymentJob` → `BankService` → `POST /stubs/bank/payments` (simulated accept)

**Inbound confirmation:** `POST /api/v1/webhooks/bank`

- Body: `{ "bank_reference_id", "status": "confirmed" | "failed" }`
- Stub schedules `SendBankWebhookCallbackJob` to hit this webhook after a short delay

---

## Postman

**Required take-home flows:** payroll (**5. Webhooks** / **6. Stubs** → salary run) and withdrawal (**4. Withdrawals** + async bank confirmation).

1. Import `postman/eddekhar-wallet.postman_collection.json`
2. Import `postman/eddekhar-wallet-local.postman_environment.json`
3. Set `payroll_webhook_secret` to the same value as `PAYROLL_WEBHOOK_SECRET` in `.env`
4. With `php artisan serve` and `php artisan queue:work` running, run requests in order: **0. Health** → **1. Employees** (Create) → **6. Stubs** (Salary Run) or **5. Webhooks** → **4. Withdrawals** (Create) → poll **Show Withdrawal** / bank webhook

**Terminated employee (manual check):**

1. **5. Webhooks** → `employee.status_changed` with `status: terminated` for an existing `employee_id` (same as `external_id` in DB).
2. **5. Webhooks** or **6. Stubs** → `salary_run.processed` including that same employee with an amount.
3. **GET** transaction history for that employee’s salary wallet — **no new credit** for that run’s reference pattern.
4. **GET** wallet — **balance unchanged** from before step 2; `PayrollRun` may show `completed_with_errors` with `Employee terminated` in `processing_errors`.

---

## Tests

### Critical coverage

| Area | Test file(s) |
|------|----------------|
| Wallet ledger / idempotency / transfer rules | `WalletServiceTest` |
| Payroll webhook + dedupe | `PayrollWebhookTest`, `PayrollPartialFailureTest`, `PayrollStubTest` |
| Withdrawal + bank flow | `WithdrawalFlowTest`, `WithdrawalApiTest`, `WithdrawalBankStubTest` |
| Pagination & filters | `EmployeeIndexTest`, `WalletIndexTest`, `TransactionIndexTest` |
| Multi-wallet API | `StoreWalletTest` |
| Health | `HealthEndpointTest` |

### Run tests

Create a test database, then:

```bash
# phpunit.xml expects DB_DATABASE=eddekhar_wallet_test
php artisan migrate --database=mysql --env=testing   # or create DB manually
php artisan test
```

Tests use `QUEUE_CONNECTION=sync` (no Redis required in CI) and `PAYROLL_WEBHOOK_SECRET=test-payroll-secret` from `phpunit.xml`.

---

## Terminated employee handling

When `employee.status_changed` is received with `status = terminated`:

1. **`employees.status`** is set to `terminated`.
2. **All wallets** for that employee get **`is_locked = true`** (no spending / transfers / withdrawals on those wallets).
3. **`salary_run.processed`** skips any row for an employee whose status is **`terminated`** — no new payroll credit, with a **warning log** and an entry in `PayrollRun.processing_errors` (`reason`: `Employee terminated`).
4. **`WalletService::credit()`** still checks lock after idempotency: if a credit is retried with the same `reference_id` as an already-posted payroll line, the **existing transaction is returned** (no double-credit). A **new** credit on a locked wallet **fails** with `WalletLockedException` and is **logged** (`Credit attempted on locked wallet`).

`active` / `inactive` restores **`is_locked = false`** so payroll can resume if HR reinstates the person (business rule you can tighten later).

---

## Assumptions

- **Unknown employees in a salary run** are skipped; the run completes for others (`processing_errors` on `PayrollRun` when partial failures).
- **`employee.status_changed` with `terminated`** locks all wallets and **subsequent salary run lines for that employee are skipped** (no credit). `active` / `inactive` unlocks wallets again.
- **Intra-employee transfers only**; employer funding via payroll credit.
- **Same currency** per transfer; FX not implemented (see below).
- **No API authentication** in v1.

### One wallet per type per employee

An employee can have **exactly one wallet per `type`** (one `salary`, one `savings`). That rule is enforced in the database with **`UNIQUE (employee_id, type)`** on `wallets`. If the product later needs **multiple wallets of the same type** (e.g. several savings pots), that constraint would need to be removed or replaced (for example with a separate “purpose” or “label” column in the unique key, or a different ownership model).

### FX (not implemented)

If required later: quote service with expiry, per-currency settlement wallets, explicit conversion legs with `metadata.fx_rate` and `quote_id` — never silent conversion inside `transfer`.

---

## What I would improve with more time

1. **Authentication & authorization** — Sanctum tokens, admin vs read-only scopes, webhook IP allowlists.
2. **Observability** — structured logging, metrics on payroll run failures, withdrawal SLA alerts.
3. **Admin APIs** — optional guarded routes to exercise `credit` / `debit` / `transfer` for support tooling.
4. **Richer transaction metadata** — payroll period, withdrawal id, on every ledger row for dashboards without joins.
5. **Employer wallets** — company pool accounts and disbursement type for non-payroll funding.
6. **Outbox / idempotency keys** — `Idempotency-Key` on withdrawals and bank webhooks at the HTTP edge (payroll webhook already supports it).
7. **OpenAPI spec** — generated docs alongside Postman.
8. **SQLite for tests** — zero-config CI without a MySQL test database.
9. **Horizon / multiple queue workers** — scale Redis consumers per queue name in production.

---

## License

MIT (Laravel framework components retain their respective licenses).
