<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceApiController extends ApiController
{
    /**
     * Token autentikasi untuk pengaksesan data performa akademik & tahfidz.
     * Dapat dioverride melalui environment variable PERFORMANCE_API_KEY.
     */
    protected const DEFAULT_API_KEY = 'c7f99b24e6a8d8102e3b8a139fa4219b6e828453';

    /**
     * Verifikasi Header X-AIS-KEY secara aman (timing-safe).
     */
    protected function authorizeApiKey(Request $request)
    {
        $clientKey = $request->header('X-AIS-KEY') ?? $request->query('api_key');
        $expectedKey = env('PERFORMANCE_API_KEY', self::DEFAULT_API_KEY);

        if (!$clientKey || !hash_equals((string)$expectedKey, (string)$clientKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Kunci API X-AIS-KEY tidak valid atau tidak disertakan.'
            ], 401);
        }

        return null;
    }

    /**
     * Mengambil rekap nilai rata-rata ujian akhir semester (PAS) per paralel,
     * rincian per rombel/sub-kelas, serta ranking mata pelajaran.
     */
    public function getAcademicAverages(Request $request)
    {
        if ($authError = $this->authorizeApiKey($request)) {
            return $authError;
        }

        try {
            // 1. Daftar Unit Pendidikan (SD, SMP, SMA, dll.)
            $units = DB::table('unit')
                ->select('id', 'nama')
                ->orderBy('id', 'asc')
                ->get();

            // 2. Daftar Tahun Ajaran yang memiliki data nilai
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

            // 3. Parameter input atau default
            $unitId = (int)$request->input('unit_id', 2); // Default Unit 2: SD
            $tahunAjaranId = (int)$request->input('tahun_ajaran_id', $academicYears->first()->id);

            $selectedUnit = $units->firstWhere('id', $unitId) ?? $units->first();
            $unitId = $selectedUnit ? $selectedUnit->id : 2;

            $selectedTahunAjaran = $academicYears->firstWhere('id', $tahunAjaranId) ?? $academicYears->first();
            $tahunAjaranId = $selectedTahunAjaran ? $selectedTahunAjaran->id : $academicYears->first()->id;

            // 4. Query Agregasi Paralel (Kelas 1 - 6)
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

            // 5. Query Rincian Sub-Kelas / Rombel (1A, 1B, 1C, dll.)
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

            // 6. Query Ranking Mata Pelajaran Per Paralel (dari nilai tertinggi ke terendah)
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

            // 7. Strukturisasi Data Terpadu
            $groupedData = [];
            foreach ($paralels as $p) {
                $pId = $p->paralel_id;

                $subs = array_values(array_filter($subKelasRaw, function ($s) use ($pId) {
                    return $s->paralel_id == $pId;
                }));

                $ranks = array_values(array_filter($mapelRankingsRaw, function ($m) use ($pId) {
                    return $m->paralel_id == $pId;
                }));

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
            Log::error('PerformanceApiController Error (AcademicAverages): ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses data performa akademik.'
            ], 500);
        }
    }

    /**
     * Katalog Resmi Urutan Surat & Tangga Kurikulum Tahfidz An-Nahl:
     * - Juz 30 (Rank 1..37): Belakang ke Depan (An-Nas -> An-Naba')
     * - Juz 29 (Rank 38..48): Depan ke Belakang (Al-Mulk -> Al-Mursalat)
     * - Juz 28 (Rank 49..57): Depan ke Belakang (Al-Mujadilah -> At-Tahrim)
     * - Juz 1..27 (Rank 58+ / Stage 4..30): Depan ke Belakang (Juz 1 -> Juz 2 -> ... -> Juz 27)
     */
    protected function getTahfidzCatalog(): array
    {
        $juz30Surahs = [
            'alfatihah'     => ['rank' => 0,  'ayats' => 7,  'label' => 'Al-Fatihah'],
            'annas'         => ['rank' => 1,  'ayats' => 6,  'label' => 'An-Nas'],
            'alfalaq'       => ['rank' => 2,  'ayats' => 5,  'label' => 'Al-Falaq'],
            'alikhlas'      => ['rank' => 3,  'ayats' => 4,  'label' => 'Al-Ikhlas'],
            'allahab'       => ['rank' => 4,  'ayats' => 5,  'label' => 'Al-Lahab'],
            'annashr'       => ['rank' => 5,  'ayats' => 3,  'label' => 'An-Nashr'],
            'alkafirun'     => ['rank' => 6,  'ayats' => 6,  'label' => 'Al-Kafirun'],
            'alkautsar'     => ['rank' => 7,  'ayats' => 3,  'label' => 'Al-Kautsar'],
            'almaun'        => ['rank' => 8,  'ayats' => 7,  'label' => "Al-Ma'un"],
            'quraisy'       => ['rank' => 9,  'ayats' => 4,  'label' => 'Quraisy'],
            'alfil'         => ['rank' => 10, 'ayats' => 5,  'label' => 'Al-Fil'],
            'alhumazah'     => ['rank' => 11, 'ayats' => 9,  'label' => 'Al-Humazah'],
            'alashr'        => ['rank' => 12, 'ayats' => 3,  'label' => "Al-'Ashr"],
            'attakatsur'    => ['rank' => 13, 'ayats' => 8,  'label' => 'At-Takatsur'],
            'alqariah'      => ['rank' => 14, 'ayats' => 11, 'label' => "Al-Qari'ah"],
            'aladiyat'      => ['rank' => 15, 'ayats' => 11, 'label' => "Al-'Adiyat"],
            'azzalzalah'    => ['rank' => 16, 'ayats' => 8,  'label' => 'Az-Zalzalah'],
            'albayyinah'    => ['rank' => 17, 'ayats' => 8,  'label' => 'Al-Bayyinah'],
            'alqadr'        => ['rank' => 18, 'ayats' => 5,  'label' => 'Al-Qadr'],
            'alalaq'        => ['rank' => 19, 'ayats' => 19, 'label' => "Al-'Alaq"],
            'attin'         => ['rank' => 20, 'ayats' => 8,  'label' => 'At-Tin'],
            'alinsyirah'    => ['rank' => 21, 'ayats' => 8,  'label' => 'Al-Insyirah'],
            'adhdhuha'      => ['rank' => 22, 'ayats' => 11, 'label' => 'Adh-Dhuha'],
            'allail'        => ['rank' => 23, 'ayats' => 21, 'label' => 'Al-Lail'],
            'asysyams'      => ['rank' => 24, 'ayats' => 15, 'label' => 'Asy-Syams'],
            'albalad'       => ['rank' => 25, 'ayats' => 20, 'label' => 'Al-Balad'],
            'alfajr'        => ['rank' => 26, 'ayats' => 30, 'label' => 'Al-Fajr'],
            'alghasyiyah'   => ['rank' => 27, 'ayats' => 26, 'label' => 'Al-Ghasyiyah'],
            'alalaa'        => ['rank' => 28, 'ayats' => 19, 'label' => "Al-A'laa"],
            'atthariq'      => ['rank' => 29, 'ayats' => 17, 'label' => 'At-Thariq'],
            'alburuj'       => ['rank' => 30, 'ayats' => 22, 'label' => 'Al-Buruj'],
            'alinsyiqaq'    => ['rank' => 31, 'ayats' => 25, 'label' => 'Al-Insyiqaq'],
            'almuthaffifin' => ['rank' => 32, 'ayats' => 36, 'label' => 'Al-Muthaffifin'],
            'alinfithar'    => ['rank' => 33, 'ayats' => 19, 'label' => 'Al-Infithar'],
            'attakwir'      => ['rank' => 34, 'ayats' => 29, 'label' => 'At-Takwir'],
            'abasa'         => ['rank' => 35, 'ayats' => 42, 'label' => "'Abasa"],
            'annaziat'      => ['rank' => 36, 'ayats' => 46, 'label' => "An-Nazi'at"],
            'annaba'        => ['rank' => 37, 'ayats' => 40, 'label' => "An-Naba'"],
        ];

        // Juz 29 Urut Depan -> Belakang (Al-Mulk -> Al-Mursalat)
        $juz29Surahs = [
            'almulk'        => ['rank' => 38, 'ayats' => 30, 'label' => 'Al-Mulk'],
            'alqalam'       => ['rank' => 39, 'ayats' => 52, 'label' => 'Al-Qalam'],
            'alhaqqah'      => ['rank' => 40, 'ayats' => 52, 'label' => 'Al-Haqqah'],
            'almaarij'      => ['rank' => 41, 'ayats' => 44, 'label' => "Al-Ma'arij"],
            'nuh'           => ['rank' => 42, 'ayats' => 28, 'label' => 'Nuh'],
            'aljin'         => ['rank' => 43, 'ayats' => 28, 'label' => 'Al-Jin'],
            'almuzzammil'   => ['rank' => 44, 'ayats' => 20, 'label' => 'Al-Muzzammil'],
            'almuddatstsir' => ['rank' => 45, 'ayats' => 56, 'label' => 'Al-Muddatstsir'],
            'alqiyamah'     => ['rank' => 46, 'ayats' => 40, 'label' => 'Al-Qiyamah'],
            'alinsan'       => ['rank' => 47, 'ayats' => 31, 'label' => 'Al-Insan'],
            'almursalat'    => ['rank' => 48, 'ayats' => 50, 'label' => 'Al-Mursalat'],
        ];

        // Juz 28 Urut Depan -> Belakang (Al-Mujadilah -> At-Tahrim)
        $juz28Surahs = [
            'almujadilah'   => ['rank' => 49, 'ayats' => 22, 'label' => 'Al-Mujadilah'],
            'alhasyr'       => ['rank' => 50, 'ayats' => 24, 'label' => 'Al-Hasyr'],
            'almumtahanah'  => ['rank' => 51, 'ayats' => 13, 'label' => 'Al-Mumtahanah'],
            'assaff'        => ['rank' => 52, 'ayats' => 14, 'label' => 'As-Saff'],
            'aljumuah'      => ['rank' => 53, 'ayats' => 11, 'label' => "Al-Jumu'ah"],
            'almunafiqun'   => ['rank' => 54, 'ayats' => 11, 'label' => 'Al-Munafiqun'],
            'attaghabun'    => ['rank' => 55, 'ayats' => 18, 'label' => 'At-Taghabun'],
            'attalaq'       => ['rank' => 56, 'ayats' => 12, 'label' => 'At-Talaq'],
            'attahrim'      => ['rank' => 57, 'ayats' => 12, 'label' => 'At-Tahrim'],
        ];

        $aliases = [
            'alfatihah' => 'alfatihah', 'fatihah' => 'alfatihah',
            'annas' => 'annas', 'annaas' => 'annas', 'nas' => 'annas', 'naas' => 'annas',
            'alfalaq' => 'alfalaq', 'falaq' => 'alfalaq',
            'alikhlas' => 'alikhlas', 'alikhlash' => 'alikhlas', 'ikhlas' => 'alikhlas', 'ikhlash' => 'alikhlas',
            'allahab' => 'allahab', 'lahab' => 'allahab', 'almasad' => 'allahab',
            'annashr' => 'annashr', 'annasr' => 'annashr', 'nasr' => 'annashr', 'nashr' => 'annashr',
            'alkafirun' => 'alkafirun', 'alkaafiruun' => 'alkafirun', 'alkaafirun' => 'alkafirun', 'kafirun' => 'alkafirun',
            'alkautsar' => 'alkautsar', 'alkausar' => 'alkautsar', 'kautsar' => 'alkautsar',
            'almaun' => 'almaun', 'almaauun' => 'almaun', 'maun' => 'almaun',
            'quraisy' => 'quraisy', 'quroisy' => 'quraisy', 'quraysh' => 'quraisy', 'quraish' => 'quraisy',
            'alfil' => 'alfil', 'alfiil' => 'alfil', 'alfill' => 'alfil', 'fil' => 'alfil', 'fiil' => 'alfil',
            'alhumazah' => 'alhumazah', 'humazah' => 'alhumazah',
            'alashr' => 'alashr', 'alasr' => 'alashr', 'ashr' => 'alashr',
            'attakatsur' => 'attakatsur', 'attakasur' => 'attakatsur', 'attakaatsur' => 'attakatsur', 'attakaastur' => 'attakatsur', 'takatsur' => 'attakatsur',
            'alqariah' => 'alqariah', 'alqoriah' => 'alqariah', 'qariah' => 'alqariah',
            'aladiyat' => 'aladiyat', 'adiyat' => 'aladiyat',
            'azzalzalah' => 'azzalzalah', 'alzalzalah' => 'azzalzalah', 'zalzalah' => 'azzalzalah',
            'albayyinah' => 'albayyinah', 'bayyinah' => 'albayyinah',
            'alqadr' => 'alqadr', 'alqodr' => 'alqadr', 'qadr' => 'alqadr',
            'alalaq' => 'alalaq', 'alaq' => 'alalaq',
            'attin' => 'attin', 'tin' => 'attin',
            'alinsyirah' => 'alinsyirah', 'alinsyiroh' => 'alinsyirah', 'insyirah' => 'alinsyirah', 'asyah' => 'alinsyirah',
            'adhdhuha' => 'adhdhuha', 'addhuha' => 'adhdhuha', 'adduha' => 'adhdhuha', 'dhuha' => 'adhdhuha',
            'allail' => 'allail', 'lail' => 'allail',
            'asysyams' => 'asysyams', 'asyams' => 'asysyams', 'syams' => 'asysyams',
            'albalad' => 'albalad', 'balad' => 'albalad',
            'alfajr' => 'alfajr', 'fajr' => 'alfajr',
            'alghasyiyah' => 'alghasyiyah', 'alghosyiyah' => 'alghasyiyah', 'alghasyiyyah' => 'alghasyiyah', 'algasyiyah' => 'alghasyiyah', 'ghasyiyah' => 'alghasyiyah',
            'alalaa' => 'alalaa', 'alala' => 'alalaa', 'alaa' => 'alalaa', 'ala' => 'alalaa',
            'atthariq' => 'atthariq', 'attariq' => 'atthariq', 'atthoriq' => 'atthariq', 'thariq' => 'atthariq',
            'alburuj' => 'alburuj', 'buruj' => 'alburuj',
            'alinsyiqaq' => 'alinsyiqaq', 'alinsyiqoq' => 'alinsyiqaq', 'insyiqaq' => 'alinsyiqaq',
            'almuthaffifin' => 'almuthaffifin', 'almutaffifin' => 'almuthaffifin', 'almuthafifin' => 'almuthaffifin', 'muthaffifin' => 'almuthaffifin',
            'alinfithar' => 'alinfithar', 'alinfitar' => 'alinfithar', 'alinfithor' => 'alinfithar', 'infithar' => 'alinfithar',
            'attakwir' => 'attakwir', 'takwir' => 'attakwir',
            'abasa' => 'abasa',
            'annaziat' => 'annaziat', 'naziat' => 'annaziat',
            'annaba' => 'annaba', 'naba' => 'annaba',
            // Juz 29
            'almulk' => 'almulk', 'mulk' => 'almulk',
            'alqalam' => 'alqalam', 'alqolam' => 'alqalam', 'qalam' => 'alqalam', 'qolam' => 'alqalam',
            'alhaqqah' => 'alhaqqah', 'alhaqoh' => 'alhaqqah', 'haqqah' => 'alhaqqah',
            'almaarij' => 'almaarij', 'maarij' => 'almaarij',
            'nuh' => 'nuh', 'annuh' => 'nuh',
            'aljin' => 'aljin', 'aljinn' => 'aljin', 'jin' => 'aljin',
            'almuzzammil' => 'almuzzammil', 'almuzammil' => 'almuzzammil', 'muzzammil' => 'almuzzammil',
            'almuddatstsir' => 'almuddatstsir', 'almuddassir' => 'almuddatstsir', 'almudatsir' => 'almuddatstsir', 'almuddatsir' => 'almuddatstsir', 'muddatstsir' => 'almuddatstsir',
            'alqiyamah' => 'alqiyamah', 'qiyamah' => 'alqiyamah',
            'alinsan' => 'alinsan', 'insan' => 'alinsan',
            'almursalat' => 'almursalat', 'mursalat' => 'almursalat',
            // Juz 28
            'almujadilah' => 'almujadilah', 'almujadalah' => 'almujadilah', 'mujadilah' => 'almujadilah', 'mujadalah' => 'almujadilah',
            'alhasyr' => 'alhasyr', 'hasyr' => 'alhasyr',
            'almumtahanah' => 'almumtahanah', 'mumtahanah' => 'almumtahanah',
            'assaff' => 'assaff', 'asshaf' => 'assaff', 'ashshaf' => 'assaff', 'asshaff' => 'assaff',
            'aljumuah' => 'aljumuah', 'jumuah' => 'aljumuah',
            'almunafiqun' => 'almunafiqun', 'almunafikun' => 'almunafiqun', 'munafiqun' => 'almunafiqun',
            'attaghabun' => 'attaghabun', 'attagobun' => 'attaghabun', 'attagabun' => 'attaghabun',
            'attalaq' => 'attalaq', 'attholaq' => 'attalaq', 'attolaq' => 'attalaq',
            'attahrim' => 'attahrim', 'tahrim' => 'attahrim',
        ];

        $postJuz28Map = [
            'albaqarah' => 1, 'albaqoroh' => 1, 'aliimran' => 3, 'annisa' => 4, 'almaidah' => 6,
            'alanam' => 7, 'alaraf' => 8, 'alanfal' => 9, 'attaubah' => 10, 'yunus' => 11,
            'hud' => 11, 'yusuf' => 12, 'arrad' => 13, 'ibrahim' => 13, 'alhijr' => 14,
            'annahl' => 14, 'alisra' => 15, 'alkahf' => 15, 'alkahfi' => 15, 'maryam' => 16,
            'taha' => 16, 'alanbiya' => 17, 'alhajj' => 17, 'almuminun' => 18, 'annur' => 18,
            'alfurqan' => 18, 'asysyuara' => 19, 'annaml' => 19, 'alqasas' => 20, 'alankabut' => 20,
            'arrum' => 21, 'luqman' => 21, 'assajdah' => 21, 'alahzab' => 21, 'saba' => 22,
            'fatir' => 22, 'yasin' => 22, 'assaffat' => 23, 'sad' => 23, 'azzumar' => 23,
            'ghafir' => 24, 'fussilat' => 24, 'asysyura' => 25, 'azzukhruf' => 25, 'addukhan' => 25,
            'aljasiyah' => 25, 'alahqaf' => 26, 'muhammad' => 26, 'alfath' => 26, 'alhujurat' => 26,
            'qaf' => 26, 'azzariyat' => 26, 'adzdzariyat' => 26, 'attur' => 27, 'atthur' => 27,
            'annajm' => 27, 'alqamar' => 27, 'alqomar' => 27, 'arrahman' => 27, 'alwaqiah' => 27,
            'alhadid' => 27, 'alhadiid' => 27,
        ];

        return compact('juz30Surahs', 'juz29Surahs', 'juz28Surahs', 'aliases', 'postJuz28Map');
    }

    /**
     * Konversi nomor Juz ke skala tahapan kurikulum monoton naik:
     * Juz 30 = 1, Juz 29 = 2, Juz 1 = 3, Juz 2 = 4, ..., Juz 27 = 29, Juz 28 = 30.
     * Catatan: Di tradisi An-Nahl, siswa SMP/SMA bisa mengikuti Juz 30 -> Juz 29 -> Juz 28 -> Juz 1..27
     * maupun Juz 30 -> Juz 29 -> Juz 1..28 (depan ke belakang). Untuk stage linear resmi:
     * 30(1) -> 29(2) -> 1..28 depan ke belakang (Juz N -> 2 + N, sehingga Juz 1 = 3, Juz 2 = 4, ..., Juz 28 = 30).
     */
    protected function juzToStage(int $juz): int
    {
        if ($juz === 30) return 1;
        if ($juz === 29) return 2;
        if ($juz >= 1 && $juz <= 28) return 2 + $juz;
        return 0;
    }

    protected function normText(string $str): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($str)) ?? '';
    }

    /**
     * Ekstrak daftar canonical key surat dari sebuah teks (mis. "Qs. An-Nas - Al-Fil" atau "Surat Al-Mulk s.d. Al-Jin").
     */
    protected function extractSurahKeysFromText(string $text, array $catalog): array
    {
        $clean = str_replace(["\u{2013}", "\u{2014}", "–", "—"], '-', $text);
        $parts = preg_split('/[-,]|\bs\.?d\.?\b/i', $clean) ?: [];
        $found = [];

        foreach ($parts as $p) {
            $stripped = preg_replace('/\b(tes|qs|q\.s|surat|surah|munaqasyah|muraja.?ah|muroja.?ah|drill)\b/i', '', $p);
            $stripped = preg_replace('/(:|ayat|ay)\s*\d.*$/i', '', (string)$stripped);
            $norm = $this->normText((string)$stripped);
            if ($norm === '') {
                continue;
            }
            if (isset($catalog['aliases'][$norm])) {
                $found[] = $catalog['aliases'][$norm];
                continue;
            }
            foreach ($catalog['aliases'] as $alias => $canonical) {
                if (strlen($alias) >= 4 && str_ends_with($norm, $alias)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Parse 1 entri Tahfidz menjadi sinyal capaian terstandarisasi sesuai urutan resmi An-Nahl:
     * - Juz 30: An-Nas (1) -> An-Naba' (37)
     * - Juz 29: Al-Mulk (38) -> Al-Mursalat (48)
     * - Juz 28: Al-Mujadilah (49) -> At-Tahrim (57)
     * - Juz 1..27: Depan ke belakang (Stage 3..29)
     */
    protected function parseTahfidzEntry(string $cpNama, string $kompetensi, array $catalog, bool $allowWholeJuzExpansion = true): array
    {
        $res = [
            'juz30_rank'   => -1.0,
            'juz29_rank'   => -1.0,
            'juz28_rank'   => -1.0,
            'juz29_keys'   => [],
            'juz28_keys'   => [],
            'juz_stage'    => 0,
            'munaq_juz30'  => false,
            'munaq_juz29'  => false,
            'munaq_juz28'  => false,
            'label'        => '',
        ];

        $cp = trim(str_replace('\\/', '/', $cpNama));
        $komp = trim($kompetensi);
        $ncp = $this->normText($cp);
        $kompLower = strtolower($komp);

        if ($ncp === '' && $komp !== '') {
            $fromKomp = $this->extractSurahKeysFromText($komp, $catalog);
            if (!empty($fromKomp)) {
                $ncp = (string)end($fromKomp);
                $cp  = $ncp;
            }
        }

        if ($ncp === '') {
            return $res;
        }

        $calcAyatFraction = function (int $totalAyats) use ($komp): float {
            if ($totalAyats <= 0) {
                return 1.0;
            }
            if (preg_match('/(?:ayat|ay|:)\s*\d*\s*[-–]?\s*(\d+)/i', $komp, $m)) {
                $endAy = (int)$m[1];
                return min(1.0, max(0.05, $endAy / $totalAyats));
            }
            if (preg_match('/^\s*(\d+)\s*[-–]\s*(\d+)\s*$/', $komp, $m)) {
                $endAy = (int)$m[2];
                return min(1.0, max(0.05, $endAy / $totalAyats));
            }
            return 1.0;
        };

        // A. Surat langsung di Juz 30, 29, atau 28
        if (isset($catalog['aliases'][$ncp])) {
            $canon = $catalog['aliases'][$ncp];

            if (isset($catalog['juz30Surahs'][$canon])) {
                $info = $catalog['juz30Surahs'][$canon];
                if ($info['rank'] === 0) {
                    $res['juz30_rank'] = 0.5;
                } else {
                    $frac = $calcAyatFraction($info['ayats']);
                    $res['juz30_rank'] = round(($info['rank'] - 1) + $frac, 3);
                }
                $res['juz_stage'] = max($res['juz_stage'], 1);
                $res['label'] = 'QS. ' . $info['label'] . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if (isset($catalog['juz29Surahs'][$canon])) {
                $info = $catalog['juz29Surahs'][$canon];
                $frac = $calcAyatFraction($info['ayats']);
                $res['juz30_rank'] = 37.0;
                $res['juz29_rank'] = round(($info['rank'] - 1) + $frac, 3);
                $res['juz29_keys'][] = $canon;
                $res['juz_stage'] = max($res['juz_stage'], 2);
                $res['label'] = 'QS. ' . $info['label'] . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if (isset($catalog['juz28Surahs'][$canon])) {
                $info = $catalog['juz28Surahs'][$canon];
                $frac = $calcAyatFraction($info['ayats']);
                $res['juz30_rank'] = 37.0;
                $res['juz29_rank'] = 48.0;
                $res['juz29_keys'] = array_keys($catalog['juz29Surahs']);
                $res['juz28_rank'] = round(($info['rank'] - 1) + $frac, 3);
                $res['juz28_keys'][] = $canon;
                $res['juz_stage'] = max($res['juz_stage'], $this->juzToStage(28));
                $res['label'] = 'QS. ' . $info['label'] . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }
        }

        // B. Surat pada Juz 1..27 (Depan ke Belakang)
        foreach ($catalog['postJuz28Map'] as $sKey => $juzNum) {
            if (str_contains($ncp, $sKey)) {
                $res['juz30_rank'] = 37.0;
                $res['juz29_rank'] = 48.0;
                $res['juz29_keys'] = array_keys($catalog['juz29Surahs']);
                $res['juz_stage'] = max($res['juz_stage'], $this->juzToStage($juzNum));
                $res['munaq_juz30'] = true;
                $res['munaq_juz29'] = true;
                $res['label'] = $cp . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }
        }

        // C. Kode Blok H1/30..H4/30, H1/29..H4/29, H*/1..28
        if (preg_match('/^h(\d+)\/(\d+)$/i', $cp, $mh)) {
            $part = (int)$mh[1];
            $juz = (int)$mh[2];

            if ($juz === 30) {
                $res['juz_stage'] = max($res['juz_stage'], 1);
                $extracted = $this->extractSurahKeysFromText($komp, $catalog);
                if (!empty($extracted)) {
                    $maxRk = -1.0;
                    foreach ($extracted as $ek) {
                        if (isset($catalog['juz30Surahs'][$ek])) {
                            $maxRk = max($maxRk, (float)$catalog['juz30Surahs'][$ek]['rank']);
                        }
                    }
                    if ($maxRk >= 0) {
                        $res['juz30_rank'] = $maxRk;
                        $res['label'] = "{$cp} ({$komp})";
                        return $res;
                    }
                }
                $res['juz30_rank'] = [1 => 16.0, 2 => 27.0, 3 => 32.0, 4 => 37.0][$part] ?? 16.0;
                $res['label'] = "{$cp}" . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if ($juz === 29) {
                $res['juz30_rank'] = 37.0;
                $res['juz_stage'] = max($res['juz_stage'], 2);
                $extracted = $this->extractSurahKeysFromText($komp, $catalog);
                $blockSets = [
                    1 => ['almulk', 'alqalam'],
                    2 => ['alhaqqah', 'almaarij', 'nuh'],
                    3 => ['aljin', 'almuzzammil', 'almuddatstsir'],
                    4 => ['alqiyamah', 'alinsan', 'almursalat'],
                ];
                $keys = array_values(array_filter($extracted, fn($k) => isset($catalog['juz29Surahs'][$k])));
                if (empty($keys)) {
                    $keys = $blockSets[$part] ?? ['almulk', 'alqalam'];
                } elseif (count($keys) >= 2 && isset($blockSets[$part])) {
                    $keys = array_values(array_unique(array_merge($keys, $blockSets[$part])));
                }
                $max29 = -1.0;
                foreach ($keys as $k) {
                    if (isset($catalog['juz29Surahs'][$k])) {
                        $max29 = max($max29, (float)$catalog['juz29Surahs'][$k]['rank']);
                    }
                }
                $res['juz29_rank'] = $max29 >= 38.0 ? $max29 : ([1 => 39.0, 2 => 42.0, 3 => 45.0, 4 => 48.0][$part] ?? 39.0);
                $res['juz29_keys'] = $keys;
                $res['label'] = "{$cp}" . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if ($juz >= 1 && $juz <= 28) {
                $res['juz30_rank'] = 37.0;
                $res['munaq_juz30'] = true;
                $res['juz29_rank'] = 48.0;
                $res['juz29_keys'] = array_keys($catalog['juz29Surahs']);
                $res['munaq_juz29'] = true;
                if ($juz === 28) {
                    $res['juz28_rank'] = [1 => 50.0, 2 => 52.0, 3 => 55.0, 4 => 57.0][$part] ?? 50.0;
                }
                $res['juz_stage'] = max($res['juz_stage'], $this->juzToStage($juz));
                $res['label'] = "{$cp}" . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }
        }

        // D. Entri Level Juz ("Juz 30", "Juz 29", "Juz 28", "Juz 1"..)
        if (preg_match('/^juz\s*(\d+)$/i', $cp, $mj)) {
            $juz = (int)$mj[1];
            $isMunaq = str_contains($kompLower, 'munaq') || str_contains($kompLower, 'kenaikan') || str_contains($kompLower, 'lulus');

            if ($juz === 30) {
                $res['juz_stage'] = max($res['juz_stage'], 1);
                $extracted = $this->extractSurahKeysFromText($komp, $catalog);
                if (!empty($extracted) && !$isMunaq && !str_contains($kompLower, 'muroja') && !str_contains($kompLower, 'muraja')) {
                    $maxRk = -1.0;
                    foreach ($extracted as $ek) {
                        if (isset($catalog['juz30Surahs'][$ek])) {
                            $maxRk = max($maxRk, (float)$catalog['juz30Surahs'][$ek]['rank']);
                        }
                    }
                    if ($maxRk >= 0) {
                        $res['juz30_rank'] = $maxRk;
                        $res['label'] = "Juz 30 ({$komp})";
                        return $res;
                    }
                }
                if ($allowWholeJuzExpansion || $isMunaq) {
                    $res['juz30_rank'] = 37.0;
                    if ($isMunaq) {
                        $res['munaq_juz30'] = true;
                    }
                }
                $res['label'] = 'Juz 30' . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if ($juz === 29) {
                $res['juz30_rank'] = 37.0;
                $res['juz_stage'] = max($res['juz_stage'], 2);
                $extracted = $this->extractSurahKeysFromText($komp, $catalog);
                $j29Extracted = array_values(array_filter($extracted, fn($k) => isset($catalog['juz29Surahs'][$k])));

                if (!empty($j29Extracted) && !$isMunaq && !str_contains($kompLower, 'muroja') && !str_contains($kompLower, 'muraja')) {
                    if (count($j29Extracted) >= 2 && (str_contains($kompLower, 's.d') || str_contains($kompLower, 'sd') || str_contains($komp, '-'))) {
                        $r1 = $catalog['juz29Surahs'][$j29Extracted[0]]['rank'];
                        $r2 = $catalog['juz29Surahs'][$j29Extracted[count($j29Extracted) - 1]]['rank'];
                        $minR = min($r1, $r2);
                        $maxR = max($r1, $r2);
                        foreach ($catalog['juz29Surahs'] as $k => $info) {
                            if ($info['rank'] >= $minR && $info['rank'] <= $maxR) {
                                $res['juz29_keys'][] = $k;
                            }
                        }
                        $res['juz29_rank'] = (float)$maxR;
                    } else {
                        $res['juz29_keys'] = $j29Extracted;
                        $lastK = end($j29Extracted);
                        $infoLast = $catalog['juz29Surahs'][$lastK];
                        $frac = $calcAyatFraction($infoLast['ayats']);
                        $res['juz29_rank'] = round(($infoLast['rank'] - 1) + $frac, 3);
                    }
                } elseif ($allowWholeJuzExpansion || $isMunaq) {
                    $res['juz29_keys'] = array_keys($catalog['juz29Surahs']);
                    $res['juz29_rank'] = 48.0;
                    if ($isMunaq) {
                        $res['munaq_juz29'] = true;
                    }
                }
                $res['label'] = 'Juz 29' . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if ($juz === 28) {
                $res['juz30_rank'] = 37.0;
                $res['juz29_rank'] = 48.0;
                $res['juz29_keys'] = array_keys($catalog['juz29Surahs']);
                $res['munaq_juz29'] = true;
                $res['juz_stage'] = max($res['juz_stage'], $this->juzToStage(28));
                $extracted = $this->extractSurahKeysFromText($komp, $catalog);
                $j28Extracted = array_values(array_filter($extracted, fn($k) => isset($catalog['juz28Surahs'][$k])));

                if (!empty($j28Extracted) && !$isMunaq) {
                    if (count($j28Extracted) >= 2 && (str_contains($kompLower, 's.d') || str_contains($kompLower, 'sd') || str_contains($komp, '-'))) {
                        $r1 = $catalog['juz28Surahs'][$j28Extracted[0]]['rank'];
                        $r2 = $catalog['juz28Surahs'][$j28Extracted[count($j28Extracted) - 1]]['rank'];
                        $minR = min($r1, $r2);
                        $maxR = max($r1, $r2);
                        foreach ($catalog['juz28Surahs'] as $k => $info) {
                            if ($info['rank'] >= $minR && $info['rank'] <= $maxR) {
                                $res['juz28_keys'][] = $k;
                            }
                        }
                        $res['juz28_rank'] = (float)$maxR;
                    } else {
                        $res['juz28_keys'] = $j28Extracted;
                        $lastK = end($j28Extracted);
                        $infoLast = $catalog['juz28Surahs'][$lastK];
                        $frac = $calcAyatFraction($infoLast['ayats']);
                        $res['juz28_rank'] = round(($infoLast['rank'] - 1) + $frac, 3);
                    }
                } elseif ($allowWholeJuzExpansion || $isMunaq) {
                    $res['juz28_keys'] = array_keys($catalog['juz28Surahs']);
                    $res['juz28_rank'] = 57.0;
                    if ($isMunaq) {
                        $res['munaq_juz28'] = true;
                    }
                }
                $res['label'] = 'Juz 28' . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }

            if ($juz >= 1 && $juz <= 27) {
                $res['juz30_rank'] = 37.0;
                $res['munaq_juz30'] = true;
                $res['juz29_rank'] = 48.0;
                $res['juz29_keys'] = array_keys($catalog['juz29Surahs']);
                $res['munaq_juz29'] = true;
                $res['juz_stage'] = max($res['juz_stage'], $this->juzToStage($juz));
                $res['label'] = "Juz {$juz}" . ($komp !== '' ? " ({$komp})" : '');
                return $res;
            }
        }

        return $res;
    }

    /**
     * Parse teks bebas 'target' dari JSON rapor (target individu siswa SMP/SMA).
     */
    protected function parseTeacherTarget(string $targetRaw, array $catalog): array
    {
        $t = strtoupper(trim($targetRaw));
        if ($t === '' || str_contains($t, 'UMMI') || str_contains($t, 'TURJUMAN') || str_contains($t, 'TARTIL') || str_contains($t, 'GHOROIB') || str_contains($t, 'TAJWID')) {
            return ['type' => null, 'value' => 0.0, 'label' => ''];
        }
        if ($t === 'JUZ !') {
            $t = 'JUZ 1';
        }
        if (str_contains($t, '2929')) {
            $t = 'JUZ 29';
        }
        if (preg_match('/JUZ\s*(\d+)/i', $t, $m)) {
            $juz = (int)$m[1];
            if ($juz === 30) {
                return ['type' => 'juz30_rank', 'value' => 37.0, 'label' => 'Tuntas Juz 30'];
            }
            if ($juz === 29) {
                return ['type' => 'juz29_rank', 'value' => 48.0, 'label' => 'Tuntas Juz 29 (Al-Mursalat)'];
            }
            if ($juz === 28) {
                return ['type' => 'juz28_rank', 'value' => 57.0, 'label' => 'Tuntas Juz 28 (At-Tahrim)'];
            }
            if ($juz >= 1 && $juz <= 27) {
                return ['type' => 'juz_stage', 'value' => (float)$this->juzToStage($juz), 'label' => "Target Juz {$juz}"];
            }
        }

        $surahs = $this->extractSurahKeysFromText($targetRaw, $catalog);
        if (!empty($surahs)) {
            $last = end($surahs);
            if (isset($catalog['juz30Surahs'][$last])) {
                $info = $catalog['juz30Surahs'][$last];
                return ['type' => 'juz30_rank', 'value' => (float)$info['rank'], 'label' => "QS. {$info['label']}"];
            }
            if (isset($catalog['juz29Surahs'][$last])) {
                $info = $catalog['juz29Surahs'][$last];
                return ['type' => 'juz29_rank', 'value' => (float)$info['rank'], 'label' => "QS. {$info['label']}"];
            }
            if (isset($catalog['juz28Surahs'][$last])) {
                $info = $catalog['juz28Surahs'][$last];
                return ['type' => 'juz28_rank', 'value' => (float)$info['rank'], 'label' => "QS. {$info['label']}"];
            }
        }
        return ['type' => null, 'value' => 0.0, 'label' => ''];
    }

    /**
     * Klasifikasi ringan konteks catatan bebas guru:
     * - negative: absensi / gagal / belum hafal -> jangan dihitung capaian oleh regex
     * - future_plan: target berikutnya / PR / drill -> serahkan ke AI agar tidak salah kira sudah hafal
     * - prose: kalimat bebas >= 4 kata -> serahkan ke AI
     * - clean: nama surat/ayat singkat yang aman diparsing Layer 1
     */
    protected function classifyNoteTextState(string $text): string
    {
        $t = strtolower(trim($text));
        if ($t === '' || $t === '-') {
            return 'empty';
        }
        if (preg_match('/\b(belum\s+hafal|belum\s+siap|belum\s+lancar|tidak\s+hadir|izin|sakit|alpha|alfa|upacara|hexafest|masih\s+di\s?bantu|ulang|remidi|mengulang)\b/i', $t)) {
            return 'negative';
        }
        if (preg_match('/\b(lanjut\s+(?:qs|sur|ke)|pr\s+(?:qs|sur)|persiapan|siapkan|target\s+berikut)\b/i', $t)) {
            return 'future_plan';
        }
        $words = preg_split('/\s+/', $t) ?: [];
        if (count($words) >= 4) {
            return 'prose';
        }
        return 'clean';
    }

    protected function isGenericCpOrKd(string $text): bool
    {
        $t = trim(str_replace('\\/', '/', $text));
        if ($t === '') {
            return true;
        }
        return (bool)preg_match('/^(h\d+\/\d+|juz\s*\d*|tahfi[dz]*|al[\s-]*qur.?an|tahsin|turjuman.*)$/i', $t);
    }

    protected function buildNoteHash(string $sourceType, string $kdNama, string $materi, string $rawText): string
    {
        $payload = implode('|', [
            strtolower(trim($sourceType)),
            strtolower(trim($kdNama)),
            strtolower(trim($materi)),
            strtolower(trim($rawText)),
        ]);
        return hash('sha256', $payload);
    }

    /**
     * Endpoint API Penilaian Kinerja:
     * 1) Data Capaian Tahfidz Santri Terstruktur (Unit -> Paralel -> Rombel -> Pengajar)
     * 2) Data Rekap Pengisian Jurnal & Aktivitas Guru Qur'an (quran_jurnal & quran_nilai)
     */
    public function getTahfidzAchievement(Request $request)
    {
        if ($authError = $this->authorizeApiKey($request)) {
            return $authError;
        }

        try {
            $units = DB::table('unit as u')
                ->join('unit_level as ul', 'ul.unit_id', '=', 'u.id')
                ->join('quran_halaqah as qh', 'qh.unit_level_id', '=', 'ul.id')
                ->select('u.id', 'u.nama')
                ->distinct()
                ->orderBy('u.id', 'asc')
                ->get();

            // Ambil semua tahun ajaran yang punya halaqah ATAU sudah berjalan tanggal mulainya (agar TA aktif seperti 2026-2027 tampil)
            $academicYears = DB::table('tahun_ajaran as ta')
                ->leftJoin('quran_halaqah as qh', 'qh.tahun_ajaran_id', '=', 'ta.id')
                ->select(
                    'ta.id',
                    'ta.nama',
                    'ta.semester',
                    'ta.start_date',
                    'ta.end_date',
                    DB::raw('COUNT(DISTINCT qh.id) as total_halaqah')
                )
                ->where(function ($q) {
                    $q->whereNotNull('qh.id')
                      ->orWhere('ta.start_date', '<=', now()->toDateString());
                })
                ->groupBy('ta.id', 'ta.nama', 'ta.semester', 'ta.start_date', 'ta.end_date')
                ->orderBy('ta.id', 'desc')
                ->get();

            if ($units->isEmpty() || $academicYears->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ditemukan data halaqah atau capaian Tahfidz pada database.',
                    'meta' => ['units' => $units, 'academic_years' => []],
                    'students' => [],
                    'guru_journals' => []
                ]);
            }

            $unitId = (int)$request->input('unit_id', 2);
            $selectedUnit = $units->firstWhere('id', $unitId) ?? $units->first();
            $unitId = (int)$selectedUnit->id;

            // Default tahun ajaran: pilih TA terbaru yang memiliki data halaqah jika parameter kosong
            $defaultTa = $academicYears->firstWhere('total_halaqah', '>', 0) ?? $academicYears->first();
            $reqTaId = $request->input('tahun_ajaran_id');
            $tahunAjaranId = ($reqTaId !== null && $reqTaId !== '') ? (int)$reqTaId : (int)$defaultTa->id;
            $selectedTahunAjaran = $academicYears->firstWhere('id', $tahunAjaranId) ?? $defaultTa;
            $tahunAjaranId = (int)$selectedTahunAjaran->id;

            // Filter bulan opsional untuk tab jurnal (1..12, 0 = semua semester)
            $month = (int)$request->input('month', 0);
            if ($month < 0 || $month > 12) {
                $month = 0;
            }

            // ams_level_sub_siswa hanya tersimpan pada Semester 1 tiap Tahun Ajaran
            $rombelTaId = $tahunAjaranId;
            if ((int)$selectedTahunAjaran->semester === 2) {
                $sem1Id = DB::table('tahun_ajaran')
                    ->where('nama', $selectedTahunAjaran->nama)
                    ->where('semester', 1)
                    ->value('id');
                if ($sem1Id) {
                    $rombelTaId = (int)$sem1Id;
                }
            }

            $paralels = DB::table('unit_level')
                ->where('unit_id', $unitId)
                ->whereNull('deleted_at')
                ->select('id as paralel_id', 'nama as paralel_nama')
                ->orderBy('id', 'asc')
                ->get();

            // -----------------------------------------------------------------
            // BAGIAN A: ROSTER & CAPAIAN SANTRI TAHFIDZ
            // -----------------------------------------------------------------
            $rosterRows = DB::select("
                SELECT 
                    qhs.id as qhs_id,
                    qhs.siswa_id,
                    s.nama as siswa_nama,
                    ul.id as paralel_id,
                    ul.nama as paralel_nama,
                    COALESCE(uls.id, 0) as rombel_id,
                    COALESCE(uls.code, 'Tanpa Rombel') as rombel_code,
                    COALESCE(uls.nama, '-') as rombel_nama,
                    qh.id as halaqah_id,
                    COALESCE(g.id, 0) as pengajar_id,
                    COALESCE(g.nik, '') as pengajar_nik,
                    COALESCE(g.nama, '-') as pengajar_nama
                FROM quran_halaqah_siswa qhs
                JOIN quran_halaqah qh ON qh.id = qhs.halaqah_id
                JOIN unit_level ul ON ul.id = qh.unit_level_id
                JOIN siswa s ON s.id = qhs.siswa_id
                LEFT JOIN guru g ON g.id = qh.pengajar_id
                LEFT JOIN ams_level_sub_siswa alss 
                    ON alss.siswa_id = s.id AND alss.tahun_ajaran_id = ? AND alss.deleted_at IS NULL
                LEFT JOIN unit_level_sub uls ON uls.id = alss.unit_level_sub_id AND uls.deleted_at IS NULL
                WHERE ul.unit_id = ?
                  AND qh.tahun_ajaran_id = ?
                  AND qhs.deleted_at IS NULL
                  AND s.deleted_at IS NULL
                ORDER BY ul.id ASC, uls.code ASC, s.nama ASC, qhs.id DESC
            ", [$rombelTaId, $unitId, $tahunAjaranId]);

            // -----------------------------------------------------------------
            // BAGIAN B: STATISTIK JURNAL & AKTIVITAS GURU QUR'AN
            // -----------------------------------------------------------------
            $monthFilterJurnal = $month > 0 ? " AND MONTH(qj.date) = " . (int)$month : "";
            $monthFilterNilai  = $month > 0 ? " AND MONTH(qn.tanggal) = " . (int)$month : "";

            $hariEfektifRows = DB::select("
                SELECT COUNT(DISTINCT qj.date) as hari_efektif,
                       MIN(qj.date) as min_jurnal,
                       MAX(qj.date) as max_jurnal
                FROM quran_jurnal qj
                JOIN quran_halaqah qh ON qh.id = qj.halaqah_id
                JOIN unit_level ul ON ul.id = qh.unit_level_id
                WHERE ul.unit_id = ?
                  AND qh.tahun_ajaran_id = ?
                  AND qj.deleted_at IS NULL
                  {$monthFilterJurnal}
            ", [$unitId, $tahunAjaranId]);
            $hariEfektifUnit = !empty($hariEfektifRows) ? (int)$hariEfektifRows[0]->hari_efektif : 0;

            $availableMonthsRows = DB::select("
                SELECT DISTINCT MONTH(qj.date) as bln
                FROM quran_jurnal qj
                JOIN quran_halaqah qh ON qh.id = qj.halaqah_id
                JOIN unit_level ul ON ul.id = qh.unit_level_id
                WHERE ul.unit_id = ?
                  AND qh.tahun_ajaran_id = ?
                  AND qj.deleted_at IS NULL
                ORDER BY bln ASC
            ", [$unitId, $tahunAjaranId]);
            $availableMonths = array_map(fn($r) => (int)$r->bln, $availableMonthsRows);

            $weekdayRows = DB::select("
                SELECT DAYOFWEEK(qj.date) as dow, COUNT(*) as cnt
                FROM quran_jurnal qj
                JOIN quran_halaqah qh ON qh.id = qj.halaqah_id
                JOIN unit_level ul ON ul.id = qh.unit_level_id
                WHERE ul.unit_id = ?
                  AND qh.tahun_ajaran_id = ?
                  AND qj.deleted_at IS NULL
                GROUP BY DAYOFWEEK(qj.date)
                HAVING cnt >= 3
                ORDER BY dow ASC
            ", [$unitId, $tahunAjaranId]);
            $dowNames = [1 => 'Min', 2 => 'Sen', 3 => 'Sel', 4 => 'Rab', 5 => 'Kam', 6 => 'Jum', 7 => 'Sab'];
            $unitActiveDays = array_values(array_filter(array_map(fn($r) => $dowNames[(int)$r->dow] ?? null, $weekdayRows)));

            $guruStatsRaw = DB::select("
                SELECT 
                    g.id as guru_id,
                    g.nama as guru_nama,
                    COALESCE(g.nik, '') as guru_nik,
                    COALESCE(g.email, '') as guru_email,
                    COUNT(DISTINCT qh.id) as jml_halaqah,
                    GROUP_CONCAT(DISTINCT ul.nama ORDER BY ul.id SEPARATOR ', ') as level_list,
                    (SELECT COUNT(DISTINCT qhs2.siswa_id)
                     FROM quran_halaqah_siswa qhs2
                     JOIN quran_halaqah qh2 ON qh2.id = qhs2.halaqah_id
                     JOIN unit_level ul2 ON ul2.id = qh2.unit_level_id
                     WHERE qh2.pengajar_id = g.id
                       AND qh2.tahun_ajaran_id = ?
                       AND ul2.unit_id = ?
                       AND qhs2.deleted_at IS NULL
                    ) as total_santri,
                    (SELECT COUNT(DISTINCT qj.date)
                     FROM quran_jurnal qj
                     JOIN quran_halaqah qh3 ON qh3.id = qj.halaqah_id
                     JOIN unit_level ul3 ON ul3.id = qh3.unit_level_id
                     WHERE qh3.pengajar_id = g.id
                       AND qh3.tahun_ajaran_id = ?
                       AND ul3.unit_id = ?
                       AND qj.deleted_at IS NULL
                       {$monthFilterJurnal}
                    ) as hari_isi_jurnal,
                    (SELECT COUNT(DISTINCT CONCAT(qj.halaqah_id, '_', qj.date))
                     FROM quran_jurnal qj
                     JOIN quran_halaqah qh3b ON qh3b.id = qj.halaqah_id
                     JOIN unit_level ul3b ON ul3b.id = qh3b.unit_level_id
                     WHERE qh3b.pengajar_id = g.id
                       AND qh3b.tahun_ajaran_id = ?
                       AND ul3b.unit_id = ?
                       AND qj.deleted_at IS NULL
                       {$monthFilterJurnal}
                    ) as sesi_jurnal_terisi,
                    (SELECT COUNT(qj.id)
                     FROM quran_jurnal qj
                     JOIN quran_halaqah qh4 ON qh4.id = qj.halaqah_id
                     JOIN unit_level ul4 ON ul4.id = qh4.unit_level_id
                     WHERE qh4.pengajar_id = g.id
                       AND qh4.tahun_ajaran_id = ?
                       AND ul4.unit_id = ?
                       AND qj.deleted_at IS NULL
                       {$monthFilterJurnal}
                    ) as total_entri_jurnal,
                    (SELECT COUNT(DISTINCT qn.tanggal)
                     FROM quran_nilai qn
                     JOIN quran_halaqah_siswa qhs5 ON qhs5.id = qn.siswa_id AND qhs5.deleted_at IS NULL
                     JOIN quran_halaqah qh5 ON qh5.id = qhs5.halaqah_id
                     JOIN unit_level ul5 ON ul5.id = qh5.unit_level_id
                     WHERE qh5.pengajar_id = g.id
                       AND qh5.tahun_ajaran_id = ?
                       AND ul5.unit_id = ?
                       {$monthFilterNilai}
                    ) as hari_input_nilai,
                    (SELECT MIN(qj.date)
                     FROM quran_jurnal qj
                     JOIN quran_halaqah qh6 ON qh6.id = qj.halaqah_id
                     JOIN unit_level ul6 ON ul6.id = qh6.unit_level_id
                     WHERE qh6.pengajar_id = g.id AND qh6.tahun_ajaran_id = ? AND ul6.unit_id = ? AND qj.deleted_at IS NULL
                       {$monthFilterJurnal}
                    ) as tgl_pertama,
                    (SELECT MAX(qj.date)
                     FROM quran_jurnal qj
                     JOIN quran_halaqah qh7 ON qh7.id = qj.halaqah_id
                     JOIN unit_level ul7 ON ul7.id = qh7.unit_level_id
                     WHERE qh7.pengajar_id = g.id AND qh7.tahun_ajaran_id = ? AND ul7.unit_id = ? AND qj.deleted_at IS NULL
                       {$monthFilterJurnal}
                    ) as tgl_terakhir
                FROM quran_halaqah qh
                JOIN guru g ON g.id = qh.pengajar_id
                JOIN unit_level ul ON ul.id = qh.unit_level_id
                WHERE ul.unit_id = ?
                  AND qh.tahun_ajaran_id = ?
                GROUP BY g.id, g.nama, g.nik, g.email
                ORDER BY hari_isi_jurnal DESC, g.nama ASC
            ", [
                $tahunAjaranId, $unitId,
                $tahunAjaranId, $unitId,
                $tahunAjaranId, $unitId,
                $tahunAjaranId, $unitId,
                $tahunAjaranId, $unitId,
                $tahunAjaranId, $unitId,
                $tahunAjaranId, $unitId,
                $unitId, $tahunAjaranId
            ]);

            $guruJournals = array_map(function ($gr) {
                return [
                    'guru_id' => (int)$gr->guru_id,
                    'guru_nama' => (string)$gr->guru_nama,
                    'guru_nik' => trim((string)$gr->guru_nik),
                    'clean_nik' => preg_replace('/[^a-zA-Z0-9]/', '', (string)$gr->guru_nik) ?? '',
                    'guru_email' => (string)$gr->guru_email,
                    'jml_halaqah' => (int)$gr->jml_halaqah,
                    'level_list' => (string)($gr->level_list ?? '-'),
                    'total_santri' => (int)$gr->total_santri,
                    'hari_isi_jurnal' => (int)$gr->hari_isi_jurnal,
                    'sesi_jurnal_terisi' => (int)$gr->sesi_jurnal_terisi,
                    'total_entri_jurnal' => (int)$gr->total_entri_jurnal,
                    'hari_input_nilai' => (int)$gr->hari_input_nilai,
                    'tgl_pertama' => $gr->tgl_pertama,
                    'tgl_terakhir' => $gr->tgl_terakhir,
                ];
            }, $guruStatsRaw);

            if (empty($rosterRows)) {
                return response()->json([
                    'success' => true,
                    'meta' => [
                        'selected_unit' => $selectedUnit,
                        'selected_tahun_ajaran' => $selectedTahunAjaran,
                        'units' => $units,
                        'academic_years' => $academicYears,
                        'paralels' => $paralels,
                        'selected_month' => $month,
                        'available_months' => $availableMonths,
                        'hari_efektif_unit' => $hariEfektifUnit,
                        'unit_active_days' => $unitActiveDays,
                        'has_raport_data' => false,
                        'total_students' => 0,
                        'server_time' => now()->toIso8601String(),
                    ],
                    'students' => [],
                    'guru_journals' => $guruJournals,
                ]);
            }

            $studentsMap = [];
            foreach ($rosterRows as $r) {
                $sid = (int)$r->siswa_id;
                if (!isset($studentsMap[$sid])) {
                    $studentsMap[$sid] = [
                        'siswa_id' => $sid,
                        'nama' => (string)$r->siswa_nama,
                        'paralel_id' => (int)$r->paralel_id,
                        'paralel_nama' => (string)$r->paralel_nama,
                        'rombel_id' => (int)$r->rombel_id,
                        'rombel_code' => (string)$r->rombel_code,
                        'rombel_nama' => (string)$r->rombel_nama,
                        'halaqah_id' => (int)$r->halaqah_id,
                        'pengajar_id' => (int)$r->pengajar_id,
                        'pengajar_nik' => trim((string)$r->pengajar_nik),
                        'pengajar_nama' => (string)$r->pengajar_nama,
                    ];
                }
            }

            $allSiswaIds = array_keys($studentsMap);
            $placeholders = implode(',', array_fill(0, count($allSiswaIds), '?'));

            // Batch 1: Rapor Akhir Qur'an (histori <= TA terpilih)
            $qnaRows = DB::select("
                SELECT qhs.siswa_id, qh.tahun_ajaran_id, qna.nilai
                FROM quran_nilai_akhir qna
                JOIN quran_halaqah_siswa qhs ON qhs.id = qna.siswa_id AND qhs.deleted_at IS NULL
                JOIN quran_halaqah qh ON qh.id = qhs.halaqah_id
                WHERE qhs.siswa_id IN ({$placeholders})
                  AND qh.tahun_ajaran_id <= ?
                ORDER BY qh.tahun_ajaran_id ASC, qna.id ASC
            ", array_merge($allSiswaIds, [$tahunAjaranId]));

            // Batch 2: Tes Kenaikan Ummi / Munaqasyah / Catatan Tes Mengandung Surat
            $tesRows = DB::select("
                SELECT qhs.siswa_id, qh.tahun_ajaran_id, t.jilid_ummi, t.catatan, t.hasil
                FROM quran_catatan_tes_ummi t
                JOIN quran_halaqah_siswa qhs ON qhs.id = t.siswa_id AND qhs.deleted_at IS NULL
                JOIN quran_halaqah qh ON qh.id = qhs.halaqah_id
                WHERE qhs.siswa_id IN ({$placeholders})
                  AND qh.tahun_ajaran_id <= ?
                  AND t.deleted_at IS NULL
                  AND t.hasil = 1
                  AND (
                      t.jilid_ummi LIKE 'Juz%'
                      OR t.jilid_ummi LIKE '%Munaq%'
                      OR LOWER(COALESCE(t.catatan, '')) REGEXP '(qs|surat|surah|juz|h[1-4]/|tasmi|munaq|hafal)'
                  )
            ", array_merge($allSiswaIds, [$tahunAjaranId]));

            // Batch 3: Setoran Harian (tanpa MAX(keterangan) timpa abjad; dukung nilai NULL bila catatan terisi)
            $harianRows = DB::select("
                SELECT qhs.siswa_id,
                       qh.tahun_ajaran_id,
                       qn.mapel,
                       COALESCE(qkd.nama, '') AS kd_nama,
                       COALESCE(qn.materi, '') AS materi,
                       COALESCE(qn.keterangan, '') AS keterangan,
                       MAX(qn.nilai) AS max_nilai,
                       MAX(qn.tanggal) AS max_tanggal
                FROM quran_nilai qn
                LEFT JOIN quran_kd qkd ON qkd.id = qn.kd_id
                JOIN quran_halaqah_siswa qhs ON qhs.id = qn.siswa_id AND qhs.deleted_at IS NULL
                JOIN quran_halaqah qh ON qh.id = qhs.halaqah_id
                WHERE qhs.siswa_id IN ({$placeholders})
                  AND qh.tahun_ajaran_id <= ?
                  AND (
                      qn.mapel = 'Tahfiz'
                      OR (
                          qn.mapel = 'Tahsin'
                          AND LOWER(COALESCE(qn.keterangan, '')) REGEXP '(juz|surat|surah|qs|baqarah|imran|nisa|mulk|naba|nas|hafal)'
                      )
                  )
                  AND (
                      qn.nilai >= 70
                      OR (qn.nilai IS NULL AND (TRIM(COALESCE(qn.materi, '')) <> '' OR TRIM(COALESCE(qn.keterangan, '')) <> ''))
                      OR LOWER(COALESCE(qn.keterangan, '')) REGEXP '(lulus|hafal|selesai|tuntas)'
                  )
                GROUP BY qhs.siswa_id, qh.tahun_ajaran_id, qn.mapel, kd_nama, qn.materi, qn.keterangan
                ORDER BY qh.tahun_ajaran_id ASC, max_tanggal ASC
            ", array_merge($allSiswaIds, [$tahunAjaranId]));

            $qnaBySiswa = [];
            $hasAnyRaportCurrentTa = false;
            foreach ($qnaRows as $row) {
                $sid = (int)$row->siswa_id;
                $qnaBySiswa[$sid][] = $row;
                if ((int)$row->tahun_ajaran_id === $tahunAjaranId) {
                    $hasAnyRaportCurrentTa = true;
                }
            }

            $tesBySiswa = [];
            foreach ($tesRows as $row) {
                $tesBySiswa[(int)$row->siswa_id][] = $row;
            }

            $harianBySiswa = [];
            foreach ($harianRows as $row) {
                $harianBySiswa[(int)$row->siswa_id][] = $row;
            }

            $catalog = $this->getTahfidzCatalog();
            $ambiguousNotesPool = [];
            $normalizedStudents = [];

            foreach ($studentsMap as $sid => $st) {
                $juz30Rank = -1.0;
                $juz29Rank = -1.0;
                $juz28Rank = -1.0;
                $juz29Set = [];
                $juz28Set = [];
                $juzStage = 0;
                $munaq30 = false;
                $munaq29 = false;
                $munaq28 = false;
                $currentTaEntries = [];
                $historyLabels = [];
                $teacherTargetRaw = '';
                $teacherTarget = ['type' => null, 'value' => 0.0, 'label' => ''];
                $hasCurrentRaport = false;
                $studentNoteHashes = [];

                $enqueueAmbiguous = function (string $srcType, string $kd, string $mat, string $rawTxt, $nilaiVal = null) use (&$ambiguousNotesPool, &$studentNoteHashes) {
                    $hash = $this->buildNoteHash($srcType, $kd, $mat, $rawTxt);
                    if (!isset($ambiguousNotesPool[$hash])) {
                        $ambiguousNotesPool[$hash] = [
                            'note_hash'   => $hash,
                            'source_type' => $srcType,
                            'kd_nama'     => $kd,
                            'materi'      => $mat,
                            'raw_text'    => $rawTxt,
                            'nilai'       => $nilaiVal !== null ? (float)$nilaiVal : null,
                        ];
                    }
                    $studentNoteHashes[$hash] = true;
                };

                // 1. Rapor Akhir Qur'an (gabung kompetensi + deskripsi + catatan)
                foreach ($qnaBySiswa[$sid] ?? [] as $qr) {
                    $decoded = json_decode((string)$qr->nilai, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $isCurrentTa = ((int)$qr->tahun_ajaran_id === $tahunAjaranId);
                    if ($isCurrentTa) {
                        $hasCurrentRaport = true;
                    }

                    foreach ($decoded as $item) {
                        if (($item['mapel'] ?? '') !== 'Tahfiz') {
                            continue;
                        }
                        if ($isCurrentTa && !empty($item['target']) && $teacherTargetRaw === '') {
                            $teacherTargetRaw = (string)$item['target'];
                            $parsedTgt = $this->parseTeacherTarget($teacherTargetRaw, $catalog);
                            if ($parsedTgt['type'] !== null) {
                                $teacherTarget = $parsedTgt;
                            }
                        }

                        $cpRaw = trim((string)($item['cp_nama'] ?? ''));
                        $kompCombined = trim(
                            (string)($item['kompetensi'] ?? '') . ' ' .
                            (string)($item['deskripsi'] ?? '') . ' ' .
                            (string)($item['catatan'] ?? '') . ' ' .
                            (string)($item['keterangan'] ?? '')
                        );

                        $parsed = $this->parseTahfidzEntry(
                            $cpRaw,
                            $kompCombined,
                            $catalog,
                            true
                        );

                        $juz30Rank = max($juz30Rank, $parsed['juz30_rank']);
                        $juz29Rank = max($juz29Rank, $parsed['juz29_rank']);
                        $juz28Rank = max($juz28Rank, $parsed['juz28_rank']);
                        foreach ($parsed['juz29_keys'] as $k) {
                            $juz29Set[$k] = true;
                        }
                        foreach ($parsed['juz28_keys'] as $k) {
                            $juz28Set[$k] = true;
                        }
                        $juzStage = max($juzStage, $parsed['juz_stage']);
                        if ($parsed['munaq_juz30']) $munaq30 = true;
                        if ($parsed['munaq_juz29']) $munaq29 = true;
                        if ($parsed['munaq_juz28']) $munaq28 = true;

                        if ($parsed['juz30_rank'] < 0 && $parsed['juz29_rank'] < 0 && $parsed['juz28_rank'] < 0 && $parsed['juz_stage'] === 0) {
                            if ($cpRaw !== '' || $kompCombined !== '') {
                                $enqueueAmbiguous('rapor', $cpRaw, '', $kompCombined, $item['nilai'] ?? null);
                            }
                        }

                        if ($isCurrentTa && $parsed['label'] !== '') {
                            $currentTaEntries[] = $parsed['label'];
                        } elseif ($parsed['label'] !== '') {
                            $historyLabels[] = $parsed['label'];
                        }
                    }
                }

                // 2. Validasi Tes Kenaikan / Munaqasyah & Catatan Tes Ummi
                foreach ($tesBySiswa[$sid] ?? [] as $tr) {
                    $jilid = trim((string)$tr->jilid_ummi);
                    $catatanDecoded = json_decode((string)($tr->catatan ?? ''), true);
                    $catatanTxt = is_array($catatanDecoded)
                        ? implode(' ', array_map('strval', $catatanDecoded))
                        : trim((string)($tr->catatan ?? ''));
                    $catatanLow = strtolower($catatanTxt);

                    $isJuzOrMunaqJilid = (stripos($jilid, 'Juz') === 0 || stripos($jilid, 'Munaq') !== false);
                    if (!$isJuzOrMunaqJilid) {
                        if ($catatanTxt !== '') {
                            $enqueueAmbiguous('tes_ummi', $jilid, '', $catatanTxt, null);
                        }
                        continue;
                    }

                    $catatanSurahs = $this->extractSurahKeysFromText($catatanTxt, $catalog);
                    $hasBlockNote = (bool)preg_match('/\bh([1-4])\s*\/\s*(\d+)/i', str_replace('\\/', '/', $catatanTxt), $mbCat);

                    if ($hasBlockNote || !empty($catatanSurahs)) {
                        $blockCode = $hasBlockNote ? ('H' . $mbCat[1] . '/' . $mbCat[2]) : $jilid;
                        if ($hasBlockNote && (int)$mbCat[2] === 20 && !empty($catatanSurahs)) {
                            $blockCode = 'H' . $mbCat[1] . '/30';
                        }
                        $parsedCat = $this->parseTahfidzEntry($blockCode, $catatanTxt, $catalog, false);
                        $juz30Rank = max($juz30Rank, $parsedCat['juz30_rank']);
                        $juz29Rank = max($juz29Rank, $parsedCat['juz29_rank']);
                        $juz28Rank = max($juz28Rank, $parsedCat['juz28_rank']);
                        foreach ($parsedCat['juz29_keys'] as $k) {
                            $juz29Set[$k] = true;
                        }
                        foreach ($parsedCat['juz28_keys'] as $k) {
                            $juz28Set[$k] = true;
                        }
                        $juzStage = max($juzStage, $parsedCat['juz_stage']);
                        if ($parsedCat['juz30_rank'] < 0 && $parsedCat['juz29_rank'] < 0 && $parsedCat['juz28_rank'] < 0 && $parsedCat['juz_stage'] === 0) {
                            $enqueueAmbiguous('tes_ummi', $jilid, '', $catatanTxt, null);
                        }
                        continue;
                    }

                    $isExplicitJuzExam = str_contains($catatanLow, 'tasmi') || str_contains($catatanLow, 'istimror') || str_contains($catatanLow, 'munaq') || str_contains($catatanLow, 'lulus juz');

                    if (preg_match('/Juz\s*(\d+)/i', $jilid, $mj)) {
                        $jn = (int)$mj[1];
                        $stageVal = $this->juzToStage($jn);

                        if ($jn === 30 && ($isExplicitJuzExam || $juz30Rank >= 35.0 || !$hasCurrentRaport)) {
                            $juzStage = max($juzStage, $stageVal);
                            $juz30Rank = max($juz30Rank, 37.0);
                            $munaq30 = true;
                        } elseif ($jn === 29 && ($isExplicitJuzExam || $juz29Rank >= 43.0 || count($juz29Set) >= 6 || !$hasCurrentRaport)) {
                            $juzStage = max($juzStage, $stageVal);
                            $juz30Rank = max($juz30Rank, 37.0);
                            $juz29Rank = max($juz29Rank, 48.0);
                            $munaq30 = true;
                            $munaq29 = true;
                            foreach (array_keys($catalog['juz29Surahs']) as $k) {
                                $juz29Set[$k] = true;
                            }
                        } elseif ($jn === 28 && ($isExplicitJuzExam || $juz28Rank >= 53.0 || count($juz28Set) >= 5 || !$hasCurrentRaport)) {
                            $juzStage = max($juzStage, $stageVal);
                            $juz30Rank = max($juz30Rank, 37.0);
                            $juz29Rank = max($juz29Rank, 48.0);
                            $juz28Rank = max($juz28Rank, 57.0);
                            $munaq30 = true;
                            $munaq29 = true;
                            $munaq28 = true;
                            foreach (array_keys($catalog['juz29Surahs']) as $k) {
                                $juz29Set[$k] = true;
                            }
                            foreach (array_keys($catalog['juz28Surahs']) as $k) {
                                $juz28Set[$k] = true;
                            }
                        } elseif ($jn >= 1 && $jn <= 27 && ($isExplicitJuzExam || $juzStage >= 2 || !$hasCurrentRaport)) {
                            $juzStage = max($juzStage, $stageVal);
                            $juz30Rank = max($juz30Rank, 37.0);
                            $juz29Rank = max($juz29Rank, 48.0);
                            $munaq30 = true;
                            $munaq29 = true;
                            foreach (array_keys($catalog['juz29Surahs']) as $k) {
                                $juz29Set[$k] = true;
                            }
                        }
                    }
                }

                // 3. Setoran Harian & Pembacaan Catatan Guru (quran_nilai)
                $hasHarianCurrentTa = false;
                $upgradedFromDailyNote = false;
                foreach ($harianBySiswa[$sid] ?? [] as $hr) {
                    $isCurrentTa = ((int)$hr->tahun_ajaran_id === $tahunAjaranId);
                    if ($isCurrentTa) {
                        $hasHarianCurrentTa = true;
                    }

                    $kdRaw  = trim((string)$hr->kd_nama);
                    $matRaw = trim((string)$hr->materi);
                    $ketRaw = trim((string)$hr->keterangan);
                    $valNum = $hr->max_nilai !== null ? (float)$hr->max_nilai : null;

                    $ketState  = $this->classifyNoteTextState($ketRaw);
                    $isGenKd   = $this->isGenericCpOrKd($kdRaw);

                    // Lewati catatan murni absensi / gagal bila KD generik
                    if ($ketState === 'negative' && $isGenKd) {
                        continue;
                    }

                    if (!$isGenKd && $hr->mapel === 'Tahfiz') {
                        // KD sudah spesifik menyebut surat (mis. "Al-Balad"), gunakan rentang ayat saja bila ket tidak negatif
                        if ($ketState !== 'negative') {
                            $kompH = $matRaw !== '' ? ('Ayat ' . $matRaw) : (preg_match('/^\d+\s*-\s*\d+$/', $ketRaw) ? ('Ayat ' . $ketRaw) : '');
                            $parsedH = $this->parseTahfidzEntry($kdRaw, $kompH, $catalog, false);

                            foreach ($parsedH['juz29_keys'] as $k) $juz29Set[$k] = true;
                            foreach ($parsedH['juz28_keys'] as $k) $juz28Set[$k] = true;
                            if ($parsedH['juz29_rank'] > $juz29Rank) $juz29Rank = $parsedH['juz29_rank'];
                            if ($parsedH['juz28_rank'] > $juz28Rank) $juz28Rank = $parsedH['juz28_rank'];

                            if ($isCurrentTa || !$hasCurrentRaport) {
                                if ($parsedH['juz30_rank'] > $juz30Rank) {
                                    $juz30Rank = $parsedH['juz30_rank'];
                                    $upgradedFromDailyNote = true;
                                }
                                if ($parsedH['juz_stage'] > $juzStage) {
                                    $juzStage = $parsedH['juz_stage'];
                                }
                                if ($parsedH['label'] !== '' && !$hasCurrentRaport) {
                                    $currentTaEntries[] = $parsedH['label'];
                                }
                            }
                        }
                        if (($ketState === 'prose' || $ketState === 'future_plan') && preg_match('/(qs|sur|juz|h[1-4]\/|sampai\s+ay|sampe\s+ay|ayat\s+\d+|lulus|tuntas|baqarah|imran|mulk|naba|nas|fajr|balad)/i', $ketRaw)) {
                            $enqueueAmbiguous('quran_nilai', $kdRaw, $matRaw, $ketRaw, $valNum);
                        }
                    } else {
                        // KD Generik (mis. H3/30, Juz 30, Juz 1, Al-Qur'an) -> cek apakah catatan/materi bersih atau perlu AI
                        $combinedMatKet = trim(($matRaw !== '' ? $matRaw . ' ' : '') . $ketRaw);
                        if ($combinedMatKet === '') {
                            continue;
                        }
                        if ($ketState === 'clean') {
                            $allowExpand = str_contains(strtolower($ketRaw), 'lulus') || str_contains(strtolower($ketRaw), 'munaq');
                            $matSurahs = $this->extractSurahKeysFromText($combinedMatKet, $catalog);
                            $parsedH = !empty($matSurahs)
                                ? $this->parseTahfidzEntry((string)end($matSurahs), $combinedMatKet, $catalog, $allowExpand)
                                : $this->parseTahfidzEntry($kdRaw, $combinedMatKet, $catalog, $allowExpand);
                            foreach ($parsedH['juz29_keys'] as $k) $juz29Set[$k] = true;
                            foreach ($parsedH['juz28_keys'] as $k) $juz28Set[$k] = true;

                            $prevBest = max($juz30Rank, $juz29Rank, $juz28Rank);
                            if ($parsedH['juz29_rank'] > $juz29Rank) $juz29Rank = $parsedH['juz29_rank'];
                            if ($parsedH['juz28_rank'] > $juz28Rank) $juz28Rank = $parsedH['juz28_rank'];

                            if ($isCurrentTa || !$hasCurrentRaport) {
                                if ($parsedH['juz30_rank'] > $juz30Rank) $juz30Rank = $parsedH['juz30_rank'];
                                if ($parsedH['juz_stage'] > $juzStage) $juzStage = $parsedH['juz_stage'];
                                if (max($juz30Rank, $juz29Rank, $juz28Rank) > $prevBest && $parsedH['label'] !== '') {
                                    $upgradedFromDailyNote = true;
                                    $currentTaEntries[] = $parsedH['label'] . ' [Catatan Harian]';
                                }
                            }

                            // Jika masih belum terekstrak dari keterangan namun memuat kata kunci penting, kirim ke antrean AI
                            if ($parsedH['juz30_rank'] < 0 && $parsedH['juz29_rank'] < 0 && $parsedH['juz28_rank'] < 0 && $parsedH['juz_stage'] === 0) {
                                if (preg_match('/(qs|sur|juz|hafal|lulus|tuntas|muroja|ziyadah|h[1-4])/i', $combinedMatKet)) {
                                    $enqueueAmbiguous('quran_nilai', $kdRaw, $matRaw, $ketRaw, $valNum);
                                }
                            }
                        } elseif ($ketState === 'empty' && $matRaw !== '' && !preg_match('/^(drill|muroja|muraja|tes|ulang|\d)/i', $matRaw)) {
                            $matSurahs = $this->extractSurahKeysFromText($matRaw, $catalog);
                            if (!empty($matSurahs)) {
                                $parsedH = $this->parseTahfidzEntry((string)end($matSurahs), $matRaw, $catalog, false);
                                foreach ($parsedH['juz29_keys'] as $k) $juz29Set[$k] = true;
                                foreach ($parsedH['juz28_keys'] as $k) $juz28Set[$k] = true;
                                $prevBest = max($juz30Rank, $juz29Rank, $juz28Rank);
                                if ($parsedH['juz29_rank'] > $juz29Rank) $juz29Rank = $parsedH['juz29_rank'];
                                if ($parsedH['juz28_rank'] > $juz28Rank) $juz28Rank = $parsedH['juz28_rank'];
                                if ($isCurrentTa || !$hasCurrentRaport) {
                                    if ($parsedH['juz30_rank'] > $juz30Rank) $juz30Rank = $parsedH['juz30_rank'];
                                    if ($parsedH['juz_stage'] > $juzStage) $juzStage = $parsedH['juz_stage'];
                                    if (max($juz30Rank, $juz29Rank, $juz28Rank) > $prevBest && $parsedH['label'] !== '') {
                                        $upgradedFromDailyNote = true;
                                        $currentTaEntries[] = $parsedH['label'] . ' [Materi Harian]';
                                    }
                                }
                            }
                        } elseif ($ketState === 'prose' || $ketState === 'future_plan') {
                            if (preg_match('/(qs|sur|juz|hafal|lulus|tuntas|muroja|ziyadah|h[1-4]|baqarah|imran|mulk|naba|nas)/i', $combinedMatKet)) {
                                $enqueueAmbiguous('quran_nilai', $kdRaw, $matRaw, $ketRaw, $valNum);
                            }
                        }
                    }
                }

                // Sinkronisasi antar tingkat Juz (Juz 30 -> Juz 29 -> Juz 1..28)
                if ($munaq29 || $juz29Rank >= 38.0 || $juzStage >= 2) {
                    $juz30Rank = max($juz30Rank, 37.0);
                    if ($munaq29 || $juzStage >= 3) {
                        $munaq30 = true;
                    }
                }
                if ($juzStage >= 3) {
                    $munaq29 = true;
                    $juz29Rank = max($juz29Rank, 48.0);
                    foreach (array_keys($catalog['juz29Surahs']) as $k) {
                        $juz29Set[$k] = true;
                    }
                }

                $sourceType = 'none';
                if ($hasCurrentRaport) {
                    $sourceType = $upgradedFromDailyNote ? 'rapor+catatan' : 'rapor';
                } elseif ($hasHarianCurrentTa) {
                    $sourceType = 'harian';
                } elseif (!empty($tesBySiswa[$sid]) || $juz30Rank >= 0) {
                    $sourceType = 'histori';
                }

                $actualDisplay = 'Belum Ada Data';
                if (!empty($currentTaEntries)) {
                    $actualDisplay = implode(' • ', array_slice(array_unique($currentTaEntries), -2));
                } elseif ($munaq29) {
                    $actualDisplay = 'Lulus Munaqasyah Juz 29';
                } elseif ($munaq30) {
                    $actualDisplay = 'Lulus Munaqasyah Juz 30';
                } elseif (!empty($historyLabels)) {
                    $actualDisplay = end($historyLabels);
                }

                $normalizedStudents[] = [
                    'siswa_id' => $st['siswa_id'],
                    'nama' => $st['nama'],
                    'paralel_id' => $st['paralel_id'],
                    'paralel_nama' => $st['paralel_nama'],
                    'rombel_id' => $st['rombel_id'],
                    'rombel_code' => $st['rombel_code'],
                    'rombel_nama' => $st['rombel_nama'],
                    'halaqah_id' => $st['halaqah_id'],
                    'pengajar_id' => $st['pengajar_id'],
                    'pengajar_nik' => $st['pengajar_nik'],
                    'pengajar_nama' => $st['pengajar_nama'],
                    'data_source' => $sourceType,
                    'juz30_rank' => round(max(0.0, $juz30Rank), 2),
                    'juz29_rank' => round(max(0.0, $juz29Rank), 2),
                    'juz28_rank' => round(max(0.0, $juz28Rank), 2),
                    'juz29_surahs' => array_keys($juz29Set),
                    'juz29_count' => $munaq29 ? 11 : count($juz29Set),
                    'juz28_surahs' => array_keys($juz28Set),
                    'juz28_count' => $munaq28 ? 9 : count($juz28Set),
                    'juz_stage' => (int)$juzStage,
                    'has_munaqasyah_juz30' => $munaq30,
                    'has_munaqasyah_juz29' => $munaq29,
                    'has_munaqasyah_juz28' => $munaq28,
                    'actual_display' => $actualDisplay,
                    'teacher_target_raw' => $teacherTargetRaw,
                    'teacher_target_type' => $teacherTarget['type'],
                    'teacher_target_value' => $teacherTarget['value'],
                    'teacher_target_label' => $teacherTarget['label'],
                    'note_hashes' => array_slice(array_keys($studentNoteHashes), 0, 15),
                ];
            }

            return response()->json([
                'success' => true,
                'meta' => [
                    'selected_unit' => $selectedUnit,
                    'selected_tahun_ajaran' => $selectedTahunAjaran,
                    'units' => $units,
                    'academic_years' => $academicYears,
                    'paralels' => $paralels,
                    'selected_month' => $month,
                    'available_months' => $availableMonths,
                    'hari_efektif_unit' => $hariEfektifUnit,
                    'unit_active_days' => $unitActiveDays,
                    'has_raport_data' => $hasAnyRaportCurrentTa,
                    'total_students' => count($normalizedStudents),
                    'ambiguous_notes_count' => count($ambiguousNotesPool),
                    'server_time' => now()->toIso8601String(),
                ],
                'students' => $normalizedStudents,
                'ambiguous_notes' => $ambiguousNotesPool,
                'guru_journals' => $guruJournals,
            ]);

        } catch (\Throwable $e) {
            Log::error('PerformanceApiController Error (Tahfidz): ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses data capaian Tahfidz.'
            ], 500);
        }
    }
}
