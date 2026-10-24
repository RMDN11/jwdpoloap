<?php
declare(strict_types=1);

$crmTitle = 'Automation Follow Up';

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/automation-followup.php';

crmAutomationEnsureTables($conn);

$tab = (string)($_GET['tab'] ?? 'prospects');
if (!in_array($tab, ['prospects', 'rules', 'templates', 'queue'], true)) {
    $tab = 'prospects';
}

$csrfToken = crmCsrfToken();

// Hitung statistik antrean
$statPending = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue WHERE status = 'pending'");
$statSentToday = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
$statCancelled = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue WHERE status LIKE 'cancelled_%'");
$statRulesActive = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_rules WHERE is_active = 1");

// Ambil rule aktif pertama untuk default form penjadwalan
$activeRuleId = 1;
$resRuleActive = $conn->query("SELECT id FROM crm_automation_rules WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
if ($resRuleActive && ($rRow = $resRuleActive->fetch_assoc())) {
    $activeRuleId = (int)$rRow['id'];
}

// Ambil daftar template untuk dropdown
$templates = [];
$resTpl = $conn->query("SELECT id, name, content, description FROM crm_automation_templates ORDER BY id DESC");
if ($resTpl instanceof mysqli_result) {
    $templates = $resTpl->fetch_all(MYSQLI_ASSOC);
}

// Tab 1: Prospek Pemicu Log
$prospects = [];
$pfilter = (string)($_GET['pfilter'] ?? 'unpaid');
if ($tab === 'prospects') {
    $resP = $conn->query("
        SELECT l.nowa, 
               MAX(l.nama) AS nama, 
               MAX(l.created_at) AS last_trigger_at,
               COUNT(*) AS trigger_count
        FROM log_wa l
        WHERE l.message LIKE '%Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada%'
           OR l.message LIKE '%tanya detail program Tahfidz Private%'
        GROUP BY l.nowa
        ORDER BY last_trigger_at DESC
        LIMIT 250
    ");

    if ($resP instanceof mysqli_result) {
        $rawProspects = $resP->fetch_all(MYSQLI_ASSOC);
        
        $existingQueue = [];
        $resQ = $conn->query("SELECT nowa, id, status, scheduled_at FROM crm_automation_queue");
        if ($resQ instanceof mysqli_result) {
            while ($qRow = $resQ->fetch_assoc()) {
                $normQ = crmProspectNormalizeNumber((string)$qRow['nowa']);
                $existingQueue[$normQ] = $qRow;
            }
        }

        foreach ($rawProspects as $p) {
            $nowa = (string)$p['nowa'];
            $normalized = crmProspectNormalizeNumber($nowa);
            $hasPayment = crmAutomationHasReceivedPayment($conn, $nowa);

            if ($pfilter === 'unpaid' && $hasPayment) continue;
            if ($pfilter === 'paid' && !$hasPayment) continue;

            $qData = $existingQueue[$normalized] ?? null;

            $prospects[] = [
                'nowa' => $nowa,
                'nama' => $p['nama'] ?: 'Kak',
                'last_trigger_at' => $p['last_trigger_at'],
                'trigger_count' => (int)$p['trigger_count'],
                'has_payment' => $hasPayment,
                'queue' => $qData,
            ];
        }
    }
}

// Tab 2: Rules
$rules = [];
if ($tab === 'rules') {
    $resRules = $conn->query("
        SELECT r.*, t.name AS template_name 
        FROM crm_automation_rules r 
        LEFT JOIN crm_automation_templates t ON t.id = r.template_id 
        ORDER BY r.id DESC
    ");
    if ($resRules instanceof mysqli_result) {
        $rules = $resRules->fetch_all(MYSQLI_ASSOC);
    }
}

// Tab 4: Queue
$queueItems = [];
if ($tab === 'queue') {
    $filterStatus = (string)($_GET['qstatus'] ?? 'all');
    $whereStatus = "1=1";
    if ($filterStatus === 'pending') $whereStatus = "q.status = 'pending'";
    elseif ($filterStatus === 'sent') $whereStatus = "q.status = 'sent'";
    elseif ($filterStatus === 'cancelled') $whereStatus = "q.status LIKE 'cancelled_%'";
    elseif ($filterStatus === 'failed') $whereStatus = "q.status = 'failed'";

    $resQueue = $conn->query("
        SELECT q.*, r.name AS rule_name, t.name AS template_name
        FROM crm_automation_queue q
        LEFT JOIN crm_automation_rules r ON r.id = q.rule_id
        LEFT JOIN crm_automation_templates t ON t.id = q.template_id
        WHERE {$whereStatus}
        ORDER BY q.id DESC
        LIMIT 100
    ");
    if ($resQueue instanceof mysqli_result) {
        $queueItems = $resQueue->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<div class="crm-auto-workspace">
    <section class="page-head">
        <span class="eyebrow">Otomatisasi Pesan</span>
        <h1>Automation Follow Up</h1>
        <p>Kelola pesan lanjutan otomatis setelah calon peserta menerima balasan pemicu program.</p>
    </section>

    <!-- Stat Grid -->
    <div class="crm-stat-grid">
        <div class="crm-stat-card">
            <span>Antrean Pending</span>
            <strong><?= number_format($statPending) ?></strong>
        </div>
        <div class="crm-stat-card">
            <span>Terkirim Hari Ini</span>
            <strong><?= number_format($statSentToday) ?></strong>
        </div>
        <div class="crm-stat-card">
            <span>Batal (User Balas/Pay)</span>
            <strong><?= number_format($statCancelled) ?></strong>
        </div>
        <div class="crm-stat-card">
            <span>Aturan Aktif</span>
            <strong><?= number_format($statRulesActive) ?></strong>
        </div>
    </div>

    <!-- Tabs Nav -->
    <nav class="crm-tabs-nav" aria-label="Tab Automasi">
        <a href="?page=automation-followup&tab=prospects" class="crm-tab-btn <?= $tab === 'prospects' ? 'active' : '' ?>">
            <i class="fa-solid fa-users-viewfinder"></i>
            <span>Log Prospek Pemicu</span>
        </a>
        <a href="?page=automation-followup&tab=rules" class="crm-tab-btn <?= $tab === 'rules' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>Aturan Sequence</span>
        </a>
        <a href="?page=automation-followup&tab=templates" class="crm-tab-btn <?= $tab === 'templates' ? 'active' : '' ?>">
            <i class="fa-regular fa-file-lines"></i>
            <span>Template Pesan</span>
        </a>
        <a href="?page=automation-followup&tab=queue" class="crm-tab-btn <?= $tab === 'queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i>
            <span>Antrean & Riwayat</span>
        </a>
    </nav>

    <!-- Tab 1: Prospects Log -->
    <?php if ($tab === 'prospects'): ?>
        <div class="crm-card">
            <div class="crm-card-header">
                <div>
                    <h2>Calon Peserta Masuk (Pemicu Tahfidz Private)</h2>
                    <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: #64748b;">
                        Filter otomatis memisahkan mana yang sudah mengisi finance / pembayaran dan mana yang potensial di-follow-up.
                    </p>
                </div>
                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                    <a href="?page=automation-followup&tab=prospects&pfilter=unpaid" class="btn-secondary" style="<?= $pfilter === 'unpaid' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-filter"></i> Belum Diisi Finance
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=paid" class="btn-secondary" style="<?= $pfilter === 'paid' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i> Sudah Diisi Finance
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=all" class="btn-secondary" style="<?= $pfilter === 'all' ? 'background: #166534; color: #fff;' : '' ?>">
                        Semua Kontak
                    </a>
                </div>
            </div>

            <div class="crm-table-container">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>No. WhatsApp</th>
                            <th>Nama</th>
                            <th>Chat Pemicu Terakhir</th>
                            <th>Status Finance</th>
                            <th>Status Antrean</th>
                            <th>Aksi Manual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($prospects)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #64748b; padding: 2rem;">
                                    Tidak ada kontak prospek pada filter ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($prospects as $item): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($item['nowa']) ?></strong></td>
                                    <td><?= htmlspecialchars($item['nama']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($item['last_trigger_at'])) ?></td>
                                    <td>
                                        <?php if ($item['has_payment']): ?>
                                            <span class="badge-status badge-sent" title="Sudah dikirimi chat: ⛔ Wajib segera diisi Mohon diisi untuk pendataan Finance kami">
                                                <i class="fa-solid fa-file-invoice-dollar"></i> Sudah Isi Finance (Aman)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-pending" title="Belum pernah dikirimi pesan pendataan Finance">
                                                <i class="fa-solid fa-clock"></i> Belum Diisi Finance
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['queue']): ?>
                                            <?php $qs = (string)$item['queue']['status']; ?>
                                            <?php if ($qs === 'pending'): ?>
                                                <span class="badge-status badge-pending">Terjadwal (<?= date('d/m H:i', strtotime($item['queue']['scheduled_at'])) ?>)</span>
                                            <?php elseif ($qs === 'sent'): ?>
                                                <span class="badge-status badge-sent">Terkirim</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-cancelled"><?= htmlspecialchars($qs) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #64748b; font-size: 0.8rem;">Belum Terjadwal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['has_payment']): ?>
                                            <span style="color: #94a3b8; font-size: 0.75rem;"><i class="fa-solid fa-check"></i> Tidak Perlu</span>
                                        <?php else: ?>
                                            <div style="display: flex; gap: 0.35rem;">
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="queue_prospect">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="nama" value="<?= htmlspecialchars($item['nama']) ?>">
                                                    <input type="hidden" name="rule_id" value="<?= $activeRuleId ?>">
                                                    <input type="hidden" name="delay_minutes" value="0">
                                                    <button type="submit" class="btn-primary" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" title="Masukkan ke antrean cron">
                                                        <i class="fa-solid fa-calendar-plus"></i> Jadwalkan
                                                    </button>
                                                </form>
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="send_prospect_now">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="nama" value="<?= htmlspecialchars($item['nama']) ?>">
                                                    <input type="hidden" name="rule_id" value="<?= $activeRuleId ?>">
                                                    <button type="submit" class="btn-secondary" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" title="Kirim follow up langsung sekarang">
                                                        <i class="fa-solid fa-paper-plane"></i> Kirim Langsung
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 2: Rules -->
    <?php if ($tab === 'rules'): ?>
        <div class="crm-card">
            <div class="crm-card-header">
                <h2>Tambah / Perbarui Aturan Sequence</h2>
            </div>
            <form action="index.php?page=automation-followup" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="tab" value="rules">
                <input type="hidden" name="rule_id" id="ruleId" value="0">

                <div class="form-group">
                    <label for="ruleName">Nama Aturan / Program</label>
                    <input type="text" name="name" id="ruleName" class="form-control" placeholder="Contoh: Follow Up Tahfidz Private" required>
                </div>

                <div class="form-group">
                    <label for="ruleKw">Kata Kunci Pesan Masuk Pemicu Awal</label>
                    <input type="text" name="trigger_keyword" id="ruleKw" class="form-control" placeholder="Contoh: Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada" required>
                    <small style="color: #64748b; font-size: 0.75rem;">Saat user mengirim pesan yang memuat kata kunci ini, kontak otomatis dijadwalkan follow up.</small>
                </div>

                <div class="form-group">
                    <label for="ruleTemplate">Pilih Template Pesan Follow-Up</label>
                    <select name="template_id" id="ruleTemplate" class="form-control" required>
                        <option value="">-- Pilih Template --</option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="ruleDelay">Jeda Waktu Kirim (Menit)</label>
                    <input type="number" name="delay_minutes" id="ruleDelay" class="form-control" value="1440" min="1" required>
                    <small style="color: #64748b; font-size: 0.75rem;">1440 menit = 24 jam (H+1). 120 menit = 2 jam.</small>
                </div>

                <div class="form-group" style="flex-direction: row; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                    <input type="checkbox" name="is_active" id="ruleActive" value="1" checked style="width: 1.1rem; height: 1.1rem; accent-color: #166534;">
                    <label for="ruleActive" style="margin: 0; cursor: pointer;">Aktifkan Aturan Ini</label>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 0.5rem;">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Simpan Aturan</span>
                </button>
            </form>
        </div>

        <div class="crm-card">
            <div class="crm-card-header">
                <h2>Daftar Aturan Pemicu Aktif</h2>
            </div>
            <div class="rule-list">
                <?php if (empty($rules)): ?>
                    <p style="color: #64748b; font-size: 0.875rem;">Belum ada aturan yang dibuat. Silakan tambahkan aturan di atas.</p>
                <?php else: ?>
                    <?php foreach ($rules as $r): ?>
                        <div class="rule-item">
                            <div class="rule-info">
                                <span class="rule-title"><?= htmlspecialchars($r['name']) ?></span>
                                <span class="rule-kw"><?= htmlspecialchars($r['trigger_keyword']) ?></span>
                                <span class="rule-meta">
                                    Template: <strong><?= htmlspecialchars($r['template_name'] ?? 'Tanpa Template') ?></strong> · 
                                    Jeda: <strong><?= (int)$r['delay_minutes'] ?> menit</strong>
                                </span>
                            </div>
                            <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="toggle_rule">
                                <input type="hidden" name="tab" value="rules">
                                <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn-secondary" style="font-size: 0.75rem;">
                                    <i class="fa-solid <?= (int)$r['is_active'] === 1 ? 'fa-toggle-on' : 'fa-toggle-off' ?>" style="color: <?= (int)$r['is_active'] === 1 ? '#166534' : '#94a3b8' ?>; font-size: 1.1rem;"></i>
                                    <span><?= (int)$r['is_active'] === 1 ? 'Aktif' : 'Non-Aktif' ?></span>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 3: Templates -->
    <?php if ($tab === 'templates'): ?>
        <div class="crm-card">
            <div class="crm-card-header">
                <h2>Buat / Edit Template Follow-Up</h2>
            </div>
            <form action="index.php?page=automation-followup" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="tab" value="templates">
                <input type="hidden" name="template_id" id="tplId" value="0">

                <div class="form-group">
                    <label for="tplName">Nama Template</label>
                    <input type="text" name="name" id="tplName" class="form-control" placeholder="Contoh: Follow Up H+1 Tahfidz Private" required>
                </div>

                <div class="form-group">
                    <label for="tplDesc">Deskripsi Ringkas</label>
                    <input type="text" name="description" id="tplDesc" class="form-control" placeholder="Tujuan pesan ini dikirimkan">
                </div>

                <div class="form-group">
                    <label for="tplContent">Konten Pesan WhatsApp</label>
                    <textarea name="content" id="tplContent" class="form-control" rows="6" placeholder="Ketik draf pesan WhatsApp..." required></textarea>
                    <small style="color: #64748b; font-size: 0.75rem; margin-top: 0.35rem;">
                        Variabel dinamis: <code>{nama}</code> (Nama kontak/Kak), <code>{sapaan}</code> (Pagi/Siang/Sore/Malam), <code>{program}</code> (Nama Program).
                    </small>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Simpan Template</span>
                </button>
            </form>
        </div>

        <div class="crm-card">
            <div class="crm-card-header">
                <h2>Daftar Template Tersedia</h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php if (empty($templates)): ?>
                    <p style="color: #64748b; font-size: 0.875rem;">Belum ada template tersimpan.</p>
                <?php else: ?>
                    <?php foreach ($templates as $t): ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong style="color: #0f172a;"><?= htmlspecialchars($t['name']) ?></strong>
                                    <?php if (!empty($t['description'])): ?>
                                        <p style="margin: 0.15rem 0 0; font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($t['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <form action="index.php?page=automation-followup" method="POST" onsubmit="return confirm('Hapus template ini?');" style="margin: 0;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="tab" value="templates">
                                    <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn-danger">
                                        <i class="fa-solid fa-trash"></i> Hapus
                                    </button>
                                </form>
                            </div>
                            <div class="wa-preview-bubble">
                                <?= nl2br(htmlspecialchars($t['content'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 4: Queue & History -->
    <?php if ($tab === 'queue'): ?>
        <div class="crm-card">
            <div class="crm-card-header">
                <h2>Antrean & Log Pengiriman Follow Up</h2>
                <div style="display: flex; gap: 0.35rem;">
                    <a href="?page=automation-followup&tab=queue" class="btn-secondary">Semua</a>
                    <a href="?page=automation-followup&tab=queue&qstatus=pending" class="btn-secondary">Pending</a>
                    <a href="?page=automation-followup&tab=queue&qstatus=sent" class="btn-secondary">Terkirim</a>
                    <a href="?page=automation-followup&tab=queue&qstatus=cancelled" class="btn-secondary">Batal</a>
                </div>
            </div>

            <div class="crm-table-container">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>No. WhatsApp</th>
                            <th>Nama</th>
                            <th>Program</th>
                            <th>Jadwal Kirim</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($queueItems)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #64748b; padding: 2rem;">Tidak ada antrean dalam filter ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($queueItems as $q): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($q['nowa']) ?></strong></td>
                                    <td><?= htmlspecialchars($q['nama'] ?: 'Kak') ?></td>
                                    <td><?= htmlspecialchars($q['rule_name'] ?? 'Tahfidz Private') ?></td>
                                    <td>
                                        <?= date('d/m/Y H:i', strtotime($q['scheduled_at'])) ?>
                                    </td>
                                    <td>
                                        <?php if ($q['status'] === 'pending'): ?>
                                            <span class="badge-status badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                        <?php elseif ($q['status'] === 'sent'): ?>
                                            <span class="badge-status badge-sent"><i class="fa-solid fa-check"></i> Terkirim</span>
                                        <?php elseif (str_starts_with((string)$q['status'], 'cancelled')): ?>
                                            <span class="badge-status badge-cancelled" title="<?= htmlspecialchars((string)($q['error_message'] ?? '')) ?>">
                                                <i class="fa-solid fa-ban"></i> Batal (<?= htmlspecialchars($q['status']) ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-failed" title="<?= htmlspecialchars((string)($q['error_message'] ?? '')) ?>">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Gagal
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($q['status'] === 'pending'): ?>
                                            <div style="display: flex; gap: 0.35rem;">
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="send_now">
                                                    <input type="hidden" name="tab" value="queue">
                                                    <input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                                                    <button type="submit" class="btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                                        <i class="fa-solid fa-paper-plane"></i> Kirim
                                                    </button>
                                                </form>
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="cancel_queue">
                                                    <input type="hidden" name="tab" value="queue">
                                                    <input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                                                    <button type="submit" class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                                        <i class="fa-solid fa-xmark"></i> Batal
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.75rem;">Selesai</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
