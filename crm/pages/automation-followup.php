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
$cfilter = (string)($_GET['cfilter'] ?? 'all');
if ($tab === 'prospects') {
    // Ambil pesan chat masuk terbaru per nomor WA secara real-time dari log_wa
    // Fokus pada chat masuk pemicu program:
    // 1. 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada'
    // 2. Berawalan 'Bismillah, perkenalkan nama saya...'
    // Serta variasi pemicu paket landing page dan antrean automasi aktif.
    $resP = $conn->query("
        SELECT l.id,
               l.nowa,
               l.nama,
               l.message AS last_message,
               l.created_at AS last_trigger_at,
               latest.trigger_count,
               latest.first_trigger_msg,
               latest.first_trigger_at,
               latest.history_text
        FROM log_wa l
        INNER JOIN (
            SELECT nowa,
                   MAX(id) AS max_id,
                   COUNT(*) AS trigger_count,
                   MIN(created_at) AS first_trigger_at,
                   SUBSTRING_INDEX(
                       GROUP_CONCAT(
                           CASE
                               WHEN LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%'
                                 OR LOWER(message) LIKE 'bismillah%perkenalkan nama saya%'
                                 OR LOWER(message) LIKE '%perkenalkan nama saya%'
                                 OR LOWER(message) LIKE '%tahfidz cilik%'
                                 OR LOWER(message) LIKE '%menghafal pemula%'
                                 OR LOWER(message) LIKE '%menghafal lanjutan%'
                                 OR LOWER(message) LIKE '%penguatan hafalan%'
                                 OR LOWER(message) LIKE '%tahfidz private%'
                                 OR LOWER(message) LIKE '%tanya detail program tahfidz%'
                               THEN message
                               ELSE NULL
                           END
                           ORDER BY id ASC SEPARATOR '|||___|||'
                       ),
                       '|||___|||',
                       1
                   ) AS first_trigger_msg,
                   GROUP_CONCAT(DISTINCT
                       CASE
                           WHEN LOWER(message) LIKE '%tahfidz cilik%' THEN 'cilik'
                           WHEN LOWER(message) LIKE '%menghafal pemula%' THEN 'pemula'
                           WHEN LOWER(message) LIKE '%menghafal lanjutan%' OR LOWER(message) LIKE '%penguatan hafalan%' THEN 'lanjutan'
                           WHEN LOWER(message) LIKE '%daftar program%' OR LOWER(message) LIKE '%daftar sekarang%' THEN 'cta'
                           WHEN LOWER(message) LIKE 'bismillah%perkenalkan nama saya%' OR LOWER(message) LIKE '%perkenalkan nama saya%' THEN 'form_lead'
                           WHEN LOWER(message) LIKE '%tahfidz private%' OR LOWER(message) LIKE '%tanya detail%' THEN 'hero'
                           ELSE NULL
                       END
                       SEPARATOR ','
                   ) AS history_text
            FROM log_wa
            WHERE nowa IS NOT NULL AND nowa != ''
              AND nowa NOT LIKE '%88223053149%'
              AND nowa NOT LIKE '%248232064090227%'
              AND nowa NOT LIKE '%@g%'
              AND message NOT LIKE '[GRUP]%'
              AND message NOT LIKE '[PROMOSI]%'
              AND message NOT LIKE '[REMINDER]%'
              AND message != 'Data CSV/Manual'
            GROUP BY nowa
            HAVING first_trigger_msg IS NOT NULL OR nowa IN (SELECT nowa FROM crm_automation_queue)
        ) latest ON l.id = latest.max_id
        ORDER BY l.id DESC
        LIMIT 250
    ");

    if ($resP instanceof mysqli_result) {
        $rawProspects = $resP->fetch_all(MYSQLI_ASSOC);
        
        $existingQueue = [];
        $resQ = $conn->query("SELECT nowa, id, status, scheduled_at, trigger_source FROM crm_automation_queue ORDER BY (status = 'pending') DESC, id DESC");
        if ($resQ instanceof mysqli_result) {
            while ($qRow = $resQ->fetch_assoc()) {
                $normQ = crmProspectNormalizeNumber((string)$qRow['nowa']);
                if (!isset($existingQueue[$normQ])) {
                    $existingQueue[$normQ] = $qRow;
                }
            }
        }

        foreach ($rawProspects as $p) {
            $nowa = (string)$p['nowa'];
            if (crmAutomationIsOperationalNumber($nowa)) {
                continue;
            }

            $normalized = crmProspectNormalizeNumber($nowa);
            if ($normalized === '' || crmAutomationIsOperationalNumber($normalized)) {
                continue;
            }

            $hasPayment = crmAutomationHasReceivedPayment($conn, $nowa);

            if ($pfilter === 'unpaid' && $hasPayment) continue;
            if ($pfilter === 'paid' && !$hasPayment) continue;

            $lastMsg = (string)($p['last_message'] ?? '');
            $firstTriggerMsg = (string)($p['first_trigger_msg'] ?? '');
            $historyText = (string)($p['history_text'] ?? '');

            // Ekstrak nama asli calon peserta jika data nama di database masih default/kosong
            $nama = trim((string)($p['nama'] ?? ''));
            if ($nama === '' || $nama === 'Kak' || $nama === 'Tanpa Nama' || $nama === 'Unknown') {
                if (preg_match('/(?:perkenalkan nama saya|nama saya|nama sy)\s+([A-Za-z]+)/i', $firstTriggerMsg, $mName)
                    || preg_match('/(?:perkenalkan nama saya|nama saya|nama sy)\s+([A-Za-z]+)/i', $lastMsg, $mName)) {
                    $nama = ucfirst(strtolower(trim($mName[1])));
                } else {
                    $nama = 'Kak';
                }
            }

            // Klasifikasikan kategori berdasarkan pesan pemicu awal & riwayat
            $combinedText = $firstTriggerMsg . ' ' . $historyText . ' ' . $lastMsg;
            if (stripos($combinedText, 'tahfidz cilik') !== false) {
                $category = crmAutomationClassifyLpLead('tahfidz cilik');
            } elseif (stripos($combinedText, 'menghafal pemula') !== false) {
                $category = crmAutomationClassifyLpLead('menghafal pemula');
            } elseif (stripos($combinedText, 'menghafal lanjutan') !== false || stripos($combinedText, 'penguatan hafalan') !== false) {
                $category = crmAutomationClassifyLpLead('menghafal lanjutan');
            } elseif (stripos($combinedText, 'daftar program') !== false || stripos($combinedText, 'daftar sekarang') !== false) {
                $category = crmAutomationClassifyLpLead('daftar program');
            } elseif (stripos($combinedText, 'perkenalkan nama saya') !== false || stripos($combinedText, 'nama saya') !== false) {
                $category = [
                    'key' => 'form_lead',
                    'label' => 'Bismillah Nama LP',
                    'color' => '#0284c7',
                    'bg' => '#e0f2fe',
                    'icon' => 'fa-user-pen',
                ];
            } else {
                $category = crmAutomationClassifyLpLead($firstTriggerMsg ?: $lastMsg);
            }

            // Filter kategori: 'all' menampilkan semua prospek chat masuk terbaru
            if ($cfilter !== 'all' && $category['key'] !== $cfilter) {
                continue;
            }

            $qData = $existingQueue[$normalized] ?? null;

            $prospects[] = [
                'nowa' => $nowa,
                'nama' => $nama,
                'last_trigger_at' => $p['last_trigger_at'],
                'first_trigger_msg' => $firstTriggerMsg,
                'trigger_count' => (int)$p['trigger_count'],
                'last_message' => $lastMsg,
                'category' => $category,
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

    <!-- Workflow Pipeline Banner -->
    <div class="crm-workflow-banner">
        <div class="crm-workflow-header">
            <h3>
                <i class="fa-solid fa-diagram-project" style="color: #166534;"></i>
                <span>Alur Otomasi & Follow-Up Calon Peserta</span>
            </h3>
            <span style="font-size: 0.75rem; color: #475569; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-shield-halved" style="color: #047857;"></i> Berhenti otomatis saat peserta membalas chat atau menyelesaikan pembayaran
            </span>
        </div>
        <div class="crm-workflow-steps">
            <div class="crm-step-card">
                <div class="crm-step-badge" style="background: #e0f2fe; color: #0369a1;">1</div>
                <div class="crm-step-body">
                    <span class="crm-step-title">Chat Masuk</span>
                    <span class="crm-step-desc">Pemicu landing page Tahfidz tercatat otomatis di Log Prospek.</span>
                </div>
            </div>
            <div class="crm-step-card">
                <div class="crm-step-badge" style="background: #fef3c7; color: #92400e;">2</div>
                <div class="crm-step-body">
                    <span class="crm-step-title">Balasan Otomatis</span>
                    <span class="crm-step-desc">Auto-reply mengirim info katalog, rincian biaya & form minat.</span>
                </div>
            </div>
            <div class="crm-step-card">
                <div class="crm-step-badge" style="background: #f3e8ff; color: #7e22ce;">3</div>
                <div class="crm-step-body">
                    <span class="crm-step-title">Verifikasi Finance</span>
                    <span class="crm-step-desc">Deteksi otomatis peserta yang sudah dikirimi form finance.</span>
                </div>
            </div>
            <div class="crm-step-card">
                <div class="crm-step-badge" style="background: #dbeafe; color: #1e40af;">4</div>
                <div class="crm-step-body">
                    <span class="crm-step-title">6 Tahap Drip FU</span>
                    <span class="crm-step-desc">FU 1 (10m) &rarr; FU 2 (1j) &rarr; FU 3 (5j) &rarr; FU 4 (H+1) &rarr; FU 5 (H+3) &rarr; FU 6 (H+7).</span>
                </div>
            </div>
            <div class="crm-step-card">
                <div class="crm-step-badge" style="background: #dcfce7; color: #166534;">5</div>
                <div class="crm-step-body">
                    <span class="crm-step-title">Closing & Stop</span>
                    <span class="crm-step-desc">Antrean otomatis batal begitu calon peserta membalas atau bayar.</span>
                </div>
            </div>
        </div>
    </div>

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

    <!-- Tabs Nav with Count Pills -->
    <nav class="crm-tabs-nav" aria-label="Tab Automasi">
        <a href="?page=automation-followup&tab=prospects" class="crm-tab-btn <?= $tab === 'prospects' ? 'active' : '' ?>">
            <i class="fa-solid fa-users-viewfinder"></i>
            <span>Log Prospek Pemicu</span>
            <span class="crm-tab-pill"><?= number_format(count($prospects)) ?></span>
        </a>
        <a href="?page=automation-followup&tab=rules" class="crm-tab-btn <?= $tab === 'rules' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>Pola Pemicu (Rules)</span>
            <span class="crm-tab-pill"><?= number_format($statRulesActive) ?></span>
        </a>
        <a href="?page=automation-followup&tab=templates" class="crm-tab-btn <?= $tab === 'templates' ? 'active' : '' ?>">
            <i class="fa-regular fa-file-lines"></i>
            <span>Template Pesan</span>
            <span class="crm-tab-pill"><?= number_format(count($templates)) ?></span>
        </a>
        <a href="?page=automation-followup&tab=queue" class="crm-tab-btn <?= $tab === 'queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i>
            <span>Antrean & Riwayat</span>
            <span class="crm-tab-pill" style="<?= $statPending > 0 ? 'background: #fef3c7; color: #92400e;' : '' ?>"><?= number_format($statPending) ?></span>
        </a>
    </nav>

    <!-- Tab 1: Prospects Log -->
    <?php if ($tab === 'prospects'): ?>
        <div class="crm-card">
            <div class="crm-card-header">
                <div>
                    <h2>Chat Masuk & Prospek Baru (Tahfidz Private)</h2>
                    <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: #64748b;">
                        Daftar calon peserta dari chat pemicu WhatsApp dan pengisian form nama. Pesan dan waktu chat selalu sinkron real-time dari log WA.
                    </p>
                </div>
                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                    <a href="?page=automation-followup&tab=prospects&pfilter=unpaid<?= $cfilter !== 'all' ? '&cfilter=' . urlencode($cfilter) : '' ?>" class="btn-secondary" style="<?= $pfilter === 'unpaid' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-filter"></i> Belum Diisi Finance
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=paid<?= $cfilter !== 'all' ? '&cfilter=' . urlencode($cfilter) : '' ?>" class="btn-secondary" style="<?= $pfilter === 'paid' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i> Sudah Diisi Finance
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=all<?= $cfilter !== 'all' ? '&cfilter=' . urlencode($cfilter) : '' ?>" class="btn-secondary" style="<?= $pfilter === 'all' ? 'background: #166534; color: #fff;' : '' ?>">
                        Semua Status
                    </a>
                </div>
            </div>

            <!-- Category Filter from Landing Page -->
            <div style="padding: 0.5rem 1.25rem 0.85rem; border-bottom: 1px solid #e2e8f0; display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap; background: #fafafa;">
                <span style="font-size: 0.75rem; font-weight: 600; color: #64748b; margin-right: 0.25rem;">
                    <i class="fa-solid fa-tags"></i> Kategori Pemicu:
                </span>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=all" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'all' ? 'background: #0f172a; color: #fff;' : '' ?>" title="Menampilkan seluruh chat masuk prospek terbaru secara up to date">
                    <i class="fa-solid fa-layer-group"></i> Semua Chat Baru
                </a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=general" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'general' ? 'background: #334155; color: #fff;' : '' ?>" title="Pemicu: Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada">
                    <i class="fa-solid fa-comment-dots"></i> Tanya Detail Hero
                </a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=form_lead" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'form_lead' ? 'background: #0284c7; color: #fff;' : '' ?>" title="Pemicu: Berawalan Bismillah, perkenalkan nama saya...">
                    <i class="fa-solid fa-user-pen"></i> Bismillah Nama LP
                </a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=pemula" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'pemula' ? 'background: #1d4ed8; color: #fff;' : '' ?>">Menghafal Pemula</a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=lanjutan" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'lanjutan' ? 'background: #6d28d9; color: #fff;' : '' ?>">Lanjutan & Muroja'ah</a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=cilik" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'cilik' ? 'background: #be185d; color: #fff;' : '' ?>">Tahfidz Cilik</a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=cta" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'cta' ? 'background: #047857; color: #fff;' : '' ?>">Pendaftaran CTA</a>

                <div style="width: 100%; margin-top: 0.35rem; font-size: 0.72rem; color: #64748b; display: flex; align-items: center; gap: 0.35rem;">
                    <i class="fa-solid fa-circle-info" style="color: #0284c7;"></i>
                    <span>Fokus mendeteksi calon peserta dari chat pemicu <strong>"Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada"</strong> dan <strong>"Bismillah, perkenalkan nama saya..."</strong> secara real-time dari log WA.</span>
                </div>
            </div>

            <div class="crm-table-container">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>Kontak & Calon Peserta</th>
                            <th>Pemicu & Kategori</th>
                            <th>Status Finance</th>
                            <th>Status Antrean</th>
                            <th>Tindak Lanjut & Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($prospects)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 2.5rem 1rem;">
                                    <i class="fa-solid fa-inbox" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                    <span>Tidak ada kontak prospek pada filter ini.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($prospects as $item): ?>
                                <tr>
                                    <td>
                                        <div class="crm-contact-cell">
                                            <div class="crm-avatar">
                                                <?= htmlspecialchars(mb_strtoupper(mb_substr($item['nama'], 0, 1))) ?>
                                            </div>
                                            <div class="crm-contact-info">
                                                <span class="crm-contact-name"><?= htmlspecialchars($item['nama']) ?></span>
                                                <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/\D+/', '', $item['nowa'])) ?>" target="_blank" rel="noopener noreferrer" class="crm-contact-wa" title="Buka obrolan di WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                    <span><?= htmlspecialchars($item['nowa']) ?></span>
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                            <div style="display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
                                                <span style="display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 9999px; background: <?= htmlspecialchars($item['category']['bg']) ?>; color: <?= htmlspecialchars($item['category']['color']) ?>;">
                                                    <i class="fa-solid <?= htmlspecialchars($item['category']['icon']) ?>"></i>
                                                    <?= htmlspecialchars($item['category']['label']) ?>
                                                </span>
                                                <span style="font-size: 0.72rem; color: #64748b;" title="Waktu chat terbaru: <?= htmlspecialchars($item['last_trigger_at']) ?>">
                                                    <i class="fa-regular fa-clock"></i> <?= date('d/m/Y H:i', strtotime($item['last_trigger_at'])) ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($item['last_message'])): ?>
                                                <div class="crm-msg-quote" title="<?= htmlspecialchars($item['last_message']) ?>">
                                                    &ldquo;<?= htmlspecialchars(mb_strimwidth($item['last_message'], 0, 85, '...')) ?>&rdquo;
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($item['first_trigger_msg']) && $item['first_trigger_msg'] !== $item['last_message']): ?>
                                                <div style="font-size: 0.68rem; color: #94a3b8; font-style: italic; margin-top: -2px;" title="<?= htmlspecialchars($item['first_trigger_msg']) ?>">
                                                    Pemicu awal: &ldquo;<?= htmlspecialchars(mb_strimwidth($item['first_trigger_msg'], 0, 60, '...')) ?>&rdquo;
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($item['has_payment']): ?>
                                            <span class="badge-status badge-sent" title="Sudah dikirimi chat: ⛔ Wajib segera diisi Mohon diisi untuk pendataan Finance kami">
                                                <i class="fa-solid fa-file-invoice-dollar"></i> Sudah Isi Finance
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
                                                <?php $isAuto = (($item['queue']['trigger_source'] ?? '') === 'auto_webhook'); ?>
                                                <?php if ($isAuto): ?>
                                                    <span class="badge-status badge-sent" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;" title="Terjadwal otomatis via Webhook WhatsApp saat chat baru masuk">
                                                        <i class="fa-solid fa-bolt"></i> Terjadwal (Otomatis)
                                                    </span>
                                                    <div style="font-size: 0.68rem; color: #64748b; margin-top: 2px;">
                                                        <i class="fa-regular fa-clock"></i> <?= date('d/m H:i', strtotime($item['queue']['scheduled_at'])) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge-status badge-pending">
                                                        <i class="fa-solid fa-calendar-check"></i> Terjadwal (<?= date('d/m H:i', strtotime($item['queue']['scheduled_at'])) ?>)
                                                    </span>
                                                <?php endif; ?>
                                            <?php elseif ($qs === 'sent'): ?>
                                                <span class="badge-status badge-sent"><i class="fa-solid fa-check-double"></i> Terkirim</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-cancelled"><?= htmlspecialchars(str_replace('cancelled_', '', $qs)) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.75rem;"><i class="fa-regular fa-circle"></i> Belum Terjadwal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['has_payment']): ?>
                                            <span style="color: #047857; font-size: 0.75rem; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> Peserta Terdaftar</span>
                                        <?php else: ?>
                                            <div class="crm-action-group">
                                                <!-- Primary: Jadwalkan Alur (6 Tahap) -->
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0; display: flex; align-items: center; gap: 0.35rem;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="nama" value="<?= htmlspecialchars($item['nama']) ?>">
                                                    <input type="hidden" name="action" value="schedule_sequence">
                                                    <input type="hidden" name="template_id" value="0">
                                                    <button type="submit" class="btn-schedule-sequence" title="Jadwalkan otomatis urutan 6 follow up bertingkat (10m, 1j, 5j, 24j, 3h, 7h)">
                                                        <i class="fa-solid fa-calendar-check"></i>
                                                        <span>Jadwalkan Alur (6 Tahap)</span>
                                                    </button>
                                                    <?php if ($item['queue'] && $item['queue']['status'] === 'pending'): ?>
                                                        <button type="submit" name="action" value="stop_prospect" class="btn-danger" style="padding: 0.35rem 0.55rem; font-size: 0.72rem;" title="Hentikan dan batalkan semua jadwal follow-up kontak ini" onclick="return confirm('Hentikan semua jadwal follow-up untuk kontak ini?');">
                                                            <i class="fa-solid fa-ban"></i> Stop
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <!-- Secondary: Kirim / Antre 1 Template Tertentu -->
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0; display: flex; gap: 0.25rem; align-items: center;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="nama" value="<?= htmlspecialchars($item['nama']) ?>">
                                                    <input type="hidden" name="rule_id" value="<?= $activeRuleId ?>">
                                                    <select name="template_id" class="form-control" style="padding: 0.2rem 0.4rem; font-size: 0.72rem; width: 145px; height: 28px;">
                                                        <?php foreach ($templates as $tpl): ?>
                                                            <option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars(mb_strimwidth($tpl['name'], 0, 24, '...')) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="action" value="send_prospect_now" class="btn-primary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; height: 28px;" title="Kirim template terpilih ini sekarang">
                                                        <i class="fa-solid fa-paper-plane"></i> Kirim
                                                    </button>
                                                    <button type="submit" name="action" value="queue_prospect" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; height: 28px;" title="Antrekan hanya 1 template terpilih ini">
                                                        <i class="fa-solid fa-clock"></i> Antre 1
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

    <!-- Tab 2: Rules (Pola Pemicu) -->
    <?php if ($tab === 'rules'): ?>
        <div class="crm-card" id="ruleFormCard">
            <div class="crm-card-header">
                <h2 id="ruleFormHeading">Tambah / Edit Pola Chat Masuk (Pemicu Alur Otomatis)</h2>
            </div>
            <p style="color: #64748b; font-size: 0.8rem; margin: -0.5rem 0 1rem 0;">
                Setiap chat WhatsApp baru yang memuat kata kunci/teks di bawah akan langsung otomatis dijadwalkan ke 6 tahap follow-up tanpa perlu klik manual.
            </p>
            <form action="index.php?page=automation-followup" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="tab" value="rules">
                <input type="hidden" name="rule_id" id="ruleId" value="0">

                <div class="form-group">
                    <label for="ruleKw">Teks / Kata Kunci Pesan Masuk <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="trigger_keyword" id="ruleKw" class="form-control" placeholder="Contoh: Kak, saya ingin daftar Tahfidz Cilik" required>
                    <small style="color: #64748b; font-size: 0.72rem;">Bisa berupa kalimat lengkap dari tombol web atau kata kunci utama (misal: 'Tahfidz Cilik', 'Menghafal Pemula').</small>
                </div>

                <div class="form-group">
                    <label for="ruleName">Nama Program / Label Aturan <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="name" id="ruleName" class="form-control" placeholder="Contoh: Tahfidz Cilik (Tombol LP)" required>
                </div>

                <div class="form-group">
                    <label for="ruleCategory">Kategori Tampilan (Badge)</label>
                    <select name="category" id="ruleCategory" class="form-control">
                        <option value="cilik">Tahfidz Cilik (Pink)</option>
                        <option value="pemula">Menghafal Pemula (Biru)</option>
                        <option value="lanjutan">Lanjutan & Muroja'ah (Ungu)</option>
                        <option value="form_lead">Bismillah Nama LP (Sky Blue)</option>
                        <option value="cta">Pendaftaran CTA (Hijau)</option>
                        <option value="general" selected>Tanya Detail Hero (Netral)</option>
                    </select>
                </div>

                <div class="form-group" style="flex-direction: row; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                    <input type="checkbox" name="is_active" id="ruleActive" value="1" checked style="width: 1.1rem; height: 1.1rem; accent-color: #166534;">
                    <label for="ruleActive" style="margin: 0; cursor: pointer; font-weight: 500;">Aktifkan Pola Pemicu Ini</label>
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <button type="submit" id="ruleSubmitBtn" class="btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Simpan Pola Pemicu</span>
                    </button>
                    <button type="button" id="ruleCancelBtn" class="btn-secondary" style="display: none;" onclick="cancelEditRule()">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Batal</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="crm-card">
            <div class="crm-card-header">
                <h2>Daftar Pola Chat Masuk (Pemicu Alur Otomatis)</h2>
                <span style="font-size: 0.75rem; color: #64748b;"><?= count($rules) ?> pola terdaftar</span>
            </div>
            <div class="table-responsive">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th style="width: 110px;">Status</th>
                            <th>Pola Teks Chat Masuk</th>
                            <th>Nama / Label</th>
                            <th style="width: 150px;">Kategori</th>
                            <th style="width: 130px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 1.5rem;">Belum ada pola pemicu. Silakan tambahkan pada form di atas.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rules as $r): ?>
                                <?php
                                $rCat = (string)($r['category'] ?? 'general');
                                $catBadge = match($rCat) {
                                    'cilik' => ['label' => 'Tahfidz Cilik', 'bg' => '#fce7f3', 'color' => '#be185d', 'icon' => 'fa-child-reaching'],
                                    'pemula' => ['label' => 'Menghafal Pemula', 'bg' => '#dbeafe', 'color' => '#1d4ed8', 'icon' => 'fa-book-quran'],
                                    'lanjutan' => ['label' => 'Lanjutan / Muroja\'ah', 'bg' => '#ede9fe', 'color' => '#6d28d9', 'icon' => 'fa-award'],
                                    'form_lead' => ['label' => 'Bismillah Nama LP', 'bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'fa-user-pen'],
                                    'cta' => ['label' => 'Pendaftaran CTA', 'bg' => '#d1fae5', 'color' => '#047857', 'icon' => 'fa-bolt'],
                                    default => ['label' => 'Tanya Detail (Hero)', 'bg' => '#f1f5f9', 'color' => '#334155', 'icon' => 'fa-comment-dots'],
                                };
                                ?>
                                <tr>
                                    <td>
                                        <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_rule">
                                            <input type="hidden" name="tab" value="rules">
                                            <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn-toggle" style="background: none; border: none; cursor: pointer; display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; padding: 0.2rem 0.4rem;">
                                                <i class="fa-solid <?= (int)$r['is_active'] === 1 ? 'fa-toggle-on' : 'fa-toggle-off' ?>" style="color: <?= (int)$r['is_active'] === 1 ? '#16a34a' : '#94a3b8' ?>; font-size: 1.25rem;"></i>
                                                <span style="font-weight: 600; color: <?= (int)$r['is_active'] === 1 ? '#16a34a' : '#64748b' ?>;">
                                                    <?= (int)$r['is_active'] === 1 ? 'Aktif' : 'Non-Aktif' ?>
                                                </span>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <code style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.82rem; color: #0f172a; font-weight: 600;">
                                            <?= htmlspecialchars($r['trigger_keyword']) ?>
                                        </code>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($r['name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="crm-category-badge" style="background: <?= $catBadge['bg'] ?>; color: <?= $catBadge['color'] ?>; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem;">
                                            <i class="fa-solid <?= $catBadge['icon'] ?>"></i>
                                            <?= htmlspecialchars($catBadge['label']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.35rem; justify-content: center; align-items: center;">
                                            <button type="button" class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.72rem;" onclick='editRule(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Ubah pola ini">
                                                <i class="fa-solid fa-pen-to-square"></i> Edit
                                            </button>
                                            <form action="index.php?page=automation-followup" method="POST" style="margin: 0;" onsubmit="return confirm('Hapus pola pemicu ini?');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_rule">
                                                <input type="hidden" name="tab" value="rules">
                                                <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.72rem;" title="Hapus pola ini">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 3: Templates -->
    <?php if ($tab === 'templates'): ?>
        <div class="crm-card" id="tplFormCard">
            <div class="crm-card-header">
                <h2 id="tplFormHeading">Buat / Edit Template Follow-Up</h2>
            </div>
            <form action="index.php?page=automation-followup" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="tab" value="templates">
                <input type="hidden" name="template_id" id="tplId" value="0">

                <div class="form-group">
                    <label for="tplName">Nama Template</label>
                    <input type="text" name="name" id="tplName" class="form-control" placeholder="Contoh: Tahfidz Private - Follow Up 1 (10 Menit)" required>
                </div>

                <div class="form-group">
                    <label for="tplDesc">Deskripsi Ringkas</label>
                    <input type="text" name="description" id="tplDesc" class="form-control" placeholder="Tujuan pesan ini dikirimkan">
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                        <label for="tplContent" style="margin: 0;">Konten Pesan WhatsApp</label>
                        <span style="font-size: 0.72rem; color: #64748b;"><span id="tplCharCount">0</span> karakter</span>
                    </div>
                    <div style="display: flex; gap: 0.35rem; align-items: center; margin-bottom: 0.35rem; flex-wrap: wrap;">
                        <span style="font-size: 0.72rem; color: #475569; font-weight: 600;">Sisipkan variabel:</span>
                        <button type="button" class="crm-var-chip" onclick="insertTplVar('{nama}')">{nama}</button>
                        <button type="button" class="crm-var-chip" onclick="insertTplVar('{sapaan}')">{sapaan}</button>
                        <button type="button" class="crm-var-chip" onclick="insertTplVar('{program}')">{program}</button>
                    </div>
                    <textarea name="content" id="tplContent" class="form-control" rows="6" placeholder="Ketik draf pesan WhatsApp..." oninput="updateTplCharCount()" required></textarea>
                    <small style="color: #64748b; font-size: 0.75rem; margin-top: 0.35rem;">
                        Variabel dinamis: <code>{nama}</code> (Nama peserta/Kak), <code>{sapaan}</code> (Pagi/Siang/Sore/Malam), <code>{program}</code> (Nama Program).
                    </small>
                </div>

                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button type="submit" id="tplSubmitBtn" class="btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Simpan Template</span>
                    </button>
                    <button type="button" id="tplCancelBtn" class="btn-secondary" style="display: none;" onclick="cancelEditTemplate()">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Batal Edit</span>
                    </button>
                </div>
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
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                        <strong style="color: #0f172a; font-size: 0.95rem;"><?= htmlspecialchars($t['name']) ?></strong>
                                        <span class="crm-queue-step-tag">
                                            <i class="fa-solid fa-layer-group" style="color: #0284c7;"></i>
                                            <?= htmlspecialchars(crmAutomationGetStepNumber((string)$t['name'])) ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($t['description'])): ?>
                                        <p style="margin: 0.2rem 0 0; font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($t['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; gap: 0.35rem; align-items: center;">
                                    <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="copyTplContent(this, <?= htmlspecialchars(json_encode((string)$t['content'])) ?>)">
                                        <i class="fa-regular fa-copy"></i> <span>Salin</span>
                                    </button>
                                    <button type="button" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="editTemplate(<?= htmlspecialchars(json_encode([
                                        'id' => (int)$t['id'],
                                        'name' => (string)$t['name'],
                                        'description' => (string)($t['description'] ?? ''),
                                        'content' => (string)$t['content'],
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>
                                    <form action="index.php?page=automation-followup" method="POST" onsubmit="return confirm('Hapus template ini?');" style="margin: 0;">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_template">
                                        <input type="hidden" name="tab" value="templates">
                                        <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="btn-danger" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">
                                            <i class="fa-solid fa-trash"></i> Hapus
                                        </button>
                                    </form>
                                </div>
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
                <div>
                    <h2>Antrean & Log Pengiriman Follow Up</h2>
                    <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: #64748b;">
                        Jadwal pesan terkirim berurutan secara berkala. Pesan otomatis berhenti jika peserta membalas chat atau membayar.
                    </p>
                </div>
                <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                    <form action="index.php?page=automation-followup" method="POST" style="margin: 0;" onsubmit="return confirm('Bersihkan semua antrean bentrok yang terjadwal di jam bersamaan?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="clean_duplicate_queues">
                        <input type="hidden" name="tab" value="queue">
                        <button type="submit" class="btn-secondary" style="color: #dc2626; border-color: #fca5a5; background: #fff5f5; font-size: 0.75rem;" title="Batalkan antrean spesifik duplikat yang bentrok di jam yang sama">
                            <i class="fa-solid fa-broom"></i> Bersihkan Jadwal Bentrok
                        </button>
                    </form>
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
                            <th>Kontak Calon Peserta</th>
                            <th>Urutan & Template Pesan</th>
                            <th>Jadwal Kirim</th>
                            <th>Status Eksekusi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($queueItems)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 2rem;">Tidak ada antrean dalam filter ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($queueItems as $q): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: #0f172a; font-size: 0.85rem;"><?= htmlspecialchars($q['nama'] ?: 'Calon Peserta') ?></div>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', (string)$q['nowa']) ?>" target="_blank" rel="noopener noreferrer" class="crm-contact-wa">
                                            <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($q['nowa']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                            <div>
                                                <span class="crm-queue-step-tag">
                                                    <i class="fa-solid fa-layer-group" style="color: #0284c7;"></i>
                                                    <?= htmlspecialchars(crmAutomationGetStepNumber((string)($q['template_name'] ?? $q['rule_name'] ?? ''))) ?>
                                                </span>
                                            </div>
                                            <strong style="color: #1e293b; font-size: 0.8rem;"><?= htmlspecialchars($q['template_name'] ?? $q['rule_name'] ?? 'Tahfidz Private') ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                            <span style="font-size: 0.8rem; color: #0f172a; font-weight: 600;">
                                                <i class="fa-regular fa-calendar" style="color: #64748b;"></i> <?= date('d/m/Y H:i', strtotime($q['scheduled_at'])) ?>
                                            </span>
                                            <?php if ($q['status'] === 'pending'): ?>
                                                <div>
                                                    <span class="crm-countdown-tag">
                                                        <i class="fa-regular fa-clock"></i> <?= htmlspecialchars(crmAutomationRelativeTime((string)$q['scheduled_at'])) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($q['status'] === 'pending'): ?>
                                            <?php if (($q['trigger_source'] ?? '') === 'auto_webhook'): ?>
                                                <span class="badge-status badge-sent" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;"><i class="fa-solid fa-bolt"></i> Terjadwal (Otomatis)</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-pending"><i class="fa-solid fa-clock"></i> Terjadwal (Manual)</span>
                                            <?php endif; ?>
                                        <?php elseif ($q['status'] === 'sent'): ?>
                                            <span class="badge-status badge-sent"><i class="fa-solid fa-circle-check"></i> Terkirim</span>
                                        <?php elseif (str_starts_with((string)$q['status'], 'cancelled')): ?>
                                            <span class="badge-status badge-cancelled" title="<?= htmlspecialchars((string)($q['error_message'] ?? '')) ?>">
                                                <i class="fa-solid fa-ban"></i> Batal (<?= htmlspecialchars(str_replace('cancelled_', '', $q['status'])) ?>)
                                            </span>
                                            <?php if (!empty($q['error_message'])): ?>
                                                <div style="font-size: 0.7rem; color: #64748b; margin-top: 0.15rem;"><?= htmlspecialchars($q['error_message']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge-status badge-failed" title="<?= htmlspecialchars((string)($q['error_message'] ?? '')) ?>">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Gagal
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($q['status'] === 'pending'): ?>
                                            <div style="display: flex; gap: 0.35rem; align-items: center;">
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="send_now">
                                                    <input type="hidden" name="tab" value="queue">
                                                    <input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                                                    <button type="submit" class="btn-primary" style="padding: 0.25rem 0.55rem; font-size: 0.72rem; height: 28px;" title="Kirim pesan ini sekarang tanpa menunggu jadwal">
                                                        <i class="fa-solid fa-paper-plane"></i> Kirim
                                                    </button>
                                                </form>
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;" onsubmit="return confirm('Batalkan pengiriman pesan ini?');">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="cancel_queue">
                                                    <input type="hidden" name="tab" value="queue">
                                                    <input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                                                    <button type="submit" class="btn-danger" style="padding: 0.25rem 0.55rem; font-size: 0.72rem; height: 28px;" title="Batalkan jadwal pesan ini">
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

    <script>
    function insertTplVar(variable) {
        var el = document.getElementById('tplContent');
        if (!el) return;
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;
        var val = el.value;
        el.value = val.substring(0, start) + variable + val.substring(end);
        el.focus();
        el.selectionStart = el.selectionEnd = start + variable.length;
        updateTplCharCount();
    }

    function updateTplCharCount() {
        var el = document.getElementById('tplContent');
        var counter = document.getElementById('tplCharCount');
        if (el && counter) {
            counter.textContent = el.value.length;
        }
    }

    function copyTplContent(btn, text) {
        if (!navigator.clipboard) {
            var temp = document.createElement('textarea');
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
        } else {
            navigator.clipboard.writeText(text);
        }
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check" style="color: #166534;"></i> <span>Tersalin!</span>';
        setTimeout(function() {
            btn.innerHTML = orig;
        }, 1800);
    }

    function editRule(data) {
        var ruleId = document.getElementById('ruleId');
        var ruleName = document.getElementById('ruleName');
        var ruleKw = document.getElementById('ruleKw');
        var ruleCat = document.getElementById('ruleCategory');
        var ruleActive = document.getElementById('ruleActive');
        var heading = document.getElementById('ruleFormHeading');
        var btn = document.getElementById('ruleSubmitBtn');
        var cancelBtn = document.getElementById('ruleCancelBtn');
        var card = document.getElementById('ruleFormCard');

        if (ruleId) ruleId.value = data.id;
        if (ruleName) ruleName.value = data.name;
        if (ruleKw) ruleKw.value = data.trigger_keyword;
        if (ruleCat) ruleCat.value = data.category || 'general';
        if (ruleActive) ruleActive.checked = (parseInt(data.is_active, 10) === 1);
        if (heading) heading.textContent = 'Edit Pola Pemicu: ' + data.name;
        if (btn) btn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Perbarui Pola</span>';
        if (cancelBtn) cancelBtn.style.display = 'inline-flex';

        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function cancelEditRule() {
        var ruleId = document.getElementById('ruleId');
        var ruleName = document.getElementById('ruleName');
        var ruleKw = document.getElementById('ruleKw');
        var ruleCat = document.getElementById('ruleCategory');
        var ruleActive = document.getElementById('ruleActive');
        var heading = document.getElementById('ruleFormHeading');
        var btn = document.getElementById('ruleSubmitBtn');
        var cancelBtn = document.getElementById('ruleCancelBtn');

        if (ruleId) ruleId.value = '0';
        if (ruleName) ruleName.value = '';
        if (ruleKw) ruleKw.value = '';
        if (ruleCat) ruleCat.value = 'general';
        if (ruleActive) ruleActive.checked = true;
        if (heading) heading.textContent = 'Tambah / Edit Pola Chat Masuk (Pemicu Alur Otomatis)';
        if (btn) btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> <span>Simpan Pola Pemicu</span>';
        if (cancelBtn) cancelBtn.style.display = 'none';
    }

    function editTemplate(data) {
        var tplId = document.getElementById('tplId');
        var tplName = document.getElementById('tplName');
        var tplDesc = document.getElementById('tplDesc');
        var tplContent = document.getElementById('tplContent');
        var heading = document.getElementById('tplFormHeading');
        var btn = document.getElementById('tplSubmitBtn');
        var cancelBtn = document.getElementById('tplCancelBtn');
        var card = document.getElementById('tplFormCard');

        if (tplId) tplId.value = data.id;
        if (tplName) tplName.value = data.name;
        if (tplDesc) tplDesc.value = data.description || '';
        if (tplContent) tplContent.value = data.content || '';
        if (heading) heading.textContent = 'Edit Template: ' + data.name;
        if (btn) btn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Perbarui Template</span>';
        if (cancelBtn) cancelBtn.style.display = 'inline-flex';

        updateTplCharCount();

        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function cancelEditTemplate() {
        var tplId = document.getElementById('tplId');
        var tplName = document.getElementById('tplName');
        var tplDesc = document.getElementById('tplDesc');
        var tplContent = document.getElementById('tplContent');
        var heading = document.getElementById('tplFormHeading');
        var btn = document.getElementById('tplSubmitBtn');
        var cancelBtn = document.getElementById('tplCancelBtn');

        if (tplId) tplId.value = '0';
        if (tplName) tplName.value = '';
        if (tplDesc) tplDesc.value = '';
        if (tplContent) tplContent.value = '';
        if (heading) heading.textContent = 'Buat / Edit Template Follow-Up';
        if (btn) btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> <span>Simpan Template</span>';
        if (cancelBtn) cancelBtn.style.display = 'none';

        updateTplCharCount();
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateTplCharCount();
    });
    </script>
</div>
