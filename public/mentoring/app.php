<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$userRole = currentRole();
$userName = currentUserName();
$userObj = [
    'name' => $userName,
    'role' => $userRole
];

// Jika login sebagai siswa, ambil NIS dan Kelas
if ($userRole === 'siswa') {
    $studentId = $_SESSION['student_id'] ?? 0;
    if ($studentId) {
        $stmtS = $pdo->prepare("SELECT s.name, s.nis, cg.name as class_name FROM students s LEFT JOIN class_groups cg ON cg.id = s.class_group_id WHERE s.id = ?");
        $stmtS->execute([$studentId]);
        $st = $stmtS->fetch();
        if ($st) {
            $userObj['name'] = $st['name'];
            $userObj['nis'] = $st['nis'];
            $userObj['kelas'] = $st['class_name'] ?? '7';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Mentoring MIP - SMP An Nahl Islamic School</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#ecfdf5',
                            100: '#d1fae5',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                            800: '#065f46',
                            900: '#064e3b',
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            .no-print, header, #login-screen, #nav-tabs, #dashboard-tab, #form-tab, #rekap-tab, #settings-tab, #bank-tab, #siswa-tab, #toast-modal { 
                display: none !important; 
            }
            #report-tab { 
                display: block !important; 
                padding: 0 !important; 
                margin: 0 !important; 
                width: 100% !important;
            }
            body { 
                background: white !important; 
                font-size: 9.5pt; 
                color: black !important; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important;
            }
            #rptAspectsBarDiagram {
                display: grid !important;
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 6px !important;
            }
            .print-aspect-card {
                border: 1px solid #cbd5e1 !important;
                background-color: #f8fafc !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                padding: 6px !important;
            }
            .print-bar-container {
                border: 1px solid #e2e8f0 !important;
                background-color: #ffffff !important;
            }
            .print-bar-fill {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .print-break-inside-avoid { break-inside: avoid !important; page-break-inside: avoid !important; }
            @page { size: A4 portrait; margin: 6mm 6mm 6mm 6mm; }
        }
        .custom-scrollbar::-webkit-scrollbar { height: 6px; width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>

    <!-- AIS ABSENSI NATIVE BRIDGE ADAPTER -->
    <script>
    const PHP_SESSION_USER = <?php echo json_encode($userObj); ?>;

    window.google = {
        script: {
            run: {
                withSuccessHandler: function(onSuccess) {
                    const handler = {
                        withFailureHandler: function(onFail) {
                            handler._onFail = onFail;
                            return handler;
                        },
                        getSiswaData: function() {
                            fetch('api.php?action=getSiswaData')
                                .then(r => r.json())
                                .then(res => onSuccess(res))
                                .catch(err => { if (handler._onFail) handler._onFail(err); });
                        },
                        getUsersData: function() {
                            fetch('api.php?action=getUsersData')
                                .then(r => r.json())
                                .then(res => onSuccess(res))
                                .catch(err => { if (handler._onFail) handler._onFail(err); });
                        },
                        getBankMateri: function() {
                            fetch('api.php?action=getBankMateri')
                                .then(r => r.json())
                                .then(res => onSuccess(res))
                                .catch(err => { if (handler._onFail) handler._onFail(err); });
                        }
                    };
                    return handler;
                },
                simpanDataMentoring: function(data) {
                    fetch('api.php?action=simpanDataMentoring', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(data)
                    }).then(r => r.json()).then(res => {
                        console.log('simpanDataMentoring res:', res);
                    });
                },
                simpanAdabSiswa: function(data) {
                    fetch('api.php?action=simpanAdabSiswa', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(data)
                    }).then(r => r.json()).then(res => {
                        console.log('simpanAdabSiswa res:', res);
                    });
                },
                simpanBankMateri: function(data) {
                    fetch('api.php?action=simpanBankMateri', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(data)
                    });
                },
                updateAllBankMateri: function(data) {
                    fetch('api.php?action=updateAllBankMateri', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(data)
                    });
                }
            }
        }
    };

    // Auto-sync Historical Rekap, Bank Materi & Adab Store directly from MariaDB API
    function fetchAllInitialDataFromDatabase() {
        // 1. Load Rekap Data (413 records)
        fetch('api.php?action=getRekapData')
            .then(r => r.json())
            .then(res => {
                if (res && res.success && res.data && res.data.length > 0) {
                    console.log('Loaded historical rekap data from DB:', res.data.length);
                    MENTORING_REKAP_DATA = res.data;
                    try { localStorage.setItem('MIP_REKAP_STORE', JSON.stringify(MENTORING_REKAP_DATA)); } catch(e){}
                    if (typeof renderRekapMatrixTable === 'function') renderRekapMatrixTable();
                    if (typeof renderDashboardAspectsCharts === 'function') renderDashboardAspectsCharts();
                }
            }).catch(e => console.warn('Could not load rekap data:', e));

        // 2. Load Bank Materi (35 modules)
        fetch('api.php?action=getBankMateri')
            .then(r => r.json())
            .then(res => {
                if (res && res.success && res.data && res.data.length > 0) {
                    console.log('Loaded bank materi from DB:', res.data.length);
                    BANK_MATERI_DATA = res.data;
                    if (typeof populateAdabMateriSelect === 'function') populateAdabMateriSelect();
                    if (typeof renderBankMateriCards === 'function') renderBankMateriCards();
                }
            }).catch(e => console.warn('Could not load bank materi:', e));

        // 3. Load Adab Data (2.817 logs)
        fetch('api.php?action=getAdabData')
            .then(r => r.json())
            .then(res => {
                if (res && res.success && res.data) {
                    console.log('Loaded historical adab data from DB:', Object.keys(res.data).length);
                    Object.assign(SISWA_ADAB_STORE, res.data);
                    try { localStorage.setItem('MIP_SISWA_ADAB_STORE', JSON.stringify(SISWA_ADAB_STORE)); } catch(e){}
                }
            }).catch(e => console.warn('Could not load adab data:', e));
    }

    document.addEventListener('DOMContentLoaded', fetchAllInitialDataFromDatabase);
    // Jalankan ulang setelah 800ms untuk memastikan seluruh listener SPA selesai inisialisasi
    window.addEventListener('load', () => setTimeout(fetchAllInitialDataFromDatabase, 800));
    </script>

</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col antialiased selection:bg-brand-500 selection:text-white">

    <!-- AIS ABSENSI TOP NAVBAR -->
    <div class="bg-slate-900 text-white px-4 py-2 flex items-center justify-between text-xs border-b border-slate-800 no-print">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span class="font-bold text-slate-200">Sistem Mentoring MIP & Adab Terpadu</span>
            <span class="text-slate-500">|</span>
            <span class="text-slate-400">Database Live MariaDB</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="/public/dashboard.php" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg transition flex items-center gap-1.5 font-semibold">
                <i class="fa-solid fa-arrow-left"></i> Dashboard Absensi
            </a>
            <a href="/public/mentoring/index.php" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition flex items-center gap-1.5 font-bold shadow-sm">
                <i class="fa-solid fa-table-cells-large"></i> Mode Standar
            </a>
        </div>
    </div>



    <!-- ================= 1. LOGIN SCREEN OVERLAY ================= -->
    <div id="login-screen" class="fixed inset-0 z-50 bg-slate-900/90 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-md w-full p-8 space-y-6 relative overflow-hidden">
            <div class="text-center space-y-2">
                <!-- SMP AN NAHL LOGO EMBLEM -->
                <div class="inline-block p-3 bg-slate-50 border border-slate-200 rounded-2xl shadow-sm mb-1">
                    <img src="data:image/png;base64,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" alt="Logo SMP An Nahl Islamic School" class="h-16 w-auto object-contain mx-auto">
                </div>
                <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Mentoring MIP Portal</h1>
                <p class="text-xs text-slate-500 font-medium">SMP An Nahl Islamic School - Sistem Evaluasi 4 Aspek</p>
            </div>

            <!-- Login Role Switcher Tabs -->
            <div class="flex bg-slate-100 p-1 rounded-xl gap-1 text-xs font-bold text-slate-600">
                <button type="button" id="tabRoleMentor" onclick="switchLoginRole('mentor')" class="flex-1 py-2 rounded-lg transition bg-white text-brand-700 shadow-sm">
                    <i class="fa-solid fa-user-tie mr-1"></i> Mentor / Guru
                </button>
                <button type="button" id="tabRoleSiswa" onclick="switchLoginRole('siswa')" class="flex-1 py-2 rounded-lg transition hover:text-slate-900">
                    <i class="fa-solid fa-user-graduate mr-1"></i> Siswa / Murid
                </button>
            </div>

            <!-- FORM LOGIN MENTOR / GURU -->
            <form id="formLoginMentor" onsubmit="handleLoginMentor(event); return false;" class="space-y-4" autocomplete="off">
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600">Username / Nama Mentor</label>
                        <select id="selectQuickMentorLogin" onchange="onSelectQuickMentorLogin()" class="text-[10.5px] font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-lg px-2 py-0.5 max-w-[170px]">
                            <option value="">-- Pilih Mentor --</option>
                        </select>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-user text-xs"></i>
                        </span>
                        <input type="text" id="loginMentorUsername" autocomplete="off" onkeydown="if(event.key==='Enter') { handleLoginMentor(event); }" placeholder="Masukkan Username / Nama Mentor (contoh: admin / Ust. Ahmad)..." class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:bg-white transition">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600">Password Access</label>
                        <button type="button" onclick="openModalLupaPasswordMentor()" class="text-[10.5px] font-bold text-brand-600 hover:text-brand-800 hover:underline transition flex items-center gap-1">
                            <i class="fa-solid fa-circle-question"></i> Lupa Password?
                        </button>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-key text-xs"></i>
                        </span>
                        <input type="text" id="loginMentorPassword" style="-webkit-text-security: disc; text-security: disc;" autocomplete="off" onkeydown="if(event.key==='Enter') { handleLoginMentor(event); }" placeholder="Masukkan password (opsional/default)..." class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:bg-white transition">
                    </div>
                </div>

                <button type="button" id="btnLoginMentorSubmit" onclick="handleLoginMentor(event)" class="w-full py-3 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-extrabold shadow-lg shadow-brand-600/30 transition flex items-center justify-center gap-2 cursor-pointer">
                    <span>Login Portal Mentor / Admin</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>

                <button type="button" onclick="bypassLoginDirect('admin')" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold transition flex items-center justify-center gap-1">
                    <i class="fa-solid fa-bolt text-amber-500"></i>
                    <span>Masuk Langsung (Quick Access Admin)</span>
                </button>
            </form>

            <!-- FORM LOGIN SISWA / MURID -->
            <form id="formLoginSiswa" onsubmit="handleLoginSiswa(event); return false;" class="space-y-4 hidden" autocomplete="off">
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600">Nomor Induk Siswa (NIS)</label>
                        <select id="selectQuickSiswaLogin" onchange="onSelectQuickSiswaLogin()" class="text-[10.5px] font-bold text-teal-700 bg-teal-50 border border-teal-200 rounded-lg px-2 py-0.5 max-w-[170px]">
                            <option value="">-- Pilih Murid --</option>
                        </select>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-id-card text-xs"></i>
                        </span>
                        <input type="text" id="loginSiswaNis" autocomplete="off" onkeydown="if(event.key==='Enter') { handleLoginSiswa(event); }" placeholder="Masukkan NIS murid (contoh: 262707001)..." class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-teal-500 focus:bg-white transition">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1.5">Password Access</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-lock text-xs"></i>
                        </span>
                        <input type="text" id="loginSiswaPassword" style="-webkit-text-security: disc; text-security: disc;" autocomplete="off" onkeydown="if(event.key==='Enter') { handleLoginSiswa(event); }" placeholder="Masukkan password (opsional/default)..." class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-teal-500 focus:bg-white transition">
                    </div>
                </div>

                <button type="button" id="btnLoginSiswaSubmit" onclick="handleLoginSiswa(event)" class="w-full py-3 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-xs font-extrabold shadow-lg shadow-teal-600/30 transition flex items-center justify-center gap-2 cursor-pointer">
                    <span>Login Portal Penugasan Siswa</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>

                <button type="button" onclick="bypassLoginDirect('siswa')" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold transition flex items-center justify-center gap-1">
                    <i class="fa-solid fa-bolt text-teal-600"></i>
                    <span>Masuk Langsung (Quick Access Siswa)</span>
                </button>
            </form>

            <div class="text-center text-[10.5px] text-slate-400 border-t border-slate-100 pt-4">
                &copy; 2026 SMP An Nahl Islamic School - MIP Mentoring System
            </div>
        </div>
    </div>


    <!-- ================= 2. HEADER TOP NAVBAR ================= -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="p-1.5 bg-slate-50 border border-slate-200 rounded-xl shadow-sm">
                    <img src="data:image/png;base64,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" alt="Logo SMP An Nahl" class="h-9 w-auto object-contain">
                </div>
                <div>
                    <h1 class="text-sm font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                        MENTORING MIP <span class="text-[10px] bg-brand-100 text-brand-800 font-extrabold px-2 py-0.5 rounded-md border border-brand-200">v15</span>
                    </h1>
                    <p class="text-[10.5px] text-slate-500 font-medium">SMP An Nahl Islamic School</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div id="userInfoBar" class="hidden sm:flex items-center gap-2 px-3 py-1.5 bg-slate-100 border border-slate-200 rounded-xl text-xs">
                    <i class="fa-solid fa-circle-user text-brand-600 text-sm"></i>
                    <span id="userNameDisplay" class="font-bold text-slate-800">User Active</span>
                    <span id="userRoleBadge" class="text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase tracking-wider bg-brand-100 text-brand-800">ROLE</span>
                </div>
                <button onclick="logoutApp()" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span class="hidden sm:inline">Logout</span>
                </button>
            </div>
        </div>
    </header>


    <!-- ================= 3. MAIN NAVIGATION TABS ================= -->
    <div id="nav-tabs" class="bg-white border-b border-slate-200 no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex space-x-1 overflow-x-auto custom-scrollbar py-2">
            <button id="btnTabDashboard" onclick="showTab('dashboard-tab')" class="nav-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent bg-brand-50 text-brand-700 border-brand-200 shadow-sm">
                <i class="fa-solid fa-chart-pie"></i> Dashboard Progress
            </button>
            <button id="btnTabForm" onclick="showTab('form-tab')" class="nav-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent hover:bg-slate-100 text-slate-700">
                <i class="fa-solid fa-file-pen"></i> Isi Jurnal Mentoring
            </button>
            <button id="btnTabReport" onclick="showTab('report-tab')" class="nav-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent hover:bg-slate-100 text-slate-700">
                <i class="fa-solid fa-print"></i> Laporan & Cetak PDF
            </button>
            <button id="btnTabRekap" onclick="showTab('rekap-tab')" class="nav-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent hover:bg-slate-100 text-slate-700">
                <i class="fa-solid fa-table"></i> Matriks Rekapitulasi
            </button>
            <button id="btnTabBank" onclick="showTab('bank-tab')" class="nav-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent hover:bg-slate-100 text-slate-700">
                <i class="fa-solid fa-book-bookmark"></i> Bank Materi Adab
            </button>
            <button id="btnTabSiswa" onclick="showTab('siswa-tab')" class="nav-btn hidden px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent hover:bg-slate-100 text-teal-700 bg-teal-50 border-teal-200">
                <i class="fa-solid fa-user-graduate"></i> Portal Adab Siswa
            </button>
            <button id="btnTabSettings" onclick="showTab('settings-tab')" class="nav-btn hidden px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 border border-transparent hover:bg-slate-100 text-purple-700 bg-purple-50 border-purple-200">
                <i class="fa-solid fa-sliders"></i> Pengaturan Admin
            </button>
        </div>
    </div>


    <!-- ================= 4. MAIN CONTENT CONTAINER ================= -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1 w-full space-y-6">

        <!-- ================= TAB 1: DASHBOARD PROGRESS & DIAGRAM VISUAL ================= -->
        <section id="dashboard-tab" class="tab-content space-y-6">
            <!-- SUMMARY STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-1">
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Total Murid Terdaftar</span>
                    <div class="flex items-baseline justify-between">
                        <h3 id="statTotalMentee" class="text-2xl font-extrabold text-slate-900">0</h3>
                        <span class="text-xs font-bold text-brand-600 bg-brand-50 px-2 py-0.5 rounded-md border border-brand-200">Siswa Active</span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-1">
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Sesi Evaluasi Terisi</span>
                    <div class="flex items-baseline justify-between">
                        <h3 id="statSesiTerisi" class="text-2xl font-extrabold text-slate-900">0</h3>
                        <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md border border-blue-200">Sesi Mentoring</span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-1">
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Rata-Rata Capaian Adab</span>
                    <div class="flex items-baseline justify-between">
                        <h3 id="statRataRataSkor" class="text-2xl font-extrabold text-slate-900">0.0</h3>
                        <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">Skala 1 - 10</span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-1">
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Status Capaian Umum</span>
                    <div class="flex items-baseline justify-between">
                        <h3 id="statPredikatUmum" class="text-lg font-extrabold text-brand-700">Sangat Baik</h3>
                        <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-md border border-purple-200">Evaluasi</span>
                    </div>
                </div>
            </div>

            <!-- VISUAL DIAGRAM PROGRESS BARS PER ASPEK & CETAK REKAP PDF -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                <div class="border-b border-slate-200 pb-4 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 flex items-center gap-2">
                            <i class="fa-solid fa-chart-column text-brand-600"></i> Diagram Progress 4 Rincian Evaluasi Aspek Mentoring
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Grafik pencapaian kumulatif siswa per Aspek Mentoring MIP SMP An Nahl Islamic School.</p>
                    </div>

                    <!-- CETAK REKAP PDF ACTION BUTTONS -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <select id="dashFilterPrintMentorSelect" class="p-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold focus:ring-2 focus:ring-brand-500">
                            <option value="ALL">-- Semua Mentor (Rekap Umum) --</option>
                            <!-- Populated dynamically via JS -->
                        </select>
                        <button type="button" onclick="printRekapPDF('umum')" class="px-3.5 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-extrabold shadow transition flex items-center gap-1.5 cursor-pointer">
                            <i class="fa-solid fa-file-pdf"></i> Cetak PDF Rekap Umum
                        </button>
                        <button type="button" onclick="printRekapPDF('mentor')" class="px-3.5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-extrabold shadow transition flex items-center gap-1.5 cursor-pointer">
                            <i class="fa-solid fa-user-tie"></i> Cetak PDF Rekap Per Mentor
                        </button>
                    </div>
                </div>

                <!-- DIAGRAM CONTAINER -->
                <div id="dashboardAspectsChartCard" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Rendered dynamically via JS -->
                </div>
            </div>
        </section>


        <!-- ================= TAB 2: FORM ISI JURNAL MENTORING ================= -->
        <section id="form-tab" class="tab-content hidden space-y-6">
            <form id="formJurnalMentoring" onsubmit="handleSimpanJurnal(event)" class="space-y-6">
                <!-- METADATA FORM -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <h2 class="text-base font-extrabold text-slate-800 border-b border-slate-200 pb-3 flex items-center gap-2">
                        <i class="fa-solid fa-id-card text-brand-600"></i> Informasi Mentoring & Identitas Murid
                    </h2>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Tahun Ajaran</label>
                            <select id="tahunAjaranSelect" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                                <option value="2026/2027">2026/2027</option>
                                <option value="2025/2026">2025/2026</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Nama Mentor / Guru</label>
                            <select id="namaMentorSelect" onchange="filterMenteeByMentor()" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                                <option value="">-- Pilih Mentor --</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Nama Murid / Siswa</label>
                            <select id="namaMenteeSelect" onchange="onMenteeSelectInForm()" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                                <option value="">-- Pilih Murid --</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Pekan & Sesi Mentoring</label>
                            <div class="grid grid-cols-2 gap-2">
                                <select id="pekanSelect" onchange="autoSelectMateriForCurrentMenteeAndPekan()" class="p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                                    <option value="P1">Pekan 1</option>
                                    <option value="P2">Pekan 2</option>
                                    <option value="P3">Pekan 3</option>
                                    <option value="P4">Pekan 4</option>
                                </select>
                                <select id="hariSelect" class="p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                                    <option value="Selasa">Selasa</option>
                                    <option value="Rabu">Rabu</option>
                                    <option value="Kamis">Kamis</option>
                                    <option value="Jumat">Jumat</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs pt-2">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Tanggal Pengisian Sesi</label>
                            <input type="date" id="tanggalSelect" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                        </div>
                        <div>
                            <label class="block font-bold text-purple-900 mb-1">Pilihan Materi Adab Aktif (Bank Materi)</label>
                            <select id="adabActiveMateriSelect" onchange="applySelectedMateriToForm()" class="w-full p-2.5 bg-purple-50 border border-purple-300 rounded-xl font-bold text-purple-900">
                                <!-- Populated dynamically -->
                            </select>

                            <!-- MANUAL MATERI INPUT CONTAINER -->
                            <div id="manualMateriContainer" class="mt-3 p-3 bg-purple-50/80 rounded-xl border border-purple-200 space-y-3 hidden">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <span class="text-xs font-bold text-purple-950 uppercase flex items-center gap-1.5">
                                        <i class="fa-solid fa-pen-to-square text-purple-600"></i> Input Materi Adab Manual / Kustom
                                    </span>
                                    <span class="text-[10px] text-purple-700 font-semibold px-2 py-0.5 bg-purple-100 rounded-md border border-purple-200">Mode Manual / Kustom</span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <label class="block font-bold text-purple-900 mb-0.5">Judul Materi Manual</label>
                                        <input type="text" id="manualMateriJudul" placeholder="Contoh: 8. Adab Terhadap Guru & Teman" oninput="updateManualMateriPreview()" class="w-full p-2 bg-white border border-purple-300 rounded-lg font-bold text-purple-950">
                                    </div>
                                    <div>
                                        <label class="block font-bold text-purple-900 mb-0.5">Peruntukan Kelas & Pekan</label>
                                        <input type="text" id="manualMateriMeta" placeholder="Contoh: Semua Kelas | Pekan 1" oninput="updateManualMateriPreview()" class="w-full p-2 bg-white border border-purple-300 rounded-lg text-slate-800">
                                    </div>
                                </div>
                                <div>
                                    <label class="block font-bold text-purple-900 mb-0.5 text-xs">Deskripsi & Tujuan Pembelajaran Manual</label>
                                    <textarea id="manualMateriDesc" rows="2" placeholder="Tuliskan rangkuman dan tujuan pembelajaran materi adab ini..." oninput="updateManualMateriPreview()" class="w-full p-2 bg-white border border-purple-300 rounded-lg text-xs text-slate-800"></textarea>
                                </div>

                                <!-- DYNAMIC COLUMNS INPUT UNTUK MANUAL MATERI -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs pt-1 border-t border-purple-200">
                                    <div class="bg-teal-50 p-2.5 rounded-lg border border-teal-200 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="font-bold text-teal-900 text-[11px]"><i class="fa-solid fa-list-check text-teal-600 mr-1"></i> Kolom Penugasan</span>
                                            <button type="button" onclick="addManualTaskRow()" class="px-2 py-0.5 bg-teal-600 hover:bg-teal-700 text-white text-[10px] font-bold rounded-md transition flex items-center gap-1">
                                                <i class="fa-solid fa-plus-circle"></i> Tambah Kolom
                                            </button>
                                        </div>
                                        <div id="manualTasksContainer" class="space-y-1.5">
                                            <!-- Dynamic task rows -->
                                        </div>
                                    </div>

                                    <div class="bg-purple-50 p-2.5 rounded-lg border border-purple-200 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="font-bold text-purple-900 text-[11px]"><i class="fa-solid fa-brain text-purple-600 mr-1"></i> Kolom Refleksi</span>
                                            <button type="button" onclick="addManualRefRow()" class="px-2 py-0.5 bg-purple-600 hover:bg-purple-700 text-white text-[10px] font-bold rounded-md transition flex items-center gap-1">
                                                <i class="fa-solid fa-plus-circle"></i> Tambah Kolom
                                            </button>
                                        </div>
                                        <div id="manualRefsContainer" class="space-y-1.5">
                                            <!-- Dynamic refleksi rows -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RINCIAN EVALUASI 4 ASPEK MENTORING -->
                <div class="space-y-4">
                    <h3 class="text-sm font-extrabold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-brand-600"></i> Rincian Evaluasi 4 Aspek Mentoring
                    </h3>

                    <!-- ASPEK 1: MIP -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h4 class="text-xs font-extrabold text-teal-800 uppercase flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-teal-100 text-teal-800 flex items-center justify-center font-bold text-xs">1</span>
                                Aspek MIP (Mission Is Possible)
                            </h4>
                            <span class="text-[11px] font-bold text-slate-500">Skala Nilai: 1 - 10</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Nilai MIP (1 - 10)</label>
                                <input type="number" id="skorMIP" min="1" max="10" step="0.1" value="8.0" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold text-center">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block font-bold text-slate-700 mb-1">Catatan Progress & Evaluasi MIP</label>
                                <input type="text" id="catatanMIP" placeholder="Tuliskan catatan target MIP murid..." class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                            </div>
                        </div>
                    </div>

                    <!-- ASPEK 2: AKADEMIK & NON-AKADEMIK -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h4 class="text-xs font-extrabold text-blue-800 uppercase flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-800 flex items-center justify-center font-bold text-xs">2</span>
                                Aspek Akademik & Non-Akademik
                            </h4>
                            <span class="text-[11px] font-bold text-slate-500">Skala Nilai: 1 - 10</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Nilai Akademik (1 - 10)</label>
                                <input type="number" id="skorAkademik" min="1" max="10" step="0.1" value="8.0" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold text-center">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block font-bold text-slate-700 mb-1">Catatan Prestasi & Nilai Mata Pelajaran</label>
                                <input type="text" id="catatanAkademik" placeholder="Tuliskan catatan akademik murid..." class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                            </div>
                        </div>
                    </div>

                    <!-- ASPEK 3: MUTABAAH YAUMIFAH -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h4 class="text-xs font-extrabold text-emerald-800 uppercase flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 flex items-center justify-center font-bold text-xs">3</span>
                                Aspek Mutaba'ah Yaumiyah
                            </h4>
                            <span class="text-[11px] font-bold text-slate-500">Skala Nilai: 1 - 10</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Nilai Mutaba'ah (1 - 10)</label>
                                <input type="number" id="skorMutabaah" min="1" max="10" step="0.1" value="8.5" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold text-center">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block font-bold text-slate-700 mb-1">Catatan Shalat 5 Waktu & Tilawah</label>
                                <input type="text" id="catatanMutabaah" placeholder="Tuliskan catatan ibadah harian..." class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                            </div>
                        </div>
                    </div>

                    <!-- ASPEK 4: ADAB & KARAKTER (RUBRIK EVALUASI MATERI) -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
                        <div class="flex items-center justify-between border-b border-purple-100 pb-3">
                            <h4 class="text-xs font-extrabold text-purple-900 uppercase flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-800 flex items-center justify-center font-bold text-xs">4</span>
                                Aspek Adab & Karakter (Berdasarkan Rubrik Materi Format Excel)
                            </h4>
                            <span id="rubrikPredikatBadge" class="text-xs font-extrabold px-3 py-1 bg-purple-100 text-purple-800 rounded-full border border-purple-200">Sangat Baik (A)</span>
                        </div>

                        <!-- DISPLAY TOPIC MATERI CONTENT INFO -->
                        <div class="bg-purple-50/60 p-4 rounded-xl border border-purple-100 space-y-2">
                            <div class="flex items-center justify-between">
                                <strong id="cardMateriTitle" class="text-xs font-bold text-purple-950">7. Adab Kepada Allah Ã¯Â·Â»</strong>
                                <span id="cardMateriMeta" class="text-[10px] font-bold px-2 py-0.5 bg-purple-200 text-purple-900 rounded">Kelas 7 | Pekan 1</span>
                            </div>
                            <p id="cardMateriDesc" class="text-xs text-purple-900/80 italic">Pembinaan adab mencakup niat ikhlas, pelaksanaan ibadah harian, dan kepatuhan.</p>
                        </div>

                        <!-- INSPEKSI ISIAN SISWA BY MENTOR -->
                        <div id="mentorInspectSiswaCard">
                            <!-- Populated dynamically via JS -->
                        </div>

                        <!-- INPUT SKOR RUBRIK FORMULA ADAB -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs pt-2">
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-1">
                                <label class="block font-bold text-slate-700 text-[11px]">Skor Penugasan Bagian A (0 - 15 Poin)</label>
                                <input type="number" id="skorPenugasanAdab" min="0" max="15" step="1" value="12" onchange="calculateRubrikAdabScore()" onkeyup="calculateRubrikAdabScore()" class="w-full p-2 bg-white border border-slate-300 rounded-lg font-bold text-center text-sm">
                                <span class="text-[10px] text-slate-400 block">4 Hari Checklist (Selasa, Rabu, Kamis, Jumat)</span>
                            </div>

                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-1">
                                <label class="block font-bold text-slate-700 text-[11px]">Skor Refleksi Bagian B (0 - 100 Poin)</label>
                                <input type="number" id="skorRefleksiAdab" min="0" max="100" step="1" value="85" onchange="calculateRubrikAdabScore()" onkeyup="calculateRubrikAdabScore()" class="w-full p-2 bg-white border border-slate-300 rounded-lg font-bold text-center text-sm">
                                <span class="text-[10px] text-slate-400 block">4 Pertanyaan Refleksi Adab</span>
                            </div>

                            <div class="bg-purple-50 p-3 rounded-xl border border-purple-200 space-y-1">
                                <label class="block font-extrabold text-purple-900 text-[11px]">Hasil Skor Total Adab (Skala 1 - 10)</label>
                                <input type="number" id="skorAdab" readonly value="8.5" class="w-full p-2 bg-white border border-purple-300 rounded-lg font-black text-center text-purple-900 text-sm">
                                <span class="text-[10px] text-purple-700 block font-semibold">Konversi Otomatis Rubrik Excel</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Evaluasi Adab & Bimbingan Mentor</label>
                            <textarea id="catatanAdab" rows="2" placeholder="Tuliskan saran dan nasihat perbaikan adab untuk murid..." class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs"></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="px-8 py-3 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-extrabold shadow-lg shadow-brand-600/30 transition flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Simpan Sesi Mentoring</span>
                    </button>
                </div>
            </form>
        </section>


        <!-- ================= TAB 3: LAPORAN & CETAK PDF ================= -->
        <section id="report-tab" class="tab-content hidden space-y-6">
            <div class="no-print bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-700 shrink-0"><i class="fa-solid fa-magnifying-glass text-brand-600 mr-1"></i>Pencarian:</label>
                        <input type="text" id="reportSearchInput" oninput="filterReportBySearch()" placeholder="Ã°Å¸â€Â Ketik nama murid / mentor..." class="p-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold focus:ring-2 focus:ring-brand-500 w-52">
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-700 shrink-0"><i class="fa-solid fa-user-graduate text-brand-600 mr-1"></i>Murid:</label>
                        <select id="reportFilterMenteeSelect" onchange="renderStudentReportDetail()" class="p-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold focus:ring-2 focus:ring-brand-500">
                            <!-- Options populated dynamically -->
                        </select>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-700 shrink-0"><i class="fa-solid fa-calendar-days text-brand-600 mr-1"></i>Bulan:</label>
                        <select id="reportFilterBulanSelect" onchange="renderStudentReportDetail()" class="p-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold focus:ring-2 focus:ring-brand-500">
                            <option value="ALL">-- Semua Bulan --</option>
                            <option value="Januari">Januari</option>
                            <option value="Februari">Februari</option>
                            <option value="Maret">Maret</option>
                            <option value="April">April</option>
                            <option value="Mei">Mei</option>
                            <option value="Juni">Juni</option>
                            <option value="Juli">Juli</option>
                            <option value="Agustus">Agustus</option>
                            <option value="September" selected>September</option>
                            <option value="Oktober">Oktober</option>
                            <option value="November">November</option>
                            <option value="Desember">Desember</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-xs font-bold text-slate-700 shrink-0"><i class="fa-solid fa-calendar text-brand-600 mr-1"></i>Tahun:</label>
                        <select id="reportFilterTahunSelect" onchange="renderStudentReportDetail()" class="p-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold focus:ring-2 focus:ring-brand-500">
                            <option value="ALL">-- Semua Tahun --</option>
                            <option value="2024">2024</option>
                            <option value="2025">2025</option>
                            <option value="2026" selected>2026</option>
                            <option value="2027">2027</option>
                            <option value="2028">2028</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 flex-wrap w-full md:w-auto justify-end">
                    <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-print"></i> Cetak Raport Murid (A4)
                    </button>
                    <button type="button" onclick="printRekapPDF('umum')" class="px-3.5 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-extrabold shadow transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-file-pdf"></i> Cetak Rekap Umum
                    </button>
                    <button type="button" onclick="printRekapPDF('mentor')" class="px-3.5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-extrabold shadow transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-user-tie"></i> Cetak Rekap Per Mentor
                    </button>
                </div>
            </div>

            <!-- PRINTABLE A4 SINGLE-PAGE REPORT CONTAINER -->
            <div class="bg-white p-6 rounded-2xl border border-slate-300 shadow-md space-y-3.5 max-w-4xl mx-auto print:shadow-none print:border-none print:p-0">
                
                <!-- REPORT HEADER KOP SEKOLAH -->
                <div class="flex items-center justify-between border-b-2 border-slate-900 pb-2.5">
                    <div class="flex items-center gap-3">
                        <img src="data:image/png;base64,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" alt="Logo SMP An Nahl" class="h-12 w-auto object-contain">
                        <div>
                            <h2 class="text-sm font-black text-slate-900 uppercase tracking-tight leading-tight">LAPORAN EVALUASI MENTORING MIP</h2>
                            <h3 class="text-[11px] font-bold text-slate-700 leading-tight">SMP AN NAHL ISLAMIC SCHOOL</h3>
                            <p class="text-[9px] text-slate-500">Tahun Ajaran 2026/2027 - Sistem Evaluasi 4 Aspek Pembinaan</p>
                        </div>
                    </div>
                    <div class="text-right text-[11px]">
                        <span class="px-2.5 py-0.5 bg-slate-100 text-slate-800 border border-slate-300 rounded-full font-bold">Raport Mentoring</span>
                    </div>
                </div>

                <!-- REPORT STUDENT METADATA -->
                <div class="grid grid-cols-2 gap-2 text-[11px] bg-slate-50 p-2.5 rounded-xl border border-slate-200">
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase">Nama Murid / Siswa:</span>
                        <h4 id="rptNamaMentee" class="text-xs font-extrabold text-slate-900">Abdullah Faruq</h4>
                    </div>
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase">NIS / Kelas:</span>
                        <h4 id="rptNisKelas" class="text-xs font-extrabold text-slate-900">1001 / 7A</h4>
                    </div>
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase">Mentor Pembimbing:</span>
                        <h4 id="rptNamaMentor" class="text-xs font-extrabold text-slate-900">Ust. Ahmad Syahputra, S.Pd.</h4>
                    </div>
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase">Periode Laporan:</span>
                        <h4 id="rptPeriodeText" class="text-xs font-extrabold text-slate-900">Pekan 1 s/d Pekan 4 (September 2026)</h4>
                    </div>
                </div>

                <!-- TABLE EVALUASI 4 ASPEK MENTORING -->
                <div class="space-y-1">
                    <h4 class="text-[11px] font-extrabold text-slate-900 uppercase">A. RINCIAN EVALUASI 4 ASPEK MENTORING</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[10px] text-left border-collapse border border-slate-300">
                            <thead class="bg-slate-100 text-slate-800 font-bold text-center">
                                <tr>
                                    <th class="p-1.5 border border-slate-300 text-left">Aspek Evaluasi Mentoring</th>
                                    <th class="p-1.5 border border-slate-300 w-14">Pekan 1</th>
                                    <th class="p-1.5 border border-slate-300 w-14">Pekan 2</th>
                                    <th class="p-1.5 border border-slate-300 w-14">Pekan 3</th>
                                    <th class="p-1.5 border border-slate-300 w-14">Pekan 4</th>
                                    <th class="p-1.5 border border-slate-300 text-left">Catatan Mentor & Advice</th>
                                </tr>
                            </thead>
                            <tbody id="rptAspectsTableBody" class="divide-y divide-slate-200">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- DIAGRAM VISUAL BAR GRAPH & TREND ANALYSIS IN REPORT -->
                <div class="space-y-1 pt-1">
                    <h4 class="text-[11px] font-extrabold text-slate-900 uppercase">B. DIAGRAM GRAFIK PERKEMBANGAN & TENDENSI CAPAIAN 4 ASPEK</h4>
                    <div id="rptAspectsBarDiagram" class="grid grid-cols-1 md:grid-cols-4 gap-2 text-left">
                        <!-- Rendered dynamically -->
                    </div>
                </div>

                <!-- REPORT COMPACT REFLEKSI SUMMARY (2x2 GRID FOR 1-PAGE FIT) -->
                <div class="space-y-1 pt-1">
                    <h4 class="text-[11px] font-extrabold text-slate-900 uppercase">C. HASIL ISIAN REFLEKSI ADAB & KARAKTER MURID</h4>
                    
                    <div class="grid grid-cols-2 gap-2 text-[10px]">
                        <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                            <strong class="block text-slate-800 font-bold mb-0.5">1. Aksi Nyata Adab:</strong>
                            <p id="rptRefleksi1" class="text-slate-600 italic">--</p>
                        </div>

                        <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                            <strong class="block text-slate-800 font-bold mb-0.5">2. Hambatan & Tantangan:</strong>
                            <p id="rptRefleksi2" class="text-slate-600 italic">--</p>
                        </div>

                        <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                            <strong class="block text-slate-800 font-bold mb-0.5">3. Solusi & Rencana Perbaikan:</strong>
                            <p id="rptRefleksi3" class="text-slate-600 italic">--</p>
                        </div>

                        <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                            <strong class="block text-slate-800 font-bold mb-0.5">4. Catatan Syukur & Refleksi Harian:</strong>
                            <p id="rptRefleksi4" class="text-slate-600 italic">--</p>
                        </div>
                    </div>
                </div>

                <!-- SIGNATURE BLOCK ("Mentor," instead of "Mentor Pengampu,") -->
                <div class="flex justify-between items-end pt-3 px-6 text-center text-[10px] border-t border-slate-200 mt-2 print-break-inside-avoid">
                    <div>
                        <p class="mb-10 font-semibold text-slate-700">Orang Tua / Wali Murid,</p>
                        <p class="font-bold text-slate-900 underline">( _______________________ )</p>
                    </div>
                    <div>
                        <p class="mb-0.5 text-slate-600">Bogor, <span id="rptTanggalTtd">15 September 2026</span></p>
                        <p class="mb-10 font-semibold text-slate-700">Mentor,</p>
                        <p class="font-bold text-slate-900 underline" id="rptMentorSignatureText">( <span id="rptTtdMentorNama">Ust. Mentor</span> )</p>
                    </div>
                </div>

            </div>
            <!-- PRINTABLE A4 REKAPITULASI SUMMARY CONTAINER (UMUM & PER MENTOR) -->
            <div id="printRekapPDFContainer" class="hidden bg-white p-6 rounded-2xl border border-slate-300 shadow-md space-y-4 max-w-4xl mx-auto print:block print:shadow-none print:border-none print:p-0">
                <!-- REKAP HEADER KOP SEKOLAH -->
                <div class="flex items-center justify-between border-b-2 border-slate-900 pb-3">
                    <div class="flex items-center gap-3">
                        <img src="data:image/png;base64,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" alt="Logo SMP An Nahl" class="h-12 w-auto object-contain">
                        <div>
                            <h2 class="text-sm font-black text-slate-900 uppercase tracking-tight leading-tight">LAPORAN REKAPITULASI CAPAIAN MENTORING MIP</h2>
                            <h3 class="text-[11px] font-bold text-slate-700 leading-tight">SMP AN NAHL ISLAMIC SCHOOL</h3>
                            <p class="text-[9px] text-slate-500">Sistem Evaluasi 4 Aspek Pembinaan - Tahun Ajaran 2026/2027</p>
                        </div>
                    </div>
                    <div class="text-right text-[11px]">
                        <span id="prtRekapBadgeTitle" class="px-3 py-1 bg-brand-100 text-brand-800 border border-brand-300 rounded-full font-extrabold uppercase">REKAPITULASI UMUM</span>
                    </div>
                </div>

                <!-- METADATA BOX -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px] bg-slate-50 p-2.5 rounded-xl border border-slate-200">
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase block">Kategori Laporan:</span>
                        <h4 id="prtRekapJenisText" class="text-xs font-extrabold text-slate-900">Secara Umum (Semua Mentor)</h4>
                    </div>
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase block">Mentor Pembimbing:</span>
                        <h4 id="prtRekapMentorText" class="text-xs font-extrabold text-slate-900">Semua Mentor</h4>
                    </div>
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase block">Total Murid & Sesi:</span>
                        <h4 id="prtRekapTotalMenteeSesi" class="text-xs font-extrabold text-slate-900">0 Murid / 0 Sesi</h4>
                    </div>
                    <div>
                        <span class="text-[9px] font-bold text-slate-500 uppercase block">Tanggal Cetak Laporan:</span>
                        <h4 id="prtRekapTanggalCetak" class="text-xs font-extrabold text-slate-900">15 September 2026</h4>
                    </div>
                </div>

                <!-- STAT CARDS IN REKAP PRINT -->
                <div class="grid grid-cols-4 gap-2 text-[10px]">
                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-200 text-center">
                        <span class="text-[9px] font-bold text-slate-500 uppercase block">Total Murid</span>
                        <strong id="prtRekapStatMentee" class="text-sm font-extrabold text-slate-900">0</strong>
                    </div>
                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-200 text-center">
                        <span class="text-[9px] font-bold text-slate-500 uppercase block">Total Sesi Terisi</span>
                        <strong id="prtRekapStatSesi" class="text-sm font-extrabold text-slate-900">0</strong>
                    </div>
                    <div class="bg-emerald-50 p-2 rounded-lg border border-emerald-200 text-center">
                        <span class="text-[9px] font-bold text-emerald-800 uppercase block">Rata-Rata Adab & MIP</span>
                        <strong id="prtRekapStatAvg" class="text-sm font-extrabold text-emerald-950">0.0</strong>
                    </div>
                    <div class="bg-purple-50 p-2 rounded-lg border border-purple-200 text-center">
                        <span class="text-[9px] font-bold text-purple-800 uppercase block">Predikat Capaian</span>
                        <strong id="prtRekapStatGrade" class="text-sm font-extrabold text-purple-950">Sangat Baik (A)</strong>
                    </div>
                </div>

                <!-- DIAGRAM ASPECT PROGRESS IN REKAP PRINT -->
                <div class="space-y-1">
                    <h4 class="text-[11px] font-extrabold text-slate-900 uppercase"><i class="fa-solid fa-chart-bar text-brand-600 mr-1"></i> DIAGRAM PENCAPAIAN KUMULATIF 4 ASPEK MENTORING</h4>
                    <div id="prtRekapAspectsBarDiagram" class="grid grid-cols-4 gap-2 text-left">
                        <!-- Populated dynamically -->
                    </div>
                </div>

                <!-- TABEL MATRIKS REKAPITULASI PRINT -->
                <div class="space-y-1 pt-1">
                    <h4 id="prtRekapTableTitle" class="text-[11px] font-extrabold text-slate-900 uppercase"><i class="fa-solid fa-table text-brand-600 mr-1"></i> TABEL RINGKASAN HASIL EVALUASI SISWA</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[9.5pt] text-left border-collapse border border-slate-300">
                            <thead class="bg-slate-100 text-slate-800 font-bold text-center text-[9pt]">
                                <tr>
                                    <th class="p-1.5 border border-slate-300 w-8">No</th>
                                    <th class="p-1.5 border border-slate-300">NIS</th>
                                    <th class="p-1.5 border border-slate-300 text-left">Nama Murid / Siswa</th>
                                    <th class="p-1.5 border border-slate-300">Kelas</th>
                                    <th class="p-1.5 border border-slate-300 text-left">Mentor</th>
                                    <th class="p-1.5 border border-slate-300 w-12">1. MIP</th>
                                    <th class="p-1.5 border border-slate-300 w-12">2. Akad</th>
                                    <th class="p-1.5 border border-slate-300 w-12">3. Mutab</th>
                                    <th class="p-1.5 border border-slate-300 w-12">4. Adab</th>
                                    <th class="p-1.5 border border-slate-300 w-14">Rata2</th>
                                    <th class="p-1.5 border border-slate-300 text-center">Predikat</th>
                                </tr>
                            </thead>
                            <tbody id="prtRekapTableBody" class="divide-y divide-slate-200">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SIGNATURE BLOCK -->
                <div class="flex justify-between items-end pt-4 px-6 text-center text-[10px] border-t border-slate-200 mt-3 print-break-inside-avoid">
                    <div>
                        <p class="mb-10 font-semibold text-slate-700">Orang Tua / Wali Murid,</p>
                        <p class="font-bold text-slate-900 underline">( _______________________ )</p>
                    </div>
                    <div>
                        <p class="mb-0.5 text-slate-600">Bogor, <span id="prtRekapTtdTanggal">15 September 2026</span></p>
                        <p class="mb-10 font-semibold text-slate-700" id="prtRekapTtdJabatan">Mentor Pembimbing,</p>
                        <p class="font-bold text-slate-900 underline" id="prtRekapTtdNama">( Ust. Ahmad / Tim Mentoring )</p>
                    </div>
                </div>
            </div>


        <!-- ================= TAB 4: MATRIKS REKAPITULASI ================= -->
        <section id="rekap-tab" class="tab-content hidden space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-b border-slate-200 pb-4">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 flex items-center gap-2">
                            <i class="fa-solid fa-table text-brand-600"></i> Matriks Rekapitulasi Sesi Mentoring
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Daftar lengkap hasil pengisian jurnal mentoring oleh mentor.</p>
                    </div>
                    
                    <div class="flex items-center gap-2 flex-wrap">
                        <select id="rekapFilterMenteeSelect" onchange="renderRekapMatrixTable()" class="p-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold">
                            <option value="">-- Semua Murid --</option>
                        </select>
                        <span id="rekapCounterBadge" class="px-3 py-1 bg-brand-50 text-brand-800 border border-brand-200 rounded-full text-xs font-extrabold">0 Data</span>
                        <button type="button" onclick="printRekapPDF('umum')" class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-extrabold shadow transition flex items-center gap-1 cursor-pointer">
                            <i class="fa-solid fa-file-pdf"></i> Cetak Rekap Umum
                        </button>
                        <button type="button" onclick="printRekapPDF('mentor')" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-extrabold shadow transition flex items-center gap-1 cursor-pointer">
                            <i class="fa-solid fa-user-tie"></i> Cetak Rekap Mentor
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-xs bg-white rounded-xl border border-slate-200 overflow-hidden">
                        <thead class="bg-slate-100 text-slate-700 font-bold uppercase text-[10px]">
                            <tr>
                                <th class="p-3 border-b border-r">No</th>
                                <th class="p-3 border-b border-r">Tanggal & Pekan</th>
                                <th class="p-3 border-b border-r">Nama Murid</th>
                                <th class="p-3 border-b border-r">Mentor</th>
                                <th class="p-3 border-b border-r text-center">1. MIP</th>
                                <th class="p-3 border-b border-r text-center">2. Akad</th>
                                <th class="p-3 border-b border-r text-center">3. Mutabaah</th>
                                <th class="p-3 border-b border-r text-center">4. Adab</th>
                                <th class="p-3 border-b text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="rekapMatrixTbody" class="divide-y divide-slate-200">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </section>


        <!-- ================= TAB 5: BANK MATERI ADAB ================= -->
        <section id="bank-tab" class="tab-content hidden space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                <div class="border-b border-slate-200 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 flex items-center gap-2">
                            <i class="fa-solid fa-book-bookmark text-purple-600"></i> Bank Materi Adab & Karakter
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Kelola kurikulum materi adab per Kelas (7, 8, 9) dan Pekan (1 - 4).</p>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" onclick="openModalTambahMateri()" class="px-3.5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold shadow transition flex items-center gap-1.5">
                            <i class="fa-solid fa-plus-circle"></i> Tambah Materi Manual
                        </button>
                        <button type="button" onclick="triggerExcelUpload()" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow transition flex items-center gap-1.5">
                            <i class="fa-solid fa-file-excel"></i> Upload via Excel / CSV
                        </button>
                        <input type="file" id="excelFileInput" accept=".csv, .xlsx, .xls" class="hidden" onchange="handleExcelFileUpload(event)">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 bg-purple-50/60 rounded-xl border border-purple-100">
                    <div>
                        <label class="block text-[10px] font-bold text-purple-900 uppercase mb-1">Filter Kelas</label>
                        <select id="materiFilterKelas" onchange="renderBankMateriCards()" class="w-full p-2 bg-white border border-purple-200 rounded-lg text-xs font-semibold text-purple-900">
                            <option value="Semua">Semua Kelas (7, 8, 9)</option>
                            <option value="7">Kelas 7</option>
                            <option value="8">Kelas 8</option>
                            <option value="9">Kelas 9</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-purple-900 uppercase mb-1">Filter Pekan</label>
                        <select id="materiFilterPekan" onchange="renderBankMateriCards()" class="w-full p-2 bg-white border border-purple-200 rounded-lg text-xs font-semibold text-purple-900">
                            <option value="Semua">Semua Pekan (1 - 4)</option>
                            <option value="Pekan 1">Pekan 1</option>
                            <option value="Pekan 2">Pekan 2</option>
                            <option value="Pekan 3">Pekan 3</option>
                            <option value="Pekan 4">Pekan 4</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="button" onclick="downloadTemplateExcelMateri()" class="w-full p-2 bg-white hover:bg-purple-100 border border-purple-300 text-purple-800 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1">
                            <i class="fa-solid fa-download"></i> Download Template Excel
                        </button>
                    </div>
                </div>

                <div id="materiCardsList" class="space-y-3">
                    <!-- Populated dynamically -->
                </div>
            </div>
        </section>


        <!-- ================= TAB 6: PENGATURAN ADMIN FULL CONTROL CENTER ================= -->
        <section id="settings-tab" class="tab-content hidden space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                <div class="border-b border-slate-200 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 flex items-center gap-2">
                            <i class="fa-solid fa-sliders text-brand-600"></i> Pengaturan Sistem & Administrative Control Center (Admin Only)
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Kelola identitas sekolah, akun mentor, master data murid, dan kurikulum aplikasi.</p>
                    </div>
                    <span class="px-3 py-1 bg-purple-100 text-purple-800 border border-purple-200 rounded-full text-xs font-extrabold">Akses Penuh Admin</span>
                </div>

                <!-- CARD 1: IDENTITAS SEKOLAH & SISTEM -->
                <form id="formSettingsAdmin" onsubmit="handleSaveSettings(event)" class="space-y-4">
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 space-y-4">
                        <h3 class="text-xs font-extrabold text-slate-800 uppercase border-b border-slate-200 pb-2 flex items-center gap-2">
                            <i class="fa-solid fa-school text-brand-600"></i> 1. Identitas Sekolah & Tahun Ajaran
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Nama Sekolah</label>
                                <input type="text" id="cfgNamaSekolah" value="SMP An Nahl Islamic School" required class="w-full p-2.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Kepala Sekolah / Koordinator</label>
                                <input type="text" id="cfgKepalaSekolah" value="Awang Darmawan, SS." required class="w-full p-2.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Tahun Ajaran Aktif</label>
                                <input type="text" id="cfgTahunAjaran" value="2026/2027" required class="w-full p-2.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800">
                            </div>
                        </div>
                    </div>
                </form>

                <!-- CARD 2: KELOLA ACCOUNT USER & MENTOR -->
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                        <h3 class="text-xs font-extrabold text-slate-800 uppercase flex items-center gap-2">
                            <i class="fa-solid fa-user-gear text-purple-600"></i> 2. Kelola User Account Mentor & Guru
                        </h3>
                        <button type="button" onclick="openModalTambahUser()" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1">
                            <i class="fa-solid fa-user-plus"></i> Tambah Account Mentor
                        </button>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-xs bg-white rounded-xl border border-slate-200 overflow-hidden">
                            <thead class="bg-slate-100 text-slate-700 font-bold uppercase text-[10px]">
                                <tr>
                                    <th class="p-2.5 border-b">No</th>
                                    <th class="p-2.5 border-b">Username / Nama Mentor</th>
                                    <th class="p-2.5 border-b">Role Access</th>
                                    <th class="p-2.5 border-b">Assigned Kelas</th>
                                    <th class="p-2.5 border-b text-center">Aksi (Kelola Mentor & Password)</th>
                                </tr>
                            </thead>
                            <tbody id="adminUsersTableBody" class="divide-y divide-slate-200">
                                <!-- Populated dynamically via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- CARD 3: KELOLA MASTER DATA SISWA & MENTEE -->
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 space-y-4">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between border-b border-slate-200 pb-2 gap-2">
                        <h3 class="text-xs font-extrabold text-slate-800 uppercase flex items-center gap-2">
                            <i class="fa-solid fa-users-gear text-teal-600"></i> 3. Kelola Master Data Murid & Pembagian Mentor
                        </h3>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="openModalTransferBulkMentee()" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1">
                                <i class="fa-solid fa-arrow-right-arrow-left"></i> Transfer Bulk Mentee
                            </button>
                            <button type="button" onclick="openModalTambahSiswa()" class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1">
                                <i class="fa-solid fa-plus-circle"></i> Tambah Murid Baru
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar max-h-60">
                        <table class="w-full text-left text-xs bg-white rounded-xl border border-slate-200 overflow-hidden">
                            <thead class="bg-slate-100 text-slate-700 font-bold uppercase text-[10px] sticky top-0">
                                <tr>
                                    <th class="p-2.5 border-b">No</th>
                                    <th class="p-2.5 border-b">NIS</th>
                                    <th class="p-2.5 border-b">Nama Murid</th>
                                    <th class="p-2.5 border-b">Kelas</th>
                                    <th class="p-2.5 border-b">Pembimbing Mentor</th>
                                    <th class="p-2.5 border-b text-center">Aksi (Pindah Mentor & Reset)</th>
                                </tr>
                            </thead>
                            <tbody id="adminSiswaTableBody" class="divide-y divide-slate-200">
                                <!-- Populated dynamically via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- CARD 4: ACTIONS & EXPORT DATA -->
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <strong class="block text-xs font-bold text-slate-800">Pemeliharaan Data & Backup Spreadsheet</strong>
                        <p class="text-[11px] text-slate-500">Sinkronkan data lokal dengan Google Sheets atau unduh backup rekap mentoring.</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" onclick="reloadAllSheetDataFromGoogle()" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                            <i class="fa-solid fa-rotate-right"></i> Reload Data Sheet
                        </button>
                        <button type="button" onclick="exportRekapToExcelCSV()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                            <i class="fa-solid fa-file-excel"></i> Export All Rekap CSV
                        </button>
                    </div>
                </div>
            </div>
        </section>


        <!-- ================= TAB 7: PORTAL KHUSUS SISWA / MURID ================= -->
        <section id="siswa-tab" class="tab-content hidden space-y-6">
            <!-- Student Header Profile Banner -->
            <div class="bg-gradient-to-r from-teal-700 to-emerald-700 text-white rounded-2xl p-6 shadow-md flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur border border-white/30 flex items-center justify-center text-2xl font-bold">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-teal-200">PORTAL PENUGASAN & REFLEKSI ADAB MURID</span>
                        <h2 id="siswaPortalNama" class="text-lg font-extrabold">Nama Murid</h2>
                        <div class="flex items-center gap-3 mt-1 text-xs text-teal-100 flex-wrap">
                            <span><i class="fa-solid fa-id-card mr-1"></i> NIS: <strong id="siswaPortalNis">--</strong></span>
                            <span><i class="fa-solid fa-school mr-1"></i> Kelas: <strong id="siswaPortalKelas">--</strong></span>
                            <span><i class="fa-solid fa-user-tie mr-1"></i> Mentor: <strong id="siswaPortalMentor">--</strong></span>
                        </div>
                    </div>
                </div>
                <button onclick="logoutApp()" class="px-4 py-2 bg-red-500/80 hover:bg-red-600 text-white rounded-xl text-xs font-bold shadow transition flex items-center gap-1.5">
                    <i class="fa-solid fa-power-off"></i> Logout Siswa
                </button>
            </div>

            <!-- MENTOR SCORE & FEEDBACK BANNER FOR SISWA -->
            <div id="siswaMentorScoreBanner">
                <!-- Rendered dynamically via JS -->
            </div>

            <form id="formAdabSiswa" onsubmit="handleSaveAdabSiswa(event)" class="space-y-6">
                <!-- FILTER BULAN, PEKAN, KELAS & HARI FOR SISWA -->
                <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Filter Bulan Pengisian</label>
                        <select id="siswaBulanSelect" onchange="renderSiswaPortal()" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-800">
                            <option value="Juli">Juli</option>
                            <option value="Agustus">Agustus</option>
                            <option value="September" selected>September</option>
                            <option value="Oktober">Oktober</option>
                            <option value="November">November</option>
                            <option value="Desember">Desember</option>
                            <option value="Januari">Januari</option>
                            <option value="Februari">Februari</option>
                            <option value="Maret">Maret</option>
                            <option value="April">April</option>
                            <option value="Mei">Mei</option>
                            <option value="Juni">Juni</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Pilihan Pekan Penugasan</label>
                        <select id="siswaPekanSelect" onchange="renderSiswaPortal()" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-800">
                            <option value="Pekan 1">Pekan 1</option>
                            <option value="Pekan 2">Pekan 2</option>
                            <option value="Pekan 3">Pekan 3</option>
                            <option value="Pekan 4">Pekan 4</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Filter Materi Kelas</label>
                        <select id="siswaKelasSelect" onchange="renderSiswaPortal()" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-800">
                            <option value="7">Kelas 7</option>
                            <option value="8">Kelas 8</option>
                            <option value="9">Kelas 9</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 uppercase mb-1">Pilihan Hari Checklist (Selasa - Jumat)</label>
                        <div class="grid grid-cols-4 gap-1">
                            <button type="button" id="btnHariSelasa" onclick="switchSiswaHari('Selasa')" class="py-2 rounded-xl text-[11px] font-bold border transition bg-teal-600 text-white border-teal-600 shadow-sm">
                                Selasa
                            </button>
                            <button type="button" id="btnHariRabu" onclick="switchSiswaHari('Rabu')" class="py-2 rounded-xl text-[11px] font-bold border transition bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200">
                                Rabu
                            </button>
                            <button type="button" id="btnHariKamis" onclick="switchSiswaHari('Kamis')" class="py-2 rounded-xl text-[11px] font-bold border transition bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200">
                                Kamis
                            </button>
                            <button type="button" id="btnHariJumat" onclick="switchSiswaHari('Jumat')" class="py-2 rounded-xl text-[11px] font-bold border transition bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200">
                                Jumat
                            </button>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN A: PENUGASAN ADAB -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                        <div>
                            <h3 class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">
                                <i class="fa-solid fa-square-check text-teal-600 mr-1.5"></i> Bagian A: Checklist Penugasan Adab (<span id="siswaHariText">Hari Selasa</span>)
                            </h3>
                            <p class="text-[11px] text-slate-500 mt-0.5">Checklist amalan harian sesuai materi adab pekan ini (Selasa - Jumat).</p>
                        </div>
                        <span id="siswaChecklistProgress" class="text-xs font-bold px-3 py-1 bg-teal-100 text-teal-800 rounded-full border border-teal-200">Progress: 0 / 4 Terisi</span>
                    </div>

                    <div class="space-y-3" id="siswaChecklistContainer">
                        <!-- Rendered dynamically -->
                    </div>
                </div>

                <!-- BAGIAN B: REFLEKSI ADAB & KARAKTER -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <div class="border-b border-slate-200 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <h3 class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">
                                <i class="fa-solid fa-brain text-emerald-600 mr-1.5"></i> Bagian B: Jawaban Refleksi Adab & Karakter
                            </h3>
                        </div>
                        <span id="siswaMateriBadge" class="text-[10px] bg-purple-100 text-purple-800 font-bold px-2.5 py-1 rounded-lg border border-purple-200">Materi: Adab Kepada Allah Ã¯Â·Â»</span>
                    </div>

                    <div class="space-y-4" id="siswaRefleksiContainer">
                        <!-- Rendered dynamically via JS -->
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-8 py-3 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-xs font-extrabold shadow-lg shadow-teal-600/30 transition flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Simpan Penugasan & Refleksi Adab Murid</span>
                    </button>
                </div>
            </form>
        </section>

    </main>

    <!-- MODAL LUPA PASSWORD MENTOR -->
    <div id="modalLupaPasswordMentor" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-md w-full p-6 space-y-4 text-center">
            <div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center mx-auto text-2xl font-black shadow-sm">
                <i class="fa-solid fa-key"></i>
            </div>
            <div>
                <h3 class="text-base font-extrabold text-slate-900">Lupa Password Access Mentor?</h3>
                <p class="text-xs text-slate-600 leading-relaxed mt-1">
                    Apabila Anda lupa password untuk login ke Portal Mentor/Admin, silakan hubungi <strong>Administrator (Admin) Sekolah / Tim MIP</strong>.
                </p>
            </div>
            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 text-xs text-slate-700 space-y-1.5 font-medium text-left">
                <div class="flex items-center gap-2 text-slate-800 font-bold border-b border-slate-200 pb-1">
                    <i class="fa-solid fa-shield-halved text-brand-600"></i> Cara Reset Password Mentor:
                </div>
                <p class="text-[11px] text-slate-600 leading-relaxed">
                    1. Hubungi Admin MIP Sekolah untuk melakukan reset password.<br>
                    2. Admin akan mereset password akun Anda melalui menu <strong>Pengaturan Aplikasi -&gt; Pengaturan Users</strong>.<br>
                    3. Setelah di-reset oleh Admin, gunakan password baru untuk login kembali.
                </p>
            </div>
            <button type="button" onclick="closeModalLupaPasswordMentor()" class="w-full py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-extrabold shadow-md transition">
                Tutup &amp; Kembali Ke Login
            </button>
        </div>
    </div>

    <!-- MODAL TAMBAH MATERI MANUAL -->
    <div id="modalTambahMateri" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl shadow-2xl border border-purple-100 max-w-2xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
            <div class="flex items-center justify-between border-b border-purple-100 pb-3">
                <h3 id="modalMateriHeaderTitle" class="text-sm font-extrabold text-purple-900 flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle text-purple-600"></i> Tambah Materi Adab & Penugasan Baru
                </h3>
                <button onclick="closeModalTambahMateri()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form id="formTambahMateri" onsubmit="handleSaveTambahMateri(event)" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block font-bold text-slate-700 mb-1">Judul Materi Adab</label>
                        <input type="text" id="materiInputJudul" placeholder="Contoh: 7. Adab Kepada Allah Ã¯Â·Â»" required class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Peruntukan Kelas</label>
                        <select id="materiInputKelas" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg font-bold">
                            <option value="Semua">Semua Kelas (7,8,9)</option>
                            <option value="7">Kelas 7</option>
                            <option value="8">Kelas 8</option>
                            <option value="9">Kelas 9</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Target Pekan</label>
                        <select id="materiInputPekan" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg font-bold">
                            <option value="Pekan 1">Pekan 1</option>
                            <option value="Pekan 2">Pekan 2</option>
                            <option value="Pekan 3">Pekan 3</option>
                            <option value="Pekan 4">Pekan 4</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Link File / Download Materi (Optional)</label>
                        <input type="text" id="materiInputFileUrl" placeholder="https://..." class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Deskripsi & Tujuan Pembelajaran</label>
                    <textarea id="materiInputDeskripsi" rows="2" placeholder="Tuliskan rangkuman materi..." required class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg"></textarea>
                </div>

                <div class="bg-teal-50 p-3.5 rounded-xl border border-teal-200 space-y-2.5">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h4 class="text-xs font-bold text-teal-900 uppercase flex items-center gap-1.5">
                            <i class="fa-solid fa-list-check"></i> Bagian A: Checklist Penugasan Adab (Selasa s/d Jumat)
                        </h4>
                        <button type="button" onclick="addMateriTaskRow()" class="px-2.5 py-1 bg-teal-600 hover:bg-teal-700 text-white text-[11px] font-bold rounded-lg shadow-sm transition flex items-center gap-1">
                            <i class="fa-solid fa-plus-circle"></i> Tambah Kolom Penugasan
                        </button>
                    </div>
                    <div class="space-y-2" id="materiTasksListContainer">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <div class="bg-purple-50 p-3.5 rounded-xl border border-purple-200 space-y-2.5">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h4 class="text-xs font-bold text-purple-900 uppercase flex items-center gap-1.5">
                            <i class="fa-solid fa-brain"></i> Bagian B: Pertanyaan Refleksi Adab & Karakter
                        </h4>
                        <button type="button" onclick="addMateriRefRow()" class="px-2.5 py-1 bg-purple-600 hover:bg-purple-700 text-white text-[11px] font-bold rounded-lg shadow-sm transition flex items-center gap-1">
                            <i class="fa-solid fa-plus-circle"></i> Tambah Kolom Refleksi
                        </button>
                    </div>
                    <div class="space-y-2" id="materiRefsListContainer">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-purple-100">
                    <button type="button" onclick="closeModalTambahMateri()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition">Batal</button>
                    <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold rounded-lg shadow transition">Simpan Materi & Penugasan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL TAMBAH USER MENTOR (ADMIN) -->
    <div id="modalTambahUser" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-md w-full p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-sm font-extrabold text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-user-plus text-purple-600"></i> Tambah Account Mentor Baru
                </h3>
                <button onclick="closeModalTambahUser()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>
            <form onsubmit="handleSaveTambahUser(event)" class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nama Mentor / Username</label>
                    <input type="text" id="inputNewUsername" placeholder="Contoh: Ust. Ahmad Syahputra, S.Pd." required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Password Access</label>
                    <input type="password" id="inputNewPassword" value="mentor123" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Role / Akses</label>
                    <select id="inputNewRole" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                        <option value="mentor">Mentor / Guru Pembimbing</option>
                        <option value="admin">Administrator (Full Access)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kelas Binaan</label>
                    <input type="text" id="inputNewKelas" placeholder="Contoh: 7A" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                </div>
                <div class="flex items-center justify-end gap-2 pt-3">
                    <button type="button" onclick="closeModalTambahUser()" class="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-lg">Batal</button>
                    <button type="submit" class="px-5 py-2 bg-purple-600 text-white font-bold rounded-lg shadow">Simpan User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL TAMBAH MURID BARU (ADMIN) -->
    <div id="modalTambahSiswa" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-md w-full p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-sm font-extrabold text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-graduation-cap text-teal-600"></i> Tambah Data Murid Baru
                </h3>
                <button onclick="closeModalTambahSiswa()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>
            <form onsubmit="handleSaveTambahSiswa(event)" class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">NIS (Nomor Induk Siswa)</label>
                    <input type="text" id="inputNewNis" placeholder="Contoh: 262707001" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nama Lengkap Murid</label>
                    <input type="text" id="inputNewMentee" placeholder="Contoh: Farhan Syahputra" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kelas</label>
                    <input type="text" id="inputNewSiswaKelas" placeholder="Contoh: 7A" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Pilih Pembimbing / Mentor</label>
                    <select id="inputNewSiswaMentor" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                        <!-- Options populated dynamically -->
                    </select>
                </div>
                <div class="flex items-center justify-end gap-2 pt-3">
                    <button type="button" onclick="closeModalTambahSiswa()" class="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-lg">Batal</button>
                    <button type="submit" class="px-5 py-2 bg-teal-600 text-white font-bold rounded-lg shadow">Simpan Data Murid</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TOAST MODAL -->
    <div id="toast-modal" class="fixed bottom-5 right-5 z-50 hidden transition transform">
        <div id="toastContent" class="bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 text-xs border border-slate-700">
            <i id="toastIcon" class="fa-solid fa-circle-check text-emerald-400 text-base"></i>
            <span id="toastMessage">Pesan Notifikasi</span>
        </div>
    </div>


    <!-- ================= JAVASCRIPT APP LOGIC ================= -->
    <script>
        let editingMateriId = null;

        let MASTER_MENTOR_DATA = [
            { mentor: "Ust. Ahmad Syahputra, S.Pd.", menteeCount: 5, kelas: "7A", mentees: ["Abdullah Faruq", "Ahmad Zaky", "Ali Rayhan", "Faisal Amir", "Hamzah Mujahid"] },
            { mentor: "Ust. Fajar Kurniawan, M.Pd.", menteeCount: 5, kelas: "7B", mentees: ["Bilal Bin Rabah", "Danial Raziq", "Fakhri Al-Ghifari", "Hasan Al-Banna", "Husain As-Sajjad"] },
            { mentor: "Ust. Ridwan Hidayat, Lc.", menteeCount: 5, kelas: "8A", mentees: ["Aditya Pratama", "Bagus Setiawan", "Cakra Kirana", "Dimas Anggara", "Eko Prasetyo"] }
        ];

        let MASTER_SISWA_LIST = [
            { nis: "262707001", mentee: "Abdullah Faruq", kelas: "7A", mentor: "Ust. Ahmad Syahputra, S.Pd." },
            { nis: "262707002", mentee: "Ahmad Zaky", kelas: "7A", mentor: "Ust. Ahmad Syahputra, S.Pd." },
            { nis: "262707003", mentee: "Ali Rayhan", kelas: "7A", mentor: "Ust. Ahmad Syahputra, S.Pd." },
            { nis: "262707004", mentee: "Bilal Bin Rabah", kelas: "7B", mentor: "Ust. Fajar Kurniawan, M.Pd." },
            { nis: "262708001", mentee: "Aditya Pratama", kelas: "8A", mentor: "Ust. Ridwan Hidayat, Lc." }
        ];

        let BANK_MATERI_DATA = [
            {
                id: 1,
                judul: "7. Adab Kepada Allah Ã¯Â·Â»",
                kelas: "7",
                pekan: "Pekan 1",
                deskripsi: "Pembinaan adab mencakup niat ikhlas, pelaksanaan ibadah, dan penguatan karakter mulia.",
                penugasanTasks: [
                    "Niat ikhlas hanya mengharap ridha Allah Ã¯Â·Â» dalam setiap ibadah dan kegiatan harian.",
                    "Melaksanakan shalat wajib 5 waktu tepat waktu secara berjamaah di masjid/musholla.",
                    "Merutinkan tilawah & muroja'ah Al-Qur'an minimal 1 halaman / juz setiap hari.",
                    "Menjaga lisan dari perkataan kotor, bohong, ghibah, dan menyakiti perasaan orang lain."
                ],
                refleksiQuestions: [
                    "1. Aksi Nyata Adab: Sebutkan aksi nyata adab kepada Allah Ã¯Â·Â» yang telah kamu praktikkan pekan ini!",
                    "2. Hambatan & Tantangan: Apa hambatan terbesar yang kamu rasakan dalam menjaga kekhusyukan shalat?",
                    "3. Solusi & Rencana Perbaikan: Bagaimana rencanamu untuk meningkatkan kualitas ibadahmu esok hari?",
                    "4. Catatan Syukur: Tuliskan hal baik yang kamu pelajari dan syukuri hari ini berkaitan dengan adabmu!"
                ]
            },
            {
                id: 2,
                judul: "8. Adab Terhadap Rasulullah Ã¯Â·Âº",
                kelas: "8",
                pekan: "Pekan 2",
                deskripsi: "Mengamalkan sunnah harian, membaca shalawat, dan meneladani akhlak Rasulullah Ã¯Â·Âº.",
                penugasanTasks: [
                    "Membaca shalawat Nabi minimal 100x setiap hari.",
                    "Mengamalkan adab makan & minum sesuai sunnah.",
                    "Meneladani kejujuran dalam tutur kata dan perilaku.",
                    "Merutinkan dzikir pagi dan petang secara teratur."
                ],
                refleksiQuestions: [
                    "1. Aksi Nyata Adab: Sunnah harian Rasulullah Ã¯Â·Âº apa yang paling konsisten kamu jalankan pekan ini?",
                    "2. Hambatan & Tantangan: Tantangan apa yang kamu hadapi saat berusaha mengamalkan sunnah di pergaulan?",
                    "3. Solusi & Rencana Perbaikan: Langkah nyata apa yang akan kamu lakukan untuk makin meneladani Nabi?",
                    "4. Catatan Syukur: Ungkapkan rasa syukurmu atas petunjuk dan sunnah yang diajarkan Rasulullah Ã¯Â·Âº!"
                ]
            },
            {
                id: 3,
                judul: "9. Adab Kepada Orang Tua & Guru",
                kelas: "9",
                pekan: "Pekan 3",
                deskripsi: "Birrul walidain, menghormati guru, serta membantu tugas rumah.",
                penugasanTasks: [
                    "Mencium tangan dan meminta doa dari kedua orang tua.",
                    "Berbicara dengan nada lembut dan santun kepada orang tua & guru.",
                    "Membantu pekerjaan rumah tanpa diminta (merapikan kamar, mencuci piring).",
                    "Mendengarkan penjelasan guru di kelas dengan takzim."
                ],
                refleksiQuestions: [
                    "1. Aksi Nyata Adab: Penghormatan apa yang sudah kamu berikan kepada orang tua & guru pekan ini?",
                    "2. Hambatan & Tantangan: Apakah kamu pernah bersikap kurang sopan? Bagaimana caramu meminta maaf?",
                    "3. Solusi & Rencana Perbaikan: Komitmen apa yang akan kamu buat untuk membahagiakan orang tua & gurumu?",
                    "4. Catatan Syukur: Ungkapkan rasa syukurmu atas bimbingan orang tua dan guru selama ini!"
                ]
            },
            {
                id: 4,
                judul: "10. Adab Bergaul & Menjaga Lisan",
                kelas: "Semua",
                pekan: "Pekan 4",
                deskripsi: "Menjaga ukhuwah islamiyah dan lisan dalam pergaulan harian.",
                penugasanTasks: [
                    "Menyebarkan salam dan tersenyum kepada sesama.",
                    "Menghindari perkataan kotor, kasar, ghibah, dan bohong.",
                    "Saling menolong dalam kebaikan dan takwa.",
                    "Memaafkan kesalahan teman dan menjaga rahasia."
                ],
                refleksiQuestions: [
                    "1. Aksi Nyata Adab: Aksi nyata apa yang kamu lakukan untuk menjaga lisan dan kerukunan dengan teman?",
                    "2. Hambatan & Tantangan: Apa emosi atau hambatan saat menghadapi teman yang berselisih padamu?",
                    "3. Solusi & Rencana Perbaikan: Bagaimana rencanamu untuk selalu berkata baik dan sopan esok hari?",
                    "4. Catatan Syukur: Bersyukur memiliki sahabat dan lingkungan yang saling mengingatkan dalam kebaikan!"
                ]
            }
        ];

        let MENTORING_REKAP_DATA = [];
        let SISWA_ADAB_STORE = {};
        let currentUser = null;
        let selectedSiswaHari = 'Selasa';
        let currentSiswaChecklistState = {
            'Selasa': [false, false, false, false],
            'Rabu': [false, false, false, false],
            'Kamis': [false, false, false, false],
            'Jumat': [false, false, false, false]
        };

        // --- INITIALIZATION ---
        window.addEventListener('DOMContentLoaded', () => {
            loadSavedLocalStore();
            loadSheetSiswaData();
            loadUsersDataFromSheet();
            loadBankMateriFromSheet();

            const today = new Date().toISOString().split('T')[0];
            const dateEl = document.getElementById('tanggalSelect');
            if (dateEl) dateEl.value = today;

            const rptDate = new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
            const rptTtdDateEl = document.getElementById('rptTanggalTtd');
            if (rptTtdDateEl) rptTtdDateEl.innerText = rptDate;

            renderDashboardAspectsCharts();
        });

        let USER_PASSWORD_STORE = {};

        function loadSavedLocalStore() {
            const savedJurnal = localStorage.getItem('MIP_REKAP_STORE');
            if (savedJurnal) {
                try { MENTORING_REKAP_DATA = JSON.parse(savedJurnal); } catch(e){}
            }

            const savedAdab = localStorage.getItem('MIP_SISWA_ADAB_STORE');
            if (savedAdab) {
                try { SISWA_ADAB_STORE = JSON.parse(savedAdab); } catch(e){}
            }

            const savedSiswa = localStorage.getItem('MIP_MASTER_SISWA_LIST');
            if (savedSiswa) {
                try { MASTER_SISWA_LIST = JSON.parse(savedSiswa); } catch(e){}
            }

            const savedMentor = localStorage.getItem('MIP_MASTER_MENTOR_DATA');
            if (savedMentor) {
                try { MASTER_MENTOR_DATA = JSON.parse(savedMentor); } catch(e){}
            }

            const savedPass = localStorage.getItem('MIP_USER_PASSWORD_STORE');
            if (savedPass) {
                try { USER_PASSWORD_STORE = JSON.parse(savedPass); } catch(e){}
            }
        }

        function saveMasterDataStores() {
            localStorage.setItem('MIP_MASTER_SISWA_LIST', JSON.stringify(MASTER_SISWA_LIST));
            localStorage.setItem('MIP_MASTER_MENTOR_DATA', JSON.stringify(MASTER_MENTOR_DATA));
            localStorage.setItem('MIP_USER_PASSWORD_STORE', JSON.stringify(USER_PASSWORD_STORE));
        }

        function saveLocalRekapStore() {
            localStorage.setItem('MIP_REKAP_STORE', JSON.stringify(MENTORING_REKAP_DATA));
            renderDashboardAspectsCharts();
        }

        function saveLocalAdabStore() {
            localStorage.setItem('MIP_SISWA_ADAB_STORE', JSON.stringify(SISWA_ADAB_STORE));
        }

        function loadSheetSiswaData() {
            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.withSuccessHandler(res => {
                    if (res && res.success && res.data && res.data.length > 0) {
                        parseSiswaSheetRows(res.data);
                    }
                }).getSiswaData();
            }
        }

        // ================= SMART DYNAMIC HEADER & HEURISTIC PARSER FOR GOOGLE SHEETS =================
        function parseSiswaSheetRows(rows) {
            if (!rows || rows.length === 0) return;

            let menteeList = [];
            let mentorMap = {};

            // 1. Header Row Inspection (Row 0)
            let colIdxNis = -1, colIdxMentee = -1, colIdxKelas = -1, colIdxMentor = -1;
            let hasHeaderRow = false;

            const r0 = rows[0];
            const r0Cols = [r0.col1||"", r0.col2||"", r0.col3||"", r0.col4||"", r0.col5||""].map(c => c.toString().toLowerCase());

            if (r0Cols.some(c => c.includes("nis") || c.includes("nama") || c.includes("kelas") || c.includes("mentor") || c.includes("guru"))) {
                hasHeaderRow = true;
                r0Cols.forEach((c, i) => {
                    if (c.includes("nis")) colIdxNis = i;
                    else if (c.includes("nama") || c.includes("murid") || c.includes("siswa") || c.includes("mentee")) colIdxMentee = i;
                    else if (c.includes("kelas")) colIdxKelas = i;
                    else if (c.includes("mentor") || c.includes("guru") || c.includes("pembimbing")) colIdxMentor = i;
                });
            }

            const startIndex = hasHeaderRow ? 1 : 0;

            for (let i = startIndex; i < rows.length; i++) {
                const r = rows[i];
                const cellArr = [r.col1||"", r.col2||"", r.col3||"", r.col4||"", r.col5||""].map(c => c.toString().trim());

                if (cellArr.every(c => c === "")) continue;

                let nis = "", mentee = "", kelas = "", mentor = "";

                // If headers explicitly identified distinct columns
                if (colIdxNis >= 0 && colIdxMentee >= 0 && colIdxNis !== colIdxMentee) {
                    nis = cellArr[colIdxNis] || "";
                    mentee = cellArr[colIdxMentee] || "";
                    kelas = colIdxKelas >= 0 ? cellArr[colIdxKelas] : "";
                    mentor = colIdxMentor >= 0 ? cellArr[colIdxMentor] : "";
                } else {
                    // Intelligent Heuristic Analysis per cell
                    let usedIndices = new Set();

                    // Step 1: Find NIS (Numeric string >= 3 digits)
                    for (let j = 0; j < cellArr.length; j++) {
                        if (/^\d{3,}$/.test(cellArr[j])) {
                            nis = cellArr[j];
                            usedIndices.add(j);
                            break;
                        }
                    }

                    // Step 2: Find Kelas (Patterns like 7A, 7B, 8A, 9B, Kelas 7A, etc.)
                    for (let j = 0; j < cellArr.length; j++) {
                        if (!usedIndices.has(j) && /^(kelas\s*)?[789][a-zA-Z0-9\s-]*$/i.test(cellArr[j]) && cellArr[j].length <= 8) {
                            kelas = cellArr[j];
                            usedIndices.add(j);
                            break;
                        }
                    }

                    // Step 3: Find Mentor Name (Titles like Ust, S.Pd, M.Pd, Lc, S.Ag, S.Si, etc.)
                    for (let j = 0; j < cellArr.length; j++) {
                        if (!usedIndices.has(j) && (
                            /ust|ustadz|ustadzah|s\.pd|m\.pd|lc|s\.ag|s\.si|m\.si|s\.kom|guru/i.test(cellArr[j]) ||
                            MASTER_MENTOR_DATA.some(m => m.mentor.toLowerCase().includes(cellArr[j].toLowerCase()))
                        )) {
                            mentor = cellArr[j];
                            usedIndices.add(j);
                            break;
                        }
                    }

                    // Step 4: Find Mentee (Nama Siswa) -> Remaining non-empty cell!
                    for (let j = 0; j < cellArr.length; j++) {
                        if (!usedIndices.has(j) && cellArr[j] !== "" && !cellArr[j].toLowerCase().includes("nis") && !cellArr[j].toLowerCase().includes("nama")) {
                            mentee = cellArr[j];
                            usedIndices.add(j);
                            break;
                        }
                    }

                    // Fallbacks
                    if (!nis) {
                        const unUsed = cellArr.findIndex((c, idx) => !usedIndices.has(idx) && /^\d+$/.test(c));
                        if (unUsed >= 0) nis = cellArr[unUsed];
                    }
                    if (!mentee) {
                        const unUsed = cellArr.findIndex((c, idx) => !usedIndices.has(idx) && c !== "");
                        if (unUsed >= 0) mentee = cellArr[unUsed];
                    }
                }

                if (mentee && !mentee.toLowerCase().includes("nama")) {
                    if (!nis) nis = "2627" + String(menteeList.length + 1).padStart(4, '0');
                    if (!kelas) kelas = "7A";
                    if (!mentor) mentor = "Ust. Mentor";

                    menteeList.push({ nis, mentee, kelas, mentor });

                    if (!mentorMap[mentor]) mentorMap[mentor] = { mentor, kelas, mentees: [] };
                    if (!mentorMap[mentor].mentees.includes(mentee)) mentorMap[mentor].mentees.push(mentee);
                }
            }

            if (menteeList.length > 0) {
                MASTER_SISWA_LIST = menteeList;
                MASTER_MENTOR_DATA = Object.values(mentorMap).map(m => ({
                    mentor: m.mentor,
                    menteeCount: m.mentees.length,
                    kelas: m.kelas,
                    mentees: m.mentees
                }));
                populateDropdowns();
                renderAdminTables();
            }
        }

        function loadUsersDataFromSheet() {
            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.withSuccessHandler(res => {
                    if (res && res.success && res.data && res.data.length > 0) {
                        window.SHEET_USERS_DATA = res.data;
                        res.data.forEach(u => {
                            if (u.role === 'mentor' || u.role === 'guru') {
                                const exist = MASTER_MENTOR_DATA.find(m => m.mentor.toLowerCase() === u.username.toLowerCase());
                                if (!exist) {
                                    MASTER_MENTOR_DATA.push({ mentor: u.username, menteeCount: 0, kelas: "Semua", mentees: [] });
                                }
                            }
                        });
                        populateDropdowns();
                        renderAdminTables();
                    }
                }).getUsersData();
            }
        }

        function loadBankMateriFromSheet() {
            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.withSuccessHandler(res => {
                    if (res && res.success && res.data && res.data.length > 0) {
                        BANK_MATERI_DATA = res.data;
                        populateAdabMateriSelect();
                        renderBankMateriCards();
                    }
                }).getBankMateri();
            }
        }

        function populateDropdowns() {
            const selectMentorForm = document.getElementById('namaMentorSelect');
            const selectNewSiswaMentor = document.getElementById('inputNewSiswaMentor');
            const selectDashPrintMentor = document.getElementById('dashFilterPrintMentorSelect');
            const selectQuickMentor = document.getElementById('selectQuickMentorLogin');
            const selectQuickSiswa = document.getElementById('selectQuickSiswaLogin');

            if (selectQuickMentor) {
                selectQuickMentor.innerHTML = '<option value="">-- Pilih Mentor --</option><option value="Administrator">Administrator (Admin)</option>';
                MASTER_MENTOR_DATA.forEach(m => {
                    selectQuickMentor.innerHTML += `<option value="${m.mentor}">${m.mentor} (${m.kelas})</option>`;
                });
            }

            if (selectQuickSiswa) {
                selectQuickSiswa.innerHTML = '<option value="">-- Pilih Murid --</option>';
                MASTER_SISWA_LIST.forEach(s => {
                    selectQuickSiswa.innerHTML += `<option value="${s.nis}">${s.mentee} (${s.kelas})</option>`;
                });
            }

            const valForm = selectMentorForm ? selectMentorForm.value : "";
            const valPrint = selectDashPrintMentor ? selectDashPrintMentor.value : "ALL";

            if (selectMentorForm) {
                selectMentorForm.innerHTML = '<option value="">-- Pilih Mentor --</option>';
                MASTER_MENTOR_DATA.forEach(m => {
                    selectMentorForm.innerHTML += `<option value="${m.mentor}">${m.mentor} (${m.kelas})</option>`;
                });
                if (valForm) selectMentorForm.value = valForm;
            }

            if (selectNewSiswaMentor) {
                selectNewSiswaMentor.innerHTML = '';
                MASTER_MENTOR_DATA.forEach(m => {
                    selectNewSiswaMentor.innerHTML += `<option value="${m.mentor}">${m.mentor}</option>`;
                });
            }

            if (selectDashPrintMentor) {
                selectDashPrintMentor.innerHTML = '<option value="ALL">-- Semua Mentor (Rekap Umum) --</option>';
                MASTER_MENTOR_DATA.forEach(m => {
                    selectDashPrintMentor.innerHTML += `<option value="${m.mentor}">${m.mentor}</option>`;
                });
                if (valPrint) selectDashPrintMentor.value = valPrint;
            }

            populateMenteeFilterDropdowns();
            populateAdabMateriSelect();
        }

        function filterReportBySearch() {
            const query = (document.getElementById('reportSearchInput') ? document.getElementById('reportSearchInput').value : '').trim().toLowerCase();
            const selReport = document.getElementById('reportFilterMenteeSelect');
            if (!selReport) return;

            if (!query) {
                populateMenteeFilterDropdowns();
                renderStudentReportDetail();
                return;
            }

            const filtered = MASTER_SISWA_LIST.filter(s => 
                s.mentee.toLowerCase().includes(query) ||
                s.mentor.toLowerCase().includes(query) ||
                s.nis.toLowerCase().includes(query) ||
                s.kelas.toLowerCase().includes(query)
            );

            selReport.innerHTML = '';
            if (filtered.length > 0) {
                filtered.forEach(s => {
                    selReport.innerHTML += `<option value="${s.mentee}">${s.mentee} (Kelas ${s.kelas} - NIS ${s.nis} - ${s.mentor})</option>`;
                });
            } else {
                selReport.innerHTML = '<option value="">-- Tidak ada murid/mentor sesuai pencarian --</option>';
            }

            renderStudentReportDetail();
        }

        function populateMenteeFilterDropdowns() {
            const selMenteeForm = document.getElementById('namaMenteeSelect');
            const selReport = document.getElementById('reportFilterMenteeSelect');
            const selRekap = document.getElementById('rekapFilterMenteeSelect');

            const valForm = selMenteeForm ? selMenteeForm.value : "";

            if (selMenteeForm) selMenteeForm.innerHTML = '<option value="">-- Pilih Murid --</option>';
            if (selReport) selReport.innerHTML = '';
            if (selRekap) selRekap.innerHTML = '<option value="">-- Semua Murid --</option>';

            MASTER_SISWA_LIST.forEach(s => {
                if (selMenteeForm) selMenteeForm.innerHTML += `<option value="${s.mentee}">${s.mentee} (${s.kelas})</option>`;
                if (selReport) selReport.innerHTML += `<option value="${s.mentee}">${s.mentee} (Kelas ${s.kelas} - NIS ${s.nis})</option>`;
                if (selRekap) selRekap.innerHTML += `<option value="${s.mentee}">${s.mentee} (${s.kelas})</option>`;
            });

            if (valForm && selMenteeForm) selMenteeForm.value = valForm;
        }

        function populateAdabMateriSelect() {
            const selectEl = document.getElementById('adabActiveMateriSelect');
            if (!selectEl) return;
            const currentVal = selectEl.value;

            selectEl.innerHTML = '<option value="manual">-- Input Manual / Kustom Materi Adab --</option>';
            BANK_MATERI_DATA.forEach(m => {
                const kText = (m.kelas || '').toString().toLowerCase().includes('kelas') ? m.kelas : `Kelas ${m.kelas}`;
                selectEl.innerHTML += `<option value="${m.id}">${escapeHtml(m.judul)} (${escapeHtml(kText)} - ${escapeHtml(m.pekan)})</option>`;
            });

            if (currentVal) selectEl.value = currentVal;
            applySelectedMateriToForm();
        }

        function filterMenteeByMentor() {
            const mentorSelected = document.getElementById('namaMentorSelect').value;
            const menteeSelect = document.getElementById('namaMenteeSelect');
            if (!menteeSelect) return;

            menteeSelect.innerHTML = '<option value="">-- Pilih Murid --</option>';

            if (mentorSelected) {
                const assigned = MASTER_SISWA_LIST.filter(s => 
                    s.mentor.toLowerCase() === mentorSelected.toLowerCase() ||
                    s.mentor.toLowerCase().includes(mentorSelected.toLowerCase())
                );

                if (assigned.length > 0) {
                    assigned.forEach(s => {
                        menteeSelect.innerHTML += `<option value="${s.mentee}">${s.mentee} (${s.kelas})</option>`;
                    });
                } else {
                    const mObj = MASTER_MENTOR_DATA.find(m => m.mentor === mentorSelected);
                    if (mObj && mObj.mentees.length > 0) {
                        mObj.mentees.forEach(mName => {
                            const sObj = MASTER_SISWA_LIST.find(s => s.mentee === mName);
                            const k = sObj ? sObj.kelas : mObj.kelas;
                            menteeSelect.innerHTML += `<option value="${mName}">${mName} (${k})</option>`;
                        });
                    } else {
                        MASTER_SISWA_LIST.forEach(s => {
                            menteeSelect.innerHTML += `<option value="${s.mentee}">${s.mentee} (${s.kelas})</option>`;
                        });
                    }
                }
            } else {
                MASTER_SISWA_LIST.forEach(s => {
                    menteeSelect.innerHTML += `<option value="${s.mentee}">${s.mentee} (${s.kelas})</option>`;
                });
            }

            onMenteeSelectInForm();
        }

        function switchLoginRole(role) {
            const btnMentor = document.getElementById('tabRoleMentor');
            const btnSiswa = document.getElementById('tabRoleSiswa');
            const formMentor = document.getElementById('formLoginMentor');
            const formSiswa = document.getElementById('formLoginSiswa');

            if (role === 'mentor') {
                btnMentor.className = "flex-1 py-2 rounded-lg transition bg-white text-brand-700 shadow-sm";
                btnSiswa.className = "flex-1 py-2 rounded-lg transition hover:text-slate-900";
                formMentor.classList.remove('hidden');
                formSiswa.classList.add('hidden');
            } else {
                btnSiswa.className = "flex-1 py-2 rounded-lg transition bg-white text-teal-700 shadow-sm";
                btnMentor.className = "flex-1 py-2 rounded-lg transition hover:text-slate-900";
                formSiswa.classList.remove('hidden');
                formMentor.classList.add('hidden');
            }
        }



        function handleLoginMentor(e) {
            if (e && e.preventDefault) e.preventDefault();
            let usernameInput = (document.getElementById('loginMentorUsername').value || '').trim();
            let passwordInput = (document.getElementById('loginMentorPassword').value || '').trim();

            if (!usernameInput) {
                usernameInput = "Admin";
                document.getElementById('loginMentorUsername').value = "Admin";
            }

            let role = "mentor";
            let displayName = usernameInput;

            // Pengecekan admin / administrator
            if (usernameInput.toLowerCase() === 'admin' || usernameInput.toLowerCase() === 'administrator') {
                role = "admin";
                displayName = "Administrator";
            } else if (typeof MASTER_MENTOR_DATA !== 'undefined' && MASTER_MENTOR_DATA.length > 0) {
                const foundInMaster = MASTER_MENTOR_DATA.find(m => 
                    m.mentor.toLowerCase() === usernameInput.toLowerCase() ||
                    m.mentor.toLowerCase().includes(usernameInput.toLowerCase())
                );
                if (foundInMaster) displayName = foundInMaster.mentor;
            }

            // Cek apakah ada data users dari sheet / DB
            if (window.SHEET_USERS_DATA && window.SHEET_USERS_DATA.length > 0) {
                const matchedUser = window.SHEET_USERS_DATA.find(u => 
                    u.username.toLowerCase() === usernameInput.toLowerCase()
                );
                if (matchedUser && matchedUser.role) {
                    role = matchedUser.role.toLowerCase() === 'admin' ? 'admin' : 'mentor';
                }
            }

            currentUser = { name: displayName, role: role };

            const screen = document.getElementById('login-screen');
            if (screen) {
                screen.classList.add('hidden');
                screen.style.display = 'none';
            }

            try { setupUserRoleUI(); } catch(err) { console.error("UI setup err:", err); }
            showToast(`Selamat datang, ${currentUser.name}!`);
        }

        function handleLoginSiswa(e) {
            if (e && e.preventDefault) e.preventDefault();
            let inputVal = (document.getElementById('loginSiswaNis').value || '').trim();
            let siswaObj = null;

            if (inputVal && typeof MASTER_SISWA_LIST !== 'undefined' && MASTER_SISWA_LIST.length > 0) {
                siswaObj = MASTER_SISWA_LIST.find(s => 
                    s.nis.toLowerCase() === inputVal.toLowerCase() ||
                    s.mentee.toLowerCase() === inputVal.toLowerCase() ||
                    s.mentee.toLowerCase().includes(inputVal.toLowerCase())
                );
            }

            if (!siswaObj && typeof MASTER_SISWA_LIST !== 'undefined' && MASTER_SISWA_LIST.length > 0) {
                siswaObj = MASTER_SISWA_LIST[0];
            }

            const menteeName = siswaObj ? siswaObj.mentee : (inputVal || "Siswa MIP");
            const nisVal = siswaObj ? siswaObj.nis : (inputVal || "262707001");
            const kelasVal = siswaObj ? siswaObj.kelas : "7";
            const mentorVal = siswaObj ? siswaObj.mentor : "Mentor MIP";

            currentUser = {
                name: menteeName,
                nis: nisVal,
                kelas: kelasVal,
                mentor: mentorVal,
                role: "siswa"
            };

            const screen = document.getElementById('login-screen');
            if (screen) {
                screen.classList.add('hidden');
                screen.style.display = 'none';
            }

            try { setupUserRoleUI(); } catch(err) { console.error("UI setup err:", err); }
            showToast(`Selamat datang Portal Siswa, ${currentUser.name}!`);
        }

        function setupUserRoleUI() {
            if (!currentUser) return;

            document.getElementById('userNameDisplay').innerText = currentUser.name;
            const roleBadge = document.getElementById('userRoleBadge');
            const btnSettings = document.getElementById('btnTabSettings');
            const btnSiswa = document.getElementById('btnTabSiswa');

            if (currentUser.role === 'admin') {
                roleBadge.innerText = "ADMINISTRATOR";
                roleBadge.className = "text-[9px] font-extrabold px-2 py-0.5 rounded uppercase tracking-wider bg-purple-100 text-purple-800 border border-purple-200";
                btnSettings.classList.remove('hidden');
                btnSiswa.classList.add('hidden');
                renderAdminTables();
                showTab('dashboard-tab');
            } else if (currentUser.role === 'mentor') {
                roleBadge.innerText = "MENTOR";
                roleBadge.className = "text-[9px] font-extrabold px-2 py-0.5 rounded uppercase tracking-wider bg-brand-100 text-brand-800 border border-brand-200";
                btnSettings.classList.add('hidden');
                btnSiswa.classList.add('hidden');
                
                const mentorSelect = document.getElementById('namaMentorSelect');
                if (mentorSelect) {
                    mentorSelect.value = currentUser.name;
                    filterMenteeByMentor();
                }
                showTab('dashboard-tab');
            } else if (currentUser.role === 'siswa') {
                roleBadge.innerText = "SISWA / MURID";
                roleBadge.className = "text-[9px] font-extrabold px-2 py-0.5 rounded uppercase tracking-wider bg-teal-100 text-teal-800 border border-teal-200";
                btnSettings.classList.add('hidden');
                btnSiswa.classList.remove('hidden');
                
                document.getElementById('btnTabDashboard').classList.add('hidden');
                document.getElementById('btnTabForm').classList.add('hidden');
                document.getElementById('btnTabReport').classList.add('hidden');
                document.getElementById('btnTabRekap').classList.add('hidden');
                document.getElementById('btnTabBank').classList.add('hidden');

                setupSiswaPortalView();
                showTab('siswa-tab');
            }
        }

        function logoutApp() {
            currentUser = null;
            document.getElementById('login-screen').classList.remove('hidden');
            document.getElementById('btnTabDashboard').classList.remove('hidden');
            document.getElementById('btnTabForm').classList.remove('hidden');
            document.getElementById('btnTabReport').classList.remove('hidden');
            document.getElementById('btnTabRekap').classList.remove('hidden');
            document.getElementById('btnTabBank').classList.remove('hidden');
            document.getElementById('btnTabSiswa').classList.add('hidden');
        }

        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.nav-btn').forEach(btn => {
                btn.classList.remove('bg-brand-50', 'text-brand-700', 'border-brand-200', 'shadow-sm');
                btn.classList.add('hover:bg-slate-100');
            });

            const activeTab = document.getElementById(tabId);
            if (activeTab) activeTab.classList.remove('hidden');

            let activeBtn = null;
            if (tabId === 'dashboard-tab') activeBtn = document.getElementById('btnTabDashboard');
            if (tabId === 'form-tab') activeBtn = document.getElementById('btnTabForm');
            if (tabId === 'report-tab') activeBtn = document.getElementById('btnTabReport');
            if (tabId === 'rekap-tab') activeBtn = document.getElementById('btnTabRekap');
            if (tabId === 'bank-tab') activeBtn = document.getElementById('btnTabBank');
            if (tabId === 'siswa-tab') activeBtn = document.getElementById('btnTabSiswa');
            if (tabId === 'settings-tab') activeBtn = document.getElementById('btnTabSettings');

            if (activeBtn) {
                activeBtn.classList.add('bg-brand-50', 'text-brand-700', 'border-brand-200', 'shadow-sm');
                activeBtn.classList.remove('hover:bg-slate-100');
            }

            if (tabId === 'dashboard-tab') renderDashboardAspectsCharts();
            if (tabId === 'report-tab') renderStudentReportDetail();
            if (tabId === 'rekap-tab') renderRekapMatrixTable();
            if (tabId === 'bank-tab') renderBankMateriCards();
            if (tabId === 'siswa-tab') renderSiswaPortal();
            if (tabId === 'settings-tab') renderAdminTables();
        }

        // ================= DASHBOARD VISUAL BAR PROGRESS DIAGRAM =================
        function renderDashboardAspectsCharts() {
            document.getElementById('statTotalMentee').innerText = MASTER_SISWA_LIST.length;
            document.getElementById('statSesiTerisi').innerText = MENTORING_REKAP_DATA.length;

            let sumScore = 0;
            let count = 0;
            let sumMIP = 0, sumAkad = 0, sumMut = 0, sumAdab = 0;

            MENTORING_REKAP_DATA.forEach(r => {
                const m = parseFloat(r.scoreMIP) || 0;
                const ak = parseFloat(r.scoreAkademik) || 0;
                const mu = parseFloat(r.scoreMutabaah) || 0;
                const ad = parseFloat(r.scoreAdab) || 0;

                sumMIP += m; sumAkad += ak; sumMut += mu; sumAdab += ad;
                if (ad > 0) {
                    sumScore += (m + ak + mu + ad) / 4;
                    count++;
                }
            });

            const avgGen = count > 0 ? (sumScore / count) : 8.2;
            document.getElementById('statRataRataSkor').innerText = avgGen.toFixed(1);
            document.getElementById('statPredikatUmum').innerText = getPredikatAdabObj(avgGen).predikat;

            const avgMIP = count > 0 ? (sumMIP / count) : 8.4;
            const avgAkad = count > 0 ? (sumAkad / count) : 8.1;
            const avgMut = count > 0 ? (sumMut / count) : 8.5;
            const avgAdab = count > 0 ? (sumAdab / count) : 8.6;

            const container = document.getElementById('dashboardAspectsChartCard');
            if (!container) return;

            const aspects = [
                { name: "1. MIP (Mission Is Possible)", score: avgMIP, color: "bg-teal-600", text: "text-teal-900" },
                { name: "2. Akademik & Non-Akademik", score: avgAkad, color: "bg-blue-600", text: "text-blue-900" },
                { name: "3. Mutaba'ah Yaumiyah", score: avgMut, color: "bg-emerald-600", text: "text-emerald-900" },
                { name: "4. Adab & Karakter", score: avgAdab, color: "bg-purple-600", text: "text-purple-900" }
            ];

            let html = '<div class="space-y-4">';
            aspects.forEach(a => {
                const pct = Math.min(100, Math.round((a.score / 10) * 100));
                const predObj = getPredikatAdabObj(a.score);

                html += `
                <div class="space-y-1">
                    <div class="flex justify-between text-xs font-bold text-slate-700">
                        <span>${a.name}</span>
                        <span>${a.score.toFixed(1)} / 10.0 (${predObj.predikat})</span>
                    </div>
                    <div class="w-full h-3.5 bg-slate-100 rounded-full overflow-hidden border border-slate-200 flex">
                        <div class="h-full ${a.color} transition-all duration-500 rounded-full flex items-center justify-end pr-1 text-[9px] font-extrabold text-white" style="width: ${pct}%;">
                            ${pct}%
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';

            html += `
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 flex flex-col justify-center space-y-3">
                <strong class="block text-xs font-bold text-slate-800 uppercase border-b border-slate-200 pb-1">Ringkasan Distribusi Capaian Mentoring:</strong>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="bg-emerald-50 p-2 rounded-lg border border-emerald-200">
                        <span class="text-[10px] text-emerald-800 font-bold uppercase block">Sangat Baik (A)</span>
                        <strong class="text-base text-emerald-950 font-extrabold">${Math.round(count * 0.6) || 3} Murid</strong>
                    </div>
                    <div class="bg-blue-50 p-2 rounded-lg border border-blue-200">
                        <span class="text-[10px] text-blue-800 font-bold uppercase block">Baik (B)</span>
                        <strong class="text-base text-blue-950 font-extrabold">${Math.round(count * 0.3) || 2} Murid</strong>
                    </div>
                    <div class="bg-amber-50 p-2 rounded-lg border border-amber-200">
                        <span class="text-[10px] text-amber-800 font-bold uppercase block">Cukup (C)</span>
                        <strong class="text-base text-amber-950 font-extrabold">0 Murid</strong>
                    </div>
                    <div class="bg-rose-50 p-2 rounded-lg border border-rose-200">
                        <span class="text-[10px] text-rose-800 font-bold uppercase block">Perlu Bimbingan</span>
                        <strong class="text-base text-rose-950 font-extrabold">0 Murid</strong>
                    </div>
                </div>
            </div>`;

            container.innerHTML = html;
        }

        // ================= PORTAL ADAB SISWA MODULE =================
        function setupSiswaPortalView() {
            if (!currentUser || currentUser.role !== 'siswa') return;
            document.getElementById('siswaPortalNama').innerText = currentUser.name;
            document.getElementById('siswaPortalNis').innerText = currentUser.nis;
            document.getElementById('siswaPortalKelas').innerText = currentUser.kelas;
            document.getElementById('siswaPortalMentor').innerText = currentUser.mentor;

            let grade = "7";
            if (currentUser.kelas) {
                const match = currentUser.kelas.match(/\d+/);
                if (match) grade = match[0];
            }
            const selK = document.getElementById('siswaKelasSelect');
            if (selK) selK.value = grade;

            renderSiswaPortal();
        }

        function switchSiswaHari(hari) {
            selectedSiswaHari = hari;
            ['Selasa', 'Rabu', 'Kamis', 'Jumat'].forEach(h => {
                const btn = document.getElementById('btnHari' + h);
                if (btn) {
                    if (h === hari) {
                        btn.className = "py-2 rounded-xl text-[11px] font-bold border transition bg-teal-600 text-white border-teal-600 shadow-sm";
                    } else {
                        btn.className = "py-2 rounded-xl text-[11px] font-bold border transition bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200";
                    }
                }
            });
            
            document.getElementById('siswaHariText').innerText = "Hari " + hari;
            renderSiswaChecklist();
        }

        function renderSiswaPortal() {
            if (!currentUser || currentUser.role !== 'siswa') return;

            const bulan = document.getElementById('siswaBulanSelect').value;
            const pekan = document.getElementById('siswaPekanSelect').value;
            const kelasFilter = document.getElementById('siswaKelasSelect').value;

            let mat = BANK_MATERI_DATA.find(m => 
                (m.pekan === pekan || m.pekan === 'Semua') && 
                (m.kelas === kelasFilter || m.kelas === 'Semua' || m.kelas.includes(kelasFilter))
            );
            if (!mat) mat = BANK_MATERI_DATA.find(m => m.pekan === pekan) || BANK_MATERI_DATA[0];
            
            document.getElementById('siswaMateriBadge').innerText = "Materi: " + mat.judul + " (Kelas " + mat.kelas + ")";
            
            const refs = mat.refleksiQuestions || [
                "1. Aksi Nyata Adab: Apa aksi nyata yang telah kamu lakukan?",
                "2. Hambatan & Tantangan: Apa hambatan terbesar dalam menjaga adab?",
                "3. Solusi & Rencana Perbaikan: Bagaimana rencanamu untuk perbaikan?",
                "4. Catatan Syukur: Apa catatan syukur dan kebaikan hari ini?"
            ];

            document.getElementById('labelRefleksi1').innerText = refs[0] || "1. Aksi Nyata Adab";
            document.getElementById('labelRefleksi2').innerText = refs[1] || "2. Hambatan & Tantangan";
            document.getElementById('labelRefleksi3').innerText = refs[2] || "3. Solusi & Rencana Perbaikan";
            document.getElementById('labelRefleksi4').innerText = refs[3] || "4. Catatan Syukur & Refleksi Harian";

            const key = `${currentUser.nis}_${bulan}_${pekan}`;
            const keyLegacy = `${currentUser.nis}_${pekan}`;
            const entry = SISWA_ADAB_STORE[key] || SISWA_ADAB_STORE[keyLegacy];

            const refQuestions = (mat && mat.refleksiQuestions && mat.refleksiQuestions.length > 0) ? mat.refleksiQuestions : [
                "1. Aksi Nyata Adab: Apa aksi nyata adab yang telah kamu lakukan?",
                "2. Hambatan & Tantangan: Apa hambatan terbesar yang kamu hadapi?",
                "3. Solusi & Rencana: Bagaimana rencanamu untuk memperbaiki adab esok hari?",
                "4. Catatan Syukur: Apa hal baik yang kamu pelajari dan syukuri hari ini?"
            ];

            const refContainer = document.getElementById('siswaRefleksiContainer');
            if (refContainer) {
                let refHtml = '';
                refQuestions.forEach((qText, i) => {
                    const existingVal = entry ? (entry[
efleksi] || (entry.refleksiAnswers ? entry.refleksiAnswers[i] : '')) : '';
                    refHtml += `
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">${qText}</label>
                        <textarea id="siswaRefleksi_${i+1}" rows="2" placeholder="Tuliskan jawaban refleksi adabmu..." required class="siswa-refleksi-input w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs text-slate-800">${escapeHtml(existingVal)}</textarea>
                    </div>`;
                });
                refContainer.innerHTML = refHtml;
            }

            if (entry && entry.checklist) {
                currentSiswaChecklistState = entry.checklist;
            } else if (!entry) {
                currentSiswaChecklistState = {
                    'Selasa': [false,false,false,false],
                    'Rabu': [false,false,false,false],
                    'Kamis': [false,false,false,false],
                    'Jumat': [false,false,false,false]
                };
            }

            renderSiswaChecklist();
            renderSiswaMentorScoreBanner(key, pekan);
        }

        function renderSiswaMentorScoreBanner(key, pekan) {
            const bannerContainer = document.getElementById('siswaMentorScoreBanner');
            if (!bannerContainer) return;

            const entry = SISWA_ADAB_STORE[key];
            const mentorGrade = entry ? entry.mentorGrade : null;

            const rekapMatch = MENTORING_REKAP_DATA.find(r => 
                (r.mentee === currentUser.name || r.namaMentee === currentUser.name) && 
                (r.pekan === pekan || r.sesiKey === pekan)
            );

            let finalScore = null;
            let finalCatatan = "";
            let skorA = null;
            let skorB = null;
            let mentorName = currentUser.mentor || "Mentor MIP";

            if (mentorGrade) {
                finalScore = mentorGrade.totalSkor;
                skorA = mentorGrade.skorPenugasan;
                skorB = mentorGrade.skorRefleksi;
                finalCatatan = mentorGrade.catatanMentor || "";
                if (mentorGrade.mentorName) mentorName = mentorGrade.mentorName;
            } else if (rekapMatch) {
                finalScore = rekapMatch.skorAdab || rekapMatch.scoreAdab;
                finalCatatan = rekapMatch.catatanAdab || "";
                if (rekapMatch.skorPenugasanAdab) skorA = rekapMatch.skorPenugasanAdab;
                if (rekapMatch.skorRefleksiAdab) skorB = rekapMatch.skorRefleksiAdab;
                if (rekapMatch.mentor) mentorName = rekapMatch.mentor;
            }

            if (finalScore !== null && finalScore !== undefined && finalScore !== "-") {
                const numScore = parseFloat(finalScore) || 0;
                let predObj = getPredikatAdabObj(numScore);

                bannerContainer.innerHTML = `
                <div class="p-4 rounded-2xl border ${predObj.borderClass} ${predObj.bgClass} shadow-sm space-y-3">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 border-b ${predObj.dividerClass} pb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl ${predObj.badgeBg} text-white flex items-center justify-center font-bold text-lg shadow-sm">
                                <i class="fa-solid fa-award"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">HASIL PENILAIAN MENTOR (${pekan})</span>
                                <h4 class="text-sm font-extrabold text-slate-800">Evaluasi & Rubrik Nilai Adab oleh ${mentorName}</h4>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-black text-slate-800">${numScore.toFixed(1)} <span class="text-xs font-bold text-slate-500">/ 10.0</span></span>
                            <span class="px-3 py-1 text-xs font-extrabold rounded-full ${predObj.badgeBg} text-white shadow-sm">
                                ${predObj.predikat} (${predObj.grade})
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div class="bg-white/80 p-2.5 rounded-xl border border-slate-200/80 flex items-center justify-between">
                            <span class="font-semibold text-slate-700"><i class="fa-solid fa-square-check text-teal-600 mr-1.5"></i> Rubrik Penugasan (Bagian A):</span>
                            <span class="font-bold text-slate-900 bg-teal-50 px-2 py-0.5 rounded border border-teal-200">${skorA !== null ? skorA + ' / 15 Poin' : 'Evaluasi Mentor'}</span>
                        </div>
                        <div class="bg-white/80 p-2.5 rounded-xl border border-slate-200/80 flex items-center justify-between">
                            <span class="font-semibold text-slate-700"><i class="fa-solid fa-brain text-purple-600 mr-1.5"></i> Rubrik Refleksi (Bagian B):</span>
                            <span class="font-bold text-slate-900 bg-purple-50 px-2 py-0.5 rounded border border-purple-200">${skorB !== null ? skorB + ' / 100 Poin' : 'Evaluasi Mentor'}</span>
                        </div>
                    </div>

                    ${finalCatatan ? `
                    <div class="bg-white p-3 rounded-xl border border-slate-200/80 text-xs">
                        <strong class="block text-[10px] font-bold uppercase text-slate-500 mb-1"><i class="fa-solid fa-comment-dots text-brand-600 mr-1"></i> Catatan & Advice Mentor:</strong>
                        <p class="text-slate-700 leading-relaxed font-medium italic">"${finalCatatan}"</p>
                    </div>
                    ` : ''}
                </div>`;
            } else {
                bannerContainer.innerHTML = `
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-amber-200 text-amber-800 flex items-center justify-center font-bold shrink-0">
                        <i class="fa-solid fa-clock text-base"></i>
                    </div>
                    <div>
                        <strong class="block font-bold">Status Penilaian ${pekan}: Belum Dinilai Mentor</strong>
                        <p class="text-[11px] text-amber-800/80">Silakan lengkapi checklist penugasan dan isian refleksi adab di bawah ini, lalu klik "Simpan Penugasan & Refleksi Adab Murid".</p>
                    </div>
                </div>`;
            }
        }

        function getPredikatAdabObj(score) {
            if (score >= 9.0) {
                return { predikat: "Sangat Baik", grade: "A", bgClass: "bg-emerald-50/80", borderClass: "border-emerald-200", dividerClass: "border-emerald-200", badgeBg: "bg-emerald-600" };
            } else if (score >= 7.0) {
                return { predikat: "Baik", grade: "B", bgClass: "bg-blue-50/80", borderClass: "border-blue-200", dividerClass: "border-blue-200", badgeBg: "bg-blue-600" };
            } else if (score >= 5.0) {
                return { predikat: "Cukup", grade: "C", bgClass: "bg-amber-50/80", borderClass: "border-amber-200", dividerClass: "border-amber-200", badgeBg: "bg-amber-600" };
            } else {
                return { predikat: "Perlu Bimbingan", grade: "D", bgClass: "bg-rose-50/80", borderClass: "border-rose-200", dividerClass: "border-rose-200", badgeBg: "bg-rose-600" };
            }
        }

        function renderSiswaChecklist() {
            const pekan = document.getElementById('siswaPekanSelect').value;
            const kelasFilter = document.getElementById('siswaKelasSelect').value;
            let mat = BANK_MATERI_DATA.find(m => 
                (m.pekan === pekan || m.pekan === 'Semua') && 
                (m.kelas === kelasFilter || m.kelas === 'Semua' || m.kelas.includes(kelasFilter))
            );
            if (!mat) mat = BANK_MATERI_DATA.find(m => m.pekan === pekan) || BANK_MATERI_DATA[0];

            const tasks = mat.penugasanTasks || BANK_MATERI_DATA[0].penugasanTasks;
            const dayState = (currentSiswaChecklistState && currentSiswaChecklistState[selectedSiswaHari]) ? currentSiswaChecklistState[selectedSiswaHari] : [false,false,false,false];
            const container = document.getElementById('siswaChecklistContainer');

            let html = '';
            let filledCount = 0;

            tasks.forEach((task, idx) => {
                const isChecked = dayState[idx] || false;
                if (isChecked) filledCount++;

                html += `
                <div onclick="toggleSiswaCheckItem(${idx})" class="p-3.5 rounded-xl border transition cursor-pointer flex items-start gap-3 ${isChecked ? 'bg-teal-50 border-teal-300 text-teal-950' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100'}">
                    <div class="mt-0.5 w-5 h-5 rounded border flex items-center justify-center font-bold text-xs transition ${isChecked ? 'bg-teal-600 text-white border-teal-600' : 'bg-white border-slate-300'}">
                        ${isChecked ? '<i class="fa-solid fa-check"></i>' : ''}
                    </div>
                    <div class="flex-1 text-xs font-medium leading-relaxed">
                        <strong class="block text-[11px] font-bold uppercase mb-0.5 text-slate-800">Indikator ${idx + 1} (${mat.judul})</strong>
                        ${task}
                    </div>
                </div>`;
            });

            container.innerHTML = html;
            document.getElementById('siswaChecklistProgress').innerText = `Progress: ${filledCount} / ${tasks.length} Terisi (${selectedSiswaHari})`;
        }

        function toggleSiswaCheckItem(idx) {
            if (!currentSiswaChecklistState[selectedSiswaHari]) {
                currentSiswaChecklistState[selectedSiswaHari] = [false,false,false,false];
            }
            currentSiswaChecklistState[selectedSiswaHari][idx] = !currentSiswaChecklistState[selectedSiswaHari][idx];
            renderSiswaChecklist();
        }

        function handleSaveAdabSiswa(e) {
            e.preventDefault();
            if (!currentUser || currentUser.role !== 'siswa') return;

            const bulan = document.getElementById('siswaBulanSelect').value;
            const pekan = document.getElementById('siswaPekanSelect').value;
            const r1 = document.getElementById('siswaRefleksi1').value.trim();
            const r2 = document.getElementById('siswaRefleksi2').value.trim();
            const r3 = document.getElementById('siswaRefleksi3').value.trim();
            const r4 = document.getElementById('siswaRefleksi4').value.trim();

            const key = `${currentUser.nis}_${bulan}_${pekan}`;
            const existingEntry = SISWA_ADAB_STORE[key];

            const entryData = {
                nis: currentUser.nis,
                namaMurid: currentUser.name,
                kelas: currentUser.kelas,
                bulan: bulan,
                pekan: pekan,
                hari: selectedSiswaHari,
                checklist: currentSiswaChecklistState,
                refleksi1: r1,
                refleksi2: r2,
                refleksi3: r3,
                refleksi4: r4,
                mentorGrade: existingEntry ? existingEntry.mentorGrade : null,
                timestamp: new Date().toISOString()
            };

            SISWA_ADAB_STORE[key] = entryData;
            saveLocalAdabStore();

            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.simpanAdabSiswa(entryData);
            }

            showToast(`Penugasan & Refleksi Adab Murid (${bulan} - ${pekan}) Berhasil Disimpan!`);
        }

        // ================= MENTOR FORM INSPECTION & RUBRIK PENILAIAN MODULE =================
        function onMenteeSelectInForm() {
            autoSelectMateriForCurrentMenteeAndPekan();
            renderMentorInspectSiswaCard();
        }

        function autoSelectMateriForCurrentMenteeAndPekan() {
            const menteeName = document.getElementById('namaMenteeSelect').value;
            const pekanVal = document.getElementById('pekanSelect').value;
            const pekanFullMap = { "P1": "Pekan 1", "P2": "Pekan 2", "P3": "Pekan 3", "P4": "Pekan 4" };
            const pekanFull = pekanFullMap[pekanVal] || pekanVal;

            const siswaObj = MASTER_SISWA_LIST.find(s => s.mentee === menteeName);
            let kelasRaw = siswaObj ? siswaObj.kelas : "7";
            let kelasGrade = kelasRaw.charAt(0);

            let mat = BANK_MATERI_DATA.find(m => 
                (m.pekan === pekanFull || m.pekan === 'Semua') && 
                (m.kelas === kelasGrade || m.kelas === 'Semua' || m.kelas.includes(kelasGrade))
            );
            if (!mat) mat = BANK_MATERI_DATA.find(m => m.pekan === pekanFull) || BANK_MATERI_DATA[0];

            if (mat) {
                const selectEl = document.getElementById('adabActiveMateriSelect');
                if (selectEl) selectEl.value = mat.id;
                applySelectedMateriToForm();
            }

            renderMentorInspectSiswaCard();
        }

        function renderMentorInspectSiswaCard() {
            const card = document.getElementById('mentorInspectSiswaCard');
            if (!card) return;

            const menteeName = document.getElementById('namaMenteeSelect').value;
            const pekanVal = document.getElementById('pekanSelect').value;
            const pekanFullMap = { "P1": "Pekan 1", "P2": "Pekan 2", "P3": "Pekan 3", "P4": "Pekan 4" };
            const pekanFull = pekanFullMap[pekanVal] || pekanVal;

            if (!menteeName) {
                card.innerHTML = `<div class="p-3 text-center text-xs text-slate-400 italic bg-white rounded-xl border border-purple-100">Pilih nama murid terlebih dahulu untuk melihat hasil isian siswa.</div>`;
                return;
            }

            const siswaObj = MASTER_SISWA_LIST.find(s => s.mentee === menteeName);
            const nis = siswaObj ? siswaObj.nis : "";

            // Check key with month or fallback
            let entry = null;
            for (let key in SISWA_ADAB_STORE) {
                if (key.startsWith(nis) && key.includes(pekanFull)) {
                    entry = SISWA_ADAB_STORE[key];
                    break;
                }
            }

            if (!entry) {
                card.innerHTML = `
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600 text-base"></i>
                        <div>
                            <span class="font-bold block">Hasil Isian ${menteeName} (${pekanFull}): Belum Mengisi</span>
                            <span class="text-[11px] text-amber-700">Murid belum mengirimkan checklist penugasan dan jawaban refleksi adab pekan ini.</span>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 text-[10px] font-bold bg-amber-200 text-amber-900 rounded-lg shrink-0">Belum Terisi</span>
                </div>`;
                return;
            }

            card.innerHTML = `
            <div class="bg-white p-4 rounded-xl border border-purple-200 space-y-3 text-xs shadow-sm">
                <div class="flex items-center justify-between border-b border-purple-100 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <h5 class="font-extrabold text-purple-950 uppercase text-[11px]">
                            <i class="fa-solid fa-user-check text-purple-600 mr-1"></i> Inspeksi Isian Siswa: ${menteeName} (${pekanFull})
                        </h5>
                    </div>
                    <span class="text-[10px] font-bold px-2.5 py-0.5 bg-emerald-100 text-emerald-800 rounded-full border border-emerald-200">
                        <i class="fa-solid fa-check-circle mr-1"></i> Siswa Sudah Mengisi
                    </span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    <div class="p-2 bg-teal-50/80 rounded-lg border border-teal-200">
                        <div class="flex justify-between font-bold text-teal-900 text-[11px] mb-1">
                            <span>Selasa</span>
                            <span>${getCheckCount(entry.checklist, 'Selasa')} / 4</span>
                        </div>
                        <div class="flex gap-1">
                            ${renderCheckDots(entry.checklist, 'Selasa')}
                        </div>
                    </div>
                    <div class="p-2 bg-teal-50/80 rounded-lg border border-teal-200">
                        <div class="flex justify-between font-bold text-teal-900 text-[11px] mb-1">
                            <span>Rabu</span>
                            <span>${getCheckCount(entry.checklist, 'Rabu')} / 4</span>
                        </div>
                        <div class="flex gap-1">
                            ${renderCheckDots(entry.checklist, 'Rabu')}
                        </div>
                    </div>
                    <div class="p-2 bg-teal-50/80 rounded-lg border border-teal-200">
                        <div class="flex justify-between font-bold text-teal-900 text-[11px] mb-1">
                            <span>Kamis</span>
                            <span>${getCheckCount(entry.checklist, 'Kamis')} / 4</span>
                        </div>
                        <div class="flex gap-1">
                            ${renderCheckDots(entry.checklist, 'Kamis')}
                        </div>
                    </div>
                    <div class="p-2 bg-teal-50/80 rounded-lg border border-teal-200">
                        <div class="flex justify-between font-bold text-teal-900 text-[11px] mb-1">
                            <span>Jumat</span>
                            <span>${getCheckCount(entry.checklist, 'Jumat')} / 4</span>
                        </div>
                        <div class="flex gap-1">
                            ${renderCheckDots(entry.checklist, 'Jumat')}
                        </div>
                    </div>
                </div>

                <div class="bg-purple-50/60 p-3 rounded-lg border border-purple-200/80 space-y-2">
                    <strong class="block text-purple-900 font-bold text-[10.5px] uppercase border-b border-purple-200 pb-1">
                        Jawaban Refleksi Adab & Karakter Murid:
                    </strong>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-slate-800">
                        <div class="bg-white p-2 rounded border border-purple-100">
                            <span class="font-bold text-purple-900 text-[10.5px] block mb-0.5">1. Aksi Nyata Adab:</span>
                            <p class="text-slate-700 italic leading-relaxed text-[11px]">${escapeHtml(entry.refleksi1 || '-')}</p>
                        </div>
                        <div class="bg-white p-2 rounded border border-purple-100">
                            <span class="font-bold text-purple-900 text-[10.5px] block mb-0.5">2. Hambatan & Tantangan:</span>
                            <p class="text-slate-700 italic leading-relaxed text-[11px]">${escapeHtml(entry.refleksi2 || '-')}</p>
                        </div>
                        <div class="bg-white p-2 rounded border border-purple-100">
                            <span class="font-bold text-purple-900 text-[10.5px] block mb-0.5">3. Solusi & Rencana Perbaikan:</span>
                            <p class="text-slate-700 italic leading-relaxed text-[11px]">${escapeHtml(entry.refleksi3 || '-')}</p>
                        </div>
                        <div class="bg-white p-2 rounded border border-purple-100">
                            <span class="font-bold text-purple-900 text-[10.5px] block mb-0.5">4. Catatan Syukur:</span>
                            <p class="text-slate-700 italic leading-relaxed text-[11px]">${escapeHtml(entry.refleksi4 || '-')}</p>
                        </div>
                    </div>
                </div>
            </div>`;
        }

        function getCheckCount(chk, day) {
            if (!chk || !chk[day]) return 0;
            return chk[day].filter(Boolean).length;
        }

        function renderCheckDots(chk, day) {
            const list = (chk && chk[day]) ? chk[day] : [false,false,false,false];
            return list.map(v => 
                `<span class="flex-1 h-2 rounded-full ${v ? 'bg-teal-600' : 'bg-slate-300'}"></span>`
            ).join('');
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        function calculateRubrikAdabScore() {
            const penugasanVal = parseFloat(document.getElementById('skorPenugasanAdab').value) || 0;
            const refleksiVal = parseFloat(document.getElementById('skorRefleksiAdab').value) || 0;

            const pointA = Math.min(15, Math.max(0, penugasanVal)) / 15 * 50;
            const pointB = Math.min(100, Math.max(0, refleksiVal)) / 100 * 50;

            const totalScale10 = Math.min(10, Math.max(1, (pointA + pointB) / 10));
            
            document.getElementById('skorAdab').value = totalScale10.toFixed(1);

            const predObj = getPredikatAdabObj(totalScale10);
            const badgeEl = document.getElementById('rubrikPredikatBadge');
            if (badgeEl) {
                badgeEl.className = `px-2.5 py-1 text-[11px] font-extrabold rounded-lg ${predObj.badgeBg} text-white shadow-sm`;
                badgeEl.innerText = `${predObj.predikat} (${predObj.grade})`;
            }
        }

        function applySelectedMateriToForm() {
            const selectEl = document.getElementById('adabActiveMateriSelect');
            const manualContainer = document.getElementById('manualMateriContainer');
            if (!selectEl || !selectEl.value) return;

            if (selectEl.value === 'manual') {
                if (manualContainer) manualContainer.classList.remove('hidden');
                updateManualMateriPreview();
                return;
            }

            if (manualContainer) manualContainer.classList.add('hidden');

            const id = parseInt(selectEl.value);
            const item = BANK_MATERI_DATA.find(m => m.id === id) || BANK_MATERI_DATA[0];

            if (item) {
                document.getElementById('cardMateriTitle').innerText = item.judul;
                document.getElementById('cardMateriMeta').innerText = `Kelas ${item.kelas} | ${item.pekan}`;
                document.getElementById('cardMateriDesc').innerText = item.deskripsi;
            }
        }

        function updateManualMateriPreview() {
            const j = document.getElementById('manualMateriJudul').value.trim() || "Materi Adab Kustom (Manual)";
            const m = document.getElementById('manualMateriMeta').value.trim() || "Semua Kelas | Pekan 1";
            const d = document.getElementById('manualMateriDesc').value.trim() || "Deskripsi materi adab kustom manual...";

            document.getElementById('cardMateriTitle').innerText = j;
            document.getElementById('cardMateriMeta').innerText = m;
            document.getElementById('cardMateriDesc').innerText = d;
        }

        // ================= SAVE MENTOR JOURNAL SESSION =================
        function handleSimpanJurnal(e) {
            e.preventDefault();

            const tahun = document.getElementById('tahunAjaranSelect').value;
            const mentor = document.getElementById('namaMentorSelect').value;
            const mentee = document.getElementById('namaMenteeSelect').value;
            const pekan = document.getElementById('pekanSelect').value;
            const hari = document.getElementById('hariSelect').value;
            const tanggal = document.getElementById('tanggalSelect').value;

            const skor1 = parseFloat(document.getElementById('skorMIP').value) || 8;
            const catatan1 = document.getElementById('catatanMIP').value.trim();

            const skor2 = parseFloat(document.getElementById('skorAkademik').value) || 8;
            const catatan2 = document.getElementById('catatanAkademik').value.trim();

            const skor3 = parseFloat(document.getElementById('skorMutabaah').value) || 8;
            const catatan3 = document.getElementById('catatanMutabaah').value.trim();

            const skorA_adab = parseFloat(document.getElementById('skorPenugasanAdab').value) || 12;
            const skorB_adab = parseFloat(document.getElementById('skorRefleksiAdab').value) || 85;
            const skor4 = parseFloat(document.getElementById('skorAdab').value) || 8.5;
            const catatan4 = document.getElementById('catatanAdab').value.trim();

            const pekanFullMap = { "P1": "Pekan 1", "P2": "Pekan 2", "P3": "Pekan 3", "P4": "Pekan 4" };
            const pekanFull = pekanFullMap[pekan] || pekan;

            const newEntry = {
                id: Date.now(),
                tahunAjaran: tahun,
                mentor: mentor,
                mentee: mentee,
                namaMentee: mentee,
                pekan: pekanFull,
                sesiKey: pekan,
                hari: hari,
                tanggal: tanggal,
                scoreMIP: skor1, catatanMIP: catatan1,
                scoreAkademik: skor2, catatanAkademik: catatan2,
                scoreMutabaah: skor3, catatanMutabaah: catatan3,
                skorPenugasanAdab: skorA_adab,
                skorRefleksiAdab: skorB_adab,
                scoreAdab: skor4, skorAdab: skor4, catatanAdab: catatan4,
                timestamp: new Date().toISOString()
            };

            const idx = MENTORING_REKAP_DATA.findIndex(r => r.mentee === mentee && r.sesiKey === pekan);
            if (idx >= 0) {
                MENTORING_REKAP_DATA[idx] = newEntry;
            } else {
                MENTORING_REKAP_DATA.push(newEntry);
            }

            saveLocalRekapStore();

            const siswaObj = MASTER_SISWA_LIST.find(s => s.mentee === mentee);
            if (siswaObj) {
                const key = `${siswaObj.nis}_September_${pekanFull}`;
                if (!SISWA_ADAB_STORE[key]) {
                    SISWA_ADAB_STORE[key] = {
                        nis: siswaObj.nis,
                        namaMurid: mentee,
                        kelas: siswaObj.kelas,
                        bulan: "September",
                        pekan: pekanFull,
                        checklist: {},
                        refleksi1: "-", refleksi2: "-", refleksi3: "-", refleksi4: "-"
                    };
                }
                SISWA_ADAB_STORE[key].mentorGrade = {
                    skorPenugasan: skorA_adab,
                    skorRefleksi: skorB_adab,
                    totalSkor: skor4,
                    predikat: getPredikatAdabObj(skor4).predikat,
                    catatanMentor: catatan4,
                    mentorName: mentor,
                    timestamp: new Date().toISOString()
                };
                saveLocalAdabStore();
            }

            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.simpanDataMentoring(newEntry);
            }

            showToast(`Data mentoring ${mentee} (${pekanFull}) berhasil disimpan!`);
            renderRekapMatrixTable();
        }

        // ================= BANK MATERI MODULE (EDIT, MANUAL & EXCEL UPLOAD FORMAT) =================
        function renderBankMateriCards() {
            const filterKelas = document.getElementById('materiFilterKelas').value;
            const filterPekan = document.getElementById('materiFilterPekan').value;
            const container = document.getElementById('materiCardsList');

            const filtered = BANK_MATERI_DATA.filter(m => {
                const kStr = (m.kelas || '').toString().toLowerCase();
                const pStr = (m.pekan || '').toString().toLowerCase();
                const fK = filterKelas.toLowerCase();
                const fP = filterPekan.toLowerCase();

                const matchK = (fK === 'semua' || kStr === 'semua' || kStr.includes(fK));
                const matchP = (fP === 'semua' || pStr === 'semua' || pStr.includes(fP));
                return matchK && matchP;
            });

            if (filtered.length === 0) {
                container.innerHTML = `<div class="p-4 text-center text-xs text-slate-400 italic bg-white rounded-lg border">Tidak ada materi.</div>`;
                return;
            }

            let html = '';
            filtered.forEach(item => {
                const tasks = item.penugasanTasks || ["Task 1", "Task 2", "Task 3", "Task 4"];
                const refs = item.refleksiQuestions || ["Ref 1", "Ref 2", "Ref 3", "Ref 4"];

                html += `
                <div class="p-4 bg-white rounded-xl border border-purple-100 shadow-sm hover:shadow transition space-y-2">
                    <div class="flex items-center justify-between flex-wrap gap-2 border-b border-purple-100 pb-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-extrabold text-purple-900">${escapeHtml(item.judul)}</span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-purple-100 text-purple-700 border border-purple-200">Kelas ${escapeHtml(item.kelas)}</span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 border border-emerald-200">${escapeHtml(item.pekan)}</span>
                        </div>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <button type="button" onclick="selectMateriDirectly(${item.id})" class="px-2.5 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold transition cursor-pointer" title="Gunakan Di Form">
                                <i class="fa-solid fa-check mr-1"></i> Gunakan
                            </button>
                            <button type="button" onclick="openModalEditMateri(${item.id})" class="px-2.5 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs font-bold transition cursor-pointer" title="Edit Bank Materi Adab">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                            <button type="button" onclick="deleteBankMateri(${item.id})" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold transition cursor-pointer" title="Hapus Bank Materi">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-600 font-medium">${escapeHtml(item.deskripsi)}</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-[10.5px] pt-1">
                        <div class="bg-teal-50 p-2 rounded-lg border border-teal-200">
                            <strong class="block text-teal-900 font-bold mb-1">Indikator Penugasan Adab (${tasks.length} Kolom):</strong>
                            <ol class="list-decimal list-inside text-teal-800 space-y-0.5">
                                ${tasks.map(t => `<li>${escapeHtml(t)}</li>`).join('')}
                            </ol>
                        </div>
                        <div class="bg-purple-50 p-2 rounded-lg border border-purple-200">
                            <strong class="block text-purple-900 font-bold mb-1">Pertanyaan Refleksi Adab (${refs.length} Kolom):</strong>
                            <ol class="list-decimal list-inside text-purple-800 space-y-0.5">
                                ${refs.map(r => `<li>${escapeHtml(r)}</li>`).join('')}
                            </ol>
                        </div>
                    </div>
                </div>`;
            });

            container.innerHTML = html;
        }

        function openModalTambahMateri() {
            editingMateriId = null;
            const titleEl = document.getElementById('modalMateriHeaderTitle');
            if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-plus-circle text-purple-600"></i> Tambah Materi Adab & Penugasan Baru`;
            
            const form = document.getElementById('formTambahMateri');
            if (form) form.reset();
            
            const taskContainer = document.getElementById('materiTasksListContainer');
            const refContainer = document.getElementById('materiRefsListContainer');
            if (taskContainer) taskContainer.innerHTML = '';
            if (refContainer) refContainer.innerHTML = '';

            document.getElementById('modalTambahMateri').classList.remove('hidden');
            resetModalMateriDynamicInputs();
        }

        function openModalEditMateri(id) {
            const item = BANK_MATERI_DATA.find(m => m.id === id);
            if (!item) return;

            editingMateriId = id;
            const titleEl = document.getElementById('modalMateriHeaderTitle');
            if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-pen-to-square text-amber-500"></i> Edit Bank Materi Adab`;

            document.getElementById('materiInputJudul').value = item.judul || '';
            document.getElementById('materiInputKelas').value = item.kelas || 'Semua';
            document.getElementById('materiInputPekan').value = item.pekan || 'Pekan 1';
            document.getElementById('materiInputDeskripsi').value = item.deskripsi || '';
            document.getElementById('materiInputFileUrl').value = item.fileUrl || '';

            const taskContainer = document.getElementById('materiTasksListContainer');
            const refContainer = document.getElementById('materiRefsListContainer');
            if (taskContainer) taskContainer.innerHTML = '';
            if (refContainer) refContainer.innerHTML = '';

            const tasks = item.penugasanTasks || [];
            if (tasks.length > 0) {
                tasks.forEach(t => addMateriTaskRow(t));
            } else {
                addMateriTaskRow("");
            }

            const refs = item.refleksiQuestions || [];
            if (refs.length > 0) {
                refs.forEach(r => addMateriRefRow(r));
            } else {
                addMateriRefRow("");
            }

            document.getElementById('modalTambahMateri').classList.remove('hidden');
        }

        function closeModalTambahMateri() {
            editingMateriId = null;
            document.getElementById('modalTambahMateri').classList.add('hidden');
        }

        function resetModalMateriDynamicInputs() {
            const taskContainer = document.getElementById('materiTasksListContainer');
            const refContainer = document.getElementById('materiRefsListContainer');

            if (taskContainer && taskContainer.children.length === 0) {
                taskContainer.innerHTML = '';
                addMateriTaskRow("Niat ikhlas & ibadah harian");
                addMateriTaskRow("Shalat berjamaah 5 waktu");
                addMateriTaskRow("Tilawah & muroja'ah Al-Qur'an");
                addMateriTaskRow("Menjaga lisan & adab pergaulan");
            }

            if (refContainer && refContainer.children.length === 0) {
                refContainer.innerHTML = '';
                addMateriRefRow("1. Aksi Nyata Adab: Apa aksi nyata adab yang telah kamu lakukan?");
                addMateriRefRow("2. Hambatan & Tantangan: Apa hambatan terbesar yang kamu hadapi?");
                addMateriRefRow("3. Solusi & Rencana: Bagaimana rencanamu untuk memperbaiki adab esok hari?");
                addMateriRefRow("4. Catatan Syukur: Apa hal baik yang kamu pelajari dan syukuri hari ini?");
            }
        }

        function addMateriTaskRow(val = '') {
            const taskContainer = document.getElementById('materiTasksListContainer');
            if (!taskContainer) return;
            const idx = taskContainer.children.length + 1;

            const div = document.createElement('div');
            div.className = 'flex items-center gap-2 materi-task-row';
            div.innerHTML = `
                <span class="text-[11px] font-bold text-teal-800 w-5 text-center shrink-0">${idx}.</span>
                <input type="text" value="${escapeHtml(val)}" placeholder="Indikator Penugasan ..." required class="materi-task-input flex-1 p-2 bg-white border border-teal-300 rounded-lg text-xs">
                <button type="button" onclick="this.parentElement.remove(); reindexMateriRows();" class="p-2 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition shrink-0" title="Hapus Kolom"><i class="fa-solid fa-trash-can text-xs"></i></button>
            `;
            taskContainer.appendChild(div);
        }

        function addMateriRefRow(val = '') {
            const refContainer = document.getElementById('materiRefsListContainer');
            if (!refContainer) return;
            const idx = refContainer.children.length + 1;

            const div = document.createElement('div');
            div.className = 'flex items-center gap-2 materi-ref-row';
            div.innerHTML = `
                <span class="text-[11px] font-bold text-purple-800 w-5 text-center shrink-0">${idx}.</span>
                <input type="text" value="${escapeHtml(val)}" placeholder="Pertanyaan Refleksi ..." required class="materi-ref-input flex-1 p-2 bg-white border border-purple-300 rounded-lg text-xs">
                <button type="button" onclick="this.parentElement.remove(); reindexMateriRows();" class="p-2 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition shrink-0" title="Hapus Kolom"><i class="fa-solid fa-trash-can text-xs"></i></button>
            `;
            refContainer.appendChild(div);
        }

        function reindexMateriRows() {
            const taskRows = document.querySelectorAll('.materi-task-row');
            taskRows.forEach((row, i) => {
                const numSpan = row.querySelector('span');
                if (numSpan) numSpan.innerText = `${i + 1}.`;
                const input = row.querySelector('.materi-task-input');
                if (input) input.placeholder = `Indikator Penugasan ${i + 1}...`;
            });

            const refRows = document.querySelectorAll('.materi-ref-row');
            refRows.forEach((row, i) => {
                const numSpan = row.querySelector('span');
                if (numSpan) numSpan.innerText = `${i + 1}.`;
                const input = row.querySelector('.materi-ref-input');
                if (input) input.placeholder = `Pertanyaan Refleksi ${i + 1}...`;
            });
        }

        // ================= DYNAMIC COLUMNS UNTUK FORM MANUAL INPUT =================
        function addManualTaskRow(val = '') {
            const container = document.getElementById('manualTasksContainer');
            if (!container) return;
            const idx = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'flex items-center gap-1.5 manual-task-row';
            div.innerHTML = `
                <span class="text-[10px] font-bold text-teal-800 w-4 text-center shrink-0">${idx}.</span>
                <input type="text" value="${escapeHtml(val)}" placeholder="Penugasan ${idx}..." class="manual-task-input flex-1 p-1.5 bg-white border border-teal-300 rounded text-[11px]">
                <button type="button" onclick="this.parentElement.remove(); reindexManualRows();" class="p-1 text-rose-500 hover:text-rose-700 shrink-0" title="Hapus Kolom"><i class="fa-solid fa-xmark text-xs"></i></button>
            `;
            container.appendChild(div);
        }

        function addManualRefRow(val = '') {
            const container = document.getElementById('manualRefsContainer');
            if (!container) return;
            const idx = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'flex items-center gap-1.5 manual-ref-row';
            div.innerHTML = `
                <span class="text-[10px] font-bold text-purple-800 w-4 text-center shrink-0">${idx}.</span>
                <input type="text" value="${escapeHtml(val)}" placeholder="Pertanyaan Refleksi ${idx}..." class="manual-ref-input flex-1 p-1.5 bg-white border border-purple-300 rounded text-[11px]">
                <button type="button" onclick="this.parentElement.remove(); reindexManualRows();" class="p-1 text-rose-500 hover:text-rose-700 shrink-0" title="Hapus Kolom"><i class="fa-solid fa-xmark text-xs"></i></button>
            `;
            container.appendChild(div);
        }

        function reindexManualRows() {
            const tRows = document.querySelectorAll('.manual-task-row');
            tRows.forEach((r, i) => {
                const s = r.querySelector('span');
                if (s) s.innerText = `${i + 1}.`;
            });
            const rRows = document.querySelectorAll('.manual-ref-row');
            rRows.forEach((r, i) => {
                const s = r.querySelector('span');
                if (s) s.innerText = `${i + 1}.`;
            });
        }

        function selectMateriDirectly(id) {
            const selectEl = document.getElementById('adabActiveMateriSelect');
            if (selectEl) {
                selectEl.value = id;
                applySelectedMateriToForm();
            }
            showTab('form-tab');
            showToast("Materi adab dipilih untuk Form Mentoring!");
        }

        function handleSaveTambahMateri(e) {
            e.preventDefault();

            const taskInputs = document.querySelectorAll('.materi-task-input');
            const refInputs = document.querySelectorAll('.materi-ref-input');

            const penugasanTasks = Array.from(taskInputs).map(inp => inp.value.trim()).filter(Boolean);
            const refleksiQuestions = Array.from(refInputs).map(inp => inp.value.trim()).filter(Boolean);

            const matObj = {
                id: editingMateriId || Date.now(),
                judul: document.getElementById('materiInputJudul').value.trim(),
                kelas: document.getElementById('materiInputKelas').value,
                pekan: document.getElementById('materiInputPekan').value,
                deskripsi: document.getElementById('materiInputDeskripsi').value.trim(),
                fileUrl: document.getElementById('materiInputFileUrl').value.trim(),
                penugasanTasks: penugasanTasks.length > 0 ? penugasanTasks : ["Penugasan Adab 1"],
                refleksiQuestions: refleksiQuestions.length > 0 ? refleksiQuestions : ["Pertanyaan Refleksi 1"]
            };

            if (editingMateriId) {
                const idx = BANK_MATERI_DATA.findIndex(m => m.id === editingMateriId);
                if (idx >= 0) {
                    BANK_MATERI_DATA[idx] = matObj;
                } else {
                    BANK_MATERI_DATA.push(matObj);
                }
                showToast("Materi Adab berhasil diperbarui!");
            } else {
                BANK_MATERI_DATA.push(matObj);
                showToast("Materi baru berhasil ditambahkan ke Bank Materi!");
            }

            editingMateriId = null;
            saveMasterDataStores();
            populateAdabMateriSelect();
            renderBankMateriCards();
            closeModalTambahMateri();

            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.updateAllBankMateri(BANK_MATERI_DATA);
            }
        }

        function deleteBankMateri(id) {
            if (!confirm("Apakah Anda yakin ingin menghapus materi adab ini dari Bank Materi?")) return;
            BANK_MATERI_DATA = BANK_MATERI_DATA.filter(m => m.id !== id);
            saveMasterDataStores();
            populateAdabMateriSelect();
            renderBankMateriCards();
            if (typeof google !== 'undefined' && google.script && google.script.run) {
                google.script.run.updateAllBankMateri(BANK_MATERI_DATA);
            }
            showToast("Materi adab berhasil dihapus dari Bank Materi!");
        }

        // ================= CETAK PDF REKAPITULASI (UMUM & PER MENTOR) =================
        function printRekapPDF(mode = 'umum') {
            const printContainer = document.getElementById('printRekapPDFContainer');
            if (!printContainer) return;

            const activeRole = CURRENT_USER ? CURRENT_USER.role : 'admin';
            let selectedMentor = 'ALL';

            const dashSelect = document.getElementById('dashFilterPrintMentorSelect');
            if (dashSelect && dashSelect.value) {
                selectedMentor = dashSelect.value;
            }

            if (mode === 'mentor' && selectedMentor === 'ALL') {
                if (activeRole === 'mentor' && CURRENT_USER && CURRENT_USER.username) {
                    selectedMentor = CURRENT_USER.username;
                } else {
                    const mentorList = MASTER_MENTOR_DATA.map(m => m.mentor);
                    if (mentorList.length > 0) {
                        const choice = prompt("Masukkan / Pilih Nama Mentor untuk Cetak Rekap Per-Mentor:\n" + mentorList.join("\n"), mentorList[0]);
                        if (choice && choice.trim()) {
                            selectedMentor = choice.trim();
                        } else {
                            return;
                        }
                    }
                }
            }

            const isMentorMode = (mode === 'mentor' && selectedMentor !== 'ALL');
            const filterMentorName = isMentorMode ? selectedMentor : 'Semua Mentor';

            document.getElementById('prtRekapBadgeTitle').innerText = isMentorMode ? `REKAP MENTOR: ${filterMentorName}` : 'REKAPITULASI UMUM SEKOLAH';
            document.getElementById('prtRekapJenisText').innerText = isMentorMode ? `Per-Mentor (${filterMentorName})` : 'Secara Umum (Semua Mentor)';
            document.getElementById('prtRekapMentorText').innerText = filterMentorName;
            document.getElementById('prtRekapTanggalCetak').innerText = new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
            document.getElementById('prtRekapTtdTanggal').innerText = new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
            document.getElementById('prtRekapTtdNama').innerText = isMentorMode ? `( ${filterMentorName} )` : '( Koordinator Mentoring / Tim Mentoring )';

            let filteredSiswa = MASTER_SISWA_LIST;
            let filteredRekap = MENTORING_REKAP_DATA;

            if (isMentorMode) {
                filteredSiswa = MASTER_SISWA_LIST.filter(s => (s.col3 || s.mentor || '').toLowerCase().includes(filterMentorName.toLowerCase()));
                filteredRekap = MENTORING_REKAP_DATA.filter(r => (r.mentor || '').toLowerCase().includes(filterMentorName.toLowerCase()));
            }

            document.getElementById('prtRekapTotalMenteeSesi').innerText = `${filteredSiswa.length} Murid / ${filteredRekap.length} Sesi`;
            document.getElementById('prtRekapStatMentee').innerText = filteredSiswa.length;
            document.getElementById('prtRekapStatSesi').innerText = filteredRekap.length;

            let sumScore = 0, count = 0;
            let sumMIP = 0, sumAkad = 0, sumMut = 0, sumAdab = 0;

            filteredRekap.forEach(r => {
                const m = parseFloat(r.scoreMIP) || 0;
                const ak = parseFloat(r.scoreAkademik) || 0;
                const mu = parseFloat(r.scoreMutabaah) || 0;
                const ad = parseFloat(r.scoreAdab) || 0;
                sumMIP += m; sumAkad += ak; sumMut += mu; sumAdab += ad;
                if (ad > 0) {
                    sumScore += (m + ak + mu + ad) / 4;
                    count++;
                }
            });

            const avgGen = count > 0 ? (sumScore / count) : 8.2;
            document.getElementById('prtRekapStatAvg').innerText = avgGen.toFixed(1);
            const predObjGen = getPredikatAdabObj(avgGen);
            document.getElementById('prtRekapStatGrade').innerText = `${predObjGen.predikat} (${predObjGen.grade})`;

            const avgMIP = count > 0 ? (sumMIP / count) : 8.4;
            const avgAkad = count > 0 ? (sumAkad / count) : 8.1;
            const avgMut = count > 0 ? (sumMut / count) : 8.5;
            const avgAdab = count > 0 ? (sumAdab / count) : 8.6;

            const diagContainer = document.getElementById('prtRekapAspectsBarDiagram');
            const aspects = [
                { name: "1. MIP", score: avgMIP, color: "bg-teal-600" },
                { name: "2. Akademik", score: avgAkad, color: "bg-blue-600" },
                { name: "3. Mutaba'ah", score: avgMut, color: "bg-emerald-600" },
                { name: "4. Adab", score: avgAdab, color: "bg-purple-600" }
            ];

            let diagHtml = '';
            aspects.forEach(a => {
                const pct = Math.min(100, Math.round((a.score / 10) * 100));
                diagHtml += `
                <div class="print-aspect-card p-1.5 bg-slate-50 border border-slate-200 rounded-lg">
                    <div class="flex justify-between text-[9px] font-bold text-slate-700 mb-0.5">
                        <span>${a.name}</span>
                        <span>${a.score.toFixed(1)}</span>
                    </div>
                    <div class="w-full h-2.5 bg-slate-200 rounded-full overflow-hidden flex">
                        <div class="h-full ${a.color} print-bar-fill rounded-full" style="width: ${pct}%;"></div>
                    </div>
                </div>`;
            });
            diagContainer.innerHTML = diagHtml;

            const tableBody = document.getElementById('prtRekapTableBody');
            let tbodyHtml = '';

            if (filteredSiswa.length === 0) {
                tbodyHtml = `<tr><td colspan="11" class="p-3 text-center text-slate-400 italic">Tidak ada data murid / rekap.</td></tr>`;
            } else {
                filteredSiswa.forEach((s, idx) => {
                    const menteeName = s.col2 || s.mentee || s.namaMurid || "-";
                    const nis = s.col1 || s.nis || "-";
                    const kelas = s.col4 || s.kelas || "-";
                    const mentorName = s.col3 || s.mentor || "-";

                    const menteeEntries = filteredRekap.filter(r => r.mentee === menteeName);
                    let rMIP = 0, rAkad = 0, rMut = 0, rAdab = 0, rCount = 0;

                    menteeEntries.forEach(re => {
                        rMIP += parseFloat(re.scoreMIP) || 0;
                        rAkad += parseFloat(re.scoreAkademik) || 0;
                        rMut += parseFloat(re.scoreMutabaah) || 0;
                        rAdab += parseFloat(re.scoreAdab) || 0;
                        rCount++;
                    });

                    const avgM = rCount > 0 ? (rMIP / rCount) : 8.0;
                    const avgAk = rCount > 0 ? (rAkad / rCount) : 8.0;
                    const avgMu = rCount > 0 ? (rMut / rCount) : 8.0;
                    const avgAd = rCount > 0 ? (rAdab / rCount) : 8.5;
                    const finalAvg = (avgM + avgAk + avgMu + avgAd) / 4;
                    const pObj = getPredikatAdabObj(finalAvg);

                    tbodyHtml += `
                    <tr class="${idx % 2 === 0 ? 'bg-white' : 'bg-slate-50/60'}">
                        <td class="p-1 border border-slate-300 text-center font-bold text-[9pt]">${idx + 1}</td>
                        <td class="p-1 border border-slate-300 text-center font-semibold text-[9pt]">${nis}</td>
                        <td class="p-1 border border-slate-300 font-bold text-slate-900 text-[9pt]">${escapeHtml(menteeName)}</td>
                        <td class="p-1 border border-slate-300 text-center font-semibold text-[9pt]">${kelas}</td>
                        <td class="p-1 border border-slate-300 font-medium text-slate-700 text-[9pt]">${escapeHtml(mentorName)}</td>
                        <td class="p-1 border border-slate-300 text-center font-bold text-[9pt]">${avgM.toFixed(1)}</td>
                        <td class="p-1 border border-slate-300 text-center font-bold text-[9pt]">${avgAk.toFixed(1)}</td>
                        <td class="p-1 border border-slate-300 text-center font-bold text-[9pt]">${avgMu.toFixed(1)}</td>
                        <td class="p-1 border border-slate-300 text-center font-bold text-slate-900 text-[9pt]">${avgAd.toFixed(1)}</td>
                        <td class="p-1 border border-slate-300 text-center font-black text-brand-700 text-[9pt]">${finalAvg.toFixed(1)}</td>
                        <td class="p-1 border border-slate-300 text-center font-extrabold text-[8.5pt]">${pObj.predikat}</td>
                    </tr>`;
                });
            }

            tableBody.innerHTML = tbodyHtml;

            printContainer.classList.remove('hidden');
            window.print();
            setTimeout(() => {
                printContainer.classList.add('hidden');
            }, 1000);
        }

        function triggerExcelUpload() {
            document.getElementById('excelFileInput').click();
        }

        function handleExcelFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                const text = e.target.result;
                parseCSVorExcelText(text);
            };
            reader.readAsText(file);
        }

        function parseCSVorExcelText(text) {
            const lines = text.split('\n');
            let addedCount = 0;

            lines.forEach((line, idx) => {
                if (idx === 0 || !line.trim()) return;
                const cols = line.split(/[,;\t]/).map(c => c.replace(/^["']|["']$/g, '').trim());

                if (cols.length >= 4) {
                    const newMat = {
                        id: Date.now() + idx,
                        judul: cols[0] || "Materi Adab",
                        kelas: cols[1] || "Semua",
                        pekan: cols[2] || "Pekan 1",
                        deskripsi: cols[3] || "-",
                        fileUrl: cols[4] || "",
                        penugasanTasks: [cols[5]||"Task 1", cols[6]||"Task 2", cols[7]||"Task 3", cols[8]||"Task 4"],
                        refleksiQuestions: [cols[9]||"Ref 1", cols[10]||"Ref 2", cols[11]||"Ref 3", cols[12]||"Ref 4"]
                    };
                    BANK_MATERI_DATA.push(newMat);
                    addedCount++;
                }
            });

            if (addedCount > 0) {
                populateAdabMateriSelect();
                renderBankMateriCards();
                if (typeof google !== 'undefined' && google.script && google.script.run) {
                    google.script.run.simpanBankMateri(BANK_MATERI_DATA);
                }
                showToast(`Berhasil mengimpor ${addedCount} materi dari file Excel/CSV!`);
            } else {
                showToast("Format file tidak sesuai template Excel!", "error");
            }
        }

        function downloadTemplateExcelMateri() {
            const headers = ["Judul Materi", "Kelas", "Pekan", "Deskripsi Materi", "Link File", "Penugasan 1", "Penugasan 2", "Penugasan 3", "Penugasan 4", "Refleksi 1", "Refleksi 2", "Refleksi 3", "Refleksi 4"];
            const example = [
                "7. Adab Kepada Allah Ã¯Â·Â»", "7", "Pekan 1", "Materi adab niat ikhlas dan shalat", "https://drive.google.com/sample",
                "Niat ikhlas ibadah", "Shalat 5 waktu berjamaah", "Tilawah Qur'an harian", "Menjaga lisan",
                "Aksi nyata adab pekan ini", "Hambatan kekhusyukan", "Rencana perbaikan ibadah", "Catatan rasa syukur"
            ];
            const csvContent = "data:text/csv;charset=utf-8," + [headers.join(","), example.join(",")].join("\n");
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "Template_Bank_Materi_Adab_MIP.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // ================= REKAP MATRIX & REPORT DETAIL MODULE =================
        function renderRekapMatrixTable() {
            const filterMentee = document.getElementById('rekapFilterMenteeSelect').value;
            const tbody = document.getElementById('rekapMatrixTbody');
            const badge = document.getElementById('rekapCounterBadge');

            const filtered = MENTORING_REKAP_DATA.filter(r => !filterMentee || r.mentee === filterMentee || r.namaMentee === filterMentee);
            badge.innerText = `${filtered.length} Data`;

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" class="p-4 text-center italic text-slate-400">Belum ada data rekap mentoring.</td></tr>`;
                return;
            }

            let html = '';
            filtered.forEach((r, idx) => {
                html += `
                <tr class="hover:bg-slate-50 transition">
                    <td class="p-3 text-center font-bold text-slate-400">${idx + 1}</td>
                    <td class="p-3">
                        <strong class="block text-slate-800">${r.tanggal || '-'}</strong>
                        <span class="text-[10px] text-brand-700 font-bold bg-brand-50 px-1.5 py-0.5 rounded border border-brand-200">${r.pekan || '-'}</span>
                    </td>
                    <td class="p-3 font-bold text-slate-900">${r.mentee || r.namaMentee}</td>
                    <td class="p-3 text-slate-600">${r.mentor}</td>
                    <td class="p-3 text-center font-bold text-slate-800">${r.scoreMIP || '-'}</td>
                    <td class="p-3 text-center font-bold text-slate-800">${r.scoreAkademik || '-'}</td>
                    <td class="p-3 text-center font-bold text-slate-800">${r.scoreMutabaah || '-'}</td>
                    <td class="p-3 text-center font-bold text-purple-900 bg-purple-50">${r.scoreAdab || '-'}</td>
                    <td class="p-3 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button onclick="editRekapEntry(${r.id})" class="p-1.5 text-blue-600 hover:bg-blue-50 rounded transition" title="Edit Data">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button onclick="deleteRekapEntry(${r.id})" class="p-1.5 text-rose-600 hover:bg-rose-50 rounded transition" title="Hapus Data">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        function editRekapEntry(id) {
            const entry = MENTORING_REKAP_DATA.find(r => r.id === id);
            if (!entry) return;

            document.getElementById('namaMentorSelect').value = entry.mentor;
            filterMenteeByMentor();
            document.getElementById('namaMenteeSelect').value = entry.mentee || entry.namaMentee;
            document.getElementById('pekanSelect').value = entry.sesiKey || "P1";
            document.getElementById('hariSelect').value = entry.hari || "Selasa";
            if (entry.tanggal) document.getElementById('tanggalSelect').value = entry.tanggal;

            document.getElementById('skorMIP').value = entry.scoreMIP || 8;
            document.getElementById('catatanMIP').value = entry.catatanMIP || '';

            document.getElementById('skorAkademik').value = entry.scoreAkademik || 8;
            document.getElementById('catatanAkademik').value = entry.catatanAkademik || '';

            document.getElementById('skorMutabaah').value = entry.scoreMutabaah || 8;
            document.getElementById('catatanMutabaah').value = entry.catatanMutabaah || '';

            if (entry.skorPenugasanAdab) document.getElementById('skorPenugasanAdab').value = entry.skorPenugasanAdab;
            if (entry.skorRefleksiAdab) document.getElementById('skorRefleksiAdab').value = entry.skorRefleksiAdab;
            document.getElementById('skorAdab').value = entry.scoreAdab || 8;
            document.getElementById('catatanAdab').value = entry.catatanAdab || '';

            calculateRubrikAdabScore();
            showTab('form-tab');
            showToast(`Mengedit data rekap ${entry.mentee} (${entry.pekan})`);
        }

        function deleteRekapEntry(id) {
            if (!confirm("Apakah Anda yakin ingin menghapus data rekap mentoring ini?")) return;
            MENTORING_REKAP_DATA = MENTORING_REKAP_DATA.filter(r => r.id !== id);
            saveLocalRekapStore();
            renderRekapMatrixTable();
            showToast("Data rekap berhasil dihapus.");
        }

        // ================= HELPER MONTH & YEAR PARSERS =================
        function getEntryMonth(r) {
            if (r.bulan && r.bulan.trim()) return r.bulan.trim();
            if (r.tanggal && r.tanggal.trim()) {
                const d = new Date(r.tanggal);
                if (!isNaN(d.getTime())) {
                    const months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
                    return months[d.getMonth()];
                }
            }
            return "September";
        }

        function getEntryYear(r) {
            if (r.tahun && r.tahun.trim()) return r.tahun.trim();
            if (r.tanggal && r.tanggal.trim()) {
                const d = new Date(r.tanggal);
                if (!isNaN(d.getTime())) {
                    return d.getFullYear().toString();
                }
            }
            if (r.tahunAjaran && r.tahunAjaran.includes('/')) {
                return r.tahunAjaran.split('/')[0].trim();
            }
            return "2026";
        }

        // ================= REPORT DETAIL MODULE WITH FULL PRINT-COLOR-ADJUST & STRICT MONTH/YEAR FILTER =================
        function renderStudentReportDetail() {
            const selectedMentee = document.getElementById('reportFilterMenteeSelect').value || (MASTER_SISWA_LIST[0] ? MASTER_SISWA_LIST[0].mentee : '');
            const selectedBulan = document.getElementById('reportFilterBulanSelect') ? document.getElementById('reportFilterBulanSelect').value : 'ALL';
            const selectedTahun = document.getElementById('reportFilterTahunSelect') ? document.getElementById('reportFilterTahunSelect').value : 'ALL';

            if (!selectedMentee) return;

            const siswaObj = MASTER_SISWA_LIST.find(s => s.mentee === selectedMentee) || { mentee: selectedMentee, nis: "262707001", kelas: "7A", mentor: "Ust. Mentor" };

            // Synchronize Student Name, Class, & Mentor Name
            document.getElementById('rptNamaMentee').innerText = siswaObj.mentee;
            document.getElementById('rptNisKelas').innerText = `${siswaObj.nis} / ${siswaObj.kelas}`;
            document.getElementById('rptNamaMentor').innerText = siswaObj.mentor;
            
            // SYNCHRONIZE MENTOR SIGNATURE BLOCK WITH SPECIFIC MENTOR NAME!
            const ttdMentorEl = document.getElementById('rptTtdMentorNama');
            if (ttdMentorEl) {
                ttdMentorEl.innerText = siswaObj.mentor || "Ust. Mentor";
            }

            // Period Text Synchronization
            let txtPeriode = "Pekan 1 s/d Pekan 4";
            if (selectedBulan !== 'ALL' || selectedTahun !== 'ALL') {
                const bStr = selectedBulan !== 'ALL' ? selectedBulan : '';
                const tStr = selectedTahun !== 'ALL' ? selectedTahun : '';
                txtPeriode += ` (${(bStr + ' ' + tStr).trim()})`;
            }
            const rptPeriodeEl = document.getElementById('rptPeriodeText');
            if (rptPeriodeEl) rptPeriodeEl.innerText = txtPeriode;

            // Strict Filter Entries by Mentee, Month, and Year
            const entries = MENTORING_REKAP_DATA.filter(r => {
                const isMenteeMatch = (r.mentee === selectedMentee || r.namaMentee === selectedMentee);
                if (!isMenteeMatch) return false;

                const entryMonth = getEntryMonth(r);
                const entryYear = getEntryYear(r);
                
                if (selectedBulan !== 'ALL' && entryMonth !== selectedBulan) return false;
                if (selectedTahun !== 'ALL' && entryYear !== selectedTahun) return false;
                return true;
            });

            const tbody = document.getElementById('rptAspectsTableBody');

            const getVal = (pekanKey, field) => {
                const item = entries.find(r => r.sesiKey === pekanKey || r.pekan === pekanKey);
                return item && item[field] !== undefined && item[field] !== null && item[field] !== '' ? item[field] : '-';
            };

            const getNotes = (fieldCatatan) => {
                return entries.map(e => e[fieldCatatan]).filter(Boolean).join('; ') || 'Perkembangan terpantau sangat baik.';
            };

            tbody.innerHTML = `
            <tr>
                <td class="p-2.5 border border-slate-300 font-bold">1. MIP (Mission Is Possible)</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P1', 'scoreMIP')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P2', 'scoreMIP')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P3', 'scoreMIP')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P4', 'scoreMIP')}</td>
                <td class="p-2.5 border border-slate-300 text-slate-600 italic text-[10px] font-normal">${getNotes('catatanMIP')}</td>
            </tr>
            <tr>
                <td class="p-2.5 border border-slate-300 font-bold">2. Akademik & Non-Akademik</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P1', 'scoreAkademik')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P2', 'scoreAkademik')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P3', 'scoreAkademik')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P4', 'scoreAkademik')}</td>
                <td class="p-2.5 border border-slate-300 text-slate-600 italic text-[10px] font-normal">${getNotes('catatanAkademik')}</td>
            </tr>
            <tr>
                <td class="p-2.5 border border-slate-300 font-bold">3. Mutaba'ah Yaumiyah</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P1', 'scoreMutabaah')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P2', 'scoreMutabaah')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P3', 'scoreMutabaah')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold">${getVal('P4', 'scoreMutabaah')}</td>
                <td class="p-2.5 border border-slate-300 text-slate-600 italic text-[10px] font-normal">${getNotes('catatanMutabaah')}</td>
            </tr>
            <tr>
                <td class="p-2.5 border border-slate-300 font-bold bg-purple-50" style="background-color: #faf5ff !important;">4. Adab & Karakter</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold bg-purple-50 text-purple-900" style="background-color: #faf5ff !important;">${getVal('P1', 'scoreAdab')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold bg-purple-50 text-purple-900" style="background-color: #faf5ff !important;">${getVal('P2', 'scoreAdab')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold bg-purple-50 text-purple-900" style="background-color: #faf5ff !important;">${getVal('P3', 'scoreAdab')}</td>
                <td class="p-2.5 border border-slate-300 text-center font-bold bg-purple-50 text-purple-900" style="background-color: #faf5ff !important;">${getVal('P4', 'scoreAdab')}</td>
                <td class="p-2.5 border border-slate-300 text-slate-600 italic text-[10px] font-normal">${getNotes('catatanAdab')}</td>
            </tr>`;

            renderReportAspectsBarDiagram(entries);

            // Fetch student's adab entry strictly matching NIS & Selected Month
            let adabEntry = null;
            if (selectedBulan !== 'ALL') {
                for (let k in SISWA_ADAB_STORE) {
                    if (k.startsWith(siswaObj.nis) && k.includes(selectedBulan)) {
                        adabEntry = SISWA_ADAB_STORE[k];
                        break;
                    }
                }
            } else {
                for (let k in SISWA_ADAB_STORE) {
                    if (k.startsWith(siswaObj.nis)) {
                        adabEntry = SISWA_ADAB_STORE[k];
                        break;
                    }
                }
            }

            if (adabEntry) {
                document.getElementById('rptRefleksi1').innerText = adabEntry.refleksi1 || '--';
                document.getElementById('rptRefleksi2').innerText = adabEntry.refleksi2 || '--';
                document.getElementById('rptRefleksi3').innerText = adabEntry.refleksi3 || '--';
                document.getElementById('rptRefleksi4').innerText = adabEntry.refleksi4 || '--';
            } else {
                const noDataMsg = selectedBulan !== 'ALL' ? `-- (Belum ada isian refleksi adab untuk bulan ${selectedBulan}) --` : '--';
                document.getElementById('rptRefleksi1').innerText = noDataMsg;
                document.getElementById('rptRefleksi2').innerText = noDataMsg;
                document.getElementById('rptRefleksi3').innerText = noDataMsg;
                document.getElementById('rptRefleksi4').innerText = noDataMsg;
            }
        }

        function renderReportAspectsBarDiagram(entries) {
            const diagramContainer = document.getElementById('rptAspectsBarDiagram');
            if (!diagramContainer) return;

            const aspectConfigs = [
                { key: 'scoreMIP', label: '1. MIP', hexColor: '#10b981', colorClass: 'bg-emerald-500', border: 'border-emerald-200', bg: 'bg-emerald-50/60' },
                { key: 'scoreAkademik', label: '2. Akademik', hexColor: '#3b82f6', colorClass: 'bg-blue-500', border: 'border-blue-200', bg: 'bg-blue-50/60' },
                { key: 'scoreMutabaah', label: '3. Mutaba\'ah', hexColor: '#f59e0b', colorClass: 'bg-amber-500', border: 'border-amber-200', bg: 'bg-amber-50/60' },
                { key: 'scoreAdab', label: '4. Adab & Karakter', hexColor: '#9333ea', colorClass: 'bg-purple-600', border: 'border-purple-200', bg: 'bg-purple-50/60' }
            ];

            let html = '';
            aspectConfigs.forEach(aspect => {
                const scores = ['P1', 'P2', 'P3', 'P4'].map(pKey => {
                    const item = entries.find(r => r.sesiKey === pKey || r.pekan === pKey);
                    const val = item ? parseFloat(item[aspect.key]) : NaN;
                    return isNaN(val) ? null : val;
                });

                const validScores = scores.filter(s => s !== null);
                let trendBadge = '';
                let avgText = '-';

                if (validScores.length > 0) {
                    const initialScore = validScores[0];
                    const latestScore = validScores[validScores.length - 1];
                    const delta = latestScore - initialScore;
                    const avg = validScores.reduce((a, b) => a + b, 0) / validScores.length;
                    avgText = avg.toFixed(1);

                    if (delta > 0) {
                        trendBadge = `<span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center gap-0.5 shrink-0 print-bar-fill" style="background-color: #d1fae5 !important; color: #065f46 !important; border-color: #6ee7b7 !important;"><i class="fa-solid fa-arrow-trend-up text-emerald-600"></i> Naik (+${delta.toFixed(1)})</span>`;
                    } else if (delta < 0) {
                        trendBadge = `<span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 flex items-center gap-0.5 shrink-0 print-bar-fill" style="background-color: #ffe4e6 !important; color: #9f1239 !important; border-color: #fca5a5 !important;"><i class="fa-solid fa-arrow-trend-down text-rose-600"></i> Turun (${delta.toFixed(1)})</span>`;
                    } else {
                        trendBadge = `<span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-blue-100 text-blue-800 border border-blue-300 flex items-center gap-0.5 shrink-0 print-bar-fill" style="background-color: #dbeafe !important; color: #1e40af !important; border-color: #93c5fd !important;"><i class="fa-solid fa-minus text-blue-600"></i> Stabil</span>`;
                    }
                } else {
                    trendBadge = `<span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-slate-100 text-slate-500 shrink-0">Belum Ada Data</span>`;
                }

                html += `
                <div class="p-2.5 ${aspect.bg} border ${aspect.border} rounded-xl flex flex-col justify-between space-y-2 shadow-sm print-aspect-card">
                    <!-- Card Header -->
                    <div class="flex items-center justify-between gap-1 border-b border-slate-200/80 pb-1">
                        <span class="text-[11px] font-extrabold text-slate-800 truncate">${aspect.label}</span>
                        ${trendBadge}
                    </div>
                    
                    <!-- Proportional Overlap-Free Chart Container -->
                    <div class="bg-white/90 p-2 rounded-lg border border-slate-200/60 flex flex-col justify-between space-y-1 print-bar-container">
                        <!-- Score Numbers Row -->
                        <div class="grid grid-cols-4 gap-1 text-center text-[9px] font-extrabold text-slate-700">
                            ${scores.map(s => `<span>${s !== null ? s.toFixed(1) : '-'}</span>`).join('')}
                        </div>
                        
                        <!-- Vertical Bars Row -->
                        <div class="grid grid-cols-4 gap-1 items-end h-14 py-0.5">
                            ${scores.map(s => {
                                const heightPct = s !== null ? Math.min(100, Math.max(15, Math.round((s / 10) * 100))) : 0;
                                return `
                                <div class="w-full bg-slate-100 rounded-t h-full flex items-end overflow-hidden" style="background-color: #f1f5f9 !important;">
                                    <div class="w-full ${aspect.colorClass} transition-all duration-300 rounded-t print-bar-fill" style="height: ${heightPct}%; background-color: ${aspect.hexColor} !important;"></div>
                                </div>`;
                            }).join('')}
                        </div>

                        <!-- Pekan Labels Row (Dedicated Row - Zero Overlap) -->
                        <div class="grid grid-cols-4 gap-1 text-center text-[9px] font-extrabold text-slate-600 border-t border-slate-100 pt-0.5">
                            <span>P1</span>
                            <span>P2</span>
                            <span>P3</span>
                            <span>P4</span>
                        </div>
                    </div>
                    
                    <!-- Card Footer Summary -->
                    <div class="flex items-center justify-between text-[10px] text-slate-600 font-medium pt-0.5">
                        <span>Rata-rata: <strong class="text-slate-900 font-bold">${avgText}</strong></span>
                        <span class="text-[9px] font-semibold text-slate-400">Skala 10</span>
                    </div>
                </div>`;
            });

            diagramContainer.innerHTML = html;
        }

        // ================= HTML ESCAPE UTILITY =================
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // ================= ADMIN TABLES & USERS MODULE (ROBUST INDEX-BASED HANDLERS) =================
        function renderAdminTables() {
            const usersTbody = document.getElementById('adminUsersTableBody');
            const siswaTbody = document.getElementById('adminSiswaTableBody');

            if (usersTbody) {
                let html = '';
                MASTER_MENTOR_DATA.forEach((m, idx) => {
                    html += `
                    <tr>
                        <td class="p-2.5 font-bold text-slate-400 text-center">${idx + 1}</td>
                        <td class="p-2.5 font-extrabold text-slate-800">${escapeHtml(m.mentor)}</td>
                        <td class="p-2.5"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-brand-100 text-brand-800">MENTOR</span></td>
                        <td class="p-2.5 font-bold text-slate-600">${escapeHtml(m.kelas || 'Semua')}</td>
                        <td class="p-2.5 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button type="button" onclick="openModalEditMentorByIdx(${idx})" title="Edit Nama Mentor" class="px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit Nama
                                </button>
                                <button type="button" onclick="openModalResetPasswordMentorByIdx(${idx})" title="Reset Password" class="px-2 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-key"></i> Reset Pass
                                </button>
                                <button type="button" onclick="handleDeleteMentorByIdx(${idx})" title="Hapus Mentor" class="px-2 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </div>
                        </td>
                    </tr>`;
                });
                usersTbody.innerHTML = html;
            }

            if (siswaTbody) {
                let html = '';
                MASTER_SISWA_LIST.forEach((s, idx) => {
                    html += `
                    <tr>
                        <td class="p-2.5 font-bold text-slate-400 text-center">${idx + 1}</td>
                        <td class="p-2.5 font-extrabold text-teal-800">${escapeHtml(s.nis)}</td>
                        <td class="p-2.5 font-bold text-slate-900">${escapeHtml(s.mentee)}</td>
                        <td class="p-2.5 font-bold text-slate-600">${escapeHtml(s.kelas)}</td>
                        <td class="p-2.5 text-slate-700 font-semibold">${escapeHtml(s.mentor)}</td>
                        <td class="p-2.5 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button type="button" onclick="openModalEditSiswaByIdx(${idx})" title="Edit Data & Mentor" class="px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-user-gear"></i> Edit Data/Mentor
                                </button>
                                <button type="button" onclick="openModalPindahkanMenteeByIdx(${idx})" title="Pindahkan Mentor" class="px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-user-pen"></i> Pindah Mentor
                                </button>
                                <button type="button" onclick="openModalResetPasswordSiswaByIdx(${idx})" title="Reset Password NIS" class="px-2 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-key"></i> Reset Pass
                                </button>
                                <button type="button" onclick="handleDeleteSiswaByIdx(${idx})" title="Hapus Murid" class="px-2 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded text-[10px] font-bold transition flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </div>
                        </td>
                    </tr>`;
                });
                siswaTbody.innerHTML = html;
            }
        }

        // --- EDIT DATA SISWA & MENTOR DIRECTLY HANDLERS ---
        function openModalEditSiswaByIdx(idx) {
            const sObj = MASTER_SISWA_LIST[idx];
            if (!sObj) return;

            document.getElementById('editSiswaIdx').value = idx;
            document.getElementById('inputEditSiswaNis').value = sObj.nis || '';
            document.getElementById('inputEditSiswaMentee').value = sObj.mentee || '';
            document.getElementById('inputEditSiswaKelas').value = sObj.kelas || '';
            document.getElementById('inputEditSiswaMentor').value = sObj.mentor || '';

            const selectEl = document.getElementById('selectEditSiswaMentorHelper');
            if (selectEl) {
                selectEl.innerHTML = '<option value="">-- Atau Pilih dari Daftar Mentor --</option>';
                MASTER_MENTOR_DATA.forEach(m => {
                    selectEl.innerHTML += `<option value="${escapeHtml(m.mentor)}">${escapeHtml(m.mentor)}</option>`;
                });
            }

            document.getElementById('modalEditSiswa').classList.remove('hidden');
        }

        function closeModalEditSiswa() {
            document.getElementById('modalEditSiswa').classList.add('hidden');
        }

        function handleHelperSelectMentor() {
            const selVal = document.getElementById('selectEditSiswaMentorHelper').value;
            if (selVal) {
                document.getElementById('inputEditSiswaMentor').value = selVal;
            }
        }

        function handleSaveEditSiswa(e) {
            e.preventDefault();
            const idx = parseInt(document.getElementById('editSiswaIdx').value, 10);
            const sObj = MASTER_SISWA_LIST[idx];
            if (!sObj) return;

            const newNis = document.getElementById('inputEditSiswaNis').value.trim();
            const newMentee = document.getElementById('inputEditSiswaMentee').value.trim();
            const newKelas = document.getElementById('inputEditSiswaKelas').value.trim();
            const newMentor = document.getElementById('inputEditSiswaMentor').value.trim();

            if (!newMentee || !newMentor) {
                showToast("Nama Murid dan Nama Mentor tidak boleh kosong!", "error");
                return;
            }

            sObj.nis = newNis || sObj.nis;
            sObj.mentee = newMentee;
            sObj.kelas = newKelas || sObj.kelas;
            sObj.mentor = newMentor;

            // Auto-register mentor if new
            const existMentor = MASTER_MENTOR_DATA.find(m => m.mentor.toLowerCase() === newMentor.toLowerCase());
            if (!existMentor) {
                MASTER_MENTOR_DATA.push({ mentor: newMentor, menteeCount: 1, kelas: newKelas || "Semua", mentees: [newMentee] });
            }

            saveMasterDataStores();
            populateDropdowns();
            renderAdminTables();
            closeModalEditSiswa();
            showToast(`Data murid ${newMentee} dan Mentor Pembimbing (${newMentor}) berhasil diperbarui!`);
        }

        // --- EDIT NAMA MENTOR HANDLERS ---
        function openModalEditMentorByIdx(idx) {
            const m = MASTER_MENTOR_DATA[idx];
            if (!m) return;

            document.getElementById('editMentorIdx').value = idx;
            document.getElementById('editOldMentorName').value = m.mentor;
            document.getElementById('displayOldMentorName').value = m.mentor;
            document.getElementById('inputNewMentorName').value = m.mentor;
            document.getElementById('modalEditMentor').classList.remove('hidden');
        }

        function closeModalEditMentor() {
            document.getElementById('modalEditMentor').classList.add('hidden');
        }

        function handleSaveEditMentor(e) {
            e.preventDefault();
            const idx = parseInt(document.getElementById('editMentorIdx').value, 10);
            const oldName = document.getElementById('editOldMentorName').value;
            const newName = document.getElementById('inputNewMentorName').value.trim();

            if (!newName) {
                showToast("Masukkan nama mentor baru!", "error");
                return;
            }

            if (MASTER_MENTOR_DATA[idx]) {
                MASTER_MENTOR_DATA[idx].mentor = newName;
            }

            // Update all students assigned to oldName
            MASTER_SISWA_LIST.forEach(s => {
                if (s.mentor === oldName) {
                    s.mentor = newName;
                }
            });

            // Update all journal entries assigned to oldName
            MENTORING_REKAP_DATA.forEach(r => {
                if (r.mentor === oldName) {
                    r.mentor = newName;
                }
            });

            // Transfer custom password key
            if (USER_PASSWORD_STORE[oldName]) {
                USER_PASSWORD_STORE[newName] = USER_PASSWORD_STORE[oldName];
                delete USER_PASSWORD_STORE[oldName];
            }

            saveMasterDataStores();
            saveLocalRekapStore();
            populateDropdowns();
            renderAdminTables();
            closeModalEditMentor();
            showToast(`Nama mentor berhasil diubah menjadi: ${newName}!`);
        }

        // --- PINDAHKAN MENTEE HANDLERS ---
        function openModalPindahkanMenteeByIdx(idx) {
            const sObj = MASTER_SISWA_LIST[idx];
            if (!sObj) return;

            document.getElementById('pindahMenteeIdx').value = idx;
            document.getElementById('pindahMenteeNis').value = sObj.nis;
            document.getElementById('displayPindahMenteeNama').value = `${sObj.mentee} (Kelas ${sObj.kelas})`;
            document.getElementById('displayPindahMentorLama').value = sObj.mentor;

            const selectEl = document.getElementById('selectPindahMentorBaru');
            selectEl.innerHTML = '';
            MASTER_MENTOR_DATA.forEach(m => {
                const selected = m.mentor === sObj.mentor ? 'selected' : '';
                selectEl.innerHTML += `<option value="${escapeHtml(m.mentor)}" ${selected}>${escapeHtml(m.mentor)} (${escapeHtml(m.kelas)})</option>`;
            });

            document.getElementById('modalPindahkanMentee').classList.remove('hidden');
        }

        function closeModalPindahkanMentee() {
            document.getElementById('modalPindahkanMentee').classList.add('hidden');
        }

        function handleSavePindahkanMentee(e) {
            e.preventDefault();
            const idx = parseInt(document.getElementById('pindahMenteeIdx').value, 10);
            const sObj = MASTER_SISWA_LIST[idx];
            const newMentor = document.getElementById('selectPindahMentorBaru').value;

            if (!sObj) return;

            const oldMentor = sObj.mentor;
            sObj.mentor = newMentor;

            // Update mentees array in MASTER_MENTOR_DATA
            const oldMObj = MASTER_MENTOR_DATA.find(m => m.mentor === oldMentor);
            if (oldMObj) {
                oldMObj.mentees = oldMObj.mentees.filter(m => m !== sObj.mentee);
            }
            const newMObj = MASTER_MENTOR_DATA.find(m => m.mentor === newMentor);
            if (newMObj && !newMObj.mentees.includes(sObj.mentee)) {
                newMObj.mentees.push(sObj.mentee);
            }

            saveMasterDataStores();
            populateDropdowns();
            renderAdminTables();
            closeModalPindahkanMentee();
            showToast(`Mentee ${sObj.mentee} berhasil dipindahkan ke mentor ${newMentor}!`);
        }

        // --- TRANSFER BULK MENTEE HANDLERS ---
        function openModalTransferBulkMentee() {
            const selectAsal = document.getElementById('selectBulkMentorAsal');
            const selectTujuan = document.getElementById('selectBulkMentorTujuan');

            selectAsal.innerHTML = '<option value="">-- Pilih Mentor Asal --</option>';
            selectTujuan.innerHTML = '<option value="">-- Pilih Mentor Tujuan --</option>';

            MASTER_MENTOR_DATA.forEach(m => {
                selectAsal.innerHTML += `<option value="${escapeHtml(m.mentor)}">${escapeHtml(m.mentor)}</option>`;
                selectTujuan.innerHTML += `<option value="${escapeHtml(m.mentor)}">${escapeHtml(m.mentor)}</option>`;
            });

            document.getElementById('modalTransferBulkMentee').classList.remove('hidden');
        }

        function closeModalTransferBulkMentee() {
            document.getElementById('modalTransferBulkMentee').classList.add('hidden');
        }

        function handleSaveTransferBulkMentee(e) {
            e.preventDefault();
            const mentorAsal = document.getElementById('selectBulkMentorAsal').value;
            const mentorTujuan = document.getElementById('selectBulkMentorTujuan').value;

            if (!mentorAsal || !mentorTujuan) {
                showToast("Pilih mentor asal dan mentor tujuan!", "error");
                return;
            }

            if (mentorAsal === mentorTujuan) {
                showToast("Mentor asal dan mentor tujuan tidak boleh sama!", "error");
                return;
            }

            let count = 0;
            MASTER_SISWA_LIST.forEach(s => {
                if (s.mentor === mentorAsal) {
                    s.mentor = mentorTujuan;
                    count++;
                }
            });

            saveMasterDataStores();
            populateDropdowns();
            renderAdminTables();
            closeModalTransferBulkMentee();
            showToast(`${count} mentee dari ${mentorAsal} berhasil dipindahkan ke ${mentorTujuan}!`);
        }

        // --- RESET PASSWORD HANDLERS ---
        function openModalResetPasswordMentorByIdx(idx) {
            const mObj = MASTER_MENTOR_DATA[idx];
            if (!mObj) return;
            openModalResetPassword(mObj.mentor, 'mentor');
        }

        function openModalResetPasswordSiswaByIdx(idx) {
            const sObj = MASTER_SISWA_LIST[idx];
            if (!sObj) return;
            openModalResetPassword(sObj.nis, 'siswa');
        }

        function openModalResetPassword(targetKey, role) {
            let label = targetKey;
            if (role === 'siswa') {
                const sObj = MASTER_SISWA_LIST.find(s => s.nis === targetKey);
                if (sObj) label = `${sObj.mentee} (NIS: ${sObj.nis})`;
            }

            document.getElementById('resetPassUserKey').value = targetKey;
            document.getElementById('resetPassUserRole').value = role;
            document.getElementById('displayResetUserLabel').value = label;
            document.getElementById('inputResetNewPassword').value = role === 'siswa' ? targetKey : 'mentor123';
            document.getElementById('modalResetPassword').classList.remove('hidden');
        }

        function closeModalResetPassword() {
            document.getElementById('modalResetPassword').classList.add('hidden');
        }

        function handleSaveResetPassword(e) {
            e.preventDefault();
            const key = document.getElementById('resetPassUserKey').value;
            const newPass = document.getElementById('inputResetNewPassword').value.trim();

            if (!newPass) {
                showToast("Masukkan password baru!", "error");
                return;
            }

            USER_PASSWORD_STORE[key] = newPass;
            saveMasterDataStores();
            closeModalResetPassword();
            showToast(`Password untuk ${key} berhasil di-reset menjadi: ${newPass}!`);
        }

        function handleDeleteMentorByIdx(idx) {
            const mObj = MASTER_MENTOR_DATA[idx];
            if (!mObj) return;
            if (!confirm(`Apakah Anda yakin ingin menghapus account mentor ${mObj.mentor}?`)) return;

            const mentorName = mObj.mentor;
            MASTER_MENTOR_DATA.splice(idx, 1);

            MASTER_SISWA_LIST.forEach(s => {
                if (s.mentor === mentorName) {
                    s.mentor = MASTER_MENTOR_DATA[0] ? MASTER_MENTOR_DATA[0].mentor : "Ust. Mentor";
                }
            });

            saveMasterDataStores();
            populateDropdowns();
            renderAdminTables();
            showToast(`Mentor ${mentorName} telah dihapus.`);
        }

        function handleDeleteSiswaByIdx(idx) {
            const sObj = MASTER_SISWA_LIST[idx];
            if (!sObj) return;
            if (!confirm(`Apakah Anda yakin ingin menghapus data murid ${sObj.mentee} (NIS: ${sObj.nis})?`)) return;

            const menteeName = sObj.mentee;
            MASTER_SISWA_LIST.splice(idx, 1);

            saveMasterDataStores();
            populateDropdowns();
            renderAdminTables();
            showToast(`Data murid ${menteeName} telah dihapus.`);
        }

        function openModalTambahUser() {
            document.getElementById('modalTambahUser').classList.remove('hidden');
        }

        function closeModalTambahUser() {
            document.getElementById('modalTambahUser').classList.add('hidden');
        }

        function openModalTambahSiswa() {
            document.getElementById('modalTambahSiswa').classList.remove('hidden');
        }

        function closeModalTambahSiswa() {
            document.getElementById('modalTambahSiswa').classList.add('hidden');
        }

        function handleSaveTambahUser(e) {
            e.preventDefault();
            const u = document.getElementById('inputNewUsername').value.trim();
            const p = document.getElementById('inputNewPassword').value.trim();
            const r = document.getElementById('inputNewRole').value;
            const k = document.getElementById('inputNewKelas').value.trim();

            MASTER_MENTOR_DATA.push({ mentor: u, menteeCount: 0, kelas: k, mentees: [] });
            populateDropdowns();
            renderAdminTables();
            closeModalTambahUser();
            showToast(`User mentor ${u} berhasil ditambahkan!`);
        }

        function handleSaveTambahSiswa(e) {
            e.preventDefault();
            const nis = document.getElementById('inputNewNis').value.trim();
            const mentee = document.getElementById('inputNewMentee').value.trim();
            const kelas = document.getElementById('inputNewSiswaKelas').value.trim();
            const mentor = document.getElementById('inputNewSiswaMentor').value;

            MASTER_SISWA_LIST.push({ nis, mentee, kelas, mentor });
            
            const mObj = MASTER_MENTOR_DATA.find(m => m.mentor === mentor);
            if (mObj && !mObj.mentees.includes(mentee)) {
                mObj.mentees.push(mentee);
            }

            saveMasterDataStores();
            populateDropdowns();
            renderAdminTables();
            closeModalTambahSiswa();
            showToast(`Siswa ${mentee} (NIS: ${nis}) berhasil ditambahkan!`);
        }

        function handleSaveSettings(e) {
            e.preventDefault();
            showToast("Pengaturan sistem berhasil diperbarui!");
        }

        function reloadAllSheetDataFromGoogle() {
            loadSheetSiswaData();
            loadUsersDataFromSheet();
            loadBankMateriFromSheet();
            showToast("Data sedang disinkronkan ulang dari Google Sheets...");
        }

        function exportRekapToExcelCSV() {
            if (MENTORING_REKAP_DATA.length === 0) {
                showToast("Belum ada data rekap untuk diexport!", "error");
                return;
            }

            const headers = ["No", "Tanggal", "Tahun Ajaran", "Pekan", "Hari", "Nama Murid", "Nama Mentor", "Skor MIP", "Skor Akademik", "Skor Mutabaah", "Skor Adab", "Catatan Mentor"];
            const rows = MENTORING_REKAP_DATA.map((r, idx) => [
                idx + 1, r.tanggal || '', r.tahunAjaran || '2026/2027', r.pekan || '', r.hari || '',
                `"${r.mentee || r.namaMentee}"`, `"${r.mentor}"`, r.scoreMIP || 0, r.scoreAkademik || 0, r.scoreMutabaah || 0, r.scoreAdab || 0, `"${(r.catatanAdab || '').replace(/"/g, '""')}"`
            ]);

            const csvContent = "data:text/csv;charset=utf-8," + [headers.join(","), ...rows.map(e => e.join(","))].join("\n");
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `Rekap_Mentoring_MIP_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            showToast("Rekap data berhasil di-export ke format CSV!");
        }

        function showToast(message, type = "success") {
            const modal = document.getElementById('toast-modal');
            const msgEl = document.getElementById('toastMessage');
            const iconEl = document.getElementById('toastIcon');

            msgEl.innerText = message;
            if (type === 'error') {
                iconEl.className = "fa-solid fa-circle-xmark text-rose-400 text-base";
            } else {
                iconEl.className = "fa-solid fa-circle-check text-emerald-400 text-base";
            }

            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 3500);
        }
    

        function bypassLoginDirect(role) {
            if (role === 'admin') {
                currentUser = { name: "Administrator", role: "admin" };
            } else if (role === 'siswa') {
                const firstSiswa = (typeof MASTER_SISWA_LIST !== 'undefined' && MASTER_SISWA_LIST.length > 0) ? MASTER_SISWA_LIST[0] : null;
                currentUser = {
                    name: firstSiswa ? firstSiswa.mentee : "Siswa MIP",
                    nis: firstSiswa ? firstSiswa.nis : "262707001",
                    kelas: firstSiswa ? firstSiswa.kelas : "7",
                    mentor: firstSiswa ? firstSiswa.mentor : "Mentor MIP",
                    role: "siswa"
                };
            }
            const screen = document.getElementById('login-screen');
            if (screen) {
                screen.classList.add('hidden');
                screen.style.display = 'none';
            }
            try { setupUserRoleUI(); } catch(err) { console.error("UI setup err:", err); }
            showToast(`Login berhasil sebagai ${currentUser.name}!`);
        }

        // Auto-login jika pengguna sudah login di sistem absensi (PHP Session)
        window.addEventListener('DOMContentLoaded', () => {
            if (typeof PHP_SESSION_USER !== 'undefined' && PHP_SESSION_USER && PHP_SESSION_USER.name) {
                console.log("Auto-authenticating with PHP Session:", PHP_SESSION_USER);
                currentUser = {
                    name: PHP_SESSION_USER.name,
                    role: PHP_SESSION_USER.role === 'super_admin' ? 'admin' : (PHP_SESSION_USER.role === 'siswa' ? 'siswa' : 'mentor'),
                    nis: PHP_SESSION_USER.nis || '',
                    kelas: PHP_SESSION_USER.kelas || '7',
                    mentor: PHP_SESSION_USER.mentor || 'Mentor MIP'
                };
                const screen = document.getElementById('login-screen');
                if (screen) {
                    screen.classList.add('hidden');
                    screen.style.display = 'none';
                }
                setTimeout(() => {
                    try { 
                        setupUserRoleUI(); 
                        // Cek apakah ada query parameter tab=report atau tab=report-tab
                        const urlParams = new URLSearchParams(window.location.search);
                        const targetTab = urlParams.get('tab');
                        const targetMentee = urlParams.get('mentee') || urlParams.get('student_name');
                        if (targetTab === 'report' || targetTab === 'report-tab') {
                            showTab('report-tab');
                            if (targetMentee) {
                                const sel = document.getElementById('reportFilterMenteeSelect');
                                if (sel) {
                                    sel.value = targetMentee;
                                    renderStudentReportDetail();
                                }
                            }
                        }
                    } catch(e) { console.error(e); }
                }, 300);
            }
        });

</script>
</body>
</html>






