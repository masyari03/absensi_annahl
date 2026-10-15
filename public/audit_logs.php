<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/auth.php';
require_once '../includes/functions.php';

requireSuperAdmin();

$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');
$dateFilter = trim($_GET['date'] ?? '');
$userFilter = trim($_GET['user'] ?? '');

$where = ["1=1"];
$params = [];

if ($actionFilter !== '') {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
}
if ($entityFilter !== '') {
    $where[] = "al.entity_type = ?";
    $params[] = $entityFilter;
}
if ($dateFilter !== '') {
    $where[] = "DATE(al.created_at) = ?";
    $params[] = $dateFilter;
}
if ($userFilter !== '') {
    $where[] = "(u.name LIKE ? OR u.username LIKE ? OR al.username LIKE ?)";
    $params[] = "%{$userFilter}%";
    $params[] = "%{$userFilter}%";
    $params[] = "%{$userFilter}%";
}

$whereSql = implode(' AND ', $where);

// Fitur Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare("
        SELECT al.*, u.name AS user_name, u.username AS user_username, u.role AS user_role
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE {$whereSql}
        ORDER BY al.created_at DESC
        LIMIT 10000
    ");
    $exportStmt->execute($params);
    $exportLogs = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM agar terbaca sempurna di Microsoft Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['ID', 'Waktu (WIB)', 'Nama Pengguna', 'Username', 'Role', 'Aksi', 'Modul / Target', 'ID Target', 'Rincian Data', 'IP Address', 'User Agent']);
    foreach ($exportLogs as $row) {
        $actorName = $row['user_name'] ?: ($row['username'] ?: 'Sistem');
        $actorUser = $row['user_username'] ?: ($row['username'] ?: '-');
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            $actorName,
            $actorUser,
            $row['user_role'] ?: '-',
            $row['action'],
            $row['entity_type'],
            $row['entity_id'] ?: '-',
            $row['details'],
            $row['ip_address'],
            $row['user_agent']
        ]);
    }
    fclose($output);
    exit;
}

// Statistik Ringkas Audit Log
$totalAll = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$deleteCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%delete%'")->fetchColumn();
$uniqueUsers = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(user_id, username)) FROM audit_logs")->fetchColumn();

// Hitung total data untuk paginasi
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT al.*, u.name AS user_name, u.username AS user_username, u.role AS user_role
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE {$whereSql}
    ORDER BY al.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daftar tipe action unik untuk filter dropdown
$distinctActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// Daftar entity_type unik untuk filter dropdown
$distinctEntities = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

// Susun queryParams untuk link pagination & export
$activeQueryParams = array_filter([
    'action' => $actionFilter,
    'entity' => $entityFilter,
    'date'   => $dateFilter,
    'user'   => $userFilter,
]);

$pageTitle = 'Audit Trail & Log Aktivitas';
require_once '../includes/header.php';
?>

<!-- Header & Kartu Statistik -->
<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div>
            <h2 style="margin: 0; font-size: 20px; color: #1e293b;">
                <i class="fa-solid fa-clock-rotate-left me-2" style="color: var(--primary);"></i>Audit Trail & Log Aktivitas
            </h2>
            <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13.5px;">
                Catatan komprehensif seluruh aktivitas: login/logout, penambahan data, perubahan, penghapusan, dan pengaturan sistem.
            </p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <?php
            $exportParams = array_merge($activeQueryParams, ['export' => 'csv']);
            $exportUrl = '?' . http_build_query($exportParams);
            ?>
            <a href="<?= e($exportUrl) ?>" class="btn" style="background: #059669; color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-excel"></i> Ekspor CSV
            </a>
            <span class="badge badge-info" style="font-size: 13px; padding: 8px 14px;">
                Total Terfilter: <?= number_format($totalRecords, 0, ',', '.') ?> Rekaman
            </span>
        </div>
    </div>

    <!-- Ringkasan Statistik Indikator -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #0284c7;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">TOTAL SEMUA LOG</div>
            <div style="font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 2px;"><?= number_format($totalAll, 0, ',', '.') ?></div>
        </div>
        <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #10b981;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">AKTIVITAS HARI INI</div>
            <div style="font-size: 20px; font-weight: 700; color: #065f46; margin-top: 2px;"><?= number_format($todayCount, 0, ',', '.') ?></div>
        </div>
        <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #ef4444;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">AKSI PENGHAPUSAN</div>
            <div style="font-size: 20px; font-weight: 700; color: #991b1b; margin-top: 2px;"><?= number_format($deleteCount, 0, ',', '.') ?></div>
        </div>
        <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #8b5cf6;">
            <div style="font-size: 12px; color: #64748b; font-weight: 600;">PENGGUNA AKTIF TERCATAT</div>
            <div style="font-size: 20px; font-weight: 700; color: #5b21b6; margin-top: 2px;"><?= number_format($uniqueUsers, 0, ',', '.') ?></div>
        </div>
    </div>
</div>

<!-- Filter Pencarian -->
<div class="card" style="margin-bottom: 20px; padding: 18px;">
    <form method="GET" action="" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 150px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Aksi</label>
            <select name="action" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
                <option value="">-- Semua Aksi --</option>
                <?php foreach ($distinctActions as $act): ?>
                    <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>><?= strtoupper(str_replace('_', ' ', e($act))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 150px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Modul / Target</label>
            <select name="entity" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
                <option value="">-- Semua Modul --</option>
                <?php foreach ($distinctEntities as $ent): ?>
                    <option value="<?= e($ent) ?>" <?= $entityFilter === $ent ? 'selected' : '' ?>><?= strtoupper(str_replace('_', ' ', e($ent))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 140px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Tanggal</label>
            <input type="date" name="date" value="<?= e($dateFilter) ?>" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
        </div>
        <div style="flex: 1.5; min-width: 180px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Pengguna (User / Admin)</label>
            <input type="text" name="user" value="<?= e($userFilter) ?>" placeholder="Ketik nama atau username..." style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="padding: 9px 18px;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
            <?php if ($actionFilter || $entityFilter || $dateFilter || $userFilter): ?>
                <a href="audit_logs.php" class="btn btn-secondary" style="padding: 9px 14px;"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Tabel Log Aktivitas -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" style="margin: 0; width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: left;">
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b; width: 140px;">Waktu</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b; width: 180px;">Pengguna</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b; width: 130px;">Aksi</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b; width: 140px;">Modul / Target</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">Rincian Data</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b; width: 130px;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="padding: 40px; text-align: center; color: #94a3b8;">
                            <i class="fa-solid fa-clipboard-question" style="font-size: 32px; display: block; margin-bottom: 10px; color: #cbd5e1;"></i>
                            Tidak ada data log aktivitas yang cocok dengan filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                            <td style="padding: 12px 16px; white-space: nowrap; color: #334155;">
                                <strong><?= date('d/m/Y', strtotime($log['created_at'])) ?></strong><br>
                                <span style="font-size: 11px; color: #64748b;"><i class="fa-regular fa-clock me-1"></i><?= date('H:i:s', strtotime($log['created_at'])) ?> WIB</span>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap;">
                                <?php if (!empty($log['user_name'])): ?>
                                    <strong><?= e($log['user_name']) ?></strong><br>
                                    <span style="font-size: 11px; color: #64748b;">@<?= e($log['user_username'] ?: $log['username']) ?> <span class="badge badge-secondary" style="font-size: 10px; padding: 2px 6px;"><?= e($log['user_role'] ?? '-') ?></span></span>
                                <?php elseif (!empty($log['username'])): ?>
                                    <strong>@<?= e($log['username']) ?></strong><br>
                                    <span style="font-size: 11px; color: #94a3b8;">(<?= e($log['user_role'] ?? 'Akun Terhapus / Sistem') ?>)</span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;"><i class="fa-solid fa-robot me-1"></i>Sistem Otomatis</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap;">
                                <?php
                                $badgeStyle = 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;';
                                $act = strtolower($log['action']);
                                if (strpos($act, 'delete') !== false) {
                                    $badgeStyle = 'background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;';
                                } elseif (strpos($act, 'create') !== false || strpos($act, 'insert') !== false || strpos($act, 'promote') !== false) {
                                    $badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;';
                                } elseif (strpos($act, 'update') !== false || strpos($act, 'edit') !== false) {
                                    $badgeStyle = 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;';
                                } elseif (strpos($act, 'login') !== false) {
                                    $badgeStyle = 'background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;';
                                } elseif (strpos($act, 'logout') !== false) {
                                    $badgeStyle = 'background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb;';
                                } elseif (strpos($act, 'import') !== false) {
                                    $badgeStyle = 'background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe;';
                                } elseif (strpos($act, 'toggle') !== false) {
                                    $badgeStyle = 'background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa;';
                                }
                                ?>
                                <span style="display: inline-block; padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 11.5px; <?= $badgeStyle ?>">
                                    <?= strtoupper(str_replace('_', ' ', e($log['action']))) ?>
                                </span>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap; color: #475569;">
                                <span style="display: inline-block; padding: 3px 7px; border-radius: 4px; background: #e2e8f0; font-family: monospace; font-size: 11.5px; color: #334155;">
                                    <?= e($log['entity_type'] ?? '-') ?>
                                </span>
                                <?php if ($log['entity_id']): ?>
                                    <span style="color: #64748b; font-size: 11px; font-weight: 600; margin-left: 2px;">#<?= e($log['entity_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; font-size: 12px; color: #334155;">
                                <?php if (!empty($log['details'])): ?>
                                    <?php
                                    $detailsDecoded = json_decode($log['details'], true);
                                    ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                        <div style="flex: 1; word-break: break-word;">
                                            <?php if (is_array($detailsDecoded)): ?>
                                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                    <?php $count = 0; ?>
                                                    <?php foreach ($detailsDecoded as $k => $v): ?>
                                                        <?php if ($count < 3): ?>
                                                            <span style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11px; border: 1px solid #e2e8f0;">
                                                                <strong style="color: #64748b;"><?= e($k) ?>:</strong> <?= e(is_array($v) ? json_encode($v) : $v) ?>
                                                            </span>
                                                            <?php $count++; ?>
                                                        <?php else: ?>
                                                            <span style="color: #94a3b8; font-size: 10.5px;">+<?= count($detailsDecoded) - 3 ?> lainnya</span>
                                                            <?php break; ?>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <?= e(mb_strimwidth($log['details'], 0, 100, '...')) ?>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm" 
                                            onclick='openLogModal(<?= json_encode([
                                                "id" => $log["id"],
                                                "time" => date("d/m/Y H:i:s", strtotime($log["created_at"])) . " WIB",
                                                "user" => ($log["user_name"] ?? "") ? ($log["user_name"] . " (@" . ($log["user_username"] ?: $log["username"]) . ")") : ($log["username"] ?: "Sistem"),
                                                "role" => $log["user_role"] ?? "-",
                                                "action" => strtoupper($log["action"]),
                                                "entity" => ($log["entity_type"] ?? "-") . ($log["entity_id"] ? " #" . $log["entity_id"] : ""),
                                                "ip" => $log["ip_address"] ?? "-",
                                                "ua" => $log["user_agent"] ?? "-",
                                                "details" => $log["details"]
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                            style="padding: 4px 8px; font-size: 11px; background: #ffffff; border: 1px solid #cbd5e1; color: #0284c7; border-radius: 6px; cursor: pointer; white-space: nowrap;">
                                            <i class="fa-solid fa-eye"></i> Detail
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap; color: #64748b; font-size: 11.5px;">
                                <i class="fa-solid fa-network-wired me-1" style="color: #94a3b8;"></i><?= e($log['ip_address'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<div style="margin-top: 20px;">
    <?= renderPagination($page, $totalPages, $activeQueryParams) ?>
</div>

<!-- MODAL RINCIAN DETAIL AUDIT LOG -->
<div class="modal-overlay" id="auditDetailModal" onclick="closeModal('auditDetailModal')" style="display: none;">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width: 600px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
            <h3 style="margin: 0; font-size: 17px; color: #1e293b;"><i class="fa-solid fa-circle-info me-2" style="color: #0284c7;"></i>Rincian Log Aktivitas</h3>
            <button type="button" onclick="closeModal('auditDetailModal')" style="background: transparent; border: none; font-size: 18px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 15px;">
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 6px 0; color: #64748b; width: 120px; font-weight: 600;">Waktu Eksekusi</td>
                <td style="padding: 6px 0; color: #1e293b;" id="modalTime">-</td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 6px 0; color: #64748b; font-weight: 600;">Pelaku / User</td>
                <td style="padding: 6px 0; color: #1e293b;" id="modalUser">-</td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 6px 0; color: #64748b; font-weight: 600;">Aksi & Target</td>
                <td style="padding: 6px 0; color: #1e293b;" id="modalActionTarget">-</td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 6px 0; color: #64748b; font-weight: 600;">IP Address</td>
                <td style="padding: 6px 0; color: #1e293b; font-family: monospace;" id="modalIp">-</td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 6px 0; color: #64748b; font-weight: 600;">Perangkat (UA)</td>
                <td style="padding: 6px 0; color: #475569; font-size: 11px; word-break: break-all;" id="modalUa">-</td>
            </tr>
        </table>

        <div style="margin-bottom: 6px; font-weight: 600; font-size: 12.5px; color: #475569;">Isi Data Rincian (Payload):</div>
        <pre id="modalDetails" style="background: #0f172a; color: #38bdf8; padding: 12px; border-radius: 8px; font-size: 12px; max-height: 250px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin: 0; font-family: monospace;"></pre>

        <div style="display: flex; justify-content: flex-end; margin-top: 18px;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('auditDetailModal')" style="padding: 7px 18px;">Tutup</button>
        </div>
    </div>
</div>

<script>
function openLogModal(data) {
    document.getElementById('modalTime').textContent = data.time || '-';
    document.getElementById('modalUser').textContent = (data.user || '-') + ' (' + (data.role || '-') + ')';
    document.getElementById('modalActionTarget').textContent = (data.action || '-') + ' -> ' + (data.entity || '-');
    document.getElementById('modalIp').textContent = data.ip || '-';
    document.getElementById('modalUa').textContent = data.ua || '-';

    let formattedDetails = '-';
    if (data.details) {
        try {
            const parsed = JSON.parse(data.details);
            formattedDetails = JSON.stringify(parsed, null, 2);
        } catch (e) {
            formattedDetails = data.details;
        }
    }
    document.getElementById('modalDetails').textContent = formattedDetails;
    
    document.getElementById('auditDetailModal').style.display = 'flex';
}
</script>

<?php require_once '../includes/footer.php'; ?>
