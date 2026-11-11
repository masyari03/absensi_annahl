<?php

/**
 * Route snippet untuk ditambahkan ke file routes/api.php pada aplikasi ais_source (Laravel):
 * Path: annahl/API/routes/api.php
 */

use App\Http\Controllers\Api\V1\PerformanceApiController;

Route::prefix('V1')->group(function () {
    // API Penilaian Kinerja Akademik (Read-Only)
    Route::get('performance/academic-averages', [PerformanceApiController::class, 'getAcademicAverages']);

    // API Penilaian Kinerja Tahfidz & Jurnal Guru Qur'an (Read-Only)
    Route::get('performance/tahfidz-achievement', [PerformanceApiController::class, 'getTahfidzAchievement']);
});
