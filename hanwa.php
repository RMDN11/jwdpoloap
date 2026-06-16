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
    <script src="https://cdn.tailwindcss.com">
    </script>
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
        /* === GLOBAL === */
        body {
            background: #f8fafc;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
        }

        /* SCROLLBAR RINGAN */
        .nav-menu::-webkit-scrollbar {
            width: 4px;
        }
        .nav-menu::-webkit-scrollbar-track {
            background: transparent;
        }
        .nav-menu::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        /* ============================================================
                   MAGNETIC BUTTON EFFECT (tetap)
                   ============================================================ */
        .nav-link {
            transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275),
                background 0.2s ease,
                color 0.2s ease,
                box-shadow 0.25s ease,
                padding 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                gap 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            border: 1px solid transparent;
            will-change: transform;
            transform-origin: center center;
        }

        .nav-link.magnetic-hover {
            transform: scale(1.04) !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08), 0 2px 6px rgba(0, 0, 0, 0.04);
            z-index: 5;
        }

        .nav-link .icon-wrapper i {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .nav-link.magnetic-hover .icon-wrapper i {
            transform: scale(1.15) rotate(4deg);
        }

        /* ============================================================
                   SIDEBAR – TRANSISI SUPER HALUS
                   ============================================================ */
        .sidebar {
            transition: width 0.4s cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.3s ease;
            z-index: 60;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            /* mencegah konten meluber saat transisi */
        }

        /* Semua elemen teks di sidebar dapat bertransisi */
        .menu-text,
        .section-label,
        .logo-text,
        .logout-text,
        .nav-link .menu-text,
        .header-brand .logo-text {
            transition: opacity 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                width 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                margin 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                padding 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                display 0.35s ease;
            white-space: nowrap;
            overflow: hidden;
        }

        /* State collapsed – sembunyikan teks dengan efek smooth */
        @media (min-width: 769px) {
            .sidebar {
                width: 17rem;
                position: relative;
            }
            .sidebar.collapsed {
                width: 5rem;
            }

            .sidebar.collapsed .menu-text,
            .sidebar.collapsed .section-label,
            .sidebar.collapsed .logo-text,
            .sidebar.collapsed .logout-text {
                opacity: 0;
                width: 0;
                margin: 0;
                padding: 0;
                display: none;
                /* tetap pakai display:none agar tidak memakan ruang, tapi transisi tetap halus karena opacity & width */;
            }

            .sidebar.collapsed .nav-link {
                justify-content: center;
                padding: 0.75rem 0;
                gap: 0;
            }

            .sidebar.collapsed .header-brand {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }

            .sidebar.collapsed .logout-btn {
                justify-content: center;
                padding: 0.75rem 0;
                gap: 0;
            }

            /* Tooltip untuk collapsed mode */
            .nav-link .nav-tooltip {
                display: none;
            }
            .sidebar.collapsed .nav-link .nav-tooltip {
                display: block;
                position: fixed;
                background: #0f172a;
                color: #f1f5f9;
                padding: 6px 14px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 500;
                letter-spacing: 0.01em;
                white-space: nowrap;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.20);
                pointer-events: none;
                opacity: 0;
                transform: translateX(8px) scale(0.96);
                transition: opacity 0.2s cubic-bezier(0.22, 1, 0.36, 1),
                    transform 0.2s cubic-bezier(0.22, 1, 0.36, 1),
                    visibility 0.2s;
                visibility: hidden;
                z-index: 999;
                border: 1px solid rgba(255, 255, 255, 0.08);
            }
            .sidebar.collapsed .nav-link .nav-tooltip::before {
                content: '';
                position: absolute;
                left: -6px;
                top: 50%;
                transform: translateY(-50%);
                border: 6px solid transparent;
                border-right-color: #0f172a;
            }
            .sidebar.collapsed .nav-link .nav-tooltip.show {
                opacity: 1;
                transform: translateX(8px) scale(1);
                visibility: visible;
            }
            .nav-link .nav-tooltip .tip-sub {
                font-weight: 400;
                opacity: 0.6;
                font-size: 10px;
                display: block;
                margin-top: 1px;
            }
        }

        /* MOBILE SIDEBAR */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 280px;
                transform: translateX(-110%);
                border-radius: 0 24px 24px 0;
                transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .nav-link .nav-tooltip {
                display: none !important;
            }
        }

        /* Toggle Desktop Button */
        .btn-collapse {
            position: absolute;
            right: -12px;
            top: 22px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            z-index: 70;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .btn-collapse:hover {
            color: #166534;
            border-color: #166534;
            transform: scale(1.1);
        }

        /* ============================================================
                   SLIDE-IN CONTENT (iframe entrance)
                   ============================================================ */
        #main-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: transparent;
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.55s cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.55s cubic-bezier(0.22, 1, 0.36, 1);
        }
        #main-frame.loaded {
            opacity: 1;
            transform: translateY(0);
        }

        /* ============================================================
                   PROGRESS LINE LOADER
                   ============================================================ */
        #progress-line {
            position: absolute;
            top: 0;
            left: 0;
            height: 2.5px;
            width: 0%;
            background: linear-gradient(90deg, #166534, #22c55e, #166534);
            background-size: 200% 100%;
            z-index: 35;
            border-radius: 0 2px 2px 0;
            box-shadow: 0 0 12px rgba(22, 101, 52, 0.35);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            pointer-events: none;
        }
        #progress-line.active {
            opacity: 1;
            animation: shimmerProgress 1.2s ease-in-out infinite;
        }
        @keyframes shimmerProgress {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }
        #progress-line.done {
            width: 100% !important;
            opacity: 0;
            transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1),
                opacity 0.4s ease 0.2s;
        }

        /* ============================================================
                   LOADER OVERLAY (glassmorphism)
                   ============================================================ */
        #loading-overlay {
            backdrop-filter: blur(8px);
            background-color: rgba(255, 255, 255, 0.82);
            transition: opacity 0.4s ease, visibility 0.4s ease;
            z-index: 30;
        }
        .loader-spin {
            animation: spinModern 0.9s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        }
        @keyframes spinModern {
            100% {
                transform: rotate(360deg);
            }
        }

        /* HEADER ICON TRANSISI */
        #header-icon-box {
            transition: background 0.3s ease, color 0.3s ease, transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        #header-icon-box i {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* ============================================================
                   MAGNETIC GLOW
                   ============================================================ */
        .nav-link .magnetic-glow {
            position: absolute;
            inset: 0;
            border-radius: 12px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease;
            background: radial-gradient(circle at var(--mx, 50%) var(--my, 50%),
                    rgba(255, 255, 255, 0.25) 0%,
                    transparent 70%);
        }
        .nav-link.magnetic-hover .magnetic-glow {
            opacity: 1;
        }

        /* ============================================================
                   SCROLL MOMENTUM (akan diinjeksi ke iframe via JS)
                   ============================================================ */
        /* fallback style jika injeksi gagal */
        .smooth-scroll-iframe {
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>
<body class="flex p-3 md:p-4 gap-4 h-screen w-screen overflow-hidden">

    <!-- MOBILE OVERLAY -->
    <div id="mobile-overlay" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-40 opacity-0 invisible transition-all duration-300 md:hidden" onclick="toggleMobileSidebar(false)"></div>

    <!-- ============================================================
    SIDEBAR
    ============================================================ -->
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

            <!-- MENU ITEMS dengan tooltip -->
            <a href="pesan.php" class="nav-link active flex items-center gap-3 px-3 py-2.5"
            data-title="Follow-Up & Single"
            data-subtitle="Kirim pesan langsung ke target"
            data-icon="fa-envelope"
            data-bg="#dbeafe"
            data-text="#2563eb"
            data-iconcolor="#3b82f6"
            data-hover="#eff6ff">
            <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-envelope text-lg"></i></div>
            <span class="menu-text text-[13px] font-semibold tracking-wide">Pesan Personal</span>
            <span class="nav-tooltip"><span class="tip-main">Pesan Personal</span><span class="tip-sub">Follow-Up & Single</span></span>
        </a>

        <a href="kirimgrup.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
        data-title="Kirim Pesan Grup"
        data-subtitle="Broadcast ke grup WhatsApp"
        data-icon="fa-users"
        data-bg="#dcfce7"
        data-text="#166534"
        data-iconcolor="#059669"
        data-hover="#e8f5e9">
        <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-users text-lg"></i></div>
        <span class="menu-text text-[13px] font-semibold tracking-wide">Pesan Grup</span>
        <span class="nav-tooltip"><span class="tip-main">Pesan Grup</span><span class="tip-sub">Broadcast ke grup</span></span>
    </a>

    <a href="promosi.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
    data-title="Promosi Broadcast"
    data-subtitle="Kirim promosi massal"
    data-icon="fa-bullhorn"
    data-bg="#dcfce7"
    data-text="#16a34a"
    data-iconcolor="#22c55e"
    data-hover="#f0fdf4">
    <div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-bullhorn text-lg"></i></div>
    <span class="menu-text text-[13px] font-semibold tracking-wide">Promosi Massal</span>
    <span class="nav-tooltip"><span class="tip-main">Promosi Massal</span><span class="tip-sub">Broadcast promosi</span></span>
</a>

<a href="wa-tut.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Pesan Tutor WhatsApp"
data-subtitle="Manajemen materi & tutorial"
data-icon="fa-chalkboard-user"
data-bg="#f3e8ff"
data-text="#9333ea"
data-iconcolor="#a855f7"
data-hover="#faf5ff">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-chalkboard-user text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Pesan Tutor</span>
<span class="nav-tooltip"><span class="tip-main">Pesan Tutor</span><span class="tip-sub">Manajemen materi</span></span>
</a>

<div class="section-label text-[10px] font-bold text-slate-400 uppercase tracking-wider px-2 pb-1 pt-4">Sistem & Auto</div>

<a href="reminder.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Reminder Pembayaran"
data-subtitle="Jadwalkan pengingat tagihan"
data-icon="fa-bell"
data-bg="#fef3c7"
data-text="#d97706"
data-iconcolor="#f59e0b"
data-hover="#fffbeb">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-bell text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Tagihan Pembayaran</span>
<span class="nav-tooltip"><span class="tip-main">Tagihan Pembayaran</span><span class="tip-sub">Reminder otomatis</span></span>
</a>

<a href="kelola_reminder.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Sistem Reminder Otomatis"
data-subtitle="Robot pengingat peserta"
data-icon="fa-clock"
data-bg="#ffedd5"
data-text="#ea580c"
data-iconcolor="#f97316"
data-hover="#fff7ed">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-clock text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Robot Reminder</span>
<span class="nav-tooltip"><span class="tip-main">Robot Reminder</span><span class="tip-sub">Pengingat peserta</span></span>
</a>

<a href="manage_auto_reply.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Auto Reply Cerdas"
data-subtitle="Balasan otomatis"
data-icon="fa-reply-all"
data-bg="#ffe4e6"
data-text="#e11d48"
data-iconcolor="#f43f5e"
data-hover="#fff1f2">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-reply-all text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Auto Reply</span>
<span class="nav-tooltip"><span class="tip-main">Auto Reply</span><span class="tip-sub">Balasan otomatis</span></span>
</a>

<div class="section-label text-[10px] font-bold text-slate-400 uppercase tracking-wider px-2 pb-1 pt-4">Database</div>

<a href="manage_templates.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Manajemen Template"
data-subtitle="Atur format pesan"
data-icon="fa-file-alt"
data-bg="#ccfbf1"
data-text="#0f766e"
data-iconcolor="#14b8a6"
data-hover="#f0fdfa">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-file-alt text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Template Pesan</span>
<span class="nav-tooltip"><span class="tip-main">Template Pesan</span><span class="tip-sub">Atur format</span></span>
</a>

<a href="kelola_grup.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Manajemen Grup"
data-subtitle="Edit daftar grup target"
data-icon="fa-address-book"
data-bg="#e0e7ff"
data-text="#4f46e5"
data-iconcolor="#818cf8"
data-hover="#eef2ff">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-address-book text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Kelola Grup</span>
<span class="nav-tooltip"><span class="tip-main">Kelola Grup</span><span class="tip-sub">Daftar grup target</span></span>
</a>

<a href="grafik.php" class="nav-link flex items-center gap-3 px-3 py-2.5"
data-title="Dashboard Statistik"
data-subtitle="Analitik performa"
data-icon="fa-chart-line"
data-bg="#ede9fe"
data-text="#6d28d9"
data-iconcolor="#8b5cf6"
data-hover="#f5f3ff">
<div class="icon-wrapper w-6 text-center shrink-0"><i class="fas fa-chart-line text-lg"></i></div>
<span class="menu-text text-[13px] font-semibold tracking-wide">Statistik Data</span>
<span class="nav-tooltip"><span class="tip-main">Statistik Data</span><span class="tip-sub">Analitik performa</span></span>
</a>
</nav>

<div class="px-3 pb-4 mt-2 border-t border-slate-100 pt-3 shrink-0">
    <a href="logoutwa.php" class="logout-btn flex items-center gap-3 px-3 py-2 rounded-xl text-rose-500 hover:bg-rose-50 transition-colors font-medium">
        <i class="fas fa-sign-out-alt w-6 text-center text-rose-400 shrink-0"></i>
        <span class="logout-text text-[13px] font-semibold">Keluar Akun</span>
    </a>
</div>
</aside>

<!-- ============================================================
MAIN CONTENT
============================================================ -->
<main class="flex-1 flex flex-col gap-4 min-w-0 relative">

    <!-- HEADER -->
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

    <!-- IFRAME WRAPPER -->
    <div class="flex-1 bg-white rounded-2xl overflow-hidden shadow-sm border border-slate-200 relative bg-slate-50/30">

        <!-- PROGRESS LINE -->
        <div id="progress-line"></div>

        <!-- LOADING OVERLAY -->
        <div id="loading-overlay" class="absolute inset-0 flex flex-col items-center justify-center gap-3">
            <i class="fab fa-whatsapp text-4xl text-[#166534] loader-spin"></i>
            <p class="text-xs font-bold text-slate-500 tracking-wide bg-white/80 px-4 py-1.5 rounded-full shadow-sm">Memuat modul...</p>
        </div>

        <!-- IFRAME -->
        <iframe id="main-frame" name="main-frame" src="pesan.php"></iframe>
    </div>
</main>

<!-- ============================================================
JAVASCRIPT — Efek & Logika
============================================================ -->
<script>
    (function() {
        'use strict';

        // DOM refs
        const iframe = document.getElementById('main-frame');
        const loader = document.getElementById('loading-overlay');
        const progressLine = document.getElementById('progress-line');
        const navLinks = document.querySelectorAll('.nav-link');
        const sidebar = document.getElementById('sidebar');
        const btnCollapse = document.getElementById('desktop-collapse-btn');
        const iconCollapse = document.getElementById('collapse-icon');
        const mobileOverlay = document.getElementById('mobile-overlay');

        let isCollapsed = false;

        // ============================================================
        // 1. MAGNETIC BUTTON EFFECT
        // ============================================================
        navLinks.forEach(link => {
            const glow = document.createElement('span');
            glow.className = 'magnetic-glow';
            link.appendChild(glow);

            link.addEventListener('mousemove', function(e) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const cx = rect.width / 2;
                const cy = rect.height / 2;
                const dx = (x - cx) / cx;
                const dy = (y - cy) / cy;

                const moveX = dx * 3.5;
                const moveY = dy * 3.5;

                this.style.setProperty('--mx', (x / rect.width * 100) + '%');
                this.style.setProperty('--my', (y / rect.height * 100) + '%');

                if (!this.classList.contains('active')) {
                    this.style.transform =
                        `translate(${moveX}px, ${moveY}px) scale(1.04)`;
                } else {
                    this.style.transform =
                        `translate(${moveX * 0.7}px, ${moveY * 0.7}px) scale(1.06)`;
                }
                this.classList.add('magnetic-hover');
            });

            link.addEventListener('mouseleave', function() {
                this.style.transform = '';
                this.classList.remove('magnetic-hover');
                this.style.setProperty('--mx', '50%');
                this.style.setProperty('--my', '50%');
            });
        });

        // ============================================================
        // 2. SMART FLOATING TOOLTIP
        // ============================================================
        function updateTooltipPosition(tooltip, link) {
            const rect = link.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            let left = rect.right + 12;
            let top = rect.top + rect.height / 2 - tooltipRect.height / 2;

            if (left + tooltipRect.width > window.innerWidth - 16) {
                left = rect.left - tooltipRect.width - 12;
            }
            if (top < 8) top = 8;
            if (top + tooltipRect.height > window.innerHeight - 8) {
                top = window.innerHeight - tooltipRect.height - 8;
            }
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        }

        navLinks.forEach(link => {
            const tip = link.querySelector('.nav-tooltip');
            if (!tip) return;

            link.addEventListener('mouseenter', function(e) {
                if (!sidebar.classList.contains('collapsed')) {
                    tip.classList.remove('show');
                    return;
                }
                requestAnimationFrame(() => {
                    updateTooltipPosition(tip, this);
                    tip.classList.add('show');
                });
            });

            link.addEventListener('mouseleave', function() {
                tip.classList.remove('show');
            });

            window.addEventListener('scroll', function() {
                if (tip.classList.contains('show')) {
                    updateTooltipPosition(tip, link);
                }
            }, { passive: true });
            window.addEventListener('resize', function() {
                if (tip.classList.contains('show')) {
                    updateTooltipPosition(tip, link);
                }
            }, { passive: true });
        });

        // ============================================================
        // 3. PROGRESS INDICATOR & SLIDE-IN
        // ============================================================
        function startProgress() {
            progressLine.classList.remove('done');
            progressLine.style.width = '0%';
            progressLine.style.opacity = '1';
            progressLine.classList.add('active');

            let p = 0;
            const step = () => {
                if (p >= 100) {
                    progressLine.style.width = '100%';
                    return;
                }
                const inc = p < 30 ? 4 : (p < 70 ? 2.5 : 1.2);
                p = Math.min(p + inc, 100);
                progressLine.style.width = p + '%';
                if (p < 100) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        }

        function finishProgress() {
            progressLine.classList.remove('active');
            progressLine.style.width = '100%';
            progressLine.classList.add('done');
            setTimeout(() => {
                progressLine.style.opacity = '0';
                progressLine.style.width = '0%';
                progressLine.classList.remove('done');
            }, 700);
        }

        // ============================================================
        // 4. IFRAME LOAD – SLIDE-IN + SCROLL MOMENTUM
        // ============================================================
        iframe.addEventListener('load', function() {
            // Slide-in
            this.classList.add('loaded');
            loader.style.opacity = '0';
            setTimeout(() => {
                loader.style.visibility = 'hidden';
            }, 350);
            finishProgress();

            // === INJEKSI SCROLL MOMENTUM ke dalam iframe ===
            try {
                const doc = this.contentDocument || this.contentWindow.document;
                if (doc) {
                    // Tambahkan CSS untuk smooth scroll dan touch momentum
                    const style = doc.createElement('style');
                    style.textContent = `
                                html {
                                    scroll-behavior: smooth !important;
                                    -webkit-overflow-scrolling: touch !important;
                                }
                                body {
                                    -webkit-overflow-scrolling: touch !important;
                                }
                                /* tambahan untuk semua elemen scrollable */
                                .scrollable, [style*="overflow"] {
                                    scroll-behavior: smooth !important;
                                    -webkit-overflow-scrolling: touch !important;
                                }
                            `;
                    doc.head.appendChild(style);

                    // Sembunyikan header & sidebar bawaan jika ada
                    const innerHeader = doc.querySelector('header');
                    const innerSidebar = doc.getElementById('mainSidebar') || doc.getElementById('sidebar');
                    if (innerHeader) innerHeader.style.display = 'none';
                    if (innerSidebar) innerSidebar.style.display = 'none';
                    doc.body.style.backgroundColor = 'transparent';
                }
            } catch (e) {
                // Jika beda origin, tidak bisa diinjeksi — diam saja
            }
        });

        // ============================================================
        // 5. NAVIGASI TAB
        // ============================================================
        function switchTab(e, link) {
            e.preventDefault();
            const targetUrl = link.getAttribute('href');

            if (iframe.src.includes(targetUrl) && iframe.classList.contains('loaded')) return;

            startProgress();
            loader.style.visibility = 'visible';
            loader.style.opacity = '1';
            iframe.classList.remove('loaded');
            iframe.style.opacity = '0';

            navLinks.forEach(l => {
                l.classList.remove('active');
                l.style.background = '';
                l.style.color = '';
                const icon = l.querySelector('.icon-wrapper');
                if (icon) icon.style.color = '';
                l.style.transform = '';
                l.classList.remove('magnetic-hover');
            });

            link.classList.add('active');
            link.style.backgroundColor = link.dataset.bg;
            link.style.color = link.dataset.text;
            const iconWrapper = link.querySelector('.icon-wrapper');
            if (iconWrapper) iconWrapper.style.color = link.dataset.iconcolor;

            document.getElementById('page-title').textContent = link.dataset.title;
            document.getElementById('page-subtitle').textContent = link.dataset.subtitle;
            document.getElementById('header-icon').className = `fas ${link.dataset.icon} text-lg transition-all`;
            const headerBox = document.getElementById('header-icon-box');
            headerBox.className =
                `${link.dataset.bg} ${link.dataset.text} p-2.5 rounded-xl shadow-sm transition-all duration-300`;
            document.getElementById('title-dot').className =
                `w-1.5 h-1.5 rounded-full ${link.dataset.text.replace('text', 'bg')}`;

            iframe.src = targetUrl;
            localStorage.setItem('reqrawa_active', targetUrl);

            if (window.innerWidth <= 768) toggleMobileSidebar(false);
        }

        navLinks.forEach(link => {
            link.addEventListener('click', (e) => switchTab(e, link));
            link.addEventListener('mouseenter', () => {
                if (!link.classList.contains('active')) {
                    link.style.backgroundColor = link.dataset.hover;
                }
            });
            link.addEventListener('mouseleave', () => {
                if (!link.classList.contains('active')) {
                    link.style.backgroundColor = '';
                }
            });
        });

        // ============================================================
        // 6. INISIALISASI TAB TERAKHIR
        // ============================================================
        const savedUrl = localStorage.getItem('reqrawa_active');
        let initialLink = null;
        if (savedUrl) {
            initialLink = Array.from(navLinks).find(l => l.getAttribute('href') === savedUrl);
        }
        if (initialLink) {
            navLinks.forEach(l => l.classList.remove('active'));
            initialLink.classList.add('active');
            initialLink.style.backgroundColor = initialLink.dataset.bg;
            initialLink.style.color = initialLink.dataset.text;
            const ic = initialLink.querySelector('.icon-wrapper');
            if (ic) ic.style.color = initialLink.dataset.iconcolor;

            document.getElementById('page-title').textContent = initialLink.dataset.title;
            document.getElementById('page-subtitle').textContent = initialLink.dataset.subtitle;
            document.getElementById('header-icon').className = `fas ${initialLink.dataset.icon} text-lg transition-all`;
            const hb = document.getElementById('header-icon-box');
            hb.className =
                `${initialLink.dataset.bg} ${initialLink.dataset.text} p-2.5 rounded-xl shadow-sm transition-all duration-300`;
            document.getElementById('title-dot').className =
                `w-1.5 h-1.5 rounded-full ${initialLink.dataset.text.replace('text', 'bg')}`;

            iframe.src = savedUrl;
            startProgress();
        } else {
            const first = navLinks[0];
            if (first) {
                first.classList.add('active');
                first.style.backgroundColor = first.dataset.bg;
                first.style.color = first.dataset.text;
                const ic = first.querySelector('.icon-wrapper');
                if (ic) ic.style.color = first.dataset.iconcolor;
                iframe.src = first.getAttribute('href');
                startProgress();
            }
        }

        // ============================================================
        // 7. TOGGLE SIDEBAR (Desktop Collapse + Mobile)
        // ============================================================
        if (btnCollapse) {
            btnCollapse.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                isCollapsed = sidebar.classList.contains('collapsed');
                if (isCollapsed) {
                    iconCollapse.classList.replace('fa-chevron-left', 'fa-chevron-right');
                } else {
                    iconCollapse.classList.replace('fa-chevron-right', 'fa-chevron-left');
                    document.querySelectorAll('.nav-tooltip.show').forEach(t => t.classList.remove('show'));
                }
            });
        }

        window.toggleMobileSidebar = function(show) {
            if (show) {
                sidebar.classList.add('show');
                mobileOverlay.classList.remove('invisible', 'opacity-0');
                mobileOverlay.classList.add('opacity-100');
            } else {
                sidebar.classList.remove('show');
                mobileOverlay.classList.remove('opacity-100');
                mobileOverlay.classList.add('invisible', 'opacity-0');
            }
        };

        // ============================================================
        // 8. RESIZE – update tooltip
        // ============================================================
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                document.querySelectorAll('.nav-tooltip.show').forEach(tip => {
                    const link = tip.closest('.nav-link');
                    if (link) updateTooltipPosition(tip, link);
                });
            }, 150);
        });

        // ============================================================
        // 9. FALLBACK if iframe stuck
        // ============================================================
        let loadFallback = setTimeout(() => {
            if (!iframe.classList.contains('loaded')) {
                iframe.classList.add('loaded');
                iframe.style.opacity = '1';
                loader.style.opacity = '0';
                setTimeout(() => loader.style.visibility = 'hidden', 350);
                finishProgress();
            }
        }, 8000);

        iframe.addEventListener('load', () => {
            clearTimeout(loadFallback);
        });

        console.log('✨ ReqraWA — Sidebar smooth + Scroll momentum di iframe');
    })();
</script>
</body>
</html>