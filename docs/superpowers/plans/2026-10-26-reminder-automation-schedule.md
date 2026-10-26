# Reminder Automation Schedule & Target UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a customizable halaqoh-based scheduling & automation chat engine with configurable inter-message delay, inter-halaqoh cooldown, start time scheduler, live progress monitoring, and enhanced Target Peserta UI/UX.

**Architecture:**
1. Database schema with `crm_reminder_campaigns` and `crm_reminder_campaign_queue` created automatically via `crmEnsureReminderScheduleTables()`.
2. Core scheduling engine in `crm/lib/reminder-schedule.php` calculating timestamps per halaqoh with delays.
3. Cron background runner in `crm/actions/reminder-schedule-cron.php` processing mature queue items.
4. Action handler `crm/actions/reminder-schedule.php` supporting campaign creation, pausing, resuming, and cancellation.
5. UI modal and campaign progress monitor inside `crm/pages/reminder-pembayaran.php` alongside quick filter shortcuts.
6. Automated test suite in `crm/tests/reminder_schedule_test.py` integrated into `crm/tests/verify_suite.py`.

**Tech Stack:** PHP 8, MySQL, Vanilla JavaScript, CSS3 Grid/Flexbox, Python 3.

---

### Task 1: Database Schema & Core Engine (`crm/lib/reminder-schedule.php`)

**Files:**
- Create: `jwdpoloap/crm/lib/reminder-schedule.php`
- Modify: `jwdpoloap/crm/config/bootstrap.php`

- [ ] **Step 1: Write failing test for table creation and schedule calculation**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Implement `crmEnsureReminderScheduleTables($conn)`**
- [ ] **Step 4: Implement `crmCreateReminderCampaign($conn, ...)` with timestamp math for inter-message and inter-group delays**
- [ ] **Step 5: Implement `crmProcessPendingReminderQueue($conn, ...)` and campaign status updater**
- [ ] **Step 6: Run test and observe pass**

---

### Task 2: Action Handlers & Cron Runner

**Files:**
- Create: `jwdpoloap/crm/actions/reminder-schedule.php`
- Create: `jwdpoloap/crm/actions/reminder-schedule-cron.php`

- [ ] **Step 1: Write failing test for schedule action and cron runner**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Implement `crm/actions/reminder-schedule.php` (POST handling for create, pause, resume, cancel)**
- [ ] **Step 4: Implement `crm/actions/reminder-schedule-cron.php` (CLI and token HTTP support)**
- [ ] **Step 5: Run test and observe pass**

---

### Task 3: Target Peserta UI/UX & Quick Filters

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`
- Modify: `jwdpoloap/crm/assets/css/reminder-v2.css`

- [ ] **Step 1: Write failing test for quick filters and schedule modal triggers**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Add quick filter chips in `reminder-pembayaran.php`**
- [ ] **Step 4: Add active campaign monitor card in `reminder-pembayaran.php`**
- [ ] **Step 5: Run test and observe pass**

---

### Task 4: Automation Schedule Modal & Interactive UI

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`
- Modify: `jwdpoloap/crm/assets/css/reminder-v2.css`

- [ ] **Step 1: Write failing test for schedule modal elements and JavaScript handlers**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Add `#reminderScheduleModal` with start time, message delay, and halaqoh cooldown inputs**
- [ ] **Step 4: Add client-side estimation calculation and form submission in JavaScript**
- [ ] **Step 5: Run test and observe pass**

---

### Task 5: Full Regression Verification & Git Deployment

**Files:**
- Test: `jwdpoloap/crm/tests/reminder_schedule_test.py`
- Modify: `jwdpoloap/crm/tests/verify_suite.py`

- [ ] **Step 1: Hook test into `verify_suite.py`**
- [ ] **Step 2: Run full regression test suite**
- [ ] **Step 3: Commit and push changes to `origin main`**
