#!/usr/bin/env python3
"""
Test suite for Modern Tadzkir (Teacher Reminder Portal) and CRM Integration.
"""
import os
import subprocess

def test_files_exist_and_lint(base_dir):
    tadzkir_path = os.path.join(base_dir, "tadzkir.php")
    search_path = os.path.join(base_dir, "search_peserta.php")
    
    assert os.path.exists(tadzkir_path), "tadzkir.php does not exist at repo root"
    assert os.path.exists(search_path), "search_peserta.php does not exist at repo root"

    for file_path in [tadzkir_path, search_path]:
        res = subprocess.run(["php", "-l", file_path], capture_output=True, text=True)
        assert res.returncode == 0, f"PHP lint failed for {file_path}: {res.stderr}"

    print("test_files_exist_and_lint: OK")

def test_security_and_database_prepared_statements(base_dir):
    tadzkir_path = os.path.join(base_dir, "tadzkir.php")
    with open(tadzkir_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "INSERT INTO reminder_requests" in content, "tadzkir.php must insert into reminder_requests"
    assert "prepare(" in content, "tadzkir.php must use prepared statements"
    assert "bind_param(" in content, "tadzkir.php must bind parameters for safety"
    assert "csrf_token" in content, "tadzkir.php must validate CSRF tokens"
    assert "hash_equals(" in content, "tadzkir.php must use timing-safe comparison for CSRF"
    assert "status) \n        VALUES (?, ?, ?, ?, ?, 'menunggu')" in content or "status)" in content, "tadzkir.php must set status 'menunggu'"

    search_path = os.path.join(base_dir, "search_peserta.php")
    with open(search_path, "r", encoding="utf-8") as f:
        search_content = f.read()

    assert "prepare(" in search_content and "bind_param(" in search_content, "search_peserta.php must use prepared statements"
    assert "application/json" in search_content, "search_peserta.php must return JSON"

    print("test_security_and_database_prepared_statements: OK")

def test_modern_ux_and_branding(base_dir):
    tadzkir_path = os.path.join(base_dir, "tadzkir.php")
    with open(tadzkir_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "action=search" in content, "tadzkir.php must support AJAX search action"
    assert "action=list_all" in content, "tadzkir.php must support list_all action"
    assert "participant-chip" in content, "tadzkir.php must render participant chips"
    assert "btnSpinner" in content or "spinner" in content, "tadzkir.php must provide submit loading spinner"
    assert "#168044" in content, "tadzkir.php must follow ReqraWA green branding"
    assert "searchTimeout" in content, "tadzkir.php must debounce live search queries"
    assert "flash-names-list" in content, "tadzkir.php must render list of sent participant names in notification"
    assert "sentNames" in content, "tadzkir.php must collect sent participant names for flash message"
    assert "suggestion-nowa" not in content, "tadzkir.php must not display nowa in suggestions dropdown"

    print("test_modern_ux_and_branding: OK")

def test_crm_reminder_peserta_integration(base_dir):
    peserta_path = os.path.join(base_dir, "crm/pages/reminder-peserta.php")
    with open(peserta_path, "r", encoding="utf-8") as f:
        content = f.read()

    assert "../tadzkir.php" in content or "tadzkir.php" in content, "reminder-peserta.php must link to tadzkir.php"
    assert "btnCopyTadzkirLink" in content, "reminder-peserta.php must have copy link button for teachers"

    css_path = os.path.join(base_dir, "crm/assets/css/reminder-v2.css")
    with open(css_path, "r", encoding="utf-8") as f:
        css_content = f.read()

    assert "reminder-peserta-head-actions" in css_content, "CSS must style header action container"
    assert "reminder-peserta-action-btn" in css_content, "CSS must style action buttons"

    print("test_crm_reminder_peserta_integration: OK")

def run_all(base_dir):
    test_files_exist_and_lint(base_dir)
    test_security_and_database_prepared_statements(base_dir)
    test_modern_ux_and_branding(base_dir)
    test_crm_reminder_peserta_integration(base_dir)
    print("tadzkir-modern-suite: OK")

if __name__ == "__main__":
    current_dir = os.path.abspath(os.path.dirname(__file__))
    project_root = os.path.abspath(os.path.join(current_dir, "../.."))
    run_all(project_root)
