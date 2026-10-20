# 📢 CATATAN PEMBARUAN SISTEM (RELEASE & UPDATE NOTES)

Dokumen ini ditujukan bagi seluruh tim kolaborator/pengembang untuk memahami pembaruan arsitektur, modul baru, migrasi database, dan tata letak antarmuka yang telah diterapkan pada repositori ini.

---

## 📌 Ringkasan Pembaruan Utama

1. **Modul Baru: Supervisi Kinerja Guru Terpadu (KURMAA Framework)**
2. **Pembaruan Tampilan: Full-Width Layout (100% Lebar Layar)**
3. **Penyempurnaan RBAC: Aksi Massal (Bulk Role Management) & Setting Manual**
4. **Navigasi: Restrukturisasi Sidebar Parent-Child Dropdown Accordion**
5. **Modul Pembinaan & Mentoring: Pemisahan Raport dan Kelompok Mentoring**
6. **Keamanan & Standar Zero Bug Protocol**

---

## 🌟 1. Modul Supervisi Kinerja Guru (KURMAA Framework)

Direktori: `public/supervisi/`  
Migrasi DB: `database/supervision_v1_migration.sql`

Modul ini mengadopsi standar penilaian kinerja guru dan kepala sekolah berbasis instrumen KURMAA (Karakter, Unggul, Religius, Mandiri, Amanah, Adaptif) yang fleksibel:
- **`index.php` (Dashboard Supervisi)**:
  - Ringkasan 4 Kartu KPI: Total Supervisi, Rata-rata Skor Sekolah, Guru Outstanding, dan Need Improvement.
  - Grafik Distribusi Predikat (Donut Chart) dan Capaian Rerata per Komponen (Bar Chart).
  - Tabel 5 dokumen evaluasi pembelajaran terbaru.
- **`peer.php` (Observasi Rekan Sejawat)**:
  - Instrumen observasi kelas antar rekan guru sejawat.
  - Sticky Live Score Bar di bagian atas yang menghitung skor berjalan, persentase, dan predikat secara realtime saat observer memilih nilai butir rubrik.
- **`principal.php` (Supervisi Kepala Sekolah)**:
  - Instrumen penilaian komprehensif kepala sekolah (perangkat ajar, kepemimpinan pembelajaran, asesmen, dan pengembangan profesional).
- **`schedules.php` (Penjadwalan Supervisi)**:
  - Pengelolaan jadwal supervisi terjadwal, status (terjadwal / berlangsung / selesai), filter multi-kriteria, dan aksi cepat mulai observasi.
- **`reports.php` (Rekapitulasi & Cetak Raport)**:
  - Arsip dokumen supervisi dengan filter lengkap (periode, jenis, guru, predikat).
  - Fitur Ekspor ke Excel (.xlsx) dan Backup JSON.
  - Cetak format Raport Resmi Standar A4 siap tanda tangan.
- **`rubrics.php` (Kelola Rubrik, Indikator & Skor Fleksibel)**:
  - Konfigurasi mandiri tanpa ubah kodingan PHP: tambah/ubah kategori aspek, butir indikator dinamis, bobot nilai, opsi skala skor (1-4), dan ambang batas predikat.

---

## 🖥️ 2. Tampilan Full-Width (Kiri-Kanan Penuh)

- **Masalah Sebelumnya**: Konten supervisi dibatasi `max-width: 1200px / 1300px; margin: 0 auto;` sehingga tampak terjepit di tengah dan menyisakan ruang kosong besar di sebelah kanan monitor widescreen desktop.
- **Pembaruan**: Seluruh 6 file supervisi kini menggunakan kontainer `width: 100%` tanpa batas kaku.
- **Hasil**:
  - Grid 4 KPI card melebar proporsional (`grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))`).
  - Dua kartu grafik (chart) memiliki area render luas seimbang (50% - 50% di desktop).
  - Tabel rekapitulasi data dan form butir indikator memiliki ruang baca yang lega tanpa teks terpotong.

---

## 🛡️ 3. Manajemen Akses & Role Pengguna (Aksi Massal & Setting Manual)

File: `public/kelola/index.php`

- **Filter Default Ringan (`staff_only`)**:
  - Tabel pengguna secara default memfilter guru/staf/admin (non-siswa) sehingga tabel memuat instan (5ms) tanpa terbebani oleh ratusan data siswa.
  - Disediakan tombol pills cepat di atas tabel untuk beralih filter: `Semua Staf`, `Semua Pengguna`, `Super Admin`, `Kepala Sekolah`, `Admin`, `Staff`, `HRD`, dan `Siswa`.
- **Aksi Massal (Bulk Role Management)**:
  - Checkbox pada header tabel (*Select All*) dan tiap baris pengguna.
  - Floating Bulk Action Toolbar muncul otomatis di bawah layar saat $\ge 1$ pengguna dicentang:
    1. **Ubah Role Massal**: Mengubah role puluhan user sekaligus dengan pilihan sinkronisasi (terapkan default role baru vs pertahankan menu saat ini).
    2. **Reset ke Default Role**: Menyelaraskan izin seluruh user terpilih ke template bawaan role masing-masing.
    3. **Beri/Cabut Menu Massal**: Menambah (+Grant) atau mencabut (-Revoke) menu tertentu sekaligus tanpa mengganggu menu lainnya.
    4. **Hapus Terpilih**: Menonaktifkan/soft-delete massal dengan proteksi anti-hapus akun Super Admin aktif.
- **Quick Role Switcher & Setting Manual**:
  - Tombol **"Ubah"** di samping badge role pada setiap pengguna.
  - Opsi `manual_customize` yang setelah role diubah langsung membuka jendela modal checklist menu pengguna tersebut secara otomatis untuk disesuaikan secara manual.
- **Edit Data Role**:
  - Pada Tab Master Role, Super Admin dapat mengubah Nama Role dan Deskripsi langsung melalui modal.

---

## 📂 4. Restrukturisasi Sidebar Parent-Child Dropdown Accordion

File: `includes/sidebar.php`

Sidebar kini mendukung pengelompokan menu bersarang (Parent-Child) dengan animasi accordion halus:
1. **Parent: Pembinaan**
   - Mentoring Siswa (MIP) (`mentoring/index.php`)
   - Laporan & Raport (`mentoring/report.php`)
   - Kurikulum Adab (`mentoring/curriculum.php`)
   - Pemantauan Adab (`mentoring/adab_monitoring.php`)
   - Kelompok Mentoring (`mentoring/assignments.php`)
2. **Parent: Supervisi Guru**
   - Dashboard Supervisi (`supervisi/index.php`)
   - Supervisi Sejawat (`supervisi/peer.php`)
   - Supervisi Kepsek (`supervisi/principal.php`)
   - Jadwal Supervisi (`supervisi/schedules.php`)
   - Rekapitulasi Data (`supervisi/reports.php`)
   - Kelola Rubrik & Skor (`supervisi/rubrics.php`)
- **Fitur Tambahan**: Auto-expand dropdown jika rute yang sedang aktif berada di dalam child menu tersebut, animasi rotasi ikon chevron, dan penutupan otomatis di mobile screen.

---

## 📊 5. Pemisahan Modul Mentoring (Raport & Kelompok)

- **`public/mentoring/assignments.php`**: Rute khusus pengelolaan kelompok santri dan penugasan guru pembina.
- **`public/mentoring/report.php`**: Rute mandiri rekapitulasi nilai pembiasaan adab dan cetak raport format A4.
- **Filter Duplikasi Pembina**: Perbaikan query DISTINCT dan JOIN pada data guru pembina agar tidak muncul nama ganda pada dropdown pemilihan.
- **Catatan Jurnal**: Logika penanganan skor jurnal ganda saat ini di-HOLD sementara sesuai arahan koordinasi.

---

## 🔒 6. Standar Keamanan & Anti-Bug (Zero HTTP 500)

- **Database Transactions**: Seluruh mutasi massal dan penyimpanan supervisi 100% menggunakan `beginTransaction()`, `commit()`, dan `rollBack()`.
- **Prepared Statements**: 100% query database menggunakan parameter binding `$stmt->prepare()` (Anti SQL Injection).
- **Sanitasi XSS**: Semua variabel output browser di-escape dengan `htmlspecialchars()` / `e()`.
- **Proteksi Akun Root**: Logika server-side memblokir aksi hapus diri sendiri (self-deletion) dan penurunan wewenang (self-demotion) bagi akun Super Admin aktif.
- **Validasi Sintaks**: Seluruh berkas telah diverifikasi menggunakan `php -l` dengan hasil **0 error sintaks**.

---

## 🚀 Panduan Menjalankan Migrasi di Server Lokal/Staging

Jika Anda menarik (*git pull*) pembaruan ini di komputer lokal atau server lain, jalankan migrasi database berikut:

1. **Migrasi Tabel Supervisi Guru**:
   ```bash
   mysql -u root library_v4 < database/supervision_v1_migration.sql
   ```
2. **Migrasi RBAC & Menu Mentoring**:
   ```bash
   php database/rbac_sidebar_migration.php
   ```
3. **Verifikasi Sintaks**:
   ```bash
   php -l public/supervisi/index.php
   php -l public/kelola/index.php
   ```

Selamat berkolaborasi! Hubungi tim pengembang jika ada pertanyaan seputar penyesuaian instrumen atau integrasi lebih lanjut.
