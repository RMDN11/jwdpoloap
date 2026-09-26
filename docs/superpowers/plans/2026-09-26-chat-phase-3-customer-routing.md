# Chat Phase 3: Customer Routing Implementation Plan

## Status

Implementation plan based on the merged Phase 3 design specification and current repository audit.

## Baseline

- Repository: `RMDN11/jwdpoloap`
- Base branch: `main`
- Base commit: `1710e81f91fbf15ed57a9d1172fd235c089b6206`
- Phase 3 design PR: #76, merged.
- Current Chat layer: `crm_conversations`, `crm_messages`, `crm_followups`.
- Legacy compatibility remains through `log_wa` and `crm_message_history`.

## 1. Current-State Audit

### Chat page

`crm/pages/chat.php` already reads the conversation layer instead of scanning `log_wa` for the primary list. It currently has:

- range filters: today/week/month/all;
- status filters: all/new/followed;
- rooms: all/people/other;
- conversation pagination;
- search by name, number, and message;
- two-way history from `crm_messages`;
- legacy outbound history fallback from `crm_message_history`;
- unread badge from `unread_count`;
- follow-up indicator from `followup_count`.

The current room model is therefore the main UI area to replace with Phase 3 effective routing.

### Conversation schema

Current `crm_conversations` contains:

- identity: `id`, `nowa`, `nama`;
- state: `status`;
- activity: `last_message_at`, `last_inbound_at`, `last_outbound_at`, `last_read_at`;
- counters: `unread_count`, `followup_count`;
- timestamps: `created_at`, `updated_at`.

Phase 3 should add only fields required for persistent routing/classification. Do not add redundant timestamps that can safely be derived from event history.

### Message layer

`crm_messages` already supports inbound/outbound direction, sender type, source, template metadata, external id, and timestamps.

This is sufficient as the source for:

- conversation history;
- inbound intent classification;
- outbound payment detection;
- payment-state evidence.

### Follow-up layer

`crm_followups` already records follow-up events per conversation/message.

It remains the history source for follow-up state. `followup_count` may remain as a denormalized counter.

### Directory / classification

`crm/config/chat-directory.php` already detects available known-contact tables through `information_schema`, avoiding the assumption that `pengajar` exists.

`crm/config/prospect.php` already provides:

- number normalization;
- generic inquiry detection;
- `crmGetProspectTriggers()`;
- `crmProspectClassifyMessage()`.

Existing trigger categories must be reused instead of creating a second trigger system.

`crm/config/prospect.php` currently has hardcoded disqualification logic and legacy prospect eligibility. Phase 3 must not use `crmIsEligibleProspect()` as the visibility gate for Chat.

### Webhook

Root `webhook.php` dual-writes inbound messages to:

1. legacy `log_wa`;
2. `crm_messages` through `crmChatStoreMessage()`.

Phase 3 classification should be added after the inbound conversation/message has been persisted, with failure isolated so classification cannot break webhook delivery.

## 2. Data Model Changes

Create an additive migration, for example:

`database/migrations/20260926_chat_phase_3.sql`

Only add fields required by the effective routing model.

Proposed fields on `crm_conversations`:

- `room` VARCHAR(30) NOT NULL DEFAULT 'lainnya'
- `room_source` VARCHAR(20) NOT NULL DEFAULT 'auto'
- `intent_category` VARCHAR(100) NULL
- `payment_detected_at` DATETIME NULL

Recommended indexes:

- `(room, last_inbound_at)`
- `(room_source, room, last_inbound_at)`
- retain existing activity/unread indexes.

Do not add `last_followup_at` because follow-up history already exists in `crm_followups`.

If implementation finds that a field can be derived safely and efficiently without schema expansion, prefer derivation.

## 3. Classification Helper

Create a dedicated helper, for example:

`crm/config/chat-routing.php`

Responsibilities:

### Number identity

Reuse `crmProspectNormalizeNumber()` or centralize normalization without changing existing behavior.

### Known contact

Reuse `crmChatKnownContactSql()` for participant/pengampu/pengajar detection.

### Internal directory

Create a clearly isolated internal-number provider/helper.

Initial internal/admin numbers should live in one explicit location rather than being scattered through classification conditions.

Do not rely on the existing single hardcoded value in `crmGetDisqualifiedNumbers()` as the long-term routing model.

### Intent

Classify inbound message using the existing `crm_prospect_triggers` mechanism.

Rules:

- qualifying trigger -> category;
- no qualifying trigger -> `lainnya`;
- generic inquiry remains visible but does not qualify as Customer Baru;
- classification must never hide or delete the message.

### Payment

Implement a dedicated outbound payment detector.

Initial patterns must be based on actual Jawwada workflow language, including:

- `Wajib segera diisi`;
- `Mohon diisi untuk pendataan Finance kami`.

Normalize case and whitespace and allow small wording variations.

Do not treat the word `finance` alone as payment evidence.

### Effective room

Priority:

1. manual override;
2. payment state;
3. known contact;
4. qualifying customer intent;
5. lainnya.

Return both effective room and reason/source so callers can persist the decision consistently.

## 4. Classification Integration

### Inbound path

Update the webhook flow:

1. receive and validate inbound;
2. write legacy `log_wa`;
3. write `crm_messages`;
4. obtain conversation;
5. classify the latest inbound message;
6. persist room/intent only when manual override is not active;
7. preserve webhook success even if routing classification fails.

Inbound must increment unread through the existing Chat helper.

### Outbound path

Update `crm/actions/send-message.php`:

1. send WhatsApp as currently implemented;
2. write legacy history;
3. write `crm_messages`;
4. write follow-up event when applicable;
5. inspect the resulting outbound message for payment trigger;
6. update payment state when detected unless manual routing is active.

Do not change the provider API contract.

### Auto-reply path

Existing auto-reply already stores outbound messages into `crm_messages`.

Ensure payment detection is applied consistently to those outbound messages only if they represent the same operational payment workflow. Do not classify arbitrary automated text as payment without a verified trigger.

## 5. Manual Routing

Create an authenticated CSRF-protected action:

`crm/actions/chat-route.php`

Input:

- conversation id;
- target room;
- optional clear/reset override.

Allowed rooms:

- `customer_baru`;
- `sudah_payment`;
- `peserta_pengajar`;
- `lainnya`.

On manual assignment:

- persist effective room;
- persist `room_source = manual`;
- keep intent/payment metadata intact;
- do not alter message history.

On clear/reset:

- set source back to auto;
- immediately re-evaluate current conversation state.

Manual override must win during webhook polling and outbound processing.

## 6. Chat Query Rebuild

Update `crm/pages/chat.php`:

Replace current `people/other` room model with:

- Semua Chat;
- Customer Baru;
- Sudah Payment;
- Peserta & Pengajar;
- Lainnya.

Customer Baru list must require the effective room, not re-run legacy prospect eligibility over historical `log_wa`.

Status filters remain separate from room:

- all;
- new/unread;
- followed.

Customer Baru operational status should be derived from:

- unread state;
- follow-up events;
- payment room.

Do not equate read with follow-up.

Selected conversation must expose:

- current room;
- room source;
- intent category;
- unread/read state;
- follow-up count/history;
- payment detected state;
- manual routing controls.

## 7. KPI

KPI queries must count conversations/customers, not message rows.

For Customer Baru:

- Hari Ini: qualifying conversation with relevant inbound activity today;
- Minggu Ini: from Monday;
- Bulan Ini: from day 1;
- All: full qualifying set according to effective room/state.

Do not use message count for customer KPI.

Participants, teachers, internal/admin numbers, outbound-only conversations, and non-qualifying conversations must not increase Customer Baru KPI.

Use `COUNT(*)` on qualifying conversation rows where possible.

## 8. Read / Unread

Keep existing fields:

- `unread_count`;
- `last_read_at`.

Existing mark-read action remains the source of truth.

Opening a Customer Baru conversation:

- sets unread to zero;
- records `last_read_at`;
- removes the red new badge;
- does not automatically mark follow-up as complete.

Polling must not recreate unread state incorrectly.

## 9. Follow-up

Continue using `crm_followups` as event source.

Display states:

- Perlu Follow-up: qualifying customer and no successful follow-up;
- Sudah Dibaca: read but no successful follow-up;
- Sudah Follow-up: one or more successful follow-up events;
- Sudah Payment: effective room/payment state.

Do not introduce a parallel session-only counter.

## 10. Performance

Do not return to legacy behavior of loading thousands of `log_wa` rows for each Chat render.

Room and KPI queries should operate on `crm_conversations`.

History queries should operate on indexed `crm_messages`.

Polling should continue using the existing lightweight endpoints.

No duplicate polling loops.

## 11. Testing

### SQL / migration

- migration succeeds on existing Phase 2 schema;
- rerunning migration is safe;
- indexes exist;
- no legacy table is modified destructively.

### Classification

Test:

1. known participant + trigger -> `peserta_pengajar`;
2. known pengampu + trigger -> `peserta_pengajar`;
3. known pengajar + trigger -> `peserta_pengajar`;
4. internal/admin + trigger -> internal/appropriate non-customer room;
5. unknown + no trigger -> `lainnya`;
6. unknown + `murojaah` -> `customer_baru`;
7. unknown + existing active trigger -> `customer_baru`;
8. generic inquiry only -> `lainnya`;
9. manual route -> requested room;
10. new inbound after manual route -> manual room remains;
11. clear override -> auto classification resumes.

### Payment

1. verified payment pattern -> `sudah_payment`;
2. `finance` alone -> no payment transition;
3. manual override -> no automatic room overwrite;
4. payment state persists after refresh.

### Read

1. inbound increments unread;
2. opening conversation clears unread;
3. refresh preserves read state;
4. polling does not resurrect badge incorrectly.

### Follow-up

1. successful follow-up creates event;
2. count matches events;
3. read state remains independent;
4. follow-up status survives refresh.

### KPI

1. one customer with 10 messages counts as one;
2. participant does not count;
3. teacher does not count;
4. admin does not count;
5. outbound-only conversation does not count;
6. today/week/month boundaries use application/database timezone consistently.

### Compatibility

1. webhook still writes `log_wa`;
2. webhook still writes `crm_messages`;
3. outbound WhatsApp still sends;
4. outbound history remains available;
5. legacy pages continue to function.

## 12. PR Sequence

### PR A — Data + routing foundation

Scope:

- Phase 3 migration;
- `crm/config/chat-routing.php`;
- classification/payment helpers;
- unit-like PHP tests or deterministic CLI test harness;
- no major UI changes.

### PR B — Inbound/outbound integration

Scope:

- webhook integration;
- send-message integration;
- auto-reply integration where validated;
- persistence of room/intent/payment state;
- regression checks.

### PR C — Chat workspace

Scope:

- room tabs;
- room counts;
- Customer Baru KPI;
- payment room;
- participant/teacher room;
- other room;
- updated status filters.

### PR D — Manual routing + UX

Scope:

- route action;
- manual override UI;
- reset-to-auto;
- intent/payment metadata display;
- final responsive behavior.

### PR E — Regression / hardening

Scope:

- production verification SQL;
- edge cases;
- polling/read-state regression;
- performance checks;
- documentation.

## 13. Deployment Discipline

For each implementation PR:

1. merge PR;
2. server `git fetch origin`;
3. `git checkout main`;
4. `git pull --ff-only origin main`;
5. inspect `git status`;
6. run the migration only when that PR introduces it;
7. run targeted verification;
8. test Chat manually;
9. record resulting HEAD.

Never use `git reset --hard` or `git clean -fd` on this server without a separate explicit investigation.

## 14. Important Constraints

- No destructive schema migration.
- No historical `log_wa` backfill.
- No parallel trigger universe.
- No hardcoded routing logic scattered across pages.
- No provider/API change.
- No message filtering based on classification.
- Manual routing always wins until explicitly cleared.
- Customer KPI is unique customer/conversation count.
- Legacy compatibility remains during migration.
