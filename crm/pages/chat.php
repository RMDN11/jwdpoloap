<?php
$crmTitle = 'Chat';

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';
$selected = trim($_GET['contact'] ?? '');

$allowedStatus = ['all', 'new', 'followed'];
if (!in_array($status, $allowedStatus, true)) $status = 'all';

$where = ["message IS NOT NULL", "message != ''", "message != 'Data CSV/Manual'"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(nama LIKE ? OR nowa LIKE ? OR message LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}

if ($status === 'new') {
    $where[] = "(last_followup_at IS NULL OR last_followup_at = '0000-00-00 00:00:00')";
} elseif ($status === 'followed') {
    $where[] = "last_followup_at IS NOT NULL";
}

$sql = "SELECT id, nama, nowa, message, created_at, last_followup_at, last_template_name, template_history
        FROM log_wa
        WHERE " . implode(' AND ', $where) . "
        ORDER BY id DESC
        LIMIT 80";

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$contacts = [];
while ($row = $result->fetch_assoc()) $contacts[] = $row;

$selectedContact = null;
if ($selected !== '') {
    $stmtSelected = $conn->prepare("SELECT id, nama, nowa, message, created_at, last_followup_at, last_template_name, template_history FROM log_wa WHERE nowa = ? LIMIT 1");
    $stmtSelected->bind_param('s', $selected);
    $stmtSelected->execute();
    $selectedContact = $stmtSelected->get_result()->fetch_assoc() ?: null;
}

$templates = [];
$templateResult = $conn->query("SELECT id, name, content FROM poloap_templates ORDER BY name ASC");
if ($templateResult) {
    while ($template = $templateResult->fetch_assoc()) $templates[] = $template;
}

$historyItems = [];
if ($selectedContact && !empty($selectedContact['template_history'])) {
    $historyItems = array_filter(explode('|||', $selectedContact['template_history']));
}

function crmChatName(array $row): string {
    $rawMessage = (string)($row['message'] ?? '');
    $dbName = trim((string)($row['nama'] ?? ''));
    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $rawMessage, $m)) return trim($m[1]);
    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $rawMessage, $m)) return trim($m[1]);
    return $dbName !== '' ? $dbName : 'Hamba Allah';
}

function crmPreview(string $text, int $length = 72): string {
    return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, $length, '…');
}
?>

<section class="page-head chat-page-head">
    <div>
        <span class="eyebrow">Inbox CRM</span>
        <h1>Chat</h1>
        <p>Prospek masuk, follow-up, dan pengiriman pesan mulai dipusatkan di sini.</p>
    </div>
</section>

<div class="chat-stats">
    <a class="<?= $status === 'all' ? 'active' : '' ?>" href="?page=chat&q=<?= urlencode($search) ?>&status=all">
        <strong><?= count($contacts) ?></strong><span>Terlihat</span>
    </a>
    <a class="<?= $status === 'new' ? 'active' : '' ?>" href="?page=chat&q=<?= urlencode($search) ?>&status=new">
        <strong>Baru</strong><span>Belum follow-up</span>
    </a>
    <a class="<?= $status === 'followed' ? 'active' : '' ?>" href="?page=chat&q=<?= urlencode($search) ?>&status=followed">
        <strong>Follow-up</strong><span>Sudah ditangani</span>
    </a>
</div>

<form class="search-box" method="get">
    <input type="hidden" name="page" value="chat">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, nomor, atau pesan...">
    <?php if ($search): ?><a href="?page=chat&status=<?= urlencode($status) ?>"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
</form>

<div class="chat-layout">
    <div class="chat-list">
        <?php if (!$contacts): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Tidak ada prospek</strong><p>Filter ini belum menemukan percakapan.</p></div>
        <?php else: ?>
            <?php foreach ($contacts as $row): ?>
                <?php $name = crmChatName($row); $isSelected = $selected !== '' && $selected === $row['nowa']; ?>
                <a href="?page=chat&q=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&contact=<?= urlencode($row['nowa']) ?>" class="chat-item <?= $isSelected ? 'selected' : '' ?>">
                    <span class="activity-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                    <span class="chat-body">
                        <strong><?= htmlspecialchars($name) ?></strong>
                        <small><?= htmlspecialchars(crmPreview((string)$row['message'])) ?></small>
                    </span>
                    <span class="chat-meta">
                        <time><?= htmlspecialchars(date('H:i', strtotime($row['created_at']))) ?></time>
                        <?php if (!empty($row['last_followup_at'])): ?><i class="fa-solid fa-check-double"></i><?php else: ?><i class="fa-regular fa-circle"></i><?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <aside class="chat-panel <?= $selectedContact ? 'has-contact' : '' ?>">
        <?php if (!$selectedContact): ?>
            <div class="chat-panel-empty">
                <i class="fa-regular fa-message"></i>
                <strong>Pilih percakapan</strong>
                <span>Pilih prospek di sebelah kiri untuk melihat pesan dan melakukan follow-up.</span>
            </div>
        <?php else: ?>
            <?php $selectedName = crmChatName($selectedContact); ?>
            <div class="chat-panel-head">
                <div class="contact-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($selectedName, 0, 1))) ?></div>
                <div>
                    <strong><?= htmlspecialchars($selectedName) ?></strong>
                    <small><?= htmlspecialchars($selectedContact['nowa']) ?></small>
                </div>
            </div>

            <div class="chat-message">
                <span class="message-label">Pesan terakhir</span>
                <p><?= nl2br(htmlspecialchars((string)$selectedContact['message'])) ?></p>
                <time><?= htmlspecialchars(date('d M Y H:i', strtotime($selectedContact['created_at']))) ?></time>
            </div>

            <?php if ($historyItems): ?>
                <div class="followup-history">
                    <span class="message-label">Riwayat follow-up</span>
                    <?php foreach (array_slice($historyItems, -5) as $history): ?>
                        <div><i class="fa-solid fa-check"></i><?= htmlspecialchars($history) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="send-box" method="post" action="actions/send-message.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                <input type="hidden" name="contact_id" value="<?= htmlspecialchars($selectedContact['nowa']) ?>">
                <label>
                    <span>Template</span>
                    <select name="template_id">
                        <option value="">Pilih template...</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= (int)$template['id'] ?>"><?= htmlspecialchars($template['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Pesan custom <small>(opsional, mengabaikan template)</small></span>
                    <textarea name="custom_message" rows="4" placeholder="Tulis pesan custom untuk <?= htmlspecialchars($selectedName) ?>..."></textarea>
                </label>
                <div class="send-box-foot">
                    <small><i class="fa-solid fa-circle-info"></i> [nama] otomatis diganti nama kontak.</small>
                    <button type="submit"><i class="fa-solid fa-paper-plane"></i> Kirim</button>
                </div>
            </form>
        <?php endif; ?>
    </aside>
</div>
