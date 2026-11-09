# 📡 Integrasi API AIS Source (Data Hub Performa Akademik)

Direktori ini memuat sumber daya dan referensi teknis untuk menghubungkan aplikasi **Absensi & Portal Sekolah** dengan data performa akademik dari sistem **AIS (An-Nahl Information System)** pada database `adanicoi_lms_annahl`.

---

## 🏗️ Arsitektur Integrasi

```text
[ Server Absensi (absensi-anak) ]
          │
          │ GET /V1/performance/academic-averages
          │ Header: X-AIS-KEY: <secret_key>
          ▼
[ Laptop Sekolah / AIS Source: 100.126.179.54:8069 ] (Docker / ais-php)
          │
          │ Query SQL (JSON_TABLE ekstensi PAS / UAS)
          ▼
[ MySQL adanicoi_lms_annahl ] (ams_nilai_raport)
```

---

## 🔑 Konfigurasi Environment (`config/config.php`)

```php
// AIS Performance API Integration
if (!defined('AIS_PERFORMANCE_API_URL')) {
    define('AIS_PERFORMANCE_API_URL', getenv('AIS_PERFORMANCE_API_URL') ?: 'http://100.126.179.54:8069/V1/performance/academic-averages');
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
   });
   ```
3. Pastikan port Docker Laravel terekspos ke Tailscale IP sekolah (`100.126.179.54:8069:8069`).

