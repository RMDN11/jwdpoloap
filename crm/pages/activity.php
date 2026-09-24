<?php
$crmTitle = 'Aktivitas';
$activityPage = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$totalLogs = crmCount($conn, "SELECT COUNT(*) total FROM log_wa WHERE message IS NOT NULL AND message != ''");
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
$activityPage = min($activityPage, $totalPages);
$offset = ($activityPage - 1) * $perPage;

$logs = [];
$stmt = $conn->prepare("SELECT id, nama, nowa, message, created_at FROM log_wa WHERE message IS NOT NULL AND message != '' ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $logs[] = $row;
$stmt->close();
?>
<section class="page-head activity-page-head">
    <div>
        <span class="eyebrow">Riwayat CRM</span>
        <h1>Semua Aktivitas</h1>
        <p>Seluruh log percakapan yang tersimpan di CRM.</p>
    </div>
    <a class="activity-back" href="?page=home"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
</section>

<section class="section">
    <div class="activity-log-head">
        <strong><?= number_format($totalLogs) ?> log</strong>
        <span>Halaman <?= $activityPage ?> / <?= $totalPages ?></span>
    </div>
    <div class="activity-log-list">
        <?php if (!$logs): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Belum ada log</strong><p>Belum ada percakapan yang tersimpan.</p></div>
        <?php else: foreach ($logs as $item): ?>
            <a href="?page=chat&contact=<?= urlencode((string)$item['nowa']) ?>" class="activity-log-item">
                <span class="activity-avatar"><?= strtoupper(substr(trim($item['nama'] ?: 'K'), 0, 1)) ?></span>
                <span class="activity-body">
                    <strong><?= htmlspecialchars($item['nama'] ?: 'Tanpa nama') ?></strong>
                    <small><?= htmlspecialchars(mb_strimwidth(trim($item['message']), 0, 140, '…')) ?></small>
                    <em><?= htmlspecialchars((string)$item['nowa']) ?></em>
                </span>
                <time><?= htmlspecialchars(date('d M Y · H:i', strtotime($item['created_at']))) ?></time>
            </a>
        <?php endforeach; endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="activity-pagination">
            <?php if ($activityPage > 1): ?><a href="?page=activity&p=<?= $activityPage - 1 ?>"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</a><?php else: ?><span class="disabled">Sebelumnya</span><?php endif; ?>
            <strong><?= $activityPage ?> / <?= $totalPages ?></strong>
            <?php if ($activityPage < $totalPages): ?><a href="?page=activity&p=<?= $activityPage + 1 ?>">Berikutnya <i class="fa-solid fa-chevron-right"></i></a><?php else: ?><span class="disabled">Berikutnya</span><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
