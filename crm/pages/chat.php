<?php
$crmTitle = 'Chat';
require_once __DIR__ . '/../config/prospect.php';
$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'new');
$selected = trim((string)($_GET['contact'] ?? ''));
$allowedStatus = ['all', 'new', 'followed'];
if (!in_array($status, $allowedStatus, true)) $status = 'all';

$where = [
    "message IS NOT NULL",
    "message != ''",
    "message != 'Data CSV/Manual'",
    "(message LIKE '%bingung mau pilih program%' OR message LIKE '%saya bingung%' OR message LIKE '%ziyadah pemula%' OR message LIKE '%ziyadah lanjutan%' OR message LIKE '%muroja''ah%' OR message LIKE '%murojaah%' OR message LIKE '%tahfidz cilik%' OR message LIKE '%intensif%' OR message LIKE '%normal%' OR message LIKE '%kak, mau%' OR message LIKE '%mau ikut%' OR message LIKE '%minat%')"
];
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

$stmt = $conn->prepare("SELECT id,nama,nowa,message,created_at,last_followup_at,last_template_name,template_history FROM log_wa WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 300");
if ($types) $stmt->bind_param($types, ...$bind);
$stmt->execute();
$result = $stmt->get_result();

$contacts = [];
$maxLogId = 0;
$seen = [];
while ($row = $result->fetch_assoc()) {
    $maxLogId = max($maxLogId, (int)$row['id']);
    if (!crmIsEligibleProspect($row, $disqualified, $blocked)) continue;
    $number = crmProspectNormalizeNumber((string)$row['nowa']);
    if ($number === '' || isset($seen[$number])) continue;
    $seen[$number] = true;
    $row['clean_wa'] = $number;
    $contacts[] = $row;
}

$selectedContact = null;
$recentMessages = [];
$poloapHistory = [];

if ($selected !== '') {
    $normalized = crmNormalizeNumber($selected);
    $s = $conn->prepare("SELECT id,nama,nowa,message,created_at,last_followup_at,last_template_name,template_history FROM log_wa WHERE nowa = ? OR nowa = ? ORDER BY id DESC LIMIT 30");
    $s->bind_param('ss', $selected, $normalized);
    $s->execute();
    $selectedContact = null;
    $selectedResult = $s->get_result();
    while ($candidate = $selectedResult->fetch_assoc()) {
        if (crmIsEligibleProspect($candidate, $disqualified, $blocked)) {
            $selectedContact = $candidate;
            break;
        }
    }
    if ($selectedContact) {
        $selectedContact['clean_wa'] = crmProspectNormalizeNumber((string)$selectedContact['nowa']);

        $historyStmt = $conn->prepare("SELECT id,nama,nowa,message,created_at,is_form_sent,last_template_name FROM log_wa WHERE nowa = ? OR nowa = ? ORDER BY created_at DESC, id DESC LIMIT 20");
        $historyStmt->bind_param('ss', $selectedContact['nowa'], $selectedContact['clean_wa']);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        while ($historyRow = $historyResult->fetch_assoc()) $recentMessages[] = $historyRow;
        $historyStmt->close();

        $outboundStmt = $conn->prepare("SELECT id,template_id,template_name,message,sent_at,status FROM crm_message_history WHERE nowa = ? OR nowa = ? ORDER BY sent_at DESC, id DESC LIMIT 20");
        $outboundStmt->bind_param('ss', $selectedContact['nowa'], $selectedContact['clean_wa']);
        $outboundStmt->execute();
        $outboundResult = $outboundStmt->get_result();
        while ($historyRow = $outboundResult->fetch_assoc()) $poloapHistory[] = $historyRow;
        $outboundStmt->close();

        if (!$poloapHistory && !empty($selectedContact['template_history'])) {
            foreach (array_reverse(array_filter(explode('|||', $selectedContact['template_history']))) as $legacyHistory) {
                $poloapHistory[] = [
                    'sent_at' => null,
                    'template_name' => $legacyHistory,
                    'message' => '',
                    'status' => 'legacy'
                ];
            }
        }
    }
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
function crmChatDate(?string $date): string {
    if (!$date) return '';
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y, H:i', $timestamp) : '';
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
    <div class="chat-page-actions">
        <button type="button" class="chat-add-prospect" id="crmAddProspectBtn"><i class="fa-solid fa-user-plus"></i> Tambah Prospek</button>
        <?php if ($selectedContact): ?>
        <a class="chat-wa-link" href="https://wa.me/<?= htmlspecialchars($selectedContact['clean_wa']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
    <?php endif; ?>
</section>

<div class="crm-modal" id="crmAddProspectModal" hidden>
    <div class="crm-modal-card">
        <div class="crm-modal-head"><div><span class="eyebrow">Chat CRM</span><h2>Tambah Prospek</h2></div><button type="button" id="crmAddProspectClose" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button></div>
        <form method="post" action="actions/add-prospect.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <label><span>Nama</span><input type="text" name="nama" required maxlength="150" placeholder="Nama prospek"></label>
            <label><span>Nomor WhatsApp</span><input type="tel" name="nowa" required maxlength="30" placeholder="08xxxxxxxxxx"></label>
            <div class="crm-modal-foot"><button type="button" class="crm-modal-secondary" id="crmAddProspectCancel">Batal</button><button type="submit" class="chat-add-prospect"><i class="fa-solid fa-user-plus"></i> Simpan Prospek</button></div>
        </form>
    </div>
</div>

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
            <div class="chat-history-section">
                <div class="section-title-row"><span class="message-label">Percakapan terbaru</span><small><?= count($recentMessages) ?> log terakhir</small></div>
                <div class="chat-history-scroll">
                    <?php foreach (array_reverse($recentMessages) as $historyRow): ?>
                        <div class="chat-log-item">
                            <div class="chat-log-meta">
                                <time><?= htmlspecialchars(crmChatDate($historyRow['created_at'])) ?></time>
                                <?php if (!empty($historyRow['is_form_sent'])): ?><span class="chat-log-badge">Form</span><?php endif; ?>
                            </div>
                            <p><?= nl2br(htmlspecialchars((string)$historyRow['message'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="poloap-history-section">
                <div class="section-title-row"><span class="message-label">Riwayat Poloap</span><small><?= count($poloapHistory) ?> follow-up</small></div>
                <div class="poloap-history-list">
                    <?php if (!$poloapHistory): ?>
                        <div class="history-empty">Belum ada riwayat Poloap untuk kontak ini.</div>
                    <?php else: foreach ($poloapHistory as $history): ?>
                        <div class="poloap-history-item">
                            <div><strong><?= htmlspecialchars((string)($history['template_name'] ?? 'Pesan')) ?></strong><time><?= htmlspecialchars($history['sent_at'] ? crmChatDate($history['sent_at']) : 'Riwayat lama') ?></time></div>
                            <?php if (!empty($history['message'])): ?><p><?= nl2br(htmlspecialchars((string)$history['message'])) ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <form class="send-box" method="post" action="actions/send-message.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                <input type="hidden" name="contact_id" value="<?= htmlspecialchars($selectedContact['nowa']) ?>">
                <label><span>Template</span><select name="template_id" id="crmTemplateSelect"><option value="">Pilih template...</option><?php foreach ($templates as $template): ?><option value="<?= (int)$template['id'] ?>" data-content="<?= htmlspecialchars($template['content'], ENT_QUOTES) ?>"><?= htmlspecialchars($template['name']) ?></option><?php endforeach; ?></select></label>
                <div class="template-preview" id="crmTemplatePreview"><span>Pilih template untuk melihat isi pesan.</span></div>
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
    const templateSelect = document.getElementById('crmTemplateSelect');
    const templatePreview = document.getElementById('crmTemplatePreview');
    if (templateSelect && templatePreview) {
        templateSelect.addEventListener('change', () => {
            const option = templateSelect.options[templateSelect.selectedIndex];
            let content = option?.dataset?.content || '';
            const contactName = <?= json_encode($selectedContact['nama'] ?? 'Kak', JSON_UNESCAPED_UNICODE) ?>;
            content = content.replace(/\[(nama|NAMA)\]|\{(nama|NAMA)\}/g, contactName);
            templatePreview.innerHTML = content
                ? '<span class="template-preview-label">Preview pesan</span><p>' + content.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') + '</p>'
                : '<span>Pilih template untuk melihat isi pesan.</span>';
        });
    }

    const modal = document.getElementById('crmAddProspectModal');
    const openBtn = document.getElementById('crmAddProspectBtn');
    const closeBtn = document.getElementById('crmAddProspectClose');
    const cancelBtn = document.getElementById('crmAddProspectCancel');
    const closeModal = () => { if (modal) modal.hidden = true; };
    if (openBtn && modal) openBtn.onclick = () => { modal.hidden = false; modal.querySelector('input[name="nama"]')?.focus(); };
    if (closeBtn) closeBtn.onclick = closeModal;
    if (cancelBtn) cancelBtn.onclick = closeModal;
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    setInterval(poll,10000);
})();
</script>
