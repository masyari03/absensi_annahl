# 🏫 Sistem Absensi Terpadu Siswa & Staff (An-Nahl Islamic School)

Aplikasi manajemen dan presensi sekolah terpadu berbasis web (PHP & MySQL) yang terintegrasi langsung dengan mesin absensi fisik **IoT ESP32** (Dual-Sensor: **RFID RC522** & **Fingerprint AS608**), notifikasi otomatis **WhatsApp Gateway**, dan panel administrasi cerdas multi-unit.

---

## 📑 Daftar Isi
1. [Fitur Unggulan](#-fitur-unggulan)
2. [Arsitektur & Anti-Tabrakan Perangkat IoT](#-arsitektur--anti-tabrakan-perangkat-iot)
3. [Struktur Folder](#-struktur-folder)
4. [Kebutuhan Sistem](#-kebutuhan-sistem)
5. [Tahapan Instalasi & Deployment](#-tahapan-instalasi--deployment-langkah-demi-langkah)
   - [1. Clone Repository](#1-clone-repository)
   - [2. Setup Database MySQL](#2-setup-database-mysql)
   - [3. Konfigurasi Aplikasi (`config/`)](#3-konfigurasi-aplikasi-config)
   - [4. Setup Web Server (Laragon / Apache / Hosting / VPS)](#4-setup-web-server)
   - [5. Setup WhatsApp Gateway (Fonnte)](#5-setup-whatsapp-gateway-fonnte)
   - [6. Setup & Flashing Mesin Absensi ESP32](#6-setup--flashing-mesin-absensi-esp32)
   - [7. Akun Login Default](#7-akun-login-default)
6. [Panduan Penggunaan Fitur Utama](#-panduan-penggunaan-fitur-utama)
   - [Bulk Update Siswa & Staff (Google Admin Style)](#-bulk-update-siswa--staff)
   - [Rekap Bulanan & Lembur Staff via WhatsApp](#-rekap-bulanan--lembur-staff-via-whatsapp)
   - [Pendaftaran Kartu RFID & Sidik Jari](#-pendaftaran-kartu-rfid--sidik-jari)
7. [Troubleshooting & FAQ](#-troubleshooting--faq)
8. [Lisensi & Kontribusi](#-lisensi)

---

## ✨ Fitur Unggulan

### 1. 👥 Manajemen Pengguna & Multi-Unit (Multi-Sekolah)
- **Role-Based Access Control (RBAC)**: Super Admin, Kepala Sekolah, Admin Unit, Staff/Guru, dan Siswa.
- Dukungan multi-unit terpadu (misal: Unit TK, SD, SMP, SMA).
- Pelacakan aktivitas pengguna (Heartbeat & User Activity Log).

### 2. ⚡ Bulk Update Data Siswa & Staff (Ala Google Admin Console)
- **Spreadsheet Interaktif**: Menampilkan data yang sudah ada di database dalam tabel yang dapat diedit langsung per sel.
- **Deteksi Otomatis Kolom Kosong & Baris Berubah**: Mempermudah identifikasi data yang belum lengkap (seperti nomor WhatsApp, No RFID, ID Sidik Jari).
- **Export & Import CSV Cerdas**:
  - Export data CSV yang sudah terisi (*pre-filled*).
  - Import CSV dengan opsi pengabaian kolom kosong (*ignore empty cells*) agar data yang tidak ingin diubah tetap aman.

### 3. 🤖 Integrasi Hardware ESP32 (RFID & Fingerprint)
- **Dual-Sensor Autentikasi**: Mendukung pemindaian kartu RFID Mifare (MFRC522 SPI) dan sensor sidik jari optik (AS608/R307 UART).
- **Indikator Visual & Audio**: LED 2 warna (Hijau = Berhasil/Tepat Waktu, Merah = Ditolak/Cooldown/Gagal) dan Active Buzzer.
- **Captive Portal Setup (`ABSENSI-SETUP`)**: Konfigurasi SSID WiFi, Password, API URL, Device ID, dan Token secara nirkabel melalui smartphone tanpa perlu flashing ulang.
- **Dukungan HTTP & HTTPS**: Firmware mendukung domain lokal maupun domain online bersertifikat SSL (`WiFiClientSecure`).

### 4. 📲 WhatsApp Gateway & Notifikasi Otomatis
- Notifikasi kehadiran real-time ke nomor WhatsApp orang tua saat siswa melakukan tap/scan masuk atau pulang.
- **Rekap Bulanan Staf & Rekap Lembur**:
  - Dikirimkan pada akhir bulan (atau rentang tanggal kustom).
  - Memuat ringkasan kehadiran (Hadir, Sakit, Izin, Terlambat) dan **total akumulasi jam & menit lembur**.
  - Dilengkapi kontrol sakelar (*toggle*) di tingkat Super Admin dan Kepala Sekolah per unit.

### 5. 📊 Laporan & Monitoring
- Dashboard visual statistik kehadiran real-time.
- Ekspor laporan kehadiran dan rekap lembur ke format **Excel (.xlsx)** dan cetak **PDF**.
- Halaman monitoring perangkat ESP32 online/offline beserta log audit tap presensi.

---

## 🛡️ Arsitektur & Anti-Tabrakan Perangkat IoT

Setiap unit sekolah dapat mengoperasikan **2 hingga 4 alat ESP32** secara bersamaan (misal: Gerbang Depan, Lobi Utama, Pintu Belakang):

```text
[ Kartu RFID / Sidik Jari ]
             │
             ▼
   ┌───────────────────┐       WiFi (WPA2)       ┌────────────────────────────────┐
   │  ESP32 Mesin 1    │ ──────────────────────> │  REST API Backend              │
   │  (Gerbang Depan)  │  Header:                │  (esp32_attendance.php)        │
   └───────────────────┘  X-Device-Id            │  - Validasi Device & Token     │
                          X-Device-Token         │  - Atomic DB Locking (FOR UPDATE)
   ┌───────────────────┐                         │  - Debounce Cooldown 20 Detik  │
   │  ESP32 Mesin 2    │ ──────────────────────> │  - Dispatch WhatsApp Otomatis  │
   │  (Lobi Utama)     │                         └────────────────────────────────┘
   └───────────────────┘                                       │
                                                               ▼
                                                  ┌───────────────────────────────┐
                                                  │ MySQL Database (library_v4)   │
                                                  │ + WhatsApp Gateway (Fonnte)   │
                                                  └───────────────────────────────┘
```

1. **Autentikasi Alat Berbasis Token**: Setiap alat memiliki `Device ID` dan `Device Token` unik yang divalidasi ketat oleh endpoint REST API.
2. **Staff Bisa Absen di Mana Saja**: Guru/Staff bebas melakukan presensi di unit mana pun; sistem secara otomatis mencocokkan jadwal kerja shift unit asal staf tersebut.
3. **Pencegahan Double-Tap (Debounce & Atomic Lock)**:
   - Sisi ESP32: Delay debounce 3 detik.
   - Sisi Backend: Cooldown 20 detik dan penguncian baris database (`FOR UPDATE`), mencegah duplikasi meskipun user tap pada dua alat berbeda dalam detik yang sama.

---

## 📂 Struktur Folder

```text
absensi-anak/
├── assets/                     # File statis (CSS, Javascript, gambar)
│   ├── css/style.css           # Styling utama antarmuka sistem
│   └── js/app.js               # Script interaksi UI & AJAX
├── config/                     # Konfigurasi sistem
│   ├── auth.php                # Middleware proteksi login & sesi
│   ├── config.php              # Definisi BASE_URL & path uploads
│   ├── database.php            # Koneksi PDO MySQL
│   └── security.php            # Filter CSRF & sanitasi input
├── database/                   # Skema dan file migrasi database
│   ├── absensi_annahl_latest.sql  # Dump database lengkap siap import
│   ├── esp32_and_bulk_migration.php # Migrasi fitur ESP32 & Bulk Update
│   ├── whatsapp_migration.php  # Migrasi tabel & pengaturan WhatsApp
│   └── acl_migration.sql       # Migrasi hak akses menu & role
├── esp32/                      # Firmware & Panduan Hardware IoT
│   ├── absensi_esp32.ino       # Source code Arduino firmware ESP32
│   └── README.md               # Skema pinout lengkap & panduan wiring
├── includes/                   # Modul PHP reusable
│   ├── functions.php           # Fungsi helper umum & auth
│   ├── header.php / footer.php # Template layout antarmuka
│   ├── sidebar.php             # Navigasi dinamis berbasis role
│   └── whatsapp.php            # Engine pengiriman WA & kalkulasi lembur
├── kelola/                     # Modul pengelolaan Super Admin & activity
├── public/                     # Dokumen root aplikasi web (Entry point)
│   ├── api/                    # Endpoint REST API
│   │   └── esp32_attendance.php # Endpoint penerima presensi dari ESP32
│   ├── devices/index.php       # Manajemen & monitoring perangkat ESP32
│   ├── students/bulk_edit.php  # Bulk edit data siswa
│   ├── staff/bulk_edit.php     # Bulk edit data staff & nomor WA
│   ├── whatsapp/index.php      # Panel WhatsApp Gateway & Rekap Bulanan
│   ├── scanner.php             # Antarmuka scanner barcode/RFID USB
│   └── dashboard.php           # Dashboard utama
├── uploads/                    # Direktori penyimpanan foto (admins, staff, students)
└── vendor/fpdf/                # Library FPDF untuk pembuatan laporan PDF
```

---

## 💻 Kebutuhan Sistem

- **Web Server**: Apache / Nginx / Laragon / XAMPP
- **Bahasa Pemrograman**: PHP **7.4** atau PHP **8.0 / 8.1 / 8.2**
- **Ekstensi PHP**: `pdo_mysql`, `curl`, `json`, `mbstring`, `fileinfo`
- **Database**: MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.4+
- **Arduino IDE**: Versi 1.8.x atau 2.x dengan Board Package ESP32
- **Layanan WhatsApp**: Akun API Fonnte (token didapat dari [fonnte.com](https://fonnte.com))

---

## 🚀 Tahapan Instalasi & Deployment Langkah demi Langkah

### 1. Clone Repository
Clone repositori ke folder web server Anda (misal `c:/laragon/www/` atau `/var/www/html/`):
```bash
git clone https://github.com/masyari03/absensi_annahl.git absensi-anak
cd absensi-anak
```

---

### 2. Setup Database MySQL

1. Buka phpMyAdmin, HeidiSQL, atau terminal MySQL Anda.
2. Buat database baru bernama `library_v4` (atau nama pilihan Anda dengan `utf8mb4_general_ci`):
   ```sql
   CREATE DATABASE library_v4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import file database utama siap pakai yang sudah mencakup seluruh tabel, menu, dan struktur terbaru:
   ```bash
   # Melalui terminal mysql:
   mysql -u root -p library_v4 < database/absensi_annahl_latest.sql
   ```
   *(Atau buka phpMyAdmin -> Pilih database `library_v4` -> Tab **Import** -> Pilih file `database/absensi_annahl_latest.sql` -> Klik **Go**)*.

4. *(Opsional jika memperbarui dari database lama)*:
   Jika Anda menggunakan database lama, jalankan script migrasi berikut melalui terminal atau browser:
   ```bash
   php database/esp32_and_bulk_migration.php
   php database/whatsapp_migration.php
   ```

---

### 3. Konfigurasi Aplikasi (`config/`)

#### A. Konfigurasi Database (`config/database.php`)
Buka file `config/database.php` dan sesuaikan kredensial database Anda:
```php
$host     = '127.0.0.1';
$dbname   = 'library_v4';
$username = 'root';
$password = ''; // Kosongkan jika di Laragon default, atau isi password MySQL Anda
```

#### B. Konfigurasi Alamat URL (`config/config.php`)
Buka file `config/config.php` dan tentukan alamat `BASE_URL`:
- **Di Lingkungan Lokal (Laragon)**:
  ```php
  define('BASE_URL', 'http://localhost/absensi-anak/public');
  ```
- **Di Server Hosting / VPS (Domain Online)**:
  - Jika Document Root web mengarah langsung ke folder `public/`:
    ```php
    define('BASE_URL', 'https://absensi.domain-sekolah.sch.id');
    ```
  - Jika Document Root mengarah ke folder utama:
    ```php
    define('BASE_URL', 'https://domain-anda.com/public');
    ```

---

### 4. Setup Web Server

#### Jika Menggunakan Laragon (Windows):
1. Pastikan Service **Apache** dan **MySQL** aktif di Laragon.
2. Akses aplikasi melalui browser:
   `http://localhost/absensi-anak/public/login.php`

#### Jika Menggunakan VPS / Hosting (Linux / cPanel):
1. Arahkan Document Root domain ke folder `/absensi-anak/public`.
2. Pastikan permission folder `uploads/` dapat ditulisi oleh web server:
   ```bash
   chmod -R 775 uploads/
   chown -R www-data:www-data uploads/
   ```

---

### 5. Setup WhatsApp Gateway (Fonnte)

1. Login ke aplikasi sebagai **Super Admin**.
2. Masuk ke menu **WhatsApp -> Pengaturan Token**.
3. Masukkan **API Token Fonnte** Anda.
4. Pada tab **Template Pesan**, sesuaikan template pesan:
   - Notifikasi Datang & Pulang Siswa
   - Rekap Kehadiran Bulanan & Rekap Lembur Staf (Placeholder: `{nama}`, `{bulan}`, `{hadir}`, `{total_lembur}`, dll.)
5. Pastikan status WhatsApp Gateway aktif (*Enabled*).

---

### 6. Setup & Flashing Mesin Absensi ESP32

#### A. Skema Pin Hardware

| Komponen Hardware | Pin Modul Sensor | Pin ESP32 (GPIO) | Catatan Penting |
|---|---|---|---|
| **RFID RC522 (SPI)** | SDA (SS) | **GPIO 5** | Chip Select |
| | SCK | **GPIO 18** | SPI Clock |
| | MOSI | **GPIO 23** | SPI Master Out |
| | MISO | **GPIO 19** | SPI Master In |
| | RST | **GPIO 22** | Reset Pin |
| | 3.3V | **3.3V** | ⚠️ **Wajib 3.3V (Bukan 5V!)** |
| | GND | **GND** | Ground |
| **Fingerprint AS608** | TX Sensor | **GPIO 16 (RX2)** | Masuk ke pin RX2 ESP32 |
| | RX Sensor | **GPIO 17 (TX2)** | Masuk ke pin TX2 ESP32 |
| | VCC & GND | **3.3V/5V & GND**| Ground bersama |
| **Indikator LED** | LED Hijau (+) | **GPIO 2** | Seri Resistor 220Ω ke GND |
| | LED Merah (+) | **GPIO 4** | Seri Resistor 220Ω ke GND |
| **Buzzer** | Buzzer (+) | **GPIO 15** | Active Buzzer (3.3V - 5V) |
| **Tombol Reset** | Push Button | **GPIO 14** & **GND** | Tahan 3 detik untuk reset WiFi |

#### B. Upload Firmware ke ESP32

1. Buka **Arduino IDE**.
2. Install library yang dibutuhkan via **Tools -> Manage Libraries...**:
   - `MFRC522`
   - `Adafruit Fingerprint Sensor Library`
   - `ArduinoJson` (v6 / v7)
3. Buka file [`esp32/absensi_esp32.ino`](file:///esp32/absensi_esp32.ino).
4. Pilih Board **DOIT ESP32 DEVKIT V1**, tancapkan kabel USB, dan klik **Upload**.

#### C. Konfigurasi WiFi & Token Melalui Captive Portal

1. Saat pertama menyala, ESP32 memancarkan WiFi:
   - **SSID**: `ABSENSI-SETUP`
   - **Password**: *(Tanpa password / Open)*
2. Hubungkan smartphone / laptop Anda ke WiFi `ABSENSI-SETUP`.
3. Buka browser dan akses alamat IP `192.168.4.1`.
4. Isi formulir yang tampil:
   - **WiFi Sekolah**: Pilih SSID dan masukkan password WiFi sekolah Anda.
   - **URL REST API Backend**:
     - Jika lokal: `http://192.168.1.100/absensi-anak/public/api/esp32_attendance.php`
     - Jika online: `https://domain-anda.com/public/api/esp32_attendance.php`
   - **Device ID**: Masukkan ID alat (contoh: `ESP32-UNIT1-DEV1`).
   - **Device Token**: Salin dari menu **Pengaturan -> Perangkat ESP32** di dashboard web.
5. Klik **Simpan & Sambungkan**. ESP32 akan merestart dan langsung terhubung secara otomatis!

---

### 7. Akun Login Default

Setelah import database, Anda dapat login menggunakan kredensial default berikut:

| Role | Username | Password | Keterangan |
|---|---|---|---|
| **Super Admin** | `admin` | `admin123` | Akses penuh seluruh unit & konfigurasi sistem |
| **Kepala Sekolah** | `kepsek_sd` | `password` | Manajemen unit, laporan, dan toggle WA unit |
| **Admin Unit** | `admin_sd` | `password` | Operasional presensi dan data unit |

> 🔒 **Keamanan:** Segera ubah password default melalui menu **Profil Akun** setelah pertama kali login.

---

## 📖 Panduan Penggunaan Fitur Utama

### 📋 Bulk Update Siswa & Staff

1. Masuk ke menu **Siswa** atau **Staff**.
2. Klik tombol **⚡ Bulk Update**.
3. Di halaman spreadsheet:
   - Kolom yang kosong akan disorot warna kuning lembut untuk menarik perhatian.
   - Anda dapat mengisi nomor WhatsApp wali/staf, UID RFID, dan ID Sidik Jari secara langsung pada tabel.
   - Atau gunakan tombol **Download Template Pre-filled (CSV)**, lengkapi datanya di Microsoft Excel, lalu upload kembali melalui fitur **Import CSV**.
   - Centang opsi *“Abaikan kolom kosong pada CSV”* agar data lama tidak terhapus jika Anda hanya ingin memperbarui sebagian kolom.

---

### 📲 Rekap Bulanan & Lembur Staff via WhatsApp

1. Masuk ke menu **WhatsApp -> Rekap Bulanan Staf**.
2. Pilih rentang tanggal (default otomatis dari tanggal 1 hingga akhir bulan berjalan).
3. Tabel ringkasan akan menghitung secara otomatis:
   - Jumlah hari hadir, sakit, izin, dan alpa.
   - Total durasi lembur yang terhitung rapi dalam format jam dan menit (contoh: `4 Jam 35 Menit`).
4. Klik **Kirim Rekap WA** pada staf yang dipilih atau kirim massal sekaligus.
5. Pesan rekapitulasi resmi akan langsung masuk ke nomor WhatsApp masing-masing guru/staf.

---

### 💳 Pendaftaran Kartu RFID & Sidik Jari

1. **Nomor Kartu RFID**:
   - Dapat diinputkan melalui halaman Edit Siswa/Staff, Bulk Update, atau tempelkan kartu pada scanner USB di halaman `public/scanner.php`.
   - Format: Hexadecimal UID (contoh: `A3B8C1D0`).
2. **ID Sidik Jari (Fingerprint ID)**:
   - Daftarkan sidik jari pada modul sensor AS608 melalui prosedur enroll (ID angka 1 s.d. 127/300).
   - Masukkan nomor ID tersebut pada profil siswa atau staf yang bersangkutan.

---

## ❓ Troubleshooting & FAQ

#### 1. ESP32 Gagal Terhubung ke Domain Online (HTTPS)
* **Solusi**: Firmware kami sudah menyertakan `WiFiClientSecure` dengan mode `clientSecure.setInsecure()`. Pastikan alamat URL di form portal menggunakan prefix `https://` yang benar dan dapat dibuka melalui browser HP.

#### 2. Scan Kartu Berbunyi Beep 2x Panjang (LED Merah)
* **Penyebab**: Kartu RFID atau ID Sidik Jari belum terdaftar di database, atau status siswa/staf sedang tidak aktif.
* **Solusi**: Daftarkan nomor UID kartu ke akun yang bersangkutan melalui menu Bulk Update atau Edit Profil.

#### 3. Gambar Profil Gagal Diupload
* **Penyebab**: Permission folder `uploads/` tidak memiliki hak akses tulis.
* **Solusi**: Jalankan perintah `chmod -R 775 uploads/` pada server Linux/cPanel.

#### 4. Pesan WhatsApp Tidak Terkirim
* **Solusi**: Periksa status token Fonnte pada menu WhatsApp Settings dan pastikan kuota pesan serta nomor pengirim dalam keadaan aktif (*Connected*).

---

## 📄 Lisensi

Hak Cipta &copy; 2026 **An-Nahl Islamic School**. Dikembangkan untuk kebutuhan operasional presensi terpadu multi-unit yang efisien, modern, dan handal.