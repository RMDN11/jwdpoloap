# Reminder CSV source smoke test

## Scope
CSV reminder source is filesystem-only. The uploaded CSV and its metadata stay in the server folder; no CSV participant rows are written to MySQL.

## Pre-flight
1. Backup the production database before deployment.
2. Confirm the server can create/write `storage/reminder-csv`.
3. Confirm direct web access to that folder is blocked by the folder access rule.
4. Confirm the existing `peserta` and `pembayaran` tables are unchanged by CSV import.

## CSV import checks
- Valid template is accepted and the original CSV is moved into the server storage folder.
- Missing header is rejected.
- Empty CSV is rejected.
- Files over 5 MB are rejected.
- More than 5,000 valid rows are rejected.
- Duplicate source rows are skipped and counted.
- WhatsApp is normalized from 08... to 62....
- Status Siswa is normalized to uppercase so `ON`, `On`, etc. are treated as `ON`.
- A unique WhatsApp match is counted against peserta for the UI, but the match is not persisted in the database.
- Multiple CRM participants sharing the same normalized WhatsApp are treated as ambiguous and are not auto-linked.
- Import failures do not leave a partial CSV or metadata file.

## Reminder checks
For a selected CSV file and BATCH 56:
- Only CSV rows with `Status Siswa = ON` are eligible; `OFF` is excluded.
- CSV `WhatsApp Wali` is the preferred and actual send number; `peserta.nowa` is used only for matching the CSV row to the CRM participant.
- Belum bayar means a matched CSV participant has no pembayaran row for BATCH 56.
- Sudah bayar means a matched CSV participant has a pembayaran row for BATCH 56.
- The query never uses peserta.status as a substitute for payment existence.
- When a CSV source is selected, the CRM `peserta.status` filter is not used to exclude CSV `ON` students.
- CSV data is read from the server file at filter time; there is no `crm_csv_*` table or database snapshot.
- Sending revalidates selected IDs against the selected CSV file, `Status Siswa = ON`, CSV WhatsApp number, and payment filter.

## Regression checks
- Existing reminder flow without a CSV source still uses the existing CRM participant source.
- No UPDATE/DELETE is executed against peserta or pembayaran.
- Sending preserves the selected `csv_file` in the redirect.
