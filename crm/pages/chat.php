<?php
$crmTitle = 'Chat';
$search = trim($_GET['q'] ?? '');
$like = '%' . $search . '%';

$stmt = $conn->prepare("SELECT id, nama, nowa, message, created_at, last_followup_at FROM log_wa WHERE message IS NOT NULL AND message != '' AND (? = '' OR nama LIKE ? OR nowa LIKE ? OR message LIKE ?) ORDER BY id DESC LIMIT 50");
$stmt->bind_param('ssss', $search, $like, $like, $like);
$stmt->execute();
$result = $stmt->get_result();
?>
<section class="page-head">
    <span class="eyebrow">Inbox</span>
    <h1>Chat</h1>
    <p>Fondasi inbox CRM V2. Data lama tetap menjadi sumber sampai modul percakapan baru selesai.</p>
</section>

<form class="search-box" method="get">
    <input type="hidden" name="page" value="chat">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, nomor, atau pesan...">
    <?php if ($search): ?><a href="?page=chat"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
</form>

<div class="chat-list">
<?php if ($result->num_rows === 0): ?>
    <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Tidak ada percakapan</strong><p>Coba kata kunci lain atau tunggu pesan baru.</p></div>
<?php else: while ($row = $result->fetch_assoc()): ?>
    <a href="../pesan.php" class="chat-item">
        <span class="activity-avatar"><?= strtoupper(substr(trim($row['nama'] ?: 'K'), 0, 1)) ?></span>
        <span class="chat-body">
            <strong><?= htmlspecialchars($row['nama'] ?: 'Tanpa nama') ?></strong>
            <small><?= htmlspecialchars(mb_strimwidth(trim($row['message']), 0, 76, '…')) ?></small>
        </span>
        <span class="chat-meta"><time><?= htmlspecialchars(date('H:i', strtotime($row['created_at']))) ?></time><?php if (!empty($row['last_followup_at'])): ?><i class="fa-solid fa-check"></i><?php endif; ?></span>
    </a>
<?php endwhile; endif; ?>
</div>
