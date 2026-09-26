<?php
$crmTitle = 'Follow Up';
require_once __DIR__ . '/../config/prospect.php';
$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
$range = (string)($_GET['range'] ?? 'today');
$selected = trim((string)($_GET['contact'] ?? ''));
$chatPage = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;

$allowedStatus = ['all', 'new', 'followed'];
if (!in_array($status, $allowedStatus, true)) $status = 'all';

$allowedRanges = ['today', 'week', 'month', 'all'];
if (!in_array($range, $allowedRanges, true)) $range = 'today';

$rangeSql = [
    'today' => "last_inbound_at >= CURDATE()",
    'week'  => "last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
    'month' => "last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    'all'   => '1=1',
][$range];

$conversationWhere = [$rangeSql];
$conversationBind = [];
$conversationTypes = '';

if ($status === 'new') {
    $conversationWhere[] = 'unread_count > 0';
} elseif ($status === 'followed') {
    $conversationWhere[] = 'unread_count = 0';
}

if ($search !== '') {
    $conversationWhere[] = "(
        nama LIKE ?
        OR nowa LIKE ?
        OR EXISTS (
            SELECT 1
            FROM crm_messages cm_search
            WHERE cm_search.conversation_id = crm_conversations.id
              AND cm_search.message LIKE ?
        )
    )";
    $like = '%' . $search . '%';
    $conversationBind = [$like, $like, $like];
    $conversationTypes = 'sss';
}

$conversationWhereSql = implode(' AND ', $conversationWhere);

$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM crm_conversations
     WHERE {$conversationWhereSql}"
);
if ($conversationTypes !== '') $countStmt->bind_param($conversationTypes, ...$conversationBind);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$countStmt->close();

$totalContacts = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalContacts / $perPage));
$chatPage = min($chatPage, $totalPages);
$offset = ($chatPage - 1) * $perPage;

$conversationSql = "SELECT
        id,
        nowa,
        nama,
        status,
        last_message_at,
        last_inbound_at,
        last_outbound_at,
        last_read_at,
        unread_count,
        followup_count,
        (
            SELECT cm.message
            FROM crm_messages cm
            WHERE cm.conversation_id = crm_conversations.id
            ORDER BY cm.sent_at DESC, cm.id DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT cm.direction
            FROM crm_messages cm
            WHERE cm.conversation_id = crm_conversations.id
            ORDER BY cm.sent_at DESC, cm.id DESC
            LIMIT 1
        ) AS last_direction
    FROM crm_conversations
    WHERE {$conversationWhereSql}
    ORDER BY COALESCE(last_message_at, last_inbound_at, last_outbound_at, created_at) DESC, id DESC
    LIMIT ? OFFSET ?";

$conversationStmt = $conn->prepare($conversationSql);
if ($conversationTypes !== '') {
    $conversationTypes .= 'ii';
    $conversationBind[] = $perPage;
    $conversationBind[] = $offset;
    $conversationStmt->bind_param($conversationTypes, ...$conversationBind);
} else {
    $conversationStmt->bind_param('ii', $perPage, $offset);
}
$conversationStmt->execute();
$conversationResult = $conversationStmt->get_result();

$contacts = [];
while ($row = $conversationResult->fetch_assoc()) {
    $row['clean_wa'] = crmProspectNormalizeNumber((string)$row['nowa']);
    $row['has_new_message'] = (int)$row['unread_count'] > 0;
    $contacts[] = $row;
}
$conversationStmt->close();

$stats = [
    'today' => ['total' => 0, 'unread' => 0, 'read_count' => 0],
    'week'  => ['total' => 0, 'unread' => 0, 'read_count' => 0],
    'month' => ['total' => 0, 'unread' => 0, 'read_count' => 0],
    'all'   => ['total' => 0, 'unread' => 0, 'read_count' => 0],
];

$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(last_inbound_at >= CURDATE()), 0) AS today_total,
        COALESCE(SUM(last_inbound_at >= CURDATE() AND unread_count > 0), 0) AS today_unread,
        COALESCE(SUM(last_inbound_at >= CURDATE() AND unread_count = 0), 0) AS today_read,
        COALESCE(SUM(last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)), 0) AS week_total,
        COALESCE(SUM(last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND unread_count > 0), 0) AS week_unread,
        COALESCE(SUM(last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND unread_count = 0), 0) AS week_read,
        COALESCE(SUM(last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS month_total,
        COALESCE(SUM(last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND unread_count > 0), 0) AS month_unread,
        COALESCE(SUM(last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND unread_count = 0), 0) AS month_read,
        COALESCE(SUM(unread_count > 0), 0) AS all_unread,
        COALESCE(SUM(unread_count = 0), 0) AS all_read
     FROM crm_conversations"
);
if ($statsResult && ($statsRow = $statsResult->fetch_assoc())) {
    $stats = [
        'today' => [
            'total' => (int)$statsRow['today_total'],
            'unread' => (int)$statsRow['today_unread'],
            'read_count' => (int)$statsRow['today_read'],
        ],
        'week' => [
            'total' => (int)$statsRow['week_total'],
            'unread' => (int)$statsRow['week_unread'],
            'read_count' => (int)$statsRow['week_read'],
        ],
        'month' => [
            'total' => (int)$statsRow['month_total'],
            'unread' => (int)$statsRow['month_unread'],
            'read_count' => (int)$statsRow['month_read'],
        ],
        'all' => [
            'total' => (int)$statsRow['total'],
            'unread' => (int)$statsRow['all_unread'],
            'read_count' => (int)$statsRow['all_read'],
        ],
    ];
}

$maxLogId = 0;

$selectedContact = null;
$recentMessages = [];
$poloapHistory = [];

if ($selected !== '') {
    $selectedNumber = crmProspectNormalizeNumber($selected);
    $selectedStmt = $conn->prepare(
        "SELECT id,nowa,nama,status,last_message_at,last_inbound_at,last_outbound_at,last_read_at,unread_count,followup_count
         FROM crm_conversations
         WHERE nowa = ? OR nowa = ?
         LIMIT 1"
    );
    $selectedStmt->bind_param('ss', $selected, $selectedNumber);
    $selectedStmt->execute();
    $selectedContact = $selectedStmt->get_result()->fetch_assoc() ?: null;
    $selectedStmt->close();

    if ($selectedContact) {
        $selectedContact['clean_wa'] = $selectedNumber;

        $historyStmt = $conn->prepare(
            "SELECT id,nowa,message,direction,sender_type,source,template_id,template_name,sent_at
             FROM crm_messages
             WHERE conversation_id = ?
             ORDER BY sent_at DESC, id DESC
             LIMIT 50"
        );
        $historyStmt->bind_param('i', $selectedContact['id']);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        while ($historyRow = $historyResult->fetch_assoc()) $recentMessages[] = $historyRow;
        $historyStmt->close();

        $outboundStmt = $conn->prepare(
            "SELECT h.id,h.template_id,h.template_name,h.message,h.sent_at,h.status
             FROM crm_message_history h
             WHERE (h.nowa = ? OR h.nowa = ?)
               AND NOT EXISTS (
                   SELECT 1
                   FROM crm_messages cm
                   WHERE cm.nowa = h.nowa
                     AND cm.direction = 'out'
                     AND cm.message = h.message
                     AND ABS(TIMESTAMPDIFF(SECOND, cm.sent_at, h.sent_at)) <= 120
               )
             ORDER BY h.sent_at DESC, h.id DESC
             LIMIT 20"
        );
        $outboundStmt->bind_param('ss', $selectedContact['nowa'], $selectedContact['clean_wa']);
        $outboundStmt->execute();
        $outboundResult = $outboundStmt->get_result();
        while ($historyRow = $outboundResult->fetch_assoc()) $poloapHistory[] = $historyRow;
        $outboundStmt->close();
    }
}

$templates = [];
$templateResult = $conn->query("SELECT id,name,content FROM poloap_templates ORDER BY name ASC");
if ($templateResult) while ($template = $templateResult->fetch_assoc()) $templates[] = $template;

$triggers = crmGetProspectTriggers($conn);

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
function crmChatUrl(string $search, string $status, string $range = 'today', string $contact = '', int $chatPage = 1): string {
    $params = ['page'=>'chat','status'=>$status,'range'=>$range];
    if ($search !== '') $params['q'] = $search;
    if ($contact !== '') $params['contact'] = $contact;
    if ($chatPage > 1) $params['p'] = $chatPage;
    return '?' . http_build_query($params);
}
$currentStats = $stats[$range];
$allContactCount = $currentStats['total'];
$newContactCount = $currentStats['unread'];
$followedContactCount = $currentStats['read_count'];

?>

<section class="page-head chat-page-head">
    <div>
        <span class="eyebrow">CRM Follow Up</span>
        <h1>Follow Up</h1>
        <p>Kelola prospek dan tindak lanjuti percakapan tanpa keluar dari workspace CRM.</p>
    </div>
    <div class="chat-page-actions">
        <button type="button" class="chat-add-trigger" id="crmAddTriggerBtn"><i class="fa-solid fa-bolt"></i> Tambah Trigger</button>
        <?php if ($selectedContact): ?>
        <a class="chat-wa-link" href="https://wa.me/<?= htmlspecialchars($selectedContact['clean_wa']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
        <?php endif; ?>
    </div>
</section>

<div class="crm-modal" id="crmAddTriggerModal" hidden>
    <div class="crm-modal-card crm-trigger-modal-card">
        <div class="crm-modal-head">
            <div>
                <span class="eyebrow">Follow Up CRM</span>
                <h2>Tambah Trigger</h2>
                <p class="crm-modal-subtitle">Tambahkan frasa chat yang otomatis dianggap sebagai prospek baru.</p>
            </div>
            <button type="button" id="crmAddTriggerClose" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post" action="actions/add-trigger.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <label><span>Trigger chat</span><input type="text" name="keyword" required maxlength="120" placeholder="Contoh: paket weekend"></label>
            <label><span>Kategori</span><input type="text" name="category" required maxlength="100" placeholder="Contoh: Paket Weekend"></label>
            <div class="trigger-helper"><i class="fa-solid fa-circle-info"></i><span>Trigger dicocokkan dari isi chat. Pertanyaan umum seperti “mau tanya...” tetap tidak dianggap prospek kecuali ada intent ikut/daftar.</span></div>
            <div class="trigger-current">
                <div class="section-title-row"><span class="message-label">Trigger aktif</span><small><?= count($triggers) ?> trigger</small></div>
                <div class="trigger-chip-list">
                    <?php foreach ($triggers as $trigger): ?>
                        <span class="trigger-chip"><strong><?= htmlspecialchars($trigger['keyword']) ?></strong><small><?= htmlspecialchars($trigger['category']) ?></small></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="crm-modal-foot"><button type="button" class="crm-modal-secondary" id="crmAddTriggerCancel">Batal</button><button type="submit" class="chat-add-trigger"><i class="fa-solid fa-plus"></i> Simpan Trigger</button></div>
        </form>
    </div>
</div>

<div class="chat-range-tabs" aria-label="Rentang waktu percakapan">
    <?php foreach (['today' => 'Hari Ini', 'week' => 'Minggu Ini', 'month' => 'Bulan Ini', 'all' => 'Semua Waktu'] as $rangeKey => $rangeLabel): ?>
        <a class="<?= $range === $rangeKey ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search, $status, $rangeKey)) ?>">
            <?= htmlspecialchars($rangeLabel) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="chat-stats">
    <a data-chat-stat="all" class="<?= $status === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search,'all',$range)) ?>"><strong><?= $allContactCount ?></strong><span>Semua</span></a>
    <a data-chat-stat="new" class="<?= $status === 'new' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search,'new',$range)) ?>"><strong><?= $newContactCount ?></strong><span>Baru</span></a>
    <a data-chat-stat="followed" class="<?= $status === 'followed' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search,'followed',$range)) ?>"><strong><?= $followedContactCount ?></strong><span>Follow-up</span></a>
</div>

<form class="search-box" method="get">
    <input type="hidden" name="page" value="chat"><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, nomor, atau isi pesan...">
    <?php if ($search): ?><a href="<?= htmlspecialchars(crmChatUrl('', $status, $range)) ?>"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
</form>

<div class="chat-sheet-backdrop" id="crmChatSheetBackdrop" aria-hidden="true"></div>

<div class="chat-layout">
    <div class="chat-list">
        <?php if (!$contacts): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Tidak ada percakapan</strong><p>Belum ada data yang cocok dengan filter ini.</p></div>
        <?php else: foreach ($contacts as $row): ?>
            <?php
                $name = trim((string)($row['nama'] ?? '')) ?: 'Hamba Allah';
                $lastMessage = trim((string)($row['last_message'] ?? ''));
                $lastDirection = (string)($row['last_direction'] ?? '');
                $activityLabel = $lastDirection === 'in' ? 'Pesan masuk' : 'Dikirim';
                $isSelected = $selected !== '' && crmProspectNormalizeNumber($selected) === $row['clean_wa'];
                $lastActivityAt = $row['last_message_at'] ?: ($row['last_inbound_at'] ?: $row['last_outbound_at']);
            ?>
            <a data-chat-nowa="<?= htmlspecialchars($row['nowa']) ?>" href="<?= htmlspecialchars(crmChatUrl($search,$status,$range,$row['nowa'],$chatPage)) ?>" class="chat-item <?= $isSelected ? 'selected' : '' ?> <?= !empty($row['has_new_message']) ? 'is-new' : '' ?>">
                <span class="activity-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($name,0,1))) ?></span>
                <span class="chat-body">
                    <strong><?= htmlspecialchars($name) ?></strong>
                    <small><?= htmlspecialchars($activityLabel) ?> · <?= htmlspecialchars(crmPreview($lastMessage)) ?></small>
                </span>
                <span class="chat-meta">
                    <time><?= htmlspecialchars($lastActivityAt ? date('H:i', strtotime($lastActivityAt)) : '') ?></time>
                    <?php if (!empty($row['has_new_message'])): ?>
                        <b class="chat-new-badge">BARU</b>
                    <?php elseif ((int)$row['followup_count'] > 0): ?>
                        <i class="fa-solid fa-check-double" title="<?= (int)$row['followup_count'] ?> follow-up"></i>
                    <?php endif; ?>
                </span>
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
                <a class="chat-panel-close" href="<?= htmlspecialchars(crmChatUrl($search,$status,$range,'',$chatPage)) ?>" aria-label="Tutup percakapan"><i class="fa-solid fa-xmark"></i></a>
            </div>
            <div class="chat-history-section">
                <div class="section-title-row"><span class="message-label">Percakapan terbaru</span><small><?= count($recentMessages) ?> log terakhir</small></div>
                <div class="chat-history-scroll">
                    <?php foreach (array_reverse($recentMessages) as $historyRow): ?>
                        <div class="chat-log-item <?= ($historyRow['direction'] ?? '') === 'in' ? 'chat-log-in' : 'chat-log-out' ?>">
                            <div class="chat-log-meta">
                                <time><?= htmlspecialchars(crmChatDate($historyRow['sent_at'] ?? null)) ?></time>
                                <span class="chat-log-badge"><?= ($historyRow['direction'] ?? '') === 'in' ? 'Masuk' : 'Admin' ?></span>
                            </div>
                            <p><?= nl2br(htmlspecialchars((string)$historyRow['message'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="poloap-history-section">
                <div class="section-title-row"><span class="message-label">Riwayat Follow-up Lama</span><small><?= count($poloapHistory) ?> log lama</small></div>
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

<?php if ($totalPages > 1): ?>
<nav class="chat-pagination" aria-label="Pagination Chat">
    <?php if ($chatPage > 1): ?>
        <a href="<?= htmlspecialchars(crmChatUrl($search,$status,$range,'',$chatPage - 1)) ?>"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</a>
    <?php else: ?>
        <span class="disabled"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</span>
    <?php endif; ?>
    <strong>Halaman <?= $chatPage ?> / <?= $totalPages ?></strong>
    <?php if ($chatPage < $totalPages): ?>
        <a href="<?= htmlspecialchars(crmChatUrl($search,$status,$range,'',$chatPage + 1)) ?>">Berikutnya <i class="fa-solid fa-chevron-right"></i></a>
    <?php else: ?>
        <span class="disabled">Berikutnya <i class="fa-solid fa-chevron-right"></i></span>
    <?php endif; ?>
</nav>
<?php endif; ?>

<script>
(() => {
    document.body.classList.toggle('crm-chat-sheet-open', <?= $selectedContact ? 'true' : 'false' ?>);

    const selectedNumber = <?= $selectedContact ? json_encode($selectedContact['nowa']) : 'null' ?>;
    const csrfToken = <?= json_encode(crmCsrfToken()) ?>;

    if (selectedNumber) {
        const form = new URLSearchParams();
        form.set('nowa', selectedNumber);
        form.set('csrf', csrfToken);

        fetch('actions/chat-mark-read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: form.toString(),
            credentials: 'same-origin',
            keepalive: true
        }).then(response => {
            if (!response.ok) return;
            document.querySelectorAll('.chat-item.is-new').forEach(item => {
                if (item.getAttribute('href')?.includes(encodeURIComponent(selectedNumber))) {
                    item.classList.remove('is-new');
                    item.querySelector('.chat-new-badge')?.remove();
                }
            });
        }).catch(() => {});
    }

    const sheetBackdrop = document.getElementById('crmChatSheetBackdrop');
    const sheetClose = document.querySelector('.chat-panel-close');
    if (sheetBackdrop) sheetBackdrop.addEventListener('click', () => sheetClose?.click());

    const templateSelect = document.getElementById('crmTemplateSelect');
    const templatePreview = document.getElementById('crmTemplatePreview');
    if (templateSelect && templatePreview) {
        templateSelect.addEventListener('change', () => {
            const option = templateSelect.options[templateSelect.selectedIndex];
            let content = option?.dataset?.content || '';
            const contactName = <?= json_encode($selectedName ?? 'Kak', JSON_UNESCAPED_UNICODE) ?>;
            content = content.replace(/\[(nama|NAMA)\]|\{(nama|NAMA)\}/g, contactName);
            templatePreview.innerHTML = content
                ? '<span class="template-preview-label">Preview pesan</span><p>' + content.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') + '</p>'
                : '<span>Pilih template untuk melihat isi pesan.</span>';
        });
    }

    const modal = document.getElementById('crmAddTriggerModal');
    const openBtn = document.getElementById('crmAddTriggerBtn');
    const closeBtn = document.getElementById('crmAddTriggerClose');
    const cancelBtn = document.getElementById('crmAddTriggerCancel');
    const closeModal = () => { if (modal) modal.hidden = true; };
    if (openBtn && modal) openBtn.onclick = () => { modal.hidden = false; modal.querySelector('input[name="keyword"]')?.focus(); };
    if (closeBtn) closeBtn.onclick = closeModal;
    if (cancelBtn) cancelBtn.onclick = closeModal;
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    const chatPollUrl = <?= json_encode('actions/chat-poll.php') ?>;
    const chatCurrentSearch = <?= json_encode($search) ?>;
    const chatCurrentStatus = <?= json_encode($status) ?>;
    const chatCurrentRange = <?= json_encode($range) ?>;
    const chatCurrentPage = <?= (int)$chatPage ?>;
    let chatPollCursor = <?= json_encode(date('Y-m-d H:i:s')) ?>;
    let chatPollBusy = false;

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const updateChatStats = (stats) => {
        if (!stats) return;
        const values = {
            all: stats.total,
            new: stats.unread,
            followed: stats.read_count
        };
        Object.entries(values).forEach(([key, value]) => {
            const node = document.querySelector('[data-chat-stat="' + key + '"] strong');
            if (node && Number.isFinite(Number(value))) node.textContent = String(value);
        });
    };

    const chatItemUrl = (nowa) => {
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'chat');
        params.set('status', chatCurrentStatus);
        params.set('range', chatCurrentRange);
        params.set('q', chatCurrentSearch);
        params.set('contact', nowa);
        params.set('p', String(chatCurrentPage));
        return '?' + params.toString();
    };

    const buildChatItem = (row) => {
        const name = String(row.nama || 'Hamba Allah').trim() || 'Hamba Allah';
        const preview = String(row.last_message || '').replace(/\s+/g, ' ').trim().slice(0, 68);
        const direction = row.last_direction === 'in' ? 'Pesan masuk' : 'Dikirim';
        const time = row.last_message_at ? new Date(row.last_message_at.replace(' ', 'T')).toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'}) : '';
        const item = document.createElement('a');
        item.className = 'chat-item' + (row.unread_count > 0 ? ' is-new' : '');
        item.dataset.chatNowa = row.nowa;
        item.href = chatItemUrl(row.nowa);
        item.innerHTML =
            '<span class="activity-avatar">' + escapeHtml(name.slice(0, 1).toUpperCase()) + '</span>' +
            '<span class="chat-body"><strong>' + escapeHtml(name) + '</strong>' +
            '<small>' + escapeHtml(direction + ' · ' + preview) + '</small></span>' +
            '<span class="chat-meta"><time>' + escapeHtml(time) + '</time>' +
            (row.unread_count > 0
                ? '<b class="chat-new-badge">BARU</b>'
                : (row.followup_count > 0 ? '<i class="fa-solid fa-check-double" title="' + escapeHtml(String(row.followup_count) + ' follow-up') + '"></i>' : '')) +
            '</span>';
        return item;
    };

    const updateChatItem = (row) => {
        const item = document.querySelector('.chat-item[data-chat-nowa="' + CSS.escape(row.nowa) + '"]');
        if (!item) return;

        const body = item.querySelector('.chat-body');
        const meta = item.querySelector('.chat-meta');
        const lastDirection = row.last_direction === 'in' ? 'Pesan masuk' : 'Dikirim';
        const preview = String(row.last_message || '').replace(/\s+/g, ' ').trim();
        const time = row.last_message_at ? new Date(row.last_message_at.replace(' ', 'T')).toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'}) : '';

        if (body) {
            const name = body.querySelector('strong');
            const detail = body.querySelector('small');
            if (name && row.nama) name.textContent = row.nama;
            if (detail) detail.textContent = lastDirection + ' · ' + preview.slice(0, 68);
        }

        if (meta) {
            const timeNode = meta.querySelector('time');
            if (timeNode) timeNode.textContent = time;
            if (row.unread_count > 0) {
                item.classList.add('is-new');
                if (!meta.querySelector('.chat-new-badge')) {
                    const badge = document.createElement('b');
                    badge.className = 'chat-new-badge';
                    badge.textContent = 'BARU';
                    meta.appendChild(badge);
                }
            }
        }
    };

    const syncChatList = (row) => {
        const list = document.querySelector('.chat-list');
        if (!list) return;

        const item = document.querySelector('.chat-item[data-chat-nowa="' + CSS.escape(row.nowa) + '"]');
        if (!row.matches_filter) {
            if (item && !item.classList.contains('selected')) item.remove();
            return;
        }

        if (item) {
            updateChatItem(row);
            return;
        }

        if (chatCurrentPage !== 1) return;

        const newItem = buildChatItem(row);
        list.prepend(newItem);

        const items = list.querySelectorAll('.chat-item');
        if (items.length > 20) items[items.length - 1].remove();

        list.querySelector('.empty-state')?.remove();
    };

    const pollChat = async () => {
        if (chatPollBusy || document.hidden) return;
        chatPollBusy = true;
        try {
            const response = await fetch(chatPollUrl + '?since=' + encodeURIComponent(chatPollCursor), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data?.ok) return;

            chatPollCursor = data.server_time || chatPollCursor;
            updateChatStats(data.stats);
            for (const row of (data.conversations || [])) syncChatList(row);
        } catch (_) {
            // Polling is non-critical. The next interval retries without disrupting Chat.
        } finally {
            chatPollBusy = false;
        }
    };

    window.setInterval(pollChat, 10000);
})();
</script>