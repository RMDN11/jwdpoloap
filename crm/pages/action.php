<?php
$crmTitle = 'Action';
require_once __DIR__ . '/../config/prospect.php';

$actionContacts = [];
$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);
$contactResult = $conn->query("SELECT id, nama, nowa, message, created_at FROM log_wa WHERE nowa IS NOT NULL AND nowa != '' ORDER BY id DESC LIMIT 500");
if ($contactResult) {
    $seenContacts = [];
    while ($contactRow = $contactResult->fetch_assoc()) {
        $normalized = crmProspectNormalizeNumber((string)$contactRow['nowa']);
        if ($normalized === '' || isset($seenContacts[$normalized])) continue;
        if (!crmIsEligibleProspect($contactRow, $disqualified, $blocked, $conn)) continue;
        $seenContacts[$normalized] = true;
        $actionContacts[] = [
            'nowa' => $normalized,
            'nama' => trim((string)$contactRow['nama']) ?: 'Hamba Allah',
        ];
        if (count($actionContacts) >= 100) break;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }
    $actionId = (int)($_POST['action_id'] ?? 0);
    if ($actionId > 0 && isset($_POST['complete_action'])) {
        $stmt = $conn->prepare("UPDATE crm_actions SET status='completed', completed_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
        if ($stmt) { $stmt->bind_param('i', $actionId); $stmt->execute(); $stmt->close(); }
        header('Location: ?page=action'); exit;
    }
}
$now = date('Y-m-d H:i:s'); $todayStart = date('Y-m-d 00:00:00'); $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$counts = ['overdue'=>0,'today'=>0,'upcoming'=>0,'completed'=>0];
$queries = [
    'overdue' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='pending' AND due_at IS NOT NULL AND due_at < ?",
    'today' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='pending' AND due_at >= ? AND due_at < ?",
    'upcoming' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='pending' AND (due_at >= ? OR due_at IS NULL)",
    'completed' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='completed'"
];
foreach ($queries as $key=>$sql) { $stmt=$conn->prepare($sql); if(!$stmt) continue; if($key==='today') $stmt->bind_param('ss',$todayStart,$tomorrowStart); elseif($key==='overdue') $stmt->bind_param('s',$now); elseif($key==='upcoming') $stmt->bind_param('s',$tomorrowStart); $stmt->execute(); $counts[$key]=(int)($stmt->get_result()->fetch_assoc()['total']??0); $stmt->close(); }
$actions=[]; $result=$conn->query("SELECT id,contact_nowa,contact_name,title,description,type,priority,status,due_at,created_at FROM crm_actions ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END, CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, id DESC LIMIT 50"); if($result) while($row=$result->fetch_assoc()) $actions[]=$row;
function crmActionDueLabel(?string $dueAt): string { if(!$dueAt)return 'Tanpa deadline'; $ts=strtotime($dueAt); return $ts?date('d M · H:i',$ts):'Tanpa deadline'; }
function crmActionDueClass(?string $dueAt,string $status): string { if($status==='completed')return 'is-complete'; if(!$dueAt)return ''; return strtotime($dueAt)<time()?'is-overdue':''; }
?>
<section class="page-head action-page-head"><div><span class="eyebrow">Workspace</span><h1>Action</h1><p>Kelola pekerjaan yang perlu ditindaklanjuti dari satu tempat.</p></div><button type="button" class="action-create-btn" id="crmCreateActionBtn"><i class="fa-solid fa-plus"></i> Action</button></section>

<div class="crm-modal" id="crmCreateActionModal" hidden>
    <div class="crm-modal-card crm-action-modal-card">
        <div class="crm-modal-head">
            <div>
                <span class="eyebrow">Action CRM</span>
                <h2>Buat Action</h2>
                <p class="crm-modal-subtitle">Tambahkan pekerjaan yang perlu ditindaklanjuti.</p>
            </div>
            <button type="button" id="crmCreateActionClose" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="post" action="actions/create-action.php" class="crm-action-create-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">

            <label>
                <span>Kontak <small>(opsional)</small></span>
                <input type="text" name="contact_nowa" list="crmActionContacts" inputmode="tel" placeholder="Cari nama atau nomor WhatsApp...">
                <datalist id="crmActionContacts">
                    <?php foreach ($actionContacts as $contact): ?>
                        <option value="<?= htmlspecialchars($contact['nowa']) ?>" label="<?= htmlspecialchars($contact['nama']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small class="crm-field-hint">Pilih nomor dari prospek aktif agar Action terhubung ke kontak CRM.</small>
            </label>

            <label>
                <span>Judul Action</span>
                <input type="text" name="title" maxlength="180" required placeholder="Contoh: Follow-up pendaftaran">
            </label>

            <label>
                <span>Deskripsi <small>(opsional)</small></span>
                <textarea name="description" rows="3" maxlength="2000" placeholder="Catatan singkat untuk Action ini..."></textarea>
            </label>

            <div class="crm-action-form-grid">
                <label>
                    <span>Tipe</span>
                    <select name="type">
                        <option value="task">Task</option>
                        <option value="follow_up">Follow-up</option>
                        <option value="call">Call</option>
                        <option value="message">Message</option>
                        <option value="other">Lainnya</option>
                    </select>
                </label>

                <label>
                    <span>Prioritas</span>
                    <select name="priority">
                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </label>
            </div>

            <label>
                <span>Deadline <small>(opsional)</small></span>
                <input type="datetime-local" name="due_at">
            </label>

            <div class="crm-modal-foot">
                <button type="button" class="crm-modal-secondary" id="crmCreateActionCancel">Batal</button>
                <button type="submit" class="action-create-submit"><i class="fa-solid fa-check"></i> Simpan Action</button>
            </div>
        </form>
    </div>
</div>
<div class="action-summary-grid"><div class="action-summary-card is-overdue"><span>🔴</span><strong><?=$counts['overdue']?></strong><small>Terlambat</small></div><div class="action-summary-card is-today"><span>🟠</span><strong><?=$counts['today']?></strong><small>Hari ini</small></div><div class="action-summary-card is-upcoming"><span>🟡</span><strong><?=$counts['upcoming']?></strong><small>Mendatang</small></div><div class="action-summary-card is-completed"><span>🟢</span><strong><?=$counts['completed']?></strong><small>Selesai</small></div></div>
<section class="section action-workspace-section"><div class="section-heading"><h2>Daftar Action</h2><span><?=count($actions)?> terakhir</span></div><div class="action-workspace-list"><?php if(!$actions): ?><div class="empty-state action-empty"><i class="fa-regular fa-circle-check"></i><strong>Belum ada Action</strong><p>Action yang dibuat dari workflow CRM akan muncul di sini.</p></div><?php else: foreach($actions as $item): ?><article class="crm-action-card <?=htmlspecialchars(crmActionDueClass($item['due_at'],$item['status']))?>"><div class="crm-action-icon"><i class="fa-solid <?=$item['status']==='completed'?'fa-check':'fa-list-check'?>"></i></div><div class="crm-action-body"><div class="crm-action-top"><strong><?=htmlspecialchars($item['title'])?></strong><span class="crm-action-priority priority-<?=htmlspecialchars($item['priority'])?>"><?=htmlspecialchars(ucfirst($item['priority']))?></span></div><?php if(!empty($item['contact_name'])):?><small><?=htmlspecialchars($item['contact_name'])?><?=$item['contact_nowa']?' · '.htmlspecialchars($item['contact_nowa']):''?></small><?php endif;?><?php if(!empty($item['description'])):?><p><?=htmlspecialchars($item['description'])?></p><?php endif;?><div class="crm-action-meta"><span class="crm-action-due"><i class="fa-regular fa-clock"></i> <?=htmlspecialchars(crmActionDueLabel($item['due_at']))?></span><span><?=htmlspecialchars(ucfirst($item['type']))?></span></div></div><?php if($item['status']==='pending'): ?><form method="post" class="crm-action-complete-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(crmCsrfToken())?>"><input type="hidden" name="action_id" value="<?=(int)$item['id']?>"><button name="complete_action" value="1" aria-label="Tandai selesai"><i class="fa-solid fa-check"></i></button></form><?php endif;?></article><?php endforeach; endif;?></div></section>

<script>
(() => {
    const modal = document.getElementById('crmCreateActionModal');
    const openBtn = document.getElementById('crmCreateActionBtn');
    const closeBtn = document.getElementById('crmCreateActionClose');
    const cancelBtn = document.getElementById('crmCreateActionCancel');

    function closeModal() {
        if (modal) modal.hidden = true;
        document.body.classList.remove('crm-modal-open');
    }

    function openModal() {
        if (modal) modal.hidden = false;
        document.body.classList.add('crm-modal-open');
        window.setTimeout(() => modal?.querySelector('input[name="title"]')?.focus(), 30);
    }

    openBtn?.addEventListener('click', openModal);
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) closeModal();
    });
})();
</script>
