<?php
require_once 'config.php';

if (isset($_GET['contact_id'])) {
    $contactId = $_GET['contact_id'];
    
    // Ambil data dari log_wa
    $stmt = $conn->prepare("SELECT * FROM log_wa WHERE nowa = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("s", $contactId);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();
    
    // Ambil data dari auto_reply_logs
    $stmt = $conn->prepare("SELECT * FROM auto_reply_logs WHERE contact_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("s", $contactId);
    $stmt->execute();
    $result = $stmt->get_result();
    $autoReplies = [];
    while ($row = $result->fetch_assoc()) {
        $autoReplies[] = $row;
    }
    $stmt->close();
    
    // Ambil data dari calon_peserta
    $stmt = $conn->prepare("SELECT * FROM calon_peserta WHERE nowa = ?");
    $stmt->bind_param("s", $contactId);
    $stmt->execute();
    $result = $stmt->get_result();
    $registeredData = $result->fetch_assoc();
    $stmt->close();
    
    // Ambil data dari blocked_peserta
    $stmt = $conn->prepare("SELECT * FROM blocked_peserta WHERE nowa = ?");
    $stmt->bind_param("s", $contactId);
    $stmt->execute();
    $result = $stmt->get_result();
    $isBlocked = $result->num_rows > 0;
    $stmt->close();
    ?>
    
    <div class="space-y-6">
        <!-- Basic Info -->
        <div class="bg-gray-50 p-4 rounded-lg">
            <h4 class="font-bold text-gray-800 mb-2">Informasi Kontak</h4>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-600">Nomor:</span>
                    <span class="font-medium ml-2"><?= htmlspecialchars($contactId) ?></span>
                </div>
                <div>
                    <span class="text-gray-600">Status:</span>
                    <span class="ml-2 px-2 py-1 rounded text-xs font-semibold <?= $isBlocked ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                        <?= $isBlocked ? 'DIBLOKIR' : 'AKTIF' ?>
                    </span>
                </div>
                <?php if ($registeredData): ?>
                <div class="col-span-2">
                    <span class="text-gray-600">Terdaftar sebagai calon peserta:</span>
                    <span class="font-medium ml-2">YA</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Messages -->
        <div>
            <h4 class="font-bold text-gray-800 mb-2">Pesan Terakhir (10 Pesan)</h4>
            <div class="space-y-2 max-h-60 overflow-y-auto">
                <?php if (empty($messages)): ?>
                <p class="text-gray-500 text-sm">Tidak ada pesan</p>
                <?php else: ?>
                <?php foreach($messages as $msg): ?>
                <div class="bg-white border border-gray-200 rounded p-3">
                    <div class="flex justify-between items-start mb-1">
                        <span class="text-xs text-gray-500"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">Dikirim</span>
                    </div>
                    <p class="text-sm"><?= htmlspecialchars($msg['message']) ?></p>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Auto-Reply History -->
        <div>
            <h4 class="font-bold text-gray-800 mb-2">Riwayat Auto-Reply</h4>
            <div class="space-y-2 max-h-60 overflow-y-auto">
                <?php if (empty($autoReplies)): ?>
                <p class="text-gray-500 text-sm">Tidak ada riwayat auto-reply</p>
                <?php else: ?>
                <?php foreach($autoReplies as $reply): ?>
                <div class="bg-white border border-gray-200 rounded p-3">
                    <div class="flex justify-between items-start mb-1">
                        <span class="text-xs text-gray-500"><?= date('H:i', strtotime($reply['created_at'])) ?></span>
                        <?php
                        $statusClass = 'bg-yellow-100 text-yellow-800';
                        if ($reply['status'] === 'sent') $statusClass = 'bg-green-100 text-green-800';
                        if ($reply['status'] === 'failed') $statusClass = 'bg-red-100 text-red-800';
                        if ($reply['status'] === 'registered') $statusClass = 'bg-blue-100 text-blue-800';
                        ?>
                        <span class="text-xs <?= $statusClass ?> px-2 py-1 rounded"><?= htmlspecialchars($reply['status'] ?? 'no_rule') ?></span>
                    </div>
                    <div class="space-y-1">
                        <div class="text-sm">
                            <span class="text-gray-600">Pesan:</span> 
                            <?= htmlspecialchars(substr($reply['incoming_message'] ?? '', 0, 50)) ?>
                        </div>
                        <?php if ($reply['reply_message']): ?>
                        <div class="text-sm">
                            <span class="text-gray-600">Balasan:</span> 
                            <?= htmlspecialchars(substr($reply['reply_message'], 0, 50)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
        </div>
    </div>
    
    <?php
} else {
    echo '<div class="text-red-600">ID kontak tidak ditemukan.</div>';
}