#!/usr/bin/env python3
"""
Test suite for WhatsApp Group Schedules Workflow:
- Batch schedule cancellation in page=group
- Message & halaqoh count grouping in page=group-schedules
- Batch cancellation by message
- Expandable halaqoh detail view
- Message edit modal and action
"""
import sys
import os

def get_base_dir():
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

def test_action_handlers(base_dir):
    act_path = os.path.join(base_dir, "crm/actions/group-schedules.php")
    if not os.path.exists(act_path):
        raise AssertionError("crm/actions/group-schedules.php does not exist")

    with open(act_path, "r", encoding="utf-8") as f:
        content = f.read()

    # CSRF check
    if "crmVerifyCsrf" not in content:
        raise AssertionError("Missing crmVerifyCsrf verification in actions/group-schedules.php")

    # cancel_batch
    if "cancel_batch" not in content:
        raise AssertionError("Missing cancel_batch action in actions/group-schedules.php")
    if "DELETE FROM jadwal_pesan_grup WHERE id IN" not in content:
        raise AssertionError("cancel_batch must perform batch DELETE with IN (?, ?, ...)")

    # edit_message / edit_schedule
    if "edit_message" not in content and "edit_schedule" not in content:
        raise AssertionError("Missing edit_message/edit_schedule action in actions/group-schedules.php")
    if "UPDATE jadwal_pesan_grup SET pesan = ?" not in content:
        raise AssertionError("edit_message/edit_schedule must perform batch UPDATE with IN (?, ?, ...)")
    if "tipe_jadwal = 'sekali'" not in content or "tipe_jadwal = 'harian'" not in content:
        raise AssertionError("actions/group-schedules.php must support updating schedule timing (sekali/harian)")

    # return_page routing
    if "return_page" not in content or "page=group" not in content:
        raise AssertionError("actions/group-schedules.php must support dynamic redirection to return_page")

    print("test_action_handlers: OK")

def test_group_page_active_schedules(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/group.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    # id_jadwal_list in query
    if "id_jadwal_list" not in content:
        raise AssertionError("crm/pages/group.php query must select id_jadwal_list via GROUP_CONCAT")

    # cancel_batch form
    if 'value="cancel_batch"' not in content:
        raise AssertionError("crm/pages/group.php must provide cancel_batch form on active schedule items")
    if 'name="return_page" value="group"' not in content and 'name="return_page"' not in content:
        raise AssertionError("crm/pages/group.php cancel_batch must pass return_page")

    if "group-schedule-row-cancel" not in content:
        raise AssertionError("crm/pages/group.php must render cancel button .group-schedule-row-cancel")

    # reuse query param handling
    if "reuse" not in content or "crmGroupMessage" not in content:
        raise AssertionError("crm/pages/group.php must support pre-filling crmGroupMessage from reuse parameter")

    print("test_group_page_active_schedules: OK")

def test_group_schedules_page_features(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/group-schedules.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    # Grouping by message
    if "$campaigns" not in content:
        raise AssertionError("crm/pages/group-schedules.php must group schedules into $campaigns")

    # Total halaqoh display
    if "Halaqoh" not in content:
        raise AssertionError("crm/pages/group-schedules.php must display total halaqoh count per message")

    # Batch cancel form
    if 'value="cancel_batch"' not in content:
        raise AssertionError("crm/pages/group-schedules.php must provide batch cancel button for each message group")

    # Detail expandable view
    if "group-schedule-detail-panel" not in content:
        raise AssertionError("crm/pages/group-schedules.php must render .group-schedule-detail-panel")
    if "toggleScheduleDetail" not in content:
        raise AssertionError("crm/pages/group-schedules.php must provide toggleScheduleDetail() function")

    # Edit message & schedule modal
    if "groupEditScheduleModal" not in content:
        raise AssertionError("crm/pages/group-schedules.php must render edit modal (#groupEditScheduleModal)")
    if 'value="edit_schedule"' not in content and 'value="edit_message"' not in content:
        raise AssertionError("crm/pages/group-schedules.php edit modal form must submit edit action")
    if 'name="tipe_jadwal"' not in content or 'name="waktu_jadwal"' not in content or 'name="jam_harian"' not in content:
        raise AssertionError("crm/pages/group-schedules.php edit modal must include schedule timing inputs")

    # History column: Gunakan Lagi
    if "group-schedules-history-card" not in content:
        raise AssertionError("crm/pages/group-schedules.php must render history column .group-schedules-history-card")
    if "Gunakan Lagi" not in content:
        raise AssertionError("crm/pages/group-schedules.php must provide 'Gunakan Lagi' buttons")
    if "?page=group&reuse=" not in content:
        raise AssertionError("crm/pages/group-schedules.php Gunakan Lagi must link to ?page=group&reuse=")

    # Critical audit tokens preservation
    audit_tokens = [
        "new DateTimeZone('Asia/Jakarta')",
        "tipe_jadwal IN ('sekali','sekarang')",
        "tipe_jadwal='sekarang' OR jadwal_kirim",
        "$routineDueToday > 0 ? (int)round(min(1, $routineSentToday / $routineDueToday) * 100) : 0"
    ]
    for token in audit_tokens:
        if token not in content:
            raise AssertionError(f"Audit token missing in crm/pages/group-schedules.php: {token}")

    print("test_group_schedules_page_features: OK")

def test_css_styles(base_dir):
    sched_css = os.path.join(base_dir, "crm/assets/css/group-schedules.css")
    with open(sched_css, "r", encoding="utf-8") as f:
        content = f.read()

    selectors = [
        ".group-schedule-campaign-card",
        ".group-schedule-halaqoh-chip",
        ".group-schedule-cancel-batch",
        ".group-schedule-detail-panel",
        ".group-edit-modal-card",
        ".group-schedules-layout-grid",
        ".group-schedules-history-card",
        ".group-history-use-btn",
        ".btn-reuse"
    ]
    for sel in selectors:
        if sel not in content:
            raise AssertionError(f"Missing CSS selector: {sel} in group-schedules.css")

    app_css = os.path.join(base_dir, "crm/assets/css/app.css")
    with open(app_css, "r", encoding="utf-8") as f:
        app_content = f.read()

    if ".group-schedule-row-cancel" not in app_content:
        raise AssertionError("Missing .group-schedule-row-cancel in app.css")

    print("test_css_styles: OK")

def test_legacy_audit_checks(base_dir):
    index_path = os.path.join(base_dir, "crm/index.php")
    home_path = os.path.join(base_dir, "crm/pages/home.php")
    group_path = os.path.join(base_dir, "crm/pages/group.php")
    group_schedules_path = os.path.join(base_dir, "crm/pages/group-schedules.php")
    app_css_path = os.path.join(base_dir, "crm/assets/css/app.css")
    cron_path = os.path.join(base_dir, "cron_grup.php")

    with open(index_path, "r", encoding="utf-8") as f: index = f.read()
    with open(home_path, "r", encoding="utf-8") as f: home = f.read()
    with open(group_path, "r", encoding="utf-8") as f: group = f.read()
    with open(group_schedules_path, "r", encoding="utf-8") as f: page = f.read()
    with open(app_css_path, "r", encoding="utf-8") as f: css = f.read()
    with open(cron_path, "r", encoding="utf-8") as f: cron = f.read()

    # group_page_test.php checks
    assert "'group'" in index, "router allows group page"
    assert "crmGroupForm" in group and "crmGroupMessage" in group, "group page has composer"
    assert "crm-group-target" in group, "group page has target selector"
    assert "../kirimgrup.php" in group and "ajax_kirim_grup" in group, "group page uses legacy sending engine endpoint"
    assert "groupSchedule" in group and "harian" in group, "group page has scheduling modes"
    assert "crmGroupPreview" in group, "group page has preview"
    assert ".group-compose-grid" in css and ".group-phone" in css, "group page has compact styles"
    assert ".group-message-history-list" in css and ".group-history-list" in css and "overflow-y:auto" in css, "group history has bounded scroll areas"
    assert ".group-message-history-card" in css and "max-height:420px" in css, "group reuse card is compact"
    assert "'group-schedules'" in index, "group schedule route exists"
    assert "?page=group-schedules" in group, "full schedule link stays inside CRM"

    # group_schedule_audit_test.php checks
    assert "'group-schedules'" in index, "schedule page route is allow-listed"
    assert "new DateTimeZone('Asia/Jakarta')" in page, "progress uses explicit Jakarta application timezone"
    assert "CURTIME()" not in page and "NOW()" not in page, "progress does not depend on MySQL CURTIME or NOW timezone"
    assert "tipe_jadwal IN ('sekali','sekarang')" in page and ("tipe_jadwal='sekarang' OR jadwal_kirim" in page), "overdue once and immediate pending schedules are counted"
    assert "$routineDueToday > 0 ? (int)round(min(1, $routineSentToday / $routineDueToday) * 100) : 0" in page, "routine progress does not claim 100 percent when none is due"
    assert "SET terakhir_dikirim = ? WHERE id = ?" in cron and "'catat kiriman rutin'" in cron, "routine schedule remains active after send"
    assert "SET status = 'terkirim', terakhir_dikirim = ? WHERE id = ?" in cron and "'tandai terkirim sekali'" in cron, "one-time schedule gets terminal sent status"
    assert "ERROR UPDATE jadwal ID" in cron and "affected_rows=" in cron, "cron checks and logs schedule update execution"
    assert "updateScheduleDateRow($conn" in cron and "CURDATE()" not in cron, "cron records sent date using application date rather than MySQL CURDATE"

    print("test_legacy_audit_checks: OK")

def main():
    base_dir = get_base_dir()
    test_action_handlers(base_dir)
    test_group_page_active_schedules(base_dir)
    test_group_schedules_page_features(base_dir)
    test_css_styles(base_dir)
    test_legacy_audit_checks(base_dir)
    print("\nALL GROUP SCHEDULES WORKFLOW TESTS PASSED!")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"\nTEST FAILED: {e}", file=sys.stderr)
        sys.exit(1)
