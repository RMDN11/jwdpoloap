<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WA Reqra Dashboard</title>
    
    <link rel="icon" type="image/png" href="LOGOJWD.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
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
            /* Latar belakang abu-abu super terang agar elemen putih menonjol tanpa border */
            background-color: #f8fafc; 
            overflow: hidden;
        }

        /* Custom scrollbar super tipis & bersih */
        .nav-menu::-webkit-scrollbar { width: 4px; }
        .nav-menu::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

        /* Menu Aktif styling */
        .nav-link.active {
            background-color: #f1f5f9; /* slate-100 */
            color: #0f172a; /* slate-900 */
            font-weight: 700;
        }
        .nav-link.active .icon-wrapper {
            color: #3b82f6; /* blue-500 */
        }

        iframe { transition: opacity 0.3s ease; }

        /* Transisi Sidebar untuk Mobile */
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

    <aside class="sidebar bg-white w-64 rounded-2xl shadow-[0_2px_20px_rgba(0,0,0,0.03)] flex flex-col py-6 h-full absolute md:relative" id="sidebar">
        
        <div class="px-6 pb-6 mb-2 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="LOGOJWD.png" alt="Logo" class="w-8 h-8 object-contain">
                <h2 class="font-extrabold tracking-wide text-gray-800 text-lg m-0">WhatsApp Reqra</h2>
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

    <main class="flex-1 flex flex-col gap-4 min-w-0">
        
        <header class="bg-white rounded-2xl shadow-[0_2px_20px_rgba(0,0,0,0.02)] px-6 py-4 flex justify-between items-center z-10">
            <div class="flex items-center space-x-4">
                <button class="md:hidden text-gray-400 hover:text-gray-800 text-xl mr-1" id="menu-toggle"><i class="fas fa-bars"></i></button>
                
                <div id="header-icon-box" class="bg-blue-100 p-3 rounded-xl transition-all duration-300 flex items-center justify-center">
                    <i id="header-icon" class="fas fa-users text-blue-600 text-xl transition-all duration-300"></i>
                </div>
                
                <div>
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

        <div class="flex-1 bg-white rounded-2xl shadow-[0_2px_20px_rgba(0,0,0,0.02)] overflow-hidden relative">
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
                // Reset semua active class
                navLinks.forEach(l => l.classList.remove('active', 'text-gray-800'));
                
                // Set class aktif pada yang diklik
                this.classList.add('active');
                
                // Update Dynamic Header Text
                pageTitle.textContent = this.dataset.title;
                pageSubtitle.textContent = this.dataset.subtitle;
                
                // Update Dynamic Icon & Colors
                headerIcon.className = `fas ${this.dataset.icon} ${this.dataset.icontext} text-xl transition-all duration-300`;
                headerIconBox.className = `${this.dataset.iconbg} p-3 rounded-xl transition-all duration-300 flex items-center justify-center`;

                // Fade animasi Iframe
                iframe.style.opacity = '0.3';
                
                // Tutup sidebar otomatis di mobile
                if (window.innerWidth <= 768) sidebar.classList.remove('show');
            });
        });

        // Dipanggil saat iframe selesai loading
        function fadeInIframe() {
            iframe.style.opacity = '1';
        }
    </script>
</body>
</html>