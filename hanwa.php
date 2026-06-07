<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>WA Reqra | Dashboard Modern</title>
    
    <!-- Favicon LOGOJWD.png -->
    <link rel="icon" type="image/png" href="LOGOJWD.png">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome 6 (free) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <!-- Google Fonts: Inter + Raleway (modern & clean) -->
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
                        'spin-slow': 'spin 1.2s linear infinite',
                        'pulse-subtle': 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
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
        /* custom scrollbar & modern touches */
        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e9eef3 100%);
            overflow: hidden;
            font-family: 'Inter', system-ui, sans-serif;
        }

        /* Sidebar scroll minimal */
        .nav-menu::-webkit-scrollbar { width: 5px; }
        .nav-menu::-webkit-scrollbar-track { background: #eef2f6; border-radius: 10px; }
        .nav-menu::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .nav-menu::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Modern active link style: border-left + subtle gradient */
        .nav-link {
            position: relative;
            transition: all 0.2s ease;
            border-radius: 14px;
        }
        .nav-link.active {
            background: linear-gradient(95deg, #ffffff, #f8fafc);
            color: #0f172a;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02), inset 0 1px 0 rgba(255,255,255,0.8);
            border-left: 3px solid #3b82f6;
        }
        .nav-link.active .icon-wrapper {
            color: #3b82f6;
            filter: drop-shadow(0 1px 1px rgba(59,130,246,0.2));
        }
        .nav-link:not(.active):hover {
            background-color: #f1f5f9;
            transform: translateX(2px);
        }

        /* iframe transitions glassy */
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

        /* loading overlay modern */
        #loading-overlay {
            backdrop-filter: blur(12px);
            background-color: rgba(255, 255, 255, 0.75);
            transition: opacity 0.35s ease, visibility 0.35s;
        }
        .loader-spin {
            animation: spinModern 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        }
        @keyframes spinModern {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Sidebar mobile smooth */
        .sidebar {
            transition: transform 0.35s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            z-index: 60;
            box-shadow: 0 25px 40px -12px rgba(0, 0, 0, 0.08);
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-110%);
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                width: 280px;
                border-radius: 0 28px 28px 0;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            body::after {
                content: '';
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.2);
                backdrop-filter: blur(3px);
                z-index: 45;
                opacity: 0;
                visibility: hidden;
                transition: 0.3s;
                pointer-events: none;
            }
            body.sidebar-open::after {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }
        }

        /* card glass effect on header & content */
        .glass-card {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.5);
        }
        .iframe-wrapper {
            background: #ffffff;
            box-shadow: 0 12px 30px -12px rgba(0, 0, 0, 0.08);
        }
        .header-icon-glow {
            transition: all 0.2s;
        }
    </style>
</head>
<body class="flex p-4 md:p-5 gap-5 h-screen w-screen text-gray-700">

    <!-- Overlay untuk mobile sidebar -->
    <div id="mobile-overlay" class="fixed inset-0 bg-black/20 backdrop-blur-sm z-45 opacity-0 invisible transition-all duration-300 pointer-events-none md:hidden"></div>

    <!-- SIDEBAR MODERN -->
    <aside class="sidebar bg-white/90 backdrop-blur-sm md:backdrop-blur-none md:bg-white w-72 rounded-3xl md:rounded-2xl flex flex-col h-full md:relative md:translate-x-0 shadow-xl" id="sidebar">
        
        <div class="px-6 pt-6 pb-5 mb-1 flex justify-between items-center border-b border-gray-100/80">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                    <img src="LOGOJWD.png" alt="Logo" class="w-6 h-6 object-contain filter brightness-0 invert">
                </div>
                <div>
                    <h2 class="font-display font-extrabold tracking-tight text-gray-800 text-lg leading-tight">Reqra<span class="text-blue-600">WA</span></h2>
                    <p class="text-[11px] font-medium text-gray-400 -mt-0.5">Smart Messaging</p>
                </div>
            </div>
            <button class="md:hidden text-gray-400 hover:text-gray-700 text-xl bg-gray-50 w-8 h-8 rounded-full transition" id="close-sidebar"><i class="fas fa-times text-sm"></i></button>
        </div>
        
        <nav class="nav-menu flex flex-col gap-1.5 px-4 py-5 overflow-y-auto flex-1">
            <a href="kirimgrup.php" target="main-frame" class="nav-link active flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200 group"
               data-title="Kirim Pesan Grup" data-subtitle="Promosi & broadcast ke grup WhatsApp" data-icon="fa-users" data-iconbg="bg-blue-100" data-icontext="text-blue-600">
                <div class="icon-wrapper w-6 text-center transition"><i class="fas fa-users text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kirim Grup</span>
            </a>
            
            <a href="reminder.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200 group"
               data-title="Reminder Pembayaran" data-subtitle="Jadwalkan & kelola pengingat otomatis" data-icon="fa-bell" data-iconbg="bg-amber-100" data-icontext="text-amber-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-bell text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Reminder</span>
            </a>
            
            <a href="wa-tut.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200 group"
               data-title="Tutor WhatsApp" data-subtitle="Manajemen materi & tutorial WA" data-icon="fa-chalkboard-user" data-iconbg="bg-purple-100" data-icontext="text-purple-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-chalkboard-user text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">WA Tutor</span>
            </a>
            
            <a href="promosi.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200 group"
               data-title="Promosi Broadcast" data-subtitle="Kirim promosi massal via WhatsApp" data-icon="fa-bullhorn" data-iconbg="bg-green-100" data-icontext="text-green-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-bullhorn text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Promosi</span>
            </a>
            
            <a href="kelola_grup.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200 group"
               data-title="Manajemen Grup" data-subtitle="Tambah, edit & hapus daftar grup target" data-icon="fa-address-book" data-iconbg="bg-indigo-100" data-icontext="text-indigo-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-address-book text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Kelola Grup</span>
            </a>

            <a href="manage_auto_reply.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 transition-all duration-200 group"
               data-title="Auto Reply" data-subtitle="Balasan otomatis cerdas" data-icon="fa-reply-all" data-iconbg="bg-rose-100" data-icontext="text-rose-600">
                <div class="icon-wrapper w-6 text-center"><i class="fas fa-reply-all text-lg"></i></div>
                <span class="text-sm font-semibold tracking-wide">Auto Reply</span>
            </a>
        </nav>

        <div class="px-4 pb-6 mt-3 border-t border-gray-100 pt-4">
            <a href="logoutwa.php" target="_top" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-600 transition-all duration-200 font-medium group">
                <i class="fas fa-sign-out-alt w-5 text-center text-red-400 group-hover:text-red-600"></i> 
                <span class="text-sm font-semibold">Keluar Akun</span>
            </a>
        </div>
    </aside>

    <!-- MAIN WORKSPACE -->
    <main class="flex-1 flex flex-col gap-5 min-w-0 relative">
        
        <header class="bg-white/70 backdrop-blur-md md:backdrop-blur-none md:bg-white rounded-2xl md:rounded-3xl px-6 py-4 flex justify-between items-center shadow-sm border border-white/40 md:border-none">
            <div class="flex items-center space-x-4">
                <button class="md:hidden text-gray-500 hover:text-blue-600 text-xl bg-gray-100 w-9 h-9 rounded-full flex items-center justify-center transition" id="menu-toggle"><i class="fas fa-bars"></i></button>
                
                <!-- Icon box dengan efek gradient modern -->
                <div id="header-icon-box" class="bg-blue-100 p-3 rounded-2xl transition-all duration-300 flex items-center justify-center shadow-inner">
                    <i id="header-icon" class="fas fa-users text-blue-600 text-xl transition-all duration-300"></i>
                </div>
                
                <div id="text-container" class="animate-fade-in-up">
                    <h1 id="page-title" class="font-display text-xl font-extrabold text-gray-800 tracking-tight leading-tight">Kirim Pesan Grup</h1>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>
                        <p id="page-subtitle" class="text-xs font-medium text-gray-500">Atur Promosi Ke Grup WhatsApp</p>
                    </div>
                </div>
            </div>
            
            <div class="hidden sm:flex items-center gap-3 bg-gradient-to-r from-slate-50 to-white px-4 py-2 rounded-full shadow-sm border border-gray-100">
                <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-blue-500 to-indigo-600 flex items-center justify-center text-white shadow-sm">
                    <i class="fas fa-user-shield text-xs"></i>
                </div>
                <span class="text-sm font-semibold text-gray-700">Admin <span class="font-normal text-gray-400">|</span> <span class="text-blue-600">Panel</span></span>
            </div>
        </header>

        <!-- KONTEN IFRAME dengan gaya modern -->
        <div class="flex-1 bg-white/90 backdrop-blur-sm md:backdrop-blur-none md:bg-white rounded-2xl md:rounded-3xl overflow-hidden shadow-xl border border-gray-100/60 relative iframe-wrapper">
            
            <!-- LOADING OVERLAY premium -->
            <div id="loading-overlay" class="absolute inset-0 z-20 bg-white/80 backdrop-blur-md flex flex-col items-center justify-center gap-3">
                <div class="relative">
                    <i class="fas fa-circle-notch text-5xl text-blue-500 loader-spin"></i>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <i class="fas fa-comment-dots text-blue-300 text-sm animate-pulse"></i>
                    </div>
                </div>
                <p class="text-sm font-bold text-gray-600 tracking-wider bg-white/60 px-4 py-1.5 rounded-full shadow-sm">Memuat konten...</p>
            </div>

            <iframe src="kirimgrup.php" name="main-frame" id="main-frame" class="w-full h-full border-none bg-gray-50 iframe-hidden" title="Dashboard WhatsApp"></iframe>
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

            // Helper: menyimpan menu aktif ke localStorage
            function saveActiveMenu(href) {
                if (href) localStorage.setItem('wa_dashboard_active_menu', href);
            }

            // Helper: mengambil menu aktif dari localStorage
            function getStoredMenu() {
                return localStorage.getItem('wa_dashboard_active_menu');
            }

            // Fungsi untuk mengupdate tampilan header dan active class berdasarkan elemen link
            function applyActiveState(linkElement) {
                if (!linkElement) return;
                // Remove active class dari semua link
                navLinks.forEach(link => link.classList.remove('active'));
                linkElement.classList.add('active');
                
                // Update header dengan dataset yang modern
                const title = linkElement.dataset.title || "Dashboard";
                const subtitle = linkElement.dataset.subtitle || "";
                const icon = linkElement.dataset.icon || "fa-users";
                const iconBg = linkElement.dataset.iconbg || "bg-blue-100";
                const iconText = linkElement.dataset.icontext || "text-blue-600";
                
                pageTitle.textContent = title;
                pageSubtitle.textContent = subtitle;
                headerIcon.className = `fas ${icon} ${iconText} text-xl transition-all duration-300`;
                headerIconBox.className = `${iconBg} p-3 rounded-2xl transition-all duration-300 flex items-center justify-center shadow-inner`;
                
                // Trigger reflow untuk animasi teks
                if (textContainer) {
                    textContainer.classList.remove('animate-fade-in-up');
                    void textContainer.offsetWidth;
                    textContainer.classList.add('animate-fade-in-up');
                }
            }
            
            // Fungsi untuk load halaman iframe sesuai menu aktif + simpan state
            function loadIframeByLink(linkElement, pushToHistory = true) {
                if (!linkElement) return;
                const targetUrl = linkElement.getAttribute('href');
                if (!targetUrl) return;
                
                const currentSrc = iframe.src;
                // Bandingkan (perlu relative path)
                let currentPath = currentSrc.substring(currentSrc.lastIndexOf('/') + 1);
                if (currentPath === targetUrl && iframe.classList.contains('iframe-visible')) {
                    // Sudah sesuai, hanya pastikan active state & header
                    applyActiveState(linkElement);
                    if (loadingOverlay.style.visibility !== 'hidden') {
                        loadingOverlay.style.opacity = '0';
                        setTimeout(() => { loadingOverlay.style.visibility = 'hidden'; }, 300);
                    }
                    return;
                }
                
                // Tampilkan loading & animasi iframe
                loadingOverlay.style.opacity = '1';
                loadingOverlay.style.visibility = 'visible';
                iframe.classList.remove('iframe-visible');
                iframe.classList.add('iframe-hidden');
                
                // Ubah src iframe
                iframe.src = targetUrl;
                // Update active style & header segera (UX responsif)
                applyActiveState(linkElement);
                // Simpan ke storage
                saveActiveMenu(targetUrl);
                
                // Small trick: jika terjadi error load, tetap sembunyikan loading
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
            
            // Fungsi saat iframe selesai dimuat (dipanggil dari inline onload juga)
            window.iframeLoaded = function() {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.visibility = 'hidden';
                }, 350);
                iframe.classList.remove('iframe-hidden');
                iframe.classList.add('iframe-visible');
            };
            
            // Event listener klik menu
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    if (!href) return;
                    
                    // Update state & load
                    loadIframeByLink(this);
                    
                    // Tutup sidebar di mobile jika terbuka
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('show');
                        document.body.classList.remove('sidebar-open');
                        if (mobileOverlay) mobileOverlay.classList.remove('opacity-100', 'visible');
                    }
                });
            });
            
            // Fungsi inisialisasi saat halaman dimuat (refresh handling)
            function initializeFromStorage() {
                const storedHref = getStoredMenu();
                let targetLink = null;
                
                if (storedHref) {
                    targetLink = Array.from(navLinks).find(link => link.getAttribute('href') === storedHref);
                }
                // Jika tidak ada atau tidak valid, pakai default (kirimgrup.php)
                if (!targetLink) {
                    targetLink = document.querySelector('.nav-link[href="kirimgrup.php"]');
                    if (!targetLink && navLinks.length) targetLink = navLinks[0];
                }
                
                if (targetLink) {
                    const targetUrl = targetLink.getAttribute('href');
                    const currentIframeSrc = iframe.src;
                    let currentPage = currentIframeSrc.substring(currentIframeSrc.lastIndexOf('/') + 1);
                    
                    // Cegah double load jika sudah sesuai
                    if (currentPage === targetUrl && iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                        // sudah benar, hanya set active & header, hilangkan loading
                        applyActiveState(targetLink);
                        iframe.classList.remove('iframe-hidden');
                        iframe.classList.add('iframe-visible');
                        loadingOverlay.style.opacity = '0';
                        loadingOverlay.style.visibility = 'hidden';
                    } else {
                        // jika berbeda atau first load (masih kirimgrup.php default) tapi storage beda -> update
                        if (currentPage !== targetUrl) {
                            // Tampilkan loading sebelum ganti src
                            loadingOverlay.style.opacity = '1';
                            loadingOverlay.style.visibility = 'visible';
                            iframe.classList.remove('iframe-visible');
                            iframe.classList.add('iframe-hidden');
                            iframe.src = targetUrl;
                            applyActiveState(targetLink);
                            saveActiveMenu(targetUrl);
                            // jika iframe sudah di set, onload akan memanggil iframeLoaded
                        } else {
                            // url sama, tapi mungkin masih loading, pastikan active state
                            applyActiveState(targetLink);
                            // Jika iframe masih loading, kita tidak perlu double loading overlay biarkan onload handle
                            if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                                iframeLoaded();
                            } else {
                                // tetap tampilkan loading sampai selesai
                                loadingOverlay.style.opacity = '1';
                                loadingOverlay.style.visibility = 'visible';
                            }
                        }
                    }
                } else {
                    // fallback aman
                    const firstLink = navLinks[0];
                    if (firstLink) {
                        loadIframeByLink(firstLink);
                    }
                }
            }
            
            // Pastikan jika iframe load dari awal sudah complete (cache) dipanggil
            if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                // sedikit delay untuk memastikan overlay tertutup
                setTimeout(() => {
                    if (loadingOverlay.style.visibility !== 'hidden') {
                        iframeLoaded();
                    }
                }, 100);
            }
            
            // Sidebar mobile management + overlay
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
            
            if (menuToggle) {
                menuToggle.addEventListener('click', () => toggleSidebar(true));
            }
            if (closeSidebarBtn) {
                closeSidebarBtn.addEventListener('click', () => toggleSidebar(false));
            }
            if (mobileOverlay) {
                mobileOverlay.addEventListener('click', () => toggleSidebar(false));
            }
            
            // Resize: jika layar > md dan sidebar terbuka di mobile mode, tutup otomatis
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    if (sidebar.classList.contains('show')) {
                        sidebar.classList.remove('show');
                        document.body.classList.remove('sidebar-open');
                        if (mobileOverlay) mobileOverlay.classList.remove('opacity-100', 'visible');
                    }
                }
            });
            
            // Inisialisasi utama : jalankan setelah DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initializeFromStorage);
            } else {
                initializeFromStorage();
            }
            
            // Jika iframe sudah di set ulang oleh storage, pastikan event onload selalu berfungsi.
            iframe.onload = function() {
                window.iframeLoaded();
            };
        })();
    </script>
</body>
</html>