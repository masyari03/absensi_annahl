<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('activity_logs');

$currentUserId = currentUserId();
$currentRole = currentRole();
$currentUserName = (function_exists('currentUserName') ? currentUserName() : null) ?: ($_SESSION['name'] ?? $_SESSION['username'] ?? 'Administrator');
$pageTitle = 'Log Aktivitas & Audit Perubahan';

$isSuperAdmin = ($currentRole === 'super_admin');
$isKepsek = ($currentRole === 'kepala_sekolah');

// Cek Unit Kepala Sekolah
$ksUnitId = null;
$ksUnitName = '';
if ($isKepsek) {
    $stmtKs = $pdo->prepare("
        SELECT aup.unit_id, u.unit 
        FROM admin_unit_permissions aup
        JOIN units u ON u.id = aup.unit_id
        WHERE aup.user_id = ? LIMIT 1
    ");
    $stmtKs->execute([$currentUserId]);
    $ksRow = $stmtKs->fetch(PDO::FETCH_ASSOC);
    if ($ksRow) {
        $ksUnitId = (int)$ksRow['unit_id'];
        $ksUnitName = $ksRow['unit'];
    }
}

// =========================================================================
// PROSES AKSI: ROLLBACK & HAPUS LOG (SUPER ADMIN ONLY)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $logId = (int)($_POST['id'] ?? 0);

    if ($action === 'rollback') {
        if (!$isSuperAdmin) {
            flash('error', 'Hanya Super Admin yang berhak melakukan rollback data.');
            redirect('audit_logs.php');
        }

        $result = rollbackActivityAudit($pdo, $logId, $currentUserName);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        redirect('audit_logs.php?' . http_build_query($_GET));
    }

    if ($action === 'delete_log') {
        if (!$isSuperAdmin) {
            flash('error', 'Hanya Super Admin yang berhak menghapus log aktivitas.');
            redirect('audit_logs.php');
        }

        if ($logId > 0) {
            $stmtDel = $pdo->prepare("
                UPDATE activity_audit_logs 
                SET is_deleted_by_superadmin = 1, deleted_at = NOW() 
                WHERE id = ?
            ");
            $stmtDel->execute([$logId]);
            flash('success', 'Log aktivitas berhasil dihapus dari tampilan.');
        }
        redirect('audit_logs.php?' . http_build_query($_GET));
    }
}

// =========================================================================
// QUERY FILTER LOG
// =========================================================================
$filterUnit = (int)($_GET['unit_id'] ?? 0);
$filterAction = trim($_GET['action_type'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$filterDate = trim($_GET['date'] ?? '');
$searchKeyword = trim($_GET['q'] ?? '');

$whereSql = ["aal.is_deleted_by_superadmin = 0"];
$params = [];

if ($isKepsek) {
    if ($ksUnitId) {
        $whereSql[] = "aal.unit_id = ?";
        $params[] = $ksUnitId;
    } else {
        $whereSql[] = "1 = 0";
    }
} elseif ($filterUnit > 0) {
    $whereSql[] = "aal.unit_id = ?";
    $params[] = $filterUnit;
}

if (!empty($filterAction)) {
    $whereSql[] = "aal.action = ?";
    $params[] = $filterAction;
}

if (!empty($filterModule)) {
    $whereSql[] = "aal.module = ?";
    $params[] = $filterModule;
}

if (!empty($filterDate)) {
    $whereSql[] = "DATE(aal.created_at) = ?";
    $params[] = $filterDate;
}

if (!empty($searchKeyword)) {
    $whereSql[] = "(aal.username LIKE ? OR aal.module LIKE ? OR aal.record_id LIKE ? OR aal.ip_address LIKE ?)";
    $kw = "%{$searchKeyword}%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$whereClause = "WHERE " . implode(" AND ", $whereSql);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM activity_audit_logs aal {$whereClause}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmtLogs = $pdo->prepare("
    SELECT aal.*, u.unit as unit_name
    FROM activity_audit_logs aal
    LEFT JOIN units u ON u.id = aal.unit_id
    {$whereClause}
    ORDER BY aal.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Fetch modules for filter dropdown
$distinctModules = $pdo->query("SELECT DISTINCT module FROM activity_audit_logs WHERE module IS NOT NULL AND module != '' ORDER BY module ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch units for filter dropdown
$unitsList = $pdo->query("SELECT id, unit FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0 0 4px 0;">
                <i class="fa-solid fa-clock-rotate-left" style="color:#0284c7; margin-right:8px;"></i> Log Aktivitas & Audit Perubahan
            </h3>
            <small class="text-muted">
                Riwayat perubahan data penting sistem, penambahan, pengeditan, dan penghapusan data 
                <?= $isKepsek ? "(Khusus Unit: " . e($ksUnitName) . ")" : "(Seluruh Unit)" ?>
            </small>
        </div>
        <?php if ($isSuperAdmin): ?>
            <div style="font-size:12px; color:#64748b; background:#f1f5f9; padding:6px 12px; border-radius:8px; border:1px solid #cbd5e1;">
                <i class="fa-solid fa-shield-halved" style="color:#0284c7;"></i> Super Admin: Dapat melakukan Rollback & Hapus Log
            </div>
        <?php endif; ?>
    </div>

    <!-- FILTER BAR -->
    <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <form method="GET" action="audit_logs.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
            <?php if (!$isKepsek): ?>
            <div style="flex: 1; min-width: 150px;">
                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Unit Sekolah:</label>
                <select name="unit_id" class="form-control" style="font-size:13px; padding:6px 10px; border-radius:6px; width:100%;">
                    <option value="">-- Semua Unit --</option>
                    <?php foreach ($unitsList as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUnit === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['unit']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div style="flex: 1; min-width: 140px;">
                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Jenis Aksi:</label>
                <select name="action_type" class="form-control" style="font-size:13px; padding:6px 10px; border-radius:6px; width:100%;">
                    <option value="">-- Semua Aksi --</option>
                    <option value="CREATE" <?= $filterAction === 'CREATE' ? 'selected' : '' ?>>🟢 CREATE (Tambah)</option>
                    <option value="UPDATE" <?= $filterAction === 'UPDATE' ? 'selected' : '' ?>>🔵 UPDATE (Ubah)</option>
                    <option value="DELETE" <?= $filterAction === 'DELETE' ? 'selected' : '' ?>>🔴 DELETE (Hapus)</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 160px;">
                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Modul / Fitur:</label>
                <select name="module" class="form-control" style="font-size:13px; padding:6px 10px; border-radius:6px; width:100%;">
                    <option value="">-- Semua Modul --</option>
                    <?php foreach ($distinctModules as $dm): ?>
                        <option value="<?= e($dm) ?>" <?= $filterModule === $dm ? 'selected' : '' ?>><?= e($dm) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 140px;">
                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Tanggal:</label>
                <input type="date" name="date" value="<?= e($filterDate) ?>" class="form-control" style="font-size:13px; padding:6px 10px; border-radius:6px; width:100%;">
            </div>

            <div style="flex: 2; min-width: 180px;">
                <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Pencarian (User / ID):</label>
                <input type="text" name="q" value="<?= e($searchKeyword) ?>" placeholder="Cari nama pengguna, record ID, IP..." class="form-control" style="font-size:13px; padding:6px 10px; border-radius:6px; width:100%;">
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary" style="font-size:13px; padding:7px 16px; border-radius:6px;">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <a href="audit_logs.php" class="btn btn-light" style="font-size:13px; padding:7px 14px; border-radius:6px;">Reset</a>
            </div>
        </form>
    </div>

    <!-- TABEL LOG AKTIVITAS -->
    <div class="table-wrapper">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f1f5f9; text-align:left; border-bottom:2px solid #e2e8f0;">
                    <th style="padding:12px 14px; width:140px;">Waktu</th>
                    <th style="padding:12px 14px;">Pengguna</th>
                    <th style="padding:12px 14px;">Unit</th>
                    <th style="padding:12px 14px;">Modul & Aksi</th>
                    <th style="padding:12px 14px;">Detail Data</th>
                    <th style="padding:12px 14px; text-align:center;">Status Rollback</th>
                    <?php if ($isSuperAdmin): ?>
                        <th style="padding:12px 14px; text-align:center; width:150px;">Aksi Super Admin</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="<?= $isSuperAdmin ? 7 : 6 ?>" style="text-align:center; padding:30px; color:#64748b;">
                            Tidak ada log aktivitas yang cocok dengan kriteria pencarian.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($logs as $log): ?>
                    <?php
                    $isRolledBack = !empty($log['is_rolled_back']);
                    $actionBadge = [
                        'CREATE' => ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'fa-plus', 'text' => 'CREATE'],
                        'UPDATE' => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'icon' => 'fa-pen-to-square', 'text' => 'UPDATE'],
                        'DELETE' => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => 'fa-trash', 'text' => 'DELETE'],
                    ][$log['action']] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-circle-info', 'text' => $log['action']];
                    ?>
                    <tr style="border-bottom:1px solid #e2e8f0; <?= $isRolledBack ? 'background:#fafafa;' : '' ?>">
                        <td style="padding:12px 14px; font-size:12px; color:#475569; white-space:nowrap;">
                            <strong><?= date('d M Y', strtotime($log['created_at'])) ?></strong><br>
                            <span style="font-family:monospace;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                        </td>

                        <td style="padding:12px 14px;">
                            <div style="font-weight:700; color:#1e293b;"><?= e($log['username'] ?: 'Sistem') ?></div>
                            <small style="color:#64748b;">Role: <strong><?= e($log['role'] ?: '-') ?></strong> | IP: <?= e($log['ip_address'] ?: '-') ?></small>
                        </td>

                        <td style="padding:12px 14px;">
                            <?php if (!empty($log['unit_name'])): ?>
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:11px; padding:3px 7px; border-radius:4px; font-weight:600;">
                                    <?= e($log['unit_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size:12px; color:#94a3b8;">Global / Semua</span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:12px 14px;">
                            <span class="badge" style="background:<?= $actionBadge['bg'] ?>; color:<?= $actionBadge['color'] ?>; font-weight:700; font-size:11px; padding:3px 8px; border-radius:4px;">
                                <i class="fa-solid <?= $actionBadge['icon'] ?>"></i> <?= $actionBadge['text'] ?>
                            </span>
                            <div style="font-weight:600; color:#1e293b; margin-top:3px; font-size:13px;">
                                <?= e($log['module'] ?: $log['table_name']) ?>
                            </div>
                            <small style="color:#64748b; font-family:monospace;">ID: <?= e($log['record_id'] ?: '-') ?></small>
                        </td>

                        <td style="padding:12px 14px;">
                            <button type="button" class="btn btn-sm btn-light" 
                                    onclick="viewAuditDiff(<?= htmlspecialchars(json_encode($log), ENT_QUOTES, 'UTF-8') ?>)"
                                    style="font-size:12px; padding:4px 10px; border-radius:6px; border:1px solid #cbd5e1;">
                                <i class="fa-solid fa-code-compare"></i> Lihat Snapshot Data
                            </button>
                        </td>

                        <td style="padding:12px 14px; text-align:center;">
                            <?php if ($isRolledBack): ?>
                                <span class="badge" style="background:#fef3c7; color:#92400e; font-size:11px; padding:4px 8px; border-radius:4px; font-weight:700;">
                                    <i class="fa-solid fa-rotate-left"></i> Telah di-Rollback
                                </span>
                                <div style="font-size:10px; color:#78350f; margin-top:2px;">
                                    <?= e($log['rolled_back_by']) ?><br>
                                    <?= date('d/m/y H:i', strtotime($log['rolled_back_at'])) ?>
                                </div>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9; color:#64748b; font-size:11px; padding:3px 6px; border-radius:4px;">Normal</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($isSuperAdmin): ?>
                        <td style="padding:12px 14px; text-align:center; white-space:nowrap;">
                            <?php if (!$isRolledBack): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin melakukan ROLLBACK untuk aktivitas ini? Data yang diubah/dihapus akan dikembalikan ke kondisi sebelumnya.');">
                                    <input type="hidden" name="action" value="rollback">
                                    <input type="hidden" name="id" value="<?= $log['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" style="font-size:11px; padding:3px 8px; border-radius:4px; cursor:pointer;" title="Kembalikan data ke kondisi sebelum aksi ini">
                                        <i class="fa-solid fa-rotate-left"></i> Rollback
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" style="display:inline; margin-left:4px;" onsubmit="return confirm('Apakah Anda yakin ingin MENGHAPUS log aktivitas ini? Log tidak akan terlihat lagi oleh admin, namun tetap tersimpan di riwayat arsip utama.');">
                                <input type="hidden" name="action" value="delete_log">
                                <input type="hidden" name="id" value="<?= $log['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="font-size:11px; padding:3px 8px; border-radius:4px; cursor:pointer;" title="Hapus log ini">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
        <div style="padding:14px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:13px; color:#64748b;">
                Menampilkan halaman <strong><?= $page ?></strong> dari <strong><?= $totalPages ?></strong> (Total <?= number_format($totalRows) ?> log)
            </div>
            <div style="display:flex; gap:6px;">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm btn-light">Sebelumnya</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm btn-primary">Berikutnya</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL LIHAT SNAPSHOT DATA / DIFF -->
<div id="auditModal" style="display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); justify-content:center; align-items:center;">
    <div style="background:white; border-radius:12px; width:92%; max-width:850px; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 25px -5px rgba(0,0,0,0.25);">
        <div style="padding:16px 22px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h4 id="auditModalTitle" style="margin:0; font-size:16px; color:#0f172a; font-weight:800;">Detail Snapshot Aktivitas</h4>
                <small id="auditModalSub" style="color:#64748b; font-size:12px;"></small>
            </div>
            <button type="button" onclick="closeAuditModal()" style="border:none; background:transparent; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:20px 22px; overflow-y:auto; flex:1;" id="auditModalBody">
            <!-- Dynamic Diff View -->
        </div>
        <div style="padding:12px 22px; border-top:1px solid #e2e8f0; text-align:right;">
            <button type="button" class="btn btn-light" onclick="closeAuditModal()" style="padding:6px 16px; font-size:13px;">Tutup</button>
        </div>
    </div>
</div>

<script>
function viewAuditDiff(log) {
    document.getElementById('auditModalTitle').innerText = 'Detail Snapshot: ' + (log.module || log.table_name) + ' (' + log.action + ')';
    document.getElementById('auditModalSub').innerText = 'Oleh: ' + (log.username || 'Sistem') + ' | ' + log.created_at + ' | IP: ' + (log.ip_address || '-');

    const body = document.getElementById('auditModalBody');
    let oldData = null;
    let newData = null;

    try { oldData = log.old_data ? JSON.parse(log.old_data) : null; } catch (e) { oldData = log.old_data; }
    try { newData = log.new_data ? JSON.parse(log.new_data) : null; } catch (e) { newData = log.new_data; }

    let html = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">';
    
    // OLD DATA
    html += '<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:14px;">';
    html += '<h5 style="margin:0 0 10px 0; color:#991b1b; font-size:13px; font-weight:700;"><i class="fa-solid fa-clock-rotate-left"></i> Data Sebelumnya (Old Data)</h5>';
    if (oldData) {
        html += '<pre style="margin:0; font-size:11.5px; background:white; padding:10px; border-radius:6px; border:1px solid #fed7aa; max-height:300px; overflow:auto; white-space:pre-wrap;">' + JSON.stringify(oldData, null, 2) + '</pre>';
    } else {
        html += '<div style="color:#64748b; font-size:12px; font-style:italic;">Tidak ada data sebelumnya (Penambahan Baru).</div>';
    }
    html += '</div>';

    // NEW DATA
    html += '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px;">';
    html += '<h5 style="margin:0 0 10px 0; color:#166534; font-size:13px; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Data Baru / Sesudah (New Data)</h5>';
    if (newData) {
        html += '<pre style="margin:0; font-size:11.5px; background:white; padding:10px; border-radius:6px; border:1px solid #bbf7d0; max-height:300px; overflow:auto; white-space:pre-wrap;">' + JSON.stringify(newData, null, 2) + '</pre>';
    } else {
        html += '<div style="color:#64748b; font-size:12px; font-style:italic;">Data dihapus (Tidak ada data baru).</div>';
    }
    html += '</div>';

    html += '</div>';

    body.innerHTML = html;
    document.getElementById('auditModal').style.display = 'flex';
}

function closeAuditModal() {
    document.getElementById('auditModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('auditModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php require '../../includes/footer.php'; ?>
