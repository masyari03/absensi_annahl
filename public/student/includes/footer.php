<?php
// public/student/includes/footer.php
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
        </div><!-- end content container -->
        
        <footer style="padding: 16px 24px; text-align: center; font-size: 12.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; background: #ffffff;">
            &copy; <?= date('Y') ?> An-Nahl Islamic School — Sistem Absensi & Portal Siswa
        </footer>
    </div><!-- end main -->
</div><!-- end app -->

<!-- BOTTOM NAVIGATION BAR UNTUK HP / MOBILE (< 768px) -->
<style>
@media (max-width: 768px) {
    body {
        padding-bottom: 64px !important;
    }
    .student-bottom-nav {
        display: flex !important;
    }
}
.student-bottom-nav {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: 60px;
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.05);
    z-index: 1000;
    justify-content: space-around;
    align-items: center;
    padding: 0 4px;
}
.student-bottom-nav a, .student-bottom-nav button {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    color: #64748b;
    text-decoration: none;
    font-size: 10px;
    font-weight: 600;
    flex: 1;
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px 0;
    transition: all 0.15s ease;
}
.student-bottom-nav a.active, .student-bottom-nav a:hover, .student-bottom-nav button:hover {
    color: #0284c7;
}
.student-bottom-nav a.active i {
    color: #0284c7;
}
.student-bottom-nav a i, .student-bottom-nav button i {
    font-size: 18px;
}
</style>

<div class="student-bottom-nav no-print">
    <a href="dashboard.php" class="<?= $currentScript === 'dashboard.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <a href="attendance.php" class="<?= $currentScript === 'attendance.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-check"></i>
        <span>Absen</span>
    </a>
    <a href="adab.php" class="<?= $currentScript === 'adab.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-heart-circle-check"></i>
        <span>Adab</span>
    </a>
    <a href="mentoring_notes.php" class="<?= $currentScript === 'mentoring_notes.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-hands-holding-child"></i>
        <span>MIP</span>
    </a>
    <button type="button" onclick="toggleStudentSidebar()" title="Buka Semua Menu">
        <i class="fa-solid fa-bars"></i>
        <span>Menu</span>
    </button>
</div>

</body>
</html>
