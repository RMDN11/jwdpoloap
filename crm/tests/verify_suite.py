#!/usr/bin/env python3
"""
Test runner for jwdpoloap CRM PHP test suites.
Translates static assertions and token/rule checks in PHP test files into Python execution.
"""
import sys
import os
import re

def run_db_test(base_dir):
    config_path = os.path.join(base_dir, "crm/config/automation-followup.php")
    if not os.path.exists(config_path):
        raise AssertionError(f"Missing config file: {config_path}")
    with open(config_path, "r", encoding="utf-8") as f:
        content = f.read()

    required_functions = [
        'crmAutomationEnsureTables',
        'crmAutomationRegisterQueue',
        'crmAutomationHasUserReplied',
        'crmAutomationIsBlocked',
        'crmAutomationGetActiveTriggers',
        'crmAutomationFindMatchingTrigger',
    ]
    for func in required_functions:
        if f"function {func}" not in content:
            raise AssertionError(f"Missing required function: {func}")

    required_tables = [
        'crm_automation_rules',
        'crm_automation_templates',
        'crm_automation_queue',
    ]
    for table in required_tables:
        if f"CREATE TABLE IF NOT EXISTS {table}" not in content:
            raise AssertionError(f"Missing table declaration: {table}")

    required_tokens = [
        'idx_auto_queue_status_sched',
        'cancelled_replied',
        'crmProspectNormalizeNumber',
        'bind_param',
        'category',
        'trigger_source',
        'Kak, saya ingin daftar Tahfidz Cilik',
        'Kak, saya ingin daftar Kelas Menghafal Pemula',
        'Kak, saya ingin daftar Kelas Menghafal Lanjutan',
        'Kak, saya ingin daftar Kelas Penguatan Hafalan',
    ]
    for token in required_tokens:
        if token not in content:
            raise AssertionError(f"Missing expected schema/logic token: {token}")

    print("automation-followup-db-test: OK")

def run_trigger_test(base_dir):
    config_path = os.path.join(base_dir, "crm/config/automation-followup.php")
    webhook_path = os.path.join(base_dir, "webhook.php")

    if not os.path.exists(config_path) or not os.path.exists(webhook_path):
        raise AssertionError("Missing config or webhook file.")

    with open(config_path, "r", encoding="utf-8") as f:
        config_content = f.read()
    with open(webhook_path, "r", encoding="utf-8") as f:
        webhook_content = f.read()

    if "crmAutomationHandleIncomingWebhook" not in webhook_content:
        raise AssertionError("webhook.php missing crmAutomationHandleIncomingWebhook call.")

    required_config_tokens = [
        'function crmAutomationHandleIncomingWebhook',
        'crmAutomationIsOperationalNumber',
        'crmAutomationIsBlocked',
        'crmAutomationHasReceivedPayment',
        'crmAutomationFindMatchingTrigger',
        'crmAutomationScheduleSequence',
        'auto_webhook',
    ]

    for token in required_config_tokens:
        if token not in config_content:
            raise AssertionError(f"automation-followup.php missing required webhook trigger token: {token}")

    print("automation-followup-trigger-test: OK")

def run_actions_test(base_dir):
    actions_path = os.path.join(base_dir, "crm/actions/automation-followup.php")
    if not os.path.exists(actions_path):
        raise AssertionError("Missing actions file.")
    with open(actions_path, "r", encoding="utf-8") as f:
        content = f.read()

    required_tokens = [
        'crmVerifyCsrf',
        'save_rule',
        'toggle_rule',
        'delete_rule',
        'save_template',
        'delete_template',
        'cancel_queue',
        'send_now',
        'queue_prospect',
        'send_prospect_now',
        'crm_flash',
        'category',
        'Location: index.php?page=automation-followup',
    ]
    for token in required_tokens:
        if token not in content:
            raise AssertionError(f"Missing required action token: {token}")

    print("automation-followup-actions-test: OK")

def run_ui_test(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    css_path = os.path.join(base_dir, "crm/assets/css/automation-followup.css")

    if not os.path.exists(page_path) or not os.path.exists(css_path):
        raise AssertionError("Missing UI page or CSS file.")

    with open(page_path, "r", encoding="utf-8") as f:
        page_content = f.read()

    required_tokens = [
        'Automation Follow Up',
        'tab=prospects',
        'tab=rules',
        'tab=templates',
        'tab=queue',
        'crm_automation_rules',
        'crm_automation_templates',
        'crm_automation_queue',
        'crmCsrfToken',
        'Pola Pemicu (Rules)',
        'Terjadwal (Otomatis)',
        'trigger_source',
        'Jadwalkan Alur (6 Tahap)',
    ]

    for token in required_tokens:
        if token not in page_content:
            raise AssertionError(f"automation-followup.php missing required token: {token}")

    print("automation-followup-ui-test: OK")

def run_e2e_test(base_dir):
    webhook_file = os.path.join(base_dir, "webhook.php")
    config_file  = os.path.join(base_dir, "crm/config/automation-followup.php")
    actions_file = os.path.join(base_dir, "crm/actions/automation-followup.php")
    page_file    = os.path.join(base_dir, "crm/pages/automation-followup.php")

    for fpath in [webhook_file, config_file, actions_file, page_file]:
        if not os.path.exists(fpath):
            raise AssertionError(f"Missing file for E2E test: {fpath}")

    with open(webhook_file, "r", encoding="utf-8") as f:
        webhook = f.read()
    with open(config_file, "r", encoding="utf-8") as f:
        config = f.read()
    with open(actions_file, "r", encoding="utf-8") as f:
        actions = f.read()
    with open(page_file, "r", encoding="utf-8") as f:
        page = f.read()

    # 1. Webhook wiring
    assert 'crmAutomationHandleIncomingWebhook' in webhook, 'webhook.php must call crmAutomationHandleIncomingWebhook'
    assert 'crm/config/automation-followup.php' in webhook, 'webhook.php must require automation-followup.php'

    # 2. Config helper & schema
    assert 'function crmAutomationHandleIncomingWebhook' in config, 'config must implement crmAutomationHandleIncomingWebhook'
    assert 'function crmAutomationGetActiveTriggers' in config, 'config must implement crmAutomationGetActiveTriggers'
    assert 'function crmAutomationFindMatchingTrigger' in config, 'config must implement crmAutomationFindMatchingTrigger'
    assert 'category VARCHAR(50)' in config, 'config must define category column in rules table'
    assert 'trigger_source VARCHAR(30)' in config, 'config must define trigger_source column in queue table'

    # 3. LP Button triggers check
    lp_triggers = [
        'Kak, saya ingin daftar Tahfidz Cilik',
        'Tahfidz Cilik',
        'Kak, saya ingin daftar Kelas Menghafal Pemula',
        'Menghafal Pemula',
        'Kak, saya ingin daftar Kelas Menghafal Lanjutan',
        'Menghafal Lanjutan',
        'Kak, saya ingin daftar Kelas Penguatan Hafalan',
        'Penguatan Hafalan',
        'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada',
        'Bismillah, perkenalkan nama saya',
    ]
    for trig in lp_triggers:
        assert trig in config, f"config must seed default LP trigger: {trig}"

    # 4. Guardrails verification
    assert 'crmAutomationIsOperationalNumber' in config, 'config must check operational CS numbers'
    assert 'crmChatIsTeacherNumber' in config, 'config must exclude teacher contacts'
    assert 'crmAutomationIsBlocked' in config, 'config must exclude blocked contacts'
    assert 'crmAutomationHasReceivedPayment' in config, 'config must exclude contacts who already paid/finance'

    # 5. Actions CRUD
    assert 'save_rule' in actions, 'actions must handle save_rule'
    assert 'delete_rule' in actions, 'actions must handle delete_rule'
    assert 'toggle_rule' in actions, 'actions must handle toggle_rule'
    assert 'category' in actions, 'actions must save category in save_rule'

    # 6. UI & Prospects distinction
    assert 'Terjadwal (Otomatis)' in page, 'page must render Terjadwal (Otomatis) badge'
    assert 'Jadwalkan Alur (6 Tahap)' in page, 'page must maintain manual Jadwalkan Alur button for legacy prospects'
    assert 'Pola Pemicu (Rules)' in page, 'page must display Pola Pemicu in tab title'
    assert 'editRule' in page, 'page must provide editRule javascript function'
    assert "ORDER BY (status = 'pending') DESC, scheduled_at ASC, id ASC" in page, 'page must sort pending queues by earliest scheduled_at first'

    # 7. Review fix verifications
    auto_reply_path = os.path.join(base_dir, 'auto_reply_engine.php')
    with open(auto_reply_path, 'r', encoding='utf-8') as f:
        auto_reply_content = f.read()
    assert 'crmAutomationRegisterQueue' not in auto_reply_content, 'auto_reply_engine.php must not call crmAutomationRegisterQueue (webhook handles enrollment)'
    assert 'crm_automation_meta' in config, 'config must use crm_automation_meta to avoid re-seeding deleted rules'
    assert 'seed_rules_v1' in config, 'config must check seed_rules_v1 flag'
    assert "r.name LIKE '%Cilik%'" not in config, 'Cleanup 3 must not cancel pending queues based on r.name'

    print("automation-followup-webhook-e2e-test: OK")

def run_cleanup_test(base_dir):
    config_path = os.path.join(base_dir, "crm/config/automation-followup.php")
    with open(config_path, "r", encoding="utf-8") as f:
        cfg = f.read()

    assert "$ruleName = 'Tahfidz Private - ' . $tpl['name']" not in cfg, 'Config still seeds legacy Tahfidz Private - Follow Up rules!'
    assert "DELETE FROM crm_automation_rules WHERE name LIKE 'Tahfidz Private - Follow Up %'" in cfg, 'Missing DELETE query'
    assert 'legacy_fu_rules_cleaned_v1' in cfg, 'Missing migration flag'
    print("automation-followup-cleanup-test: OK")

def run_prospect_filter_test(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    with open(page_path, "r", encoding="utf-8") as f:
        page = f.read()

    assert 'crmAutomationFindMatchingTrigger($conn' in page, 'Page does not validate prospects using crmAutomationFindMatchingTrigger'
    assert ('!$qData && !$matchedRule' in page or '!$matchedRule && !$qData' in page), 'Page does not skip prospects with no matching rule and no queue'
    print("automation-followup-prospect-filter-test: OK")

def run_pagination_test(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    css_path = os.path.join(base_dir, "crm/assets/css/automation-followup.css")
    with open(page_path, "r", encoding="utf-8") as f:
        page = f.read()
    with open(css_path, "r", encoding="utf-8") as f:
        css = f.read()

    assert '$perPage = 15' in page, 'Page does not define $perPage = 15'
    assert 'crmRenderPagination' in page, 'Page does not define crmRenderPagination helper'
    assert 'crm-pagination' in page, 'Page does not include crm-pagination class'
    assert '.crm-pagination' in css, 'CSS missing .crm-pagination styling'
    print("automation-followup-pagination-test: OK")

def run_copy_wa_test(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    css_path = os.path.join(base_dir, "crm/assets/css/automation-followup.css")
    with open(page_path, "r", encoding="utf-8") as f:
        page = f.read()
    with open(css_path, "r", encoding="utf-8") as f:
        css = f.read()

    assert 'class="btn-copy-wa"' in page, 'Page missing btn-copy-wa'
    assert 'https://wa.me/' in page, 'Page missing wa.me link'
    assert 'function crmCopyWaNumber(' in page, 'Page missing crmCopyWaNumber'
    assert '.btn-copy-wa' in css, 'CSS missing .btn-copy-wa styling'
    print("automation-followup-copy-wa-test: OK")

def run_review_fixes_test(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    config_path = os.path.join(base_dir, "crm/config/automation-followup.php")
    with open(page_path, "r", encoding="utf-8") as f:
        page = f.read()
    with open(config_path, "r", encoding="utf-8") as f:
        config = f.read()

    assert "'cfilter' => $cfilter" in page, 'Missing cfilter in pagination'
    assert "$_GET['cfilter'] ?? $_GET['cat']" in page, 'Missing fallback'
    assert 'https://wa.me/<?= htmlspecialchars(crmProspectNormalizeNumber(' in page, 'Missing normalized wa.me'
    assert "SHOW TABLES LIKE 'crm_automation_rules'" in config, 'Missing table existence check'
    assert '$cachedTriggers' in config, 'Missing cachedTriggers'
    assert "btn.classList.contains('copied')" in page, 'Missing double-click guard'
    print("automation-followup-review-fixes-test: OK")

def run_retention_test(base_dir):
    actions_path = os.path.join(base_dir, "crm/actions/automation-followup.php")
    pages_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    css_path = os.path.join(base_dir, "crm/assets/css/automation-followup.css")

    with open(actions_path, "r", encoding="utf-8") as f:
        actions = f.read()
    with open(pages_path, "r", encoding="utf-8") as f:
        page = f.read()
    with open(css_path, "r", encoding="utf-8") as f:
        css = f.read()

    # 1. crmAutoRedirect signature in actions
    if not re.search(r'function\s+crmAutoRedirect\s*\(\s*string\s+\$tab\s*=\s*[\'"](?:rules|prospects)[\'"],\s*\?string\s+\$returnUrl\s*=\s*null,\s*\?string\s+\$anchor\s*=\s*null\s*\)', actions):
        raise AssertionError("crmAutoRedirect must accept optional $returnUrl and $anchor parameters")

    # 2. return_url handling
    if "return_url" not in actions or "$returnUrl" not in actions:
        raise AssertionError("crmAutoRedirect must support return_url redirection")

    # 3. row anchors in actions
    for param in ["row-p-", "row-rule-", "row-queue-", "row-tpl-"]:
        if param not in actions:
            raise AssertionError(f"Missing row anchor handling for: {param}")

    # 4. row IDs in pages
    for row_id in ['id="row-p-', 'id="row-rule-', 'id="row-tpl-', 'id="row-queue-']:
        if row_id not in page:
            raise AssertionError(f"Missing row ID anchor in pages: {row_id}")

    # 5. hidden return_url in pages
    if 'name="return_url"' not in page:
        raise AssertionError("Missing hidden input return_url in pages")

    # 6. CSS animation for row pulse
    if "crmRowPulse" not in css or ":target" not in css:
        raise AssertionError("Missing CSS animation crmRowPulse or :target in automation-followup.css")

    # 7. Search in pages
    if 'name="search"' not in page or "$search" not in page:
        raise AssertionError("Missing search filter integration in automation-followup.php")

    # 8. LIMIT 100 removed
    if re.search(r'FROM\s+crm_automation_queue[^\;]+LIMIT\s+100', page, re.IGNORECASE):
        raise AssertionError("Hardcoded LIMIT 100 on crm_automation_queue query must be removed")

    print("automation-followup-retention-test: OK")

def run_queue_bug_test(base_dir):
    pages_path = os.path.join(base_dir, "crm/pages/automation-followup.php")
    with open(pages_path, "r", encoding="utf-8") as f:
        page = f.read()

    per_page_pos = page.find("$perPage =")
    queue_pos = page.find("if ($tab === 'queue')")

    if per_page_pos == -1:
        raise AssertionError("Missing $perPage definition")
    if queue_pos == -1:
        raise AssertionError("Missing queue tab query block")
    if per_page_pos > queue_pos:
        raise AssertionError(f"$perPage is defined at pos {per_page_pos} AFTER queue query at pos {queue_pos}, causing DivisionByZeroError!")

    # Verify Tab Queue has contact avatar
    if 'id="row-queue-' in page and 'crm-avatar' not in page[page.find('id="row-queue-'):]:
        raise AssertionError("Tab Queue table rows missing crm-avatar styling")

    print("automation-followup-queue-bug-test: OK")

def run_sequence_name_test(base_dir: str):
    cfg_path = os.path.join(base_dir, 'crm/config/automation-followup.php')
    with open(cfg_path, 'r', encoding='utf-8') as f:
        cfg = f.read()

    # 1. Deduplication per template_id
    if "WHERE nowa = ? AND template_id = ? AND status = 'pending'" not in cfg:
        raise AssertionError("crmAutomationRegisterQueue must deduplicate by nowa and template_id only")
    if "(template_id = ? OR (rule_id = ? AND rule_id > 0))" in cfg:
        raise AssertionError("crmAutomationRegisterQueue must NOT overwrite across different templates via rule_id")

    # 2. Sequence resolution flexible matching
    if "%Follow Up ' . $num" not in cfg and "Follow Up {$num}" not in cfg and "CONCAT" not in cfg:
        raise AssertionError("Sequence resolution must match both prefixed and unprefixed Follow Up templates")

    # 3. Explicit name extraction & smart rendering
    if "crmAutomationExtractExplicitName" not in cfg:
        raise AssertionError("crmAutomationExtractExplicitName must be implemented in automation-followup.php")

    # 4. Tab 4 chronological sort order
    page_path = os.path.join(base_dir, 'crm/pages/automation-followup.php')
    with open(page_path, 'r', encoding='utf-8') as f:
        page = f.read()
    if "(q.status = 'pending') DESC, q.scheduled_at ASC" not in page:
        raise AssertionError("Tab 4 Queue table must sort pending items chronologically by scheduled_at ASC")

    print("automation-followup-sequence-name-test: OK")

def run_finance_scheduling_test(base_dir: str):
    cfg_path = os.path.join(base_dir, 'crm/config/automation-followup.php')
    with open(cfg_path, 'r', encoding='utf-8') as f:
        cfg = f.read()

    # 1. SQL query syntax check: no raw dot concatenation in string
    if "' . $num . '" in cfg or "t.name LIKE 'Follow Up ' ." in cfg:
        raise AssertionError("SQL query string has broken LIKE clause with raw dot concatenation")

    # 2. Finance helper and table
    if "crmAutomationSetFinanceStatus" not in cfg:
        raise AssertionError("Function crmAutomationSetFinanceStatus must exist in config")
    if "crm_automation_finance" not in cfg:
        raise AssertionError("Config must manage crm_automation_finance table")

    # 3. Actions file
    act_path = os.path.join(base_dir, 'crm/actions/automation-followup.php')
    with open(act_path, 'r', encoding='utf-8') as f:
        act = f.read()
    if "toggle_finance_status" not in act and "set_finance_status" not in act:
        raise AssertionError("Actions must handle toggle_finance_status")
    if "crmAutomationSetFinanceStatus" not in act:
        raise AssertionError("Actions must call crmAutomationSetFinanceStatus")

    # 4. Page file
    page_path = os.path.join(base_dir, 'crm/pages/automation-followup.php')
    with open(page_path, 'r', encoding='utf-8') as f:
        page = f.read()
    if "toggle_finance_status" not in page:
        raise AssertionError("Prospects table must render toggle_finance_status form")
    if "Ubah Jadi Sudah" not in page and "Tandai Sudah" not in page:
        raise AssertionError("Prospects table must provide button to change finance status to Sudah")

    print("automation-followup-finance-scheduling-test: OK")

def run_reminder_pembayaran_suite(base_dir: str):
    import reminder_pembayaran_test
    reminder_pembayaran_test.test_bulan_pembayaran_query(base_dir)
    reminder_pembayaran_test.test_status_peserta_filter_with_csv(base_dir)
    reminder_pembayaran_test.test_pagination(base_dir)
    reminder_pembayaran_test.test_send_action_db_verification(base_dir)
    reminder_pembayaran_test.test_send_action_placeholders(base_dir)
    reminder_pembayaran_test.test_send_action_safety(base_dir)
    reminder_pembayaran_test.test_layout_and_ux(base_dir)
    reminder_pembayaran_test.test_kelola_reminder_removed(base_dir)
    reminder_pembayaran_test.test_teacher_requests_removed(base_dir)
    reminder_pembayaran_test.test_live_preview_and_selection_ux(base_dir)
    print("reminder-pembayaran-suite: OK")

def run_reminder_schedule_suite(base_dir: str):
    import reminder_schedule_test
    reminder_schedule_test.test_schema_and_library_functions(base_dir)
    reminder_schedule_test.test_schedule_actions_and_cron(base_dir)
    reminder_schedule_test.test_ui_schedule_modal_and_filters(base_dir)
    reminder_schedule_test.test_css_styles(base_dir)
    print("reminder-schedule-suite: OK")

def run_reminder_peserta_schedule_suite(base_dir: str):
    import reminder_peserta_schedule_test
    reminder_peserta_schedule_test.test_lib_enhancements(base_dir)
    reminder_peserta_schedule_test.test_action_enhancements(base_dir)
    reminder_peserta_schedule_test.test_peserta_page_ui(base_dir)
    reminder_peserta_schedule_test.test_css_rules(base_dir)
    print("reminder-peserta-schedule-suite: OK")

def main():
    base_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
    test_name = sys.argv[1] if len(sys.argv) > 1 else "all"

    if test_name in ["automation-followup-db-test", "db", "all"]:
        run_db_test(base_dir)
    if test_name in ["automation-followup-trigger-test", "trigger", "all"]:
        run_trigger_test(base_dir)
    if test_name in ["automation-followup-actions-test", "actions", "all"]:
        run_actions_test(base_dir)
    if test_name in ["automation-followup-ui-test", "ui", "all"]:
        run_ui_test(base_dir)
    if test_name in ["automation-followup-webhook-e2e-test", "e2e", "all"]:
        run_e2e_test(base_dir)
    if test_name in ["automation-followup-cleanup-test", "cleanup", "all"]:
        run_cleanup_test(base_dir)
    if test_name in ["automation-followup-prospect-filter-test", "prospect-filter", "all"]:
        run_prospect_filter_test(base_dir)
    if test_name in ["automation-followup-pagination-test", "pagination", "all"]:
        run_pagination_test(base_dir)
    if test_name in ["automation-followup-copy-wa-test", "copy-wa", "all"]:
        run_copy_wa_test(base_dir)
    if test_name in ["automation-followup-review-fixes-test", "review-fixes", "all"]:
        run_review_fixes_test(base_dir)
    if test_name in ["automation-followup-retention-test", "retention", "all"]:
        run_retention_test(base_dir)
    if test_name in ["automation-followup-queue-bug-test", "queue-bug", "all"]:
        run_queue_bug_test(base_dir)
    if test_name in ["automation-followup-sequence-name-test", "sequence-name", "all"]:
        run_sequence_name_test(base_dir)
    if test_name in ["automation-followup-finance-scheduling-test", "finance-scheduling", "all"]:
        run_finance_scheduling_test(base_dir)
    if test_name in ["reminder-pembayaran-suite", "reminder-pembayaran", "all"]:
        run_reminder_pembayaran_suite(base_dir)
    if test_name in ["reminder-schedule-suite", "reminder-schedule", "all"]:
        run_reminder_schedule_suite(base_dir)
    if test_name in ["reminder-peserta-schedule-suite", "reminder-peserta-schedule", "all"]:
        run_reminder_peserta_schedule_suite(base_dir)

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"FAILED: {e}", file=sys.stderr)
        sys.exit(1)
