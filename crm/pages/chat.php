<?php
$crmTitle = 'Chat';

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
$selected = trim((string)($_GET['contact'] ?? ''));
$allowedStatus = ['all', 'new', 'followed'];
if (!in_array($status, $allowedStatus, true)) $status = 'all';

$where = ["message IS NOT NULL", "message != ''", "message != 'Data CSV/Manual'"];
$bind = [];
$types = '';
if ($search !== '') {
    $where[] = "(nama LIKE ? OR nowa LIKE ? OR message LIKE ?)";
    $like = '%' . $search . '%';
    $bind = [$like, $like, $like];
    $types = 'sss';
}
if ($status === 'new') $where[] = "(last_followup_at IS NULL OR last_followup_at = '0000-00-00 00:00:00')";
if ($status === 'followed') $where[] = "last_followup_at IS NOT NULL AND last_followup_at != '0000-00-00 00:00:00'";

$stmt = $conn->prepare("SELECT id,nama,nowa,message,created_at,last_followup_at,last_template_name,template_history FROM log_wa WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 100");
if ($types) $stmt->bind_param($types, ...$bind);
$stmt->execute();
$result = $stmt->get_result();

$contacts = [];
$maxLogId = 0;
$seen = [];
while ($row = $result->fetch_assoc()) {
    $maxLogId = max($maxLogId, (int)$row['id']);
    $number = crmNormalizeNumber((string)$row['nowa']);
    if ($number === '' || isset($seen[$number])) continue;
    $seen[$number] = true;
    $row['clean_wa'] = $number;
    $contacts[] = $row;
}

$selectedContact = null;
if ($selected !== '') {
    $normalized = crmNormalizeNumber($selected);
    $s = $conn->prepare("SELECT id,nama,nowa,message,created_at,last_followup_at,last_template_name,template_history FROM log_wa WHERE nowa = ? OR nowa = ? ORDER BY id DESC LIMIT 1");
    $s->bind_param('ss', $selected, $normalized);
    $s->execute();
    $selectedContact = $s->get_result()->fetch_assoc() ?: null;
    if ($selectedContact) $selectedContact['clean_wa'] = crmNormalizeNumber((string)$selectedContact['nowa']);
}

$templates = [];
$templateResult = $conn->query("SELECT id,name,content FROM poloap_templates ORDER BY name ASC");
if ($templateResult) while ($template = $templateResult->fetch_assoc()) $templates[] = $template;

$historyItems = $selectedContact && !empty($selectedContact['template_history'])
    ? array_filter(explode('|||', $selectedContact['template_history'])) : [];

function crmChatName(array $row): string {
    $raw = (string)($row['message'] ?? '');
    $dbName = trim((string)($row['nama'] ?? ''));
    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $raw, $m)) return trim($m[1]);
    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $raw, $m)) return trim($m[1]);
    return $dbName !== '' ? $dbName : 'Hamba Allah';
}
function crmPreview(string $text, int $length = 68): string {
    return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, $length, '…');
}
function crmChatUrl(string $search, string $status, string $contact = ''): string {
    $params = ['page'=>'chat','status'=>$status];
    if ($search !== '') $params['q'] = $search;
    if ($contact !== '') $params['contact'] = $contact;
    return '?' . http_build_query($params);
}
?>

<section class="page-head chat-page-head">
    <div>
        <span class="eyebrow">Inbox CRM</span>
        <h1>Chat</h1>
        <p>Kelola prospek dan follow-up tanpa keluar dari workspace CRM.</p>
    </div>
    <?php if ($selectedContact): ?>
        <a class="chat-wa-link" href="https://wa.me/<?= htmlspecialchars($selectedContact['clean_wa']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
    <?php endif; ?>
</section>

<div class="chat-stats">
    <a class="<?= $status === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search,'all')) ?>"><strong><?= count($contacts) ?></strong><span>Semua</span></a>
    <a class="<?= $status === 'new' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search,'new')) ?>"><strong>Baru</strong><span>Belum follow-up</span></a>
    <a class="<?= $status === 'followed' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search,'followed')) ?>"><strong>Follow-up</strong><span>Sudah ditangani</span></a>
</div>

<form class="search-box" method="get">
    <input type="hidden" name="page" value="chat"><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, nomor, atau isi pesan...">
    <?php if ($search): ?><a href="<?= htmlspecialchars(crmChatUrl('', $status)) ?>"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
</form>

<div class="chat-layout">
    <div class="chat-list">
        <?php if (!$contacts): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Tidak ada percakapan</strong><p>Belum ada data yang cocok dengan filter ini.</p></div>
        <?php else: foreach ($contacts as $row): ?>
            <?php $name = crmChatName($row); $isSelected = $selected !== '' && crmNormalizeNumber($selected) === $row['clean_wa']; ?>
            <a href="<?= htmlspecialchars(crmChatUrl($search,$status,$row['nowa'])) ?>" class="chat-item <?= $isSelected ? 'selected' : '' ?>">
                <span class="activity-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($name,0,1))) ?></span>
                <span class="chat-body"><strong><?= htmlspecialchars($name) ?></strong><small><?= htmlspecialchars(crmPreview((string)$row['message'])) ?></small></span>
                <span class="chat-meta"><time><?= htmlspecialchars(date('H:i',strtotime($row['created_at']))) ?></time><?php if (!empty($row['last_followup_at'])): ?><i class="fa-solid fa-check-double"></i><?php else: ?><i class="fa-regular fa-circle"></i><?php endif; ?></span>
            </a>
        <?php endforeach; endif; ?>
    </div>

    <aside class="chat-panel <?= $selectedContact ? 'has-contact' : '' ?>">
        <?php if (!$selectedContact): ?>
            <div class="chat-panel-empty"><i class="fa-regular fa-message"></i><strong>Pilih percakapan</strong><span>Pilih prospek untuk melihat pesan dan melakukan follow-up.</span></div>
        <?php else: $selectedName = crmChatName($selectedContact); ?>
            <div class="chat-panel-head">
                <div class="contact-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($selectedName,0,1))) ?></div>
                <div class="chat-panel-contact"><strong><?= htmlspecialchars($selectedName) ?></strong><small><?= htmlspecialchars($selectedContact['nowa']) ?></small></div>
                <a class="chat-panel-close" href="<?= htmlspecialchars(crmChatUrl($search,$status)) ?>" aria-label="Tutup percakapan"><i class="fa-solid fa-xmark"></i></a>
            </div>
            <div class="chat-message">
                <div class="message-label-row"><span class="message-label">Pesan terakhir</span><time><?= htmlspecialchars(date('d M Y H:i',strtotime($selectedContact['created_at']))) ?></time></div>
                <p><?= nl2br(htmlspecialchars((string)$selectedContact['message'])) ?></p>
            </div>
            <?php if ($historyItems): ?><div class="followup-history"><span class="message-label">Riwayat follow-up</span><?php foreach (array_slice($historyItems,-5) as $history): ?><div><i class="fa-solid fa-check"></i><?= htmlspecialchars($history) ?></div><?php endforeach; ?></div><?php endif; ?>
            <form class="send-box" method="post" action="actions/send-message.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                <input type="hidden" name="contact_id" value="<?= htmlspecialchars($selectedContact['nowa']) ?>">
                <label><span>Template</span><select name="template_id"><option value="">Pilih template...</option><?php foreach ($templates as $template): ?><option value="<?= (int)$template['id'] ?>"><?= htmlspecialchars($template['name']) ?></option><?php endforeach; ?></select></label>
                <label><span>Pesan custom <small>(opsional, menggantikan template)</small></span><textarea name="custom_message" rows="4" placeholder="Tulis pesan untuk <?= htmlspecialchars($selectedName) ?>..."></textarea></label>
                <div class="send-box-foot"><small><i class="fa-solid fa-circle-info"></i> [nama] akan otomatis diganti.</small><button type="submit"><i class="fa-solid fa-paper-plane"></i> Kirim</button></div>
            </form>
        <?php endif; ?>
    </aside>
</div>

<script>
(() => {
    const lastLogId = <?= (int)$maxLogId ?>;
    const toastContainer = document.querySelector('.toast-container, #toast-container');
    function poll() {
        if (!lastLogId) return;
        fetch('pages/chat-poll.php?last_id=' + encodeURIComponent(lastLogId), {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json()).then(data => {
            if (data.status === 'success' && data.new_count > 0 && toastContainer && !document.getElementById('crm-new-chat-toast')) {
                const el = document.createElement('button');
                el.id = 'crm-new-chat-toast'; el.type = 'button'; el.className = 'crm-new-chat-toast';
                el.innerHTML = '<i class="fa-solid fa-bell"></i><span>' + data.new_count + ' pesan baru masuk. Muat ulang</span>';
                el.onclick = () => location.reload(); toastContainer.appendChild(el);
            }
        }).catch(() => {});
    }
    setInterval(poll,10000);
})();
</script>
