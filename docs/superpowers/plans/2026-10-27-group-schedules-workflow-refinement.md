# Group Schedules Workflow Refinement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

This plan implements enhanced workflow management for WhatsApp group broadcast schedules in `page=group` and `page=group-schedules`:
1. **`page=group` Active Schedules Deletion**: Allow cancelling all halaqoh registered in a scheduled broadcast at once instead of one by one.
2. **`page=group-schedules` Categorization by Message & Total Halaqoh**: Group schedules by message content and total halaqoh, allow cancelling all halaqoh in a batch, provide an expandable detail view to see the full list of halaqoh, and provide inline/modal editing for the message content.

---

## File Structure

- **Modified Files**:
  - `crm/actions/group-schedules.php`: Add `cancel_batch` and `edit_message` actions, dynamic redirect to `return_page`, and flash notifications.
  - `crm/pages/group.php`: Include `id_jadwal_list` in active schedules query and render a batch cancellation action for each active schedule.
  - `crm/pages/group-schedules.php`: Reorganize schedule listing to group by message content & halaqoh count, add batch cancel, detail view for all halaqoh, and message editing modal.
  - `crm/assets/css/group-schedules.css`: Add styles for schedule card actions (edit, detail, cancel), expandable halaqoh list, and edit modal.
  - `crm/assets/css/group-mobile.css`: Add styles for the delete action on `page=group` active schedule rows.
  - `crm/tests/verify_suite.py`: Hook group schedules workflow test into the master test runner.
- **New Files**:
  - `crm/tests/group_schedules_workflow_test.py`: Comprehensive test suite testing actions, UI elements, audit assertions, and regression safety.

---

## Tasks

### Task 1: Action Handlers for Batch Cancellation and Message Editing

**Files**:
- Modify: `crm/actions/group-schedules.php`

- [ ] Support `cancel_batch` action:
  - Extract comma-separated IDs from `$_POST['id_list']`.
  - Sanitize to integers (`intval`).
  - Delete matching pending rows: `DELETE FROM jadwal_pesan_grup WHERE id IN (...) AND status = 'pending'`.
  - Set flash message with count of affected rows.
- [ ] Support `edit_message` action:
  - Extract comma-separated IDs from `$_POST['id_list']` and string from `$_POST['pesan']`.
  - Validate message is not empty.
  - Update matching pending rows: `UPDATE jadwal_pesan_grup SET pesan = ? WHERE id IN (...) AND status = 'pending'`.
  - Set flash message with count of updated rows.
- [ ] Support dynamic redirection:
  - Check `$_POST['return_page']` (`'group'` vs `'group-schedules'`).
  - Preserve `status` and `jenis` query parameters when redirecting to `group-schedules`.
- [ ] Maintain backward compatibility:
  - Preserve existing single-id `action === 'cancel'` handler.

---

### Task 2: Batch Cancellation in `page=group` Active Schedules

**Files**:
- Modify: `crm/pages/group.php`
- Modify: `crm/assets/css/group-mobile.css`
- Modify: `crm/assets/css/app.css`

- [ ] Update `$jadwalQuery` in `crm/pages/group.php`:
  - Add `GROUP_CONCAT(DISTINCT j.id SEPARATOR ',') AS id_jadwal_list`.
- [ ] Update schedule item markup in `crm/pages/group.php`:
  - Inside each `.group-schedule-item`, add a form submitting `cancel_batch` with `id_list` and `return_page = 'group'`.
  - Add a trash/delete button with confirmation: `"Hapus/batalkan jadwal untuk SEMUA X grup ini?"`.
- [ ] Add CSS styling in `crm/assets/css/app.css` and `crm/assets/css/group-mobile.css` for `.group-schedule-row-cancel`:
  - Clean button styling matching the CRM theme (red accent, hover state, mobile friendly).

---

### Task 3: Message-Based Grouping, Batch Cancel, Detail View & Edit Modal in `page=group-schedules`

**Files**:
- Modify: `crm/pages/group-schedules.php`
- Modify: `crm/assets/css/group-schedules.css`

- [ ] Reorganize schedule grouping in `crm/pages/group-schedules.php`:
  - Fetch rows with group info and group them by message + schedule settings in PHP.
  - Calculate total halaqoh count, pending count, sent count, and categories for each message group.
- [ ] Render grouped schedule cards:
  - Header: Total halaqoh badge (e.g. `14 Halaqoh`), Categories involved, Schedule time badge, and Status badge.
  - Message preview box with quotes/formatted text.
  - Media badge if image is attached.
  - Action buttons:
    - **Detail Halaqoh**: toggles the full list of halaqoh.
    - **Edit Pesan**: opens edit message modal.
    - **Batalkan Jadwal**: submits `cancel_batch` for all halaqoh in this schedule group.
- [ ] Implement Expandable Detail Accordion:
  - Show full list of halaqoh: ID, Group Name, Category, Status, Last Sent date.
  - Individual cancel button for single halaqoh if desired.
- [ ] Implement Edit Message Modal:
  - Modal with textarea prefilled with the message.
  - Hidden inputs: `csrf`, `action="edit_message"`, `id_list`, `return_page="group-schedules"`, `status`, `jenis`.
  - Form validation: ensures message is not empty.
- [ ] Update CSS in `crm/assets/css/group-schedules.css`:
  - Styles for batch action buttons, detail accordion, halaqoh list chips, and edit modal.

---

### Task 4: Test Suite & Verification

**Files**:
- Create: `crm/tests/group_schedules_workflow_test.py`
- Modify: `crm/tests/verify_suite.py`

- [ ] Create `crm/tests/group_schedules_workflow_test.py`:
  - Test batch cancel and edit message actions in `actions/group-schedules.php`.
  - Test batch delete button presence in `pages/group.php`.
  - Test message grouping, total halaqoh display, batch cancel, detail view, and edit modal in `pages/group-schedules.php`.
  - Ensure compatibility with existing `group_schedule_audit_test.php` tokens (Jakarta timezone, DateTimeZone, no MySQL CURTIME).
- [ ] Hook test into `crm/tests/verify_suite.py`.
- [ ] Run test suite with `python3 crm/tests/verify_suite.py all` and verify 100% PASS.
- [ ] Run existing PHP tests `php crm/tests/group_page_test.php` and `php crm/tests/group_schedule_audit_test.php` (or Python equivalents if PHP CLI unavailable).

---

### Task 5: Final Review, Commit, Push & Graphify Update

- [ ] Review git diff for clean code and adherence to standards.
- [ ] Commit with descriptive message: `feat(group): add batch schedule cancellation, message grouping, detail view, and message editing`.
- [ ] Push to `origin main`.
- [ ] Run `graphify update .`.
