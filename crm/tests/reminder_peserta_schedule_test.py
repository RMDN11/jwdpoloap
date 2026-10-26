#!/usr/bin/env python3
"""
Test suite for Reminder Peserta Automation, Day Selection, and Contact History.
"""
import sys
import os
import re

def get_base_dir():
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

def test_lib_enhancements(base_dir):
    lib_path = os.path.join(base_dir, "crm/lib/reminder-schedule.php")
    if not os.path.exists(lib_path):
        raise AssertionError("crm/lib/reminder-schedule.php does not exist")

    with open(lib_path, "r", encoding="utf-8") as f:
        content = f.read()

    # Function crmAdjustTimestampToAllowedDays
    if "function crmAdjustTimestampToAllowedDays" not in content:
        raise AssertionError("Missing function crmAdjustTimestampToAllowedDays in reminder-schedule.php")

    # Schema columns for day filtering and peserta requests
    for col in ["campaign_type", "allowed_days", "request_id"]:
        if col not in content:
            raise AssertionError(f"Missing schema reference for '{col}' in reminder-schedule.php")

    # Queue processing handles reminder_requests update
    if "reminder_requests" not in content:
        raise AssertionError("Queue processor should update reminder_requests when request_id is present")

    print("test_lib_enhancements: OK")

def test_action_enhancements(base_dir):
    act_path = os.path.join(base_dir, "crm/actions/reminder-schedule.php")
    with open(act_path, "r", encoding="utf-8") as f:
        content = f.read()

    if "peserta" not in content:
        raise AssertionError("actions/reminder-schedule.php must support 'peserta' campaign type")

    if "allowed_days" not in content:
        raise AssertionError("actions/reminder-schedule.php must process allowed_days parameter")

    if "reminder_requests" not in content:
        raise AssertionError("actions/reminder-schedule.php must query reminder_requests for target resolution")

    print("test_action_enhancements: OK")

def test_peserta_page_ui(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-peserta.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    # History integration
    if "formatPesertaHistory" not in content and "formatReminderHistory" not in content:
        raise AssertionError("reminder-peserta.php must define a history formatter function")

    if "reminder-peserta-history" not in content:
        raise AssertionError("reminder-peserta.php must render .reminder-peserta-history badge on rows")

    # Active campaigns monitor
    if "reminder-campaign-card" not in content:
        raise AssertionError("reminder-peserta.php must render active campaigns card")

    # Multi-select controls
    if "reminder-peserta-checkbox" not in content:
        raise AssertionError("reminder-peserta.php must provide checkboxes for participant selection")

    if "openPesertaScheduleBtn" not in content:
        raise AssertionError("reminder-peserta.php must provide button to open schedule modal (#openPesertaScheduleBtn)")

    # Schedule modal & Day of week selection
    if "reminderPesertaScheduleModal" not in content:
        raise AssertionError("reminder-peserta.php must render #reminderPesertaScheduleModal")

    if 'name="allowed_days[]"' not in content:
        raise AssertionError("reminder-peserta.php must provide day selection inputs (allowed_days[])")

    for quick_btn in ["pesertaDayAllBtn", "pesertaDayWorkdaysBtn", "pesertaDayWeekendBtn"]:
        if quick_btn not in content:
            raise AssertionError(f"reminder-peserta.php missing quick day selector: {quick_btn}")

    print("test_peserta_page_ui: OK")

def test_css_rules(base_dir):
    css_path = os.path.join(base_dir, "crm/assets/css/reminder-v2.css")
    with open(css_path, "r", encoding="utf-8") as f:
        content = f.read()

    for selector in [".reminder-peserta-history", ".reminder-peserta-days-grid", ".reminder-day-chip", ".reminder-quick-day-btn"]:
        if selector not in content:
            raise AssertionError(f"Missing CSS rule: {selector} in reminder-v2.css")

    print("test_css_rules: OK")

def main():
    base_dir = get_base_dir()
    test_lib_enhancements(base_dir)
    test_action_enhancements(base_dir)
    test_peserta_page_ui(base_dir)
    test_css_rules(base_dir)
    print("\nALL REMINDER PESERTA SCHEDULE & HISTORY TESTS PASSED!")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"\nTEST FAILED: {e}", file=sys.stderr)
        sys.exit(1)
