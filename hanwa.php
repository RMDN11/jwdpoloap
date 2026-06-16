<?php
session_start();
// Proteksi Autentikasi
if (!file_exists('auth_checkwa.php')) {
    die("<div style='padding:30px; text-align:center; font-family:sans-serif;'>Sistem dihentikan: File otentikasi tidak ditemukan.</div>");
}
require_once 'auth_checkwa.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JWD Master Dashboard</title>
    <link rel="icon" href="LOGOJWD.png?v=<?= time() ?>" type="image/png">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #334155; overflow: hidden; }

        /* Custom Scrollbar untuk Sidebar */
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 4px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }

        /* ================= ANIMASI SIDEBAR ================= */
        .master-sidebar { width: 16rem; transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); flex-shrink: 0; position: relative; z-index: 40; }
        .master-sidebar.collapsed { width: 4.5rem; }
        
        /* Elemen teks hilang saat collapsed */
        .master-sidebar.collapsed .menu-text { opacity: 0; pointer-events: none; width: 0; overflow: hidden; white-space: nowrap; transition: opacity 0.2s; margin-left: 0; }
        .master-sidebar:not(.collapsed) .menu-text { opacity: 1; transition: opacity 0.3s 0.1s ease; white-space: nowrap; margin-left: 0.75rem; }
        
        /* Penyesuaian ikon dan logo saat collapsed */
        .master-sidebar.collapsed .logo-title { display: none; }
        .master-sidebar.collapsed .logo-box { margin: 0 auto; }
        .master-sidebar.collapsed .nav-item { justify-content: center; padding-left: 0; padding-right: 0; }
        .master-sidebar.collapsed .nav-item i { margin-right: 0; font-size: 1.1rem; }
        .master-sidebar.collapsed .section-label { opacity: 0; height: 0; overflow: hidden; margin: 0; padding: 0; }

        /* Style Menu Item */
        .nav-item { display: flex; align-items: center; padding: 0.75rem 1.25rem; border-radius: 0.75rem; cursor: pointer; transition: all 0.2s ease; margin-bottom: 0.25rem; color: #64748b; font-weight: 600; font-size: 0.85rem; border: 1px solid transparent; }
        .nav-item:hover { background-color: #f8fafc; color: #3b82f6; border-color: #e2e8f0; transform: translateX(2px); }
        .nav-item.active { background-color: #eff6ff; color: #2563eb; border-color: #bfdbfe; box-shadow: inset 3px 0 0 0 #3b82f6; }
        .nav-item i { width: 1.25rem; text-align: center; transition: all 0.2s; }
        
        /* Toggle Button Edge */
        .toggle-btn { position: absolute; right: -14px; top: 21px; width: 28px; height: 28px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #94a3b8; transition: all 0.2s ease; box-shadow: 0 2px 5px rgba(0,0,0,0.05); z-index: 50; }
        .toggle-btn:hover { color: #3b82f6; border-color: #bfdbfe; transform: scale(1.1); }

        /* ================= IFRAME & LOADER ================= */
        #content-frame { width: 100%; height: 100%; border: none; background: transparent; transition: opacity 0.3s ease; }
        
        #frame-loader { position: absolute; inset: 0; background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(5px); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 30; opacity: 1; visibility: visible; transition: opacity 0.4s ease, visibility 0.4s ease; }
        #frame-loader.hidden-loader { opacity: 0; visibility: hidden; pointer-events: none; }
        
        .spinner-ring { width: 45px; height: 45px; border: 4px solid #e2e8f0; border-top-color: #3b82f6; border-right-color: #60a5fa; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        
        /* Animasi masuk */
        @keyframes fadeInRight { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
        .animate-fade-right { animation: fadeInRight 0.3s ease forwards; }
    </style>
</head>
<body class="flex h-screen w-full font-sans antialiased">

    <aside id="mainSidebar" class="master-sidebar bg-white border-r border-slate-200 shadow-sm flex flex-col">
        
        <div class="h-[70px] border-b border-slate-100 flex items-center px-5 flex-shrink-0 relative bg-white/90 backdrop-blur z-20">
            <div class="logo-box bg-gradient-to-br from-blue-600 to-indigo-600 text-white w-8 h-8 rounded-lg flex items-center justify-center shadow-md shrink-0 border border-blue-400/30">
                <i class="fas fa-layer-group text-sm"></i>
            </div>
            <h1 class="logo-title ml-3 font-bold text-slate-800 tracking-tight text-[15px]">JWD CRM Center</h1>
            
            <div class="toggle-btn" onclick="toggleSidebar()" title="Kecilkan Sidebar">
                <i class="fas fa-chevron-left text-[10px]" id="toggleIcon"></i>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto py-5 px-3 custom-scroll flex flex-col gap-1 z-10">
            
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 px-3 section-label mt-1">Dashboard & CRM</div>
            
            <a onclick="switchTab('pesan.php', 'Daftar Antrean Follow-Up', this)" class="nav-item active animate-fade-right" style="animation-delay: 0s;">
                <i class="fas fa-inbox"></i><span class="menu-text">Follow-Up Pesan</span>
            </a>
            <a onclick="switchTab('grafik.php', 'Dashboard Analitik Prospek', this)" class="nav-item animate-fade-right" style="animation-delay: 0.05s;">
                <i class="fas fa-chart-pie"></i><span class="menu-text">Statistik Minat</span>
            </a>
            <a onclick="switchTab('manage_templates.php', 'Manajemen Template Pesan', this)" class="nav-item animate-fade-right" style="animation-delay: 0.1s;">
                <i class="fas fa-comment-dots"></i><span class="menu-text">Kelola Template</span>
            </a>

            <div class="my-4 border-t border-slate-100 w-full section-label"></div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 px-3 section-label">Manajemen Data</div>

            <a onclick="switchTab('kelola_grup.php', 'Manajemen Grup & Anggota', this)" class="nav-item animate-fade-right" style="animation-delay: 0.15s;">
                <i class="fas fa-users"></i><span class="menu-text">Kelola Grup</span>
            </a>
            <a onclick="switchTab('kelola_reminder.php', 'Sistem Reminder Otomatis', this)" class="nav-item animate-fade-right" style="animation-delay: 0.2s;">
                <i class="fas fa-clock"></i><span class="menu-text">Robot Reminder</span>
            </a>
            <a onclick="switchTab('search_peserta.php', 'Database Peserta', this)" class="nav-item animate-fade-right" style="animation-delay: 0.25s;">
                <i class="fas fa-search"></i><span class="menu-text">Pencarian Data</span>
            </a>
            
        </div>

        <div class="p-3 border-t border-slate-100 flex-shrink-0 bg-slate-50/50">
            <a href="logoutwa.php" class="nav-item hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 text-slate-500 m-0" onsubmit="return confirm('Anda yakin ingin keluar?')">
                <i class="fas fa-sign-out-alt"></i><span class="menu-text">Keluar Sistem</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full min-w-0 relative bg-slate-50">
        
        <header class="h-[70px] bg-white border-b border-slate-200 px-6 flex items-center justify-between z-20 shrink-0 shadow-sm">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-slate-400 hover:text-blue-600 w-8 h-8 flex justify-center items-center rounded bg-slate-50 border border-slate-200">
                    <i class="fas fa-bars"></i>
                </button>
                <h2 id="topbarTitle" class="text-sm md:text-base font-bold text-slate-800 tracking-tight transition-all duration-300">Daftar Antrean Follow-Up</h2>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="hidden sm:flex items-center gap-2 bg-emerald-50 px-3 py-1.5 rounded-full border border-emerald-100">
                    <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                    <span class="text-[10px] font-bold text-emerald-700 uppercase tracking-wide">Server Online</span>
                </div>
                <div class="w-8 h-8 rounded-full bg-slate-200 border border-slate-300 text-slate-600 flex items-center justify-center text-xs shadow-sm hover:shadow-md cursor-pointer transition-shadow" title="Profil Admin">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
        </header>

        <div class="flex-1 relative w-full h-full overflow-hidden bg-slate-50/50">
            
            <div id="frame-loader">
                <div class="spinner-ring"></div>
                <p class="mt-4 text-xs font-bold text-blue-600 tracking-wider">Mempersiapkan Modul...</p>
            </div>
            
            <iframe id="content-frame" src="pesan.php" onload="onIframeLoaded()"></iframe>
            
        </div>
    </main>

    <script>
        const sidebar     = document.getElementById('mainSidebar');
        const toggleIcon  = document.getElementById('toggleIcon');
        const iframe      = document.getElementById('content-frame');
        const loader      = document.getElementById('frame-loader');
        const titleLabel  = document.getElementById('topbarTitle');
        const navItems    = document.querySelectorAll('.nav-item[onclick]');

        /**
         * FUNGSI 1: Toggle Sidebar (Kecil/Besar)
         */
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.classList.replace('fa-chevron-left', 'fa-bars');
                toggleIcon.style.transform = "rotate(180deg)";
            } else {
                toggleIcon.classList.replace('fa-bars', 'fa-chevron-left');
                toggleIcon.style.transform = "rotate(0deg)";
            }
        }

        /**
         * FUNGSI 2: Pindah Tab/Halaman Iframe dengan Loop Efisien
         */
        function switchTab(targetUrl, targetTitle, clickedElement) {
            // Cegah reload jika URL yang dituju sama persis dengan yang sedang aktif
            const currentSrc = iframe.src.split('/').pop(); // ambil nama filenya saja
            if (currentSrc === targetUrl || iframe.src.includes(targetUrl)) {
                return; 
            }

            // 1. Tampilkan Loader & Samarkan Iframe
            loader.classList.remove('hidden-loader');
            iframe.style.opacity = '0.3';

            // 2. Loop Efisien untuk mereset class 'active'
            navItems.forEach(el => el.classList.remove('active'));
            clickedElement.classList.add('active');

            // 3. Update Title dengan efek transisi kecil
            titleLabel.style.opacity = '0';
            setTimeout(() => {
                titleLabel.innerText = targetTitle;
                titleLabel.style.opacity = '1';
            }, 200);

            // 4. Ubah Source Iframe (Akan memicu onIframeLoaded saat selesai)
            iframe.src = targetUrl;
        }

        /**
         * FUNGSI 3: Dijalankan otomatis saat iframe selesai dimuat
         */
        function onIframeLoaded() {
            // Sembunyikan loader dan kembalikan opacity iframe
            loader.classList.add('hidden-loader');
            iframe.style.opacity = '1';

            // Opsional: Mencoba inject CSS agar sidebar ganda di pesan.php disembunyikan
            try {
                const innerDoc = iframe.contentDocument || iframe.contentWindow.document;
                
                // Jika halaman di dalam punya sidebar sendiri (seperti yang ada di pesan.php), sembunyikan!
                const innerSidebar = innerDoc.getElementById('mainSidebar');
                if(innerSidebar) { innerSidebar.style.display = 'none'; }
                
                // Sembunyikan header bawaan iframe agar tidak dobel
                const innerHeader = innerDoc.querySelector('header');
                if(innerHeader) { innerHeader.style.display = 'none'; }

                // Pastikan background iframe transparan/bersih
                innerDoc.body.style.backgroundColor = "transparent";
            } catch (e) {
                // Cross-Origin batasan, aman untuk diabaikan.
                console.log("Iframe loaded from external origin or safety blocks modification.");
            }
        }
    </script>
</body>
</html>