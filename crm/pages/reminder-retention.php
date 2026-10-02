<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reminder-retention.php';

$crmTitle = 'Retention Rate';

$previousSource = trim((string)($_GET['previous_source'] ?? ''));
$currentSource = trim((string)($_GET['current_source'] ?? ''));
$selectedAk = trim((string)($_GET['ak'] ?? ''));
$analyze = (string)($_GET['analyze'] ?? '') === '1';
$threshold = 70.0;

$csvImports = [];
$paymentSources = [];
$analysis = null;
$analysisError = '';
$historyMap = [];
$previousRecords = [];
$currentRecords = [];

try {
    $csvImports = crmReminderCsvList();
} catch (Throwable $e) {
    error_log('CRM retention CSV source list failed: ' . $e->getMessage());
}

try {
    $result = $conn->query(
        "SELECT bulan_pembayaran, MAX(id) AS last_id
         FROM pembayaran
         WHERE bulan_pembayaran IS NOT NULL
           AND bulan_pembayaran <> ''
         GROUP BY bulan_pembayaran
         ORDER BY last_id DESC"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $paymentSources[] = (string)$row['bulan_pembayaran'];
        }
    }
} catch (Throwable $e) {
    error_log('CRM retention payment source list failed: ' . $e->getMessage());
}

$sourceLabel = static function (string $source) use ($csvImports): string {
    [$type, $value] = crmRetentionParseSourceKey($source);

    if ($type === 'csv') {
        foreach ($csvImports as $item) {
            if ((string)($item['file'] ?? '') === $value) {
                return 'CSV · ' . (string)($item['label'] ?? $value);
            }
        }
        return 'CSV · ' . $value;
    }

    if ($type === 'payment') {
        return 'Pembayaran · ' . $value;
    }

    return 'Sumber belum dipilih';
};

$formatRate = static function (float $rate): string {
    $rounded = round($rate, 1);
    return (abs($rounded - round($rounded)) < 0.001)
        ? number_format($rounded, 0, ',', '.')
        : number_format($rounded, 1, ',', '.');
};

$formatHistory = static function (array $history): string {
    $count = (int)($history['count'] ?? 0);
    $lastAt = (string)($history['last_at'] ?? '');

    if ($count < 1 || $lastAt === '') {
        return 'Belum pernah dihubungi';
    }

    $timestamp = strtotime($lastAt);
    if (!$timestamp) {
        return $count . 'x';
    }

    $label = date('Y-m-d', $timestamp) === date('Y-m-d')
        ? 'Hari ini ' . date('H:i', $timestamp)
        : date('d/m/Y H:i', $timestamp);

    return $count . 'x · ' . $label;
};

if ($analyze) {
    if ($previousSource === '' || $currentSource === '') {
        $analysisError = 'Pilih sumber batch sebelumnya dan batch sekarang terlebih dahulu.';
    } elseif ($previousSource === $currentSource) {
        // Comparing a source with itself is valid, but it is usually accidental.
        // Keep it allowed because the result is deterministic and harmless.
    }

    if ($analysisError === '') {
        try {
            $previousRecords = crmRetentionLoadSource($conn, $previousSource);
            $currentRecords = crmRetentionLoadSource($conn, $currentSource);

            if ($selectedAk !== '') {
                $akKey = crmRetentionNormalizeGroup($selectedAk);

                $previousRecords = array_filter(
                    $previousRecords,
                    static fn(array $record): bool => (string)($record['group_key'] ?? '') === $akKey
                );

                $currentRecords = array_filter(
                    $currentRecords,
                    static fn(array $record): bool => (string)($record['group_key'] ?? '') === $akKey
                );

                $previousRecords = array_values($previousRecords);
                $currentRecords = array_values($currentRecords);
            }

            $analysis = crmRetentionCompare($previousRecords, $currentRecords);

            // History is optional UI metadata. If log_wa has a problem,
            // retention numbers must still render.
            try {
                $historyMap = crmRetentionLoadHistory($conn, $analysis['not_continued']);
            } catch (Throwable $historyError) {
                error_log('CRM retention history read failed: ' . $historyError->getMessage());
                $historyMap = [];
            }
        } catch (Throwable $e) {
            error_log('CRM retention analysis failed: ' . $e->getMessage());
            $analysisError = 'Analisis retention gagal diproses. Periksa sumber yang dipilih dan coba lagi.';
            $analysis = null;
            $previousRecords = [];
            $currentRecords = [];
            $historyMap = [];
        }
    }
}

$akOptions = [];
if ($analysis !== null) {
    foreach ($previousRecords as $record) {
        $key = (string)($record['group_key'] ?? '');
        if ($key === '') continue;
        $akOptions[$key] = crmRetentionDisplayGroup((string)($record['group'] ?? ''));
    }
    uasort($akOptions, static fn(string $a, string $b): int => strcasecmp($a, $b));
}

$templates = [];
try {
    $result = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
    if ($result) {
        $templates = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    error_log('CRM retention template list failed: ' . $e->getMessage());
}

$returnQuery = http_build_query([
    'previous_source' => $previousSource,
    'current_source' => $currentSource,
    'ak' => $selectedAk,
    'analyze' => '1',
]);

$rate = (float)($analysis['rate'] ?? 0.0);
$rateClass = $rate >= $threshold ? 'good' : 'bad';
?>
<section class="retention-page">
    <div class="retention-topbar">
        <a class="reminder-back-btn" href="?page=reminder" title="Kembali ke Reminder" aria-label="Kembali ke Reminder">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        </a>
    </div>

    <section class="retention-hero">
        <div>
            <span class="reminder-kicker">Workspace Reminder</span>
            <h1>Retention Rate</h1>
            <p>Ukur berapa banyak peserta dari batch sebelumnya yang kembali lanjut di batch sekarang.</p>
        </div>
        <div class="retention-rule">
            <strong>Rumus</strong>
            <span>Lanjut ÷ total batch sebelumnya × 100%</span>
            <small>Peserta Baru tidak masuk perhitungan retention.</small>
        </div>
    </section>

    <section class="reminder-card retention-source-card">
        <div class="reminder-card-head">
            <div>
                <span class="reminder-kicker">Sumber analisis</span>
                <h2>Bandingkan dua batch</h2>
            </div>
            <?php if ($analysis !== null): ?>
                <span class="retention-source-badge"><i class="fa-solid fa-check"></i> Analisis aktif</span>
            <?php endif; ?>
        </div>

        <form method="get" class="retention-source-form">
            <input type="hidden" name="page" value="reminder-retention">
            <input type="hidden" name="analyze" value="1">

            <label>
                <span>Batch sebelumnya</span>
                <select name="previous_source" required>
                    <option value="">Pilih sumber...</option>
                    <?php foreach ($csvImports as $item):
                        $value = crmRetentionSourceKey('csv', (string)$item['file']);
                    ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $previousSource === $value ? 'selected' : '' ?>>
                            CSV · <?= htmlspecialchars((string)$item['label']) ?> · <?= (int)($item['matched_count'] ?? 0) ?> cocok
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($paymentSources as $item):
                        $value = crmRetentionSourceKey('payment', $item);
                    ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $previousSource === $value ? 'selected' : '' ?>>
                            Pembayaran · <?= htmlspecialchars($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <span class="retention-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>

            <label>
                <span>Batch sekarang</span>
                <select name="current_source" required>
                    <option value="">Pilih sumber...</option>
                    <?php foreach ($csvImports as $item):
                        $value = crmRetentionSourceKey('csv', (string)$item['file']);
                    ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $currentSource === $value ? 'selected' : '' ?>>
                            CSV · <?= htmlspecialchars((string)$item['label']) ?> · <?= (int)($item['matched_count'] ?? 0) ?> cocok
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($paymentSources as $item):
                        $value = crmRetentionSourceKey('payment', $item);
                    ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $currentSource === $value ? 'selected' : '' ?>>
                            Pembayaran · <?= htmlspecialchars($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>AK</span>
                <select name="ak">
                    <option value="">Semua AK</option>
                    <?php foreach ($akOptions as $key => $label): ?>
                        <option value="<?= htmlspecialchars($label, ENT_QUOTES) ?>" <?= crmRetentionNormalizeGroup($selectedAk) === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="retention-analyze-btn" type="submit">
                <i class="fa-solid fa-chart-simple"></i>
                Analisis
            </button>
        </form>

        <div class="retention-source-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>CSV hanya dibaca dari file server. Payment dibaca dari tabel <code>pembayaran</code>. Identitas peserta dicocokkan berdasarkan nomor WhatsApp yang sudah dinormalisasi, bukan nama.</span>
        </div>

        <?php if ($analysisError !== ''): ?>
            <div class="retention-alert error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($analysisError) ?></span>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($analysis !== null): ?>
        <section class="retention-summary-grid">
            <article class="retention-summary-card">
                <span class="retention-summary-icon blue"><i class="fa-solid fa-users"></i></span>
                <div><strong><?= (int)$analysis['previous_count'] ?></strong><small>Batch sebelumnya</small></div>
            </article>
            <article class="retention-summary-card">
                <span class="retention-summary-icon purple"><i class="fa-solid fa-user-group"></i></span>
                <div><strong><?= (int)$analysis['current_count'] ?></strong><small>Batch sekarang</small></div>
            </article>
            <article class="retention-summary-card">
                <span class="retention-summary-icon green"><i class="fa-solid fa-user-check"></i></span>
                <div><strong><?= (int)$analysis['continued_count'] ?></strong><small>Lanjut</small></div>
            </article>
            <article class="retention-summary-card <?= $rateClass ?>">
                <span class="retention-summary-icon <?= $rateClass ?>"><i class="fa-solid fa-chart-line"></i></span>
                <div><strong><?= $formatRate($rate) ?>%</strong><small>Retention Rate · batas <?= $formatRate($threshold) ?>%</small></div>
            </article>
            <article class="retention-summary-card">
                <span class="retention-summary-icon red"><i class="fa-solid fa-user-minus"></i></span>
                <div><strong><?= (int)$analysis['not_continued_count'] ?></strong><small>Tidak lanjut</small></div>
            </article>
        </section>

        <section class="reminder-card retention-breakdown-card">
            <div class="reminder-card-head">
                <div>
                    <span class="reminder-kicker">Breakdown</span>
                    <h2>Retention per AK</h2>
                </div>
                <span class="retention-count-note"><?= htmlspecialchars($sourceLabel($previousSource)) ?> → <?= htmlspecialchars($sourceLabel($currentSource)) ?></span>
            </div>

            <?php if (!$analysis['breakdown']): ?>
                <div class="reminder-empty">
                    <i class="fa-solid fa-chart-simple"></i>
                    <strong>Tidak ada peserta pada filter ini</strong>
                    <span>Coba ganti AK atau sumber batch.</span>
                </div>
            <?php else: ?>
                <div class="retention-breakdown-table-wrap">
                    <table class="retention-breakdown-table">
                        <thead>
                            <tr>
                                <th>AK</th>
                                <th>Total</th>
                                <th>Lanjut</th>
                                <th>Tidak lanjut</th>
                                <th>Retention Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analysis['breakdown'] as $item):
                                $itemClass = (float)$item['rate'] >= $threshold ? 'good' : 'bad';
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string)$item['group']) ?></strong></td>
                                    <td><?= (int)$item['total'] ?></td>
                                    <td><?= (int)$item['continued'] ?></td>
                                    <td><?= (int)$item['not_continued'] ?></td>
                                    <td><span class="retention-rate-pill <?= $itemClass ?>"><?= $formatRate((float)$item['rate']) ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($analysis['not_continued_count'] > 0): ?>
            <section class="reminder-card retention-compose-card">
                <div class="reminder-card-head">
                    <div>
                        <span class="reminder-kicker">Follow-up</span>
                        <h2>Hubungi peserta yang tidak lanjut</h2>
                    </div>
                    <span class="retention-selected-count" id="retentionSelectedCount">0 dipilih</span>
                </div>

                <?php if ($templates): ?>
                    <form method="post" action="actions/reminder-retention-send.php" id="retentionSendForm">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                        <input type="hidden" name="selected" id="retentionSelectedInput" value="[]">
                        <input type="hidden" name="template_id" id="retentionTemplateId" value="<?= (int)$templates[0]['id'] ?>">
                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery, ENT_QUOTES) ?>">

                        <div class="retention-compose-grid">
                            <label class="reminder-field">
                                <span>Template</span>
                                <select id="retentionTemplate" name="template_id_select">
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= (int)$template['id'] ?>"><?= htmlspecialchars((string)$template['title']) ?><?= (string)$template['category'] !== '' ? ' · ' . htmlspecialchars((string)$template['category']) : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="retention-preview-box">
                                <span>Preview</span>
                                <p id="retentionPreview">Pilih peserta untuk melihat preview pesan.</p>
                            </div>

                            <button class="retention-send-btn" type="submit" id="retentionSendBtn" disabled>
                                <i class="fa-solid fa-paper-plane"></i>
                                Kirim Reminder
                            </button>
                        </div>
                        <small class="retention-compose-help">Maksimal 100 peserta per sekali kirim. Placeholder <code>{nama}</code> akan otomatis diganti nama peserta.</small>
                    </form>
                <?php else: ?>
                    <div class="retention-alert">
                        <i class="fa-solid fa-message"></i>
                        <span>Belum ada template pada <code>wa_templates</code>. Buat template dari Reminder Pembayaran terlebih dahulu.</span>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="reminder-card retention-detail-card">
            <div class="reminder-card-head">
                <div>
                    <span class="reminder-kicker">Detail peserta</span>
                    <h2>Lanjut & Tidak lanjut</h2>
                </div>
                <span class="retention-count-note"><?= (int)$analysis['previous_count'] ?> peserta cohort sebelumnya</span>
            </div>

            <div class="retention-detail-list">
                <?php foreach ($analysis['breakdown'] as $item):
                    $groupKey = crmRetentionNormalizeGroup((string)$item['group']);
                    $continuedForGroup = [];
                    $notContinuedForGroup = [];

                    foreach ($analysis['continued'] as $record) {
                        if ((string)($record['group_key'] ?? '') === $groupKey) {
                            $continuedForGroup[] = $record;
                        }
                    }

                    foreach ($analysis['not_continued'] as $record) {
                        if ((string)($record['group_key'] ?? '') === $groupKey) {
                            $notContinuedForGroup[] = $record;
                        }
                    }

                    $itemClass = (float)$item['rate'] >= $threshold ? 'good' : 'bad';
                ?>
                    <details class="retention-group" <?= count($analysis['breakdown']) === 1 ? 'open' : '' ?>>
                        <summary>
                            <span class="retention-group-title">
                                <strong><?= htmlspecialchars((string)$item['group']) ?></strong>
                                <small><?= (int)$item['total'] ?> total · <?= (int)$item['continued'] ?> lanjut · <?= (int)$item['not_continued'] ?> tidak lanjut</small>
                            </span>
                            <span class="retention-group-rate <?= $itemClass ?>"><?= $formatRate((float)$item['rate']) ?>%</span>
                            <i class="fa-solid fa-chevron-down retention-chevron" aria-hidden="true"></i>
                        </summary>

                        <div class="retention-detail-columns">
                            <div class="retention-detail-panel continued">
                                <div class="retention-detail-panel-head">
                                    <div><strong>Lanjut</strong><small><?= count($continuedForGroup) ?> peserta</small></div>
                                    <span><i class="fa-solid fa-user-check"></i></span>
                                </div>

                                <?php if (!$continuedForGroup): ?>
                                    <div class="retention-mini-empty">Belum ada peserta yang lanjut.</div>
                                <?php else: ?>
                                    <div class="retention-person-list">
                                        <?php foreach ($continuedForGroup as $record): ?>
                                            <div class="retention-person">
                                                <span class="retention-person-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$record['name'], 0, 1))) ?></span>
                                                <div>
                                                    <strong><?= htmlspecialchars((string)$record['name']) ?></strong>
                                                    <small><?= htmlspecialchars((string)$record['target_wa']) ?></small>
                                                </div>
                                                <span class="retention-person-status continue">Lanjut</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="retention-detail-panel not-continued">
                                <div class="retention-detail-panel-head">
                                    <div><strong>Tidak lanjut</strong><small><?= count($notContinuedForGroup) ?> peserta</small></div>
                                    <span><i class="fa-solid fa-user-minus"></i></span>
                                </div>

                                <?php if (!$notContinuedForGroup): ?>
                                    <div class="retention-mini-empty">Semua peserta pada AK ini lanjut.</div>
                                <?php else: ?>
                                    <div class="retention-person-list">
                                        <?php foreach ($notContinuedForGroup as $record):
                                            $targetWa = crmReminderCsvNormalizeWa((string)$record['target_wa']);
                                            $history = $historyMap[$targetWa] ?? [];
                                        ?>
                                            <label class="retention-person selectable">
                                                <input
                                                    type="checkbox"
                                                    class="retention-target"
                                                    value="<?= htmlspecialchars($targetWa, ENT_QUOTES) ?>"
                                                    data-name="<?= htmlspecialchars((string)$record['name'], ENT_QUOTES) ?>"
                                                >
                                                <span class="retention-person-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$record['name'], 0, 1))) ?></span>
                                                <div>
                                                    <strong><?= htmlspecialchars((string)$record['name']) ?></strong>
                                                    <small><?= htmlspecialchars($targetWa) ?></small>
                                                    <em><i class="fa-solid fa-rotate-left"></i> <?= htmlspecialchars($formatHistory($history)) ?></em>
                                                </div>
                                                <span class="retention-person-status stop">Tidak lanjut</span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="reminder-card retention-empty-state">
            <span class="retention-empty-icon"><i class="fa-solid fa-chart-line"></i></span>
            <h2>Siap menghitung retention</h2>
            <p>Pilih sumber batch sebelumnya dan batch sekarang, lalu jalankan analisis. Peserta Baru tidak akan masuk ke denominator retention.</p>
            <div class="retention-empty-rules">
                <span><i class="fa-solid fa-phone"></i> Cocokkan nomor WhatsApp</span>
                <span><i class="fa-solid fa-ban"></i> Abaikan Status Peserta</span>
                <span><i class="fa-solid fa-database"></i> Tidak membuat tabel retention</span>
            </div>
        </section>
    <?php endif; ?>
</section>

<script>
(() => {
    const form = document.getElementById('retentionSendForm');
    if (!form) return;

    const templateSelect = document.getElementById('retentionTemplate');
    const templateId = document.getElementById('retentionTemplateId');
    const selectedInput = document.getElementById('retentionSelectedInput');
    const selectedCount = document.getElementById('retentionSelectedCount');
    const preview = document.getElementById('retentionPreview');
    const sendButton = document.getElementById('retentionSendBtn');
    const targets = [...document.querySelectorAll('.retention-target')];

    const templates = <?= json_encode(
        array_map(
            static fn(array $item): array => [
                'id' => (int)$item['id'],
                'content' => (string)$item['content'],
            ],
            $templates
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));

    const getSelected = () => targets.filter(item => item.checked).slice(0, 100).map(item => ({
        name: item.dataset.name || 'Kak',
        nowa: item.value
    }));

    const getTemplate = () => templates.find(item => item.id === Number(templateSelect?.value || 0)) || null;

    const renderPreview = () => {
        const selected = getSelected();
        const template = getTemplate();

        if (!selected.length || !template) {
            preview.textContent = 'Pilih peserta untuk melihat preview pesan.';
            return;
        }

        preview.textContent = String(template.content || '')
            .replace(/\{nama\}/gi, selected[0].name)
            .replace(/\[nama\]/gi, selected[0].name);
    };

    const sync = () => {
        const selected = getSelected();
        const checked = targets.filter(item => item.checked);

        if (checked.length > 100) {
            checked.slice(100).forEach(item => { item.checked = false; });
        }

        selectedInput.value = JSON.stringify(selected);
        selectedCount.textContent = selected.length + ' dipilih';
        sendButton.disabled = selected.length < 1 || !getTemplate();
        renderPreview();
    };

    targets.forEach(item => item.addEventListener('change', sync));
    templateSelect?.addEventListener('change', () => {
        templateId.value = templateSelect.value;
        sync();
    });

    form.addEventListener('submit', event => {
        sync();
        const selected = getSelected();

        if (!selected.length || !getTemplate()) {
            event.preventDefault();
            return;
        }

        if (!window.confirm('Kirim reminder ke ' + selected.length + ' peserta yang dipilih?')) {
            event.preventDefault();
        }
    });

    sync();
})();
</script>
