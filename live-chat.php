<?php
if (session_status() === PHP_SESSION_NONE) session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'auth_checkwa.php';
require_once 'config.php';

// Ambil Konfigurasi API dari config.php
if (!isset($apiUrl) && defined('ONESENDER_API_URL')) $apiUrl = ONESENDER_API_URL;
if (!isset($apiToken) && defined('ONESENDER_API_TOKEN')) $apiToken = ONESENDER_API_TOKEN;

// ==================================================================
// 1. BACKEND AJAX: MENGAMBIL & MENGIRIM PESAN DENGAN PERFORMA RINGAN
// ==================================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // FUNGSI BACA LOG RINGAN (Hanya membaca dari akhir file)
    function tailCustom($filepath, $lines = 300) {
        if (!file_exists($filepath)) return [];
        $data = [];
        $file = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$file) return [];
        $file = array_slice($file, -$lines);
        foreach ($file as $line) {
            $parsed = json_decode($line, true);
            if ($parsed && isset($parsed['message_text'])) { // Sesuai format webhook.php Anda
                $data[] = [
                    'timestamp' => $parsed['time'] ?? date('Y-m-d H:i:s'),
                    'sender' => preg_replace('/\D/', '', $parsed['sender_phone'] ?? $parsed['phone'] ?? ''),
                    'message' => $parsed['message_text'],
                    'nama' => $parsed['from_name'] ?? $parsed['pushName'] ?? 'Unknown'
                ];
            }
        }
        return $data;
    }

    // --- ACTION: LOAD CHATS ---
    if ($action === 'load_chats') {
        $history = [];
        
        // 1. Ambil Pesan KELUAR & MASUK dari Database (Menggunakan LIMIT agar super ringan)
        if ($conn) {
            $q = $conn->query("SELECT nowa, nama, message, created_at FROM log_wa ORDER BY id DESC LIMIT 300");
            if ($q) {
                while($r = $q->fetch_assoc()) {
                    $n = preg_replace('/\D/', '', $r['nowa']);
                    if(strpos($n,'0')===0) $n = '62'.substr($n,1);
                    if(!$n) continue;
                    
                    if(!isset($history[$n])) $history[$n] = ['nama' => $r['nama'] ?: $n, 'messages' => []];
                    
                    // Jika nama di DB lebih valid dari nomor, gunakan nama DB
                    if ($r['nama'] && $r['nama'] !== $n && $history[$n]['nama'] === $n) {
                        $history[$n]['nama'] = $r['nama'];
                    }

                    // Tentukan arah (Asumsi: pesan di log_wa dominan pesan keluar, kecuali yang dicatat webhook)
                    $dir = 'out';
                    // Di webhook.php, pesan masuk dicatat ke log_wa juga. 
                    // Kita bisa bedakan jika perlu, tapi sementara anggap dari DB.
                    
                    $history[$n]['messages'][] = [
                        'dir' => 'out', // Secara visual kita set sebagai pesan keluar untuk yang dikirim sistem
                        'text' => $r['message'], 
                        'time' => date('H:i', strtotime($r['created_at'])),
                        'raw_time' => strtotime($r['created_at'])
                    ];
                }
            }
        }

        // 2. Ambil Pesan MASUK MURNI dari webhook.log
        $inboundLogs = tailCustom(__DIR__ . '/webhook.log');
        foreach ($inboundLogs as $log) {
            $n = $log['sender'];
            if (!$n) continue;
            if(!isset($history[$n])) $history[$n] = ['nama' => $log['nama'], 'messages' => []];
            
            // Override nama jika di log ada namanya
            if ($log['nama'] !== 'Unknown') $history[$n]['nama'] = $log['nama'];

            $history[$n]['messages'][] = [
                'dir' => 'in', 
                'text' => $log['message'], 
                'time' => date('H:i', strtotime($log['timestamp'])),
                'raw_time' => strtotime($log['timestamp'])
            ];
        }

        // 3. Susun Ulang, Urutkan, & Hapus Duplikat
        $contactList = [];
        foreach ($history as $number => $data) {
            // Sort pesan berdasarkan waktu terlama ke terbaru
            usort($data['messages'], function($a, $b) { return $a['raw_time'] <=> $b['raw_time']; });
            
            // Hapus duplikasi pesan (jika tersimpan di webhook & db bersamaan)
            $uniqueMsgs = []; $msgHashes = [];
            foreach($data['messages'] as $m) {
                $hash = md5($m['text'] . $m['raw_time']);
                if(!isset($msgHashes[$hash])) {
                    $msgHashes[$hash] = true;
                    $uniqueMsgs[] = $m;
                }
            }

            $lastMsg = end($uniqueMsgs);
            $contactList[] = [
                'nowa' => $number,
                'nama' => $data['nama'],
                'last_text' => $lastMsg['text'] ?? '',
                'last_time' => $lastMsg['raw_time'] ?? 0,
                'time_display' => $lastMsg['time'] ?? '',
                'messages' => $uniqueMsgs
            ];
        }
        
        // Urutkan kontak berdasarkan waktu interaksi terakhir
        usort($contactList, function($a, $b) { return $b['last_time'] <=> $a['last_time']; });
        
        echo json_encode(['status' => 'success', 'data' => $contactList]);
        exit;
    }

    // --- ACTION: SEND MESSAGE ---
    if ($action === 'send_reply') {
        $target = preg_replace('/\D/', '', $_POST['nowa'] ?? '');
        $pesan = trim($_POST['pesan'] ?? '');
        
        if (empty($target) || empty($pesan)) { echo json_encode(['status'=>'error', 'msg'=>'Data kosong']); exit; }

        // Eksekusi API OneSender Sesuai dengan pesan.php
        $data = ["recipient_type" => "individual", "to" => $target, "type" => "text", "text" => ["body" => $pesan]];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl, 
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE), 
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            // Catat ke DB agar tersinkron dengan history
            if ($conn) {
                $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at, is_form_sent) VALUES (?, 'Live Chat Admin', ?, NOW(), 1)");
                $stmt->bind_param("ss", $target, $pesan);
                $stmt->execute();
            }
            echo json_encode(['status'=>'success', 'msg'=>'Terkirim']);
        } else {
            echo json_encode(['status'=>'error', 'msg'=>"Gagal API ($httpCode)"]);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Tab Live Chat | ReqraWA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; overflow: hidden; }
        
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
        
        .chat-bg { background-color: #efeae2; background-image: radial-gradient(#cbd5e1 1px, transparent 0); background-size: 20px 20px; }
        .bubble-in { background: #ffffff; border-radius: 0 12px 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); align-self: flex-start; max-width: 75%; }
        .bubble-out { background: #dcf8c6; border-radius: 12px 0 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); align-self: flex-end; max-width: 75%; }

        /* Multi-Tab Styles */
        .chat-tab { transition: all 0.2s; border-bottom: 2px solid transparent; }
        .chat-tab.active { background: #eff6ff; border-bottom-color: #3b82f6; color: #1e40af; }
        .chat-pane { display: none; flex-direction: column; height: 100%; }
        .chat-pane.active { display: flex; }

        @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-msg { animation: slideIn 0.3s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; }
    </style>
</head>
<body class="flex flex-col h-screen">

    <header class="h-[60px] shrink-0 bg-white border-b border-slate-200 px-5 flex items-center justify-between z-20 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="bg-[#166534] text-white w-8 h-8 flex justify-center items-center rounded-lg shadow-sm"><i class="fas fa-headset"></i></div>
            <div>
                <h2 class="text-[15px] font-bold text-slate-800 leading-tight">Live Chat & Bot Agent</h2>
                <div class="flex items-center gap-1 mt-0.5">
                    <span id="syncDot" class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    <p class="text-[10px] text-slate-500 font-medium" id="syncStatus">Connected Real-time</p>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 flex overflow-hidden relative">
        
        <aside class="w-80 flex flex-col bg-white border-r border-slate-200 z-10 shrink-0">
            <div class="p-3 border-b border-slate-100">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input type="text" id="searchInput" oninput="renderSidebar()" placeholder="Cari percakapan..." class="w-full bg-slate-50 border border-slate-200 rounded-lg pl-8 pr-3 py-2 text-xs focus:outline-none focus:border-blue-400 transition-colors">
                </div>
            </div>
            <div id="contactList" class="flex-1 overflow-y-auto custom-scroll p-1">
                </div>
        </aside>

        <section class="flex-1 flex flex-col bg-slate-50 min-w-0">
            
            <div id="tabBarContainer" class="hidden bg-white border-b border-slate-200 flex overflow-x-auto custom-scroll shrink-0 shadow-sm">
                <div id="tabBar" class="flex p-1 gap-1"></div>
            </div>

            <div id="chatPanesContainer" class="flex-1 relative overflow-hidden">
                <div id="emptyState" class="absolute inset-0 flex flex-col items-center justify-center text-slate-400">
                    <div class="w-20 h-20 bg-slate-200/50 rounded-full flex items-center justify-center mb-4 shadow-inner"><i class="fab fa-whatsapp text-4xl text-slate-400"></i></div>
                    <h3 class="font-bold text-slate-600">Mulai Percakapan</h3>
                    <p class="text-xs mt-1">Klik kontak di sebelah kiri untuk membuka ruang obrolan.</p>
                </div>
                </div>
            
        </section>
    </main>

<script>
let allChats = [];          // Data mentah dari Server
let openTabs = [];          // Array nomor WA yang sedang terbuka Tab-nya
let activeTab = null;       // Nomor WA yang tab-nya sedang dilihat

// ==========================================
// 1. SISTEM TAB & MULTI-PANE
// ==========================================

// Fungsi membuka atau pindah Tab
function openChatTab(nowa) {
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('tabBarContainer').classList.remove('hidden');

    const chatData = allChats.find(c => c.nowa === nowa);
    if(!chatData) return;

    // Jika tab belum pernah dibuka, buat elemen Pane dan Tab-nya
    if (!openTabs.includes(nowa)) {
        openTabs.push(nowa);
        
        // Buat Tab UI
        const tab = document.createElement('div');
        tab.id = `tab-${nowa}`;
        tab.className = `chat-tab group flex items-center gap-2 px-3 py-2 cursor-pointer bg-slate-50 border border-slate-100 rounded-t-lg shrink-0 hover:bg-slate-100`;
        tab.onclick = () => switchTab(nowa);
        tab.innerHTML = `
            <span class="text-xs font-bold truncate max-w-[100px] pointer-events-none" id="tab-name-${nowa}">${chatData.nama}</span>
            <button onclick="closeTab(event, '${nowa}')" class="text-slate-400 hover:text-rose-500 rounded-full w-4 h-4 flex items-center justify-center transition-colors"><i class="fas fa-times text-[10px]"></i></button>
        `;
        document.getElementById('tabBar').appendChild(tab);

        // Buat Pane (Ruang Chat) UI
        const pane = document.createElement('div');
        pane.id = `pane-${nowa}`;
        pane.className = `chat-pane absolute inset-0`;
        pane.innerHTML = `
            <div class="chat-bg flex-1 p-4 overflow-y-auto custom-scroll shadow-inner flex flex-col gap-2 relative" id="history-${nowa}"></div>
            <div class="bg-slate-100 p-3 border-t border-slate-200 shrink-0">
                <form onsubmit="sendMessage(event, '${nowa}')" class="flex gap-2 bg-white p-1 rounded-xl shadow-sm border border-slate-200">
                    <textarea id="input-${nowa}" placeholder="Ketik balasan untuk ${chatData.nama}..." class="flex-1 bg-transparent border-none resize-none p-2 text-sm focus:ring-0 custom-scroll h-10 max-h-24" oninput="this.style.height=''; this.style.height=this.scrollHeight+'px'" required onkeydown="if(event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); this.form.dispatchEvent(new Event('submit')); }"></textarea>
                    <button type="submit" id="btn-${nowa}" class="w-10 h-10 bg-[#166534] text-white rounded-lg flex items-center justify-center hover:bg-green-800 transition-colors shadow-sm shrink-0"><i class="fas fa-paper-plane text-xs"></i></button>
                </form>
            </div>
        `;
        document.getElementById('chatPanesContainer').appendChild(pane);
    }

    switchTab(nowa);
    renderChatHistory(nowa); // Gambar isi pesannya
}

// Fungsi pindah tampilan ke Tab tertentu
function switchTab(nowa) {
    activeTab = nowa;
    
    // Reset semua tab
    document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active', 'bg-blue-50'));
    document.querySelectorAll('.chat-pane').forEach(p => p.classList.remove('active'));
    
    // Aktifkan yang dipilih
    if(document.getElementById(`tab-${nowa}`)) {
        document.getElementById(`tab-${nowa}`).classList.add('active', 'bg-blue-50');
        document.getElementById(`pane-${nowa}`).classList.add('active');
        
        // Auto scroll ke bawah
        const histContainer = document.getElementById(`history-${nowa}`);
        histContainer.scrollTop = histContainer.scrollHeight;
        
        // Focus ke input
        setTimeout(() => document.getElementById(`input-${nowa}`).focus(), 100);
    }
    renderSidebar(); // Update highlight di sidebar
}

// Fungsi menutup Tab
function closeTab(e, nowa) {
    e.stopPropagation();
    openTabs = openTabs.filter(id => id !== nowa);
    document.getElementById(`tab-${nowa}`).remove();
    document.getElementById(`pane-${nowa}`).remove();
    
    if (activeTab === nowa) {
        if (openTabs.length > 0) {
            switchTab(openTabs[openTabs.length - 1]); // Pindah ke tab terakhir
        } else {
            activeTab = null;
            document.getElementById('emptyState').classList.remove('hidden');
            document.getElementById('tabBarContainer').classList.add('hidden');
        }
    }
    renderSidebar();
}

// ==========================================
// 2. DATA POLLING & RENDERING
// ==========================================

async function fetchChats() {
    const dot = document.getElementById('syncDot');
    dot.classList.add('animate-ping', 'bg-blue-500'); // Indikator menarik data
    
    try {
        let fd = new FormData(); fd.append('ajax_action', 'load_chats');
        let res = await fetch('', { method: 'POST', body: fd });
        let json = await res.json();
        
        if (json.status === 'success') {
            allChats = json.data;
            renderSidebar();
            
            // Perbarui HANYA tab yang sedang terbuka
            openTabs.forEach(nowa => {
                // Update nama tab jika berubah
                const d = allChats.find(c => c.nowa === nowa);
                if(d) {
                    document.getElementById(`tab-name-${nowa}`).innerText = d.nama;
                    renderChatHistory(nowa, true); // true = update pintar (tanpa kedip)
                }
            });
        }
    } catch (err) { console.error(err); }
    
    setTimeout(() => dot.classList.remove('animate-ping', 'bg-blue-500'), 500);
}

function renderSidebar() {
    const list = document.getElementById('contactList');
    const filter = document.getElementById('searchInput').value.toLowerCase();
    
    if (allChats.length === 0) { list.innerHTML = '<div class="p-5 text-center text-xs text-slate-400">Belum ada history percakapan.</div>'; return; }
    
    let html = '';
    allChats.forEach(c => {
        if (c.nama.toLowerCase().includes(filter) || c.nowa.includes(filter)) {
            let isActive = (c.nowa === activeTab) ? 'bg-blue-50 border-l-4 border-l-blue-500' : 'hover:bg-slate-50 border-l-4 border-l-transparent';
            let initial = c.nama.charAt(0).toUpperCase();
            let msgFmt = c.last_text.length > 35 ? c.last_text.substring(0,35) + '...' : c.last_text;
            
            html += `
            <div class="flex items-center gap-3 p-3 cursor-pointer border-b border-slate-50 transition-colors ${isActive}" onclick="openChatTab('${c.nowa}')">
                <div class="w-10 h-10 rounded-full bg-slate-200 text-slate-600 font-bold flex items-center justify-center shrink-0 text-sm shadow-inner">${initial}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-baseline mb-0.5">
                        <h4 class="font-bold text-xs text-slate-800 truncate pr-2">${c.nama}</h4>
                        <span class="text-[9px] text-slate-400 font-medium shrink-0">${c.time_display}</span>
                    </div>
                    <p class="text-[10px] text-slate-500 truncate leading-tight">${msgFmt || '<i class="fas fa-image text-slate-300"></i> Media'}</p>
                </div>
            </div>`;
        }
    });
    list.innerHTML = html;
}

// Update History tanpa kedip
function renderChatHistory(nowa, isPollingUpdate = false) {
    const container = document.getElementById(`history-${nowa}`);
    if (!container) return;
    
    const data = allChats.find(c => c.nowa === nowa);
    if (!data) return;

    let html = '';
    let lastDate = '';

    data.messages.forEach((m, i) => {
        let dateObj = new Date(m.raw_time * 1000);
        let msgDate = dateObj.toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
        
        if (msgDate !== lastDate) {
            html += `<div class="flex justify-center my-1"><span class="bg-white/80 backdrop-blur text-slate-500 text-[9px] font-bold px-2.5 py-0.5 rounded shadow-sm border border-slate-100 uppercase tracking-wider">${msgDate}</span></div>`;
            lastDate = msgDate;
        }

        let isOut = m.dir === 'out';
        let align = isOut ? 'justify-end pl-12' : 'justify-start pr-12';
        let bubble = isOut ? 'bubble-out' : 'bubble-in';
        let icon = isOut ? '<i class="fas fa-check text-blue-400 ml-1"></i>' : '';
        let text = m.text.replace(/\n/g, '<br>').replace(/\*(.*?)\*/g, '<b>$1</b>');

        html += `
        <div class="flex ${align} animate-msg" style="animation-delay: ${isPollingUpdate ? '0s' : (i * 0.02) + 's'}">
            <div class="${bubble} p-2 px-3 relative text-[13px] text-[#111b21] leading-snug border border-white/50">
                ${text}
                <div class="text-[9px] text-slate-400 text-right mt-1 font-medium flex items-center justify-end gap-1">
                    ${m.time} ${icon}
                </div>
            </div>
        </div>`;
    });
    
    // Cek apakah user sedang scroll ke atas. Jika ya, jangan paksa scroll ke bawah.
    const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;
    
    container.innerHTML = html;
    
    if (isScrolledToBottom || !isPollingUpdate) {
        container.scrollTop = container.scrollHeight;
    }
}

// ==========================================
// 3. MENGIRIM PESAN (AJAX)
// ==========================================

async function sendMessage(e, nowa) {
    e.preventDefault();
    const input = document.getElementById(`input-${nowa}`);
    const btn = document.getElementById(`btn-${nowa}`);
    const msg = input.value.trim();
    
    if (!msg) return;

    // UI Optimistic Update: Langsung gambar chat ke layar sebelum server merespon
    const hist = document.getElementById(`history-${nowa}`);
    let tempHtml = `
        <div class="flex justify-end pl-12 animate-msg">
            <div class="bubble-out p-2 px-3 relative text-[13px] text-[#111b21] leading-snug opacity-70">
                ${msg.replace(/\n/g, '<br>')}
                <div class="text-[9px] text-slate-400 text-right mt-1"><i class="far fa-clock"></i></div>
            </div>
        </div>`;
    hist.insertAdjacentHTML('beforeend', tempHtml);
    hist.scrollTop = hist.scrollHeight;

    input.value = '';
    input.style.height = '40px';
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin text-xs"></i>';
    btn.disabled = true;

    try {
        let fd = new FormData(); 
        fd.append('ajax_action', 'send_reply'); 
        fd.append('nowa', nowa); 
        fd.append('pesan', msg);
        
        let res = await fetch('', { method: 'POST', body: fd });
        let json = await res.json();
        
        if (json.status !== 'success') {
            alert("Gagal kirim: " + json.msg);
        }
        // Polling akan otomatis menimpa DOM sementara dengan data asli dari database
        fetchChats(); 
    } catch (err) { alert("Kesalahan jaringan."); }

    btn.innerHTML = '<i class="fas fa-paper-plane text-xs"></i>';
    btn.disabled = false;
    input.focus();
}

// Jalankan sistem
fetchChats();
setInterval(fetchChats, 4000); // Tarik data setiap 4 detik agar real-time

// Clean background if inside iframe
if (window.self !== window.top) { document.body.style.backgroundColor = "transparent"; }
</script>
</body>
</html>