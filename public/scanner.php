<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

$today = date('Y-m-d');
$now = time();

$stmtStats = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM student_attendances WHERE attendance_date = ? AND status NOT IN ('alpha', 'tidak_absen')) as total_siswa,
        (SELECT COUNT(*) FROM student_attendances WHERE attendance_date = ? AND status = 'tepat_waktu') as tepat_siswa,
        (SELECT COUNT(*) FROM staff_attendances WHERE attendance_date = ? AND status NOT IN ('alpha', 'tidak_absen')) as total_staff
");
$stmtStats->execute([$today, $today, $today]);
$stats = $stmtStats->fetch();
$persentase = $stats['total_siswa'] > 0 ? round(($stats['tepat_siswa'] / $stats['total_siswa']) * 100) : 0;

$stmtRecent = $pdo->prepare("
    (SELECT COALESCE(NULLIF(sa.time_out, '00:00:00'), NULLIF(sa.time_in, '00:00:00')) as scan_time, s.name, cg.name as class_name, sa.status, 'student' as type, s.photo
    FROM student_attendances sa 
    JOIN students s ON s.id = sa.student_id 
    JOIN student_enrollments se ON se.id = sa.enrollment_id 
    JOIN class_groups cg ON cg.id = se.class_group_id
    WHERE sa.attendance_date = ? AND (NULLIF(sa.time_in, '00:00:00') IS NOT NULL OR NULLIF(sa.time_out, '00:00:00') IS NOT NULL))
    UNION ALL
    (SELECT COALESCE(NULLIF(sta.time_out, '00:00:00'), NULLIF(sta.time_in, '00:00:00')) as scan_time, st.name, 'Guru/Staff' as class_name, sta.status, 'staff' as type, st.photo
    FROM staff_attendances sta 
    JOIN staff st ON st.id = sta.staff_id
    WHERE sta.attendance_date = ? AND (NULLIF(sta.time_in, '00:00:00') IS NOT NULL OR NULLIF(sta.time_out, '00:00:00') IS NOT NULL))
    ORDER BY scan_time DESC LIMIT 5
");

$stmtRecent->execute([$today, $today]);
$recents = $stmtRecent->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Smart Scanner | AIS ABSENSI</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Amiri:wght@700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/html5-qrcode"></script>
<style>
    :root {
        --bg-dark: #0f172a; --panel-bg: #1e293b; --text-main: #f8fafc; --text-muted: #94a3b8;
        --accent: #3b82f6; --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
    }
    body {
        margin: 0; padding: 20px; background-color: var(--bg-dark); color: var(--text-main);
        font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; height: 100vh; box-sizing: border-box;
    }
    .topbar {
        display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
        background: var(--panel-bg); padding: 15px 25px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    }
    .brand { display: flex; align-items: center; gap: 15px; }
    .brand img { width: 45px; height: 45px; border-radius: 10px; background: white; padding: 2px; }
    .brand-text h1 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
    .brand-text span { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
    
    .top-actions { display: flex; align-items: center; gap: 15px; }
    .btn-action { 
        background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); 
        padding: 8px 15px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; text-decoration: none; transition: 0.2s;
    }
    .btn-action:hover { background: rgba(255,255,255,0.1); }
    .status-dot { width: 8px; height: 8px; background: var(--success); border-radius: 50%; box-shadow: 0 0 10px var(--success); }

    .main-grid { display: grid; grid-template-columns: 1fr 400px; gap: 20px; height: calc(100vh - 110px); }
    .panel { background: var(--panel-bg); border-radius: 16px; padding: 25px; display: flex; flex-direction: column; position: relative; overflow: hidden; box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
    
    .scanner-mode-tabs { display: flex; justify-content: center; gap: 10px; margin-bottom: 25px; z-index: 5; }
    .tab-btn { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: 0.2s; }
    .tab-btn.active { background: var(--accent); color: white; border-color: var(--accent); }

    .scan-area-wrapper { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; position: relative; }
    
    .scan-icon-pulse {
        width: 120px; height: 120px; background: rgba(59,130,246,0.2); border-radius: 20px;
        display: flex; justify-content: center; align-items: center; font-size: 50px; margin-bottom: 25px;
        box-shadow: 0 0 0 0 rgba(59,130,246, 0.4); animation: pulse 2s infinite;
    }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(59,130,246, 0.5); } 70% { box-shadow: 0 0 0 25px rgba(59,130,246, 0); } 100% { box-shadow: 0 0 0 0 rgba(59,130,246, 0); } }
    
    .manual-input-box { background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05); padding: 15px; border-radius: 12px; margin-top: 30px; width: 100%; max-width: 400px; text-align: center; }
    .visible-input { width: calc(100% - 20px); padding: 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: white; font-size: 16px; text-align: center; font-family: inherit; outline: none; }
    .visible-input:focus { border-color: var(--accent); }

    #reader { width: 100%; max-width: 500px; border-radius: 15px; overflow: hidden; display: none; border: 2px solid rgba(255,255,255,0.1); }

    .full-overlay {
        position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; 
        background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(10px);
        display: none; flex-direction: column; justify-content: center; align-items: center; z-index: 9999;
        opacity: 0; transition: opacity 0.3s ease;
    }
    .full-overlay.show { display: flex; opacity: 1; }
    
    .result-card-horizontal {
        background: var(--panel-bg); border: 1px solid rgba(255,255,255,0.1); 
        padding: 40px; border-radius: 24px; width: 90%; max-width: 900px; 
        display: flex; gap: 40px; align-items: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .full-overlay.show .result-card-horizontal { transform: scale(1); }
    
    .photo-wrapper {
        flex-shrink: 0; width: 250px; height: 250px; background: white; padding: 12px; 
        border-radius: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    }
    .res-photo { width: 100%; height: 100%; object-fit: cover; border-radius: 16px; background: #cbd5e1; }
    
    .info-wrapper { flex: 1; text-align: left; }
    .warning-banner { background: rgba(239,68,68,0.15); color: #fca5a5; padding: 10px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; border-left: 4px solid #ef4444; display: none; }
    .role-badge { background: rgba(59,130,246,0.2); color: #93c5fd; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 800; letter-spacing: 1px; display: inline-block; margin-bottom: 15px; border: 1px solid rgba(59,130,246,0.3); }
    
    .name-title { margin: 0 0 5px 0; font-size: 42px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff; }
    .detail-subtitle { margin: 0 0 25px 0; color: #94a3b8; font-size: 18px; }
    
    .time-status-row { display: flex; align-items: center; gap: 20px; margin-bottom: 25px; }
    .time-big { font-size: 60px; font-weight: 800; color: #38bdf8; font-variant-numeric: tabular-nums; line-height: 1; }
    .badge-status { padding: 10px 20px; border-radius: 12px; font-size: 16px; font-weight: 700; letter-spacing: 0.5px; }
    .bg-ontime { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.4); }
    .bg-late { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.4); }
    
    .greeting-box { background: rgba(255,255,255,0.05); padding: 15px 20px; border-radius: 12px; border-left: 4px solid #10b981; }
    .greeting-box h4 { margin: 0 0 5px 0; color: #f8fafc; font-size: 16px; }
    .greeting-box p { margin: 0; color: #94a3b8; font-size: 13px; font-style: italic; }

    .progress-container { width: 90%; max-width: 900px; margin-top: 30px; display: flex; flex-direction: column; align-items: center; }
    .progress-track { width: 100%; height: 6px; background: rgba(255,255,255,0.1); border-radius: 6px; overflow: hidden; margin-bottom: 15px; }
    .progress-fill { height: 100%; background: var(--accent); width: 100%; }
    .btn-next { background: rgba(255,255,255,0.1); color: var(--text-muted); border: none; padding: 10px 25px; border-radius: 20px; font-size: 14px; font-family: inherit; font-weight: 600; cursor: pointer; transition: 0.2s;}
    .btn-next:hover { background: rgba(255,255,255,0.2); color: white; }

    .digital-clock-box { background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 25px; border-radius: 16px; text-align: center; margin-bottom: 20px; box-shadow: inset 0 2px 10px rgba(0,0,0,0.2); }
    .time-display { font-size: 52px; font-weight: 800; color: #38bdf8; letter-spacing: 2px; line-height: 1.1; margin-bottom: 12px; font-variant-numeric: tabular-nums; }
    .date-display { color: var(--text-muted); font-size: 13px; display: flex; justify-content: center; align-items: center; gap: 10px; font-weight: 600; }
    .hijri-date { color: #fcd34d; background: rgba(252,211,77,0.1); padding: 3px 10px; border-radius: 20px; border: 1px solid rgba(252,211,77,0.2); }
    
    .stats-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 25px; }
    .stat-box { background: rgba(0,0,0,0.2); padding: 15px 5px; border-radius: 12px; text-align: center; border: 1px solid rgba(255,255,255,0.05); }
    .stat-num { font-size: 24px; font-weight: 800; margin-bottom: 5px; }
    .stat-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    
    .recent-list { flex: 1; }
    .recent-list h3 { font-size: 13px; color: var(--text-muted); margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 10px; display: flex; justify-content: space-between; }
    .recent-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.02); }
    .recent-info h4 { margin: 0 0 3px 0; font-size: 14px; font-weight: 700; color: #f1f5f9; }
    .recent-info p { margin: 0; font-size: 12px; color: var(--text-muted); }
    .recent-time { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .text-green { color: #34d399; } .text-red { color: #f87171; }

    .hadith-box { text-align: center; padding: 20px; background: rgba(0,0,0,0.15); border-radius: 12px; border-top: 2px solid rgba(255,255,255,0.05); margin-top: auto; height: 110px; display: flex; flex-direction: column; justify-content: center; }
    .hadith-arabic { font-family: 'Amiri', serif; font-size: 24px; color: #f8fafc; margin-bottom: 10px; line-height: 1.4; transition: opacity 0.5s; opacity: 1; }
    .hadith-translate { font-size: 11px; color: var(--text-muted); font-style: italic; transition: opacity 0.5s; opacity: 1; }
</style>
</head>
<body>

<div class="full-overlay" id="fullOverlay">
    <div class="result-card-horizontal">
        <div class="photo-wrapper">
            <img src="" id="resPhoto" class="res-photo">
        </div>
        <div class="info-wrapper">
            <div class="warning-banner" id="resWarning"></div>
            <div class="role-badge" id="resRole">SISWA</div>
            <h2 class="name-title" id="resName">Nama Lengkap</h2>
            <p class="detail-subtitle" id="resDetail">Detail</p>
            <div class="time-status-row">
                <div class="time-big" id="resTime">00:00:00</div>
                <div class="badge-status" id="resBadge">STATUS</div>
            </div>
            <div class="greeting-box">
                <h4 id="resGreetTitle">Fi Amanillah ✨</h4>
                <p id="resGreetDesc">Pesan</p>
            </div>
        </div>
    </div>
    <div class="progress-container">
        <div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
        <button class="btn-next" onclick="forceCloseOverlay()">Tutup & Lanjut Scan (Spasi)</button>
    </div>
</div>

<div class="topbar">
    <div class="brand">
        <img src="../public/logo.jpg" alt="Logo">
        <div class="brand-text">
            <span>Gerbang Utama • Smart Presensi</span>
            <h1>AN NAHL ISLAMIC SCHOOL</h1>
        </div>
    </div>
    <div class="top-actions">
        <div class="btn-action" style="cursor:default;"><span class="status-dot"></span> Online</div>
        <button class="btn-action" onclick="toggleFullScreen()" title="Layar Penuh">⛶ Layar Penuh</button>
        <a href="dashboard.php" class="btn-action">⬅ Kembali</a>
    </div>
</div>

<div class="main-grid">
    <div class="panel">
        <div class="scanner-mode-tabs">
            <div class="tab-btn" id="btnHp" onclick="switchScanner('environment', this)">Kamera HP (Belakang)</div>
            <div class="tab-btn" id="btnDepan" onclick="switchScanner('user', this)">Kamera Depan</div>
            <div class="tab-btn active" id="btnMesin" onclick="switchScanner('rfid', this)">Mesin / RFID</div>
        </div>

        <div class="scan-area-wrapper">
            <div id="rfidMode" style="display:flex; flex-direction:column; align-items:center;">
                <div class="scan-icon-pulse">🪪</div>
                <h2 style="margin:0 0 5px 0;">Mesin Scanner & RFID Siap</h2>
                <p style="color:var(--text-muted); font-size:14px; text-align:center; max-width:300px;">Tempelkan kartu RFID atau arahkan ke alat barcode scanner</p>
            </div>

            <div id="reader"></div>

            <div class="manual-input-box">
                <form id="scannerForm" onsubmit="processScan(event)">
                    <input type="text" name="code" id="barcodeInput" class="visible-input" placeholder="Atau ketik manual NIS/NIK lalu Enter" autocomplete="off" autofocus>
                </form>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="digital-clock-box">
            <div class="time-display" id="clock">00:00:00</div>
            <div class="date-display">
                <span id="dateStr"></span>
                <span class="hijri-date" id="hijriStr"></span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-num text-green" id="uiTotalHadir"><?= number_format($stats['total_siswa'] ?? 0) ?></div>
                <div class="stat-label">Siswa Hadir</div>
            </div>
            <div class="stat-box">
                <div class="stat-num text-green" id="uiTotalStaff"><?= number_format($stats['total_staff'] ?? 0) ?></div>
                <div class="stat-label">Guru / Staff</div>
            </div>
            <div class="stat-box">
                <div class="stat-num text-green" id="uiPersen"><?= $persentase ?>%</div>
                <div class="stat-label">Tepat Waktu</div>
            </div>
        </div>

        <div class="recent-list">
            <h3><span>↺ Presensi Terkini</span> <span style="color:var(--success)">● LIVE</span></h3>
            <div id="recentContainer">
                <?php if(empty($recents)): ?>
                    <p style="text-align:center; color:var(--text-muted); font-size:13px; margin-top:30px;">Belum ada presensi yang tercatat hari ini.</p>
                <?php else: ?>
                    <?php foreach($recents as $r): 
                        $isOntime = ($r['status'] === 'tepat_waktu'); 
                        $folder = ($r['type'] === 'staff') ? 'staff' : 'students';
                        $imgSrc = !empty($r['photo']) ? "../uploads/{$folder}/" . e($r['photo']) : 'https://via.placeholder.com/80/cbd5e1/475569?text=' . strtoupper(substr($r['name'], 0, 1));
                    ?>
                    <div class="recent-item" style="display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <img src="<?= $imgSrc ?>" alt="Foto" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: #cbd5e1; flex-shrink: 0;">
                        <div class="recent-info" style="flex: 1;">
                            <h4 style="margin: 0 0 3px 0; font-size: 14px; font-weight: 700; color: #f1f5f9;"><?= e($r['name']) ?></h4>
                            <p style="margin: 0; font-size: 12px; color: var(--text-muted);"><?= e($r['class_name']) ?></p>
                        </div>
                        <div class="recent-time <?= $isOntime ? 'text-green' : 'text-red' ?>" style="font-size: 14px; font-weight: 700;">
                            <?= date('H:i', strtotime($r['scan_time'])) ?> 
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="hadith-box">
            <div class="hadith-arabic" id="hadithArabic">طَلَبُ الْعِلْمِ فَرِيضَةٌ عَلَى كُلِّ مُسْلِمٍ</div>
            <div class="hadith-translate" id="hadithText">"Menuntut ilmu itu wajib atas setiap Muslim." (HR. Ibnu Majah)</div>
        </div>
    </div>
</div>

<script>
    let overlayTimeout = null;

    function processScan(e) {
        if(e) e.preventDefault();
        
        const inputField = document.getElementById('barcodeInput');
        const code = inputField.value.trim();
        inputField.value = ''; 
        if(code === '') return;

        fetch('scanner_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'code=' + encodeURIComponent(code)
        })
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                showFullOverlay(res.data);
                updateAnalytics(res.stats, res.recent);
            } else {
                alert(res.msg || "Data tidak ditemukan!");
            }
        })
        .catch(err => console.error("AJAX Error:", err));
    }

    function showFullOverlay(data) {
        if(overlayTimeout) clearTimeout(overlayTimeout);

        let folder = data.type === 'staff' ? 'staff' : 'students';
        let imgSrc = data.photo ? `../uploads/${folder}/${data.photo}` : `https://via.placeholder.com/250/cbd5e1/475569?text=${data.name.charAt(0).toUpperCase()}`;
        document.getElementById('resPhoto').src = imgSrc;
        
        document.getElementById('resRole').innerText = data.type === 'student' ? 'SISWA' : 'STAFF / GURU';
        document.getElementById('resName').innerText = data.name;
        document.getElementById('resDetail').innerText = data.type === 'student' ? `NIS: ${data.nis} • Kelas: ${data.class_name}` : `NIK: ${data.nik}`;
        document.getElementById('resTime').innerText = data.time;
        
        let badge = document.getElementById('resBadge');
        badge.innerText = data.badge_text;
        badge.className = 'badge-status ' + (data.badge_class === 'ontime' ? 'bg-ontime' : 'bg-late');

        let warningBox = document.getElementById('resWarning');
        if (data.already) {
            warningBox.innerText = '⛔ ' + data.message;
            warningBox.style.display = 'inline-block';
        } else {
            warningBox.style.display = 'none';
        }

        let title = "Selamat Bekerja / Belajar"; let desc = "";
        if(data.type === 'student') {
            if(data.scan_type === 'pulang') { title = "Fi Amanillah ✨"; desc = "Semoga pembelajaran hari ini diberkahi Allah dan bermanfaat."; }
            else { title = "Selamat bersekolah dengan bahagia 🌿"; desc = "Jangan lupa baca doa sebelum masuk kelas."; }
        } else {
            if(data.scan_type === 'pulang') { title = "Fi Amanillah ✨"; desc = "Terima kasih atas dedikasinya, semoga selamat sampai tujuan."; }
            else { title = "Selamat Bekerja 🌿"; desc = "Semoga amal ibadah & pekerjaan hari ini dilancarkan."; }
        }
        document.getElementById('resGreetTitle').innerText = title;
        document.getElementById('resGreetDesc').innerText = desc;

        let prog = document.getElementById('progressFill');
        prog.style.animation = 'none';
        prog.offsetHeight; 
        prog.style.animation = 'shrink 3.5s linear forwards';

        document.getElementById('fullOverlay').classList.add('show');
        
        let beep = new Audio('data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU'); 
        beep.play().catch(e=>{});

        overlayTimeout = setTimeout(() => { forceCloseOverlay(); }, 3500);
    }

    function forceCloseOverlay() {
        if(overlayTimeout) clearTimeout(overlayTimeout);
        document.getElementById('fullOverlay').classList.remove('show');
        document.getElementById('barcodeInput').focus();
    }

    window.addEventListener('keydown', function(e) {
        if(e.code === 'Space' && document.getElementById('fullOverlay').classList.contains('show')) {
            e.preventDefault(); forceCloseOverlay();
        }
    });

    function updateAnalytics(stats, recentsHTML) {
        document.getElementById('uiTotalHadir').innerText = stats.total_siswa;
        document.getElementById('uiTotalStaff').innerText = stats.total_staff;
        let persen = stats.total_siswa > 0 ? Math.round((stats.tepat_siswa / stats.total_siswa) * 100) : 0;
        document.getElementById('uiPersen').innerText = persen + '%';
        document.getElementById('recentContainer').innerHTML = recentsHTML;
    }

    function updateClock() {
        const now = new Date();
        document.getElementById('clock').innerText = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).replace(/\./g, ':');
        document.getElementById('dateStr').innerText = now.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        document.getElementById('hijriStr').innerText = new Intl.DateTimeFormat('id-TN-u-ca-islamic', { day: 'numeric', month: 'long', year: 'numeric', calendar: 'islamic-civil' }).format(now) + ' H';
    }
    setInterval(updateClock, 1000); updateClock();

    let keepFocus = true;
    setInterval(() => {
        let inputEl = document.getElementById('barcodeInput');
        if(keepFocus && document.activeElement !== inputEl && !document.getElementById('fullOverlay').classList.contains('show')) { 
            inputEl.focus(); 
        }
    }, 1000);

    let html5QrCode = null;
    function switchScanner(mode, btnElement) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        btnElement.classList.add('active');

        if (html5QrCode) { html5QrCode.stop().then(() => { html5QrCode.clear(); html5QrCode = null; }).catch(e=>{}); }

        if (mode === 'rfid') {
            document.getElementById('rfidMode').style.display = 'flex';
            document.getElementById('reader').style.display = 'none';
            keepFocus = true; document.getElementById('barcodeInput').focus();
        } else {
            document.getElementById('rfidMode').style.display = 'none';
            document.getElementById('reader').style.display = 'block';
            keepFocus = false;

            html5QrCode = new Html5Qrcode("reader");
            html5QrCode.start(
                { facingMode: mode },
                { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 },
                (decodedText) => {
                    document.getElementById('barcodeInput').value = decodedText;
                    processScan();
                    html5QrCode.stop();
                },
                (err) => {}
            ).catch(err => {
                alert("Kamera tidak dapat diakses."); switchScanner('rfid', document.getElementById('btnMesin'));
            });
        }
    }

    const hadiths = [
        { arabic: "طَلَبُ الْعِلْمِ فَرِيضَةٌ عَلَى كُلِّ مُسْلِمٍ", text: '"Menuntut ilmu itu wajib atas setiap Muslim." (HR. Ibnu Majah)' },
        { arabic: "مَنْ سَلَكَ طَرِيقًا يَلْتَمِسُ فِيهِ عِلْمًا سَهَّلَ اللَّهُ لَهُ بِهِ طَرِيقًا إِلَى الْجَنَّةِ", text: '"Barangsiapa meniti jalan mencari ilmu, Allah mudahkan jalan ke surga." (HR. Muslim)' },
        { arabic: "خَيْرُكُمْ مَنْ تَعَلَّمَ الْقُرْآنَ وَعَلَّمَهُ", text: '"Sebaik-baik kalian adalah orang yang belajar Al-Qur\'an dan mengajarkannya." (HR. Bukhari)' }
    ];
    let hadithIndex = 0;
    setInterval(() => {
        const ar = document.getElementById('hadithArabic'), txt = document.getElementById('hadithText');
        ar.style.opacity = 0; txt.style.opacity = 0;
        setTimeout(() => {
            hadithIndex = (hadithIndex + 1) % hadiths.length;
            ar.innerText = hadiths[hadithIndex].arabic; txt.innerText = hadiths[hadithIndex].text;
            ar.style.opacity = 1; txt.style.opacity = 1;
        }, 500);
    }, 10000);

    function toggleFullScreen() {
        if (!document.fullscreenElement) document.documentElement.requestFullscreen();
        else if (document.exitFullscreen) document.exitFullscreen();
    }
</script>
</body>
</html>