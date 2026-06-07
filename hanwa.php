<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WA Reqra</title>
    
    <!-- Favicon LOGOJWD.png -->
    <link rel="icon" type="image/png" href="LOGOJWD.png">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <!-- Google Fonts: Raleway -->
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Raleway', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <style>
        body {
            background-color: #f8fafc; 
            overflow: hidden;
        }

        /* Scrollbar styling */
        .nav-menu::-webkit-scrollbar { width: 4px; }
        .nav-menu::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

        .nav-link.active {
            background-color: #f1f5f9; 
            color: #0f172a; 
            font-weight: 700;
        }
        .nav-link.active .icon-wrapper {
            color: #3b82f6; 
        }

        /* --- ANIMASI KUSTOM --- */
        
        /* Animasi Transisi Iframe */
        .iframe-hidden {
            opacity: 0;
            transform: translateY(15px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .iframe-visible {
            opacity: 1;
            transform: translateY(0);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Animasi Teks Header */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-text {
            animation: fadeInUp 0.4s ease-out forwards;
        }

        /* Animasi Loading Overlay */
        #loading-overlay {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .loader-spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        /* Transisi Sidebar Mobile */
        .sidebar { transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1); z-index: 50; }
        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                transform: translateX(-120%);
                box-shadow: 4px 0 24px rgba(0,0,0,0.05);
            }
            .sidebar.show { transform: translateX(0); }
        }
    </style>
</head>
<body class="flex p-4 gap-4 h-screen w-screen text-gray-600">

    <!-- Sidebar Navigation -->
    <aside class="sidebar bg-white w-64 rounded-2xl shadow-[0_2px_20px_rgba(0,0,0,0.03)] flex flex-col py-6 h-full absolute md:relative" id="sidebar">
        
        <div class="px-6 pb-6 mb-2 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="LOGOJWD.png" alt="Logo" class="w-8 h-8 object-contain">
                <h2 class="font-extrabold tracking-wide text-gray-800 text-lg m-0">Reqra WhatsApp</h2>
            </div>
            <button class="md:hidden text-gray-400 hover:text-gray-700 text-xl" id="close-sidebar"><i class="fas fa-times"></i></button>
        </div>
        
        <nav class="nav-menu flex flex-col gap-2 px-4 overflow-y-auto flex-1">
            <a href="kirimgrup.php" target="main-frame" class="nav-link active flex items-center gap-3 px-4 py-3 rounded-xl text-gray-500 hover:bg-slate-50 hover:text-gray-800 transition-all"
               data-title="Kirim Pesan Grup" data-subtitle="Atur Promosi Ke Grup WhatsApp" data-icon="fa-users" data-iconbg="bg-blue-100" data-icontext="text-blue-600">
                <i class="fas fa-users w-5 text-center icon-wrapper"></i> <span class="text-sm font-medium">Kirim Grup</span>
            </a>
            
            <a href="reminder.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-500 hover:bg-slate-50 hover:text-gray-800 transition-all"
               data-title="Reminder Pembayaran" data-subtitle="Atur Reminder Pembayaran" data-icon="fa-bell" data-iconbg="bg-amber-100" data-icontext="text-amber-600">
                <i class="fas fa-bell w-5 text-center icon-wrapper"></i> <span class="text-sm font-medium">Reminder</span>
            </a>
            
            <a href="wa-tut.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-500 hover:bg-slate-50 hover:text-gray-800 transition-all"
               data-title="Tutor WhatsApp" data-subtitle="Manajemen Tutor WA" data-icon="fa-chalkboard-teacher" data-iconbg="bg-purple-100" data-icontext="text-purple-600">
                <i class="fas fa-chalkboard-teacher w-5 text-center icon-wrapper"></i> <span class="text-sm font-medium">WA Tut</span>
            </a>
            
            <a href="promosi.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-500 hover:bg-slate-50 hover:text-gray-800 transition-all"
               data-title="Promosi Broadcast" data-subtitle="Kirim Pesan Promosi Massal" data-icon="fa-bullhorn" data-iconbg="bg-green-100" data-icontext="text-green-600">
                <i class="fas fa-bullhorn w-5 text-center icon-wrapper"></i> <span class="text-sm font-medium">Promosi</span>
            </a>
            
            <a href="kelola_grup.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-500 hover:bg-slate-50 hover:text-gray-800 transition-all"
               data-title="Manajemen Grup" data-subtitle="Tambah dan kelola daftar grup penerima" data-icon="fa-address-book" data-iconbg="bg-indigo-100" data-icontext="text-indigo-600">
                <i class="fas fa-address-book w-5 text-center icon-wrapper"></i> <span class="text-sm font-medium">Kelola Grup</span>
            </a>

            <a href="manage_auto_reply.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-500 hover:bg-slate-50 hover:text-gray-800 transition-all"
               data-title="Auto Reply" data-subtitle="Setting Balasan Otomatis" data-icon="fa-reply-all" data-iconbg="bg-rose-100" data-icontext="text-rose-600">
                <i class="fas fa-reply-all w-5 text-center icon-wrapper"></i> <span class="text-sm font-medium">Auto Reply</span>
            </a>
        </nav>

        <div class="px-4 mt-2">
            <a href="logoutwa.php" target="_top" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-600 transition-all font-medium">
                <i class="fas fa-sign-out-alt w-5 text-center"></i> 
                <span class="text-sm">Keluar Akun</span>
            </a>
        </div>
    </aside>

    <!-- Main Workspace -->
    <main class="flex-1 flex flex-col gap-4 min-w-0 relative">
        
        <header class="bg-white rounded-2xl shadow-[0_2px_20px_rgba(0,0,0,0.02)] px-6 py-4 flex justify-between items-center z-10">
            <div class="flex items-center space-x-4">
                <button class="md:hidden text-gray-400 hover:text-gray-800 text-xl mr-1" id="menu-toggle"><i class="fas fa-bars"></i></button>
                
                <div id="header-icon-box" class="bg-blue-100 p-3 rounded-xl transition-all duration-300 flex items-center justify-center">
                    <i id="header-icon" class="fas fa-users text-blue-600 text-xl transition-all duration-300"></i>
                </div>
                
                <!-- Kontainer Teks Animasi -->
                <div id="text-container" class="animate-text">
                    <h1 id="page-title" class="text-xl font-bold text-gray-800 m-0 tracking-tight">Kirim Pesan Grup</h1>
                    <p id="page-subtitle" class="text-sm text-gray-500 m-0 font-medium">Atur Promosi Ke Grup WhatsApp</p>
                </div>
            </div>
            
            <div class="hidden sm:flex items-center gap-3 bg-slate-50 px-4 py-2 rounded-xl">
                <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-600">
                    <i class="fas fa-user text-sm"></i>
                </div>
                <span class="text-sm font-semibold text-gray-700">Admin Panel</span>
            </div>
        </header>

        <!-- KONTEN IFRAME -->
        <div class="flex-1 bg-white rounded-2xl shadow-[0_2px_20px_rgba(0,0,0,0.02)] overflow-hidden relative">
            
            <!-- LOADING OVERLAY -->
            <div id="loading-overlay" class="absolute inset-0 z-20 bg-white/70 backdrop-blur-sm flex flex-col items-center justify-center">
                <i class="fas fa-circle-notch text-4xl text-blue-500 loader-spin mb-3"></i>
                <p class="text-sm font-semibold text-gray-500 tracking-wider">MEMUAT DATA...</p>
            </div>

            <!-- IFRAME DENGAN CLASS ANIMASI -->
            <iframe src="kirimgrup.php" name="main-frame" id="main-frame" class="w-full h-full border-none bg-transparent iframe-hidden" onload="iframeLoaded()"></iframe>
        </div>

    </main>

    <script>
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menu-toggle');
        const closeSidebar = document.getElementById('close-sidebar');
        const navLinks = document.querySelectorAll('.nav-link');
        const iframe = document.getElementById('main-frame');
        
        const textContainer = document.getElementById('text-container');
        const pageTitle = document.getElementById('page-title');
        const pageSubtitle = document.getElementById('page-subtitle');
        const headerIcon = document.getElementById('header-icon');
        const headerIconBox = document.getElementById('header-icon-box');
        const loadingOverlay = document.getElementById('loading-overlay');

        // Toggle Sidebar
        menuToggle.addEventListener('click', () => sidebar.classList.add('show'));
        closeSidebar.addEventListener('click', () => sidebar.classList.remove('show'));

        // Saat menu diklik
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                // Tampilkan overlay loading & sembunyikan iframe (animasi turun)
                loadingOverlay.style.opacity = '1';
                loadingOverlay.style.visibility = 'visible';
                iframe.classList.remove('iframe-visible');
                iframe.classList.add('iframe-hidden');

                // Reset Class Aktif
                navLinks.forEach(l => l.classList.remove('active', 'text-gray-800'));
                this.classList.add('active');
                
                // Animasi Ulang Teks Header (Trigger Reflow)
                textContainer.classList.remove('animate-text');
                void textContainer.offsetWidth; // Trigger reflow agar animasi ter-reset
                textContainer.classList.add('animate-text');

                // Update Teks & Icon
                pageTitle.textContent = this.dataset.title;
                pageSubtitle.textContent = this.dataset.subtitle;
                headerIcon.className = `fas ${this.dataset.icon} ${this.dataset.icontext} text-xl transition-all duration-300`;
                headerIconBox.className = `${this.dataset.iconbg} p-3 rounded-xl transition-all duration-300 flex items-center justify-center`;

                if (window.innerWidth <= 768) sidebar.classList.remove('show');
            });
        });

        // Saat iframe SELESAI memuat (Loading Selesai)
        function iframeLoaded() {
            // Sembunyikan overlay loading
            loadingOverlay.style.opacity = '0';
            setTimeout(() => {
                loadingOverlay.style.visibility = 'hidden';
            }, 300);

            // Tampilkan halaman dengan animasi Fade-In Up
            iframe.classList.remove('iframe-hidden');
            iframe.classList.add('iframe-visible');
        }
        
        // Memicu tampilan awal saat pertama kali buka web
        window.addEventListener('DOMContentLoaded', () => {
             // Jika iframe sudah berstatus complete dari cache
             if(iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                 iframeLoaded();
             }
        });
    </script>
</body>
</html>