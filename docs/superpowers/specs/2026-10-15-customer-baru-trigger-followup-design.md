# Customer Baru Trigger & Follow-up Automation Design

**Date:** 2026-10-15
**Status:** Draft for review

## Goal

Give CRM operators a UI to manage manual text triggers for the Customer Baru inbox, then support safe, configurable follow-up scheduling when an incoming customer message matches an active trigger. Preserve raw conversation history and exclude recognized teacher contacts from prospect triggers.

## Confirmed decisions

- Successfully sent automated messages are hidden from the active Customer Baru list and retained in an archive.
- Teacher messages and logs are excluded from Customer Baru and its triggers, while raw history is retained.
- Trigger management UI is the first deliverable; follow-up scheduling is a separate subsequent deliverable.
- Follow-up eligibility must be rechecked immediately before sending so a customer reply or status change can stop a queued follow-up.
- Do not delete raw chat/log records.

## Current-code observations

- `crm/config/prospect.php` contains `crmGetProspectTriggers()` and existing prospect classification helpers, so the feature should extend the current trigger source of truth instead of creating a competing engine.
- `crm/pages/chat.php` renders the Chat inbox and is the natural place for a visible “Kelola Trigger” entry.
- `crm/index.php` uses an allowlist of CRM pages and dispatches page files.
- `webhook.php` stores incoming message history and invokes `AutoReplyEngine`; teacher exclusion must happen before prospect trigger/auto-reply processing, without bypassing raw history persistence.
- Existing chat and follow-up history use more than one table. Successful automatic sends cannot be hidden safely until automated/manual origin and send outcome are represented unambiguously.

These are source-level observations; schema details and production behavior must be verified during implementation before migrations are written.

## Proposed design

### 1. Trigger management UI

Add a CRM page reachable from Chat using a visible “Kelola Trigger” button. The page lists active and inactive rules and supports create, edit, enable/disable, and a dry-run test against sample text. Each rule has:
- keyword or phrase;
- target category;
- matching mode (case-insensitive phrase match initially);
- priority/order;
- active flag;
- created/updated timestamps.

Reuse the existing trigger table if its schema supports these fields. If not, add a migration that extends it compatibly. Do not hard-code trigger rules in a second location. Use existing CRM authentication, CSRF validation, prepared statements, and redirect-after-POST conventions.

### 2. Trigger evaluation and teacher exclusion

Normalize phone numbers before checking known teacher directories. Preserve incoming raw message history, but exclude recognized teacher contacts from Customer Baru categorization and prospect-trigger evaluation. Unknown contacts must not be classified as teachers based on message wording alone. Trigger evaluation should be deterministic and record which rule matched.

### 3. Automated-send archive

Represent message origin and delivery outcome explicitly (automated/manual; pending/sent/failed/unknown where supported by the existing sending contract). Hide only confirmed-success automated messages from the active Customer Baru view, and retain them in an archive view. Never infer successful delivery solely from a browser timeout or an ambiguous API response. Never physically delete raw logs as part of this feature.

### 4. Follow-up automation (separate deliverable)

When an incoming customer message matches an active trigger, create or update a follow-up state for the contact. Follow-up rules specify category, delay, template/message, active state, and optional sequence step. Before dispatch, re-check that:
- the contact is not a recognized teacher;
- the conversation is still eligible for Customer Baru follow-up;
- the customer has not replied or been manually handled since scheduling;
- the same follow-up step has not already been sent;
- the rule and template are still active.

Use an idempotency key per contact/rule/step and a scheduler that processes due work in bounded batches. Record outcomes and stop reasons. Do not send automatically from the trigger-management dry-run.

## UI outline

- Chat inbox: visible “Kelola Trigger” action.
- Trigger page: compact bento summary, list of rules, add/edit form, active toggle, test-message field with matched rule/category result.
- Follow-up management will be a later page or section after trigger management is stable; it should expose delay, template, active state, and recent run outcomes.

## Data flow

1. Receive webhook message.
2. Persist raw inbound event/history as today.
3. Normalize contact number and exclude known teacher contacts from prospect-trigger processing.
4. Match active trigger rules deterministically.
5. Store classification and, in the later phase, create a deduplicated follow-up schedule.
6. At due time, re-check eligibility and send through the existing supported WhatsApp gateway.
7. Record send outcome; confirmed successful automated sends appear in archive rather than the active Customer Baru list.

## Error handling and safety

- If teacher directory lookup fails, do not silently treat a contact as a confirmed teacher; log the lookup failure and avoid destructive action.
- If a trigger rule cannot be loaded, do not invent a fallback match that sends a message.
- If gateway outcome is ambiguous, record unknown and do not blindly retry a potentially delivered message.
- All management POST actions require authentication, CSRF validation, and server-side input validation.
- Keep raw history immutable for this feature; no permanent deletion.
- Log scheduling, dispatch, cancellation, and skip reasons without exposing API secrets.

## Testing strategy

- Unit/regression tests for phone normalization and teacher exclusion across `08`, `62`, and `+62` formats.
- Trigger tests for case-insensitive matching, disabled rules, priority, overlapping keywords, empty text, and dry-run with no send side effect.
- Permission/CSRF tests for all management actions.
- Archive tests proving only confirmed-success automated messages are hidden from active list, while manual messages, failed sends, ambiguous outcomes, and raw history remain available.
- Follow-up tests for delay, duplicate scheduling, idempotency, customer reply before dispatch, manual status change, teacher exclusion, disabled rule/template, gateway failure, and ambiguous timeout.
- Run PHP syntax checks and repository tests before creating a production-ready PR; validate DB migrations against a disposable/test database, not production.

## Delivery slices

1. **Trigger management foundation:** UI, CRUD/enable-disable, dry-run matching, teacher exclusion at trigger boundary, tests.
2. **Automated-send archive:** stable origin/outcome metadata, active-list filter, archive view, migration and regression tests.
3. **Follow-up scheduler:** configurable delay/template rules, due queue, pre-send eligibility checks, idempotency, logs and tests.

## Open implementation checks

- Confirm exact columns and consumers of the existing trigger table before deciding whether a migration is needed.
- Confirm all places that write automated messages to `crm_messages`, `log_wa`, `crm_message_history`, and `auto_reply_logs`.
- Confirm the actual sending API's response semantics so “success” and “unknown” are not conflated.
- Confirm the current teacher directory schemas and whether both tables are always present.
