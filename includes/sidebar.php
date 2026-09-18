<?php
$role = $_SESSION['role'] ?? '';
?>
<aside class="sidebar" id="sidebar">
    <div class="brand" style="position: relative;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="brand-icon" style="background:transparent; padding:0; display:flex; justify-content:center; align-items:center;">
                <img src="<?= BASE_URL ?>/logo.jpg" alt="Logo" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
            </div>
            <div>
                <strong>AIS Absensi</strong>
                <small>An Nahl Islamic School</small>
            </div>
        </div>
        <!-- TOMBOL X (TUTUP) YANG LEBIH MODERN -->
        <button id="closeSidebar" class="btn-close-sidebar" title="Tutup Menu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <nav>
        <span class="menu-title">UTAMA</span>
        <a href="<?= BASE_URL ?>/dashboard.php"><span>⌂</span>Dashboard</a>
        <a href="<?= BASE_URL ?>/scanner.php"><span>▣</span>Scanner</a>

        <?php if (in_array($role, ['super_admin', 'kepala_sekolah', 'admin'])): ?>
            <span class="menu-title">KEHADIRAN</span>
            <a href="<?= BASE_URL ?>/attendance/students.php"><span>✓</span>Absensi Siswa</a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'kepala_sekolah'])): ?>
            <a href="<?= BASE_URL ?>/attendance/staff.php"><span>♟</span>Absensi Staff</a>
            
            <span class="menu-title">MASTER DATA</span>
            <a href="<?= BASE_URL ?>/students/index.php"><span>👨‍🎓</span>Siswa</a>
            <a href="<?= BASE_URL ?>/staff/index.php"><span>👨‍🏫</span>Staff / Guru</a>
            <a href="<?= BASE_URL ?>/admins/index.php"><span>👤</span>Admin</a>
            
            <?php if ($role === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>/kepala_sekolah/index.php"><span>👤</span>Kepala Sekolah</a>
                <a href="<?= BASE_URL ?>/units/index.php"><span>💼</span>Unit</a>
            <?php endif; ?>
            
            <a href="<?= BASE_URL ?>/grades/index.php"><span>▦</span>Grade</a>
            <a href="<?= BASE_URL ?>/classes/index.php"><span>▤</span>Subkelas</a>
            <a href="<?= BASE_URL ?>/activities/index.php"><span>★</span>Activities</a>
            <a href="<?= BASE_URL ?>/enrollments/index.php"><span>⇄</span>Kenaikan Kelas</a>
            <a href="<?= BASE_URL ?>/weekly_schedules/index.php"><span>🕒</span>Jadwal Pekanan</a>
            
            <?php if ($role === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>/academic_years/index.php"><span>◷</span>Tahun Ajaran</a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'kepala_sekolah', 'admin'])): ?>
            <span class="menu-title">LAPORAN</span>
            <a href="<?= BASE_URL ?>/reports/index.php"><span>▤</span>Laporan Absen</a>
        <?php endif; ?>
        
        <?php if (in_array($role, ['super_admin', 'kepala_sekolah'])): ?>
            <a href="<?= BASE_URL ?>/overtimes/index.php"><span>⏱</span>Lembur Staff</a>
        <?php endif; ?>

        <span class="menu-title">AKUN</span>
        <a href="<?= BASE_URL ?>/logout.php" class="logout-link"><span>↪</span>Keluar</a>
    </nav>
</aside>