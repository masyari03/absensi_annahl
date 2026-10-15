<?php
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

requireLogin();

$currentUserId = currentUserId();
$currentRole = currentRole();
$schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'An-Nahl Islamic School';
$schoolLat  = defined('SCHOOL_LATITUDE') ? SCHOOL_LATITUDE : -6.377038;
$schoolLng  = defined('SCHOOL_LONGITUDE') ? SCHOOL_LONGITUDE : 106.945892;
$schoolRadius = defined('SCHOOL_RADIUS_METERS') ? SCHOOL_RADIUS_METERS : 250;

// Cari profil staff yang terhubung dengan akun ini
$stmtStaff = $pdo->prepare("
    SELECT s.*, u.unit AS unit_name 
    FROM staff s 
    LEFT JOIN units u ON u.id = s.unit_id 
    WHERE s.user_id = ? AND s.deleted_at IS NULL 
    LIMIT 1
");
$stmtStaff->execute([$currentUserId]);
$userStaff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

// Daftar semua staff untuk admin / super_admin jika diperlukan
$allStaff = [];
if (in_array($currentRole, ['super_admin', 'admin'])) {
    $stmtAll = $pdo->query("SELECT s.id, s.name, s.nik, u.unit AS unit_name FROM staff s LEFT JOIN units u ON u.id = s.unit_id WHERE s.deleted_at IS NULL ORDER BY s.name ASC");
    $allStaff = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
}

$selectedStaffId = (int)($_GET['staff_id'] ?? ($userStaff['id'] ?? 0));
$activeStaff = null;
if ($selectedStaffId > 0) {
    $stmtCur = $pdo->prepare("SELECT s.*, u.unit AS unit_name FROM staff s LEFT JOIN units u ON u.id = s.unit_id WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1");
    $stmtCur->execute([$selectedStaffId]);
    $activeStaff = $stmtCur->fetch(PDO::FETCH_ASSOC);
}

// Proses POST Absensi GPS
$result = null;
$postError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $staffId = (int)($_POST['staff_id'] ?? 0);
    $lat = (float)($_POST['latitude'] ?? 0);
    $lng = (float)($_POST['longitude'] ?? 0);
    $selfie = trim($_POST['selfie_image'] ?? '');

    // Cek hak akses
    if ($currentRole === 'staff' && (!$userStaff || (int)$userStaff['id'] !== $staffId)) {
        $postError = 'Anda hanya dapat melakukan absensi untuk akun staff Anda sendiri.';
    } elseif ($staffId <= 0) {
        $postError = 'Pilih staff terlebih dahulu.';
    } elseif ($lat == 0 || $lng == 0) {
        $postError = 'Koordinat GPS tidak valid. Pastikan izin lokasi browser telah diaktifkan.';
    } else {
        $res = \App\Services\AttendanceService::processStaffGpsAttendance(
            $pdo,
            $staffId,
            $lat,
            $lng,
            $selfie ?: null,
            $currentUserId
        );

        if ($res['success']) {
            $result = $res;
            logAudit(
                $pdo,
                'gps_attendance',
                'staff_attendances',
                $staffId,
                ['lat' => $lat, 'lng' => $lng, 'distance' => $res['distance'] ?? 0, 'scan_type' => $res['scan_type'] ?? '']
            );
            setFlash('success', 'Absensi berhasil: ' . ($res['message'] ?? 'Data tersimpan.'));
        } else {
            $postError = $res['message'] ?? 'Gagal melakukan absensi GPS.';
        }
    }
}

// Ambil riwayat absen hari ini untuk staff terpilih
$todayRecord = null;
if ($activeStaff) {
    $stmtToday = $pdo->prepare("
        SELECT sa.*, a.staff_in, a.staff_out, a.name AS activity_name
        FROM staff_attendances sa
        LEFT JOIN activities a ON a.id = sa.activity_id
        WHERE sa.staff_id = ? AND sa.attendance_date = ? 
        LIMIT 1
    ");
    $stmtToday->execute([$activeStaff['id'], date('Y-m-d')]);
    $todayRecord = $stmtToday->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Absensi Mandiri GPS Staff';
require_once '../../includes/header.php';
?>

<div class="row">
    <div class="col-12" style="max-width: 850px; margin: 0 auto;">
        
        <div class="card" style="margin-bottom: 20px; border-left: 5px solid #4f46e5;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="margin: 0; font-size: 20px; color: #1e293b;">📍 Presensi Mobile GPS Staff</h2>
                    <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13.5px;">
                        Lokasi: <strong><?= e($schoolName) ?></strong> &bull; Radius Maks: <strong><?= e($schoolRadius) ?> meter</strong>
                    </p>
                </div>
                <div style="text-align: right;">
                    <div id="liveClock" style="font-size: 20px; font-weight: 700; color: #0f172a; font-family: monospace;">--:--:--</div>
                    <span style="font-size: 12px; color: #64748b;"><?= date('l, d F Y') ?></span>
                </div>
            </div>
        </div>

        <?php if ($postError): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                ⛔ <?= e($postError) ?>
            </div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="card" style="margin-bottom: 25px; background: #f0fdf4; border: 1px solid #86efac; text-align: center; padding: 25px;">
                <div style="font-size: 48px; line-height: 1; margin-bottom: 10px;">✅</div>
                <h3 style="color: #166534; margin: 0 0 6px 0;">Presensi Berhasil Dicatat!</h3>
                <p style="color: #15803d; font-size: 14px; margin-bottom: 14px;"><?= e($result['message'] ?? '') ?></p>
                
                <div style="display: inline-block; background: #ffffff; padding: 12px 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: left;">
                    <div><strong>Staff:</strong> <?= e($result['name'] ?? '') ?> (<?= e($result['nik'] ?? '') ?>)</div>
                    <div><strong>Waktu:</strong> <?= e($result['time'] ?? '') ?></div>
                    <div><strong>Status:</strong> <span class="badge badge-success"><?= e($result['badge_text'] ?? '') ?></span></div>
                    <div><strong>Jarak:</strong> <?= e($result['distance'] ?? 0) ?> meter dari sekolah</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pilih Staff jika role Admin & belum ada staff terpilih -->
        <?php if (in_array($currentRole, ['super_admin', 'admin']) && count($allStaff) > 0): ?>
            <div class="card" style="margin-bottom: 20px;">
                <label style="font-weight: 600; display: block; margin-bottom: 8px;">Pilih Akun Staff / Guru:</label>
                <form method="GET" action="" style="display: flex; gap: 10px;">
                    <select name="staff_id" class="form-control" onchange="this.form.submit()" style="padding: 9px 12px; border-radius: 8px; border: 1px solid #cbd5e1; flex: 1;">
                        <option value="">-- Pilih Nama Staff --</option>
                        <?php foreach ($allStaff as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= ($activeStaff && $activeStaff['id'] == $st['id']) ? 'selected' : '' ?>>
                                <?= e($st['name']) ?> (NIK: <?= e($st['nik']) ?>) - <?= e($st['unit_name'] ?? 'Umum') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$activeStaff): ?>
            <div class="card" style="text-align: center; padding: 30px;">
                <div style="font-size: 40px; margin-bottom: 10px;">👤</div>
                <h3>Profil Staff Belum Terhubung</h3>
                <p style="color: #64748b; max-width: 450px; margin: 0 auto 15px auto;">
                    Akun login Anda belum tertaut ke data master Staff. Silakan hubungi Super Admin untuk menautkan user_id ke tabel Staff.
                </p>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary">Kembali ke Dashboard</a>
            </div>
        <?php else: ?>

            <!-- Status Presensi Hari Ini -->
            <div class="card" style="margin-bottom: 20px; background: #f8fafc;">
                <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                    <div style="width: 54px; height: 54px; border-radius: 50%; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: bold; overflow: hidden; flex-shrink: 0;">
                        <?php if (!empty($activeStaff['photo'])): ?>
                            <img src="<?= BASE_URL ?>/../uploads/staff/<?= e($activeStaff['photo']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <?= strtoupper(substr($activeStaff['name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <h3 style="margin: 0; font-size: 17px; color: #0f172a;"><?= e($activeStaff['name']) ?></h3>
                        <p style="margin: 2px 0 0 0; font-size: 13px; color: #64748b;">
                            NIK: <strong><?= e($activeStaff['nik']) ?></strong> &bull; Unit: <strong><?= e($activeStaff['unit_name'] ?? '-') ?></strong>
                        </p>
                    </div>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <div style="background: #ffffff; padding: 8px 14px; border-radius: 8px; border: 1px solid #e2e8f0; text-align: center;">
                            <div style="font-size: 11px; color: #64748b;">Jam Masuk</div>
                            <div style="font-weight: 700; color: #0f172a; font-size: 14px;">
                                <?= !empty($todayRecord['time_in']) ? e(substr($todayRecord['time_in'], 0, 5)) : '-' ?>
                            </div>
                        </div>
                        <div style="background: #ffffff; padding: 8px 14px; border-radius: 8px; border: 1px solid #e2e8f0; text-align: center;">
                            <div style="font-size: 11px; color: #64748b;">Jam Pulang</div>
                            <div style="font-weight: 700; color: #0f172a; font-size: 14px;">
                                <?= !empty($todayRecord['time_out']) ? e(substr($todayRecord['time_out'], 0, 5)) : '-' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Presensi GPS & Kamera Selfie -->
            <div class="card" style="padding: 24px;">
                <form method="POST" id="gpsAttendanceForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="staff_id" value="<?= $activeStaff['id'] ?>">
                    <input type="hidden" name="latitude" id="inputLatitude" value="">
                    <input type="hidden" name="longitude" id="inputLongitude" value="">
                    <input type="hidden" name="selfie_image" id="inputSelfieImage" value="">

                    <!-- Kotak Status GPS & Geofence -->
                    <div style="background: #f1f5f9; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600; font-size: 13.5px; color: #334155;">📡 Status Geolocation</span>
                            <button type="button" id="btnRefreshGps" style="border: none; background: #e2e8f0; padding: 4px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; color: #1e293b;">🔄 Segarkan Lokasi</button>
                        </div>
                        <div id="gpsStatusBox" style="font-size: 13px; color: #475569;">
                            <span class="spinner" style="display: inline-block; width: 12px; height: 12px; border: 2px solid #cbd5e1; border-top-color: #4f46e5; border-radius: 50%; animation: spin 0.8s linear infinite;"></span>
                            Sedang mendeteksi sinyal GPS Anda...
                        </div>
                        <div id="distanceBadge" style="margin-top: 10px; display: none; padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 600;"></div>
                    </div>

                    <!-- Kamera Selfie Live Snapshot -->
                    <div style="margin-bottom: 20px;">
                        <label style="font-weight: 600; font-size: 13.5px; color: #334155; display: block; margin-bottom: 8px;">
                            📷 Foto Selfie Presensi (Real-time):
                        </label>
                        <div style="position: relative; max-width: 380px; margin: 0 auto; border-radius: 16px; overflow: hidden; background: #000; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                            <video id="selfieVideo" autoplay playsinline muted style="width: 100%; height: 260px; object-fit: cover; transform: scaleX(-1); display: block;"></video>
                            <img id="selfiePreview" style="width: 100%; height: 260px; object-fit: cover; display: none;">
                            <canvas id="selfieCanvas" width="480" height="480" style="display: none;"></canvas>
                        </div>
                        <div style="text-align: center; margin-top: 10px;">
                            <button type="button" id="btnSnapSelfie" class="btn" style="background: #e2e8f0; color: #0f172a; padding: 6px 14px; font-size: 12.5px; border-radius: 6px;">📸 Ambil Ulang Foto</button>
                        </div>
                    </div>

                    <!-- Tombol Aksi Submit -->
                    <button type="submit" id="btnSubmitAttendance" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; border-radius: 10px;" disabled>
                        ⏳ Mengambil Posisi GPS...
                    </button>
                </form>
            </div>

        <?php endif; ?>

    </div>
</div>

<script>
(function() {
    'use strict';

    // Konfigurasi Target Sekolah dari Server
    const TARGET_LAT = <?= json_encode($schoolLat) ?>;
    const TARGET_LNG = <?= json_encode($schoolLng) ?>;
    const MAX_RADIUS = <?= json_encode($schoolRadius) ?>;

    // Jam Real-time
    function updateClock() {
        const el = document.getElementById('liveClock');
        if (!el) return;
        const now = new Date();
        el.innerText = now.toTimeString().split(' ')[0];
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Elemen DOM
    const inputLat = document.getElementById('inputLatitude');
    const inputLng = document.getElementById('inputLongitude');
    const inputSelfie = document.getElementById('inputSelfieImage');
    const gpsStatusBox = document.getElementById('gpsStatusBox');
    const distanceBadge = document.getElementById('distanceBadge');
    const btnSubmit = document.getElementById('btnSubmitAttendance');
    const btnRefreshGps = document.getElementById('btnRefreshGps');
    const selfieVideo = document.getElementById('selfieVideo');
    const selfiePreview = document.getElementById('selfiePreview');
    const selfieCanvas = document.getElementById('selfieCanvas');
    const btnSnapSelfie = document.getElementById('btnSnapSelfie');
    const form = document.getElementById('gpsAttendanceForm');

    let currentLat = null;
    let currentLng = null;
    let currentDist = null;
    let videoStream = null;

    // Haversine Formula (Meter)
    function calculateHaversine(lat1, lon1, lat2, lon2) {
        const R = 6371000;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return Math.round(R * c);
    }

    // Inisialisasi Kamera Depan Selfie
    async function initSelfieCamera() {
        if (!selfieVideo) return;
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                videoStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } },
                    audio: false
                });
                selfieVideo.srcObject = videoStream;
            }
        } catch (e) {
            console.warn('Gagal akses kamera selfie:', e);
            if (gpsStatusBox) {
                gpsStatusBox.innerHTML += '<br><small style="color:#dc2626;">Kamera selfie tidak dapat diakses.</small>';
            }
        }
    }

    // Ambil Snapshot dari Video
    function captureSnapshot() {
        if (!selfieVideo || !selfieCanvas) return '';
        try {
            const ctx = selfieCanvas.getContext('2d');
            selfieCanvas.width = selfieVideo.videoWidth || 480;
            selfieCanvas.height = selfieVideo.videoHeight || 480;
            // Mirror flip agar sesuai preview
            ctx.translate(selfieCanvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(selfieVideo, 0, 0, selfieCanvas.width, selfieCanvas.height);
            return selfieCanvas.toDataURL('image/jpeg', 0.85);
        } catch (e) {
            return '';
        }
    }

    if (btnSnapSelfie) {
        btnSnapSelfie.addEventListener('click', function() {
            if (selfiePreview.style.display === 'block') {
                selfiePreview.style.display = 'none';
                selfieVideo.style.display = 'block';
                inputSelfie.value = '';
                btnSnapSelfie.innerText = '📸 Jepret Foto Preview';
            } else {
                const dataUrl = captureSnapshot();
                if (dataUrl) {
                    inputSelfie.value = dataUrl;
                    selfiePreview.src = dataUrl;
                    selfiePreview.style.display = 'block';
                    selfieVideo.style.display = 'none';
                    btnSnapSelfie.innerText = '🔄 Batal & Foto Ulang';
                }
            }
        });
    }

    // Deteksi GPS
    function acquireLocation() {
        if (!navigator.geolocation) {
            if (gpsStatusBox) {
                gpsStatusBox.innerHTML = '<span style="color:#dc2626;">Browser Anda tidak mendukung fitur Geolocation GPS.</span>';
            }
            return;
        }

        if (gpsStatusBox) {
            gpsStatusBox.innerHTML = 'Sedang mencari koordinat GPS presisi tinggi...';
        }
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerText = '⏳ Mengambil Koordinat GPS...';
        }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                currentLat = pos.coords.latitude;
                currentLng = pos.coords.longitude;
                const accuracy = Math.round(pos.coords.accuracy);

                if (inputLat) inputLat.value = currentLat;
                if (inputLng) inputLng.value = currentLng;

                currentDist = calculateHaversine(currentLat, currentLng, TARGET_LAT, TARGET_LNG);

                if (gpsStatusBox) {
                    gpsStatusBox.innerHTML = `
                        <strong>Koordinat:</strong> ${currentLat.toFixed(6)}, ${currentLng.toFixed(6)} 
                        <span style="color:#64748b;">(Akurasi ±${accuracy}m)</span>
                    `;
                }

                if (distanceBadge) {
                    distanceBadge.style.display = 'block';
                    if (currentDist <= MAX_RADIUS) {
                        distanceBadge.style.background = '#dcfce7';
                        distanceBadge.style.color = '#15803d';
                        distanceBadge.style.border = '1px solid #86efac';
                        distanceBadge.innerHTML = `✅ <strong>DALAM RADIUS ABSENSI</strong>: Jarak ~${currentDist} meter dari sekolah (Maks: ${MAX_RADIUS}m)`;
                        if (btnSubmit) {
                            btnSubmit.disabled = false;
                            btnSubmit.innerText = '🚀 Kirim Presensi Sekarang';
                            btnSubmit.classList.remove('btn-danger');
                            btnSubmit.classList.add('btn-primary');
                        }
                    } else {
                        distanceBadge.style.background = '#fee2e2';
                        distanceBadge.style.color = '#b91c1c';
                        distanceBadge.style.border = '1px solid #fca5a5';
                        distanceBadge.innerHTML = `⛔ <strong>DI LUAR RADIUS</strong>: Jarak ~${currentDist} meter dari sekolah. Anda harus berada dalam radius ${MAX_RADIUS}m untuk presensi.`;
                        if (btnSubmit) {
                            btnSubmit.disabled = true;
                            btnSubmit.innerText = `⛔ Di Luar Radius (${currentDist}m > ${MAX_RADIUS}m)`;
                        }
                    }
                }
            },
            function(err) {
                let msg = 'Gagal mendeteksi lokasi GPS.';
                if (err.code === 1) msg = 'Izin akses lokasi ditolak oleh pengguna/browser. Mohon izinkan akses GPS di pengaturan browser.';
                else if (err.code === 2) msg = 'Sinyal lokasi GPS tidak tersedia.';
                else if (err.code === 3) msg = 'Waktu permintaan lokasi habis (timeout).';

                if (gpsStatusBox) {
                    gpsStatusBox.innerHTML = `<span style="color:#dc2626; font-weight:600;">⛔ ${msg}</span>`;
                }
                if (btnSubmit) {
                    btnSubmit.disabled = true;
                    btnSubmit.innerText = '⛔ Akses Lokasi Diperlukan';
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 12000,
                maximumAge: 0
            }
        );
    }

    if (btnRefreshGps) {
        btnRefreshGps.addEventListener('click', acquireLocation);
    }

    // Submit handler
    if (form) {
        form.addEventListener('submit', function(e) {
            // Otomatis jepret snapshot jika belum diambil
            if (!inputSelfie.value && selfieVideo && selfieVideo.srcObject) {
                const autoSnap = captureSnapshot();
                if (autoSnap) inputSelfie.value = autoSnap;
            }

            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerText = 'Mengirim data presensi...';
            }
        });
    }

    // Start kamera & GPS
    initSelfieCamera();
    acquireLocation();

})();
</script>

<?php require_once '../../includes/footer.php'; ?>
