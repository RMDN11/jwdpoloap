# Automation Follow-Up Row Retention & Workflow Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retain exact pagination page, filters, scroll position, and target row highlight when performing actions in Automation Follow-Up, while eliminating workflow gaps (search bar, hardcoded queue limits, double-click debouncing, and empty-state resets).

**Architecture:** Action endpoints receive and validate a `return_url` plus target row anchor, redirecting admins back to their exact context with a visual glow effect and sessionStorage fallback. Search filtering and dynamic SQL pagination replace static limits, while vanilla JS handlers provide submit debouncing and instant user feedback.

**Tech Stack:** PHP 8+, MariaDB/MySQL, Vanilla JS (Clipboard/History/Scroll APIs), CSS3 Animations, FontAwesome 6.

**Spec:** `docs/superpowers/specs/2026-10-25-automation-workflow-retention-design.md`

## Global Constraints
- Standard PHP 8+ without external framework dependencies.
- No local `php` CLI binary on the macOS PATH; run all test assertions using `python3 crm/tests/verify_suite.py all`.
- Maintain clean clickable links using `file://` scheme in all responses and reports.
- Ensure all forms have valid CSRF tokens (`crmCsrfToken()`) and prevent open redirect vulnerabilities.

## Review Focus
- Open redirect vulnerability via manipulated `return_url`: Ensure `crmAutoRedirect` strictly normalizes internal URLs starting with `index.php?page=automation-followup`.
- Missing search parameter in pagination links: Ensure pagination helper retains `search` query parameter alongside `p`, `tab`, `cfilter`, `pfilter`, and `qstatus`.
- Non-digits in phone number anchor: Ensure anchor IDs use normalized digit-only strings (e.g. `row-p-628123456789`) so CSS `#` selectors and DOM `getElementById` never fail on spaces or plus signs.
- Double form submission on slow networks: Ensure submit buttons immediately disable and display a spinner while still allowing form data submission.
- Queue table pagination truncation: Ensure `LIMIT 100` is removed and full queue count is queried dynamically.

---

### Task 1: Action Redirect & Return URL Preservation

**Files:**
- Modify: `crm/actions/automation-followup.php:18-23, 70, 84, 98, 129, 157, 166, 197, 232, 244, 280, 296, 332`
- Test: `crm/tests/automation-followup-retention-test.php`

**Interfaces:**
- Consumes: `$_POST['return_url']`, `$_POST['anchor_id']`
- Produces: `crmAutoRedirect(string $tab, ?string $returnUrl = null, ?string $anchor = null): void`

- [ ] **Step 1: Write test verifying `crmAutoRedirect` URL normalization and anchor preservation**
- [ ] **Step 2: Update `crmAutoRedirect` in `crm/actions/automation-followup.php` to accept `$returnUrl` and `$anchor`**
- [ ] **Step 3: Update all action cases in `crm/actions/automation-followup.php` to pass `$_POST['return_url']` and row anchor**
- [ ] **Step 4: Run test suite to verify action redirect behavior**
- [ ] **Step 5: Commit changes**

---

### Task 2: Unique Row Anchors, CSS Highlight Pulse, & Scroll Retention

**Files:**
- Modify: `crm/pages/automation-followup.php:498, 722, 837, 928`
- Modify: `crm/assets/css/automation-followup.css`
- Test: `crm/tests/automation-followup-retention-test.php`

**Interfaces:**
- Consumes: Prospect, rule, template, and queue item identifiers
- Produces: `id="row-p-..."`, `id="row-rule-..."`, `id="row-tpl-..."`, `id="row-queue-..."`, CSS animation `@keyframes crmRowPulse`

- [ ] **Step 1: Add row ID anchors and hidden `return_url` fields to every form across all 4 tabs**
- [ ] **Step 2: Add CSS `@keyframes crmRowPulse` and `:target` highlight rules in `automation-followup.css`**
- [ ] **Step 3: Add lightweight JS scroll memory (`sessionStorage`) restoration in `crm/pages/automation-followup.php`**
- [ ] **Step 4: Verify row anchor format and CSS highlight styling**
- [ ] **Step 5: Commit changes**

---

### Task 3: Prospect & Queue Search Bar Integration

**Files:**
- Modify: `crm/pages/automation-followup.php:42, 226, 451, 910`
- Modify: `crm/assets/css/automation-followup.css`
- Test: `crm/tests/automation-followup-retention-test.php`

**Interfaces:**
- Consumes: `$_GET['search']`
- Produces: Filtered prospect array, filtered queue SQL query, search input UI elements

- [ ] **Step 1: Add search input box in Tab Prospek and Tab Queue**
- [ ] **Step 2: Filter `$prospects` array by `search` (matching name or phone number)**
- [ ] **Step 3: Add `AND (q.nowa LIKE ? OR q.nama LIKE ?)` to Tab Queue query**
- [ ] **Step 4: Update `crmRenderPagination` calls to include `search` parameter**
- [ ] **Step 5: Verify search filtering behavior**
- [ ] **Step 6: Commit changes**

---

### Task 4: Removal of Hardcoded `LIMIT 100` on Queue & Dynamic Pagination

**Files:**
- Modify: `crm/pages/automation-followup.php:226-245`
- Test: `crm/tests/automation-followup-retention-test.php`

**Interfaces:**
- Consumes: Queue count query and pagination parameters
- Produces: Dynamic `$totalQueue`, `$totalPagesQueue`, `$pagedQueue` via SQL `LIMIT` & `OFFSET`

- [ ] **Step 1: Replace hardcoded `LIMIT 100` with dynamic `COUNT(*)` query and SQL `LIMIT offset, perPage`**
- [ ] **Step 2: Verify queue pagination correctly supports >100 records**
- [ ] **Step 3: Commit changes**

---

### Task 5: Debounce Spinner, Action Confirmations, & Empty State Reset Links

**Files:**
- Modify: `crm/pages/automation-followup.php:489, 584, 923, 1024`
- Modify: `crm/assets/css/automation-followup.css`
- Test: `crm/tests/automation-followup-retention-test.php`

**Interfaces:**
- Consumes: Action submit events, empty list conditions
- Produces: Submit spinner debounce, confirmation dialog on 6-stage scheduling, one-click reset filter link, refresh data button

- [ ] **Step 1: Add submit debounce handler to disable double-clicks and display spinner**
- [ ] **Step 2: Add confirmation dialog to "Jadwalkan Alur (6 Tahap)"**
- [ ] **Step 3: Add one-click "Reset Semua Filter" button on empty state across Tab Prospek and Tab Queue**
- [ ] **Step 4: Add "Refresh Data" button preserving current tab and filter parameters**
- [ ] **Step 5: Verify debounce and empty state buttons**
- [ ] **Step 6: Commit changes**

---

### Task 6: Automated Test Suite & Regression Verification

**Files:**
- Create: `crm/tests/automation-followup-retention-test.php`
- Modify: `crm/tests/verify_suite.py`
- Run: `python3 crm/tests/verify_suite.py all`

**Interfaces:**
- Consumes: All updated PHP and CSS assets
- Produces: Full regression test passing across all 11 suites

- [ ] **Step 1: Create test suite verifying redirect safety, row anchors, search filtering, and debounce**
- [ ] **Step 2: Register test in `crm/tests/verify_suite.py`**
- [ ] **Step 3: Run full suite with `python3 crm/tests/verify_suite.py all`**
- [ ] **Step 4: Run `graphify update .`**
- [ ] **Step 5: Final commit and push to remote**
