# 📋 CATATAN PEMBARUAN DATABASE & ACL MIGRATION
**Proyek:** AIS Absensi An-Nahl Islamic School  
**Tanggal Update:** 5 Oktober 2026  
**Status Pengujian:** 100% Lolos Verifikasi & Siap Digunakan di Server Produksi  
**File Skrip Migrasi:** [`database/acl_migration.sql`](file:///c:/laragon/www/absensi-anak/database/acl_migration.sql)

---

## 🚀 1. Ringkasan Fitur Baru yang Diimplementasikan

1. **Anti-Tabrakan Absensi Security (Shift 1 & Shift 2):**
   - Mendukung dua shift kerja security:
     - **Shift 1 (Pagi/Siang):** Masuk 06.00 – Pulang 18.00 (Shift normal hari yang sama).
     - **Shift 2 (Malam):** Masuk 18.00 – Pulang 06.00 (Shift lintas hari / overnight).
   - **Smart State-Aware Engine:** Saat security scan jam 06.00 pagi, sistem secara otomatis mengecek riwayat:
     - Jika ada check-in shift malam kemarin yang belum check-out, scan jam 06.00 dicatat sebagai **Pulang Shift 2**.
     - Jika tidak ada check-in malam kemarin, scan jam 06.00 dicatat sebagai **Masuk Shift 1**.
   - Dilengkapi tabel penugasan personel khusus (`weekly_schedule_staff`) sehingga personel shift bisa diatur dan diprioritaskan.

2. **Pemisahan Kegiatan (Activities) Siswa vs Staff:**
   - Halaman Activities kini dipisahkan ke dalam 2 tab independen:
     - 🎓 **Kegiatan Siswa:** Khusus kalender kegiatan/event siswa, jam masuk-pulang siswa, dan libur KBM.
     - 💼 **Kegiatan Staff & Guru:** Khusus rapat, briefing, atau event kedinasan guru/staff.
   - Absensi scanner siswa dan staff tidak lagi saling menimpa atau terganggu oleh kegiatan kelompok lain.

3. **Pemisahan Jadwal Pekanan (KBM Siswa, Staff/Guru, Eskul):**
   - 🎓 **Jadwal Siswa (KBM):** Bisa dispesifikasi per Unit, per Tingkat (Grade), atau per Subkelas.
   - 💼 **Jadwal Staff & Guru:** Mengatur shift kerja (normal maupun malam lintas hari) beserta checklist penugasan personel per shift.
   - ⚽ **Kegiatan Eskul:** Memilih peserta murid secara spesifik dengan filter pencarian instan, filter tingkat, dan subkelas.

4. **Atur Absen Otomatis Kepala Sekolah & Custom Guru/Kelas:**
   - Menu Atur Absen Otomatis kini dapat diakses oleh Kepala Sekolah sesuai akses unitnya.
   - Mendukung penyesuaian khusus per guru/user tertentu, grade tertentu, atau subkelas tertentu.

5. **Kontrol 4 Jenis Pesan Otomatis WhatsApp per Unit:**
   - Setiap unit (SD, SMP, SMA, Pondok, dll.) memiliki 4 template pesan WhatsApp mandiri:
     - `masuk_siswa`, `pulang_siswa`, `masuk_staff`, `pulang_staff`.
   - Kepala Sekolah dapat menyesuaikan redaksi pesan dan mengaktifkan/menonaktifkan pesan untuk unitnya sendiri.

6. **Dashboard Pemakaian WhatsApp:**
   - Memantau volume pengiriman WhatsApp harian dan status pesan (Terkirim, Diterima, Dibaca, Gagal) untuk seluruh unit. Tampilan sepenuhnya responsif untuk smartphone dan laptop.

7. **Audit Log Aktivitas & Rollback:**
   - Mencatat setiap tindakan penambahan, pengubahan, atau penghapusan data penting.
   - Superadmin dapat membatalkan aksi (*Rollback*) serta membersihkan data log lama.
   - Kepala Sekolah dapat melihat aktivitas unitnya masing-masing.

8. **Kelola Akun Cadangan Darurat Superadmin:**
   - CRUD akun pengguna di menu Super Admin untuk kesiapsiagaan cadangan jika akun utama mengalami kendala atau percobaan peretasan.

---

## 🗄️ 2. Daftar Tabel Baru di Database

| Nama Tabel | Fungsi & Keterangan |
|---|---|
| `activity_audit_logs` | Menyimpan jejak audit seluruh aktivitas sistem (user, role, aksi, payload `old_data` & `new_data`, IP, serta status rollback). |
| `weekly_schedule_students` | Relasi *many-to-many* antara jadwal eskul dengan siswa peserta eskul tersebut. |
| `weekly_schedule_staff` | Relasi *many-to-many* penugasan shift kerja tertentu ke personel staff/security spesifik. |
| `unit_message_templates` | Menyimpan 4 varian template pesan WhatsApp per unit sekolah beserta saklar aktif/nonaktif. |
| `whatsapp_usage_daily` | Rekapitulasi statistik harian penggunaan WhatsApp Gateway per unit sekolah. |

---

## 🧱 3. Daftar Kolom Baru pada Tabel Eksisting

### A. Tabel `weekly_schedules`
- `target_type` : `ENUM('student', 'staff') NOT NULL DEFAULT 'student'`
- `grade_id` : `INT NULL` (Foreign key tingkat siswa)
- `class_group_id` : `INT NULL` (Foreign key subkelas siswa)
- `schedule_type` : `ENUM('reguler', 'eskul') NOT NULL DEFAULT 'reguler'`
- `student_in` : `TIME NULL DEFAULT '00:00:00'` (Jam masuk KBM siswa)
- `student_late` : `TIME NULL DEFAULT '00:00:00'` (Batas telat siswa)
- `student_out` : `TIME NULL DEFAULT '00:00:00'` (Jam pulang siswa)
- `staff_in` : `TIME NULL DEFAULT '00:00:00'` (Jam masuk staff/guru)
- `staff_late` : `TIME NULL DEFAULT '00:00:00'` (Batas telat staff/guru)
- `staff_out` : `TIME NULL DEFAULT '00:00:00'` (Jam pulang staff/guru)
- `is_overnight` : `TINYINT(1) NOT NULL DEFAULT 0` (1 = Shift malam lintas hari)

### B. Tabel `auto_attendances`
- `user_id` : `INT NULL` (Opsional untuk pengkhususan guru/staf tertentu)
- `grade_id` : `INT NULL` (Opsional untuk pembatasan per tingkat)
- `class_group_id` : `INT NULL` (Opsional untuk pembatasan per subkelas)

### C. Tabel `activities`
- `target_type` : `ENUM('student', 'staff', 'all') NOT NULL DEFAULT 'student'` (Membedakan kegiatan siswa vs kegiatan staff)

---

## 🔐 4. Pembaruan Menu & Hak Akses (ACL)

### Menu Baru pada Tabel `app_menus`:
1. `kelola_wa_dashboard` → URL: `kelola/wa_dashboard.php` | Ikon: `fa-chart-pie` (Dashboard WA Gateway)
2. `kelola_audit_logs` → URL: `kelola/audit_logs.php` | Ikon: `fa-clock-rotate-left` (Audit Log Aktivitas)
3. `kelola_message_templates` → URL: `kelola/message_templates.php` | Ikon: `fa-comments` (Template Pesan Unit)
4. `super_kelola_akun` → URL: `kelola/super/index.php` | Ikon: `fa-users-gear` (Kelola Akun Cadangan)

### Pemetaan Hak Akses pada `role_menu_access`:
- **Super Admin (`super_admin`):** Memiliki akses ke seluruh menu tanpa pembatasan (termasuk kelola akun cadangan dan audit log global).
- **Kepala Sekolah (`kepala_sekolah`):** Diberikan akses ke `kelola_audit_logs` dan `kelola_message_templates` (terisolasi hanya untuk data unit yang dipimpinnya).
- **Admin Unit & Staff:** Tetap dibatasi pada menu operasional masing-masing.

---

## ⚡ 5. Cara Upload & Eksekusi Migrasi di Server Web (Produksi)

### Opsi A: Menggunakan phpMyAdmin (cPanel / DirectAdmin / Plesk)
1. Buka cPanel hosting Anda, lalu masuk ke **phpMyAdmin**.
2. Pilih database aplikasi absensi Anda (misal: `db_absensi` atau `library_v4`).
3. Klik tab **Import** pada menu atas.
4. Klik **Choose File / Browse**, lalu pilih file [`database/acl_migration.sql`](file:///c:/laragon/www/absensi-anak/database/acl_migration.sql).
5. Klik tombol **Go / Kirim** di kanan bawah.
6. Seluruh tabel, kolom, index, dan menu ACL akan langsung terpasang tanpa bentrok (*idempotent*).

### Opsi B: Menggunakan Terminal / SSH (Jika Memiliki Akses Shell)
Jalankan perintah berikut di terminal server:
```bash
mysql -u [USERNAME_DB] -p [NAMA_DATABASE] < /path/ke/aplikasi/database/acl_migration.sql
```

### Opsi C: Menggunakan PHP Script Langsung di Browser
File PHP migrasi juga telah disiapkan di server lokal:
- Buka di browser: `https://domain-anda.com/database/migrate_features_phase3.php`
- Buka di browser: `https://domain-anda.com/database/migrate_features_phase2.php`

---

## 📁 6. Daftar File Source Code yang Perlu Di-Upload / Ditimpa ke Server

Jika Anda melakukan upload manual via FTP / File Manager cPanel, pastikan file-file berikut ikut ter-upload:

1. **Core & Config:**
   - [`includes/functions.php`](file:///c:/laragon/www/absensi-anak/includes/functions.php) *(Engine Anti-Collision Shift Security, Resolusi Activity, dan Audit Trail)*
   - [`includes/header.php`](file:///c:/laragon/www/absensi-anak/includes/header.php) *(Integrasi font dan menu)*

2. **Scanner & Engine Absensi:**
   - [`public/scanner_process.php`](file:///c:/laragon/www/absensi-anak/public/scanner_process.php) *(Proses scan barcode/RFID/fingerprint multi-shift dan activity terpisah)*
   - [`public/api/esp32_attendance.php`](file:///c:/laragon/www/absensi-anak/public/api/esp32_attendance.php) *(Dukungan mesin tapping ESP32)*

3. **Master Jadwal Pekanan & Eskul:**
   - [`public/weekly_schedules/index.php`](file:///c:/laragon/www/absensi-anak/public/weekly_schedules/index.php)
   - [`public/weekly_schedules/create.php`](file:///c:/laragon/www/absensi-anak/public/weekly_schedules/create.php)
   - [`public/weekly_schedules/edit.php`](file:///c:/laragon/www/absensi-anak/public/weekly_schedules/edit.php)
   - [`public/weekly_schedules/delete.php`](file:///c:/laragon/www/absensi-anak/public/weekly_schedules/delete.php)
   - [`public/weekly_schedules/get_students_by_unit.php`](file:///c:/laragon/www/absensi-anak/public/weekly_schedules/get_students_by_unit.php)
   - [`public/weekly_schedules/get_staff_by_unit.php`](file:///c:/laragon/www/absensi-anak/public/weekly_schedules/get_staff_by_unit.php)

4. **Master Activities (Kegiatan Siswa vs Staff):**
   - [`public/activities/index.php`](file:///c:/laragon/www/absensi-anak/public/activities/index.php)
   - [`public/activities/create.php`](file:///c:/laragon/www/absensi-anak/public/activities/create.php)
   - [`public/activities/edit.php`](file:///c:/laragon/www/absensi-anak/public/activities/edit.php)
   - [`public/activities/close.php`](file:///c:/laragon/www/absensi-anak/public/activities/close.php)

5. **Modul Pengaturan & Kelola:**
   - [`public/kelola/index.php`](file:///c:/laragon/www/absensi-anak/public/kelola/index.php) *(Manajemen Akses & User)*
   - [`public/kelola/audit_logs.php`](file:///c:/laragon/www/absensi-anak/public/kelola/audit_logs.php) *(Audit Trail & Rollback)*
   - [`public/kelola/message_templates.php`](file:///c:/laragon/www/absensi-anak/public/kelola/message_templates.php) *(Kontrol 4 Pesan Unit)*
   - [`public/kelola/wa_dashboard.php`](file:///c:/laragon/www/absensi-anak/public/kelola/wa_dashboard.php) *(Dashboard Pemakaian WA)*
   - [`public/kelola/super/index.php`](file:///c:/laragon/www/absensi-anak/public/kelola/super/index.php) *(CRUD Akun Cadangan Superadmin)*

6. **Database:**
   - [`database/acl_migration.sql`](file:///c:/laragon/www/absensi-anak/database/acl_migration.sql)
   - [`database/CATATAN_UPDATE_DATABASE.md`](file:///c:/laragon/www/absensi-anak/database/CATATAN_UPDATE_DATABASE.md)
