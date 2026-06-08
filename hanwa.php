<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>ReqraWA | Dashboard</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="LOGOJWD.png">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                        'display': ['Raleway', 'sans-serif'],
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1) forwards',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(12px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* clean white background */
        body {
            background: #ffffff;
            overflow: hidden;
            font-family: 'Inter', system-ui, sans-serif;
        }

        /* custom scrollbar ringan */
        .nav-menu::-webkit-scrollbar { width: 5px; }
        .nav-menu::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .nav-menu::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Style menu navigasi - tanpa border, box berwarna saat aktif */
        .nav-link {
            transition: all 0.2s ease;
            border-radius: 14px;
        }
        
        /* Aktif: background hijau lembut */
        .nav-link.active {
            background: #dcfce7 !important;
            color: #166534 !important;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
        }
        
        .nav-link.active .icon-wrapper {
            color: #059669 !important;
        }
        
        .nav-link:not(.active):hover {
            background-color: #f8fafc;
            transform: translateX(3px);
        }

        /* iframe transitions */
        .iframe-hidden {
            opacity: 0;
            transform: translateY(18px) scale(0.99);
            transition: opacity 0.35s ease-out, transform 0.4s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }
        .iframe-visible {
            opacity: 1;
            transform: translateY(0) scale(1);
            transition: opacity 0.45s ease-out, transform 0.5s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }

        /* loading overlay */
        #loading-overlay {
            backdrop-filter: blur(8px);
            background-color: rgba(255, 255, 255, 0.85);
            transition: opacity 0.35s ease, visibility 0.35s;
        }
        .loader-spin {
            animation: spinModern 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        }
        @keyframes spinModern {
            100% { transform: rotate(360deg); }
        }

        /* SIDEBAR - DESKTOP & MOBILE YANG LEBIH RAPIH */
        .sidebar {
            transition: transform 0.35s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            z-index: 60;
            background: white;
            box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.05);
        }
        
        /* Mobile: tampilan sidebar lebih rapi, border-radius, padding proporsional */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 280px;
                transform: translateX(-110%);
                border-radius: 0 28px 28px 0;
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.1);
                display: flex;
                flex-direction: column;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            /* Konten sidebar agar scroll rapi */
            .sidebar .nav-menu {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            /* Header sidebar mobile lebih kompak */
            .sidebar > div:first-child {
                padding-top: 1.25rem;
                padding-bottom: 1rem;
            }
            body.sidebar-open::after {
                content: '';
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.2);
                backdrop-filter: blur(3px);
                z-index: 45;
                pointer-events: auto;
            }
        }
        
        /* perbaikan untuk desktop */
        @media (min-width: 769px) {
            .sidebar {
                position: relative;
                transform: none !important;
            }
        }
        /* Animasi gentle untuk icon smile */
@keyframes gentleSmile {
    0%, 100% { transform: scale(1) rotate(0deg); }
    50% { transform: scale(1.1) rotate(2deg); }
}
.animate-gentle {
    animation: gentleSmile 2s ease-in-out infinite;
    display: inline-block;
}

/* Animasi wave untuk icon peace */
@keyframes waveHand {
    0% { transform: rotate(0deg); }
    25% { transform: rotate(12deg); }
    50% { transform: rotate(0deg); }
    75% { transform: rotate(8deg); }
    100% { transform: rotate(0deg); }
}
.animate-wave {
    animation: waveHand 1.8s ease-in-out infinite;
    display: inline-block;
    transform-origin: 70% 70%;
}
    </style>
</head>
<body class="flex p-4 md:p-5 gap-5 h-screen w-screen">

    <!-- Overlay mobile -->
    <div id="mobile-overlay" class="fixed inset-0 bg-black/20 backdrop-blur-sm z-45 opacity-0 invisible transition-all duration-300 pointer-events-none md:hidden"></div>

    <!-- SIDEBAR dengan logo WhatsApp style & teks hijau - lebih rapi di mobile -->
    <aside class="sidebar w-72 rounded-3xl md:rounded-2xl flex flex-col h-full" id="sidebar">
        
        <div class="px-5 pt-5 pb-4 mb-1 flex justify-between items-center border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-[#166534] flex items-center justify-center shadow-sm">
    <i class="fab fa-whatsapp text-white text-xl"></i>
</div>
<div>
    <h2 class="font-display font-extrabold tracking-tight text-lg leading-tight">
        <span class="text-gray-800">Reqra</span><span class="text-[#166534]">WA</span>
    </h2>
     <p class="text-[10px] font-medium text-gray-400 -mt-0.5">Smart Messaging</p>
</div>
            </div>
            <button class="md:hidden text-gray-400 hover:text-gray-700 text-xl bg-gray-50 w-8 h-8 rounded-full transition" id="close-sidebar"><i class="fas fa-times text-sm"></i></button>
        </div>
        
        <nav class="nav-menu flex flex-col gap-1.5 px-4 py-4 overflow-y-auto flex-1">
            <a href="kirimgrup.php" target="main-frame" class="nav-link active flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Kirim Pesan Grup" data-subtitle="Promosi & broadcast ke grup WhatsApp" data-icon="fa-users" data-iconbg="bg-green-100" data-icontext="text-green-700">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-users text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kirim Grup</span>
            </a>
            
            <a href="reminder.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Reminder Pembayaran" data-subtitle="Jadwalkan & kelola pengingat otomatis" data-icon="fa-bell" data-iconbg="bg-amber-100" data-icontext="text-amber-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-bell text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Reminder</span>
            </a>
            
            <a href="wa-tut.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Tutor WhatsApp" data-subtitle="Manajemen materi & tutorial WA" data-icon="fa-chalkboard-user" data-iconbg="bg-purple-100" data-icontext="text-purple-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-chalkboard-user text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">WA Tutor</span>
            </a>
            
            <a href="promosi.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Promosi Broadcast" data-subtitle="Kirim promosi massal via WhatsApp" data-icon="fa-bullhorn" data-iconbg="bg-green-100" data-icontext="text-green-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-bullhorn text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Promosi</span>
            </a>
            
            <a href="kelola_grup.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Manajemen Grup" data-subtitle="Tambah, edit & hapus daftar grup target" data-icon="fa-address-book" data-iconbg="bg-indigo-100" data-icontext="text-indigo-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-address-book text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kelola Grup</span>
            </a>

            <a href="manage_auto_reply.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Auto Reply" data-subtitle="Balasan otomatis cerdas" data-icon="fa-reply-all" data-iconbg="bg-rose-100" data-icontext="text-rose-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-reply-all text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Auto Reply</span>
            </a>

            <!-- TIGA HALAMAN BARU -->
            <a href="pesan.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Pesan & Broadcast" data-subtitle="Kirim pesan langsung dan broadcast" data-icon="fa-envelope" data-iconbg="bg-blue-100" data-icontext="text-blue-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-envelope text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Pesan</span>
            </a>
            
            <a href="grafik.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Grafik & Statistik" data-subtitle="Analisis data dan grafik pengiriman" data-icon="fa-chart-line" data-iconbg="bg-indigo-100" data-icontext="text-indigo-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-chart-line text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Grafik</span>
            </a>
            
            <a href="manage_templates.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200"
               data-title="Manajemen Template" data-subtitle="Kelola template pesan" data-icon="fa-file-alt" data-iconbg="bg-teal-100" data-icontext="text-teal-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-file-alt text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Template</span>
            </a>
        </nav>

        <div class="px-4 pb-5 mt-3 border-t border-gray-100 pt-3">
            <a href="logoutwa.php" target="_top" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-600 transition-all duration-200 font-medium">
                <i class="fas fa-sign-out-alt w-5 text-center text-red-400"></i> 
                <span class="text-sm font-semibold">Keluar Akun</span>
            </a>
        </div>
    </aside>

    <!-- MAIN WORKSPACE -->
    <main class="flex-1 flex flex-col gap-5 min-w-0 relative">
        
        <header class="bg-white rounded-2xl md:rounded-3xl px-6 py-4 flex justify-between items-center shadow-sm border border-gray-100">
            <div class="flex items-center space-x-4">
                <button class="md:hidden text-gray-500 hover:text-[#25D366] text-xl bg-gray-100 w-9 h-9 rounded-full flex items-center justify-center transition" id="menu-toggle"><i class="fas fa-bars"></i></button>
                
                <div id="header-icon-box" class="bg-green-100 p-3 rounded-2xl transition-all duration-300 flex items-center justify-center shadow-sm">
                    <i id="header-icon" class="fas fa-users text-green-700 text-xl transition-all duration-300"></i>
                </div>
                
                <div id="text-container" class="animate-fade-in-up">
                    <h1 id="page-title" class="font-display text-xl font-extrabold text-gray-800 tracking-tight leading-tight">Kirim Pesan Grup</h1>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#25D366]"></span>
                        <p id="page-subtitle" class="text-xs font-medium text-gray-500">Promosi & broadcast ke grup WhatsApp</p>
                    </div>
                </div>
            </div>
            
            <div class="hidden sm:flex items-center gap-3 bg-white/80 backdrop-blur-sm px-4 py-2 rounded-full shadow-sm border border-gray-100">
    <div class="w-8 h-8 rounded-full bg-[#166534] flex items-center justify-center text-white shadow-sm">
        <i class="fas fa-smile-wink text-sm animate-gentle"></i>
    </div>
    <span class="text-sm font-medium text-gray-600">
        Halo, <span class="text-[#166534] font-semibold">Han!</span>
        <i class="fas fa-hand-peace text-[#166534] ml-1 text-xs animate-wave"></i>
    </span>
</div>
        </header>

        <!-- KONTEN IFRAME -->
        <div class="flex-1 bg-white rounded-2xl md:rounded-3xl overflow-hidden shadow-sm border border-gray-100 relative">
            
            <div id="loading-overlay" class="absolute inset-0 z-20 bg-white/90 backdrop-blur-sm flex flex-col items-center justify-center gap-3">
                <div class="relative">
                    <i class="fab fa-whatsapp text-4xl text-[#25D366] loader-spin"></i>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <i class="fas fa-circle-notch text-green-300 text-sm animate-pulse"></i>
                    </div>
                </div>
                <p class="text-sm font-bold text-gray-500 tracking-wide bg-white px-4 py-1.5 rounded-full shadow-sm">Memuat konten...</p>
            </div>

            <iframe src="kirimgrup.php" name="main-frame" id="main-frame" class="w-full h-full border-none bg-white iframe-hidden" title="Dashboard ReqraWA"></iframe>
        </div>
    </main>

    <script>
        (function() {
            // DOM Elements
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menu-toggle');
            const closeSidebarBtn = document.getElementById('close-sidebar');
            const mobileOverlay = document.getElementById('mobile-overlay');
            const navLinks = document.querySelectorAll('.nav-link');
            const iframe = document.getElementById('main-frame');
            const pageTitle = document.getElementById('page-title');
            const pageSubtitle = document.getElementById('page-subtitle');
            const headerIcon = document.getElementById('header-icon');
            const headerIconBox = document.getElementById('header-icon-box');
            const loadingOverlay = document.getElementById('loading-overlay');
            const textContainer = document.getElementById('text-container');

            // Simpan menu aktif ke localStorage
            function saveActiveMenu(href) {
                if (href) localStorage.setItem('wa_dashboard_active_menu', href);
            }

            function getStoredMenu() {
                return localStorage.getItem('wa_dashboard_active_menu');
            }

            // Update tampilan header & active class
            function applyActiveState(linkElement) {
                if (!linkElement) return;
                navLinks.forEach(link => link.classList.remove('active'));
                linkElement.classList.add('active');
                
                const title = linkElement.dataset.title || "Dashboard";
                const subtitle = linkElement.dataset.subtitle || "";
                const icon = linkElement.dataset.icon || "fa-users";
                const iconBg = linkElement.dataset.iconbg || "bg-green-100";
                const iconText = linkElement.dataset.icontext || "text-green-700";
                
                pageTitle.textContent = title;
                pageSubtitle.textContent = subtitle;
                headerIcon.className = `fas ${icon} ${iconText} text-xl transition-all duration-300`;
                headerIconBox.className = `${iconBg} p-3 rounded-2xl transition-all duration-300 flex items-center justify-center shadow-sm`;
                
                if (textContainer) {
                    textContainer.classList.remove('animate-fade-in-up');
                    void textContainer.offsetWidth;
                    textContainer.classList.add('animate-fade-in-up');
                }
            }
            
            // Memuat iframe berdasarkan link
            function loadIframeByLink(linkElement) {
                if (!linkElement) return;
                const targetUrl = linkElement.getAttribute('href');
                if (!targetUrl) return;
                
                const currentSrc = iframe.src;
                let currentPath = currentSrc.substring(currentSrc.lastIndexOf('/') + 1);
                if (currentPath === targetUrl && iframe.classList.contains('iframe-visible')) {
                    applyActiveState(linkElement);
                    if (loadingOverlay.style.visibility !== 'hidden') {
                        loadingOverlay.style.opacity = '0';
                        setTimeout(() => { loadingOverlay.style.visibility = 'hidden'; }, 300);
                    }
                    return;
                }
                
                loadingOverlay.style.opacity = '1';
                loadingOverlay.style.visibility = 'visible';
                iframe.classList.remove('iframe-visible');
                iframe.classList.add('iframe-hidden');
                
                iframe.src = targetUrl;
                applyActiveState(linkElement);
                saveActiveMenu(targetUrl);
                
                iframe.onload = function() {
                    iframeLoaded();
                };
                iframe.onerror = function() {
                    setTimeout(() => {
                        loadingOverlay.style.opacity = '0';
                        setTimeout(() => { loadingOverlay.style.visibility = 'hidden'; }, 200);
                    }, 800);
                };
            }
            
            window.iframeLoaded = function() {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.visibility = 'hidden';
                }, 350);
                iframe.classList.remove('iframe-hidden');
                iframe.classList.add('iframe-visible');
            };
            
            // Event klik menu
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    loadIframeByLink(this);
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('show');
                        document.body.classList.remove('sidebar-open');
                        if (mobileOverlay) mobileOverlay.classList.remove('opacity-100', 'visible');
                    }
                });
            });
            
            // Inisialisasi saat refresh
            function initializeFromStorage() {
                const storedHref = getStoredMenu();
                let targetLink = null;
                if (storedHref) {
                    targetLink = Array.from(navLinks).find(link => link.getAttribute('href') === storedHref);
                }
                if (!targetLink) {
                    targetLink = document.querySelector('.nav-link[href="kirimgrup.php"]');
                    if (!targetLink && navLinks.length) targetLink = navLinks[0];
                }
                
                if (targetLink) {
                    const targetUrl = targetLink.getAttribute('href');
                    const currentIframeSrc = iframe.src;
                    let currentPage = currentIframeSrc.substring(currentIframeSrc.lastIndexOf('/') + 1);
                    
                    if (currentPage === targetUrl && iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                        applyActiveState(targetLink);
                        iframe.classList.remove('iframe-hidden');
                        iframe.classList.add('iframe-visible');
                        loadingOverlay.style.opacity = '0';
                        loadingOverlay.style.visibility = 'hidden';
                    } else {
                        if (currentPage !== targetUrl) {
                            loadingOverlay.style.opacity = '1';
                            loadingOverlay.style.visibility = 'visible';
                            iframe.classList.remove('iframe-visible');
                            iframe.classList.add('iframe-hidden');
                            iframe.src = targetUrl;
                            applyActiveState(targetLink);
                            saveActiveMenu(targetUrl);
                        } else {
                            applyActiveState(targetLink);
                            if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                                iframeLoaded();
                            } else {
                                loadingOverlay.style.opacity = '1';
                                loadingOverlay.style.visibility = 'visible';
                            }
                        }
                    }
                } else {
                    const firstLink = navLinks[0];
                    if (firstLink) loadIframeByLink(firstLink);
                }
            }
            
            // Sidebar mobile handler
            function toggleSidebar(show) {
                if (show) {
                    sidebar.classList.add('show');
                    document.body.classList.add('sidebar-open');
                    if (mobileOverlay) {
                        mobileOverlay.classList.remove('opacity-0', 'invisible');
                        mobileOverlay.classList.add('opacity-100', 'visible');
                    }
                } else {
                    sidebar.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                    if (mobileOverlay) {
                        mobileOverlay.classList.remove('opacity-100', 'visible');
                        mobileOverlay.classList.add('opacity-0', 'invisible');
                    }
                }
            }
            
            if (menuToggle) menuToggle.addEventListener('click', () => toggleSidebar(true));
            if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', () => toggleSidebar(false));
            if (mobileOverlay) mobileOverlay.addEventListener('click', () => toggleSidebar(false));
            
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768 && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                    if (mobileOverlay) mobileOverlay.classList.remove('opacity-100', 'visible');
                }
            });
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initializeFromStorage);
            } else {
                initializeFromStorage();
            }
            
            iframe.onload = function() {
                window.iframeLoaded();
            };
        })();
    </script>
</body>
</html>