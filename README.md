# PHILCST Centralized School Facility Maintenance Reporting System

A web-based system for reporting, triaging, repairing, and tracking facility maintenance
work at PhilCST, with an integrated, ledger-backed inventory and dispatch module.

This file is the single source of truth for the project's documentation. It consolidates
what were previously ~200 separate Markdown files (architecture notes, business-rule
specifications, deployment runbooks, design-system notes, QA templates, and per-task audit
reports) into one document. See [§21 Documentation History](#21-documentation-history) for
where the originals went and how to recover them.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Purpose](#2-purpose)
3. [Key Features](#3-key-features)
4. [Technology Stack](#4-technology-stack)
5. [System Architecture](#5-system-architecture)
6. [User Roles and Permissions](#6-user-roles-and-permissions)
7. [Main System Workflows](#7-main-system-workflows)
8. [Maintenance Report Workflow](#8-maintenance-report-workflow)
9. [Inventory Management](#9-inventory-management)
10. [Dispatch and Release Workflow](#10-dispatch-and-release-workflow)
11. [Damage Reports](#11-damage-reports)
12. [Repair Request and Replacement Workflow](#12-repair-request-and-replacement-workflow)
13. [Security and Authorization](#13-security-and-authorization)
14. [Database Overview](#14-database-overview)
15. [Installation and Setup](#15-installation-and-setup)
16. [Running the System](#16-running-the-system)
17. [Testing](#17-testing)
18. [Deployment](#18-deployment)
19. [Maintenance and Troubleshooting](#19-maintenance-and-troubleshooting)
20. [Current Project Status](#20-current-project-status)
21. [Documentation History](#21-documentation-history)

---

## 1. Project Overview

The system replaces paper- and chat-based facility maintenance reporting with a single
digital system of record. A staff member reports a problem; an administrator triages and
assigns it; a maintenance technician performs the work; parts consumed are drawn from a
tracked inventory; and every status change, approval, and stock movement is recorded and
attributable.

The application is a **hybrid Laravel + legacy-PHP system**, deliberately so. The business
logic, data access, authorization, and HTTP API all live in a modern Laravel 12
application under `app/`. The user-facing pages are still procedural PHP under
`public/frontend/`, which call the Laravel JSON API over `fetch()`. The two halves share a
single authenticated session through a bridging middleware. This is an intentional,
incremental migration posture — not an accident — and it is described in
[§5 System Architecture](#5-system-architecture).

**Scale of the current implementation** (verified against the working tree):

| | Count |
|---|---|
| Eloquent models | 21 |
| Service classes | 19 |
| API controllers | 24 |
| Middleware | 3 |
| Model observers | 2 |
| Console commands | 2 |
| Database migrations | 43 |
| Database tables | 39 |
| HTTP routes | 186 |
| Frontend pages | 38 |
| Automated tests | 740 (68 files, 2,811 assertions) |

---

## 2. Purpose

The system exists to solve five specific operational problems:

1. **Reports get lost.** Maintenance requests made verbally or by chat have no record, no
   owner, and no status. Every report here is a database row with a status, an assignee, a
   location, and a full history.
2. **Nobody knows what stock is actually on hand.** Parts were consumed without being
   recorded. The system uses an append-only inventory ledger, so on-hand quantity is a
   provable consequence of recorded movements rather than a number someone typed in.
3. **Approvals are not attributable.** Who approved releasing a given quantity of stock?
   The system records the approver, the timestamp, and the reason for every approval and
   every status transition.
4. **There is no operational visibility.** Administrators could not answer "what is
   overdue," "what breaks most often," or "what are we running out of." The analytics
   module answers these from live data.
5. **Roles are not enforced.** Access was by convention. Authorization is now enforced at
   the route layer and again in service classes.

---

## 3. Key Features

**Maintenance reporting**
- Structured report submission with Building / Floor / Room location selection (not free text)
- Priority levels, categories, and photo attachment
- Assignment to a technician, with department-mismatch warning and audit trail
- Completion proof image upload
- Full status history per report

**Inventory management**
- Ledger-backed stock tracking (`inventory_transactions`) — every quantity change is a recorded movement
- Reserve / release / deploy / return / adjustment / dispose transaction types
- Automatic status derivation: `available` / `low_stock` / `out_of_stock`
- Low-stock notification to administrators
- Purchase receipt posting (draft → posted) as the stock-in path
- Room-asset vs. warehouse-stock distinction, enforced by invariant
- Per-item asset code generation

**Dispatch**
- Multi-item dispatch requests against a maintenance report
- Approval step (pending → approved → released), with segregation of duties
- Release deducts stock through the ledger; duplicate line items are aggregated correctly

**Damage reports and repairs**
- Separate damage-report intake with its own lifecycle
- Repair requests with technician assignment, diagnosis, parts-waiting, completion, and failure states
- Replacement workflow when a repair fails, composed from deploy + dispose ledger transactions

**Preventive maintenance**
- Scheduled recurring maintenance tasks with completion proof

**Administration and visibility**
- User registration with administrator approval, role assignment, and activation/deactivation
- Buildings / floors / rooms, departments, suppliers, and semester settings management
- Activity log covering privileged actions
- In-app notification centre
- Analytics dashboard: top requested items, top repaired, monthly and semester comparison,
  department usage, inventory health, low stock, damaged items, dispatch/repair/replacement reports
- Automated nightly database backup

---

## 4. Technology Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12.56.0 |
| Language | PHP 8.2.12 |
| Database | MariaDB 10.4.32 (MySQL-compatible) |
| Local stack | XAMPP (Apache + MariaDB) on Windows |
| Frontend | Procedural PHP pages + vanilla JavaScript (`fetch`) + CSS |
| Build tooling | Vite + Tailwind CSS (`@tailwindcss/vite`) |
| Testing | PHPUnit via `php artisan test`, SQLite in-memory |
| Mail | PHPMailer 7 |

**PHP dependencies** (`composer.json`, `require`):

```
php                     ^8.2
laravel/framework       ^12.0
laravel/tinker          ^2.10.1
phpmailer/phpmailer     ^7.0
```

Dev dependencies include `fakerphp/faker`, `laravel/pail`, `laravel/pint`,
`laravel/sail`, `mockery/mockery`, `nunomaduro/collision`, and `phpunit/phpunit`.

**Node dependencies** are `devDependencies` only — there is no runtime JavaScript bundle
dependency: `@tailwindcss/vite`, `axios`, `concurrently`, `laravel-vite-plugin`,
`tailwindcss`, `vite`.

---

## 5. System Architecture

### 5.1 The hybrid model

```
Browser
   │
   ├── public/frontend/pages/*.php ──────┐  procedural PHP pages (UI)
   │                                     │  render HTML, then call the API
   │                                     ▼
   └── /api/* ──► routes/web.php ──► Middleware ──► Controller ──► Service ──► Model
                                                                      │
                                                                      ▼
                                                                  Observer
                                                                      │
                                                                      ▼
                                                                   Database
```

The root `index.php` acts as a front controller that dispatches between the legacy
frontend surface and the Laravel application. The `SyncLegacyPhpSession` middleware
bridges the native PHP `$_SESSION` used by the legacy pages and the Laravel session, so a
user logs in once and is authenticated on both surfaces.

**All routes live in `routes/web.php`.** There is deliberately no `routes/api.php`; the
JSON API is declared inside a `Route::prefix('api')` group in `routes/web.php` so that it
shares the web session middleware stack — which is what makes the legacy bridge work.

### 5.2 Folder structure

```
app/
  Console/Commands/    BackfillInventoryStockStatus, BackupDatabase
  Exceptions/          DuplicateDamageReportException, DuplicateDeploymentException
  Http/
    Controllers/Api/   24 JSON API controllers
    Middleware/        EnsureApiAuthenticated, EnsureRole, SyncLegacyPhpSession
  Models/              21 Eloquent models
  Observers/           InventoryTransactionObserver, InventoryStockEntryObserver
  Services/            19 service classes — all business logic lives here
bootstrap/app.php      middleware registration, CSRF exemptions
config/                Laravel configuration
database/
  migrations/          43 migrations — the sole source of schema truth
  seeders/             DatabaseSeeder
  factories/           model factories for tests
public/
  index.php            Laravel public entry point
  frontend/
    pages/             38 user-facing PHP pages
    includes/          header, footer, sidebar, session-guard
    assets/            css, js, images, uploads
routes/
  web.php              all HTTP routes, including the /api group
  console.php          scheduled tasks
tests/
  Feature/             HTTP-level and workflow tests
  Unit/                isolated logic tests
  Support/             shared test infrastructure
```

### 5.3 The service layer

All 19 services, and what each owns:

| Service | Responsibility |
|---|---|
| `ReportService` | Maintenance report create / update / delete |
| `ReportAuthorizationService` | **The** single source of truth for report modification rights |
| `MaintenanceReportSyncService` | Keeps a linked damage report and maintenance report in step (privileged writer — see §13.4) |
| `DamageReportService` | Damage report lifecycle and history |
| `RepairService` | Repair request lifecycle, replacement composition |
| `NeedChangeService` | Need Change request and approval, including inventory deduction |
| `DispatchService` | Dispatch creation, approval, release, cancellation |
| `DispatchAuthorizationService` | Who may approve and who may release a dispatch |
| `InventoryAdjustmentService` | The only sanctioned manual quantity-change path |
| `InventoryStatusService` | Derives `items.status` from quantity and reorder level |
| `InventoryLowStockNotifier` | Raises de-duplicated low-stock notifications |
| `PurchaseReceiptPostingService` | Posts a draft receipt, creating stock-in transactions |
| `AssetCodeGenerator` | Generates per-item asset codes |
| `PreventiveMaintenanceService` | Scheduled maintenance tasks and completion |
| `PersonnelDirectoryService` | Technician/personnel lookup |
| `AnalyticsService` | All dashboard and report aggregations |
| `NotificationService` | In-app notification creation and delivery |
| `ActivityLogService` | Audit log writes |
| `RoleNormalizerService` | The only sanctioned role-string comparison path |

### 5.4 Layering rules

The layering is strict and is the project's most important architectural constraint:

1. **Controllers** validate input, call a service, and shape the HTTP response. They do
   not contain business rules.
2. **Services** own all business logic, authorization decisions, and orchestration. A
   service may call other services.
3. **Models** are data access and relationships. They do not contain workflow logic.
4. **Observers** are the *only* writers of derived inventory state. See
   [§9 Inventory Management](#9-inventory-management).

### 5.5 Coding standards

These are enforced by convention and code review:

- Business logic belongs in a service class, never in a controller or a view.
- Any operation that reads state and then mutates it based on that read must run inside
  `DB::transaction()` and must take a `lockForUpdate()` row lock on what it read.
- Inventory quantity is never assigned directly outside the observers.
- Role strings are always normalized through `RoleNormalizerService` before comparison.
- Privileged/unguarded writers are explicitly documented as such (see §13.4).
- Schema changes are made only via additive migrations; `inventory_transactions` and
  `activity_logs` are append-only audit surfaces and must never be destructively migrated.
- New behavior ships with test coverage.

---

## 6. User Roles and Permissions

There are three operational roles, stored in `users.role` as `varchar(50)`.

| Stored role | Display name | Scope |
|---|---|---|
| `super_admin` | Administrator | Full system access, including user management |
| `maintenance_admin` | Head Maintenance | Department-scoped administration and approvals |
| `maintenance_staff` | Maintenance Staff | Executes assigned work |

A newly registered account has the raw role `user` and status `pending`. It cannot reach
any protected endpoint until an Administrator approves it and assigns a real role. The
approval endpoint will not assign `super_admin`, and will not operate on a user who is not
pending.

### 6.1 Role aliases

Historical data and some legacy pages use alternative role strings. `RoleNormalizerService`
is the single canonical mapping:

| Alias | Normalizes to |
|---|---|
| `admin_maintenance` | `maintenance_admin` |
| `eelab_staff` | `maintenance_staff` |
| `maintenance_personnel` | `maintenance_staff` |

Two entry points exist and their difference matters:

- `normalize($role)` — an empty/unknown input returns `''` (no role). Use this where an
  unknown role must *not* be granted anything.
- `normalizeWithStaffDefault($role)` — an empty input returns `'maintenance_staff'`. Use
  this only where a legacy record legitimately means "staff."

Any new code that compares a role string without normalizing it first is a bug.

### 6.2 Department scoping

`super_admin` is not department-scoped and may be created without a department.
`maintenance_admin` and `maintenance_staff` **must** have a department; the API rejects
creation without one, rejects an explicit `null`, rejects a nonexistent department, and
rejects role aliases used to bypass the requirement.

### 6.3 Permission summary

| Capability | Administrator | Head Maintenance | Maintenance Staff |
|---|---|---|---|
| Submit maintenance report | ✔ | ✔ | ✔ |
| View reports | all | department | own / assigned |
| Assign a report to a technician | ✔ | ✔ | ✘ |
| Update report status | ✔ | ✔ | ✔ (assigned only) |
| Approve Need Change | ✔ | ✔ | ✘ |
| Approve a dispatch | ✔ | ✔ | ✘ |
| Release a dispatch | ✔ | ✔ | ✔ (if designated) |
| Manual stock adjustment | ✔ | ✔ | ✘ |
| Post a purchase receipt | ✔ | ✔ | ✘ |
| View analytics | ✔ | ✔ | ✔ |
| Manage users / approve signups | ✔ | ✘ | ✘ |
| Manage buildings, rooms, departments | ✔ | ✔ | ✘ |

---

## 7. Main System Workflows

```
                    ┌─────────────────────┐
                    │  Maintenance Report │  staff reports a problem
                    └──────────┬──────────┘
                               │ triage + assign
                               ▼
                    ┌─────────────────────┐
                    │   Assigned to tech  │
                    └──────────┬──────────┘
                               │ needs parts?
              ┌────────────────┴────────────────┐
              │ no                              │ yes
              ▼                                 ▼
     ┌────────────────┐              ┌────────────────────┐
     │  In Progress   │              │  Dispatch request  │
     └───────┬────────┘              └─────────┬──────────┘
             │                                 │ approve → release
             │                                 ▼
             │                       ┌────────────────────┐
             │                       │   Stock deducted   │  (ledger)
             │                       └─────────┬──────────┘
             │◄────────────────────────────────┘
             ▼
     ┌────────────────┐
     │   Completed    │  completion proof uploaded
     └───────┬────────┘
             ▼
     ┌────────────────┐
     │     Closed     │
     └────────────────┘
```

A parallel track exists for **Damage Reports** (an asset is broken, rather than a facility
issue needing work), which can escalate into a **Repair Request**, which on failure can
escalate into a **Replacement**. Those are described in §11 and §12.

---

## 8. Maintenance Report Workflow

### 8.1 States

`maintenance_reports.status` is an enum with exactly these values:

```
submitted → assigned → in_progress → completed → closed
                                              ↘ cancelled
```

`maintenance_reports.priority` is an enum: `low`, `medium`, `high`, `urgent`, `critical`.

### 8.2 Creation

Reports are created through `ReportController::store()` → `ReportService`. Location is
**validated as a real Building / Floor / Room triple**, not accepted as free text — the
frontend uses dependent select inputs and the backend re-validates the relationship.

### 8.3 Assignment

Assignment sets the technician and moves the report to `assigned`. If the technician's
department does not match the report's department, the UI shows a confirmation modal and
the override is recorded in the activity log — the assignment is allowed, but it is never
silent.

### 8.4 Modification authorization

`ReportAuthorizationService::canModifyReport()` is the **single source of truth** for
whether a given user may modify a given report. Every modification path routes through it.
There is no parallel or duplicated check. It combines role and department scope:
Administrators may modify anything; Head Maintenance may modify within their department;
Maintenance Staff may modify only reports assigned to them.

### 8.5 Need Change

"Need Change" is the path where a technician determines that a part must be consumed to
complete the report. Approval of a Need Change performs a real inventory deduction through
the ledger — this was historically a no-op and was closed on 2026-07-21. Only
Administrators and Head Maintenance may approve; Maintenance Staff are explicitly denied.

`need_change_status` is a `varchar(50)` column carrying `pending` / `approved` /
`deducted` / `failed`. The rejection path writes `rejected`, which is not one of those four
— a known, documented naming inconsistency that does not affect behavior (see §20.2).

Need Change approval rejects a **room asset** as the replacement item (§9.4); only
inventory stock is accepted.

### 8.6 Completion

Completion requires a completion proof image, uploaded to
`public/frontend/uploads/completion-proofs/`. A completion notification is sent to the
reporter.

### 8.7 Damage-report synchronisation

`MaintenanceReportSyncService` keeps a linked damage report in step with its maintenance
report. It maps statuses as follows:

| Damage report status | Maintenance report status |
|---|---|
| `pending` | `submitted` |
| `under_review` | `assigned` |
| `repairing` | `in_progress` |
| `repaired` | `completed` |
| `replaced` | `completed` |
| `closed` | `closed` |

This service is a **deliberately privileged, unguarded writer** — it acts on behalf of the
system, not on behalf of a user, so it does not run the normal authorization check. It must
only ever be called from a code path that has already authorized the triggering action.
See §13.4.

---

## 9. Inventory Management

This is the most safety-critical subsystem in the project, because defects here corrupt
real-world physical stock records.

### 9.1 The single-writer principle

**Only `InventoryTransactionObserver` and `InventoryStockEntryObserver` may assign
`items.quantity` or `items.reserved_quantity`.** No controller, service, job, command, or
raw query may write those columns.

All other code expresses *intent* by creating an `InventoryTransaction` row. The observer
sees the new row and applies the arithmetic. This makes on-hand quantity a provable
function of the recorded ledger rather than an independently-maintained number that can
silently drift.

The observers take `lockForUpdate()` row locks inside `DB::transaction()` on `creating()`,
`created()`, and `deleted()` before touching item quantities.

### 9.2 Transaction types

`inventory_transactions.transaction_type` is an enum:

| Type | Effect |
|---|---|
| `reserve` | Increases `reserved_quantity` (stock earmarked, not yet gone) |
| `release` | Decreases `reserved_quantity` (reservation cancelled) |
| `deploy` | Decreases `quantity` (stock physically issued) |
| `return` | Increases `quantity` (stock came back) |
| `adjustment` | Corrects `quantity` up or down (manual stock adjustment) |
| `dispose` | Decreases `quantity` (stock written off) |

Each row records `item_id`, optional `report_id` / `dispatch_id` / `room_id`, `quantity`,
a `reference_note`, and `performed_by`. The table is append-only audit history.

### 9.3 Derived item status

`items.status` is never set by hand for stock-level purposes. `InventoryStatusService`
derives it:

```
quantity <= 0                          → out_of_stock
0 < quantity <= reorder_level          → low_stock
otherwise                              → available
```

(The enum also carries `damaged` and `maintenance`, which describe asset condition rather
than stock level.)

### 9.4 Room assets vs. warehouse stock

An item created **with** a room is a *room asset*; an item created **without** a room is
*inventory stock*. This invariant is enforced server-side — a client cannot override the
item type to break it. Moving an item into a room retypes it as a room asset; removing it
retypes it as inventory stock; updating an unrelated field leaves the type alone.

Room assets are excluded from replacement-item queries — you cannot fulfil a replacement
out of an asset that is already installed somewhere — and Need Change approval rejects a
room asset as the replacement item. Adding a room item does not change existing warehouse
stock, and the same item may be assigned to two different rooms but not twice to the same
room.

### 9.5 Stock-in

Stock enters the system through **purchase receipts**. A receipt is created as `draft`,
edited freely, and then **posted**. Posting is the irreversible step that creates the
inventory transactions. `PurchaseReceiptPostingService` owns this.

### 9.6 Manual stock adjustment

`ItemController::adjustStock()` → `InventoryAdjustmentService` is the **only** sanctioned
path for directly changing on-hand quantity. A previous bypass in
`InventoryStockController::update()` that wrote `items.quantity` via raw SQL was closed on
2026-07-22.

### 9.7 Low stock notification

`InventoryLowStockNotifier` raises an in-app notification to administrators when an item
crosses its reorder level, de-duplicated so that a single crossing does not spam.

---

## 10. Dispatch and Release Workflow

A **dispatch** is a request to issue one or more inventory items against a maintenance
report.

### 10.1 States

`dispatches.status` is an enum:

```
pending → approved → released
      ↘ cancelled ↙
```

### 10.2 The flow

1. **Create** — a dispatch is created with line items (`dispatch_items`) and a designated
   *Release Personnel* (`release_assigned_to`). Stock is `reserve`d. The requester is
   recorded in `requested_by`, distinct from the approver and the releaser.
2. **Approve** — an Administrator or Head Maintenance approves, setting `approved_by` and
   `approved_at`.
3. **Release** — the items are physically handed over. Release creates `deploy`
   transactions, which the observer applies to `items.quantity`.
4. **Cancel** — at any point before release, cancelling creates `release` transactions
   that give the reservation back.

### 10.3 Administrator dispatches skip the approval step

When an Administrator creates a dispatch, `DispatchAuthorizationService::creationRequiresApproval()`
returns false and the dispatch is created directly in status `approved` — there is nobody
above an Administrator to approve it. Two details matter:

- `'approved'` here means *"no approval step applies"*, not *"an approval happened."* No
  new status was invented for this case; the existing enum value is reused because its
  operational meaning is "releasable."
- **`approved_by` and `approved_at` are deliberately left NULL.** Writing the creator into
  those columns would fabricate an approval event that never occurred, corrupting the audit
  trail. The absence of an approver is the honest record.

The decision is made in the authorization service from the creator's role, never from
client input, and never inside `DispatchService` itself.

### 10.4 Segregation of duties

`DispatchAuthorizationService` governs who may approve and who may release. Requesting,
approving, and releasing are three distinct recorded capabilities (`requested_by`,
`approved_by`, `released_by`); approval is restricted to Administrator and Head
Maintenance.

### 10.5 Duplicate line items

If the same item appears on more than one line of a dispatch, `releaseDispatch()`
aggregates the quantities before creating ledger transactions. Failing to do so previously
produced an incorrect deduction; this was fixed and is covered by tests.

---

## 11. Damage Reports

A damage report records that a specific *asset* is broken, as distinct from a maintenance
report which records that something needs doing.

`damage_reports.status` is an enum:

```
pending → under_review → repairing → repaired → closed
                                   ↘ replaced ↗
```

`DamageReportService` owns creation and transitions. Every transition is written to
`damage_report_histories`. A damage report does **not** create a repair request at
creation time — the repair request is created separately when the work is actually
scheduled.

Duplicate damage reports against the same asset are rejected via
`DuplicateDamageReportException`.

When a damage report is linked to a maintenance report, `MaintenanceReportSyncService`
keeps the two in step using the mapping in §8.7.

---

## 12. Repair Request and Replacement Workflow

### 12.1 Repair request states

`repair_requests.repair_status` is an enum:

```
pending → assigned → diagnosing → repairing → completed
                                ↘ waiting_parts ↗
                                ↘ failed
                                          → archived
```

A repair request carries `repair_code`, the linked `damage_report_id` and `report_id`, the
assigned `technician_user_id`, `repair_type`, `repair_description`, `repair_cost`,
`repair_date`, `estimated_completion_date`, `completion_date`, and `notes`.

`RepairService` owns the lifecycle. Repair history is written to `repair_histories`.

### 12.2 Failure and replacement

If a repair cannot succeed, the request moves to `failed` with a `failure_reason`, and a
**replacement** may be issued. A replacement is not a first-class entity — it is
*composed* from the existing ledger primitives:

```
replacement = deploy (the new item)  +  dispose (the broken item)
```

The repair request records the outcome in `replacement_item_id`,
`replacement_quantity`, `replacement_dispatch_id`, and `replacement_transaction_id`, which
is what makes the composed operation traceable after the fact.

Replacement items must be inventory stock, never a room asset (§9.4). Duplicate deployments
are rejected via `DuplicateDeploymentException`.

### 12.3 Tracking

`ReplacementTrackingController` and `DeploymentTrackingController` expose read surfaces
over these records for the replacement-tracking and deployment-tracking pages.

---

## 13. Security and Authorization

### 13.1 Authentication

- Session-based authentication, shared between Laravel and the legacy PHP surface via
  `SyncLegacyPhpSession`.
- Session ID is regenerated on login (session-fixation defence).
- Login is rejected for `pending` accounts (403, "awaiting approval"), `inactive` accounts
  (403), and non-existent accounts (401, worded so as not to leak whether an account
  exists).
- Failed-login lockout applies after repeated failures within a time window (429); the
  counter clears after a successful login.
- A deactivated user's existing session is rejected on the next request — deactivation
  takes effect immediately, it does not wait for session expiry.

### 13.2 Authorization layers

Authorization is enforced twice, deliberately:

1. **Route layer** — `EnsureApiAuthenticated` wraps the whole `/api` group;
   `EnsureRole:role1,role2` gates individual route groups.
2. **Service layer** — services re-check authorization against the specific record being
   acted on (ownership, department scope, assignment), because the route layer only knows
   the role, not the record.

**There is no `app/Policies` directory, no Gate definitions, and no permission package.**
Authorization lives in service classes. This is intentional; do not introduce a parallel
mechanism without a deliberate architectural decision.

### 13.3 Authorization anchors

- `ReportAuthorizationService::canModifyReport()` — maintenance report modification
- `DispatchAuthorizationService` — dispatch approve / release
- `RoleNormalizerService` — the only sanctioned role-string comparison path
- `EnsureRole` — route-level role gate

### 13.4 Privileged unguarded writers

`MaintenanceReportSyncService` writes maintenance reports without running the normal
authorization check, by design, because it acts on behalf of the system in response to an
already-authorized damage-report transition. **This is the documented exception, not a
precedent.** Any new unguarded writer must be justified and documented here.

### 13.5 Other protections

- CSRF protection is enabled. The `/api/*` prefix is exempted because it is consumed by
  `fetch()` with session cookies from same-origin pages.
- Input is validated in controllers via `$request->validate()` / `Validator`. There are
  no `FormRequest` classes — validation is inline by convention.
- Mass assignment is constrained by `$fillable` on every model.
- Output is escaped at render time; SQL uses parameter binding throughout.
- Uploaded files are validated by type and size.

### 13.6 Multipart request note

The frontend submits updates as **`POST` with a `_method=PATCH` field** (Laravel method
spoofing) rather than a true `PATCH`, because PHP does not populate `$_FILES` for `PATCH`
bodies. Any new multipart update endpoint must follow the same pattern.

---

## 14. Database Overview

Database name: `school_facility_maintenance`. 39 tables, 43 migrations.
`database/migrations/` is the **sole source of schema truth** — the schema is never edited
directly in a GUI.

### 14.1 Core tables

| Table | Purpose |
|---|---|
| `users` | Accounts, role, department, status |
| `departments` | Organisational units |
| `buildings`, `floors`, `rooms` | Physical location hierarchy |
| `maintenance_reports` | The primary work record |
| `damage_reports`, `damage_report_histories` | Broken-asset records and their transitions |
| `repair_requests`, `repair_histories` | Repair lifecycle |
| `items` | Inventory items and room assets |
| `inventory_transactions` | **Append-only stock ledger** |
| `inventory_stock_entries` | Stock entry records |
| `inventory_categories`, `inventory_rooms` | Inventory classification |
| `dispatches`, `dispatch_items` | Dispatch requests and line items |
| `purchase_receipts`, `purchase_receipt_items` | Stock-in |
| `purchase_orders`, `purchase_requests`, `restock_requests` | Procurement records |
| `suppliers`, `supplier_histories` | Supplier records |
| `report_inventory_allocations` | Links stock allocation to a report |
| `preventive_maintenance_tasks`, `preventive_maintenance_history` | Scheduled maintenance |
| `activity_logs` | **Append-only audit log** |
| `notifications` | In-app notifications |
| `school_settings` | Semester and institution settings |

Framework tables: `migrations`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`,
`failed_jobs`, `password_reset_tokens`.

### 14.2 Append-only tables

`inventory_transactions` and `activity_logs` are audit surfaces. A migration that deletes
from, truncates, or destructively alters either of them destroys audit history and must
never be written. All schema change to these tables must be additive.

### 14.3 Key enums

| Column | Values |
|---|---|
| `maintenance_reports.status` | `submitted`, `assigned`, `in_progress`, `completed`, `closed`, `cancelled` |
| `maintenance_reports.priority` | `low`, `medium`, `high`, `urgent`, `critical` |
| `damage_reports.status` | `pending`, `under_review`, `repairing`, `repaired`, `replaced`, `closed` |
| `repair_requests.repair_status` | `pending`, `assigned`, `diagnosing`, `repairing`, `waiting_parts`, `completed`, `failed`, `archived` |
| `dispatches.status` | `pending`, `approved`, `released`, `cancelled` |
| `inventory_transactions.transaction_type` | `reserve`, `release`, `deploy`, `return`, `adjustment`, `dispose` |
| `items.status` | `available`, `damaged`, `low_stock`, `out_of_stock`, `maintenance` |
| `users.status` | `active`, `inactive`, `suspended`, `pending` |
| `purchase_receipts.status` | `draft`, `posted` |

---

## 15. Installation and Setup

### 15.1 Requirements

- PHP **8.2** or higher, with extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`,
  `gd`, `zip`
- MySQL **8.0+** or MariaDB **10.4+**
- Composer 2
- Node.js 18+ and npm (build tooling only)
- Apache with `mod_rewrite` enabled
- `mysqldump` on `PATH` (or configured, see §19.1) if you want automated backups

The reference local environment is **XAMPP on Windows**, with the project at
`C:\xampp\htdocs\School_Facility_Maintenance_System`.

### 15.2 Install

```bash
# 1. Get the code
cd C:\xampp\htdocs
git clone <repository-url> School_Facility_Maintenance_System
cd School_Facility_Maintenance_System

# 2. PHP dependencies
composer install                  # local
# composer install --no-dev --optimize-autoloader --classmap-authoritative   # production

# 3. Node dependencies + asset build
npm ci
npm run build

# 4. Environment file
cp .env.example .env
php artisan key:generate
```

### 15.3 Configure `.env`

```dotenv
APP_NAME="PhilCST Facility Maintenance"
APP_ENV=local                  # production on a live host
APP_DEBUG=true                 # MUST be false in production
APP_URL=http://localhost/School_Facility_Maintenance_System

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=school_facility_maintenance
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120

# Seeder credentials — set these before running db:seed
SEED_SUPER_ADMIN_PASSWORD=
SEED_MAINTENANCE_ADMIN_PASSWORD=

# Optional: explicit mysqldump path for db:backup
DB_BACKUP_MYSQLDUMP_PATH=
```

> **Windows / Apache note.** Under `mpm_winnt`, Laravel's `Env::disablePutenv()` behaviour
> matters: environment variables must be read through `env()` / config rather than
> `getenv()`, because `putenv()` is not thread-safe on a threaded MPM. Do not add code
> that relies on `getenv()`.

### 15.4 Create and migrate the database

```bash
# Create the schema
mysql -u root -e "CREATE DATABASE school_facility_maintenance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations
php artisan migrate

# Seed baseline data (departments, settings, initial accounts)
php artisan db:seed
```

Seeded administrator passwords come from `SEED_SUPER_ADMIN_PASSWORD` and
`SEED_MAINTENANCE_ADMIN_PASSWORD`. If unset they fall back to obvious placeholders
(`change-me-super-admin`, `change-me-maint-admin`) — **change them immediately after first
login on any host that matters.**

### 15.5 Writable directories

These must be writable by the web server process:

| Directory | Written by |
|---|---|
| `storage/` and `bootstrap/cache/` | Laravel (framework requirement) |
| `public/frontend/assets/uploads/avatars/` | `UserController` |
| `public/frontend/uploads/completion-proofs/` | `ReportController` |
| `public/frontend/uploads/pm-completion-proofs/` | `PreventiveMaintenanceService` |
| `public/frontend/uploads/damage-reports/` | reserved |
| `storage/app/backups/database/` | `BackupDatabase` command |

The application creates most of these lazily, but the parent `public/frontend/uploads/`
and `public/frontend/assets/uploads/` roots should be pre-created with correct permissions
during deployment.

---

## 16. Running the System

**Local (XAMPP):** start Apache and MySQL from the XAMPP control panel, then visit

```
http://localhost/School_Facility_Maintenance_System/
```

**Local (artisan) — for API/development work:**

```bash
php artisan serve
```

**Asset watch during frontend work:**

```bash
npm run dev
```

**Useful commands:**

```bash
php artisan migrate:status                     # which migrations have run
php artisan route:list                         # all 186 routes
php artisan tinker                             # REPL against the live models
php artisan db:backup                          # manual database backup
php artisan inventory:backfill-stock-status    # recompute derived item status
```

---

## 17. Testing

### 17.1 Running the suite

```bash
php artisan test
```

**Current baseline: 740 tests, 2,811 assertions, 0 failures, 0 errors, 0 skipped**
(~7.5 minutes). Any run reporting failures, errors, or a lower test count than this is a
regression.

Run a subset:

```bash
php artisan test --filter=RoomItemTypeInvariantTest
php artisan test tests/Feature/UserManagementAuthorizationTest.php
```

### 17.2 Test database

`phpunit.xml` forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. **The test suite
never touches the live MySQL database.** Feature tests use `RefreshDatabase` against the
in-memory SQLite schema built from the same migrations.

### 17.3 MySQL-specific suites

Six suites use the `Tests\Support\ConnectsToScratchMySqlDatabase` trait because they
exercise behaviour SQLite cannot represent (MySQL `DATE_FORMAT` / `DATE_SUB` / `CURDATE()`
raw SQL, strict-mode asymmetry, real migration rehearsal):

- `AnalyticsMySqlExecutionTest`
- `LegacyMigrationRehearsalTest`
- `LegacyNonStrictSqlModeAsymmetryTest`
- `LegacyRehearsalFixtureTest`
- `MigrationChainReproducibilityTest`
- `NeedChangeStatusSchemaDriftTest`

These **self-skip** if MySQL is not reachable. A green run with skips is therefore *not* a
full run — the `0 skipped` part of the baseline is the load-bearing figure, and achieving it
requires MySQL to be up. Never accept an empty or zero-byte test output as proof of success.

### 17.4 Reading test output on Windows

PHPUnit emits ANSI escapes and `\r` line endings that Windows terminals render poorly.
Pipe the output:

```bash
php artisan test | cat -v | tr '\r' '\n' | sed 's/\^\[\[[0-9;]*m//g'
```

### 17.5 What is covered

The 68 test files cover: authentication and lockout, role/RBAC enforcement on every route
group, user management and approval, the inventory ledger and both observers, the
room-asset invariant, dispatch approval and release (including duplicate-line aggregation),
Need Change deduction and authorization, damage-report and repair-request lifecycles,
replacement composition, analytics RBAC and MySQL execution, notification behaviour,
migration-chain reproducibility, and legacy-schema rehearsal.

The automated suite is the project's primary correctness gate. It replaced an earlier
reliance on manual `scripts/smoke_*.php` scripts and a 205-case manual test plan.

---

## 18. Deployment

### 18.1 Pre-release checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` generated and **not** shared with any other environment
- [ ] Real database credentials; the DB user has no more privilege than it needs
- [ ] `SEED_*_PASSWORD` values set, or seeding skipped entirely on an existing database
- [ ] `php artisan migrate:status` shows nothing unexpectedly pending
- [ ] `php artisan test` green at the 740-test / 0-skipped baseline
- [ ] `git status` clean; the release commit is tagged
- [ ] A verified database backup exists and has been test-restored

### 18.2 Deploy

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader --classmap-authoritative
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 18.3 Post-deploy verification

1. Log in as each of the three roles; confirm the correct dashboard renders.
2. Submit a maintenance report end to end.
3. Create, approve, and release a dispatch; confirm `items.quantity` moved by exactly the
   released amount and that a matching `inventory_transactions` row exists.
4. Confirm the analytics dashboard loads without error.
5. Confirm an upload (avatar or completion proof) succeeds and is served back.
6. Check `storage/logs/laravel.log` for errors.

### 18.4 Rollback

```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Database rollback is the dangerous part.** Because migrations are additive and
`inventory_transactions` / `activity_logs` are append-only, prefer *rolling forward* with a
corrective migration over `php artisan migrate:rollback`. If a data restore is genuinely
required, restore the dump into a staging database first, verify it, and only then promote.

### 18.5 Cache invalidation when re-testing

`composer.json`'s `scripts.test` runs `php artisan config:clear` first. Mirror that in any
pipeline: clear `config`, `route`, `view`, and `cache` before running tests, then re-cache
afterwards for production traffic.

---

## 19. Maintenance and Troubleshooting

### 19.1 Database backups

`php artisan db:backup [--keep-days=N]` (`app/Console/Commands/BackupDatabase.php`):

- Requires `DB_CONNECTION=mysql`; fails otherwise.
- Runs `mysqldump --single-transaction --quick --routines --triggers`.
- Writes to `storage/app/backups/database/{database}_backup_{Ymd_His}.sql`.
- Deletes local backups older than `--keep-days` (default 14) after a successful run.
- Output is already git-ignored by Laravel's built-in `storage/app/.gitignore`.

`mysqldump` is located in this order: `DB_BACKUP_MYSQLDUMP_PATH` →
`C:\xampp\mysql\bin\mysqldump.exe` → bare `mysqldump` on `PATH`.

### 19.2 Scheduled tasks

`routes/console.php` schedules exactly one task:

```php
Schedule::command('db:backup')->dailyAt('01:00')->withoutOverlapping();
```

> This only runs if the host invokes Laravel's scheduler every minute. Confirm a cron entry
> (Linux) or Task Scheduler job (Windows) exists on the production host:
> ```
> * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
> ```
> There is no automated verification that backups are actually running — check for recent
> files in `storage/app/backups/database/` periodically.

### 19.3 Restore

There is **no** `db:restore` command. Restoring is manual:

```bash
mysql -h <host> -P <port> -u <user> -p <database> < backup_file.sql
```

Always restore into a staging database first and verify row counts and application
behaviour before promoting. No automated post-restore verification exists.

### 19.4 Common problems

| Symptom | Likely cause and fix |
|---|---|
| 419 Page Expired | Session expired, or a non-`/api` POST missing its CSRF token |
| 401 on every API call | Session not bridged — check `SyncLegacyPhpSession` is registered in `bootstrap/app.php` |
| 403 for a user who should have access | Role string not normalized. Check the value in `users.role` against §6.1 |
| Uploads fail silently | Target directory (§15.5) missing or not writable by the web server user |
| Item quantity looks wrong | Do **not** correct it by hand. Inspect `inventory_transactions` for that `item_id` — the ledger is the truth. Use `php artisan inventory:backfill-stock-status` to recompute derived status. |
| Analytics endpoints return 500 | Usually a MySQL-only SQL function running on a non-MySQL connection |
| `db:backup` fails | `mysqldump` not found — set `DB_BACKUP_MYSQLDUMP_PATH` |
| Tests finish suspiciously fast, or report skips | MySQL is down; the six scratch-DB suites self-skipped. Start MySQL and re-run. |

### 19.5 Invariants to protect

Anyone extending this system should treat these as non-negotiable:

1. Never assign `items.quantity` or `items.reserved_quantity` outside the two observers.
2. Never compare a role string without `RoleNormalizerService`.
3. Never read-then-write without `DB::transaction()` + `lockForUpdate()`.
4. Never write a destructive migration against `inventory_transactions` or `activity_logs`.
5. Never put business logic in a controller or a view.
6. Never add a second authorization mechanism alongside the service-layer checks.
7. Never bypass `ReportAuthorizationService::canModifyReport()` for report modification.

---

## 20. Current Project Status

**The system is feature-complete for its intended scope and passes its full automated test
suite: 740 tests, 2,811 assertions, 0 failures, 0 skipped.**

### 20.1 Completed

- Maintenance report lifecycle, including structured location validation and completion proof
- Ledger-based inventory with the single-writer principle enforced and tested
- Dispatch approve/release with segregation of duties and correct duplicate-line handling
- Damage report and repair request lifecycles, with replacement composition
- Need Change inventory deduction — the historical no-op gap, closed 2026-07-21
- Role-alias normalization consolidated into `RoleNormalizerService`, previously duplicated
  across ~12 call sites
- `InventoryTransactionObserver::deleted()` row locking — previously a race-condition gap
- Seeder credentials moved out of source and into environment variables
- Route-level RBAC on the analytics group — previously authenticated-only, so an
  unapproved account could reach all 14 analytics endpoints
- Inventory quantity-edit bypass in `InventoryStockController::update()` closed
- `ReportService` extracted from `ReportController`, restoring the controller → service boundary
- Preventive maintenance, analytics, notifications, activity log, automated backup

### 20.2 Known open items

These are documented deliberately rather than silently patched:

1. **Pending migrations.** Three migrations exist in `database/migrations/` but have not
   been applied to the local development database:
   - `2026_08_15_000100_add_username_to_users_table`
   - `2026_09_08_000100_add_out_of_band_user_and_report_columns`
   - `2026_09_08_000200_align_need_change_columns_with_application_behavior`

   Run `php artisan migrate:status` to confirm and `php artisan migrate` to apply. They are
   additive.

2. **`need_change_status` enum naming.** The rejection path writes `'rejected'`, which is
   not among the `pending` / `approved` / `deducted` / `failed` values the original
   migration described. The column is `varchar(50)`, so nothing breaks; it is a naming
   inconsistency, not a defect. The
   `..._align_need_change_columns_with_application_behavior` migration addresses it.

3. **`PurchaseReceiptPostingService` activity logging.** It writes to `activity_logs` via a
   direct `DB::table('activity_logs')->insert()` instead of going through
   `ActivityLogService`, inconsistent with `DispatchService`. A consistency issue; the log
   entry itself is correct.

4. **Stale CSRF exemption.** `bootstrap/app.php` still exempts `backend/api/*` from CSRF,
   but the legacy `public/backend/api/*.php` files it referenced have been removed. The
   exemption currently matches nothing and should be deleted.

5. **Legacy frontend surface.** `public/frontend/` remains procedural PHP. The long-term
   intent is to migrate it to Laravel views and retire the `SyncLegacyPhpSession` bridge.
   This is incremental, not urgent — the bridge is stable, though it has no dedicated test
   coverage of its own.

6. **No `FormRequest` classes.** Validation is inline in controllers. Not a correctness
   problem; a consistency improvement candidate.

### 20.3 Deliberately not adopted

- **Laravel Policies / Gates / permission packages.** Authorization is service-layer by
  design. Adopting a second mechanism would create two places to look for the same answer.
- **A first-class `Replacement` entity.** Replacement remains composed from `deploy` +
  `dispose` ledger transactions, with the outcome recorded on the repair request. Promoting
  it is only justified if operational reporting demands it.

---

## 21. Documentation History

This README replaces a large set of previously separate Markdown files. Understanding what
happened to them matters if you encounter a reference to one.

### 21.1 What was consolidated

| Former file | Where its content now lives |
|---|---|
| `ARCHITECTURE.md` | §5 System Architecture, §9 Inventory, §13 Security, §19.5 Invariants |
| `BUSINESS_RULES.md` | §6 Roles, §7–§12 Workflows, §14.3 Enums |
| `DEPLOYMENT.md` | §15 Installation, §18 Deployment, §19 Maintenance |
| `TEST_EXECUTION_GUIDE.md` | §17 Testing |
| `CHANGELOG.md` | §20.1 Completed — outcomes only, not the chronology |
| `ROADMAP.md` | §20.2 Known open items, §20.3 Deliberately not adopted |
| `AI_INSTRUCTIONS.md` | §5.5 Coding standards, §19.5 Invariants |
| `DESIGN_SYSTEM.md` | Superseded — the design tokens now live in the stylesheets themselves |
| `PROJECT_BASELINE.md` | Superseded — a 2026-07-21 snapshot, since overtaken on every figure |
| `docs/FINAL_ARCHITECTURE_SUMMARY.md` | Superseded — described files that no longer exist |
| `public/backend/README.md` | Superseded — described a legacy API layer that has been removed |
| `public/frontend/guide1/*` (11 files) | Superseded — delivery docs for a sidebar that is already integrated |
| QA package — `END_TO_END_TEST_PLAN.md`, `QA_EXECUTION_SHEET.md`, `BUG_REPORT_TEMPLATE.md`, `QA_SUMMARY_TEMPLATE.md` | Superseded by the automated suite (§17); the execution sheet was never filled in |
| 114 `TASK_*.md` audit and implementation reports | Their *outcomes* are in §20.1 and §20.2; the narratives are archived |
| ~60 other report, sprint, and fix files | Same |

### 21.2 References in code comments

A number of source files carry comments citing a report by filename — for example
`app/Observers/InventoryTransactionObserver.php` cites a Task 36 design analysis, and
`routes/web.php`, `.htaccess`, and `tests/Unit/DevGuardIntegrityTest.php` cite others.

**These are documentation trails, not functional dependencies.** Nothing at runtime reads a
Markdown file; no test asserts one exists; no build step consumes one. The comments were
left untouched because editing source files was out of scope for the consolidation. If you
follow one of those citations and the file is not present, the reasoning it recorded has
been folded into the section of this README covering that subsystem.

### 21.3 The archive

Nothing was deleted without a backup. Every removed Markdown file was copied, byte for
byte, into a timestamped archive directory under `db_backups/` before removal.
`db_backups/` is git-ignored, so the archive is local to this machine — to move it, copy
the directory across. The 25 files that were committed can also be recovered from git
history:

```bash
git log --diff-filter=D --name-only -- '*.md'
git show <commit>:<path>
```

---

*This document describes the system as implemented. Where documentation and code disagree,
the code is correct and this document is the defect — fix it here.*
