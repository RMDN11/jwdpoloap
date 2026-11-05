#!/usr/bin/env python3
"""
Unit test for Spintax & Anti-Ban Human Jitter in Reminder System
"""
import os
import sys

def get_base_dir():
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

def test_spintax_in_lib(base_dir):
    lib_path = os.path.join(base_dir, "crm/lib/reminder-schedule.php")
    with open(lib_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "function crmResolveSpintax" in content, "crmResolveSpintax missing from lib"
    assert "function crmGetTimeGreeting" in content, "crmGetTimeGreeting missing from lib"
    assert "function crmResolveReminderMessage" in content, "crmResolveReminderMessage missing from lib"
    assert "jitter" in content.lower(), "Human jitter missing from schedule queue generator"
    print("test_spintax_in_lib: OK")

def test_spintax_in_actions(base_dir):
    send_path = os.path.join(base_dir, "crm/actions/reminder-send.php")
    with open(send_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "crmResolveReminderMessage" in content, "crmResolveReminderMessage not used in reminder-send.php"
    assert "usleep(rand(" in content, "Humanized randomized delay not found in reminder-send.php"
    print("test_spintax_in_actions: OK")

def test_spintax_in_page_ui(base_dir):
    page_path = os.path.join(base_dir, "crm/pages/reminder-pembayaran.php")
    with open(page_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "btnRerollSpintax" in content, "btnRerollSpintax missing from reminder-pembayaran.php"
    assert "resolveSpintaxClient" in content, "resolveSpintaxClient missing from reminder-pembayaran.php"
    assert "getTimeGreetingClient" in content, "getTimeGreetingClient missing from reminder-pembayaran.php"
    print("test_spintax_in_page_ui: OK")

def main():
    base_dir = get_base_dir()
    test_spintax_in_lib(base_dir)
    test_spintax_in_actions(base_dir)
    test_spintax_in_page_ui(base_dir)
    print("\nALL SPINTAX & ANTI-BAN REMINDER TESTS PASSED!")

if __name__ == "__main__":
    main()
