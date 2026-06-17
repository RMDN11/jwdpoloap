<?php
if (session_status() === PHP_SESSION_NONE) session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'auth_checkwa.php';
require_once 'config.php';

if (!isset($apiUrl) && defined('ONESENDER_API_URL')) $apiUrl = ONESENDER_API_URL;
if (!isset($apiToken) && defined('ONESENDER_API_TOKEN')) $apiToken = ONESENDER_API_TOKEN;

// ==================================================================
// 1. AJAX HANDLER: MENGAMBIL & MENGIRIM PESAN (REAL-TIME)
// ==================================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // FUNGSI BACA LOG (Membaca 500 baris terakhir agar ringan)
    function tailCustom($filepath, $lines = 500) {
        if (!file_exists($filepath)) return [];
        $data = [];
        $file = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $file = array_slice($file, -$lines);
        foreach ($file as $line) {
            // Deteksi format JSON dari OneSender/Webhook
            $parsed = json_decode($line, true);
            if ($parsed && isset($parsed['message'])) {
                $data[] = $parsed;
            } else {
                // Coba Regex jika formatnya log TXT biasa: [Tanggal] [Nomor] - Pesan
                if (preg_match('/\[(.*?)\] \[?(628[0-9]+)\]? \- (.*)/', $line, $m)) {
                    $data[] = ['timestamp' => $m[1], 'sender' => $m[2], 'message' => $m[3], 'type' => 'inbound'];
                }
            }
        }
        return $data;
    }

    if ($action === 'load_chats') {
        $history = [];
        
        // 1. Ambil pesan KELUAR dari Database (log_wa)
        if ($conn) {
            $q = $conn->query("SELECT nowa, nama, message, created_at FROM log_wa ORDER BY created_at DESC LIMIT 200");
            if ($q) {
                while($r = $q->fetch_assoc()) {
                    $n = preg_replace('/\D/', '', $r['nowa']);
                    if(strpos($n,'0')===0) $n = '62'.substr($n,1);
                    if(!isset($history[$n])) $history[$n] = ['nama' => $r['nama'] ?: $n, 'messages' => []];
                    
                    $history[$n]['messages'][] = [
                        'dir' => 'out', 'text' => $r['message'], 
                        'time' => date('H:i', strtotime($r['created_at'])),
                        'raw_time' => strtotime($r['created_at'])
                    ];
                }
            }
        }

        // 2. Ambil pesan MASUK dari webhook.log & auto_reply_log.txt
        $inboundLogs = array_merge(tailCustom('webhook.log'), tailCustom('auto_reply_log.txt'));
        foreach ($inboundLogs as $log) {
            $n = preg_replace('/\D/', '', $log['sender'] ?? '');
            if (!$n) continue;
            if(!isset($history[$n])) $history[$n] = ['nama' => $n, 'messages' => []];
            
            $history[$n]['messages'][] = [
                'dir' => 'in', 'text' => $log['message'] ?? '', 
                'time' => isset($log['timestamp']) ? date('H:i', strtotime($log['timestamp'])) : date('H:i'),
                'raw_time' => isset($log['timestamp']) ? strtotime($log['timestamp']) : time()
            ];
        }

        // Urutkan & Rapikan Data
        $contactList = [];
        foreach ($history as $number => $data) {
            usort($data['messages'], function($a, $b) { return $a['raw_time'] <=> $b['raw_time']; });
            $lastMsg = end($data['messages']);
            $contactList[] = [
                'nowa' => $number,
                'nama' => $data['nama'],
                'last_text' => $lastMsg['text'] ?? '',
                'last_time' => $lastMsg['raw_time'] ?? 0,
                'time_display' => $lastMsg['time'] ?? '',
                'messages' => $data['messages']
            ];
        }
        
        // Urutkan kontak berdasarkan waktu pesan terakhir
        usort($contactList, function($a, $b) { return $b['last_time'] <=> $a['last_time']; });
        
        echo json_encode(['status' => 'success', 'data' => $contactList]);
        exit;
    }

    if ($action === 'send_reply') {
        $target = preg_replace('/\D/', '', $_POST['nowa'] ?? '');
        $pesan = trim($_POST['pesan'] ?? '');
        
        if (empty($target) || empty($pesan)) { echo json_encode(['status'=>'error', 'msg'=>'Data tidak lengkap']); exit; }

        // Logic Kirim via OneSender
        $data = ["recipient_type" => "individual", "to" => $target, "type" => "text", "text" => ["body" => $pesan]];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data), 
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            // Catat ke log_wa agar terbaca sebagai pesan keluar
            if ($conn) {
                $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at, is_form_sent) VALUES (?, 'Live Chat', ?, NOW(), 1)");
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
    <title>Live Chat & Bot Visualizer | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #334155; overflow: hidden; }
        
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
        
        .crm-input { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; transition: all 0.2s; }
        .crm-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); background: #ffffff; }
        
        /* Layout Animasi & Split Pane */
        .chat-sidebar { width: 320px; transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); flex-shrink: 0; background: #ffffff; border-right: 1px solid #e2e8f0; }
        .chat-sidebar.collapsed { width: 80px; }
        .chat-sidebar.collapsed .hide-on-collapse { display: none; }
        .chat-sidebar.collapsed .contact-item { justify-content: center; padding-left: 0; padding-right: 0; }
        .chat-sidebar.collapsed .avatar-box { margin: 0; }
        
        .contact-item { transition: background 0.2s; cursor: pointer; border-left: 3px solid transparent; }
        .contact-item:hover { background: #f1f5f9; }
        .contact-item.active { background: #eff6ff; border-left-color: #3b82f6; }
        
        /* WhatsApp Background */
        .chat-bg { background-color: #efeae2; background-image: radial-gradient(#cbd5e1 1px, transparent 0); background-size: 20px 20px; }
        .bubble-in { background: #ffffff; border-radius: 0 12px 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); align-self: flex-start; max-width: 80%; }
        .bubble-out { background: #dcf8c6; border-radius: 12px 0 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); align-self: flex-end; max-width: 80%; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.3s ease forwards; }

        #line-loader { position: absolute; top: 0; left: 0; height: 2px; background: #3b82f6; width: 0%; z-index: 50; transition: width 0.3s ease; }
    </style>
</head>
<body class="flex flex-col h-screen">

    <div id="line-loader"></div>

    <header class="h-[70px] shrink-0 bg-white border-b border-slate-200 px-6 flex items-center justify-between z-10 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="bg-indigo-600 text-white w-8 h-8 flex justify-center items-center rounded-lg shadow-sm"><i class="fas fa-robot"></i></div>
            <div>
                <h2 class="text-base font-bold text-slate-800 leading-tight">Bot Visualizer & Live Chat</h2>
                <p class="text-[10px] text-emerald-600 font-bold flex items-center gap-1"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> Sinkronisasi Real-Time Aktif</p>
            </div>
        </div>
        <div class="flex gap-2">
            <button onclick="fetchChats()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm"><i class="fas fa-sync-alt mr-1"></i> Refresh Manual</button>
        </div>
    </header>

    <main class="flex-1 flex overflow-hidden">
        
        <aside id="chatSidebar" class="chat-sidebar flex flex-col relative z-20">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <div class="relative flex-1 hide-on-collapse">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[11px]"></i>
                    <input type="text" id="searchContact" onkeyup="filterContacts()" placeholder="Cari pesan / nomor..." class="crm-input w-full pl-8 py-2 text-xs">
                </div>
                <button onclick="toggleSidebar()" class="text-slate-400 hover:text-indigo-600 w-8 h-8 flex items-center justify-center rounded-full bg-slate-50 border border-slate-200 ml-2 shadow-sm transition-transform"><i class="fas fa-chevron-left text-xs" id="collapseIcon"></i></button>
            </div>
            
            <div id="contactList" class="flex-1 overflow-y-auto custom-scroll flex flex-col py-1">
                <div class="p-8 text-center text-slate-400 text-xs"><i class="fas fa-spinner fa-spin text-2xl mb-2"></i><br>Memuat riwayat chat...</div>
            </div>
        </aside>

        <section class="flex-1 flex flex-col bg-slate-50 relative z-10">
            
            <div id="emptyState" class="flex-1 flex flex-col items-center justify-center text-slate-400">
                <div class="w-20 h-20 bg-slate-200 rounded-full flex items-center justify-center mb-4 shadow-inner"><i class="fab fa-whatsapp text-4xl text-slate-400"></i></div>
                <h3 class="font-bold text-slate-600">JWD WebChat terhubung</h3>
                <p class="text-xs mt-1">Pilih prospek di sidebar untuk melihat riwayat bot dan membalas pesan.</p>
            </div>

            <div id="activeChat" class="hidden flex-1 flex flex-col h-full animate-fade">
                <div class="h-[60px] bg-white border-b border-slate-200 px-5 flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold" id="chatHeaderInitial">U</div>
                        <div>
                            <h3 class="font-bold text-sm text-slate-800" id="chatHeaderName">User</h3>
                            <p class="text-[10px] text-slate-500 font-medium font-mono" id="chatHeaderNumber">+62 000 0000</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:text-blue-600 flex items-center justify-center transition-colors" title="Buka Detail Prospek"><i class="fas fa-user-tag text-[11px]"></i></button>
                    </div>
                </div>

                <div id="chatHistory" class="flex-1 chat-bg p-5 overflow-y-auto custom-scroll flex flex-col gap-3 shadow-inner"></div>

                <div class="bg-slate-100 p-3 border-t border-slate-200 shrink-0">
                    <form id="formReply" class="flex items-end gap-2 bg-white p-1.5 rounded-xl border border-slate-200 shadow-sm" onsubmit="sendReply(event)">
                        <input type="hidden" id="targetNowa">
                        <button type="button" class="w-10 h-10 shrink-0 rounded-lg text-slate-400 hover:text-blue-500 hover:bg-slate-50 flex items-center justify-center transition-colors"><i class="fas fa-paperclip"></i></button>
                        <textarea id="replyMsg" required placeholder="Ketik balasan untuk prospek..." class="flex-1 border-none bg-transparent resize-none p-2 text-sm focus:ring-0 custom-scroll h-10 max-h-32" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                        <button type="submit" id="btnSend" class="w-10 h-10 shrink-0 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 flex items-center justify-center transition-colors shadow-sm"><i class="fas fa-paper-plane text-[13px]"></i></button>
                    </form>
                </div>
            </div>
            
        </section>
    </main>

    <script>
        let allChats = [];
        let activeNumber = null;
        const loader = document.getElementById('line-loader');

        function startLoader() { loader.style.width = '40%'; }
        function endLoader() { loader.style.width = '100%'; setTimeout(() => { loader.style.opacity = '0'; setTimeout(() => { loader.style.width = '0%'; loader.style.opacity = '1'; }, 300); }, 300); }

        function toggleSidebar() {
            const sb = document.getElementById('chatSidebar');
            const icon = document.getElementById('collapseIcon');
            sb.classList.toggle('collapsed');
            icon.classList.toggle('fa-chevron-right', sb.classList.contains('collapsed'));
            icon.classList.toggle('fa-chevron-left', !sb.classList.contains('collapsed'));
        }

        async function fetchChats() {
            try {
                let fd = new FormData(); fd.append('ajax_action', 'load_chats');
                let res = await fetch('', { method: 'POST', body: fd });
                let json = await res.json();
                
                if (json.status === 'success') {
                    allChats = json.data;
                    renderContactList();
                    // Auto-refresh chat history jika ada room yang sedang terbuka
                    if (activeNumber) {
                        const activeData = allChats.find(c => c.nowa === activeNumber);
                        if (activeData) renderChatHistory(activeData);
                    }
                }
            } catch (err) { console.error("Polling error", err); }
        }

        function renderContactList() {
            const list = document.getElementById('contactList');
            const filter = document.getElementById('searchContact').value.toLowerCase();
            
            if (allChats.length === 0) { list.innerHTML = '<div class="p-6 text-center text-xs text-slate-400">Tidak ada log chat ditemukan.</div>'; return; }
            
            let html = '';
            allChats.forEach(c => {
                if (c.nama.toLowerCase().includes(filter) || c.nowa.includes(filter) || c.last_text.toLowerCase().includes(filter)) {
                    let initial = c.nama.charAt(0).toUpperCase();
                    let isActive = (c.nowa === activeNumber) ? 'active' : '';
                    let formatMsg = c.last_text.length > 30 ? c.last_text.substring(0,30) + '...' : c.last_text;
                    
                    html += `
                    <div class="contact-item p-3 flex items-center gap-3 ${isActive}" onclick="openChat('${c.nowa}')" title="${c.nama}">
                        <div class="avatar-box w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold shrink-0 border border-indigo-200/50">${initial}</div>
                        <div class="flex-1 min-w-0 hide-on-collapse">
                            <div class="flex justify-between items-center mb-0.5">
                                <h4 class="font-bold text-xs text-slate-800 truncate pr-2">${c.nama}</h4>
                                <span class="text-[9px] text-slate-400 font-medium shrink-0">${c.time_display}</span>
                            </div>
                            <p class="text-[10px] text-slate-500 truncate">${formatMsg || '<i class="fas fa-image"></i> Media/Terkirim'}</p>
                        </div>
                    </div>`;
                }
            });
            list.innerHTML = html;
        }

        function filterContacts() { renderContactList(); }

        function openChat(number) {
            activeNumber = number;
            const data = allChats.find(c => c.nowa === number);
            if (!data) return;

            document.getElementById('emptyState').classList.add('hidden');
            document.getElementById('activeChat').classList.remove('hidden');
            document.getElementById('chatHeaderName').innerText = data.nama;
            document.getElementById('chatHeaderNumber').innerText = "+" + data.nowa;
            document.getElementById('chatHeaderInitial').innerText = data.nama.charAt(0).toUpperCase();
            document.getElementById('targetNowa').value = data.nowa;
            
            renderContactList(); // Update style active
            renderChatHistory(data);
        }

        function renderChatHistory(data) {
            const container = document.getElementById('chatHistory');
            let html = '';
            let lastDate = '';

            data.messages.forEach(m => {
                let msgDate = new Date(m.raw_time * 1000).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'short' });
                if (msgDate !== lastDate) {
                    html += `<div class="flex justify-center my-2"><span class="bg-white/80 backdrop-blur-sm text-slate-500 text-[9px] font-bold px-3 py-1 rounded-full shadow-sm border border-slate-100 uppercase tracking-wider">${msgDate}</span></div>`;
                    lastDate = msgDate;
                }

                let isOut = m.dir === 'out';
                let bubbleClass = isOut ? 'bubble-out' : 'bubble-in';
                let flexAlign = isOut ? 'justify-end pl-10' : 'justify-start pr-10';
                let icon = isOut ? '<i class="fas fa-check-double text-indigo-400 ml-1"></i>' : '';
                let formatText = m.text.replace(/\n/g, '<br>').replace(/\*(.*?)\*/g, '<b>$1</b>');

                html += `
                <div class="flex ${flexAlign} animate-fade">
                    <div class="${bubbleClass} p-2.5 px-3.5 relative text-[13px] text-[#111b21] leading-relaxed border border-white/50">
                        ${formatText}
                        <div class="text-[9px] text-slate-400 text-right mt-1 font-medium flex items-center justify-end gap-1">
                            ${m.time} ${icon}
                        </div>
                    </div>
                </div>`;
            });
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }

        async function sendReply(e) {
            e.preventDefault();
            const nowa = document.getElementById('targetNowa').value;
            const pesanBox = document.getElementById('replyMsg');
            const btn = document.getElementById('btnSend');
            const msg = pesanBox.value;
            
            if (!msg.trim()) return;
            
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
            btn.disabled = true; startLoader();

            try {
                let fd = new FormData(); 
                fd.append('ajax_action', 'send_reply'); 
                fd.append('nowa', nowa); 
                fd.append('pesan', msg);
                
                let res = await fetch('', { method: 'POST', body: fd });
                let json = await res.json();
                
                if (json.status === 'success') {
                    pesanBox.value = '';
                    pesanBox.style.height = '40px';
                    await fetchChats(); // Refresh data
                } else {
                    alert("Gagal mengirim: " + json.msg);
                }
            } catch (err) { alert("Kesalahan Jaringan!"); }
            
            btn.innerHTML = '<i class="fas fa-paper-plane text-[13px]"></i>';
            btn.disabled = false; endLoader();
        }

        // Jalankan polling setiap 5 detik
        fetchChats();
        setInterval(fetchChats, 5000);

        // Jika halaman dipanggil di dalam iframe, hilangkan border luar agar rapi
        if (window.self !== window.top) { document.body.style.backgroundColor = "transparent"; }
    </script>
</body>
</html>