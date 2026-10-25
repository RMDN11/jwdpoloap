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

def main():
    base_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
    test_name = sys.argv[1] if len(sys.argv) > 1 else "all"

    if test_name in ["automation-followup-db-test", "db", "all"]:
        run_db_test(base_dir)
    if test_name in ["automation-followup-trigger-test", "trigger", "all"]:
        run_trigger_test(base_dir)

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"FAILED: {e}", file=sys.stderr)
        sys.exit(1)
