# Credit Union System — Domain Spec v1

**Status:** DRAFT v1 — awaiting customer answers to §9
**Date:** 2026-09-21
**Depends on:** `credit-union-system-audit-round-1.md` (all decisions D1–D34)

---

## 1. What this system is

A signature-card registry for a credit union. Staff at a branch create a Member record and upload an image of the member's physical signature card. Branch admins review and maintain those cards within their branch. A central admin oversees operations across branches via dashboards and an activity log, but — by deliberate design — cannot open member cards or view signature images.

The system stores PII-adjacent data: names, account numbers, signature images. Signature images are biometric-adjacent and, in a credit union context, are audit records. The rebuild's purpose is to make the system defensible to a risk function, not to make it faster.

## 2. Scope

### In scope for v1
- One credit union, multiple branches
- Signature card storage, viewing, replacement, deletion
- User lifecycle: create, password issue, disable, enable
- Activity audit trail for every state change, plus member card access
- CSV export of member lists and activity logs

### Out of scope for v1 (explicitly)
- Notifications of any kind (email, SMS, in-app)
- Multi-tenant UI (schema is tenant-ready; no tenant switching)
- Editing a member's account number or name
- Member-facing portal
- Integration with any core banking system
- Password reset by the user (only admin-issued temporary passwords)
- User deletion (users are disabled, never deleted)

## 3. Entities

### 3.1 Tenant
- Fields: id, name, timestamps
- v1: exactly one row exists. Populated by seeder.
- No UI to create or switch tenants.
- Every other entity belongs to a tenant.

### 3.2 Branch
- Belongs to: Tenant
- Has many: Users, Members
- Fields: id, tenant_id, name, location (nullable), timestamps
- Invariant: `name` unique within tenant

### 3.3 User
- Belongs to: Tenant, Branch (nullable — central_admin has none)
- Has many: createdUsers (as creator), membersCreated (as creator)
- Fields: id, tenant_id, name, email, password, role, status, must_change_password, password_changed_at, last_login_at, status_changed_at, status_changed_by, status_reason, branch_id, created_by, timestamps
- `role` ∈ {central_admin, admin, staff}
- `status` ∈ {active, disabled}
- Invariants:
  - `email` unique within tenant
  - role=staff ⇒ `branch_id` not null
  - role=admin ⇒ `branch_id` not null
  - role=central_admin ⇒ `branch_id` is null

### 3.4 Member
- Belongs to: Tenant, Branch, User (creator)
- Has one: Signature (0 or 1)
- Fields: id, tenant_id, branch_id, account_number, name, phone (nullable), slug, created_by, timestamps
- Invariants:
  - `account_number` matches `^[0-9]{6,15}$`
  - `account_number` unique within tenant
  - `slug` unique within tenant, format `Str::slug(name) . '-' . account_number`
  - `branch_id` set at creation, never changed

### 3.5 Signature
- Belongs to: Member, User (creator)
- Fields: id, member_id, image_path, created_by, timestamps
- Invariants:
  - Cannot exist without a Member (FK cascade)
  - At most one per Member (enforced by Action, not by DB)

### 3.6 ActivityLog
- Belongs to: Tenant, User (actor, nullable)
- Fields: id, tenant_id, user_id (nullable), actor_reason (nullable), user_name, user_email, user_role, user_branch_id, user_branch_name, user_created_by, action, description, ip_address, user_agent, model_type, model_id, timestamps
- Invariants:
  - If `user_id` is null, `actor_reason` must be non-null
  - Rows are inserted, never updated by domain code
  - Rows are never deleted by domain code (CLI prune is exempt, documented)

## 4. Roles and capabilities

| Capability | central_admin | branch admin | staff |
|---|---|---|---|
| Create branch | ✓ | – | – |
| Create branch admin | ✓ | – | – |
| Create staff | – | ✓ (own branch) | – |
| Reset branch admin password | ✓ | – | – |
| Reset staff password | – | ✓ (own branch) | – |
| Disable branch admin | ✓ | – | – |
| Disable staff | – | ✓ (own branch) | – |
| Enable branch admin | ✓ | – | – |
| Enable staff | – | ✓ (own branch) | – |
| View activity log (all branches) | ✓ | – | – |
| View activity log (own branch) | ✓ (as filter) | ✓ | – |
| Export activity log (all branches) | ✓ | – | – |
| Export activity log (own branch) | ✓ | ✓ | – |
| Create member | – | – | ✓ (own branch) |
| View member card | – | ✓ (own branch) | ✓ (own branch) |
| View signature image | – | ✓ (own branch) | ✓ (own branch) |
| Replace member signature | – | ✓ (own branch) | – |
| Delete member | – | ✓ (own branch) | – |
| Export member list | – | ✓ (own branch) | – |
| View own dashboard | ✓ | ✓ | ✓ |
| Change own password | ✓ | ✓ | ✓ |

"Own branch" = `member.branch_id == user.branch_id`.
Central admin's "own branch" is null; they are filtered by tenant only.

## 5. Domain rules

Each rule is enforced in code and covered by a test. Rule IDs are stable.

| ID | Rule |
|---|---|
| R1 | A user's email is unique within their tenant. |
| R2 | A staff or branch admin must belong to exactly one branch. |
| R3 | A central admin belongs to no branch. |
| R4 | A member has exactly one branch. |
| R5 | A member's branch is set at creation and never changes. |
| R6 | A member has zero or one signature. |
| R7 | A member's account_number is unique within their tenant. |
| R8 | A member's account_number matches `^[0-9]{6,15}$`. |
| R9 | A member's slug is `Str::slug(name) . '-' . account_number`. |
| R10 | A branch name is unique within its tenant. |
| R11 | A user can be disabled only by a user who outranks them, within scope (see §4). |
| R12 | A user cannot disable themselves. |
| R13 | The last active central admin cannot be disabled. |
| R14 | A branch admin can manage only staff in their own branch. |
| R15 | A central admin can manage branch admins, but cannot manage other central admins. |
| R16 | Central admin cannot view member cards or signature images. |
| R17 | Staff and branch admins can view only member cards in their own branch. |
| R18 | Deleting a member cascades to delete the signature row and the stored file. |
| R19 | Replacing a signature deletes the previous file. No orphan files. |
| R20 | Signature images are stored on a private disk. No public URL is ever issued. |
| R21 | Disabling a user invalidates their active sessions on next request. |
| R22 | Every state change writes exactly one ActivityLog row, inside the same transaction, after the mutation succeeds. |
| R23 | Every ActivityLog row names its actor: `user_id`, or `user_id=null` with `actor_reason`. |
| R24 | No notification is sent to the actor who performed the action. (Currently vacuous — no notification subsystem exists.) |
| R25 | The system does not hard-delete users. |
| R26 | The system does not hard-delete activity logs from within domain code. |

## 6. Lifecycle

### User
reated (status=active, must_change_password=true)
├─► must_change_password=true → forced password change → active
├─► active → disabled (by admin above them, reason recorded)
└─► disabled → active (by same admin tier)

text
Terminal in v1: disabled. No deletion path.

### Member
created (branch_id set, creator set)
├─► active → signature replaced (updated)
└─► active → deleted (cascades to signature)

text
Account number and name are immutable in v1.

### Signature
created → replaced (old file deleted) → deleted (cascade from member)

text

## 7. Activity logging

### Event names (canonical)

| Event | When | Type |
|---|---|---|
| `member.created` | after Member + Signature insert succeeds | state change |
| `member.viewed` | after Member detail page renders | access event |
| `member.updated` | after Signature replace succeeds | state change |
| `member.deleted` | after Member delete succeeds | state change |
| `user.created` | after User insert succeeds | state change |
| `user.password_issued` | after admin resets user's password | state change |
| `user.password_changed` | after user changes own password (forced or voluntary) | state change |
| `user.disabled` | after status flip to disabled | state change |
| `user.enabled` | after status flip to active | state change |
| `branch.created` | after Branch insert succeeds | state change |

### Actor rules
Every log row records:
- `user_id` — the acting user's id, or null
- `actor_reason` — non-null if `user_id` is null (e.g. `system`, `cli-prune`)
- `user_name`, `user_email`, `user_role`, `user_branch_id`, `user_branch_name` — snapshotted at write time
- `ip_address`, `user_agent` — captured from the current request when available
- `model_type`, `model_id` — the entity touched, when applicable

### Access events
`member.viewed` is the only read that is logged. It is documented as an *access event*, not a state change. This is an explicit deviation from the TaskForge standard (see audit doc §5, Deviation 1).

## 8. Explicit non-rules

The following are **not** rules in this domain, and must not be implemented by inference:

- "Notify the reviewer when a submission is resolved" — no submission/review concept exists
- "Blocker" — no such concept exists
- Self-notification prevention — vacuous; no notifications exist (see R24)
- "The user who created a member owns it forever" — ownership is via branch, not user (see R5)

The TaskForge standard's rules 10 (actor logging) and 11 (no self-notification) map here as follows:
- Rule 10 → R22, R23, and §7 above
- Rule 11 → R24, inert until notifications are introduced

## 9. Open questions for the customer

These require business answers, not engineering decisions. Slice work does not block on them, but the domain spec is not final until they're answered.

| # | Question | Recommendation |
|---|---|---|
| Q1 | Can a member's name be edited after creation? | No. Slug stability matters for audit. |
| Q2 | Can a member's account_number change? | No. If renumbered, create new and delete old. |
| Q3 | When a staff member transfers branches, do their old member cards move? | No. Cards stay in the branch they were created in (R5). |
| Q4 | Is `^[0-9]{6,15}$` the correct account_number format? | Confirm with customer. |
| Q5 | Retention window for activity logs? | Keep indefinitely. Regulator may require. |
| Q6 | Should central admin see member counts per branch even though they can't open cards? | Yes — counts are in the dashboard today. Confirm intent. |
| Q7 | Is there a "super central admin" tier? | No. All central admins equal in v1. |

## 10. Glossary

- **Actor** — the user performing an action; recorded on every log entry.
- **Access event** — a read that the customer requires to be logged. Only `member.viewed` in v1.
- **Branch admin** — `role=admin`; manages staff and member cards within one branch.
- **Central admin** — `role=central_admin`; institution-wide oversight; creates branch admins; cannot view member cards.
- **Member** — a credit union member whose signature card is on file.
- **Signature card** — the image of a member's signature; stored privately.
- **Staff** — `role=staff`; creates member cards within their branch.
- **State change** — a mutation of a domain entity. Logged per R22.
- **Tenant** — a credit union. v1 has one. Schema supports many.

## 11. What "done" looks like for v1

- Every rule R1–R26 is enforced by code, not convention.
- Every state change is logged with a named actor.
- Every authorization decision lives in a Policy.
- Every domain rule lives in an Action.
- No controller exceeds 100 lines.
- No `auth()` call outside controllers.
- No authorization logic in middleware.
- Every Action has tests covering the success path and at least one domain-exception path.
- A new engineer can read `docs/credit-union-system-domain-spec-v1.md` and `docs/credit-union-system-audit-round-1.md` and understand the entire system without opening the code.