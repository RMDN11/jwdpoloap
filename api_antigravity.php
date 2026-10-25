<?php
declare(strict_types=1);

/**
 * Antigravity Bridge Relay API
 * Menghubungkan Antigravity Daemon di MacBook dengan antrean task server jwdpoloap.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crm/config/admin-bot.php';

// Verifikasi Koneksi Database
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Inisialisasi tabel jika belum ada
crmAdminBotEnsureTables($conn);

// Verifikasi Token Otentikasi
$expectedToken = defined('ANTIGRAVITY_BRIDGE_TOKEN') ? ANTIGRAVITY_BRIDGE_TOKEN : '';
$providedToken = $_GET['token'] ?? $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? $_POST['token'] ?? '';

if (empty($expectedToken) || !hash_equals($expectedToken, (string)$providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden', 'message' => 'Invalid or missing bridge token']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'poll';

// 1. Action: POLL (Mengambil task pending berikutnya untuk dieksekusi di Mac)
if ($action === 'poll') {
    $stmt = $conn->prepare("SELECT id, nowa, prompt, created_at FROM crm_antigravity_tasks WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Query prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    $stmt->close();

    if ($task) {
        $updateStmt = $conn->prepare("UPDATE crm_antigravity_tasks SET status = 'processing', updated_at = NOW() WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param('i', $task['id']);
            $updateStmt->execute();
            $updateStmt->close();
        }

        echo json_encode([
            'status'   => 'ok',
            'has_task' => true,
            'task'     => [
                'id'         => (int)$task['id'],
                'nowa'       => $task['nowa'],
                'prompt'     => $task['prompt'],
                'created_at' => $task['created_at']
            ]
        ]);
    } else {
        echo json_encode([
            'status'   => 'ok',
            'has_task' => false
        ]);
    }
    exit;
}

// 2. Action: COMPLETE (Mencatat hasil pengerjaan task oleh Mac)
if ($action === 'complete') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    $taskId = (int)($data['task_id'] ?? $_POST['task_id'] ?? 0);
    $status = ($data['status'] ?? $_POST['status'] ?? '') === 'failed' ? 'failed' : 'completed';
    $resultText = (string)($data['result'] ?? $_POST['result'] ?? '');

    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid task_id']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE crm_antigravity_tasks SET status = ?, result = ?, completed_at = NOW() WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Query prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param('ssi', $status, $resultText, $taskId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'status'   => 'ok',
        'task_id'  => $taskId,
        'affected' => $affected
    ]);
    exit;
}

// 3. Action: STATUS (Statistik antrean Antigravity)
if ($action === 'status') {
    $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    $q = $conn->query("SELECT status, COUNT(*) as total FROM crm_antigravity_tasks GROUP BY status");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']] = (int)$r['total'];
            }
        }
    }

    $rows = [];
    $rawQ = $conn->query("SELECT id, nowa, prompt, status, HEX(status) as hex_status FROM crm_antigravity_tasks ORDER BY id DESC LIMIT 5");
    if ($rawQ) {
        while ($row = $rawQ->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    echo json_encode([
        'status' => 'ok',
        'counts' => $counts,
        'recent' => $rows
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . $action]);
