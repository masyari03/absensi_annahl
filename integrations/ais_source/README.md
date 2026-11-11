# 📡 Integrasi API AIS Source (Data Hub Performa Akademik)

Direktori ini memuat sumber daya dan referensi teknis untuk menghubungkan aplikasi **Absensi & Portal Sekolah** dengan data performa akademik dari sistem **AIS (An-Nahl Information System)** pada database `adanicoi_lms_annahl`.

---

## 🏗️ Arsitektur Integrasi (Public Cloudflare Tunnel & Tailscale Fallback)

```text
[ Server Absensi Publik (ais-absensi.ametriyadhi.com) ]
          │
          │ 1. GET https://ais.codika.my.id/V1/performance/academic-averages
          │    (Cloudflare Tunnel Publik - Tanpa Perlu Tailscale)
          │    Header: X-AIS-KEY: <secret_key>
          ▼
[ Cloudflare Zero Trust Edge Network ]
          │
          │ Enkripsi Tunnel Terproteksi
          ▼
[ Laptop Sekolah: Docker ais-cloudflared ]
          │
          │ Reverse Proxy Internal (Port 8068 / 8069)
          ▼
[ Docker ais-php:8.2 (Laravel API) ]
          │
          │ Query SQL (JSON_TABLE ekstensi PAS / UAS)
          ▼
[ MySQL adanicoi_lms_annahl ] (ams_nilai_raport)
```

> [!NOTE]
> Sistem dilengkapi **Smart Dual-Fallback**:
> - **Prioritas 1 (Publik):** `https://ais.codika.my.id/V1/performance/academic-averages` (Dapat diakses oleh server mana pun tanpa Tailscale).
> - **Prioritas 2 (Internal):** `http://100.126.179.54:8069/V1/performance/academic-averages` (Fallback jika berada di dalam VPN Tailscale).
> - **Prioritas 3 (Offline):** Smart local cache di `storage/cache/` jika laptop sekolah mati.

---

## 🔑 Konfigurasi Environment (`config/config.php`)

```php
// AIS Performance API Integration
if (!defined('AIS_PERFORMANCE_API_URL')) {
    define('AIS_PERFORMANCE_API_URL', getenv('AIS_PERFORMANCE_API_URL') ?: 'https://ais.codika.my.id/V1/performance/academic-averages');
}
if (!defined('AIS_PERFORMANCE_API_FALLBACK_URL')) {
    define('AIS_PERFORMANCE_API_FALLBACK_URL', getenv('AIS_PERFORMANCE_API_FALLBACK_URL') ?: 'http://100.126.179.54:8069/V1/performance/academic-averages');
}
if (!defined('AIS_PERFORMANCE_API_KEY')) {
    define('AIS_PERFORMANCE_API_KEY', getenv('AIS_PERFORMANCE_API_KEY') ?: 'c7f99b24e6a8d8102e3b8a139fa4219b6e828453');
}
```

---

## 📦 Pemasangan di AIS Source (Laravel)

1. Salin `PerformanceApiController.php` ke:
   `annahl/API/app/Http/Controllers/Api/V1/PerformanceApiController.php`
2. Tambahkan route di `annahl/API/routes/api.php`:
   ```php
   use App\Http\Controllers\Api\V1\PerformanceApiController;

   Route::prefix('V1')->group(function () {
       Route::get('performance/academic-averages', [PerformanceApiController::class, 'getAcademicAverages']);
       Route::get('performance/tahfidz-achievement', [PerformanceApiController::class, 'getTahfidzAchievement']);
   });
   ```
3. Docker stack `ais_docker` di laptop sekolah secara otomatis mengekspos rute ini melalui Cloudflare Tunnel `ais-cloudflared` ke `https://ais.codika.my.id`.

### Endpoint Tambahan (`V1/performance/tahfidz-achievement`)
- Parameter query: `unit_id`, `tahun_ajaran_id`, dan opsional `month` (`1..12`).
- Mengembalikan:
  - Normalisasi capaian Al-Qur'an santri berdasar tangga resmi An-Nahl (**Juz 30 mundur `An-Nas → An-Naba'`**, **Juz 29 maju `Al-Mulk → Al-Mursalat`**, **Juz 1–28 maju**) beserta pembimbing halaqah & target rapor.
  - Rekap statistik pengisian jurnal (`quran_jurnal`) & input nilai (`quran_nilai`) per guru Qur'an untuk disinkronkan dengan `staff_attendances` pada aplikasi `absensi-anak`.
