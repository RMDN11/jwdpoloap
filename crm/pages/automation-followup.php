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
$currentUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? ('index.php?' . http_build_query($_GET)));
$perPage = 15;
$currentPage = max(1, (int)($_GET['p'] ?? 1));

// Hitung statistik antrean
$statPending = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue WHERE status = 'pending'");
$statSentToday = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
$statCancelled = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue WHERE status LIKE 'cancelled_%'");
$statRulesActive = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_rules WHERE is_active = 1");
$sleepSettings = crmAutomationGetSleepSettings($conn);

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
$cfilter = (string)($_GET['cfilter'] ?? $_GET['cat'] ?? 'all');
$search = trim((string)($_GET['search'] ?? ''));
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
        $resQ = $conn->query("SELECT nowa, id, status, scheduled_at, trigger_source FROM crm_automation_queue ORDER BY (status = 'pending') DESC, scheduled_at ASC, id ASC");
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

            // Validasi apakah pesan prospek cocok dengan pola pemicu aturan aktif
            $matchedRule = !empty($firstTriggerMsg) ? crmAutomationFindMatchingTrigger($conn, $firstTriggerMsg) : null;
            if (!$matchedRule && !empty($lastMsg)) {
                $matchedRule = crmAutomationFindMatchingTrigger($conn, $lastMsg);
            }

            $qData = $existingQueue[$normalized] ?? null;

            // Jika tidak ada antrean follow-up dan tidak cocok dengan rules pemicu aktif, jangan tampilkan di prospek
            if (!$qData && !$matchedRule) {
                continue;
            }

            // Klasifikasikan kategori berdasarkan rule yang cocok
            if ($matchedRule) {
                $catKey = (string)($matchedRule['category'] ?? 'general');
                $category = crmAutomationClassifyLpLead($matchedRule['trigger_keyword'] ?: $catKey);
                if ($catKey === 'form_lead') {
                    $category = [
                        'key' => 'form_lead',
                        'label' => 'Bismillah Nama LP',
                        'color' => '#0284c7',
                        'bg' => '#e0f2fe',
                        'icon' => 'fa-user-pen',
                    ];
                } elseif (!empty($matchedRule['name']) && $category['key'] === 'general') {
                    $category['label'] = $matchedRule['name'];
                }
            } else {
                $category = crmAutomationClassifyLpLead($firstTriggerMsg ?: $lastMsg);
            }

            // Filter kategori: 'all' menampilkan semua prospek chat masuk terbaru
            if ($cfilter !== 'all' && $category['key'] !== $cfilter) {
                continue;
            }

            // Filter pencarian nama / nomor WhatsApp
            if ($search !== '') {
                $searchLower = mb_strtolower($search);
                $searchDigits = preg_replace('/\D/', '', $search);
                $nowaDigits = preg_replace('/\D/', '', $nowa);
                $nowaMatch = ($searchDigits !== '' && str_contains($nowaDigits, $searchDigits));
                $nameMatch = str_contains(mb_strtolower($nama), $searchLower);
                $msgMatch = str_contains(mb_strtolower($lastMsg), $searchLower);
                if (!$nowaMatch && !$nameMatch && !$msgMatch) {
                    continue;
                }
            }

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
$totalQueue = 0;
$totalPagesQueue = 1;
$pageQueue = 1;
$pagedQueue = [];

if ($tab === 'queue') {
    $filterStatus = (string)($_GET['qstatus'] ?? 'all');
    $whereStatus = "1=1";
    if ($filterStatus === 'pending') $whereStatus = "q.status = 'pending'";
    elseif ($filterStatus === 'sent') $whereStatus = "q.status = 'sent'";
    elseif ($filterStatus === 'cancelled') $whereStatus = "q.status LIKE 'cancelled_%'";
    elseif ($filterStatus === 'failed') $whereStatus = "q.status = 'failed'";

    $whereSearch = "1=1";
    if ($search !== '') {
        $escapedSearch = $conn->real_escape_string($search);
        $whereSearch = "(q.nowa LIKE '%{$escapedSearch}%' OR q.nama LIKE '%{$escapedSearch}%')";
    }

    $whereClause = "{$whereStatus} AND {$whereSearch}";
    $totalQueue = crmCount($conn, "SELECT COUNT(*) AS total FROM crm_automation_queue q WHERE {$whereClause}");
    $totalPagesQueue = max(1, (int)ceil($totalQueue / $perPage));
    $pageQueue = min($currentPage, $totalPagesQueue);
    $offsetQueue = ($pageQueue - 1) * $perPage;

    $orderQueue = "ORDER BY (q.status = 'pending') DESC, q.scheduled_at ASC, q.id ASC";
    if ($filterStatus === 'sent') {
        $orderQueue = "ORDER BY q.sent_at DESC, q.id DESC";
    } elseif ($filterStatus === 'cancelled' || $filterStatus === 'failed') {
        $orderQueue = "ORDER BY q.id DESC";
    }

    $resQueue = $conn->query("
        SELECT q.*, r.name AS rule_name, t.name AS template_name
        FROM crm_automation_queue q
        LEFT JOIN crm_automation_rules r ON r.id = q.rule_id
        LEFT JOIN crm_automation_templates t ON t.id = q.template_id
        WHERE {$whereClause}
        {$orderQueue}
        LIMIT {$offsetQueue}, {$perPage}
    ");
    if ($resQueue instanceof mysqli_result) {
        $pagedQueue = $resQueue->fetch_all(MYSQLI_ASSOC);
    }
}

// Helper pagination
function crmRenderPagination(int $currentPage, int $totalPages, int $totalItems, int $perPage, array $baseParams): string
{
    if ($totalItems <= 0) {
        return '';
    }

    $startItem = ($currentPage - 1) * $perPage + 1;
    $endItem = min($totalItems, $currentPage * $perPage);

    $html = '<div class="crm-pagination">';
    $html .= '<div class="crm-pagination-info">Menampilkan <strong>' . $startItem . '-' . $endItem . '</strong> dari <strong>' . $totalItems . '</strong> data</div>';

    if ($totalPages > 1) {
        $html .= '<div class="crm-pagination-links">';

        // Prev
        $prevParams = array_merge($baseParams, ['p' => max(1, $currentPage - 1)]);
        $prevUrl = 'index.php?' . http_build_query($prevParams);
        $prevDisabled = ($currentPage <= 1) ? ' disabled' : '';
        $html .= '<a href="' . ($currentPage <= 1 ? '#' : htmlspecialchars($prevUrl)) . '" class="crm-page-btn' . $prevDisabled . '"><i class="fa-solid fa-chevron-left"></i> Prev</a>';

        // Page numbers
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);

        if ($startPage > 1) {
            $p1Params = array_merge($baseParams, ['p' => 1]);
            $html .= '<a href="index.php?' . htmlspecialchars(http_build_query($p1Params)) . '" class="crm-page-btn">1</a>';
            if ($startPage > 2) {
                $html .= '<span class="crm-page-dots">...</span>';
            }
        }

        for ($p = $startPage; $p <= $endPage; $p++) {
            $pParams = array_merge($baseParams, ['p' => $p]);
            $activeClass = ($p === $currentPage) ? ' active' : '';
            $html .= '<a href="index.php?' . htmlspecialchars(http_build_query($pParams)) . '" class="crm-page-btn' . $activeClass . '">' . $p . '</a>';
        }

        if ($endPage < $totalPages) {
            if ($endPage < $totalPages - 1) {
                $html .= '<span class="crm-page-dots">...</span>';
            }
            $pEndParams = array_merge($baseParams, ['p' => $totalPages]);
            $html .= '<a href="index.php?' . htmlspecialchars(http_build_query($pEndParams)) . '" class="crm-page-btn">' . $totalPages . '</a>';
        }

        // Next
        $nextParams = array_merge($baseParams, ['p' => min($totalPages, $currentPage + 1)]);
        $nextUrl = 'index.php?' . http_build_query($nextParams);
        $nextDisabled = ($currentPage >= $totalPages) ? ' disabled' : '';
        $html .= '<a href="' . ($currentPage >= $totalPages ? '#' : htmlspecialchars($nextUrl)) . '" class="crm-page-btn' . $nextDisabled . '">Next <i class="fa-solid fa-chevron-right"></i></a>';

        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

// Hitung data pagination per tab
$totalProspects = count($prospects);
$totalPagesProspects = max(1, (int)ceil($totalProspects / $perPage));
$pageProspects = min($currentPage, $totalPagesProspects);
$pagedProspects = array_slice($prospects, ($pageProspects - 1) * $perPage, $perPage);

$totalRules = count($rules);
$totalPagesRules = max(1, (int)ceil($totalRules / $perPage));
$pageRules = min($currentPage, $totalPagesRules);
$pagedRules = array_slice($rules, ($pageRules - 1) * $perPage, $perPage);

if ($tab !== 'queue') {
    $totalQueue = count($queueItems);
    $totalPagesQueue = max(1, (int)ceil($totalQueue / $perPage));
    $pageQueue = min($currentPage, $totalPagesQueue);
    $pagedQueue = array_slice($queueItems, ($pageQueue - 1) * $perPage, $perPage);
}
?>

<div class="crm-auto-workspace">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px">
        <div>
            <h1 style="margin:0;font-size:20px;font-weight:800;color:#0f172a">Automation Follow Up</h1>
            <span style="font-size:12px;color:#64748b">Otomasi pesan prospek baru & jam istirahat</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <span style="background:<?= $sleepSettings['enabled'] ? '#ecfdf5' : '#f1f5f9' ?>;color:<?= $sleepSettings['enabled'] ? '#047857' : '#64748b' ?>;border:1px solid <?= $sleepSettings['enabled'] ? '#a7f3d0' : '#e2e8f0' ?>;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:5px">
                <i class="fa-solid fa-moon"></i> Istirahat <?= htmlspecialchars($sleepSettings['start']) ?> &ndash; <?= htmlspecialchars($sleepSettings['end']) ?> WIB
            </span>
            <button type="button" id="btnOpenSleepModal" style="background:#fff;border:1px solid #cbd5e1;padding:4px 12px;border-radius:8px;font-size:11px;font-weight:600;color:#334155;cursor:pointer">
                <i class="fa-solid fa-gear"></i> Atur
            </button>
        </div>
    </div>

    <!-- Minimalist Pipeline Bar -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:8px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:11px">
        <div style="display:flex;align-items:center;gap:6px;color:#334155">
            <span style="font-weight:700;color:#166534"><i class="fa-solid fa-bolt"></i> Alur:</span>
            <span>Welcome 2m &rarr; FU 2 (45m) &rarr; FU 3 (4j) &rarr; H+1 &rarr; H+3 &rarr; H+7</span>
        </div>
        <span style="color:#047857;background:#ecfdf5;padding:2px 8px;border-radius:6px;font-weight:600;font-size:10px">
            <i class="fa-solid fa-shield-halved"></i> Stop otomatis saat balas/bayar
        </span>
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
            <span>Log Prospek</span>
            <span class="crm-tab-pill"><?= number_format(count($prospects)) ?></span>
        </a>
        <a href="?page=automation-followup&tab=rules" class="crm-tab-btn <?= $tab === 'rules' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>Pola Pemicu (Rules)</span>
            <span class="crm-tab-pill"><?= number_format($statRulesActive) ?></span>
        </a>
        <a href="?page=automation-followup&tab=templates" class="crm-tab-btn <?= $tab === 'templates' ? 'active' : '' ?>">
            <i class="fa-regular fa-file-lines"></i>
            <span>Template</span>
            <span class="crm-tab-pill"><?= number_format(count($templates)) ?></span>
        </a>
        <a href="?page=automation-followup&tab=queue" class="crm-tab-btn <?= $tab === 'queue' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i>
            <span>Antrean</span>
            <span class="crm-tab-pill" style="<?= $statPending > 0 ? 'background: #fef3c7; color: #92400e;' : '' ?>"><?= number_format($statPending) ?></span>
        </a>
    </nav>

    <!-- Tab 1: Prospects Log -->
    <?php if ($tab === 'prospects'): ?>
        <div class="crm-card">
            <div class="crm-card-header">
                <div>
                    <h2>Log Prospek Masuk</h2>
                </div>
                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center;">
                    <form method="GET" action="index.php" style="margin: 0; display: flex; gap: 0.25rem; align-items: center;">
                        <input type="hidden" name="page" value="automation-followup">
                        <input type="hidden" name="tab" value="prospects">
                        <input type="hidden" name="pfilter" value="<?= htmlspecialchars($pfilter) ?>">
                        <input type="hidden" name="cfilter" value="<?= htmlspecialchars($cfilter) ?>">
                        <div style="position: relative; display: flex; align-items: center;">
                            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.65rem; color: #94a3b8; font-size: 0.72rem; pointer-events: none;"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama / no WA..." class="form-control" style="padding-left: 1.8rem; height: 30px; font-size: 0.75rem; width: 160px;">
                        </div>
                        <button type="submit" class="btn-secondary" style="height: 30px; padding: 0 0.55rem; font-size: 0.72rem;">Cari</button>
                        <?php if ($search !== ''): ?>
                            <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=<?= urlencode($cfilter) ?>" class="btn-secondary" style="height: 30px; padding: 0 0.5rem; font-size: 0.72rem; color: #ef4444;" title="Reset pencarian"><i class="fa-solid fa-xmark"></i></a>
                        <?php endif; ?>
                    </form>
                    <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? ('?page=automation-followup&tab=prospects')) ?>" class="btn-secondary" style="height: 30px; padding: 0 0.55rem; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 0.3rem;" title="Muat ulang data terbaru">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=unpaid<?= $cfilter !== 'all' ? '&cfilter=' . urlencode($cfilter) : '' ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn-secondary" style="height: 30px; padding: 0 0.55rem; font-size: 0.72rem; display: inline-flex; align-items: center; <?= $pfilter === 'unpaid' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-filter"></i> Belum Finance
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=paid<?= $cfilter !== 'all' ? '&cfilter=' . urlencode($cfilter) : '' ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn-secondary" style="height: 30px; padding: 0 0.55rem; font-size: 0.72rem; display: inline-flex; align-items: center; <?= $pfilter === 'paid' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i> Sudah Finance
                    </a>
                    <a href="?page=automation-followup&tab=prospects&pfilter=all<?= $cfilter !== 'all' ? '&cfilter=' . urlencode($cfilter) : '' ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn-secondary" style="height: 30px; padding: 0 0.55rem; font-size: 0.72rem; display: inline-flex; align-items: center; <?= $pfilter === 'all' ? 'background: #166534; color: #fff;' : '' ?>">
                        Semua
                    </a>
                </div>
            </div>

            <!-- Category Filter from Landing Page -->
            <div style="padding: 0.5rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap; background: #fafafa;">
                <span style="font-size: 0.75rem; font-weight: 600; color: #64748b; margin-right: 0.25rem;">
                    <i class="fa-solid fa-tags"></i> Kategori:
                </span>
                <?php $sParam = ($search !== '') ? '&search=' . urlencode($search) : ''; ?>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=all<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'all' ? 'background: #0f172a; color: #fff;' : '' ?>">
                    Semua
                </a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=general<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'general' ? 'background: #334155; color: #fff;' : '' ?>">
                    Tanya Detail
                </a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=form_lead<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'form_lead' ? 'background: #0284c7; color: #fff;' : '' ?>">
                    Form Nama LP
                </a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=pemula<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'pemula' ? 'background: #1d4ed8; color: #fff;' : '' ?>">Menghafal Pemula</a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=lanjutan<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'lanjutan' ? 'background: #6d28d9; color: #fff;' : '' ?>">Lanjutan & Muroja'ah</a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=cilik<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'cilik' ? 'background: #be185d; color: #fff;' : '' ?>">Tahfidz Cilik</a>
                <a href="?page=automation-followup&tab=prospects&pfilter=<?= urlencode($pfilter) ?>&cfilter=cta<?= $sParam ?>" class="btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.72rem; <?= $cfilter === 'cta' ? 'background: #047857; color: #fff;' : '' ?>">Pendaftaran CTA</a>
            </div>

            <div class="crm-table-container">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>Kontak</th>
                            <th>Pemicu</th>
                            <th>Finance</th>
                            <th>Antrean</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($prospects)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 2.5rem 1rem;">
                                    <i class="fa-solid fa-inbox" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                    <div>Tidak ada kontak prospek pada filter ini.</div>
                                    <?php if ($search !== '' || $cfilter !== 'all' || $pfilter !== 'all'): ?>
                                        <div style="margin-top: 0.75rem;">
                                            <a href="?page=automation-followup&tab=prospects&pfilter=all&cfilter=all" class="btn-secondary" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                                <i class="fa-solid fa-rotate-left"></i> Reset Semua Filter
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pagedProspects as $item): ?>
                                <tr id="row-p-<?= preg_replace('/\D/', '', $item['nowa']) ?>">
                                    <td>
                                        <div class="crm-contact-cell">
                                            <div class="crm-avatar">
                                                <?= htmlspecialchars(mb_strtoupper(mb_substr($item['nama'], 0, 1))) ?>
                                            </div>
                                            <div class="crm-contact-info">
                                                <span class="crm-contact-name"><?= htmlspecialchars($item['nama']) ?></span>
                                                <div class="crm-contact-wa-wrap">
                                                    <a href="https://wa.me/<?= htmlspecialchars(crmProspectNormalizeNumber($item['nowa'])) ?>" target="_blank" rel="noopener noreferrer" class="crm-contact-wa" title="Buka obrolan di WhatsApp">
                                                        <i class="fa-brands fa-whatsapp"></i>
                                                        <span><?= htmlspecialchars($item['nowa']) ?></span>
                                                    </a>
                                                    <button type="button" class="btn-copy-wa" onclick="crmCopyWaNumber('<?= htmlspecialchars(crmProspectNormalizeNumber($item['nowa'])) ?>', this)" title="Salin nomor WhatsApp">
                                                        <i class="fa-regular fa-copy"></i>
                                                    </button>
                                                </div>
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
                                        <div style="display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start;">
                                            <?php if ($item['has_payment']): ?>
                                                <span class="badge-status badge-sent" title="Status: Sudah isi/terdata di Finance">
                                                    <i class="fa-solid fa-file-invoice-dollar"></i> Terdata Finance
                                                </span>
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                                                    <input type="hidden" name="action" value="toggle_finance_status">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="status" value="unpaid">
                                                    <button type="submit" class="btn-secondary" style="font-size: 0.68rem; padding: 0.15rem 0.45rem; height: 22px; display: inline-flex; align-items: center; gap: 0.25rem; color: #64748b;" onclick="return confirm('Ubah status finance kontak ini menjadi Belum Diisi Finance?');" title="Kembalikan status menjadi Belum">
                                                        <i class="fa-solid fa-rotate-left"></i> Ke Belum
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge-status badge-pending" title="Status: Belum ada data Finance">
                                                    <i class="fa-solid fa-clock"></i> Belum Finance
                                                </span>
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                                                    <input type="hidden" name="action" value="toggle_finance_status">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="status" value="paid">
                                                    <button type="submit" class="btn-secondary" style="font-size: 0.68rem; padding: 0.15rem 0.5rem; height: 24px; display: inline-flex; align-items: center; gap: 0.3rem; background: #ecfdf5; border-color: #a7f3d0; color: #047857; font-weight: 600;" onclick="return confirm('Ubah status finance kontak ini menjadi Sudah? Antrean follow up pending akan dihentikan otomatis.');" title="Ubah status menjadi Sudah Diisi Finance">
                                                        <i class="fa-solid fa-circle-check"></i> Tandai Sudah
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($item['queue']): ?>
                                            <?php $qs = (string)$item['queue']['status']; ?>
                                            <?php if ($qs === 'pending'): ?>
                                                <?php $isAuto = (($item['queue']['trigger_source'] ?? '') === 'auto_webhook'); ?>
                                                <?php if ($isAuto): ?>
                                                    <span class="badge-status badge-sent" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;" title="Terjadwal otomatis via Webhook WhatsApp saat chat baru masuk">
                                                        <i class="fa-solid fa-bolt"></i> Otomatis
                                                    </span>
                                                    <div style="font-size: 0.68rem; color: #64748b; margin-top: 2px;">
                                                        <i class="fa-regular fa-clock"></i> <?= date('d/m H:i', strtotime($item['queue']['scheduled_at'])) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge-status badge-pending">
                                                        <i class="fa-solid fa-calendar-check"></i> <?= date('d/m H:i', strtotime($item['queue']['scheduled_at'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php elseif ($qs === 'sent'): ?>
                                                <span class="badge-status badge-sent"><i class="fa-solid fa-check-double"></i> Terkirim</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-cancelled"><?= htmlspecialchars(str_replace('cancelled_', '', $qs)) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.75rem;"><i class="fa-regular fa-circle"></i> Kosong</span>
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
                                                    <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                                                    <input type="hidden" name="nowa" value="<?= htmlspecialchars($item['nowa']) ?>">
                                                    <input type="hidden" name="nama" value="<?= htmlspecialchars($item['nama']) ?>">
                                                    <input type="hidden" name="action" value="schedule_sequence">
                                                    <input type="hidden" name="template_id" value="0">
                                                    <button type="submit" class="btn-schedule-sequence" title="Jadwalkan urutan follow up otomatis" onclick="return confirm('Jadwalkan alur follow-up untuk kontak ini?');">
                                                        <i class="fa-solid fa-calendar-check"></i>
                                                        <span>Jadwalkan Alur</span>
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
                                                    <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
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
            <?php
            $prospectPaginationParams = ['page' => 'automation-followup', 'tab' => 'prospects', 'cfilter' => $cfilter, 'pfilter' => $pfilter];
            if ($search !== '') {
                $prospectPaginationParams['search'] = $search;
            }
            ?>
            <?= crmRenderPagination($pageProspects, $totalPagesProspects, $totalProspects, $perPage, $prospectPaginationParams) ?>
        </div>
    <?php endif; ?>

    <!-- Tab 2: Rules (Pola Pemicu) -->
    <?php if ($tab === 'rules'): ?>
        <div class="crm-card" id="ruleFormCard">
            <div class="crm-card-header">
                <h2 id="ruleFormHeading">Tambah / Edit Pola Chat</h2>
            </div>
            <form action="index.php?page=automation-followup" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="tab" value="rules">
                <input type="hidden" name="rule_id" id="ruleId" value="0">

                <div class="form-group">
                    <label for="ruleKw">Kata Kunci / Teks Chat <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="trigger_keyword" id="ruleKw" class="form-control" placeholder="Contoh: Kak, saya ingin daftar Tahfidz Cilik" required>
                </div>

                <div class="form-group">
                    <label for="ruleName">Label Program <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="name" id="ruleName" class="form-control" placeholder="Contoh: Tahfidz Cilik" required>
                </div>

                <div class="form-group">
                    <label for="ruleCategory">Kategori</label>
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
                <h2>Daftar Pola Pemicu</h2>
                <span style="font-size: 0.75rem; color: #64748b;"><?= count($rules) ?> pola terdaftar</span>
            </div>
            <div class="table-responsive">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th style="width: 110px;">Status</th>
                            <th>Kata Kunci / Pesan</th>
                            <th>Label</th>
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
                            <?php foreach ($pagedRules as $r): ?>
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
                                <tr id="row-rule-<?= (int)$r['id'] ?>">
                                    <td>
                                        <form action="index.php?page=automation-followup" method="POST" style="margin: 0;">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
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
                                                <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
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
            <?= crmRenderPagination($pageRules, $totalPagesRules, $totalRules, $perPage, ['page' => 'automation-followup', 'tab' => 'rules']) ?>
        </div>
    <?php endif; ?>

    <!-- Tab 3: Templates -->
    <?php if ($tab === 'templates'): ?>
        <div class="crm-card" id="tplFormCard">
            <div class="crm-card-header">
                <h2 id="tplFormHeading">Buat / Edit Template</h2>
            </div>
            <form action="index.php?page=automation-followup" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="tab" value="templates">
                <input type="hidden" name="template_id" id="tplId" value="0">

                <div class="form-group">
                    <label for="tplName">Nama Template</label>
                    <input type="text" name="name" id="tplName" class="form-control" placeholder="Contoh: Tahfidz Private - Follow Up 1 (10 Menit)" required>
                </div>

                <div class="form-group">
                    <label for="tplDesc">Deskripsi (Opsional)</label>
                    <input type="text" name="description" id="tplDesc" class="form-control" placeholder="Tujuan pesan ini dikirimkan">
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                        <label for="tplContent" style="margin: 0;">Isi Pesan WhatsApp</label>
                        <span style="font-size: 0.72rem; color: #64748b;"><span id="tplCharCount">0</span> karakter</span>
                    </div>
                    <div style="display: flex; gap: 0.35rem; align-items: center; margin-bottom: 0.35rem; flex-wrap: wrap;">
                        <span style="font-size: 0.72rem; color: #475569; font-weight: 600;">Sisipkan variabel:</span>
                        <button type="button" class="crm-var-chip" onclick="insertTplVar('{nama}')">{nama}</button>
                        <button type="button" class="crm-var-chip" onclick="insertTplVar('{sapaan}')">{sapaan}</button>
                        <button type="button" class="crm-var-chip" onclick="insertTplVar('{program}')">{program}</button>
                    </div>
                    <textarea name="content" id="tplContent" class="form-control" rows="6" placeholder="Ketik draf pesan WhatsApp..." oninput="updateTplCharCount()" required></textarea>
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
                <h2>Daftar Template</h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php if (empty($templates)): ?>
                    <p style="color: #64748b; font-size: 0.875rem;">Belum ada template tersimpan.</p>
                <?php else: ?>
                    <?php foreach ($templates as $t): ?>
                        <div id="row-tpl-<?= (int)$t['id'] ?>" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
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
                                        <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
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
                    <h2>Antrean & Riwayat</h2>
                </div>
                <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                    <form action="index.php?page=automation-followup" method="POST" style="margin: 0;" onsubmit="return confirm('Bersihkan semua antrean bentrok yang terjadwal di jam bersamaan?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                        <input type="hidden" name="action" value="clean_duplicate_queues">
                        <input type="hidden" name="tab" value="queue">
                        <button type="submit" class="btn-secondary" style="color: #dc2626; border-color: #fca5a5; background: #fff5f5; font-size: 0.75rem; height: 32px; padding: 0 0.65rem;" title="Batalkan antrean spesifik duplikat yang bentrok di jam yang sama">
                            <i class="fa-solid fa-broom"></i> Bersihkan Bentrok
                        </button>
                    </form>
                    <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? ('?page=automation-followup&tab=queue')) ?>" class="btn-secondary" style="height: 32px; padding: 0 0.65rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 0.35rem;" title="Muat ulang data terbaru tanpa mengubah filter">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- Queue Filter & Search Sub-Bar -->
            <div style="padding: 0.6rem 1.25rem 0.85rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.6rem; background: #fafafa;">
                <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                    <span style="font-size: 0.75rem; font-weight: 600; color: #64748b; margin-right: 0.25rem;">
                        <i class="fa-solid fa-filter"></i> Status:
                    </span>
                    <?php $qSearch = ($search !== '') ? '&search=' . urlencode($search) : ''; ?>
                    <a href="?page=automation-followup&tab=queue<?= $qSearch ?>" class="btn-secondary" style="height: 28px; padding: 0 0.6rem; font-size: 0.72rem; display: inline-flex; align-items: center; <?= $filterStatus === 'all' ? 'background: #166534; color: #fff;' : '' ?>">
                        Semua
                    </a>
                    <a href="?page=automation-followup&tab=queue&qstatus=pending<?= $qSearch ?>" class="btn-secondary" style="height: 28px; padding: 0 0.6rem; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 0.35rem; <?= $filterStatus === 'pending' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-clock"></i> Pending
                        <?php if ($statPending > 0): ?>
                            <span style="font-size: 0.68rem; padding: 0.05rem 0.35rem; border-radius: 9999px; background: <?= $filterStatus === 'pending' ? 'rgba(255,255,255,0.25)' : '#fef3c7' ?>; color: <?= $filterStatus === 'pending' ? '#fff' : '#92400e' ?>; font-weight: 700;"><?= number_format($statPending) ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?page=automation-followup&tab=queue&qstatus=sent<?= $qSearch ?>" class="btn-secondary" style="height: 28px; padding: 0 0.6rem; font-size: 0.72rem; display: inline-flex; align-items: center; <?= $filterStatus === 'sent' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i> Terkirim
                    </a>
                    <a href="?page=automation-followup&tab=queue&qstatus=cancelled<?= $qSearch ?>" class="btn-secondary" style="height: 28px; padding: 0 0.6rem; font-size: 0.72rem; display: inline-flex; align-items: center; <?= $filterStatus === 'cancelled' ? 'background: #166534; color: #fff;' : '' ?>">
                        <i class="fa-solid fa-ban"></i> Batal
                    </a>
                </div>

                <!-- Search form on right -->
                <form method="GET" action="index.php" style="margin: 0; display: flex; gap: 0.25rem; align-items: center;">
                    <input type="hidden" name="page" value="automation-followup">
                    <input type="hidden" name="tab" value="queue">
                    <input type="hidden" name="qstatus" value="<?= htmlspecialchars($filterStatus) ?>">
                    <div style="position: relative; display: flex; align-items: center;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.65rem; color: #94a3b8; font-size: 0.72rem; pointer-events: none;"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari kontak antrean..." class="form-control" style="padding-left: 1.8rem; height: 30px; font-size: 0.75rem; width: 170px;">
                    </div>
                    <button type="submit" class="btn-secondary" style="height: 30px; padding: 0 0.6rem; font-size: 0.72rem;">Cari</button>
                    <?php if ($search !== ''): ?>
                        <a href="?page=automation-followup&tab=queue&qstatus=<?= urlencode($filterStatus) ?>" class="btn-secondary" style="height: 30px; padding: 0 0.5rem; font-size: 0.72rem; color: #ef4444;" title="Reset pencarian"><i class="fa-solid fa-xmark"></i></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="crm-table-container">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>Kontak</th>
                            <th>Template</th>
                            <th>Jadwal</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedQueue)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 2.5rem 1rem;">
                                    <i class="fa-solid fa-inbox" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                    <div>Tidak ada antrean dalam filter ini.</div>
                                    <?php if ($search !== '' || $filterStatus !== 'all'): ?>
                                        <div style="margin-top: 0.75rem;">
                                            <a href="?page=automation-followup&tab=queue&qstatus=all" class="btn-secondary" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                                <i class="fa-solid fa-rotate-left"></i> Reset Filter Antrean
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pagedQueue as $q): ?>
                                <tr id="row-queue-<?= (int)$q['id'] ?>">
                                    <td>
                                        <div class="crm-contact-cell">
                                            <div class="crm-avatar">
                                                <?= htmlspecialchars(mb_strtoupper(mb_substr($q['nama'] ?: 'C', 0, 1))) ?>
                                            </div>
                                            <div class="crm-contact-info">
                                                <span class="crm-contact-name"><?= htmlspecialchars($q['nama'] ?: 'Calon Peserta') ?></span>
                                                <div class="crm-contact-wa-wrap">
                                                    <a href="https://wa.me/<?= htmlspecialchars(crmProspectNormalizeNumber((string)$q['nowa'])) ?>" target="_blank" rel="noopener noreferrer" class="crm-contact-wa" title="Buka obrolan di WhatsApp">
                                                        <i class="fa-brands fa-whatsapp"></i> <span><?= htmlspecialchars($q['nowa']) ?></span>
                                                    </a>
                                                    <button type="button" class="btn-copy-wa" onclick="crmCopyWaNumber('<?= htmlspecialchars(crmProspectNormalizeNumber((string)$q['nowa'])) ?>', this)" title="Salin nomor WhatsApp">
                                                        <i class="fa-regular fa-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
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
                                                    <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
                                                    <input type="hidden" name="action" value="send_now">
                                                    <input type="hidden" name="tab" value="queue">
                                                    <input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                                                    <button type="submit" class="btn-primary" style="padding: 0.25rem 0.55rem; font-size: 0.72rem; height: 28px;" title="Kirim pesan ini sekarang tanpa menunggu jadwal">
                                                        <i class="fa-solid fa-paper-plane"></i> Kirim
                                                    </button>
                                                </form>
                                                <form action="index.php?page=automation-followup" method="POST" style="margin: 0;" onsubmit="return confirm('Batalkan pengiriman pesan ini?');">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="return_url" value="<?= $currentUrl ?>">
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
            <?php
            $queuePaginationParams = ['page' => 'automation-followup', 'tab' => 'queue', 'qstatus' => $filterStatus];
            if ($search !== '') {
                $queuePaginationParams['search'] = $search;
            }
            ?>
            <?= crmRenderPagination($pageQueue, $totalPagesQueue, $totalQueue, $perPage, $queuePaginationParams) ?>
        </div>
    <?php endif; ?>

    <!-- Modal Pengaturan Jam Istirahat Malam -->
    <div id="sleepSettingsModal" hidden style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.45);padding:20px;align-items:center;justify-content:center">
        <div style="width:min(480px,100%);background:#fff;border-radius:18px;padding:24px;box-shadow:0 20px 60px rgba(15,23,42,.2)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:34px;height:34px;border-radius:8px;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:16px">
                        <i class="fa-solid fa-moon"></i>
                    </div>
                    <div>
                        <h3 style="margin:0;font-size:16px;color:#1e293b">Pengaturan Jam Istirahat Malam</h3>
                        <small style="color:#64748b;font-size:11px">Pesan tidak akan dikirim saat jam istirahat</small>
                    </div>
                </div>
                <button type="button" id="btnCloseSleepModal" style="border:0;background:#f1f5f9;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:16px">×</button>
            </div>

            <form action="actions/automation-followup.php" method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_sleep_settings">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <input type="hidden" name="return_url" value="<?= $currentUrl ?>">

                <div style="margin-bottom:16px">
                    <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#334155;cursor:pointer">
                        <input type="checkbox" name="sleep_hours_enabled" value="1" <?= $sleepSettings['enabled'] ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#166534">
                        Aktifkan Jam Istirahat
                    </label>
                    <small style="display:block;color:#64748b;font-size:11px;margin-top:4px;padding-left:24px">
                        Pesan malam otomatis dialihkan ke pagi hari.
                    </small>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px">Mulai Istirahat</label>
                        <input type="time" name="sleep_start" value="<?= htmlspecialchars($sleepSettings['start']) ?>" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px">
                        <small style="color:#94a3b8;font-size:10px">Default: 21:30 WIB</small>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px">Selesai Istirahat</label>
                        <input type="time" name="sleep_end" value="<?= htmlspecialchars($sleepSettings['end']) ?>" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px">
                        <small style="color:#94a3b8;font-size:10px">Default: 07:30 WIB</small>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:8px">
                    <button type="button" id="btnCancelSleepModal" style="background:#f1f5f9;border:0;padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;color:#475569;cursor:pointer">Batal</button>
                    <button type="submit" class="btn-primary" style="padding:8px 18px;font-size:12px;border-radius:8px">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    var sleepModal = document.getElementById('sleepSettingsModal');
    var btnOpenSleep = document.getElementById('btnOpenSleepModal');
    var btnCloseSleep = document.getElementById('btnCloseSleepModal');
    var btnCancelSleep = document.getElementById('btnCancelSleepModal');

    if (btnOpenSleep && sleepModal) {
        btnOpenSleep.addEventListener('click', function() {
            sleepModal.style.display = 'flex';
            sleepModal.removeAttribute('hidden');
        });
    }
    function hideSleepModal() {
        if (sleepModal) {
            sleepModal.style.display = 'none';
            sleepModal.setAttribute('hidden', '');
        }
    }
    if (btnCloseSleep) btnCloseSleep.addEventListener('click', hideSleepModal);
    if (btnCancelSleep) btnCancelSleep.addEventListener('click', hideSleepModal);
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

    function crmCopyWaNumber(nowa, btn) {
        if (!nowa || btn.classList.contains('copied')) return;
        var origHtml = btn.innerHTML;
        var feedback = function() {
            btn.innerHTML = '<i class="fa-solid fa-check" style="color: #10b981;"></i>';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.innerHTML = origHtml;
                btn.classList.remove('copied');
            }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(nowa).then(feedback).catch(function() {
                fallbackCopy(nowa, feedback);
            });
        } else {
            fallbackCopy(nowa, feedback);
        }
    }

    function fallbackCopy(text, callback) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            if (callback) callback();
        } catch (e) {}
        document.body.removeChild(ta);
    }

    // Preserve & Restore Scroll Position on Form Submit + Debounce Spinner
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || !form.closest('.crm-auto-workspace')) return;
        try {
            sessionStorage.setItem('crm_scroll_y', window.scrollY.toString());
        } catch (err) {}

        // Debounce submit button to avoid double-clicks
        var submitBtn = form.querySelector('button[type=submit]');
        if (submitBtn && !submitBtn.disabled) {
            setTimeout(function() {
                try {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.7';
                    var icon = submitBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'fa-solid fa-spinner fa-spin';
                    }
                } catch (err) {}
            }, 10);
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        updateTplCharCount();

        // Restore scroll position or smooth scroll to targeted anchor
        if (window.location.hash) {
            try {
                var targetEl = document.querySelector(window.location.hash);
                if (targetEl) {
                    targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                sessionStorage.removeItem('crm_scroll_y');
            } catch (err) {}
        } else {
            try {
                var savedY = sessionStorage.getItem('crm_scroll_y');
                if (savedY !== null) {
                    window.scrollTo({ top: parseInt(savedY, 10), behavior: 'instant' });
                    sessionStorage.removeItem('crm_scroll_y');
                }
            } catch (err) {}
        }
    });
    </script>
</div>
