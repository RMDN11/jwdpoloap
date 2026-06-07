<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WA System Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #121212 0%, #2b2b2b 100%);
            color: #f1f1f1;
            height: 100vh;
            overflow: hidden;
        }

        /* Glassmorphism Utilities */
        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }

        /* Sidebar Styling */
        .sidebar {
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            z-index: 50;
        }

        .nav-menu::-webkit-scrollbar { width: 4px; }
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.12);
            border-left: 3px solid #60a5fa; /* Tailwind blue-400 */
            color: #ffffff;
            font-weight: 600;
        }

        iframe { transition: opacity 0.3s ease; }

        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                transform: translateX(-120%);
                background: rgba(30, 30, 30, 0.95);
            }
            .sidebar.show { transform: translateX(0); }
        }
    </style>
</head>
<body class="flex p-3 gap-3">

    <aside class="sidebar glass w-64 rounded-2xl flex flex-col py-5 h-full absolute md:relative" id="sidebar">
        <div class="px-6 pb-4 border-b border-white/10 mb-4 flex justify-between items-center">
            <h2 class="font-bold tracking-wider text-white">WA SYSTEM</h2>
            <button class="md:hidden text-white" id="close-sidebar"><i class="fas fa-times"></i></button>
        </div>
        
        <nav class="nav-menu flex flex-col gap-1 px-3 overflow-y-auto flex-1">
            <a href="kirimgrup.php" target="main-frame" class="nav-link active flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 transition"
               data-title="Kirim Pesan Grup" data-subtitle="Atur Promosi Ke Grup WhatsApp" data-icon="fa-users" data-color="from-gray-600 to-gray-700">
                <i class="fas fa-users w-5 text-center"></i> <span class="text-sm">Kirim Grup</span>
            </a>
            
            <a href="reminder.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 transition"
               data-title="Reminder Pembayaran" data-subtitle="Atur Reminder Pembayaran" data-icon="fa-bell" data-color="from-gray-600 to-gray-700">
                <i class="fas fa-bell w-5 text-center"></i> <span class="text-sm">Reminder</span>
            </a>
            
            <a href="wa-tut.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 transition"
               data-title="Tutor WhatsApp" data-subtitle="Manajemen Tutor WA" data-icon="fa-chalkboard-teacher" data-color="from-blue-500 to-indigo-600">
                <i class="fas fa-chalkboard-teacher w-5 text-center"></i> <span class="text-sm">WA Tut</span>
            </a>
            
            <a href="promosi.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 transition"
               data-title="Promosi Broadcast" data-subtitle="Kirim Pesan Promosi Massal" data-icon="fa-bullhorn" data-color="from-green-500 to-emerald-600">
                <i class="fas fa-bullhorn w-5 text-center"></i> <span class="text-sm">Promosi</span>
            </a>
            
            <a href="kelola_grup.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 transition"
               data-title="Manajemen Grup WhatsApp" data-subtitle="Tambah dan kelola daftar grup penerima" data-icon="fa-address-book" data-color="from-purple-500 to-indigo-600">
                <i class="fas fa-address-book w-5 text-center"></i> <span class="text-sm">Kelola Grup</span>
            </a>

            <a href="manage_auto_reply.php" target="main-frame" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 transition"
               data-title="Auto Reply" data-subtitle="Setting Balasan Otomatis" data-icon="fa-reply-all" data-color="from-orange-500 to-red-600">
                <i class="fas fa-reply-all w-5 text-center"></i> <span class="text-sm">Auto Reply</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col gap-3 min-w-0">
        
        <header class="glass rounded-2xl shadow-sm px-4 sm:px-6 py-3 flex justify-between items-center z-10">
            <div class="flex items-center space-x-3">
                <button class="md:hidden text-white mr-2 text-xl" id="menu-toggle"><i class="fas fa-bars"></i></button>
                
                <div id="header-icon-box" class="bg-gradient-to-br from-gray-600 to-gray-700 p-2 sm:p-3 rounded-xl shadow-lg transition-all duration-300">
                    <i id="header-icon" class="fas fa-users text-white text-xl sm:text-2xl transition-all duration-300"></i>
                </div>
                
                <div>
                    <h1 id="page-title" class="text-lg sm:text-xl font-bold text-white m-0">Kirim Pesan Grup</h1>
                    <p id="page-subtitle" class="text-xs sm:text-sm text-gray-300 m-0">Atur Promosi Ke Grup WhatsApp</p>
                </div>
            </div>

            <div class="hidden sm:block">
                <a href="logoutwa.php" class="text-sm font-medium text-gray-300 hover:text-white px-3 py-2 rounded bg-gray-700 bg-opacity-50 flex items-center gap-2 transition">
                    <i class="fas fa-right-from-bracket"></i> Keluar
                </a>
            </div>
        </header>

        <div class="flex-1 glass rounded-2xl overflow-hidden relative">
            <iframe src="kirimgrup.php" name="main-frame" id="main-frame" class="w-full h-full border-none bg-transparent" onload="fadeInIframe()"></iframe>
        </div>

    </main>

    <script>
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menu-toggle');
        const closeSidebar = document.getElementById('close-sidebar');
        const navLinks = document.querySelectorAll('.nav-link');
        const iframe = document.getElementById('main-frame');

        // Dynamic Header Elements
        const pageTitle = document.getElementById('page-title');
        const pageSubtitle = document.getElementById('page-subtitle');
        const headerIcon = document.getElementById('header-icon');
        const headerIconBox = document.getElementById('header-icon-box');

        // Toggle Sidebar
        menuToggle.addEventListener('click', () => sidebar.classList.add('show'));
        closeSidebar.addEventListener('click', () => sidebar.classList.remove('show'));

        // Handle Menu Clicks
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                navLinks.forEach(l => l.classList.remove('active', 'text-white'));
                this.classList.add('active', 'text-white');
                
                // Update Dynamic Header
                pageTitle.textContent = this.dataset.title;
                pageSubtitle.textContent = this.dataset.subtitle;
                headerIcon.className = `fas ${this.dataset.icon} text-white text-xl sm:text-2xl transition-all duration-300`;
                headerIconBox.className = `bg-gradient-to-br ${this.dataset.color} p-2 sm:p-3 rounded-xl shadow-lg transition-all duration-300`;

                iframe.style.opacity = '0.3';
                if (window.innerWidth <= 768) sidebar.classList.remove('show');
            });
        });

        function fadeInIframe() {
            iframe.style.opacity = '1';
        }
    </script>
</body>
</html>