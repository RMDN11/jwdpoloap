<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Get total count
$result = $conn->query("SELECT COUNT(*) as total FROM auto_reply_logs");
$total = $result->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($total / $limit);

// Get logs
$result = $conn->query("SELECT * FROM auto_reply_logs ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Log Auto-Reply</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto p-4">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-history text-blue-500"></i> Log Auto-Reply
                </h1>
                <a href="analitikjwd.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium flex items-center gap-2">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Waktu</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Kontak</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Pesan Masuk</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Balasan</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Rule ID</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach($logs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600">
                                <?= date('Y-m-d H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="px-4 py-3 font-mono"><?= htmlspecialchars($log['contact_id']) ?></td>
                            <td class="px-4 py-3 text-gray-700 max-w-xs truncate" title="<?= htmlspecialchars($log['incoming_message']) ?>">
                                <?= htmlspecialchars($log['incoming_message']) ?>
                            </td>
                            <td class="px-4 py-3 text-gray-700 max-w-xs truncate" title="<?= htmlspecialchars($log['reply_message']) ?>">
                                <?= htmlspecialchars($log['reply_message']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php
                                $statusClass = 'bg-yellow-100 text-yellow-800';
                                if ($log['status'] === 'sent') $statusClass = 'bg-green-100 text-green-800';
                                if ($log['status'] === 'failed') $statusClass = 'bg-red-100 text-red-800';
                                if ($log['status'] === 'registered') $statusClass = 'bg-blue-100 text-blue-800';
                                ?>
                                <span class="px-2 py-1 rounded text-xs font-semibold <?= $statusClass ?>">
                                    <?= htmlspecialchars($log['status'] ?? 'no_rule') ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($log['rule_id']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="flex justify-center mt-6">
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?= $page-1 ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-2 rounded">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php endif; ?>
                    
                    <?php for($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="bg-blue-600 text-white px-3 py-2 rounded"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-2 rounded"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page+1 ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-2 rounded">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="mt-4 text-center text-gray-500 text-sm">
                Menampilkan <?= count($logs) ?> dari <?= $total ?> log
            </div>
        </div>
    </div>
</body>
</html>