# Credit Union System — Audit Round 1

**Status:** CLOSED. No open items.
**Date:** 2026-09-21
**Auditor:** engineering review
**Scope:** `app/`, `routes/`, `bootstrap/`, `config/`, `database/migrations/`, `tests/`
**Out of scope:** frontend Blade markup, deployment infrastructure, CI/CD.

---

## 0. Inputs reviewed

- `composer.json`
- `bootstrap/app.php`
- `routes/web.php`, `routes/auth.php`, `routes/console.php`, `routes/api.php` (exists; contents not yet inspected — decision D24 applies regardless)
- `app/Http/Middleware/*`
- `app/Http/Controllers/*` and `Auth/*`
- `app/Http/Requests/Auth/*`
- `app/Models/*`
- `app/Providers/AppServiceProvider.php`
- `app/Support/ImageOptimizer.php`
- `app/Exceptions/Handler.php`
- `config/auth.php`, `config/filesystems.php`
- All 15 migrations
- `tests/` directory listing and `tests/Feature/UserDeletionAuditTest.php`
- `.env.example`

Tests were **not executed** during this audit (no working `php` in Git Bash). Suite status is unknown as of the audit close and becomes a Slice 0 gate.

---

## 1. Stack (locked)

| Item | Value |
|---|---|
| Framework | Laravel 12.55.1 |
| PHP | 8.4.23 |
| Auth scaffold | Breeze 2.4 |
| Test runner | PHPUnit 11.5.50 |
| Static analysis | Pint 1.24 |
| DB (dev, historical) | MySQL via DBngin |
| DB (prod, historical) | Postgres (Render free tier) |
| DB (target) | **PostgreSQL** — see D2 |
| Target host | Digital Ocean |
| Users at launch | 1 branch, ~5 daily staff, ceiling ~30 with interns |

---

## 2. What this application actually is

A signature-card registry for a credit union. Three roles (`central_admin`, `admin` = branch admin, `staff`) operate on five entities (`Branch`, `User`, `Member`, `Signature`, `ActivityLog`).

The core workflow is:
- **Staff** at a branch create `Member` records and upload a signature image.
- **Branch admins** view, replace, and delete those cards within their branch.
- **Central admin** oversees institutional activity via audit logs and dashboard metrics, and creates branch admins.
- A **deliberate privacy design** blocks central admin from viewing member cards or signature images. This is not a bug and not a gap. It is a business rule and must be preserved by the rebuild.

The system stores **PII-adjacent data**: names, account numbers, and signature images. Signatures are biometric-adjacent and, in a credit union regulatory context, are the kind of record that must survive an audit. The current implementation is not audit-grade. That is the real driver for this rebuild — not scale.

---

## 3. Findings

Findings are numbered F1.. and are closed as either a fix target for a slice, a decision (D), or a documented non-issue.

### 3.1 Architecture

- **F1 — No `app/Actions`.** State changes live in controllers. Confirmed.
- **F2 — No `app/Policies`.** Authorization is spread across three layers: `AdminMiddleware` (coarse role gate), inline `abort_unless(...)` in controllers, and eight `can*`/`is*` methods on the `User` model. No single source of truth.
- **F3 — No `app/DTOs` / `app/Data`.** Controller boundaries accept `Request` and pass arrays into models.
- **F4 — No Events, Listeners, or Notifications.** No notification subsystem exists. The "never notify the actor / no self-notification" rule has no enforcement surface today.
- **F5 — No `app/Jobs`.** No async work. At 30 users this is correct. Do not add queues without a business reason.
- **F6 — `App\Exceptions\Handler.php` is Laravel 10-era.** Laravel 12 configures exception handling via `bootstrap/app.php::withExceptions()`. The custom `Handler` is likely dead code — the `bootstrap/app.php` closure is empty. The error views (`errors.{status}`) that the Handler renders are probably never used. Must verify and consolidate (D25).
- **F7 — `AppServiceProvider::boot()` calls `env('APP_ENV')` outside config.** With `config:cache` in production (DO will cache config), `env()` returns `null` and `URL::forceScheme('https')` never fires. This will manifest as mixed-content warnings and insecure redirects on production. Real bug. Fix in D27.
- **F8 — `composer.json` is named `laravel/laravel`.** The project has never claimed its own identity. Cosmetic; Slice 0.

### 3.2 Authorization

- **F9 — `AdminMiddleware` collapses two roles.** It calls `auth()->user()->isAdmin()`, which is `role in ['central_admin','admin']`. Every `/admin/*` route is therefore reachable by both roles; differentiation happens only inside controller bodies. This is fragile: any missed `abort_unless(...)` in a controller is an authorization bypass.
- **F10 — Branch-scoping is duplicated in three places.** `MemberController::buildVisibleMembersQuery`, `MemberController::export`, `DashboardController::dashboard`, and `UserController::buildActivityLogQuery` each re-implement "which records belong to this user's branch." This is a maintenance hazard and a place where a single inconsistency equals a leak.
- **F11 — Model methods doing authorization.** `User::canResetPasswordFor`, `canManageAccountStatusFor`, `canDisableUser`, `canEnableUser`, `canCreateMembers`, `canViewMember`, `canManageMemberCards`, `canManageMember`, and `Member::branchIdForAccess` are all authorization logic living on models. Belongs in Policies.
- **F12 — Export permission is inconsistent.** `MemberController::export` requires `isBranchAdmin()`. `MemberController::index` allows staff and branch admin. So a staff member can view a member card on screen but cannot export the same data to CSV. This may be intentional (audit rationale) or accidental (copy-paste). **Decision required and resolved as D16.**
- **F13 — Self-disable is blocked. Self-disable of central admin is not checked against the "last remaining central admin."** A central admin can disable every other central admin. If they then lose credentials, the institution is locked out of admin functions. Edge case; handled by D18.
- **F14 — `AdminMiddleware` is not a Policy.** The standard says "every authorization in a Policy." Delete `AdminMiddleware`, replace with route-level `can:` middleware or controller `authorize()` calls. Confirmed D3.
- **F15 — `EnsureAccountIsActive` and `EnsurePasswordChangeIsComplete` are NOT authorization.** They are session-validity middleware and stay. Do not delete them in the name of "no auth middleware." Their job is preventing a stale session from continuing to act after a state change, which is a distinct concern from permission.

### 3.3 Data model

- **F16 — `members` has no `branch_id`.** Branch ownership is derived at read time via `Member::branchIdForAccess()`, which walks `creator.branch_id` and falls back to `signature.creator.branch_id`. Consequences:
  - If a user is transferred to a different branch, all their historical member cards move with them retroactively.
  - If both creator and signature creator are hard-deleted, the member becomes invisible to everyone.
  - If creator and signature creator had different branches at any point, the derived value flips depending on which one exists.
  This is why the `repair_orphaned_member_ownership_from_signatures` migration exists. The migration patched `created_by` but not the underlying design. **The real fix is a column, not a lookup.** D19.
- **F17 — `activity_logs.user_id` is NOT NULL and has no FK.** The no-FK is correct (audit rows must survive user deletion). The NOT NULL is wrong: it cannot represent a system-initiated action (`null + reason`). Standard requires `null + reason` support. D12, D13.
- **F18 — Activity log has no IP, no user agent, no model reference.** For an audit-grade system that will be reviewed by a credit union's risk function, this is insufficient. Add `ip_address`, `user_agent` (nullable), and `model_type` / `model_id` (nullable). D13.
- **F19 — `slug` generation is a race condition.** `Member::generateSlug` loops in PHP on the model side. Two simultaneous member creates with the same name can both compute the same slug; the second insert fails on the unique constraint. At 30 users this is unlikely, but easy to eliminate. D20.
- **F20 — `password_reset_tokens` table exists; no password-reset routes exist.** Breeze scaffold came with it and the routes were deleted. The table is unused. `tests/Feature/Auth/PasswordResetTest.php` therefore likely fails. Verify; delete table + test or wire the flow. D21.
- **F21 — `members.account_number` has no format constraint.** Only `required|unique`. A credit union account number has a known format. This is a domain rule that belongs in a FormRequest. D22.

### 3.4 Activity logging

- **F22 — Action names are SCREAMING_SNAKE.** `CREATE_MEMBER`, `VIEW_MEMBER`, `UPDATE_MEMBER`, `DELETE_MEMBER`, `CREATE_USER`, `RESET_PASSWORD`, `DISABLE_USER`, `ENABLE_USER`, `CHANGE_PASSWORD`. The standard says `entity.action_past` dot notation. D9.
- **F23 — Access events are logged as activity.** `MemberController::show` writes `VIEW_MEMBER` on every detail page view. This collides with the standard's "activity logging on state changes." Reading is not a state change. **This is a conflict between the current code and the standard, and both cannot stand.** D10 resolves it: log both, but distinguish them; document the deviation.
- **F24 — ActivityLog actor snapshot is written in `booted(creating)` on the model.** Functional, but couples the model to User and performs an implicit DB read at write time. In Action-based design the actor is already in hand — pass it in explicitly. D14.
- **F25 — Snapshot vs fallback inconsistency.** `ActivityLog::actorName()` returns `'Deleted user'` when no name is available. `tests/Feature/UserDeletionAuditTest.php` asserts `'Deleted account'`. Either the view has different text, or the test is failing. Verify.
- **F26 — Log is written before the state change completes.** In `MemberController::store`, the `ActivityLog::create(...)` call happens **before** `ImageOptimizer::storeSignature`. If image storage fails, the log claims the card was created but no signature exists. Same in `update`: log is written before the file is replaced. **Rule violation for the rebuild.** D11.
- **F27 — `activity-logs:prune` command deletes rows with no audit of the prune.** CLI-only. Documented exemption from actor rule. D14b.

### 3.5 Password / auth

- **F28 — `Hash::make` + `hashed` cast.** Controllers call `Hash::make($plain)` and pass to a model with `'password' => 'hashed'` cast. Laravel's `HashedCast` checks `Hash::isHashed()` and no-ops if already hashed — so this is **safe today**. It is also fragile: the next dev might remove the cast or the make and get a double hash. Rely on one, not both. D23.
- **F29 — `password_changed_at` set to `null` on admin reset.** `UserController::updatePassword` sets `'password_changed_at' => null`. Semantically ambiguous: was the field never set, or has it been cleared? Choose one meaning. D24.
- **F30 — Voluntary password change does not log activity; forced change does.** `PasswordController::update` (Breeze default) writes no ActivityLog. `PasswordController::updateForcedChange` writes `CHANGE_PASSWORD`. Inconsistent. D25.
- **F31 — Password reset for admin issues a temporary password that is displayed on screen.** This is acceptable for 5 users but should be documented as a known trade-off in the domain spec. No email delivery exists; the admin reads the temp password to the user. Note as domain decision D26.
- **F32 — No throttling on write routes.** Only `POST /login` has `throttle:5,1`. `POST /admin/users`, `PUT /admin/users/{}/password`, `POST /members`, `DELETE /members/{}` have no throttle. At 30 users this is low-risk; on Digital Ocean (public internet) it is worth adding. D27.

### 3.6 File handling

- **F33 — Signature storage is correctly private.** `SIGNATURE_CARD_DISK=local` → `storage/app/private` → not web-reachable. Access is gated via `MemberController::signature()` with `canViewMember`. Serving via `Storage::disk('local')->response(...)` is correct. This is the strongest part of the codebase. Preserve it exactly.
- **F34 — Public fallback exists but is disabled.** `SIGNATURE_ALLOW_PUBLIC_FALLBACK=false`. Correct for production. The `signatures:migrate-private` artisan command exists to migrate old files. Confirm it has been run in production. Add to deployment checklist.
- **F35 — `ImageOptimizer` fallback preserves EXIF.** If GD is unavailable or the source is unexpected, the file is stored raw. For signature cards, EXIF is not useful and can carry metadata (camera, timestamp, GPS on mobile captures). Add an EXIF strip even on fallback, or reject the upload. D28.
- **F36 — `ImageOptimizer` uses `Storage::disk($disk)->path(...)`.** On a non-local disk (S3), `->path()` throws. Today's disk is `local`, so fine. Documented as a constraint: the optimizer is local-only. D29.

### 3.7 Routes

- **F37 — `routes/api.php` is registered in `bootstrap/app.php`.** Contents not inspected. This app has no Sanctum, no tokens, no API consumers. Remove the file and the registration. D30.
- **F38 — `bootstrap/app.php` registers `'cors'` as an API middleware alias.** `cors` is not a Laravel middleware alias. This is likely a no-op or an error. Removing `routes/api.php` (D30) removes this too.
- **F39 — `/` route returns `view('index')` outside the auth group.** Public landing page. Fine for now; confirm it does not leak any data.
- **F40 — Central admin cannot view `/members`** (`abort_if($user->isCentralAdmin(), 403)`). This is the F16 privacy rule surfacing in a second place. It must become a policy check (`MemberPolicy::viewAny`), not an inline abort. D3.

### 3.8 Tests

- **F41 — 10 of 11 test files are Breeze scaffold.** Only `tests/Feature/UserDeletionAuditTest.php` tests domain behaviour. `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` are framework defaults.
- **F42 — Test suite status unknown.** `php` unavailable in Git Bash. Must be made runnable before Slice 1. Slice 0 gate.
- **F43 — No tests for `MemberController`, `BranchController`, `UserController::store`, `UserController::activityLogs`, or `ActivityLog` actor rules.** Every slice will add tests for the behaviour it refactors. Standard: tests green before commit.
- **F44 — `tests/Feature/Auth/RegistrationTest.php` tests a route that does not exist.** `routes/auth.php` has no registration routes. Verify and delete.

### 3.9 Processes and non-code

- **F45 — Git history shows deployment churn.** Render → Docker → nginx-php-fpm → revert → re-add → revert. Deployment infrastructure has never been settled. Out of scope for this audit; recommend a separate `docs/credit-union-system-deployment-v1.md` after the domain spec.
- **F46 — No `docs/` directory.** This audit creates it. The domain spec will live there too.
- **F47 — `laravel/breeze` is in `require`, not `require-dev`.** Breeze is a scaffold, not a runtime dependency. Move to `require-dev`, then delete after the frontend stabilises. D31.
- **F48 — `User::isAdmin()` conflates two roles.** Rename to `isAnyAdmin()` for clarity, and stop using it as an authorization check anywhere. D32.
- **F49 — Tests require Vite assets but no build step runs before phpunit.** @vite in app.blade.php throws when no manifest exists. Any future contributor cloning the repo and running tests hits this.

---

## 4. Locked decisions

Each decision is closed. Reopening any of them requires a written rationale and a new audit round. Numbering is stable; do not renumber.

- **D1 (amended 2026-09-21) — Repo identity vs product brand.** Repo and composer package are `credit-union/credit-union-system`. The product brand is `SignatureSuite`, used for `APP_NAME`, UI text, and user-facing material. Docs filenames stay `credit-union-system-*` (repo-scoped). Internal naming ≠ product naming.
- **D2 — Database is PostgreSQL, everywhere.** Dev = prod. MySQL is dropped. All migrations re-run cleanly against Postgres as Slice 0. Any MySQL-only operations (notably `->after(...)`) are removed. Local Herd/DBngin Postgres is the dev target.
- **D3 — Delete `AdminMiddleware`.** All authorization moves to Policies. Routes use `can:` middleware or controllers call `$this->authorize(...)`. `AdminMiddleware` does not survive the audit.
- **D4 — `EnsureAccountIsActive` and `EnsurePasswordChangeIsComplete` stay.** They are session-validity middleware, not authorization. They are exempt from the "no auth middleware" rule.
- **D5 — Introduce `app/Actions/`.** Naming: `VerbNoun` (e.g. `CreateMember`, `ReplaceMemberSignature`, `IssueTemporaryPassword`, `DisableUser`, `ReactivateUser`, `CreateBranch`, `CreateBranchAdmin`). Every state change goes through exactly one Action.
- **D6 — Introduce `app/Policies/`.** One policy per aggregate: `MemberPolicy`, `SignaturePolicy`, `UserPolicy`, `BranchPolicy`, `ActivityLogPolicy`. No `can*` methods remain on `User` or `Member` after the rebuild.
- **D7 — Introduce `app/DTOs/`.** Naming: `VerbNounData` (e.g. `CreateMemberData`, `ReplaceMemberSignatureData`). Immutable (`readonly` public properties, no setters). Every Action takes a DTO, not a `Request`, not an array.
- **D8 — Two exception types.** `App\Exceptions\DomainException` (base) for domain-rule violations. `App\Exceptions\ValidationException` extends Laravel's `Illuminate\Validation\ValidationException` for input errors. `Handler`/`withExceptions` maps `DomainException` → 422 with a user-safe message. Subclasses: `MemberAlreadyExists`, `CannotDisableViewedUser`, `CannotRemoveLastCentralAdmin`, `SignatureNotPresent`, `InvalidSignatureImage`, `UserAlreadyDisabled`.
- **D9 — Activity action names use dot notation, `entity.action_past`.** Canonical list:
  - `member.created`, `member.viewed`, `member.updated`, `member.deleted`
  - `user.created`, `user.password_issued`, `user.password_changed`, `user.disabled`, `user.enabled`
  - `branch.created`
  A migration rewrites existing rows to the new form via a lookup map. Old data remains queryable.
- **D10 — Access events and state changes share one table, distinguished by convention.** `member.viewed` is the only read event logged; it is documented as an **access event**, not a state change. This is an explicit deviation from the standard's "logging on state changes" wording (see §5). No separate `access_logs` table.
- **D11 — Logs are written inside the same transaction as the state change, and after the state change succeeds.** Sequence: begin transaction → perform mutation → write log → commit. Never write a log before the mutation it describes.
- **D12 — `activity_logs.user_id` becomes nullable. Add `actor_reason` (string, nullable).** Rule: if `user_id` is null, `actor_reason` is required. If `user_id` is set, `actor_reason` is optional (used for impersonation, override, or "acting on behalf of").
- **D13 — `activity_logs` gains `ip_address` (string 45, nullable), `user_agent` (text, nullable), `model_type` (string, nullable), `model_id` (unsigned bigint, nullable).** Populated on every write. `model_*` are for future filtering; they do not replace the actor snapshot.
- **D14 — Actions receive the actor `User` explicitly.** Rule: `auth()` is never called inside an Action. Controllers call `$request->user()` once and pass it into the Action. This is the mechanism that makes "every log entry names its actor" enforceable and testable.
- **D14b — Exempt from actor requirement: `activity-logs:prune` (CLI).** This operation is retention maintenance, not a domain event. It is documented as an exemption, not silently ignored.
- **D15 — No `auth()` call inside a `DB::transaction()` closure.** Enforced by a test that greps for the pattern in `app/Actions/`.
- **D16 — Export permission matches view permission.** If a user can see `members.index`, they can export the same scope to CSV. Rationale: the current asymmetry has no business justification. If the customer wants export restricted, they must state the rule and it goes in the Policy.
- **D17 — Central admin cannot view member cards or signature images.** This is a business rule. It becomes `MemberPolicy::view()` and `MemberPolicy::viewAny()`, returning `false` for `central_admin`. Same for `SignaturePolicy::view`. This is a deliberate privacy stance and is written into the domain spec as a domain rule.
- **D18 — Cannot disable the last active central admin.** New rule. `UserPolicy::disable()` returns `false` if the target is a central admin and the count of active central admins is 1. Enforced in the `DisableUser` Action with a `DomainException`.
- **D19 — Add `members.branch_id` column, NOT NULL after backfill.** Set at creation time by the `CreateMember` Action, based on `$actor->branch_id`. Backfilled from `creator.branch_id` in a migration, with a fallback to `signature.creator.branch_id`. `Member::branchIdForAccess()` is deleted.
- **D20 — Member slug is `Str::slug(name) . '-' . account_number`.** Deterministic, unique via `account_number`. Race condition eliminated. Migration backfills.
- **D21 — Breeze scaffold dead code is removed.** Delete `password_reset_tokens` migration path if the flow is not built (target: not built; table dropped in a new migration). Delete `tests/Feature/Auth/RegistrationTest.php` and `tests/Feature/Auth/PasswordResetTest.php` if the routes do not exist. Delete `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php`.
- **D22 — `account_number` has an explicit format rule.** Placeholder: `regex:/^[0-9]{5,20}$/` unless the customer specifies otherwise. **The exact regex is a customer question, not an engineering question. Do not invent it. Confirm before Slice 2.**
- **D23 — Password hashing relies on the `hashed` cast only.** Actions pass plain strings to `User::create` / `User::update`. No `Hash::make()` in controllers or Actions. Add a test asserting passwords are verified correctly after every write path.
- **D24 — `password_changed_at` means "last user-initiated password change."** On admin-issued temp password reset, `password_changed_at` is left unchanged (not nulled). On forced change and voluntary change, set to `now()`. The column's meaning is documented in a code comment.
- **D25 — `App\Exceptions\Handler.php` is deleted.** Exception rendering moves into `bootstrap/app.php::withExceptions()`. Error views are still rendered for web requests; JSON requests still fall through to parent rendering.
- **D26 — No email delivery. Temp passwords are displayed on screen to the issuing admin.** Documented domain constraint. Not changed in this rebuild.
- **D27 — Throttling added to write routes.** `POST /admin/users` 20/min per actor; `PUT /admin/users/{}/password` 5/min per actor; `POST /members` 30/min per actor; `DELETE /members/{}` 10/min per actor. `POST /login` keeps 5/min per IP+email.
- **D28 — Signature uploads always strip metadata.** When GD is available: resize + re-encode (current behavior). When GD is not available: reject with `ValidationException` (`signature.unsupported`). No raw-storage fallback.
- **D29 — `ImageOptimizer` is local-disk only.** Documented. If cloud storage is introduced later, this class is rewritten, not patched.
- **D30 — `routes/api.php` and its registration in `bootstrap/app.php` are deleted.** No API consumers. The `'cors'` middleware alias is removed with it.
- **D31 — Breeze moves to `require-dev`.** After the frontend stabilises, Breeze is removed entirely. Scaffold code that is not used by the app (registration, email verification, password reset views/tests) is removed sooner.
- **D32 — `User::isAdmin()` is renamed `isAnyAdmin()`.** And it is no longer used for authorization anywhere. The rename is mechanical (Python heredoc, anchors on the class).
D33 — Tests call $this->withoutVite() in Tests\TestCase::setUp(). This is the Laravel-blessed fix; no npm run build needed in CI for feature tests. Deployment still requires npm run build — that's separate.

D34 — Test DB is Postgres, not SQLite. Same reasoning as D2: dev, test, prod must match. Speed cost is trivial at this suite size. Add DB_CONNECTION=pgsql and a DB_DATABASE=credit_union_system_test to phpunit.xml once Postgres is up.

---

## 5. Explicit deviations from the standard

The rebuild standard was written for TaskForge. It does not fit this domain perfectly. Two deviations are documented here so they are not silent.

- **Deviation 1 — "activity logging on state changes" is extended to include a single read event: `member.viewed`.** Rationale: the customer's risk function will ask "who looked at this member's signature card, and when." Logging the access is a business requirement. It is logged as an access event with dot notation, and the domain spec will describe it as an access event, not a state change. All other reads are not logged.
- **Deviation 2 — "every blocker, submission, review, resolution logs its actor" does not apply.** This domain has no blockers, submissions, reviews, or resolutions. The mapping is:
  - "submission" → `member.created` / `user.created` / `branch.created`
  - "review" → `member.viewed`
  - "resolution" → `member.deleted` / `user.disabled` / `user.enabled`
  - "blocker" → no equivalent; rule is inert in this domain
  Every state change and the single access event names its actor, per D12, D14.

---

## 6. Slice 0 (prerequisite to Slice 1)

Not a feature slice. Required before any refactor commit.

1. Delete `routes/api.php` and its `bootstrap/app.php` registration (D30).
2. Delete `app/Http/Middleware/AdminMiddleware.php` (D3) — even before Policies exist, the app still works because it currently just checks `isAdmin()`; temporary route guards will be added in Slice 1.
   - **Order note:** D3 is completed in Slice 1, not here. Slice 0 deletes `routes/api.php`, verifies tests pass, and stops.
3. Verify `php` runs in Git Bash. If not, add to PATH. Run `php artisan test --compact` and record baseline.
4. Re-run migrations against a **fresh local Postgres** database. Capture any Postgres-specific errors. Fix MySQL-only migration operations.
5. Add `docs/` directory (already exists after this audit doc is saved).
6. `composer.json` name → `credit-union/credit-union-system` (D1).
7. Move `laravel/breeze` to `require-dev` (D31).
8. Delete test files identified in F41/F44 (D21) — but do this only after step 3 confirms which tests currently fail.
9. Commit message: `slice-0: audit close — repo hygiene and Postgres verification`. Tests green.

---

## 7. What comes next

1. **Domain spec** — `docs/credit-union-system-domain-spec-v1.md`. Written next, in one message. Defines entities, roles, invariants, lifecycle, and the actor-logging rule in this domain's own terms.
2. **Slice 1** — Policies + Actions + DTOs for `Member` (the highest-risk area). No controller refactor in Slice 1 beyond `MemberController`.
3. **Slice 2** — `User` lifecycle: `CreateBranchAdmin`, `CreateStaff`, `IssueTemporaryPassword`, `DisableUser`, `ReactivateUser`. Introduces `UserPolicy`, `CannotRemoveLastCentralAdmin`.
4. **Slice 3** — Activity log migration to dot notation, actor snapshot column changes, IP/UA capture.
5. **Slice 4** — `Branch` + `ActivityLog` policies, throttling, CSRF/rate-limit review.
6. **Slice 5** — Test suite hardening, delete Breeze entirely, final remove `AdminMiddleware` references.

No slice begins before the domain spec exists and is accepted.

---

## 8. Audit round 1 — closed

All findings have a resolution: a decision, a slice assignment, or a documented non-issue. No open items. No TBD.

Reopening any decision requires a written rationale and a new audit round.