# Remove kelola_reminder.php and Improve Reminder Workflow Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete obsolete `kelola_reminder.php`, re-route all navigation references to CRM Reminder workspaces (`page=reminder` and `page=reminder-peserta`), and enhance the UI/UX workflow of `reminder-pembayaran` with 1-click teacher request processing and dynamic live preview.

**Architecture:**
1. Safely remove `jwdpoloap/kelola_reminder.php` as its capabilities have been superseded by `crm/pages/reminder-peserta.php`.
2. Update all navigation links across legacy navigation hubs (`manage_auto_reply.php`, `kirimgrup.php`, `hanwa.php`, `promosi.php`, `reminder-index.php`) and `crm/pages/reminder-pembayaran.php` to target the CRM workspaces.
3. In `crm/pages/reminder-pembayaran.php`:
   - Add inline 1-click action to execute/send pending teacher requests directly via `actions/reminder-send.php?mode=request`.
   - Update footer link to `?page=reminder-peserta`.
   - Add dynamic live preview: selecting/clicking a participant renders their personalized message in real time.
   - Add quick selection reset button in the selectbar.
4. Verify all tests pass with regression suite.

**Tech Stack:** PHP 8, Vanilla JS, CSS3, Python test suite.

---

### Task 1: Delete `kelola_reminder.php` and Re-route Legacy Links

**Files:**
- Delete: `jwdpoloap/kelola_reminder.php`
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`
- Modify: `jwdpoloap/manage_auto_reply.php`
- Modify: `jwdpoloap/kirimgrup.php`
- Modify: `jwdpoloap/hanwa.php`
- Modify: `jwdpoloap/promosi.php`
- Modify: `jwdpoloap/reminder-index.php`

- [ ] **Step 1: Write failing test verifying `kelola_reminder.php` is deleted and links updated**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Remove `jwdpoloap/kelola_reminder.php` and update links in legacy files and `reminder-pembayaran.php` to `crm/index.php?page=reminder-peserta` / `crm/index.php?page=reminder`**
- [ ] **Step 4: Run test and observe pass**

---

### Task 2: Implement 1-Click Teacher Request Action in `reminder-pembayaran.php`

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`
- Modify: `jwdpoloap/crm/assets/css/reminder-v2.css`

- [ ] **Step 1: Write failing test verifying request send form and button in `reminder-pembayaran.php`**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Add inline form/button on pending teacher requests with hidden `request_id`, `template_id`, and `mode=request`**
- [ ] **Step 4: Style request action buttons in `reminder-v2.css`**
- [ ] **Step 5: Run test and observe pass**

---

### Task 3: Interactive Live Preview and Selection UX

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`
- Modify: `jwdpoloap/crm/assets/css/reminder-v2.css`

- [ ] **Step 1: Write failing test verifying live personalized preview and selection controls**
- [ ] **Step 2: Run test and observe failure**
- [ ] **Step 3: Enhance JavaScript in `reminder-pembayaran.php` to render personalized preview with `{nama}`, `{bulan}`, `{halaqoh}` when clicking a participant or switching templates**
- [ ] **Step 4: Add clear selection button in selectbar**
- [ ] **Step 5: Run test and observe pass**

---

### Task 4: Regression Testing

**Files:**
- Modify: `jwdpoloap/crm/tests/reminder_pembayaran_test.py`
- Test: `jwdpoloap/crm/tests/verify_suite.py`

- [ ] **Step 1: Run complete `verify_suite.py`**
- [ ] **Step 2: Verify all tests PASS**
