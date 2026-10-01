# Panduan Mesin Absensi Hardware ESP32 (RFID RC522 & Fingerprint AS608)

Dokumentasi lengkap perakitan, pengkabelan pin, upload firmware, konfigurasi awal captive portal, dan integrasi anti-tabrakan multi-perangkat ke sistem absensi sekolah.

---

## 📌 Daftar Komponen Hardware yang Digunakan

1. **Microcontroller**: ESP32 DevKit V1 (30 pin / 38 pin)
2. **RFID Reader**: MFRC522 (13.56 MHz SPI) + Kartu / Gantungan Kunci RFID Mifare 1K
3. **Fingerprint Sensor**: AS608 / R307 / FPM10A / R503 Optical Fingerprint Sensor (UART)
4. **Indikator LED 2 Warna**:
   - 1x LED Hijau (Tepat Waktu / Sukses / Ready)
   - 1x LED Merah (Terlambat / Ditolak / Offline / Error)
   - 2x Resistor 220Ω &mdash; 330Ω (untuk pembatas arus LED)
5. **Buzzer**: Active Buzzer 3.3V &mdash; 5V (Audio feedback)
6. **Tombol Reset / Setup**: Push Button (tactile switch) untuk reset WiFi & masuk captive portal
7. **Power Supply**: Adaptor 5V 2A micro-USB atau modul Step-Down 5V

---

## 🔌 Skema Pin Wiring ESP32

| Komponen Hardware | Pin Modul Sensor | Pin ESP32 (GPIO) | Keterangan & Catatan |
|---|---|---|---|
| **RFID MFRC522** | **SDA (SS)** | **GPIO 5** | SPI Slave Select |
| | **SCK** | **GPIO 18** | SPI Clock |
| | **MOSI** | **GPIO 23** | SPI Master Out |
| | **MISO** | **GPIO 19** | SPI Master In |
| | **RST** | **GPIO 22** | Reset Pin |
| | **3.3V** | **3.3V ESP32** | ⚠️ **PENTING:** Wajib 3.3V, jangan sambung ke 5V! |
| | **GND** | **GND** | Ground |
| **Fingerprint AS608** | **TX Sensor** | **GPIO 16 (RX2)** | Serial2 Hardware UART (Data Masuk ke ESP32) |
| | **RX Sensor** | **GPIO 17 (TX2)** | Serial2 Hardware UART (Data Keluar dari ESP32) |
| | **VCC** | **3.3V atau 5V** | Sesuai label modul (umumnya toleran 3.3V &mdash; 5V) |
| | **GND** | **GND** | Ground bersama |
| **LED Hijau** | Anoda (+) | **GPIO 2** | Melalui resistor 220Ω |
| | Katoda (-) | **GND** | Ground |
| **LED Merah** | Anoda (+) | **GPIO 4** | Melalui resistor 220Ω |
| | Katoda (-) | **GND** | Ground |
| **Buzzer** | Pin (+) | **GPIO 15** | Aktif HIGH |
| | Pin (-) | **GND** | Ground |
| **Tombol Reset** | Kaki 1 | **GPIO 14** | Menggunakan internal `INPUT_PULLUP` |
| | Kaki 2 | **GND** | Ground (Tekan 3 detik untuk reset) |

---

## 🛠️ Persiapan Arduino IDE & Library

1. Pasang Board ESP32 pada Arduino IDE:
   - Buka **File** -> **Preferences**.
   - Tambahkan URL berikut pada *Additional Board Manager URLs*:
     ```
     https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json
     ```
   - Buka **Tools** -> **Board** -> **Boards Manager**, cari `esp32` lalu klik **Install**.
2. Pasang Library yang Diperlukan via **Tools** -> **Manage Libraries...**:
   - **MFRC522** (oleh *GithubCommunity / Miguel Balboa*)
   - **Adafruit Fingerprint Sensor Library** (oleh *Adafruit*)
   - **ArduinoJson** (versi 6.x atau 7.x oleh *Benoit Blanchon*)
3. Pengaturan Board pada Arduino IDE:
   - **Board**: `DOIT ESP32 DEVKIT V1` (atau `ESP32 Dev Module`)
   - **Upload Speed**: `921600` (atau `115200`)
   - **Flash Frequency**: `80MHz`
   - **Port**: Pilih port COM ESP32 Anda.

---

## 🚀 Panduan Konfigurasi Awal (Captive Portal Anti-Tabrakan)

Ketika firmware baru pertama kali diunggah ke ESP32 atau saat tombol reset ditekan selama 3 detik:

1. ESP32 secara otomatis memancarkan jaringan WiFi Access Point:
   - **Nama WiFi (SSID)**: `ABSENSI-SETUP` (atau sesuai konfigurasi di sketch)
   - **Password**: *(Tanpa password / Open)*
2. Buka smartphone atau laptop Anda, sambungkan ke WiFi `ABSENSI-SETUP`.
3. Jendela pengaturan otomatis muncul di browser Anda (atau buka browser dan ketik alamat IP `192.168.4.1`).
4. Pada halaman formulir setup:
   - **Pilih WiFi Sekolah**: Pilih SSID WiFi sekolah Anda dan masukkan passwordnya.
   - **URL REST API Backend**:
     - **Jika sudah Online / Pakai Domain (Hosting/VPS)**:
       - Bila document root web mengarah ke folder utama:
         `https://domain-sekolah-anda.com/public/api/esp32_attendance.php`
       - Bila document root web langsung mengarah ke folder `public/`:
         `https://domain-sekolah-anda.com/api/esp32_attendance.php`
     - **Jika masih di Jaringan Lokal (Laragon/XAMPP)**:
       `http://192.168.1.100/absensi-anak/public/api/esp32_attendance.php`
   - **Device ID**: Masukkan ID alat unik, misalnya `ESP32-UNIT1-DEV1` untuk mesin 1, `ESP32-UNIT1-DEV2` untuk mesin 2, dst.
   - **Device Token**: Salin dari menu **Pengaturan -> Perangkat ESP32** di dashboard web.
5. Klik tombol **Simpan & Sambungkan**.
6. ESP32 akan menyimpan pengaturan ke memori Flash (NVS) dan merestart dirinya sendiri untuk langsung online dan siap pakai.

---

## 🛡️ Bagaimana Sistem Mencegah Tabrakan Antar Unit & Mesin?

1. **Identitas Unik Tiap Alat**:
   Setiap unit sekolah dapat memiliki **2 hingga 4 alat** (misalnya Gerbang Depan, Lobi, Pintu Samping). Tiap mesin memiliki `Device ID` dan `Device Token` sendiri. Backend selalu memvalidasi token ini sehingga tidak ada kemungkinan data salah unit.
2. **Staff Bisa Absen di Mana Saja**:
   Jika guru/staff dari Unit SD mengetuk kartu di mesin Unit SMP atau SMA, sistem secara cerdas mengenali profil staf tersebut dan mencatatkan presensi berdasarkan jadwal kerja unit asalnya.
3. **Pencegahan Double-Tap (Debounce)**:
   - **Sisi ESP32**: Jeda debounce 3 detik untuk mencegah scan bertubi-tubi.
   - **Sisi Server API**: Mekanisme cooldown 20 detik dan penguncian transaksi database (`SELECT ... FOR UPDATE`), sehingga jika user tap dua alat sekaligus di detik yang sama, tidak akan pernah terjadi duplikasi data.
4. **Respon Visual & Audio**:
   - **LED Hijau 2x Kedip + Beep 1x**: Absen berhasil diterima (Tepat Waktu / Hadir / Pulang / Lembur).
   - **LED Hijau Menyala Tenang 1 Detik + Beep 1x**: Cooldown (baru saja tap, tidak perlu tap ulang).
   - **LED Merah 3x Kedip + Beep 2x Panjang**: Kartu/Sidik jari belum terdaftar atau jadwal ditutup.
   - **LED Merah Menyala Terus**: Koneksi WiFi terputus (ESP32 akan mencoba auto-reconnect).

---

## 💡 Cara Menghubungkan Kartu RFID / Sidik Jari ke Siswa atau Guru

1. Buka menu **Master Data -> Siswa** atau **Staff / Guru** di dashboard web.
2. Klik tombol **Bulk Update (Google Admin)**.
3. Kolom **RFID Card UID** dan **Fingerprint ID** dapat langsung diketikkan, atau di-tap menggunakan reader USB.
4. Anda juga bisa mengunduh file CSV dengan menekan tombol **Download CSV Pre-filled**, mengisinya di Microsoft Excel, lalu mengunggahnya kembali dengan **Upload Perubahan CSV**.
