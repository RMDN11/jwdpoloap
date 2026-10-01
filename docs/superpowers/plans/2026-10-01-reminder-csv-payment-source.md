# Reminder CSV source smoke test

## Scope
This change adds crm_csv_imports and crm_csv_participants only. It must not mutate peserta or pembayaran.

## Pre-flight
1. Backup the production database.
2. Run database/migrations/2026_10_01_reminder_csv_source.sql.
3. Confirm both new tables exist.
4. Confirm SELECT COUNT(*) FROM peserta and SELECT COUNT(*) FROM pembayaran are unchanged.

## CSV import checks
- Valid template imports inside one transaction.
- Missing header is rejected.
- Empty CSV is rejected.
- Files over 5 MB are rejected.
- More than 5,000 valid rows are rejected.
- Duplicate source rows are skipped and counted.
- WhatsApp is normalized from 08... to 62....
- A unique WhatsApp match links to peserta_id.
- Multiple CRM participants sharing the same normalized WhatsApp become ambiguous, never auto-linked.
- Any insert failure rolls back the whole import.

## Reminder checks
For a selected CSV import and BATCH 56:
- Belum bayar means a matched CSV participant has no pembayaran row for BATCH 56.
- Sudah bayar means a matched CSV participant has a pembayaran row for BATCH 56.
- The query never uses peserta.status as a substitute for payment existence.
- Repeated CSV rows cannot multiply the reminder count because the source condition uses EXISTS.
- Sending revalidates selected IDs against the selected CSV source and payment filter.

## Regression checks
- Existing reminder flow without csv_import_id still uses the existing CRM participant source.
- No UPDATE/DELETE is executed against peserta or pembayaran.
- Sending preserves csv_import_id in the redirect.
