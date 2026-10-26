#!/usr/bin/env python3
"""
Test suite for Reminder Schedule & Automation Engine.
"""
import sys
import os
import re

def get_base_dir():
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

def test_schema_and_library_functions(base_dir):
    lib_path = os.path.join(base_dir, "crm/lib/reminder-schedule.php")
    if not os.path.exists(lib_path):
        raise AssertionError("crm/lib/reminder-schedule.php does not exist")

    with open(lib_path, "r", encoding="utf-8") as f:
        content = f.read()

    required_funcs = [
        "crmEnsureReminderScheduleTables",
        "crmCreateReminderCampaign",
        "crmProcessPendingReminderQueue",
        "crmUpdateCampaignStatus",
        "crmGetActiveCampaigns"
    ]
    for func in required_funcs:
        if f"function {func}" not in content:
            raise AssertionError(f"Missing required function: {func} in crm/lib/reminder-schedule.php")

    if "CREATE TABLE IF NOT EXISTS crm_reminder_campaigns" not in content:
        raise AssertionError("Missing table declaration for crm_reminder_campaigns")

    if "CREATE TABLE IF NOT EXISTS crm_reminder_campaign_queue" not in content:
        raise AssertionError("Missing table declaration for crm_reminder_campaign_queue")

    print("test_schema_and_library_functions: OK")

def test_schedule_actions_and_cron(base_dir):
    act_path = os.path.join(base_dir, "crm/actions/reminder-schedule.php")
    cron_path = os.path.join(base_dir, "crm/actions/reminder-schedule-cron.php")

    if not os.path.exists(act_path):
        raise AssertionError("crm/actions/reminder-schedule.php does not exist")
    if not os.path.exists(cron_path):
        raise AssertionError("crm/actions/reminder-schedule-cron.php does not exist")

    with open(act_path, "r", encoding="utf-8") as f:
        act_content = f.read()

    if "crmVerifyCsrf" not in act_content:
        raise AssertionError("crm/actions/reminder-schedule.php must verify CSRF token")

    if "create_campaign" not in act_content and "create" not in act_content:
        raise AssertionError("crm/actions/reminder-schedule.php must handle campaign creation")

    with open(cron_path, "r", encoding="utf-8") as f:
        cron_content = f.read()

    if "crmProcessPendingReminderQueue" not in cron_content:
        raise AssertionError("crm/actions/reminder-schedule-cron.php must call crmProcessPendingReminderQueue")

    print("test_schedule_actions_and_cron: OK")

def test_ui_schedule_modal_and_filters(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    if "reminder-quick-filters" not in content:
        raise AssertionError("crm/pages/reminder-pembayaran.php must render quick filter chips (.reminder-quick-filters)")

    if "reminderScheduleModal" not in content:
        raise AssertionError("crm/pages/reminder-pembayaran.php must render schedule modal (#reminderScheduleModal)")

    for field in ["delay_message", "delay_halaqoh", "start_type"]:
        if f'name="{field}"' not in content:
            raise AssertionError(f"Schedule modal missing field: {field}")

    if "reminder-campaign-card" not in content and "reminder-campaigns" not in content:
        raise AssertionError("crm/pages/reminder-pembayaran.php must render active campaigns monitor")

    print("test_ui_schedule_modal_and_filters: OK")

def test_css_styles(base_dir):
    css_path = os.path.join(base_dir, "crm/assets/css/reminder-v2.css")
    with open(css_path, "r", encoding="utf-8") as f:
        content = f.read()

    for selector in [".reminder-quick-filters", ".reminder-campaign-card"]:
        if selector not in content:
            raise AssertionError(f"Missing CSS selector: {selector} in reminder-v2.css")

    print("test_css_styles: OK")

def main():
    base_dir = get_base_dir()
    test_schema_and_library_functions(base_dir)
    test_schedule_actions_and_cron(base_dir)
    test_ui_schedule_modal_and_filters(base_dir)
    test_css_styles(base_dir)
    print("\nALL REMINDER SCHEDULE TESTS PASSED!")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"FAILED: {e}", file=sys.stderr)
        sys.exit(1)
