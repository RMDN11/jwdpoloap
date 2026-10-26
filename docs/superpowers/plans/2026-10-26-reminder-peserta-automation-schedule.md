# Reminder Peserta Automation & Schedule Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Overview
Implement automated halaqoh-based scheduling for `page=reminder-peserta` with configurable inter-message delay, inter-halaqoh cooldown pause, campaign status monitoring (pause/resume/cancel), AND a dedicated day-of-week selection feature ("bisa atur harinya", e.g. Senin, Selasa, etc.), plus contact history display on each participant row ("berikan riwayatnya pada baris peserta tersebut").

## File Structure & Responsibilities
- `crm/lib/reminder-schedule.php`: Core schema migration (`campaign_type`, `allowed_days`, `request_id`), `crmAdjustTimestampToAllowedDays()`, campaign creator supporting both `wa_templates` and `reminder_templates`, cron dispatcher updating `reminder_requests` status on successful delivery.
- `crm/actions/reminder-schedule.php`: Handle POST actions for `type='peserta'`, parsing `allowed_days`, halaqoh groups, and redirecting back to `index.php?page=reminder-peserta`.
- `crm/pages/reminder-peserta.php`:
  - Calculate and display contact history (`reminder_count`, `reminder_last_at`) on each request row.
  - Active campaigns monitor widget showing progress, next run time, and real-time controls (Pause, Resume, Cancel).
  - Selection checkboxes + Select All bar + counter for requests.
  - Modal `#reminderPesertaScheduleModal` with day-of-week picker, quick day filters, delay sliders, and live completion estimator.
- `crm/assets/css/reminder-v2.css`: Responsive styles for day-of-week chips, campaign monitor in peserta workspace, and history badges.
- `crm/tests/reminder_peserta_schedule_test.py`: Full verification test suite covering schema, action endpoints, day-of-week logic, and UI elements.

## Tasks

- [ ] Task 1: Extend `crm/lib/reminder-schedule.php` with Day-of-Week & Peserta Support
  - [ ] Add schema columns `campaign_type`, `allowed_days` to `crm_reminder_campaigns`, and `request_id` to `crm_reminder_campaign_queue`.
  - [ ] Add `crmAdjustTimestampToAllowedDays()` helper.
  - [ ] Update `crmCreateReminderCampaign()` to accept `type` (`pembayaran` or `peserta`), `allowed_days` (array of 1..7), and resolve templates from `reminder_templates` or `wa_templates`.
  - [ ] Update `crmProcessPendingReminderQueue()` to update `reminder_requests.status = 'terkirim'` when a `request_id` is processed.
  - [ ] Update `crmGetActiveCampaigns()` to support filtering by `campaign_type`.

- [ ] Task 2: Update `crm/actions/reminder-schedule.php` for Peserta Actions
  - [ ] Support `campaign_type === 'peserta'` POST requests.
  - [ ] Parse `allowed_days` array/string into integer list (1..7).
  - [ ] Parse selected `request_id` items and halaqoh groupings.
  - [ ] Redirect to `index.php?page=reminder-peserta` preserving search/filter parameters.

- [ ] Task 3: Implement History & Automation UI in `crm/pages/reminder-peserta.php`
  - [ ] Fetch WhatsApp reminder history from `log_wa` for all rendered participant numbers and compute count + last sent time.
  - [ ] Display contact history badge on each `.reminder-peserta-item`.
  - [ ] Render active campaigns monitor card (`.reminder-campaign-card`) above the list for active `peserta` campaigns.
  - [ ] Add item selection checkboxes + Select All bar + dynamic counter.
  - [ ] Add "Atur Jadwal Otomasi" button in `.reminder-peserta-bulk`.
  - [ ] Build `#reminderPesertaScheduleModal` with:
    - Campaign Title
    - Template selection
    - Day-of-week selection (`Senin` - `Ahad`) with quick buttons (Semua Hari, Hari Kerja, Akhir Pekan)
    - Jeda antar pesan & jeda antar halaqoh
    - Waktu & tanggal mulai
    - Live estimation calculator box
  - [ ] Add JavaScript event handlers to calculate estimation and bind modal controls.

- [ ] Task 4: Add CSS styles in `crm/assets/css/reminder-v2.css`
  - [ ] Style `.reminder-peserta-days-grid`, `.reminder-day-chip`, `.reminder-peserta-history`, `.reminder-peserta-checkbox`.

- [ ] Task 5: Automated Testing & Verification
  - [ ] Create `crm/tests/reminder_peserta_schedule_test.py`.
  - [ ] Connect suite to `crm/tests/verify_suite.py`.
  - [ ] Run test suite until all assertions pass.
  - [ ] Commit and push to repository `main`.
