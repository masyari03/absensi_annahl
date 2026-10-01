<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('iot_devices');
$currentRole = currentRole();
$pageTitle = 'Manajemen Perangkat ESP32 / IoT';
$userId = currentUserId();

$isSuperAdmin = ($currentRole === 'super_admin');
$isKepsek = ($currentRole === 'kepala_sekolah');

// Penguncian Unit Kepala Sekolah
$ksUnitId = null;
if ($isKepsek) {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// PROSES POST ACTION
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH PERANGKAT BARU
    if ($action === 'create_device') {
        $devId = strtoupper(trim($_POST['device_id'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $unitId = (int)($_POST['unit_id'] ?? 0);

        if ($isKepsek && $ksUnitId) {
            $unitId = $ksUnitId;
        }

        if (empty($devId) || empty($name) || $unitId <= 0) {
            flash('error', 'Semua field wajib diisi.');
            redirect('index.php');
        }

        // Generate Device Token unik
        $token = 'TOK-' . strtoupper(substr(md5(uniqid($devId, true)), 0, 16));

        try {
            $stmt = $pdo->prepare("
                INSERT INTO iot_devices (device_id, name, unit_id, device_token, device_type, is_active)
                VALUES (?, ?, ?, ?, 'rfid_fingerprint', 1)
            ");
            $stmt->execute([$devId, $name, $unitId, $token]);
            flash('success', "Perangkat ESP32 [{$devId}] berhasil didaftarkan.");
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', 'Gagal mendaftarkan perangkat: ' . $e->getMessage());
            redirect('index.php');
        }
    }

    // 2. EDIT PERANGKAT
    if ($action === 'edit_device') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($isKepsek && $ksUnitId) {
            $unitId = $ksUnitId;
        }

        try {
            $stmt = $pdo->prepare("UPDATE iot_devices SET name = ?, unit_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $unitId, $isActive, $id]);
            flash('success', 'Data perangkat berhasil diperbarui.');
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', 'Gagal memperbarui perangkat: ' . $e->getMessage());
            redirect('index.php');
        }
    }

    // 3. GENERATE TOKEN BARU
    if ($action === 'regenerate_token') {
        $id = (int)($_POST['id'] ?? 0);
        $newToken = 'TOK-' . strtoupper(substr(md5(uniqid('dev', true)), 0, 16));

        try {
            $stmt = $pdo->prepare("UPDATE iot_devices SET device_token = ? WHERE id = ?");
            $stmt->execute([$newToken, $id]);
            flash('success', 'Token baru berhasil dibuat. Pastikan Anda memperbarui token di pengaturan ESP32!');
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', 'Gagal membuat token baru.');
            redirect('index.php');
        }
    }

    // 4. HAPUS PERANGKAT
    if ($action === 'delete_device') {
        if (!$isSuperAdmin) {
            flash('error', 'Hanya Super Admin yang berhak menghapus perangkat.');
            redirect('index.php');
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM iot_devices WHERE id = ?")->execute([$id]);
            flash('success', 'Perangkat berhasil dihapus.');
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', 'Gagal menghapus perangkat.');
            redirect('index.php');
        }
    }
}

// QUERY PERANGKAT
$devWhere = [];
$devParams = [];
if ($isKepsek && $ksUnitId) {
    $devWhere[] = "d.unit_id = ?";
    $devParams[] = $ksUnitId;
}
$devWhereSql = !empty($devWhere) ? "WHERE " . implode(' AND ', $devWhere) : "";

$stmtDevs = $pdo->prepare("
    SELECT d.*, u.unit as unit_name
    FROM iot_devices d
    LEFT JOIN units u ON u.id = d.unit_id
    {$devWhereSql}
    ORDER BY d.unit_id ASC, d.id ASC
");
$stmtDevs->execute($devParams);
$devices = $stmtDevs->fetchAll(PDO::FETCH_ASSOC);

// Ambil 15 Log Scan Terakhir
$logWhere = [];
$logParams = [];
if ($isKepsek && $ksUnitId) {
    $logWhere[] = "l.unit_id = ?";
    $logParams[] = $ksUnitId;
}
$logWhereSql = !empty($logWhere) ? "WHERE " . implode(' AND ', $logWhere) : "";

$stmtLogs = $pdo->prepare("
    SELECT l.*, d.name as device_name, u.unit as unit_name
    FROM iot_attendance_logs l
    LEFT JOIN iot_devices d ON d.device_id = l.device_id
    LEFT JOIN units u ON u.id = l.unit_id
    {$logWhereSql}
    ORDER BY l.id DESC
    LIMIT 15
");
$stmtLogs->execute($logParams);
$recentLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="flex-wrap: wrap; gap: 15px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="background: linear-gradient(135deg, #0284c7, #2563eb); color: white; padding: 6px 12px; border-radius: 8px; font-size: 14px; font-weight: 700;">
                    <i class="fa-solid fa-microchip"></i> IoT & Hardware
                </span>
                <h3 style="margin: 0;">Manajemen Perangkat ESP32 (RFID & Fingerprint)</h3>
            </div>
            <p style="margin: 6px 0 0 0; color: #64748b; font-size: 13.5px;">
                Kelola mesin absensi hardware ESP32. Setiap unit dapat memiliki 2 &mdash; 4 alat tanpa takut bertabrakan. Staf dapat melakukan presensi di alat mana saja.
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                <i class="fa-solid fa-plus"></i> Tambah Alat ESP32
            </button>
            <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="document.getElementById('modalSetupInfo').style.display='flex'">
                <i class="fa-solid fa-book-open"></i> Panduan Setup & API
            </button>
        </div>
    </div>
</div>

<!-- GRID DAFTAR PERANGKAT ESP32 -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <?php foreach ($devices as $dev): 
        $lastSeenTs = !empty($dev['last_seen']) ? strtotime($dev['last_seen']) : 0;
        $isOnline = ($lastSeenTs > 0 && (time() - $lastSeenTs) <= 180); // Online jika ping < 3 menit
    ?>
    <div class="card" style="margin: 0; display: flex; flex-direction: column; justify-content: space-between; border-top: 4px solid <?= $isOnline ? '#10b981' : '#94a3b8' ?>;">
        <div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                <div>
                    <div style="font-weight: 800; font-size: 16px; color: #0f172a;"><?= htmlspecialchars($dev['name']) ?></div>
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px; margin-top: 4px; display: inline-block;">
                        <i class="fa-solid fa-school"></i> <?= htmlspecialchars($dev['unit_name'] ?? 'Semua Unit') ?>
                    </span>
                </div>
                <div>
                    <?php if ($isOnline): ?>
                        <span class="badge" style="background: #dcfce7; color: #166534; font-size: 11px; font-weight: 700;">
                            <i class="fa-solid fa-circle" style="font-size: 8px; color: #16a34a; margin-right: 4px;"></i> Online
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background: #f1f5f9; color: #64748b; font-size: 11px;">
                            <i class="fa-regular fa-circle" style="font-size: 8px; margin-right: 4px;"></i> Offline
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: #f8fafc; border-radius: 10px; padding: 12px; margin-bottom: 14px; font-size: 12.5px; border: 1px solid #e2e8f0;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: #64748b;">Device ID:</span>
                    <code style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($dev['device_id']) ?></code>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: #64748b;">Device Token:</span>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <code id="tok-<?= $dev['id'] ?>" style="background: #e2e8f0; padding: 1px 6px; border-radius: 4px;"><?= substr($dev['device_token'], 0, 8) ?>••••••</code>
                        <button type="button" onclick="copyToken('<?= htmlspecialchars($dev['device_token']) ?>')" style="background: none; border: none; cursor: pointer; color: #0284c7; padding: 0;" title="Salin Token">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: #64748b;">IP Address:</span>
                    <span style="color: #334155; font-weight: 600;"><?= htmlspecialchars($dev['ip_address'] ?: 'Belum terhubung') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">Aktivitas Terakhir:</span>
                    <span style="color: #334155; font-weight: 600;"><?= !empty($dev['last_seen']) ? date('d/m/Y H:i:s', strtotime($dev['last_seen'])) : 'Belum ada ping' ?></span>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 6px; border-top: 1px solid #f1f5f9; padding-top: 12px; justify-content: flex-end;">
            <form method="POST" onsubmit="return confirm('Buat token baru untuk perangkat ini? Token lama pada firmware ESP32 akan kadaluarsa!');" style="margin: 0;">
                <input type="hidden" name="action" value="regenerate_token">
                <input type="hidden" name="id" value="<?= $dev['id'] ?>">
                <button type="submit" class="btn" style="padding: 6px 10px; font-size: 11.5px; background: #fff; border: 1px solid #cbd5e1; color: #475569;" title="Generate Token Baru">
                    <i class="fa-solid fa-arrows-rotate"></i> Reset Token
                </button>
            </form>

            <button type="button" class="btn" style="padding: 6px 10px; font-size: 11.5px; background: #0284c7; color: white;" onclick="openEditModal(<?= htmlspecialchars(json_encode($dev)) ?>)">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </button>

            <?php if ($isSuperAdmin): ?>
            <form method="POST" onsubmit="return confirm('Hapus perangkat ESP32 ini?');" style="margin: 0;">
                <input type="hidden" name="action" value="delete_device">
                <input type="hidden" name="id" value="<?= $dev['id'] ?>">
                <button type="submit" class="btn" style="padding: 6px 10px; font-size: 11.5px; background: #ef4444; color: white; border: none;">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- LOG MONITOR SCAN ESP32 REALTIME -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 style="margin: 0; font-size: 16px;"><i class="fa-solid fa-wave-square" style="color: #10b981;"></i> Log Pemindaian Terakhir (ESP32 Live Feed)</h3>
            <small>Memantau transaksi dan ping tap kartu RFID / sidik jari yang diterima server.</small>
        </div>
        <button type="button" class="btn" style="background: #f1f5f9; color: #475569; font-size: 12px;" onclick="location.reload()">
            <i class="fa-solid fa-rotate-right"></i> Refresh Feed
        </button>
    </div>

    <div class="table-wrapper" style="margin-top: 15px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="width: 140px;">Waktu</th>
                    <th>Perangkat</th>
                    <th>Unit</th>
                    <th>Sensor</th>
                    <th>Kode Scan</th>
                    <th>Tipe User</th>
                    <th>Status</th>
                    <th>Respon Mesin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 25px; color: #94a3b8;">Belum ada log scan ESP32.</td></tr>
                <?php endif; ?>

                <?php foreach ($recentLogs as $log): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td><small style="color: #64748b;"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></small></td>
                    <td><b><?= htmlspecialchars($log['device_name'] ?: $log['device_id']) ?></b></td>
                    <td><span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:10.5px;"><?= htmlspecialchars($log['unit_name'] ?? '-') ?></span></td>
                    <td>
                        <?php if ($log['scan_type'] === 'rfid'): ?>
                            <span style="color: #6366f1; font-weight: 600;"><i class="fa-solid fa-id-card"></i> RFID</span>
                        <?php else: ?>
                            <span style="color: #0ea5e9; font-weight: 600;"><i class="fa-solid fa-fingerprint"></i> Fingerprint</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($log['scan_code']) ?></code></td>
                    <td>
                        <span style="text-transform: capitalize; font-weight: 600;">
                            <?= htmlspecialchars($log['user_type']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($log['status'] === 'success'): ?>
                            <span style="color: #16a34a; font-weight: 700;"><i class="fa-solid fa-check"></i> Sukses</span>
                        <?php elseif ($log['status'] === 'cooldown'): ?>
                            <span style="color: #d97706; font-weight: 600;"><i class="fa-solid fa-hourglass-half"></i> Cooldown</span>
                        <?php else: ?>
                            <span style="color: #dc2626; font-weight: 700;"><i class="fa-solid fa-xmark"></i> Ditolak</span>
                        <?php endif; ?>
                    </td>
                    <td style="color: #475569; font-size: 12px;"><?= htmlspecialchars($log['response_message']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL TAMBAH PERANGKAT -->
<div id="modalCreateDev" class="modal-overlay">
    <div class="modal-box" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;"><i class="fa-solid fa-microchip" style="color: #0284c7;"></i> Tambah Alat ESP32</h3>
            <button type="button" onclick="document.getElementById('modalCreateDev').style.display='none'" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="create_device">

            <div class="form-group" style="margin-bottom: 14px;">
                <label>Device ID (Unik) *</label>
                <input type="text" name="device_id" placeholder="Contoh: ESP32-UNIT1-DEV3" required style="text-transform: uppercase;">
                <small style="color: #64748b;">ID unik alat untuk membedakan mesin 1, 2, 3, dan 4 pada setiap unit.</small>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label>Nama / Lokasi Alat *</label>
                <input type="text" name="name" placeholder="Contoh: Pintu Gerbang Timur Unit SD" required>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label>Unit Sekolah *</label>
                <?php if ($isSuperAdmin): ?>
                    <select name="unit_id" required>
                        <option value="">Pilih Unit...</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="unit_id" value="<?= $ksUnitId ?>">
                    <input type="text" value="<?= htmlspecialchars($ksUnitName) ?>" disabled style="background: #f1f5f9;">
                <?php endif; ?>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="document.getElementById('modalCreateDev').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Daftarkan Alat</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT PERANGKAT -->
<div id="modalEditDev" class="modal-overlay">
    <div class="modal-box" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;"><i class="fa-solid fa-pen-to-square" style="color: #0284c7;"></i> Edit Data Alat ESP32</h3>
            <button type="button" onclick="document.getElementById('modalEditDev').style.display='none'" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="edit_device">
            <input type="hidden" name="id" id="editDevId" value="">

            <div class="form-group" style="margin-bottom: 14px;">
                <label>Nama / Lokasi Alat *</label>
                <input type="text" name="name" id="editDevName" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label>Unit Sekolah *</label>
                <?php if ($isSuperAdmin): ?>
                    <select name="unit_id" id="editDevUnit" required>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="unit_id" value="<?= $ksUnitId ?>">
                    <input type="text" value="<?= htmlspecialchars($ksUnitName) ?>" disabled style="background: #f1f5f9;">
                <?php endif; ?>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="is_active" id="editDevActive" value="1" style="width: 18px; height: 18px; accent-color: #10b981;">
                    <span style="font-weight: 600;">Status Perangkat Aktif (Izinkan Menerima Presensi)</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="document.getElementById('modalEditDev').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PANDUAN SETUP & API -->
<div id="modalSetupInfo" class="modal-overlay">
    <div class="modal-box" style="max-width: 620px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;"><i class="fa-solid fa-book-open" style="color: #0284c7;"></i> Panduan Integrasi ESP32</h3>
            <button type="button" onclick="document.getElementById('modalSetupInfo').style.display='none'" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <div style="font-size: 13px; line-height: 1.6; color: #334155; max-height: 480px; overflow-y: auto;">
            <h4 style="margin: 10px 0 6px 0; color: #0f172a;">1. Endpoint REST API Absensi:</h4>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px; margin-bottom: 12px;">
                <code><?= BASE_URL ?>/api/esp32_attendance.php</code>
            </div>

            <h4 style="margin: 10px 0 6px 0; color: #0f172a;">2. Alur Setting Awal (Captive Portal):</h4>
            <ol style="margin-left: 20px; padding: 0;">
                <li>Saat alat pertama kali dinyalakan atau tombol Reset ditekan selama 3 detik, ESP32 memancarkan WiFi Access Point <b>ABSENSI-ESP32-SETUP</b>.</li>
                <li>Hubungkan HP / Laptop ke WiFi tersebut (portal konfigurasi <code>192.168.4.1</code> akan otomatis terbuka).</li>
                <li>Pilih SSID WiFi sekolah, masukkan password, isi URL API di atas, masukkan <b>Device ID</b> dan <b>Device Token</b> dari halaman ini.</li>
                <li>Klik Save. ESP32 otomatis tersambung ke WiFi dan siap memproses presensi!</li>
            </ol>

            <h4 style="margin: 10px 0 6px 0; color: #0f172a;">3. Penataan Hardware & Pin:</h4>
            <p>File source code Arduino <code>.ino</code> dan skema pin lengkap telah kami sediakan di folder: <code>esp32/absensi_esp32.ino</code> di dalam project ini.</p>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
            <button type="button" class="btn btn-primary" onclick="document.getElementById('modalSetupInfo').style.display='none'">Mengerti</button>
        </div>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalCreateDev').style.display = 'flex';
}

function openEditModal(dev) {
    document.getElementById('editDevId').value = dev.id;
    document.getElementById('editDevName').value = dev.name;
    const unitSelect = document.getElementById('editDevUnit');
    if (unitSelect) unitSelect.value = dev.unit_id;
    document.getElementById('editDevActive').checked = (parseInt(dev.is_active) === 1);
    document.getElementById('modalEditDev').style.display = 'flex';
}

function copyToken(token) {
    navigator.clipboard.writeText(token).then(() => {
        alert('Device Token berhasil disalin ke clipboard:\n' + token);
    });
}
</script>

<?php require '../../includes/footer.php'; ?>
