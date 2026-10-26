#!/usr/bin/env python3
"""
Test suite for Reminder Pembayaran workspace (page=reminder-pembayaran).
Tests query compatibility, pagination, CSV filtering, security validation,
placeholder expansion, layout structure, and anti-double-submit UX.
"""
import sys
import os
import re

def get_base_dir():
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

def test_bulan_pembayaran_query(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    # Must NOT have ONLY_FULL_GROUP_BY incompatible query
    if "SELECT DISTINCT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran <> '' ORDER BY id DESC" in content:
        raise AssertionError("Incompatible query found: SELECT DISTINCT ... ORDER BY id DESC will fail under MySQL ONLY_FULL_GROUP_BY")

    # Must use GROUP BY and MAX(id) DESC
    if "GROUP BY bulan_pembayaran ORDER BY MAX(id) DESC" not in content and "GROUP BY bulan_pembayaran" not in content:
        raise AssertionError("bulan_pembayaran query must use GROUP BY bulan_pembayaran ORDER BY MAX(id) DESC")

    print("test_bulan_pembayaran_query: OK")

def test_status_peserta_filter_with_csv(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    # Check that status_peserta filter is not excluded when csvFile is set
    if "$csvFile === '' && $statusPeserta !== ''" in content:
        raise AssertionError("status_peserta filter is silently ignored when CSV file is selected")

    print("test_status_peserta_filter_with_csv: OK")

def test_pagination(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    if "$perPage" not in content or "$offset" not in content or "$totalPages" not in content:
        raise AssertionError("Missing pagination variables ($perPage, $offset, $totalPages)")

    # Hardcoded LIMIT 100 must not exist
    if "LIMIT 100\n" in content or "LIMIT 100\"" in content:
        raise AssertionError("Hardcoded LIMIT 100 still present without pagination parameters")

    # Pagination navigation controls in HTML
    if "reminder-pagination" not in content:
        raise AssertionError("Missing pagination HTML container (.reminder-pagination)")

    print("test_pagination: OK")

def test_send_action_db_verification(base_dir):
    action_path = os.path.join(base_dir, "crm/actions/reminder-send.php")
    with open(action_path, "r", encoding="utf-8") as f:
        content = f.read()

    # When csvFile is empty, must verify IDs against peserta table
    if "$selectedIds" not in content or "FROM peserta WHERE id IN" not in content:
        raise AssertionError("actions/reminder-send.php blindly trusts client JSON without verifying master peserta in DB")

    print("test_send_action_db_verification: OK")

def test_send_action_placeholders(base_dir):
    action_path = os.path.join(base_dir, "crm/actions/reminder-send.php")
    with open(action_path, "r", encoding="utf-8") as f:
        action = f.read()

    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        page = f.read()

    if "{bulan}" not in action or "[bulan]" not in action:
        raise AssertionError("actions/reminder-send.php must support {bulan} / [bulan] placeholder")

    if "{halaqoh}" not in action or "[halaqoh]" not in action:
        raise AssertionError("actions/reminder-send.php must support {halaqoh} / [halaqoh] placeholder")

    if "{bulan}" not in page:
        raise AssertionError("crm/pages/reminder-pembayaran.php helper hint must document {bulan}")

    print("test_send_action_placeholders: OK")

def test_send_action_safety(base_dir):
    action_path = os.path.join(base_dir, "crm/actions/reminder-send.php")
    with open(action_path, "r", encoding="utf-8") as f:
        action = f.read()

    if "set_time_limit" not in action:
        raise AssertionError("actions/reminder-send.php must call set_time_limit to prevent timeout on batch sends")

    if "usleep" not in action:
        raise AssertionError("actions/reminder-send.php should include pacing (usleep) between WhatsApp sends")

    print("test_send_action_safety: OK")

def test_layout_and_ux(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        page = f.read()

    css_path = os.path.join(base_dir, "crm/assets/css/reminder-v2.css")
    with open(css_path, "r", encoding="utf-8") as f:
        css = f.read()

    if "reminder-payment-grid" not in page:
        raise AssertionError("page must structure target list and sidebar inside .reminder-payment-grid")

    if ".reminder-payment-grid" not in css:
        raise AssertionError("reminder-v2.css must define .reminder-payment-grid")

    if "reminder-payment-side" not in css and "reminder-side" not in css:
        raise AssertionError("reminder-v2.css must style sidebar")

    # Anti double submit
    if "disabled = true" not in page and "setAttribute('disabled'" not in page and ".disabled = true" not in page:
        raise AssertionError("Submit form must disable submit button to prevent double-submissions")

    print("test_layout_and_ux: OK")

def main():
    base_dir = get_base_dir()
    test_bulan_pembayaran_query(base_dir)
    test_status_peserta_filter_with_csv(base_dir)
    test_pagination(base_dir)
    test_send_action_db_verification(base_dir)
    test_send_action_placeholders(base_dir)
    test_send_action_safety(base_dir)
    test_layout_and_ux(base_dir)
    print("\nALL REMINDER PEMBAYARAN TESTS PASSED!")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"FAILED: {e}", file=sys.stderr)
        sys.exit(1)
