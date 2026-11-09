<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceApiController extends ApiController
{
    /**
     * Token autentikasi untuk pengaksesan data performa akademik.
     * Dapat dioverride melalui environment variable PERFORMANCE_API_KEY.
     */
    protected const DEFAULT_API_KEY = 'c7f99b24e6a8d8102e3b8a139fa4219b6e828453';

    /**
     * Mengambil rekap nilai rata-rata ujian akhir semester (PAS) per paralel,
     * rincian per rombel/sub-kelas, serta ranking mata pelajaran.
     */
    public function getAcademicAverages(Request $request)
    {
        // 1. Verifikasi Header X-AIS-KEY (Anti-Unauthorized Access)
        $clientKey = $request->header('X-AIS-KEY') ?? $request->query('api_key');
        $expectedKey = env('PERFORMANCE_API_KEY', self::DEFAULT_API_KEY);

        if (!$clientKey || !hash_equals((string)$expectedKey, (string)$clientKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Kunci API X-AIS-KEY tidak valid atau tidak disertakan.'
            ], 401);
        }

        try {
            // 2. Daftar Unit Pendidikan (SD, SMP, SMA, dll.)
            $units = DB::table('unit')
                ->select('id', 'nama')
                ->orderBy('id', 'asc')
                ->get();

            // 3. Daftar Tahun Ajaran yang memiliki data nilai
            $academicYears = DB::table('tahun_ajaran as ta')
                ->join('ams_nilai_raport as anr', 'anr.tahun_ajaran_id', '=', 'ta.id')
                ->select('ta.id', 'ta.nama', 'ta.semester', DB::raw('COUNT(anr.id) as total_nilai'))
                ->groupBy('ta.id', 'ta.nama', 'ta.semester')
                ->orderBy('ta.id', 'desc')
                ->get();

            if ($academicYears->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ditemukan data nilai pada database.',
                    'meta' => ['units' => $units, 'academic_years' => []],
                    'data' => []
                ]);
            }

            // 4. Parameter input atau default
            $unitId = (int)$request->input('unit_id', 2); // Default Unit 2: SD
            $tahunAjaranId = (int)$request->input('tahun_ajaran_id', $academicYears->first()->id);

            // Validasi unit terpilih
            $selectedUnit = $units->firstWhere('id', $unitId) ?? $units->first();
            $unitId = $selectedUnit ? $selectedUnit->id : 2;

            // Validasi tahun ajaran terpilih
            $selectedTahunAjaran = $academicYears->firstWhere('id', $tahunAjaranId) ?? $academicYears->first();
            $tahunAjaranId = $selectedTahunAjaran ? $selectedTahunAjaran->id : $academicYears->first()->id;

            // 5. Query Agregasi Paralel (Kelas 1 - 6)
            $paralels = DB::select("
                SELECT 
                    ul.id as paralel_id,
                    ul.nama as paralel_nama,
                    ROUND(AVG(jt.val), 2) as rata_rata_paralel,
                    COUNT(DISTINCT alss.siswa_id) as total_siswa,
                    COUNT(jt.val) as total_entri
                FROM ams_nilai_raport anr
                JOIN ams_level_sub_siswa alss ON alss.id = anr.level_sub_siswa_id
                JOIN unit_level_sub uls ON uls.id = alss.unit_level_sub_id
                JOIN unit_level ul ON ul.id = uls.unit_level_id,
                JSON_TABLE(anr.component_nilai, '$[*]' COLUMNS (
                    id_comp VARCHAR(50) PATH '$.id',
                    val DOUBLE PATH '$.value'
                )) as jt
                WHERE ul.unit_id = ? 
                  AND anr.tahun_ajaran_id = ?
                  AND jt.id_comp = 'pas'
                  AND jt.val IS NOT NULL
                GROUP BY ul.id, ul.nama
                ORDER BY ul.id ASC
            ", [$unitId, $tahunAjaranId]);

            // 6. Query Rincian Sub-Kelas / Rombel (1A, 1B, 1C, dll.)
            $subKelasRaw = DB::select("
                SELECT 
                    ul.id as paralel_id,
                    uls.id as sub_kelas_id,
                    uls.nama as sub_kelas_nama,
                    ROUND(AVG(jt.val), 2) as rata_rata_sub,
                    COUNT(DISTINCT alss.siswa_id) as total_siswa,
                    COUNT(jt.val) as total_entri
                FROM ams_nilai_raport anr
                JOIN ams_level_sub_siswa alss ON alss.id = anr.level_sub_siswa_id
                JOIN unit_level_sub uls ON uls.id = alss.unit_level_sub_id
                JOIN unit_level ul ON ul.id = uls.unit_level_id,
                JSON_TABLE(anr.component_nilai, '$[*]' COLUMNS (
                    id_comp VARCHAR(50) PATH '$.id',
                    val DOUBLE PATH '$.value'
                )) as jt
                WHERE ul.unit_id = ? 
                  AND anr.tahun_ajaran_id = ?
                  AND jt.id_comp = 'pas'
                  AND jt.val IS NOT NULL
                GROUP BY ul.id, uls.id, uls.nama
                ORDER BY ul.id ASC, rata_rata_sub DESC
            ", [$unitId, $tahunAjaranId]);

            // 7. Query Ranking Mata Pelajaran Per Paralel (dari nilai tertinggi ke terendah)
            $mapelRankingsRaw = DB::select("
                SELECT 
                    ul.id as paralel_id,
                    mp.id as mapel_id,
                    mp.nama as mapel_nama,
                    ROUND(AVG(jt.val), 2) as rata_rata_mapel,
                    COUNT(jt.val) as total_entri
                FROM ams_nilai_raport anr
                JOIN ams_level_sub_siswa alss ON alss.id = anr.level_sub_siswa_id
                JOIN unit_level_sub uls ON uls.id = alss.unit_level_sub_id
                JOIN unit_level ul ON ul.id = uls.unit_level_id
                JOIN mata_pelajaran mp ON mp.id = anr.mata_pelajaran_id,
                JSON_TABLE(anr.component_nilai, '$[*]' COLUMNS (
                    id_comp VARCHAR(50) PATH '$.id',
                    val DOUBLE PATH '$.value'
                )) as jt
                WHERE ul.unit_id = ? 
                  AND anr.tahun_ajaran_id = ?
                  AND jt.id_comp = 'pas'
                  AND jt.val IS NOT NULL
                GROUP BY ul.id, mp.id, mp.nama
                ORDER BY ul.id ASC, rata_rata_mapel DESC
            ", [$unitId, $tahunAjaranId]);

            // 8. Strukturisasi Data Terpadu
            $groupedData = [];
            foreach ($paralels as $p) {
                $pId = $p->paralel_id;

                $subs = array_values(array_filter($subKelasRaw, function ($s) use ($pId) {
                    return $s->paralel_id == $pId;
                }));

                $ranks = array_values(array_filter($mapelRankingsRaw, function ($m) use ($pId) {
                    return $m->paralel_id == $pId;
                }));

                // Format data bersih dan aman
                $groupedData[] = [
                    'paralel_id' => (int)$p->paralel_id,
                    'paralel_nama' => (string)$p->paralel_nama,
                    'rata_rata_paralel' => (float)$p->rata_rata_paralel,
                    'total_siswa' => (int)$p->total_siswa,
                    'total_entri' => (int)$p->total_entri,
                    'sub_kelas' => array_map(function ($s) {
                        return [
                            'id' => (int)$s->sub_kelas_id,
                            'nama' => (string)$s->sub_kelas_nama,
                            'rata_rata' => (float)$s->rata_rata_sub,
                            'total_siswa' => (int)$s->total_siswa,
                        ];
                    }, $subs),
                    'mapel_rankings' => array_map(function ($m, $index) {
                        return [
                            'rank' => $index + 1,
                            'mapel_id' => (int)$m->mapel_id,
                            'mapel_nama' => (string)$m->mapel_nama,
                            'rata_rata' => (float)$m->rata_rata_mapel,
                            'total_entri' => (int)$m->total_entri,
                        ];
                    }, $ranks, array_keys($ranks)),
                ];
            }

            return response()->json([
                'success' => true,
                'meta' => [
                    'selected_unit' => $selectedUnit,
                    'selected_tahun_ajaran' => $selectedTahunAjaran,
                    'units' => $units,
                    'academic_years' => $academicYears,
                    'server_time' => now()->toIso8601String(),
                ],
                'data' => $groupedData,
            ]);

        } catch (\Throwable $e) {
            Log::error('PerformanceApiController Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses data performa akademik: ' . $e->getMessage()
            ], 500);
        }
    }
}

