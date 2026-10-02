# Retention Rate implementation plan

## Goal
Add a Reminder workspace page named **Retention Rate** that compares a previous cohort with a current cohort.

## Source model
- Previous and current sources may independently be CSV or Payment.
- CSV is read from `storage/reminder-csv`; no CSV rows are copied into MySQL.
- Payment is read from `pembayaran` joined to `peserta`.
- CSV `Status Siswa` must normalize to `ON`.
- Missing WhatsApp is excluded from matching.
- Normalized WhatsApp is the only retention identity. Names are display data, not matching keys.
- Duplicate normalized WhatsApp values are reduced to one source record.

## Metric
`Retention Rate = continued previous-cohort participants / previous-cohort participants × 100%`

- `Lanjut`: previous normalized WhatsApp exists in current source.
- `Tidak lanjut`: previous normalized WhatsApp does not exist in current source.
- Denominator is always the previous cohort.
- Peserta Baru is never included in the retention denominator.

## Breakdown
- Group breakdown uses CSV `Kelas / Grup` and payment `peserta.halaqoh`.
- Group matching is whitespace/case normalized only; no hidden semantic remapping.
- Default threshold is 70%.
- Rates below 70% are shown in red.

## Follow-up safety
- Only `Tidak lanjut` participants can be selected.
- Server revalidates the selected source pair before sending.
- The send action accepts at most 100 targets per request.
- Targets are normalized and deduplicated server-side.
- CSRF is required.
- Sending logs `[REMINDER] [TERKIRIM]` / `[REMINDER] [GAGAL]` into the existing `log_wa` flow.
- Template content is read from existing `wa_templates`; no new template table is created.

## Failure isolation
- Source list failures do not blank the page.
- Retention analysis errors become an inline error state.
- Reminder history is optional UI metadata and is isolated from the retention calculation.
- Redirects use HTTP 303 after POST and preserve the retention source/filter state.

## Regression checks
- Existing Reminder routes remain unchanged.
- No UPDATE/DELETE is performed on `peserta` or `pembayaran`.
- CSV remains filesystem-only.
- CSV parser no longer emits an undefined `id` lookup warning when the real 15-column template has no `id`.
- Pure comparison rules are covered by `crm/tests/reminder_retention_test.php`.
