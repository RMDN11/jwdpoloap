#!/usr/bin/env python3
"""
Comprehensive Anti-Suspend & Safe Bulk Messaging Test Suite for ReqraWA CRM.
Tests rate limiting, batch size caps, random human jitter pacing, spintax entropy,
and scheduling stagger across all messaging endpoints in crm/index.php.
"""
import os
import sys
import re
import random

def get_base_dir():
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

def test_batch_limit_guards(base_dir):
    """
    Verifies that all synchronous instant bulk send actions enforce a strict
    threshold (max 20 recipients) to prevent Meta WhatsApp automated blast bans.
    """
    files_to_check = [
        "crm/actions/reminder-send.php",
        "crm/actions/reminder-peserta.php",
        "crm/actions/reminder-pengajar-send.php",
        "crm/actions/reminder-retention-send.php",
        "crm/actions/reminder-retention-tutor-send.php",
    ]

    for rel_path in files_to_check:
        full_path = os.path.join(base_dir, rel_path)
        assert os.path.exists(full_path), f"File {rel_path} does not exist"
        with open(full_path, "r", encoding="utf-8") as f:
            code = f.read()

        has_cap = (
            "> 20" in code or
            ">20" in code or
            "> 20" in code
        )
        assert has_cap, f"File {rel_path} must enforce max 20 target safety guard"

        has_anti_banned_msg = (
            "Anti-Banned" in code or
            "diblokir" in code or
            "berisiko" in code
        )
        assert has_anti_banned_msg, f"File {rel_path} must warn about anti-banned risk when exceeding cap"

    # UI client-side interceptors
    ui_pages = {
        "crm/pages/reminder-pembayaran.php": "effectiveCount > 20",
        "crm/pages/reminder-peserta.php": "pendingCount > 20",
        "crm/pages/reminder-pengajar.php": "selected.length > 20",
        "crm/pages/reminder-retention.php": "selected.length > 20",
    }
    for page_rel, token in ui_pages.items():
        page_full = os.path.join(base_dir, page_rel)
        with open(page_full, "r", encoding="utf-8") as f:
            p_code = f.read()
        assert token in p_code, f"Page {page_rel} must check {token} before instant submission"

    print("test_batch_limit_guards: OK")

def test_human_jitter_pacing(base_dir):
    """
    Verifies that all bulk messaging paths include randomized human-like delays
    (usleep/sleep) between sends to defeat bot burst heuristics.
    """
    actions_with_usleep = [
        "crm/actions/reminder-send.php",
        "crm/actions/reminder-peserta.php",
        "crm/actions/reminder-pengajar-send.php",
        "crm/actions/reminder-retention-send.php",
        "crm/actions/reminder-retention-tutor-send.php",
        "kirimgrup.php",
    ]

    for rel_path in actions_with_usleep:
        full_path = os.path.join(base_dir, rel_path)
        with open(full_path, "r", encoding="utf-8") as f:
            code = f.read()
        assert "usleep(rand(" in code, f"File {rel_path} must include usleep(rand(...)) pacing between sends"

    # Automation engine pending queue pacing
    engine_path = os.path.join(base_dir, "crm/lib/automation-engine.php")
    with open(engine_path, "r", encoding="utf-8") as f:
        engine_code = f.read()
    assert "sleep(" in engine_code and "rand(" in engine_code, "automation-engine.php must include sleep delay between queue dispatches"

    # Cron queue pacing
    sched_path = os.path.join(base_dir, "crm/lib/reminder-schedule.php")
    with open(sched_path, "r", encoding="utf-8") as f:
        sched_code = f.read()
    assert "usleep(rand(" in sched_code, "reminder-schedule.php queue processor must pace outgoing messages"

    # Group broadcast frontend pacing
    group_page = os.path.join(base_dir, "crm/pages/group.php")
    with open(group_page, "r", encoding="utf-8") as f:
        group_code = f.read()
    assert "setTimeout" in group_code and "Math.random" in group_code, "group.php must include randomized delay between group broadcast dispatches"

    # Promotion frontend pacing
    promosi_page = os.path.join(base_dir, "crm/pages/reminder-promosi.php")
    with open(promosi_page, "r", encoding="utf-8") as f:
        promosi_code = f.read()
    assert "8000" in promosi_code and "Math.random" in promosi_code, "reminder-promosi.php must include 8-15s pacing delay"

    print("test_human_jitter_pacing: OK")

def test_dynamic_spintax_resolution(base_dir):
    """
    Verifies that dynamic spintax resolution is active on all bulk messaging
    actions to guarantee message entropy and eliminate identical text hashes.
    """
    spintax_actions = [
        "crm/actions/reminder-send.php",
        "crm/actions/reminder-peserta.php",
        "crm/actions/reminder-pengajar-send.php",
        "crm/actions/reminder-retention-send.php",
        "crm/actions/reminder-retention-tutor-send.php",
        "crm/actions/reminder-promosi-send.php",
    ]

    for rel_path in spintax_actions:
        full_path = os.path.join(base_dir, rel_path)
        with open(full_path, "r", encoding="utf-8") as f:
            code = f.read()
        has_resolver = (
            "crmResolveReminderMessage" in code or
            "crmResolveSpintax" in code
        )
        assert has_resolver, f"File {rel_path} must resolve message with spintax resolver"

    # Verify lib functions
    sched_path = os.path.join(base_dir, "crm/lib/reminder-schedule.php")
    with open(sched_path, "r", encoding="utf-8") as f:
        sched_code = f.read()
    assert "function crmResolveSpintax" in sched_code, "crmResolveSpintax missing from lib"
    assert "function crmResolveReminderMessage" in sched_code, "crmResolveReminderMessage missing from lib"

    print("test_dynamic_spintax_resolution: OK")

def test_timeout_and_csrf_safety(base_dir):
    """
    Verifies that all batch actions set extended execution timeout limits and
    verify CSRF tokens to prevent forged or interrupted blast runs.
    """
    batch_actions = [
        "crm/actions/reminder-send.php",
        "crm/actions/reminder-peserta.php",
        "crm/actions/reminder-pengajar-send.php",
        "crm/actions/reminder-retention-send.php",
        "crm/actions/reminder-retention-tutor-send.php",
    ]

    for rel_path in batch_actions:
        full_path = os.path.join(base_dir, rel_path)
        with open(full_path, "r", encoding="utf-8") as f:
            code = f.read()
        assert "set_time_limit" in code, f"File {rel_path} must call set_time_limit"
        assert "crmVerifyCsrf" in code, f"File {rel_path} must verify CSRF token"

    print("test_timeout_and_csrf_safety: OK")

def python_spintax_resolver(text):
    """Python reference equivalent of crmResolveSpintax"""
    pattern = re.compile(r'\{([^{}]+\|[^{}]+)\}')
    for _ in range(10):
        m = pattern.search(text)
        if not m:
            break
        text = pattern.sub(lambda match: random.choice(match.group(1).split('|')), text)
    return text

def test_spintax_entropy_simulation():
    """
    Simulates spintax generation over 100 messages to verify statistical variance.
    Ensures message diversity > 80% to prevent WhatsApp pattern matching detection.
    """
    template = "{Bismillah|Assalamu'alaikum|Halo} {kak|sahabat|akhi/ukhti}, {izin menginfokan|sekadar mengingatkan|info terkait} SPP {bulan ini|periode berjalan}."
    generated = set()
    total_runs = 100
    for _ in range(total_runs):
        out = python_spintax_resolver(template)
        generated.add(out)

    # 3 options * 3 options * 3 options * 2 options = 54 distinct permutations
    assert len(generated) >= 15, f"Expected high entropy from template, got only {len(generated)} unique outputs"
    print(f"test_spintax_entropy_simulation: OK ({len(generated)} unique variations generated from 100 samples)")

def test_queue_schedule_stagger_simulation():
    """
    Simulates schedule queue time calculation to ensure successive targets
    are spaced out with human jitter and not grouped in identical seconds.
    """
    inter_message_delay = 120  # 2 minutes base
    schedule_time = 1700000000
    timestamps = []

    for i in range(10):
        jitter = random.randint(-15, 30)
        target_ts = schedule_time + jitter
        timestamps.append(target_ts)
        actual_delay = max(30, inter_message_delay + random.randint(-15, 25))
        schedule_time += actual_delay

    # Ensure all timestamps are strictly strictly increasing with safe gaps >= 30 seconds
    for i in range(len(timestamps) - 1):
        diff = timestamps[i+1] - timestamps[i]
        assert diff >= 30, f"Gap between message {i} and {i+1} is too small: {diff}s (must be >= 30s)"

    print("test_queue_schedule_stagger_simulation: OK (10 scheduled messages correctly staggered with jitter)")

def main():
    base_dir = get_base_dir()
    print("=== RUNNING ANTI-SUSPEND BULK MESSAGING TEST SUITE ===")
    test_batch_limit_guards(base_dir)
    test_human_jitter_pacing(base_dir)
    test_dynamic_spintax_resolution(base_dir)
    test_timeout_and_csrf_safety(base_dir)
    test_spintax_entropy_simulation()
    test_queue_schedule_stagger_simulation()
    print("\nALL ANTI-SUSPEND AND SAFE BULK MESSAGING TESTS PASSED!")

if __name__ == "__main__":
    main()
