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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>ReqraWA | Smart Dashboard</title>
    
    <link rel="icon" type="image/png" href="LOGOJWD.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Raleway:wght@600;700;800&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], display: ['Raleway', 'sans-serif'] },
                    animation: { 'fade-in-up': 'fadeInUp 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1) forwards' },
                    keyframes: { fadeInUp: { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } } }
                }
            }
        }
    </script>

    <style>
        body { background: #f8fafc; overflow: hidden; font-family: 'Inter', sans-serif; }

        /* ✅ SCROLLBAR RINGAN */
        .nav-menu::-webkit-scrollbar { width: 4px; }
        .nav-menu::-webkit-scrollbar-track { background: transparent; }
        .nav-menu::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* ✅ 1. MAGNETIC NAV LINK & HOVER */
        .nav-link, .logout-btn { 
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), background-color 0.25s, color 0.25s; 
            border-radius: 12px; position: relative; border: 1px solid transparent; 
        }
        /* Efek Magnetic: tertarik dan membesar sedikit saat di-hover */
        .nav-link:hover, .logout-btn:hover { transform: scale(1.02) translateX(4px); }
        .nav-link:hover .icon-wrapper i, .logout-btn:hover .icon-wrapper i { animation: iconBounce 0.4s ease-in-out; }
        @keyframes iconBounce { 0% { transform: scale(1); } 50% { transform: scale(1.2) rotate(5deg); } 100% { transform: scale(1); } }
        .nav-link.active { font-weight: 600; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); }

        /* ✅ 4. SMART FLOATING TOOLTIP */
        .nav-tooltip {
            position: absolute; left: 100%; top: 50%; transform: translateY(-50%) translateX(10px);
            background: #1e293b; color: white; padding: 5px 12px; border-radius: 6px;
            font-size: 12px; font-weight: 500; white-space: nowrap; pointer-events: none;
            opacity: 0; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 100; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .sidebar.collapsed .nav-link:hover .nav-tooltip,
        .sidebar.collapsed .logout-btn:hover .nav-tooltip { opacity: 1; transform: translateY(-50%) translateX(4px); }
        .sidebar:not(.collapsed) .nav-tooltip { display: none; }

        /* ✅ TRANSISI TEKS SMOOTH SAAT MINIMIZE */
        .menu-text, .section-label, .logo-text, .logout-text {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap; overflow: hidden; opacity: 1;
        }

        /* ✅ SIDEBAR DESKTOP (COLLAPSED MODE) */
        .sidebar { transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s ease; z-index: 60; background: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        
        @media (min-width: 769px) {
            .sidebar { width: 17rem; position: relative; }
            .sidebar.collapsed { width: 5rem; }
            
            /* HILANGKAN GAP SUPAYA ICON BENAR-BENAR KE TENGAH */
            .sidebar.collapsed .nav-link,
            .sidebar.collapsed .logout-btn,
            .sidebar.collapsed .header-brand { gap: 0 !important; }

            /* Teks hilang dengan transisi, tidak pakai display:none */
            .sidebar.collapsed .menu-text, 
            .sidebar.collapsed .section-label, 
            .sidebar.collapsed .logo-text,
            .sidebar.collapsed .logout-text { opacity: 0; width: 0; margin: 0; padding: 0; }
            
            .sidebar.collapsed .nav-link,
            .sidebar.collapsed .logout-btn { justify-content: center; padding: 0.75rem 0; }
            .sidebar.collapsed .header-brand { justify-content: center; }
        }

        /* SIDEBAR MOBILE */
        @media (max-width: 768px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 280px; transform: translateX(-110%); border-radius: 0 24px 24px 0; }
            .sidebar.show { transform: translateX(0); }
        }

        /* ✅ 2. IFRAME SLIDE-IN ENTRANCE & LOADER */
        #main-frame { 
            width: 100%; height: 100%; border: none; background: transparent; 
            opacity: 0; transform: translateY(20px); 
            transition: opacity 0.5s ease-out, transform 0.5s ease-out; 
        }
        #loading-overlay { backdrop-filter: blur(8px); background-color: rgba(255, 255, 255, 0.85); transition: opacity 0.3s ease, visibility 0.3s; z-index: 30; }
        .loader-spin { animation: spinModern 0.9s cubic-bezier(0.5, 0, 0.5, 1) infinite; }
        @keyframes spinModern { 100% { transform: rotate(360deg); } }

        /* ✅ 3. SUBTLE PROGRESS INDICATOR (LINE LOADER) */
        #line-loader {
            position: absolute; top: 0; left: 0; height: 3px;
            background: linear-gradient(90deg, #3b82f6, #14b8a6);
            z-index: 50; width: 0; opacity: 0;
            transition: opacity 0.3s ease;
        }
        .loading-active {
            opacity: 1 !important;
            animation: loadingLine 1.5s infinite ease-in-out;
        }
        @keyframes loadingLine {
            0% { left: -35%; width: 35%; }
            60% { left: 100%; width: 100%; }
            100% { left: 100%; width: 0%; }
        }

        /* Toggle Desktop Button */
        .btn-collapse { position: absolute; right: -12px; top: 22px; background: white; border: 1px solid #e2e8f0; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; z-index: 70; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .btn-collapse:hover { color: #166534; border-color: #166534; transform: scale(1.1); }
    </style>
</head>
<body class="flex p-3 md:p-4 gap-4 h-screen w-screen overflow-hidden">

    <div id="mobile-overlay" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-40 opacity-0 invisible transition-all duration-300 md:hidden" onclick="toggleMobileSidebar(false)"></div>

    <aside class="sidebar rounded-2xl flex flex-col h-full border border-slate-200" id="sidebar">
        
        <div class="header-brand px-5 pt-5 pb-4 mb-1 flex items-center border-b border-slate-100 shrink-0 relative">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#166534] to-[#15803d] flex items-center justify-center shadow-sm shrink-0">
                    <i class="fab fa-whatsapp text-white text-xl"></i>
                </div>
                <div class="logo-text transition-all duration-300">
                    <h2 class="font-display font-extrabold tracking-tight text-lg leading-tight"><span class="text-slate-800">Reqra</span><span class="text-[#166534]">WA</span></h2>
                    <p class="text-[10px] font-medium text-slate-400 -mt-0.5">Smart Messaging</p>
                </div>
            </div>
            
            <button class="btn-collapse hidden md:flex" id="desktop-collapse-btn"><i class="fas fa-chevron-left text-[10px]" id="collapse-icon"></i></button>
            <button class="md:hidden text-slate-400 hover:text-slate-700 ml-auto" onclick="toggleMobileSidebar(false)"><i class="fas fa-times"></i></button>
        </div>
        
        <nav class="nav-menu flex flex-col gap-1.5 px-3 py-4 overflow-y-auto flex-1">
            
            <div class="section-label text-[10px] font-bold text-slate-400 uppercase tracking-wider px-2 pb-1 pt-1">Operasional</div>
            
            <a href="pesan.php" class="nav-link active flex items-center gap-3 px-3 py-2.5" data-title="Follow-Up & Single" data-subtitle="Kirim pesan langsung ke target" data-icon="fa-envelope" data-bg="#dbeafe" data-text="#2563eb" data-iconcolor="#3b82f6" data-hover="#eff6ff">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-envelope text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Pesan Personal</span>
                <span class="nav-tooltip">Pesan Personal</span>
            </a>
            <a href="kirimgrup.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Kirim Pesan Grup" data-subtitle="Broadcast ke grup WhatsApp" data-icon="fa-users" data-bg="#dcfce7" data-text="#166534" data-iconcolor="#059669" data-hover="#e8f5e9">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-users text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Pesan Grup</span>
                <span class="nav-tooltip">Pesan Grup</span>
            </a>
            <a href="promosi.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Promosi Broadcast" data-subtitle="Kirim promosi massal" data-icon="fa-bullhorn" data-bg="#dcfce7" data-text="#16a34a" data-iconcolor="#22c55e" data-hover="#f0fdf4">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-bullhorn text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Promosi Massal</span>
                <span class="nav-tooltip">Promosi Massal</span>
            </a>
            <a href="wa-tut.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Pesan Tutor WhatsApp" data-subtitle="Manajemen materi & tutorial" data-icon="fa-chalkboard-user" data-bg="#f3e8ff" data-text="#9333ea" data-iconcolor="#a855f7" data-hover="#faf5ff">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-chalkboard-user text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Pesan Tutor</span>
                <span class="nav-tooltip">Pesan Tutor</span>
            </a>

            <div class="section-label text-[10px] font-bold text-slate-400 uppercase tracking-wider px-2 pb-1 pt-4">Sistem & Auto</div>
            
            <a href="reminder.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Reminder Pembayaran" data-subtitle="Jadwalkan pengingat tagihan" data-icon="fa-bell" data-bg="#fef3c7" data-text="#d97706" data-iconcolor="#f59e0b" data-hover="#fffbeb">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-bell text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Tagihan Pembayaran</span>
                <span class="nav-tooltip">Tagihan Pembayaran</span>
            </a>
            <a href="kelola_reminder.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Sistem Reminder Otomatis" data-subtitle="Robot pengingat peserta" data-icon="fa-clock" data-bg="#ffedd5" data-text="#ea580c" data-iconcolor="#f97316" data-hover="#fff7ed">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-clock text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Robot Reminder</span>
                <span class="nav-tooltip">Robot Reminder</span>
            </a>
            <a href="manage_auto_reply.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Auto Reply Cerdas" data-subtitle="Balasan otomatis" data-icon="fa-reply-all" data-bg="#ffe4e6" data-text="#e11d48" data-iconcolor="#f43f5e" data-hover="#fff1f2">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-reply-all text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Auto Reply</span>
                <span class="nav-tooltip">Auto Reply</span>
            </a>

            <div class="section-label text-[10px] font-bold text-slate-400 uppercase tracking-wider px-2 pb-1 pt-4">Database</div>

            <a href="manage_templates.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Manajemen Template" data-subtitle="Atur format pesan" data-icon="fa-file-alt" data-bg="#ccfbf1" data-text="#0f766e" data-iconcolor="#14b8a6" data-hover="#f0fdfa">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-file-alt text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Template Pesan</span>
                <span class="nav-tooltip">Template Pesan</span>
            </a>
            <a href="kelola_grup.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Manajemen Grup" data-subtitle="Edit daftar grup target" data-icon="fa-address-book" data-bg="#e0e7ff" data-text="#4f46e5" data-iconcolor="#818cf8" data-hover="#eef2ff">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-address-book text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Kelola Grup</span>
                <span class="nav-tooltip">Kelola Grup</span>
            </a>
            
            <a href="grafik.php" class="nav-link flex items-center gap-3 px-3 py-2.5" data-title="Dashboard Statistik" data-subtitle="Analitik performa" data-icon="fa-chart-line" data-bg="#ede9fe" data-text="#6d28d9" data-iconcolor="#8b5cf6" data-hover="#f5f3ff">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-chart-line text-lg"></i></div>
                <span class="menu-text text-[13px] font-semibold tracking-wide">Statistik Data</span>
                <span class="nav-tooltip">Statistik Data</span>
            </a>
        </nav>

        <div class="px-3 pb-4 mt-2 border-t border-slate-100 pt-3 shrink-0">
            <a href="logoutwa.php" class="logout-btn flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-500 hover:bg-rose-50 transition-colors font-medium">
                <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-sign-out-alt text-lg text-rose-500"></i></div>
                <span class="logout-text text-[13px] font-semibold tracking-wide">Keluar Akun</span>
                <span class="nav-tooltip" style="background-color: #e11d48;">Keluar Akun</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col gap-4 min-w-0 relative">
        
        <header class="bg-white rounded-2xl px-5 py-4 flex justify-between items-center shadow-sm border border-slate-200 shrink-0 z-20">
            <div class="flex items-center space-x-3">
                <button class="md:hidden text-slate-500 hover:text-[#166534] w-8 h-8 rounded bg-slate-100 flex items-center justify-center transition" onclick="toggleMobileSidebar(true)"><i class="fas fa-bars"></i></button>
                
                <div id="header-icon-box" class="bg-blue-100 text-blue-600 p-2.5 rounded-xl flex items-center justify-center shadow-sm transition-all duration-300">
                    <i id="header-icon" class="fas fa-envelope text-lg"></i>
                </div>
                
                <div id="text-container" class="animate-fade-in-up">
                    <h1 id="page-title" class="font-display text-lg md:text-xl font-extrabold text-slate-800 tracking-tight leading-tight">Follow-Up & Single</h1>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span id="title-dot" class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                        <p id="page-subtitle" class="text-[11px] md:text-xs font-medium text-slate-500">Kirim pesan langsung ke target</p>
                    </div>
                </div>
            </div>
            
            <div class="hidden sm:flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200">
                <div class="w-7 h-7 rounded-full bg-[#166534] flex items-center justify-center text-white"><i class="fas fa-user-shield text-[10px]"></i></div>
                <span class="text-xs font-semibold text-slate-600 px-1">Halo, Han!</span>
            </div>
        </header>

        <div class="flex-1 bg-white rounded-2xl overflow-hidden shadow-sm border border-slate-200 relative bg-slate-50/30">
            <div id="line-loader"></div>

            <div id="loading-overlay" class="absolute inset-0 flex flex-col items-center justify-center gap-3">
                <i class="fab fa-whatsapp text-4xl text-[#166534] loader-spin"></i>
                <p class="text-xs font-bold text-slate-500 tracking-wide bg-white/80 px-4 py-1.5 rounded-full shadow-sm">Memuat modul...</p>
            </div>
            <iframe id="main-frame" name="main-frame" src="pesan.php"></iframe>
        </div>
    </main>

    <script>
        const iframe = document.getElementById('main-frame');
        const loader = document.getElementById('loading-overlay');
        const lineLoader = document.getElementById('line-loader');
        const navLinks = document.querySelectorAll('.nav-link');
        const sidebar = document.getElementById('sidebar');
        
        function switchTab(e, link) {
            e.preventDefault();
            const targetUrl = link.getAttribute('href');
            
            if (iframe.src.includes(targetUrl)) return; 

            // Tampilkan Line Loader & Overlay
            loader.style.visibility = 'visible';
            loader.style.opacity = '1';
            lineLoader.classList.add('loading-active'); // Aktifkan progress indicator
            
            // 2. Turunkan dan hilangkan iframe (Slide Out)
            iframe.style.opacity = '0';
            iframe.style.transform = 'translateY(20px)';

            navLinks.forEach(l => {
                l.classList.remove('active');
                l.style.background = ''; l.style.color = '';
                l.querySelector('.icon-wrapper').style.color = '';
            });

            link.classList.add('active');
            link.style.backgroundColor = link.dataset.bg;
            link.style.color = link.dataset.text;
            link.querySelector('.icon-wrapper').style.color = link.dataset.iconcolor;

            document.getElementById('page-title').textContent = link.dataset.title;
            document.getElementById('page-subtitle').textContent = link.dataset.subtitle;
            document.getElementById('header-icon').className = `fas ${link.dataset.icon} text-lg transition-all`;
            document.getElementById('header-icon-box').className = `${link.dataset.bg} ${link.dataset.text} p-2.5 rounded-xl shadow-sm`;
            document.getElementById('title-dot').className = `w-1.5 h-1.5 rounded-full ${link.dataset.text.replace('text', 'bg')}`;

            iframe.src = targetUrl;
            localStorage.setItem('reqrawa_active', targetUrl);
            
            if (window.innerWidth <= 768) toggleMobileSidebar(false);
        }

        iframe.onload = () => {
            // Sembunyikan loader overlay
            loader.style.opacity = '0';
            setTimeout(() => loader.style.visibility = 'hidden', 300);
            
            // Hentikan line loader
            lineLoader.classList.remove('loading-active');

            // 2. Munculkan iframe ke atas (Slide In Entrance)
            setTimeout(() => {
                iframe.style.opacity = '1';
                iframe.style.transform = 'translateY(0)';
            }, 100);

            try {
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                const innerHeader = doc.querySelector('header');
                const innerSidebar = doc.getElementById('mainSidebar') || doc.getElementById('sidebar');
                if(innerHeader) innerHeader.style.display = 'none';
                if(innerSidebar) innerSidebar.style.display = 'none';
                doc.body.style.backgroundColor = 'transparent';
            } catch(e) { }
        };

        navLinks.forEach(link => {
            link.addEventListener('click', (e) => switchTab(e, link));
            link.addEventListener('mouseenter', () => { if(!link.classList.contains('active')) link.style.backgroundColor = link.dataset.hover; });
            link.addEventListener('mouseleave', () => { if(!link.classList.contains('active')) link.style.backgroundColor = ''; });
        });

        const savedUrl = localStorage.getItem('reqrawa_active');
        if(savedUrl) {
            const savedLink = Array.from(navLinks).find(l => l.getAttribute('href') === savedUrl);
            if(savedLink) {
                iframe.src = savedUrl; 
                savedLink.click(); 
            }
        }

        const btnCollapse = document.getElementById('desktop-collapse-btn');
        const iconCollapse = document.getElementById('collapse-icon');
        const mobileOverlay = document.getElementById('mobile-overlay');

        if (btnCollapse) {
            btnCollapse.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                if(sidebar.classList.contains('collapsed')) {
                    iconCollapse.classList.replace('fa-chevron-left', 'fa-chevron-right');
                } else {
                    iconCollapse.classList.replace('fa-chevron-right', 'fa-chevron-left');
                }
            });
        }

        function toggleMobileSidebar(show) {
            if (show) {
                sidebar.classList.add('show');
                mobileOverlay.classList.remove('invisible', 'opacity-0');
                mobileOverlay.classList.add('opacity-100');
            } else {
                sidebar.classList.remove('show');
                mobileOverlay.classList.remove('opacity-100');
                mobileOverlay.classList.add('invisible', 'opacity-0');
            }
        }
    </script>
</body>
</html>