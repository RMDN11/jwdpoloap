<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WA System Dashboard</title>
    <style>
        /* --- Base Reset & Monochrome Palette --- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            /* Background gelap monokrom untuk menonjolkan efek glass */
            background: linear-gradient(135deg, #121212 0%, #2b2b2b 100%);
            color: #f1f1f1;
            height: 100vh;
            overflow: hidden;
            display: flex;
        }

        /* --- Glassmorphism Utilities --- */
        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }

        /* --- Layout Structure --- */
        .dashboard-container {
            display: flex;
            width: 100%;
            height: 100%;
            padding: 15px;
            gap: 15px;
        }

        /* --- Sidebar --- */
        .sidebar {
            width: 260px;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            padding: 20px 0;
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            z-index: 10;
        }

        .sidebar-header {
            padding: 0 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 15px;
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            letter-spacing: 1px;
            color: #ffffff;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 0 15px;
            overflow-y: auto;
        }

        /* Custom Scrollbar untuk menu */
        .nav-menu::-webkit-scrollbar { width: 4px; }
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .nav-link {
            text-decoration: none;
            color: #a0a0a0;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            transform: translateX(5px);
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-left: 3px solid #ffffff;
            font-weight: 500;
        }

        /* --- Main Content Area --- */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 0; /* Mencegah overflow pada flexbox */
        }

        /* --- Top Bar --- */
        .top-bar {
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            justify-content: space-between;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            display: none; /* Disembunyikan di desktop */
            transition: transform 0.3s ease;
        }

        .menu-toggle:active {
            transform: scale(0.9);
        }

        /* --- Iframe Container --- */
        .iframe-container {
            flex: 1;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: rgba(255, 255, 255, 0.02); /* Transparansi dasar untuk iframe */
            transition: opacity 0.3s ease;
        }

        /* --- Responsive Design (Mobile Friendly) --- */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 10px;
                gap: 10px;
            }

            .sidebar {
                position: absolute;
                height: calc(100% - 20px);
                transform: translateX(-120%);
                background: rgba(30, 30, 30, 0.85); /* Lebih pekat di mobile agar terbaca */
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>

    <div class="dashboard-container">
        
        <!-- Sidebar Navigation -->
        <aside class="sidebar glass" id="sidebar">
            <div class="sidebar-header">
                <h2>WA SYSTEM</h2>
            </div>
            <nav class="nav-menu">
                <!-- Halaman Utama dijadikan default active -->
                <a href="kirimgrup.php" target="main-frame" class="nav-link active">Kirim Grup</a>
                <a href="reminder.php" target="main-frame" class="nav-link">Reminder</a>
                <a href="wa-tut.php" target="main-frame" class="nav-link">WA Tut</a>
                <a href="promosi.php" target="main-frame" class="nav-link">Promosi</a>
                <a href="kelola_reminder.php" target="main-frame" class="nav-link">Kelola Reminder</a>
                <a href="kelola_grup.php" target="main-frame" class="nav-link">Kelola Grup</a>
                <a href="manage_auto_reply.php" target="main-frame" class="nav-link">Manage Auto Reply</a>
                <a href="manage_templates.php" target="main-frame" class="nav-link">Manage Templates</a>
            </nav>
        </aside>

        <!-- Main Workspace -->
        <main class="main-content">
            
            <!-- Header / Topbar -->
            <header class="top-bar glass">
                <button class="menu-toggle" id="menu-toggle">☰</button>
                <div class="top-title" id="page-title">Kirim Grup</div>
            </header>

            <!-- Iframe Wrapper -->
            <div class="iframe-container glass">
                <iframe src="kirimgrup.php" name="main-frame" id="main-frame" onload="fadeInIframe()"></iframe>
            </div>

        </main>
    </div>

    <script>
        // DOM Elements
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menu-toggle');
        const navLinks = document.querySelectorAll('.nav-link');
        const pageTitle = document.getElementById('page-title');
        const iframe = document.getElementById('main-frame');

        // Toggle Sidebar on Mobile
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('show');
        });

        // Handle Active Class & Title Change
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                // Remove active from all
                navLinks.forEach(l => l.classList.remove('active'));
                
                // Add active to clicked
                this.classList.add('active');
                
                // Update Topbar Title
                pageTitle.textContent = this.textContent;

                // Animasi fade out ringan sebelum halaman baru dimuat
                iframe.style.opacity = '0.3';

                // Auto-close sidebar on mobile after clicking a link
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('show');
                }
            });
        });

        // Fungsi dipanggil ketika iframe selesai memuat halaman (.php)
        function fadeInIframe() {
            iframe.style.opacity = '1';
        }
    </script>
</body>
</html>