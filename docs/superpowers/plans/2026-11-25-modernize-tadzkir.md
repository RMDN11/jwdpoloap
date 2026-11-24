# Modernize Tadzkir (Teacher Reminder Portal) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modernize the legacy `tadzkir.php` into a high-performance, mobile-first teacher submission portal at `tadzkir.php` that inserts into `reminder_requests`, provides real-time search with chips selection, and integrates seamlessly with CRM `page=reminder-peserta`.

**Architecture:**
- `tadzkir.php`: Self-contained, responsive portal for teachers with Halaqoh selection, instant debounced search with participant multi-select chips, teacher message input, CSRF & double-submit protection, and insertion into `reminder_requests`.
- `search_peserta.php`: Standalone backward-compatible API endpoint returning JSON peserta for halaqoh searches.
- `crm/pages/reminder-peserta.php`: Add a teacher link share card with copy-to-clipboard button and direct navigation to `tadzkir.php`.
- `crm/tests/tadzkir_modern_test.py`: Automated validation suite connected to `crm/tests/verify_suite.py`.

**Tech Stack:** PHP 8+ (mysqli prepared statements), Modern CSS (ReqraWA green palette `#168044`, CSS variables, mobile-first touch UI), Vanilla JavaScript (no external runtime dependencies), Python (verification suite).

**Spec:** Modernization of legacy `archive/legacy_scripts/tadzkir.php` based on user request and existing CRM reminder workflow.

## Global Constraints

- Standalone teacher access: Does not require CRM admin session login to allow direct access by halaqoh teachers.
- Design consistency: Follows ReqraWA green aesthetic (`#168044`, `#126b39`, `#e8f7ed`, `#10231b`, clean card elevation, min 44px tap targets).
- Database safety: Use prepared statements (`mysqli_stmt`) for all halaqoh lookups, participant searches, and `reminder_requests` insertions.
- Zero broken dependencies: No reliance on Tailwind runtime CDN scripts; all styles embedded or self-contained for offline resilience and fast load.

## Review Focus

- Empty or invalid halaqoh: Show clear validation and block participant search until halaqoh is chosen.
- Duplicate selection prevention: Selecting a participant multiple times must be prevented and communicated clearly.
- Special character escaping: Names with quotes or ampersands must render safely without XSS.
- Double-submit prevention: Button disables on submit with spinner indicator.
- Empty selection guard: Submission without selecting any participant must be blocked both on client and server.

---

### Task 1: Create Modernized `tadzkir.php`

**Files:**
- Create: `/Users/macbookair/jwdpoloap/tadzkir.php`
- Consumes: `config.php`, MySQL tables `peserta`, `reminder_requests`
- Produces: Web interface at `/tadzkir.php` and AJAX search endpoint `tadzkir.php?action=search`

- [ ] **Step 1: Write PHP backend handling**
  - Read halaqoh list: `SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh != '' ORDER BY halaqoh`.
  - Handle AJAX search: `action=search`, validate `term` (min 2 chars) and `halaqoh`, query `SELECT id, nama_lengkap, nowa FROM peserta WHERE nama_lengkap LIKE ? AND halaqoh = ? ORDER BY nama_lengkap ASC LIMIT 20`, return JSON.
  - Handle POST submission: validate `halaqoh`, `selected_peserta` array, sanitize `pesan_pengajar`, insert into `reminder_requests (halaqoh, peserta_id, peserta_nama, peserta_nowa, pesan_pengajar, status)` with `status = 'menunggu'`.
  - Provide session flash messages for success/error with Post-Redirect-Get (PRG) pattern.

- [ ] **Step 2: Build modern mobile-first UI & CSS**
  - Header with JWD branding, clean title ("Form Tadzkir Pengajar"), and subtitle explaining the purpose.
  - Halaqoh selection with custom styled select and clear placeholder.
  - Search input with clear button, debounced typeahead search, and suggestion dropdown.
  - Selected participants chip list with count badge, remove button per participant, and "Hapus Semua" action.
  - Optional note textarea with character counter.
  - Submit button with icon and disabled state until at least 1 participant is selected.
  - Responsive layout optimized for smartphones (360px+) and desktop.

- [ ] **Step 3: Test PHP syntax**
  Run: `php -l /Users/macbookair/jwdpoloap/tadzkir.php`
  Expected: `No syntax errors detected`

---

### Task 2: Create Compatibility Endpoint `search_peserta.php`

**Files:**
- Create: `/Users/macbookair/jwdpoloap/search_peserta.php`
- Consumes: `config.php`, `peserta` table
- Produces: JSON response `[{"id": 1, "nama_lengkap": "...", "nowa": "..."}]`

- [ ] **Step 1: Implement endpoint**
  - Read `term` and `halaqoh` from `$_GET`.
  - Return JSON empty array `[]` if input length < 2 or empty halaqoh.
  - Execute prepared statement and return JSON encoded participant list with `Content-Type: application/json; charset=utf-8`.

- [ ] **Step 2: Verify syntax**
  Run: `php -l /Users/macbookair/jwdpoloap/search_peserta.php`
  Expected: `No syntax errors detected`

---

### Task 3: Integrate Share Link in CRM `page=reminder-peserta`

**Files:**
- Modify: `/Users/macbookair/jwdpoloap/crm/pages/reminder-peserta.php`
- Consumes: `tadzkir.php`
- Produces: Action button / share banner in Pengingat Peserta page

- [ ] **Step 1: Add Teacher Form Share Card/Button**
  - In `crm/pages/reminder-peserta.php`, add a modern action button in the header/bulk bar: "Buka Form Tadzkir" linking to `../tadzkir.php` (target `_blank`).
  - Add a "Salin Link Form Pengajar" button with automatic clipboard copy and tooltip feedback.

- [ ] **Step 2: Verify PHP syntax**
  Run: `php -l /Users/macbookair/jwdpoloap/crm/pages/reminder-peserta.php`
  Expected: `No syntax errors detected`

---

### Task 4: Automated Verification Test Suite

**Files:**
- Create: `/Users/macbookair/jwdpoloap/crm/tests/tadzkir_modern_test.py`
- Modify: `/Users/macbookair/jwdpoloap/crm/tests/verify_suite.py`

- [ ] **Step 1: Write `tadzkir_modern_test.py`**
  - Verify `tadzkir.php` and `search_peserta.php` exist and pass `php -l`.
  - Verify `tadzkir.php` contains prepared statement insertions for `reminder_requests`.
  - Verify `tadzkir.php` contains CSRF or session guard and halaqoh validation.
  - Verify modern UX components: search debounce, chips display, counter badge, mobile responsiveness CSS.
  - Verify `crm/pages/reminder-peserta.php` contains the teacher portal link.

- [ ] **Step 2: Register test in `crm/tests/verify_suite.py`**
  - Add `tadzkir_modern_test.run_all(base_dir)` to `verify_suite.py`.

- [ ] **Step 3: Run test suite**
  Run: `python3 /Users/macbookair/jwdpoloap/crm/tests/verify_suite.py`
  Expected: All tests pass including `tadzkir_modern_test`.
