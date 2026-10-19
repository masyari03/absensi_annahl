<?php
// public/student/includes/sidebar.php
$webPermitEnabled = (getSystemSetting($pdo, 'student_web_permit_enabled', '1') === '1');
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="sidebar" id="studentSidebar" style="width: 250px; background: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; flex-direction: column; flex-shrink: 0; transition: all 0.3s ease;">
    <!-- Brand / Logo -->
    <div style="padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="width: 36px; height: 36px; border-radius: 8px; background: #0284c7; display: flex; align-items: center; justify-content: center; font-weight: 800; color: white;">
                AIS
            </div>
            <div>
                <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #ffffff; letter-spacing: 0.5px;">PORTAL SISWA</h3>
                <small style="color: #94a3b8; font-size: 11px;">An-Nahl Islamic School</small>
            </div>
        </div>
        <button type="button" class="btn-close-sidebar" onclick="toggleStudentSidebar()" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; width: 34px; height: 34px; align-items: center; justify-content: center; color: #cbd5e1; font-size: 16px; cursor: pointer;">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <!-- Navigation Menu -->
    <nav style="padding: 16px 12px; flex: 1; display: flex; flex-direction: column; gap: 4px;">
        <div style="padding: 6px 12px; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">
            Menu Utama
        </div>

        <a href="dashboard.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'dashboard.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'dashboard.php' ? '#0284c7' : 'transparent' ?>;">
            <i class="fa-solid fa-house" style="width: 18px;"></i>
            <span>Dashboard</span>
        </a>

        <a href="attendance.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'attendance.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'attendance.php' ? '#0284c7' : 'transparent' ?>;">
            <i class="fa-solid fa-calendar-check" style="width: 18px;"></i>
            <span>Riwayat Absensi</span>
        </a>

        <!-- MENU SURAT IZIN / SAKIT: HANYA TAMPIL JIKA DIAKTIFKAN OLEH SUPER ADMIN -->
        <?php if ($webPermitEnabled): ?>
            <a href="permit_upload.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'permit_upload.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'permit_upload.php' ? '#0284c7' : 'transparent' ?>;">
                <i class="fa-solid fa-file-medical" style="width: 18px;"></i>
                <span>Surat Izin / Sakit</span>
            </a>
        <?php endif; ?>

        <a href="achievements.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'achievements.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'achievements.php' ? '#0284c7' : 'transparent' ?>;">
            <i class="fa-solid fa-trophy" style="width: 18px;"></i>
            <span>Prestasi Siswa</span>
        </a>

        <div style="padding: 12px 12px 6px 12px; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">
            Pembinaan & Karakter
        </div>

        <a href="adab.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'adab.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'adab.php' ? '#0284c7' : 'transparent' ?>;">
            <i class="fa-solid fa-heart-circle-check" style="width: 18px;"></i>
            <span>Pembiasaan Adab</span>
        </a>

        <a href="mentoring_notes.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'mentoring_notes.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'mentoring_notes.php' ? '#0284c7' : 'transparent' ?>;">
            <i class="fa-solid fa-hands-holding-child" style="width: 18px;"></i>
            <span>Catatan Mentoring</span>
        </a>

        <div style="padding: 12px 12px 6px 12px; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">
            Akun & Keamanan
        </div>

        <a href="profile.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: <?= $currentScript === 'profile.php' ? '#ffffff' : '#94a3b8' ?>; background: <?= $currentScript === 'profile.php' ? '#0284c7' : 'transparent' ?>;">
            <i class="fa-solid fa-user-shield" style="width: 18px;"></i>
            <span>Profil & Keamanan</span>
        </a>

        <a href="<?= BASE_URL ?>/logout.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: all 0.2s; color: #f87171; margin-top: auto;">
            <i class="fa-solid fa-right-from-bracket" style="width: 18px;"></i>
            <span>Keluar (Logout)</span>
        </a>
    </nav>
</aside>

<script>
function toggleStudentSidebar() {
    const sb = document.getElementById('studentSidebar');
    sb.classList.toggle('active');
}
</script>
