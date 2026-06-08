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
        
        /* Style menu navigasi - animasi icon dan hover */
        .nav-link {
            transition: all 0.25s ease;
            border-radius: 14px;
            position: relative;
            overflow: hidden;
        }
        
        /* Hover effect dengan warna sesuai icon */
        .nav-link:hover {
            transform: translateX(4px);
        }
        
        /* Animasi icon saat hover */
        .nav-link:hover .icon-wrapper i {
            animation: iconBounce 0.4s ease-in-out;
        }
        
        @keyframes iconBounce {
            0% { transform: scale(1); }
            50% { transform: scale(1.2) rotate(5deg); }
            100% { transform: scale(1); }
        }
        
        /* Aktif: background dengan warna soft sesuai icon */
        .nav-link.active {
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
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

        /* SIDEBAR */
        .sidebar {
            transition: transform 0.35s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            z-index: 60;
            background: white;
            box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.05);
        }
        
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
            .sidebar.show { transform: translateX(0); }
            .sidebar .nav-menu { padding-left: 1rem; padding-right: 1rem; }
            .sidebar > div:first-child { padding-top: 1.25rem; padding-bottom: 1rem; }
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
        
        @media (min-width: 769px) {
            .sidebar { position: relative; transform: none !important; }
        }
    </style>
</head>
<body class="flex p-4 md:p-5 gap-5 h-screen w-screen">

    <div id="mobile-overlay" class="fixed inset-0 bg-black/20 backdrop-blur-sm z-45 opacity-0 invisible transition-all duration-300 pointer-events-none md:hidden"></div>

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
            <!-- 1. Kirim Pesan Grup - Hijau -->
            <a href="kirimgrup.php" target="main-frame" class="nav-link active flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Kirim Pesan Grup" data-subtitle="Promosi & broadcast ke grup WhatsApp" data-icon="fa-users" data-bg="#dcfce7" data-text="#166534" data-iconcolor="#059669" data-hover-bg="#e8f5e9">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-users text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kirim Pesan Grup</span>
            </a>
            
            <!-- 2. Reminder Pembayaran - Amber/Kuning -->
            <a href="reminder.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Reminder Pembayaran" data-subtitle="Jadwalkan & kelola pengingat otomatis" data-icon="fa-bell" data-bg="#fef3c7" data-text="#d97706" data-iconcolor="#f59e0b" data-hover-bg="#fffbeb">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-bell text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Reminder Pembayaran</span>
            </a>

            <!-- 3. Reminder Peserta - Jam/Amber -->
            <a href="kelola_reminder.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Reminder Peserta Telat" data-subtitle="Jadwalkan & kelola pengingat otomatis" data-icon="fa-clock" data-bg="#fef3c7" data-text="#d97706" data-iconcolor="#f59e0b" data-hover-bg="#fffbeb">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-clock text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Reminder Peserta</span>
            </a>
            
            <!-- 4. Pesan Tutor - Ungu -->
            <a href="wa-tut.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Tutor WhatsApp" data-subtitle="Manajemen materi & tutorial WA" data-icon="fa-chalkboard-user" data-bg="#f3e8ff" data-text="#9333ea" data-iconcolor="#a855f7" data-hover-bg="#faf5ff">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-chalkboard-user text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Pesan Tutor</span>
            </a>

            <!-- 5. Pesan Baru - Biru -->
            <a href="pesan.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Pesan & Broadcast" data-subtitle="Kirim pesan langsung dan broadcast" data-icon="fa-envelope" data-bg="#dbeafe" data-text="#2563eb" data-iconcolor="#3b82f6" data-hover-bg="#eff6ff">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-envelope text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Pesan Baru</span>
            </a>
            
            <!-- 6. Promosi - Hijau Muda -->
            <a href="promosi.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Promosi Broadcast" data-subtitle="Kirim promosi massal via WhatsApp" data-icon="fa-bullhorn" data-bg="#dcfce7" data-text="#16a34a" data-iconcolor="#22c55e" data-hover-bg="#f0fdf4">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-bullhorn text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Promosi</span>
            </a>
            
            <!-- 7. Grafik - Indigo -->
            <a href="grafik.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Grafik & Statistik" data-subtitle="Analisis data dan grafik pengiriman" data-icon="fa-chart-line" data-bg="#e0e7ff" data-text="#4338ca" data-iconcolor="#6366f1" data-hover-bg="#eef2ff">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-chart-line text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Grafik</span>
            </a>
            
            <!-- 8. Template - Teal -->
            <a href="manage_templates.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Manajemen Template" data-subtitle="Kelola template pesan" data-icon="fa-file-alt" data-bg="#ccfbf1" data-text="#0f766e" data-iconcolor="#14b8a6" data-hover-bg="#f0fdfa">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-file-alt text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Template</span>
            </a>
            
            <!-- 9. Kelola Grup - Indigo -->
            <a href="kelola_grup.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Manajemen Grup" data-subtitle="Tambah, edit & hapus daftar grup target" data-icon="fa-address-book" data-bg="#e0e7ff" data-text="#4f46e5" data-iconcolor="#818cf8" data-hover-bg="#eef2ff">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-address-book text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kelola Grup</span>
            </a>

            <!-- 10. Auto Reply - Rose/Merah Muda -->
            <a href="manage_auto_reply.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200"
               data-title="Auto Reply" data-subtitle="Balasan otomatis cerdas" data-icon="fa-reply-all" data-bg="#ffe4e6" data-text="#e11d48" data-iconcolor="#f43f5e" data-hover-bg="#fff1f2">
                <div class="icon-wrapper w-6 text-center transition-all duration-200"><i class="fas fa-reply-all text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kelola Auto Reply</span>
            </a>
        </nav>

        <div class="px-4 pb-5 mt-3 border-t border-gray-100 pt-3">
            <a href="logoutwa.php" target="_top" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-600 transition-all duration-200 font-medium">
                <i class="fas fa-sign-out-alt w-5 text-center text-red-400"></i> 
                <span class="text-sm font-semibold">Keluar Akun</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col gap-5 min-w-0 relative">
        <header class="bg-white rounded-2xl md:rounded-3xl px-6 py-4 flex justify-between items-center shadow-sm border border-gray-100">
            <div class="flex items-center space-x-4">
                <button class="md:hidden text-gray-500 hover:text-[#166534] text-xl bg-gray-100 w-9 h-9 rounded-full flex items-center justify-center transition" id="menu-toggle"><i class="fas fa-bars"></i></button>
                
                <div id="header-icon-box" class="bg-green-100 p-3 rounded-2xl transition-all duration-300 flex items-center justify-center shadow-sm">
                    <i id="header-icon" class="fas fa-users text-green-700 text-xl transition-all duration-300"></i>
                </div>
                
                <div id="text-container" class="animate-fade-in-up">
                    <h1 id="page-title" class="font-display text-xl font-extrabold text-gray-800 tracking-tight leading-tight">Kirim Pesan Grup</h1>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span id="title-dot" class="w-1.5 h-1.5 rounded-full bg-[#166534]"></span>
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

        <div class="flex-1 bg-white rounded-2xl md:rounded-3xl overflow-hidden shadow-sm border border-gray-100 relative">
            <div id="loading-overlay" class="absolute inset-0 z-20 bg-white/90 backdrop-blur-sm flex flex-col items-center justify-center gap-3" style="visibility: hidden; opacity: 0;">
                <div class="relative">
                    <i class="fab fa-whatsapp text-4xl text-[#166534] loader-spin"></i>
                </div>
                <p class="text-sm font-bold text-gray-500 tracking-wide bg-white px-4 py-1.5 rounded-full shadow-sm">Memuat konten...</p>
            </div>
            <iframe src="kirimgrup.php" name="main-frame" id="main-frame" class="w-full h-full border-none bg-white iframe-visible" title="Dashboard ReqraWA"></iframe>
        </div>
    </main>

    <script>
        (function() {
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
            const titleDot = document.getElementById('title-dot');
            const textContainer = document.getElementById('text-container');

            let isTransitioning = false;

            function saveActiveMenu(href) {
                if (href) localStorage.setItem('wa_dashboard_active_menu', href);
            }

            function getStoredMenu() {
                return localStorage.getItem('wa_dashboard_active_menu');
            }

            function resetLinkStyle(link) {
                link.style.backgroundColor = '';
                link.style.color = '';
                const wrapper = link.querySelector('.icon-wrapper');
                if (wrapper) wrapper.style.color = '';
            }

            function applyActiveState(linkElement, skipAnimation = false) {
                if (!linkElement) return;
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    resetLinkStyle(link);
                });
                
                linkElement.classList.add('active');
                
                const bgColor = linkElement.dataset.bg || '#dcfce7';
                const textColor = linkElement.dataset.text || '#166534';
                const iconColor = linkElement.dataset.iconcolor || '#059669';
                const iconClass = linkElement.dataset.icon || 'fa-users';
                const title = linkElement.dataset.title || 'Dashboard';
                const subtitle = linkElement.dataset.subtitle || '';
                
                linkElement.style.backgroundColor = bgColor;
                linkElement.style.color = textColor;
                const iconWrapper = linkElement.querySelector('.icon-wrapper');
                if (iconWrapper) iconWrapper.style.color = iconColor;
                
                pageTitle.textContent = title;
                pageSubtitle.textContent = subtitle;
                headerIcon.className = `fas ${iconClass} ${textColor} text-xl transition-all duration-300`;
                headerIconBox.className = `${bgColor} p-3 rounded-2xl transition-all duration-300 flex items-center justify-center shadow-sm`;
                if (titleDot) titleDot.className = `w-1.5 h-1.5 rounded-full ${textColor.replace('text', 'bg')}`;
                
                if (!skipAnimation && textContainer) {
                    textContainer.style.animation = 'none';
                    textContainer.offsetHeight;
                    textContainer.style.animation = 'fadeInUp 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1) forwards';
                }
            }

            // Setup hover effect untuk setiap link (warna hover sesuai dataset)
            navLinks.forEach(link => {
                const hoverBg = link.dataset.hoverBg || '#f8fafc';
                const textColor = link.dataset.text || '#166534';
                link.addEventListener('mouseenter', function() {
                    if (!this.classList.contains('active')) {
                        this.style.backgroundColor = hoverBg;
                        this.style.color = textColor;
                    }
                });
                link.addEventListener('mouseleave', function() {
                    if (!this.classList.contains('active')) {
                        this.style.backgroundColor = '';
                        this.style.color = '';
                    }
                });
            });

            function showLoading() {
                loadingOverlay.style.visibility = 'visible';
                loadingOverlay.style.opacity = '1';
                iframe.classList.remove('iframe-visible');
                iframe.classList.add('iframe-hidden');
            }

            function hideLoading() {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.visibility = 'hidden';
                }, 300);
                iframe.classList.remove('iframe-hidden');
                iframe.classList.add('iframe-visible');
            }

            function loadIframeByLink(linkElement) {
                if (!linkElement || isTransitioning) return;
                
                const targetUrl = linkElement.getAttribute('href');
                if (!targetUrl) return;
                
                const currentSrc = iframe.src;
                let currentPath = currentSrc.substring(currentSrc.lastIndexOf('/') + 1);
                
                if (currentPath === targetUrl && iframe.classList.contains('iframe-visible')) {
                    applyActiveState(linkElement);
                    return;
                }
                
                isTransitioning = true;
                showLoading();
                applyActiveState(linkElement, true);
                saveActiveMenu(targetUrl);
                
                iframe.src = targetUrl;
                
                const onLoadHandler = function() {
                    hideLoading();
                    isTransitioning = false;
                    iframe.removeEventListener('load', onLoadHandler);
                };
                
                iframe.addEventListener('load', onLoadHandler);
                
                setTimeout(() => {
                    if (isTransitioning) {
                        hideLoading();
                        isTransitioning = false;
                    }
                }, 5000);
            }
            
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
                    const currentSrc = iframe.src;
                    let currentPath = currentSrc.substring(currentSrc.lastIndexOf('/') + 1);
                    
                    applyActiveState(targetLink, true);
                    
                    if (currentPath !== targetUrl) {
                        iframe.src = targetUrl;
                        iframe.onload = function() {
                            hideLoading();
                            iframe.onload = null;
                        };
                    } else {
                        hideLoading();
                    }
                }
            }
            
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
            
            window.addEventListener('resize', () => {
                if (window.innerWidth > 768 && sidebar.classList.contains('show')) {
                    toggleSidebar(false);
                }
            });
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initializeFromStorage);
            } else {
                initializeFromStorage();
            }
        })();
    </script>
</body>
</html>