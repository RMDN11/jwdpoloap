# Fix Reminder Pembayaran Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all critical bugs, layout flaws, pagination limitations, security issues, and template shortcomings in `page=reminder-pembayaran` and its related actions.

**Architecture:** 
1. Modernize SQL queries for MySQL 8 compatibility (`GROUP BY` for `bulan_pembayaran`).
2. Add server-side pagination with query preservation and page controls.
3. Secure `crm/actions/reminder-send.php` by querying the DB for master participants, rate-limiting HTTP requests, adding placeholder support (`{bulan}`, `{halaqoh}`), and logging to message history.
4. Update UI layout with a two-column responsive grid (`.reminder-payment-grid`) and sticky sidebar, plus anti-double-submit loading states.
5. Create a test runner in `crm/tests/reminder_pembayaran_test.py` integrated with `verify_suite.py`.

**Tech Stack:** PHP 8, MySQL, Vanilla JavaScript, CSS3 Grid/Flexbox, Python 3 (testing).

**Spec:** Code review and issue audit conducted on `crm/pages/reminder-pembayaran.php`, `crm/actions/reminder-send.php`, and `crm/assets/css/reminder-v2.css`.

## Global Constraints
- Preserve backward compatibility with existing templates and CSV import schema.
- Follow existing CSRF token and session handling patterns (`crmCsrfToken()`, `crmVerifyCsrf()`).
- Do not introduce external dependencies.
- Ensure PHP files pass strict static verification tests.

## Review Focus
1. Pagination state persistence when applying search, halaqoh, or CSV filters.
2. Participant security: master DB participants sent in `selected` must be resolved from `peserta` table.
3. MySQL `ONLY_FULL_GROUP_BY` compliance for month ordering.
4. CSS desktop grid responsiveness: sidebar must sit alongside table on screens > 900px and stack cleanly on mobile.
5. Multi-placeholder string replacements (`{nama}`, `{bulan}`, `{halaqoh}`).

---

### Task 1: Fix SQL Compatibility and Filter Logic

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`

- [ ] **Step 1: Write failing test checking for MySQL 8 query and CSV filter fix**
Add assertions in `crm/tests/reminder_pembayaran_test.py`.
- [ ] **Step 2: Run test and verify it fails**
- [ ] **Step 3: Update `bulan_pembayaran` query to use `GROUP BY bulan_pembayaran ORDER BY MAX(id) DESC`**
- [ ] **Step 4: Enable `status_peserta` filter even when `$csvFile !== ''`**
- [ ] **Step 5: Run test and verify it passes**

---

### Task 2: Implement Pagination with Query Preservation

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`

- [ ] **Step 1: Write failing test checking pagination logic and controls**
- [ ] **Step 2: Run test and verify it fails**
- [ ] **Step 3: Add `$page` (default 1), `$perPage = 50`, `$offset`, and `$totalPages` computation**
- [ ] **Step 4: Update SQL with `LIMIT ? OFFSET ?` prepared statement binding**
- [ ] **Step 5: Add pagination bar with previous/next and page indicators**
- [ ] **Step 6: Run test and verify it passes**

---

### Task 3: Secure and Upgrade `reminder-send.php`

**Files:**
- Modify: `jwdpoloap/crm/actions/reminder-send.php`
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`

- [ ] **Step 1: Write failing test checking DB validation, placeholder replacement, and execution safeguards**
- [ ] **Step 2: Run test and verify it fails**
- [ ] **Step 3: In `actions/reminder-send.php`, query database for participant records when `$csvFile === ''` to prevent client spoofing**
- [ ] **Step 4: Support `{bulan}` and `{halaqoh}` placeholders in message template replacement**
- [ ] **Step 5: Add rate limit pacing (`usleep(100000)`) and insert log to `crm_message_history` if table exists**
- [ ] **Step 6: Update helper text in `reminder-pembayaran.php`**
- [ ] **Step 7: Run test and verify it passes**

---

### Task 4: Responsive Grid Layout and Anti-Double-Submit UX

**Files:**
- Modify: `jwdpoloap/crm/pages/reminder-pembayaran.php`
- Modify: `jwdpoloap/crm/assets/css/reminder-v2.css`

- [ ] **Step 1: Write failing test checking for `.reminder-payment-grid` and button disable state on submit**
- [ ] **Step 2: Run test and verify it fails**
- [ ] **Step 3: Restructure HTML in `reminder-pembayaran.php` to wrap main and aside inside `.reminder-payment-grid`**
- [ ] **Step 4: Add CSS styles in `reminder-v2.css` for 2-column layout and sticky side card**
- [ ] **Step 5: Add JS loading state on submit (disabling button and changing text to "Mengirim...")**
- [ ] **Step 6: Run test and verify it passes**

---

### Task 5: Full Suite Regression Verification

**Files:**
- Modify: `jwdpoloap/crm/tests/verify_suite.py`
- Test: `jwdpoloap/crm/tests/reminder_pembayaran_test.py`

- [ ] **Step 1: Integrate `reminder_pembayaran_test.py` into `verify_suite.py`**
- [ ] **Step 2: Run full regression test suite**
- [ ] **Step 3: Verify all tests PASS**
