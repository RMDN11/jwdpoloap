# Customer Baru Trigger & Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a lightweight, reliable trigger-management UI for Customer Baru, preserve raw history while excluding teachers from prospect automation, archive confirmed-success automated messages, and prepare a safe follow-up scheduler in a separate delivery slice.

**Architecture:** Reuse `crm_prospect_triggers` and existing CRM routing/auth/CSRF conventions. Ship trigger management and teacher exclusion first; then add explicit origin/outcome metadata and an archive view; only then add the due-follow-up queue with idempotency and pre-send eligibility checks. Keep rendering progressive and bounded, with lightweight CSS loading states and no blocking full-page data fetches.

**Tech Stack:** PHP, MySQLi, existing CRM CSS/JS, PHPUnit-free repository PHP regression scripts unless project tests establish another convention.

**Spec:** `docs/superpowers/specs/2026-10-15-customer-baru-trigger-followup-design.md`

## Global Constraints

- Preserve raw chat and log records; this feature must not physically delete history.
- Recognized teacher contacts are excluded from Customer Baru triggers, while raw messages remain stored.
- Reuse `crm_prospect_triggers`; do not create a competing trigger source of truth.
- Only confirmed-success automated messages may be hidden from the active Customer Baru list.
- An ambiguous provider timeout is not a confirmed failure or success and must not trigger automatic resend.
- All mutating endpoints require existing CRM authentication, CSRF validation, prepared statements, and server-side validation.
- Loading animations must be CSS-only or use existing frontend dependencies; do not add a new JS framework.
- All list queries must be bounded/paginated; errors must render an informative CRM state, not a blank page.
- Do not deploy, merge, or run production sends as part of tests.

## Review Focus

- Empty/malformed trigger rules and overlapping keywords must not crash the chat route or unexpectedly trigger multiple sends.
- Teacher phone numbers in `08`, `62`, and `+62` forms must be consistently excluded.
- Failed or ambiguous sends must remain visible/auditable and must not be archived as successful.
- Missing optional tables or failed queries must produce a visible recoverable error, not a fatal blank page.
- Slow/empty data responses must preserve navigation and show a bounded loading/empty/error state without continuous animation.

---

## Delivery Slice 1: Trigger Management Foundation

### Task 1: Audit schema and add regression tests

**Files:**
- Read: `crm/config/prospect.php`
- Read: `crm/pages/chat.php`
- Read: `crm/index.php`
- Read: `crm/config/chat-directory.php`
- Read: `webhook.php`
- Create: `crm/tests/customer_trigger_management_test.php`

**Interfaces:**
- Existing trigger source: `crmGetProspectTriggers(mysqli $conn): array` and `crmProspectClassifyMessage(...)`; inspect exact signatures before changing.
- Existing directory helper: `crmChatIsTeacherNumber(mysqli $conn, string $nowa): bool` if present on the target base; otherwise define a single helper in `crm/config/chat-directory.php`.

- [ ] Write failing static regression checks for route allowlist, reuse of `crm_prospect_triggers`, CSRF on POST, and dry-run having no send API call.
- [ ] Run `php crm/tests/customer_trigger_management_test.php`; expected initial failures for missing UI/route.
- [ ] Inspect actual table columns from repository schema/migrations and all trigger consumers; do not guess schema.
- [ ] Implement only after schema is verified; do not add a migration if existing columns suffice.
- [ ] Run the test and PHP syntax checks; expected all trigger-foundation checks pass.

### Task 2: Add trigger management route and compact UI

**Files:**
- Modify: `crm/index.php`
- Create: `crm/pages/triggers.php`
- Create: `crm/actions/triggers.php`
- Create: `crm/assets/css/triggers.css`
- Modify: `crm/pages/chat.php`
- Test: `crm/tests/customer_trigger_management_test.php`

**Interfaces:**
- Page route: `?page=triggers`.
- POST action values: `create`, `update`, `toggle`, `delete` only if deletion is safe and unused rules can be identified; default recommendation is deactivate instead of delete.
- Dry-run endpoint/action accepts sample text and returns matched rule/category only; it must never call the WhatsApp gateway.

- [ ] Add failing tests for route allowlist, visible “Kelola Trigger” entry, escaped output, CSRF, and prepared statements.
- [ ] Run tests and confirm expected failures.
- [ ] Implement CRUD/active toggle using existing schema, or a minimal compatible migration if the schema cannot represent required fields.
- [ ] Implement deterministic case-insensitive phrase matching and stable priority ordering; preserve current fallback behavior where needed.
- [ ] Add dry-run test UI and explicit no-send guarantee.
- [ ] Add CSS-only skeleton/shimmer for initial loading, reduced-motion support, and explicit empty/error states.
- [ ] Run PHP syntax checks and regression tests; expected all pass.

### Task 3: Teacher exclusion regression coverage

**Files:**
- Modify if needed: `webhook.php`
- Modify if needed: `crm/pages/chat-poll.php`
- Modify if needed: `crm/config/chat-directory.php`
- Test: `crm/tests/customer_trigger_management_test.php`

**Interfaces:**
- Keep raw inbound persistence before the trigger exclusion gate.
- Use one shared normalized teacher-number lookup and never classify an unknown contact as a teacher based on message wording.

- [ ] Write failing tests for normalized phone variants, raw-history persistence before exclusion, and no trigger processing for recognized teachers.
- [ ] Run tests and confirm failures.
- [ ] Implement or consolidate the exclusion at webhook and polling/eligibility boundaries without duplicate helper names.
- [ ] Run PHP syntax checks and tests; expected all pass.

## Delivery Slice 2: Automated-Send Archive

### Task 4: Map outbound writers and add source/outcome metadata

**Files:**
- Read and modify only after audit: `crm/actions/send-message.php`, all other outbound send handlers, `auto_reply_engine.php`, `webhook.php`, relevant schema migration files.
- Create: `crm/tests/automated_message_archive_test.php`

**Interfaces:**
- Persist origin as automated/manual and outcome as confirmed success/failed/unknown using the project's existing naming conventions.
- Do not infer success from browser redirect or a timeout page.

- [ ] Write failing tests that require explicit origin/outcome and verify manual messages are not classified as automated.
- [ ] Run tests and confirm expected failures.
- [ ] Audit every writer to `crm_messages`, `log_wa`, `crm_message_history`, and `auto_reply_logs`.
- [ ] Add a backward-compatible migration and update every relevant writer in one coherent change.
- [ ] Run tests and PHP syntax checks; expected all pass.

### Task 5: Archive view and active-list filter

**Files:**
- Modify: `crm/pages/chat.php`
- Modify: `crm/index.php`
- Create: `crm/pages/automated-archive.php`
- Create: `crm/assets/css/automated-archive.css`
- Test: `crm/tests/automated_message_archive_test.php`

- [ ] Write failing tests proving only confirmed-success automated sends are hidden from active Customer Baru and visible in archive.
- [ ] Run tests and confirm expected failures.
- [ ] Implement bounded archive pagination and query filters.
- [ ] Preserve failed, unknown, and manual messages in active/auditable views.
- [ ] Add loading/empty/error states and reduced-motion CSS.
- [ ] Run tests and syntax checks; expected all pass.

## Delivery Slice 3: Follow-up Scheduler

### Task 6: Follow-up rule schema and idempotent queue

**Files:**
- Create: migration for follow-up rules and queue, after inspecting project migration conventions.
- Create: `crm/config/followup-scheduler.php`
- Create: `crm/tests/followup_scheduler_test.php`

**Interfaces:**
- Define exact schema for rule (trigger/category, delay, template, active) and queue (contact, rule, step, due_at, state, idempotency key, timestamps).
- Unique idempotency key must prevent duplicate queue entries for the same contact/rule/step/event.

- [ ] Write failing tests for duplicate scheduling, delay calculation, and disabled rules.
- [ ] Run tests and confirm expected failures.
- [ ] Implement queue creation on a matched trigger without sending in the webhook request.
- [ ] Run tests and syntax checks; expected all pass.

### Task 7: Due-work processor with eligibility recheck

**Files:**
- Create: `cron_customer_followup.php`
- Modify: `crm/config/followup-scheduler.php`
- Test: `crm/tests/followup_scheduler_test.php`

- [ ] Write failing tests for teacher exclusion, customer reply since schedule, manual handling, disabled rule/template, provider failure, and ambiguous timeout.
- [ ] Run tests and confirm expected failures.
- [ ] Implement bounded batch processing and recheck all eligibility immediately before send.
- [ ] Mark ambiguous provider outcomes as unknown; do not blindly retry.
- [ ] Log outcome/skip reason without secrets or message bodies.
- [ ] Run tests and syntax checks; expected all pass.

### Task 8: Follow-up controls, loading states, and final review

**Files:**
- Create or modify: follow-up management page and CSS, using existing CRM routing conventions.
- Test: all tests from Tasks 1–7.

- [ ] Add UI for delay, template, active status, and recent execution outcomes.
- [ ] Add CSS-only loading animation, reduced-motion support, and visible error states.
- [ ] Run every regression test and `php -l` on all changed PHP files.
- [ ] Inspect every query for bounded result sets and all POST handlers for CSRF.
- [ ] Confirm no raw history deletion, no duplicate trigger engine, no send in dry-run, and no automatic resend after ambiguous timeout.
- [ ] Prepare PR with deployment/migration instructions and explicit production verification checklist.

---

## Acceptance Criteria

- Chat shows a visible “Kelola Trigger” action.
- Operators can create/edit/toggle rules and test matching without sending messages.
- Recognized teachers remain in raw history but never enter Customer Baru trigger/follow-up processing.
- Only confirmed-success automated messages are hidden from active Customer Baru and retained in archive.
- Follow-ups are queued rather than sent inline from webhook; dispatch is bounded, idempotent, and eligibility is rechecked before sending.
- All changed pages show loading, empty, and error states; reduced-motion preferences are respected.
- Regression tests and PHP syntax checks pass before PR is marked ready for review.
