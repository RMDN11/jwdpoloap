<?php
declare(strict_types=1);

$crmTitle = 'Reminder Peserta';
$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$selectedRequestId = (int)($_GET['request_id'] ?? 0);

function rpInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (!$parts) return '?';
    return strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr($parts[count($parts)-1], 0, 1) : ''));
}
function rpTime(string $value): string {
    $ts = strtotime($value);
    return $ts ? date('d M Y, H:i', $ts) : '-';
}
function rpMessage(string $template, string $name, string $nowa): string {
    return str_ireplace(
        ['{peserta_nama}', '{peserta_nowa}', '{nama}', '[nama]'],
        [$name, $nowa, $name, $name],
        $template
    );
}

/* Preserve the legacy daily reset from kelola_reminder.php. */
$lastResetDate = null;
$resetStmt = $conn->prepare("SELECT value FROM reminder_configs WHERE \`key\` = 'last_reset_date' LIMIT 1");
if ($resetStmt) {
    $resetStmt->execute();
    $row = $resetStmt->get_result()->fetch_assoc();
    $lastResetDate = $row['value'] ?? null;
    $resetStmt->close();
}
if ($lastResetDate !== date('Y-m-d')) {
    $conn->query("UPDATE reminder_requests SET status = 'menunggu'");
    $updateResetStmt = $conn->prepare("
        INSERT INTO reminder_configs (\`key\`, \`value\`)
        VALUES ('last_reset_date', ?)
        ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`)
    ");
    if ($updateResetStmt) {
        $today = date('Y-m-d');
        $updateResetStmt->bind_param('s', $today);
        $updateResetStmt->execute();
        $updateResetStmt->close();
    }
}

$halaqohList = [];
$result = $conn->query("SELECT DISTINCT halaqoh FROM reminder_requests WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($result) while ($row = $result->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$stats = ['total'=>0,'pending'=>0,'sent'=>0];
$result = $conn->query("SELECT COUNT(*) total, SUM(status='menunggu') pending, SUM(status='terkirim') sent FROM reminder_requests");
if ($result && ($row = $result->fetch_assoc())) {
    $stats['total'] = (int)($row['total'] ?? 0);
    $stats['pending'] = (int)($row['pending'] ?? 0);
    $stats['sent'] = (int)($row['sent'] ?? 0);
}

$where = [];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = '(peserta_nama LIKE ? OR peserta_nowa LIKE ? OR pesan_pengajar LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($status === 'menunggu' || $status === 'terkirim') {
    $where[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($halaqoh !== '') {
    $where[] = 'halaqoh = ?';
    $params[] = $halaqoh;
    $types .= 's';
}

$sql = "SELECT id, halaqoh, peserta_id, peserta_nama, peserta_nowa, pesan_pengajar, status, created_at, updated_at FROM reminder_requests";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY CASE WHEN status='menunggu' THEN 1 ELSE 2 END, created_at DESC LIMIT 300";

$requests = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($params) {
        $refs = [];
        foreach ($params as $key => $value) $refs[$key] = &$params[$key];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    }
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$templates = [];
$result = $conn->query("SELECT id, name, content FROM reminder_templates ORDER BY name ASC");
if ($result) $templates = $result->fetch_all(MYSQLI_ASSOC);

$selectedRequest = null;
if ($selectedRequestId > 0) {
    foreach ($requests as $item) if ((int)$item['id'] === $selectedRequestId) { $selectedRequest = $item; break; }
}
if (!$selectedRequest) {
    foreach ($requests as $item) if (($item['status'] ?? '') === 'menunggu') { $selectedRequest = $item; break; }
    if (!$selectedRequest && $requests) $selectedRequest = $requests[0];
}
$defaultTemplate = $templates[0] ?? null;
$previewMessage = ($selectedRequest && $defaultTemplate)
    ? rpMessage((string)$defaultTemplate['content'], (string)$selectedRequest['peserta_nama'], (string)$selectedRequest['peserta_nowa'])
    : '';
?>

<section class="reminder-peserta-page">
    <div class="reminder-peserta-back"><a href="?page=reminder" aria-label="Kembali"><i class="fa-solid fa-arrow-left"></i></a></div>

    <div class="reminder-peserta-head">
        <div>
            <span class="reminder-kicker">Workspace</span>
            <h1>Pengingat Peserta</h1>
            <p>Kelola permintaan tadzkir peserta, kirim reminder WhatsApp, dan simpan template yang sering dipakai.</p>
        </div>
        <span class="reminder-peserta-head-badge"><i class="fa-solid fa-clock"></i><?= $stats['pending'] ?> menunggu</span>
    </div>

    <div class="reminder-peserta-stats">
        <article class="reminder-peserta-stat"><span class="reminder-peserta-stat-icon blue"><i class="fa-solid fa-inbox"></i></span><div><strong><?= $stats['total'] ?></strong><small>Total Permintaan</small></div></article>
        <article class="reminder-peserta-stat"><span class="reminder-peserta-stat-icon amber"><i class="fa-solid fa-hourglass-half"></i></span><div><strong><?= $stats['pending'] ?></strong><small>Menunggu</small></div></article>
        <article class="reminder-peserta-stat"><span class="reminder-peserta-stat-icon green"><i class="fa-solid fa-circle-check"></i></span><div><strong><?= $stats['sent'] ?></strong><small>Terkirim</small></div></article>
    </div>

    <div class="reminder-peserta-grid">
        <div class="reminder-peserta-main">
            <section class="reminder-card">
                <div class="reminder-card-head">
                    <div><span class="reminder-kicker">Inbox Reminder</span><h2>Permintaan Peserta</h2></div>
                    <span class="reminder-count"><?= count($requests) ?> ditampilkan</span>
                </div>

                <form method="GET" class="reminder-peserta-filter">
                    <input type="hidden" name="page" value="reminder-peserta">
                    <label class="reminder-peserta-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari peserta, nomor, atau pesan..."></label>
                    <select name="status"><option value="">Semua Status</option><option value="menunggu" <?= $status==='menunggu'?'selected':'' ?>>Menunggu</option><option value="terkirim" <?= $status==='terkirim'?'selected':'' ?>>Terkirim</option></select>
                    <select name="halaqoh"><option value="">Semua Halaqoh</option><?php foreach($halaqohList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select>
                    <button type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                </form>

                <div class="reminder-peserta-bulk">
                    <div><strong>Kirim reminder menunggu</strong><span>Pilih template lalu kirim semua permintaan yang masih menunggu.</span></div>
                    <form method="POST" action="actions/reminder-peserta.php" id="sendAllPesertaForm">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                        <input type="hidden" name="action" value="send_all">
                        <select name="template_id" <?= (!$templates || !$stats['pending'])?'disabled':'' ?>>
                            <?php foreach($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="reminder-peserta-bulk-send" type="submit" <?= (!$templates || !$stats['pending'])?'disabled':'' ?>><i class="fa-solid fa-paper-plane"></i> Kirim Semua</button>
                    </form>
                    <?php if($stats['total']>0): ?><form method="POST" action="actions/reminder-peserta.php" id="deleteAllPesertaForm"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete_all"><button class="reminder-peserta-danger" title="Hapus semua"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?>
                </div>

                <?php if(!$requests): ?>
                    <div class="reminder-empty reminder-peserta-empty"><span class="reminder-peserta-empty-icon"><i class="fa-solid fa-inbox"></i></span><strong>Belum ada permintaan</strong><span>Permintaan dari form Tadzkir akan muncul di sini.</span></div>
                <?php else: ?>
                    <div class="reminder-peserta-list">
                        <?php foreach($requests as $request):
                            $pending = ($request['status'] ?? '') === 'menunggu';
                            $selected = $selectedRequest && (int)$selectedRequest['id'] === (int)$request['id'];
                        ?>
                            <article class="reminder-peserta-item <?= $selected?'is-selected':'' ?>" data-request-id="<?= (int)$request['id'] ?>" data-name="<?= htmlspecialchars((string)$request['peserta_nama'],ENT_QUOTES) ?>" data-nowa="<?= htmlspecialchars((string)$request['peserta_nowa'],ENT_QUOTES) ?>" data-halaqoh="<?= htmlspecialchars((string)$request['halaqoh'],ENT_QUOTES) ?>">
                                <button type="button" class="reminder-peserta-item-main">
                                    <span class="reminder-avatar"><?= htmlspecialchars(rpInitials((string)$request['peserta_nama'])) ?></span>
                                    <span class="reminder-peserta-item-body">
                                        <strong><?= htmlspecialchars((string)$request['peserta_nama']) ?></strong>
                                        <small><?= htmlspecialchars((string)$request['halaqoh']) ?> · <?= htmlspecialchars((string)$request['peserta_nowa'] ?: '-') ?></small>
                                        <span class="reminder-peserta-source"><?= htmlspecialchars(mb_strimwidth((string)$request['pesan_pengajar'],0,120,'…')) ?></span>
                                        <span class="reminder-peserta-time"><?= rpTime((string)$request['created_at']) ?></span>
                                    </span>
                                </button>
                                <span class="reminder-peserta-status <?= $pending?'pending':'sent' ?>"><?= $pending?'Menunggu':'Terkirim' ?></span>
                                <div class="reminder-peserta-actions">
                                    <?php if($pending && $templates): ?><button type="button" class="reminder-peserta-quick-send" title="Kirim" data-quick-send="<?= (int)$request['id'] ?>"><i class="fa-solid fa-paper-plane"></i></button><?php endif; ?>
                                    <form method="POST" action="actions/reminder-peserta.php" class="delete-request-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete_request"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button type="submit" class="reminder-peserta-delete" title="Hapus"><i class="fa-solid fa-trash"></i></button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="reminder-peserta-side">
            <section class="reminder-card reminder-peserta-compose">
                <div class="reminder-card-head"><div><span class="reminder-kicker">Composer</span><h2>Kirim Reminder</h2></div><span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span></div>
                <div id="pesertaTarget" class="reminder-peserta-target">
                    <?php if($selectedRequest): ?><span class="reminder-avatar"><?= htmlspecialchars(rpInitials((string)$selectedRequest['peserta_nama'])) ?></span><div><strong><?= htmlspecialchars((string)$selectedRequest['peserta_nama']) ?></strong><small><?= htmlspecialchars((string)$selectedRequest['halaqoh']) ?> · <?= htmlspecialchars((string)$selectedRequest['peserta_nowa']) ?></small></div>
                    <?php else: ?><span class="reminder-avatar"><i class="fa-solid fa-user"></i></span><div><strong>Pilih permintaan</strong><small>Target reminder tampil di sini.</small></div><?php endif; ?>
                </div>

                <form method="POST" action="actions/reminder-peserta.php" id="reminderPesertaForm">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                    <input type="hidden" name="action" value="send_one">
                    <input type="hidden" name="request_id" id="pesertaRequestId" value="<?= $selectedRequest?(int)$selectedRequest['id']:0 ?>">
                    <div class="reminder-field"><label for="pesertaTemplate">Template</label><select name="template_id" id="pesertaTemplate" <?= !$templates?'disabled':'' ?>><?php foreach($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="reminder-peserta-preview" id="pesertaPreview"><?= $previewMessage!==''?htmlspecialchars($previewMessage):'Pilih permintaan dan template untuk melihat preview pesan.' ?></div>
                    <div class="reminder-peserta-helper">Placeholder <code>{peserta_nama}</code> dan <code>{peserta_nowa}</code> akan diganti otomatis.</div>
                    <button type="submit" class="reminder-send-btn" id="sendPesertaBtn" <?= (!$selectedRequest || !$templates || ($selectedRequest['status']??'')==='terkirim')?'disabled':'' ?>><i class="fa-solid fa-paper-plane"></i> Kirim WhatsApp</button>
                </form>
            </section>

            <section class="reminder-card">
                <div class="reminder-card-head compact"><div><span class="reminder-kicker">Template</span><h2>Pesan Tersimpan</h2></div><span class="reminder-count"><?= count($templates) ?> template</span></div>
                <form method="POST" action="actions/reminder-peserta.php" id="templateForm" class="reminder-peserta-template-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_template">
                    <input type="hidden" name="template_id" id="templateId">
                    <input type="text" name="template_name" id="templateName" maxlength="120" required placeholder="Nama template">
                    <textarea name="template_content" id="templateContent" maxlength="4000" required placeholder="Tulis isi template..."></textarea>
                    <div class="reminder-peserta-template-help">Gunakan <code>{peserta_nama}</code>, <code>{peserta_nowa}</code>, atau <code>{nama}</code>.</div>
                    <div class="reminder-peserta-template-actions"><button type="submit" class="reminder-peserta-save-template"><i class="fa-solid fa-floppy-disk"></i> Simpan</button><button type="button" class="reminder-peserta-clear-template" id="clearTemplateBtn">Batal</button></div>
                </form>
                <?php if($templates): ?><div class="reminder-peserta-template-list"><?php foreach($templates as $tpl): ?><article class="reminder-peserta-template-item"><div><strong><?= htmlspecialchars((string)$tpl['name']) ?></strong><p><?= htmlspecialchars(mb_strimwidth((string)$tpl['content'],0,180,'…')) ?></p></div><div class="reminder-peserta-template-item-actions"><button type="button" class="edit-peserta-template" data-id="<?= (int)$tpl['id'] ?>" data-name="<?= htmlspecialchars((string)$tpl['name'],ENT_QUOTES) ?>" data-content="<?= htmlspecialchars((string)$tpl['content'],ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button><form method="POST" action="actions/reminder-peserta.php" class="delete-template-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button type="submit"><i class="fa-solid fa-trash"></i></button></form></div></article><?php endforeach; ?></div><?php else: ?><div class="reminder-empty compact">Belum ada template tersimpan.</div><?php endif; ?>
            </section>
        </aside>
    </div>
</section>

<script>
(() => {
    const items = [...document.querySelectorAll('.reminder-peserta-item')];
    const templates = <?= json_encode(array_map(static fn(array $t): array => ['id'=>(int)$t['id'],'name'=>(string)$t['name'],'content'=>(string)$t['content']], $templates), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const idEl = document.getElementById('pesertaRequestId'), templateEl = document.getElementById('pesertaTemplate'), previewEl = document.getElementById('pesertaPreview'), targetEl = document.getElementById('pesertaTarget'), sendBtn = document.getElementById('sendPesertaBtn'), form = document.getElementById('reminderPesertaForm');
    let selected = null;
    const esc = v => String(v||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const initials = n => { const p=String(n||'').trim().split(/\s+/).filter(Boolean); return p.length ? (p[0][0]||'')+(p.length>1?(p[p.length-1][0]||''):'') : '?'; };
    const tpl = () => templates.find(t=>t.id===Number(templateEl?.value||0)) || null;
    const render = (t,r) => String(t||'').replace(/\{peserta_nama\}/gi,r.name).replace(/\{peserta_nowa\}/gi,r.nowa).replace(/\{nama\}/gi,r.name).replace(/\[nama\]/gi,r.name);
    function sync(){
        const t=tpl();
        if(!selected){ sendBtn.disabled=true; previewEl.textContent='Pilih permintaan dan template untuk melihat preview pesan.'; return; }
        targetEl.innerHTML='<span class="reminder-avatar">'+esc(initials(selected.name))+'</span><div><strong>'+esc(selected.name)+'</strong><small>'+esc(selected.halaqoh)+' · '+esc(selected.nowa||'-')+'</small></div>';
        idEl.value=String(selected.id);
        previewEl.textContent=t?render(t.content,selected):'Belum ada template.';
        sendBtn.disabled=selected.status!=='menunggu'||!t;
    }
    function selectItem(el){
        items.forEach(x=>x.classList.remove('is-selected')); el.classList.add('is-selected');
        selected={id:Number(el.dataset.requestId),name:el.dataset.name||'',nowa:el.dataset.nowa||'',halaqoh:el.dataset.halaqoh||'',status:el.querySelector('.reminder-peserta-status')?.classList.contains('sent')?'terkirim':'menunggu'};
        sync();
        if(innerWidth<901) document.querySelector('.reminder-peserta-side')?.scrollIntoView({behavior:'smooth',block:'start'});
    }
    items.forEach(el=>{
        el.querySelector('.reminder-peserta-item-main')?.addEventListener('click',()=>selectItem(el));
        el.querySelector('[data-quick-send]')?.addEventListener('click',()=>{selectItem(el);setTimeout(()=>form?.requestSubmit(),0);});
    });
    templateEl?.addEventListener('change',sync);
    form?.addEventListener('submit',e=>{if(!selected||!tpl()||!confirm('Kirim reminder ke '+selected.name+'?'))e.preventDefault();});
    document.querySelectorAll('.delete-request-form,.delete-template-form').forEach(f=>f.addEventListener('submit',e=>{if(!confirm('Hapus item ini? Tindakan ini tidak bisa dibatalkan.'))e.preventDefault();}));
    document.getElementById('deleteAllPesertaForm')?.addEventListener('submit',e=>{if(!confirm('Hapus SEMUA permintaan reminder?'))e.preventDefault();});
    document.getElementById('sendAllPesertaForm')?.addEventListener('submit',e=>{if(!confirm('Kirim ke semua permintaan yang masih menunggu ('+<?= (int)$stats['pending'] ?>+' peserta)?'))e.preventDefault();});
    const tid=document.getElementById('templateId'),tn=document.getElementById('templateName'),tc=document.getElementById('templateContent');
    document.querySelectorAll('.edit-peserta-template').forEach(b=>b.addEventListener('click',()=>{tid.value=b.dataset.id||'';tn.value=b.dataset.name||'';tc.value=b.dataset.content||'';tn.focus();tn.scrollIntoView({behavior:'smooth',block:'center'});}));
    document.getElementById('clearTemplateBtn')?.addEventListener('click',()=>{tid.value='';tn.value='';tc.value='';tn.focus();});
    const initial=items.find(x=>Number(x.dataset.requestId)===Number(idEl.value)) || items.find(x=>x.querySelector('.reminder-peserta-status')?.classList.contains('pending')) || items[0];
    if(initial) selectItem(initial); else sync();
})();
</script>
