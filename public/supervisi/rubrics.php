<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('supervisi_rubrik');

$pageTitle = 'Kelola Rubrik, Indikator & Skor Supervisi Fleksibel';
$currentUserId = currentUserId();
$currentRole = currentRole();

// Ambil tipe supervisi aktif
$types = $pdo->query("SELECT * FROM supervision_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$selectedTypeId = (int)($_GET['type_id'] ?? ($types[0]['id'] ?? 1));

// =========================================================================
// ACTION HANDLER (POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH ASPEK / KATEGORI BARU
    if ($action === 'add_category') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $catName = trim($_POST['category_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($typeId > 0 && !empty($catName)) {
            $stmt = $pdo->prepare("INSERT INTO supervision_categories (type_id, category_name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$typeId, $catName, $desc, $sortOrder]);
            flash('success', 'Aspek penilaian baru berhasil ditambahkan!');
        } else {
            flash('error', 'Nama aspek penilaian tidak boleh kosong.');
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 2. EDIT ASPEK / KATEGORI
    if ($action === 'edit_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);
        $catName = trim($_POST['category_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($catId > 0 && !empty($catName)) {
            $stmt = $pdo->prepare("UPDATE supervision_categories SET category_name = ?, description = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$catName, $desc, $sortOrder, $catId]);
            flash('success', 'Aspek penilaian berhasil diperbarui!');
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 3. HAPUS ASPEK / KATEGORI
    if ($action === 'delete_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);

        if ($catId > 0) {
            // Cek apakah ada butir di dalam aspek ini
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM supervision_rubric_items WHERE category_id = ?");
            $stmtCheck->execute([$catId]);
            $itemCount = (int)$stmtCheck->fetchColumn();

            if ($itemCount > 0) {
                flash('error', "Aspek tidak dapat dihapus karena masih memuat {$itemCount} butir indikator. Hapus atau pindahkan butir terlebih dahulu.");
            } else {
                $stmtDel = $pdo->prepare("DELETE FROM supervision_categories WHERE id = ?");
                $stmtDel->execute([$catId]);
                flash('success', 'Aspek penilaian berhasil dihapus.');
            }
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 4. TAMBAH BUTIR INDIKATOR BARU (FLEKSIBEL)
    if ($action === 'add_item') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $aspekLabel = trim($_POST['aspek_label'] ?? '');
        $indicatorText = trim($_POST['indicator_text'] ?? '');
        $minScore = max(0, (int)($_POST['min_score'] ?? 1));
        $maxScore = max($minScore + 1, (int)($_POST['max_score'] ?? 4));
        $weight = (float)($_POST['weight'] ?? 1.00);
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($catId > 0 && !empty($indicatorText)) {
            $stmt = $pdo->prepare("
                INSERT INTO supervision_rubric_items (category_id, code, aspek_label, indicator_text, min_score, max_score, weight, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$catId, $code, $aspekLabel, $indicatorText, $minScore, $maxScore, $weight, $sortOrder]);
            flash('success', 'Butir indikator baru berhasil ditambahkan! Total skor maksimal otomatis menyesuaikan.');
        } else {
            flash('error', 'Deskripsi indikator dan aspek tidak boleh kosong.');
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 5. EDIT BUTIR INDIKATOR
    if ($action === 'edit_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $aspekLabel = trim($_POST['aspek_label'] ?? '');
        $indicatorText = trim($_POST['indicator_text'] ?? '');
        $minScore = max(0, (int)($_POST['min_score'] ?? 1));
        $maxScore = max($minScore + 1, (int)($_POST['max_score'] ?? 4));
        $weight = (float)($_POST['weight'] ?? 1.00);
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($itemId > 0 && $catId > 0 && !empty($indicatorText)) {
            $stmt = $pdo->prepare("
                UPDATE supervision_rubric_items
                SET category_id = ?, code = ?, aspek_label = ?, indicator_text = ?, min_score = ?, max_score = ?, weight = ?, sort_order = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$catId, $code, $aspekLabel, $indicatorText, $minScore, $maxScore, $weight, $sortOrder, $isActive, $itemId]);
            flash('success', 'Butir indikator berhasil diperbarui!');
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 6. TOGGLE STATUS AKTIF BUTIR INDIKATOR
    if ($action === 'toggle_item_active') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);

        if ($itemId > 0) {
            $pdo->prepare("UPDATE supervision_rubric_items SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?")->execute([$itemId]);
            flash('success', 'Status keaktifan butir indikator berhasil diubah!');
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 7. HAPUS BUTIR INDIKATOR
    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);

        if ($itemId > 0) {
            $pdo->prepare("DELETE FROM supervision_rubric_items WHERE id = ?")->execute([$itemId]);
            flash('success', 'Butir indikator berhasil dihapus. Total skor maksimal instrumen telah disesuaikan.');
        }
        redirect("rubrics.php?type_id={$typeId}");
    }

    // 8. UPDATE AMBANG BATAS PREDIKAT
    if ($action === 'update_predicates') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $predNames = $_POST['pred_name'] ?? [];
        $predPcts = $_POST['pred_pct'] ?? [];
        $predColors = $_POST['pred_color'] ?? [];
        $predDescs = $_POST['pred_desc'] ?? [];

        foreach ($predNames as $pId => $name) {
            $idInt = (int)$pId;
            $pct = (float)($predPcts[$idInt] ?? 0);
            $color = trim($predColors[$idInt] ?? '#6366f1');
            $desc = trim($predDescs[$idInt] ?? '');

            if ($idInt > 0) {
                $stmt = $pdo->prepare("
                    UPDATE supervision_predicates
                    SET predicate_name = ?, min_percentage = ?, badge_color = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $pct, $color, $desc, $idInt]);
            }
        }
        flash('success', 'Aturan ambang batas predikat supervisi berhasil diperbarui!');
        redirect("rubrics.php?type_id={$typeId}");
    }
}

// =========================================================================
// QUERY DATA TAMPILAN
// =========================================================================
// 1. Data Jenis yang Sedang Dipilih
$currentType = null;
foreach ($types as $t) {
    if ((int)$t['id'] === $selectedTypeId) {
        $currentType = $t;
        break;
    }
}
if (!$currentType && !empty($types)) {
    $currentType = $types[0];
    $selectedTypeId = (int)$currentType['id'];
}

// 2. Kategori / Aspek Penilaian untuk Type ini
$stmtCats = $pdo->prepare("
    SELECT sc.*,
           (SELECT COUNT(*) FROM supervision_rubric_items sri WHERE sri.category_id = sc.id) as total_items,
           (SELECT COUNT(*) FROM supervision_rubric_items sri WHERE sri.category_id = sc.id AND sri.is_active = 1) as active_items,
           (SELECT COALESCE(SUM(sri.max_score * sri.weight), 0) FROM supervision_rubric_items sri WHERE sri.category_id = sc.id AND sri.is_active = 1) as subtotal_max
    FROM supervision_categories sc
    WHERE sc.type_id = ?
    ORDER BY sc.sort_order ASC, sc.id ASC
");
$stmtCats->execute([$selectedTypeId]);
$categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// 3. Seluruh Butir Indikator untuk Type ini
$categoryIds = array_column($categories, 'id');
$itemsByCategory = [];
$totalActiveItems = 0;
$grandMaxScore = 0;

if (!empty($categoryIds)) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $stmtItems = $pdo->prepare("
        SELECT sri.*, sc.category_name
        FROM supervision_rubric_items sri
        JOIN supervision_categories sc ON sc.id = sri.category_id
        WHERE sri.category_id IN ($placeholders)
        ORDER BY sri.sort_order ASC, sri.id ASC
    ");
    $stmtItems->execute($categoryIds);
    $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allItems as $it) {
        $itemsByCategory[$it['category_id']][] = $it;
        if ($it['is_active'] == 1) {
            $totalActiveItems++;
            $grandMaxScore += ($it['max_score'] * $it['weight']);
        }
    }
}

// 4. Aturan Predikat
$predicates = $pdo->query("SELECT * FROM supervision_predicates ORDER BY sort_order ASC, min_percentage DESC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="supervisi-fullwidth" style="width: 100%; max-width: 100%;">
    <!-- HEADER & JUDUL MODUL -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: #e0e7ff; color: #4338ca; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                    <i class="fa-solid fa-sliders"></i> Konfigurasi Fleksibel
                </span>
                <span style="color: #94a3b8; font-size: 13px;">&bull; Terintegrasi Database AIS & KURMAA</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a;">
                Pengaturan Rubrik, Butir & Skor Supervisi
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Kelola aspek, butir indikator, skala skor, dan ambang batas predikat secara dinamis dan mandiri.
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="reports.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-table-list"></i> Rekapitulasi Data
            </a>
            <a href="schedules.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-calendar-days"></i> Jadwal Supervisi
            </a>
        </div>
    </div>

    <!-- TAB TIPE SUPERVISI -->
    <div style="display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 24px;">
        <?php foreach ($types as $t): 
            $isActiveTab = ($t['id'] == $selectedTypeId);
            $icon = ($t['type_key'] === 'peer') ? 'fa-user-pen' : 'fa-school';
        ?>
            <a href="rubrics.php?type_id=<?= $t['id'] ?>" 
               style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; font-weight: 700; font-size: 14px; text-decoration: none; border-bottom: 3px solid <?= $isActiveTab ? 'var(--primary, #4f46e5)' : 'transparent' ?>; color: <?= $isActiveTab ? 'var(--primary, #4f46e5)' : '#64748b' ?>; background: <?= $isActiveTab ? '#ffffff' : 'transparent' ?>; border-top-left-radius: 8px; border-top-right-radius: 8px; transition: all 0.2s;">
                <i class="fa-solid <?= $icon ?>"></i>
                <?= htmlspecialchars($t['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- KARTU STATISTIK & FLEKSIBILITAS REALTIME -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Aspek / Komponen</div>
            <div style="font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= count($categories) ?></div>
            <div style="font-size: 12px; color: #10b981; margin-top: 4px;"><i class="fa-solid fa-check-circle"></i> Terstruktur rapi</div>
        </div>

        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Butir Indikator Aktif</div>
            <div style="font-size: 28px; font-weight: 800; color: #4f46e5; margin-top: 4px;"><?= $totalActiveItems ?></div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Dapat ditambah/dikurangi bebas</div>
        </div>

        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Skor Maksimal Instrumen</div>
            <div style="font-size: 28px; font-weight: 800; color: #0284c7; margin-top: 4px;"><?= number_format($grandMaxScore, 0) ?></div>
            <div style="font-size: 12px; color: #0284c7; margin-top: 4px;"><i class="fa-solid fa-calculator"></i> Dihitung dinamis otomatis</div>
        </div>

        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: center;">
            <button type="button" class="btn btn-primary" onclick="openAddCategoryModal()" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 10px 14px; margin-bottom: 8px;">
                <i class="fa-solid fa-folder-plus"></i> Tambah Aspek Baru
            </button>
            <button type="button" class="btn btn-secondary" onclick="openAddItemModal(0)" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 8px 14px; font-size: 13px;">
                <i class="fa-solid fa-plus-circle"></i> Tambah Butir Indikator
            </button>
        </div>
    </div>

    <!-- DAFTAR ASPEK & BUTIR INDIKATOR -->
    <div style="margin-bottom: 36px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <h2 style="margin: 0; font-size: 18px; font-weight: 800; color: #1e293b;">
                Daftar Aspek & Butir Instrumen (<?= htmlspecialchars($currentType['name']) ?>)
            </h2>
            <span style="font-size: 13px; color: #64748b;">
                Skala default: <strong>1 - 4 (Likert)</strong> &bull; Total Skor = &Sigma;(Skor per Butir &times; Bobot)
            </span>
        </div>

        <?php if (empty($categories)): ?>
            <div style="background: white; padding: 40px; text-align: center; border-radius: 12px; border: 1px dashed #cbd5e1; color: #64748b;">
                <i class="fa-solid fa-folder-open" style="font-size: 36px; color: #94a3b8; margin-bottom: 12px; display: block;"></i>
                Belum ada aspek penilaian untuk instrumen ini. Silakan klik tombol <strong>"Tambah Aspek Baru"</strong> di atas.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <?php foreach ($categories as $cat): 
                    $catId = (int)$cat['id'];
                    $items = $itemsByCategory[$catId] ?? [];
                ?>
                    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <!-- HEADER KATEGORI -->
                        <div style="background: #f8fafc; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <span style="font-weight: 800; font-size: 16px; color: #0f172a;">
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </span>
                                <?php if (!empty($cat['description'])): ?>
                                    <span style="font-size: 12.5px; color: #64748b; margin-left: 8px;">
                                        &bull; <?= htmlspecialchars($cat['description']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px;">
                                    Subtotal: <?= count($items) ?> Butir (Maks: <?= number_format($cat['subtotal_max'], 0) ?> poin)
                                </span>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="openAddItemModal(<?= $catId ?>)" title="Tambah Butir ke Aspek Ini">
                                    <i class="fa-solid fa-plus"></i> Tambah Butir
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="openEditCategoryModal(<?= $catId ?>, '<?= htmlspecialchars(addslashes($cat['category_name'])) ?>', '<?= htmlspecialchars(addslashes($cat['description'] ?? '')) ?>', <?= (int)$cat['sort_order'] ?>)" title="Edit Aspek">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus aspek ini?');">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
                                    <input type="hidden" name="category_id" value="<?= $catId ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus Aspek">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- TABEL BUTIR INDIKATOR -->
                        <?php if (empty($items)): ?>
                            <div style="padding: 24px; text-align: center; color: #94a3b8; font-size: 13px;">
                                Belum ada butir indikator pada aspek ini.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                                    <thead>
                                        <tr style="background: #ffffff; border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 11px; text-transform: uppercase;">
                                            <th style="padding: 10px 16px; width: 40px; text-align: center;">No</th>
                                            <th style="padding: 10px 14px; width: 140px;">Sub-Aspek / Kode</th>
                                            <th style="padding: 10px 14px;">Bunyi Deskripsi Indikator Penilaian</th>
                                            <th style="padding: 10px 14px; width: 100px; text-align: center;">Skala Nilai</th>
                                            <th style="padding: 10px 14px; width: 80px; text-align: center;">Bobot</th>
                                            <th style="padding: 10px 14px; width: 90px; text-align: center;">Status</th>
                                            <th style="padding: 10px 16px; width: 120px; text-align: right;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($items as $it): 
                                            $isActive = ($it['is_active'] == 1);
                                        ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9; <?= !$isActive ? 'background: #f8fafc; opacity: 0.6;' : '' ?>">
                                                <td style="padding: 12px 16px; text-align: center; font-weight: 700; color: #94a3b8;">
                                                    <?= $no++ ?>
                                                </td>
                                                <td style="padding: 12px 14px;">
                                                    <?php if (!empty($it['aspek_label'])): ?>
                                                        <span style="font-weight: 700; color: #1e293b; display: block; font-size: 12px;">
                                                            <?= htmlspecialchars($it['aspek_label']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($it['code'])): ?>
                                                        <span style="display: inline-block; background: #eef2ff; color: #4338ca; font-size: 10.5px; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-weight: 700;">
                                                            <?= htmlspecialchars($it['code']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 12px 14px; color: #334155; line-height: 1.5;">
                                                    <?= htmlspecialchars($it['indicator_text']) ?>
                                                </td>
                                                <td style="padding: 12px 14px; text-align: center;">
                                                    <span style="background: #f1f5f9; color: #334155; font-weight: 700; padding: 3px 8px; border-radius: 6px; font-size: 12px;">
                                                        <?= (int)$it['min_score'] ?> - <?= (int)$it['max_score'] ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px 14px; text-align: center; font-weight: 600; color: #64748b;">
                                                    &times;<?= number_format($it['weight'], 2) ?>
                                                </td>
                                                <td style="padding: 12px 14px; text-align: center;">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_item_active">
                                                        <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
                                                        <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                                        <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;">
                                                            <?php if ($isActive): ?>
                                                                <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px;">
                                                                    Aktif
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="background: #fee2e2; color: #b91c1c; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px;">
                                                                    Nonaktif
                                                                </span>
                                                            <?php endif; ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td style="padding: 12px 16px; text-align: right;">
                                                    <div style="display: inline-flex; gap: 6px;">
                                                        <button type="button" class="btn btn-sm btn-secondary" onclick='openEditItemModal(<?= json_encode($it) ?>)' title="Edit Butir">
                                                            <i class="fa-solid fa-pen"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus butir indikator ini?');">
                                                            <input type="hidden" name="action" value="delete_item">
                                                            <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
                                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Butir">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- KONFIGURASI AMBANG BATAS PREDIKAT FLEKSIBEL -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-award" style="color: #f59e0b; margin-right: 8px;"></i>
                    Ambang Batas & Kategori Predikat Kelulusan
                </h3>
                <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">
                    Persentase = (Total Skor Perolehan / Total Skor Maksimal Aktif) &times; 100%. Predikat ditentukan otomatis dari persentase ini.
                </p>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_predicates">
            <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
                <?php foreach ($predicates as $p): ?>
                    <div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; background: #f8fafc;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <span style="background: <?= htmlspecialchars($p['badge_color']) ?>; color: white; font-weight: 800; font-size: 12px; padding: 4px 10px; border-radius: 20px;">
                                <?= htmlspecialchars($p['predicate_name']) ?>
                            </span>
                            <input type="color" name="pred_color[<?= $p['id'] ?>]" value="<?= htmlspecialchars($p['badge_color']) ?>" style="border: none; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; background: transparent;">
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Nama Predikat:</label>
                            <input type="text" name="pred_name[<?= $p['id'] ?>]" value="<?= htmlspecialchars($p['predicate_name']) ?>" class="form-control" style="font-size: 13px; font-weight: 700;" required>
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Batas Minimal Persentase (%):</label>
                            <div style="position: relative;">
                                <input type="number" step="0.01" min="0" max="100" name="pred_pct[<?= $p['id'] ?>]" value="<?= (float)$p['min_percentage'] ?>" class="form-control" style="font-size: 13px; font-weight: 700; padding-right: 32px;" required>
                                <span style="position: absolute; right: 10px; top: 8px; font-weight: 700; color: #94a3b8; font-size: 12px;">%</span>
                            </div>
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Keterangan / Deskripsi:</label>
                            <input type="text" name="pred_desc[<?= $p['id'] ?>]" value="<?= htmlspecialchars($p['description'] ?? '') ?>" class="form-control" style="font-size: 12px;">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 18px; text-align: right;">
                <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Pengaturan Predikat
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH / EDIT ASPEK -->
<!-- ========================================================================= -->
<div id="modalCategory" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 500px;">
        <div class="custom-modal-header">
            <h3 id="modalCategoryTitle" style="margin: 0; font-size: 17px; color: #0f172a;">Tambah Aspek Baru</h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalCategory')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="catActionInput" value="add_category">
            <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
            <input type="hidden" name="category_id" id="catIdInput" value="">

            <div class="custom-modal-body" style="padding: 20px;">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Nama Aspek / Komponen Penilaian *</label>
                    <input type="text" name="category_name" id="catNameInput" placeholder="Misal: 1. Pembelajaran Mendalam" class="form-control" required>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Deskripsi Singkat Aspek</label>
                    <input type="text" name="description" id="catDescInput" placeholder="Misal: Mindful, Meaningful, Joyful..." class="form-control">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Urutan Tampilan</label>
                    <input type="number" name="sort_order" id="catSortInput" value="<?= count($categories) + 1 ?>" class="form-control">
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCategory')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Aspek</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH / EDIT BUTIR INDIKATOR -->
<!-- ========================================================================= -->
<div id="modalItem" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 620px;">
        <div class="custom-modal-header">
            <h3 id="modalItemTitle" style="margin: 0; font-size: 17px; color: #0f172a;">Tambah Butir Indikator Baru</h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalItem')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="itemActionInput" value="add_item">
            <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
            <input type="hidden" name="item_id" id="itemIdInput" value="">

            <div class="custom-modal-body" style="padding: 20px;">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Pilih Aspek Penilaian *</label>
                    <select name="category_id" id="itemCatSelect" class="form-control" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Sub-Aspek / Label</label>
                        <input type="text" name="aspek_label" id="itemLabelInput" placeholder="Misal: Berkesadaran (Mindful)" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Kode Unik (Opsional)</label>
                        <input type="text" name="code" id="itemCodeInput" placeholder="Misal: pm1, kr2, kp3" class="form-control">
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Bunyi Deskripsi Indikator / Observasi *</label>
                    <textarea name="indicator_text" id="itemTextInput" rows="3" placeholder="Deskripsikan perilaku atau bukti pembelajaran yang diobservasi..." class="form-control" required></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #334155;">Skor Min</label>
                        <input type="number" name="min_score" id="itemMinInput" value="1" min="0" class="form-control" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #334155;">Skor Maks</label>
                        <input type="number" name="max_score" id="itemMaxInput" value="4" min="1" class="form-control" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #334155;">Bobot</label>
                        <input type="number" step="0.1" name="weight" id="itemWeightInput" value="1.00" class="form-control" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #334155;">Urutan</label>
                        <input type="number" name="sort_order" id="itemSortInput" value="0" class="form-control">
                    </div>
                </div>

                <div id="itemActiveContainer" style="display: none; align-items: center; gap: 8px;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="itemActiveCheckbox" value="1" checked>
                        <span style="font-size: 13px; font-weight: 600; color: #334155;">Aktifkan butir ini dalam formulir supervisi</span>
                    </label>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalItem')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Butir</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddCategoryModal() {
    document.getElementById('modalCategoryTitle').innerText = 'Tambah Aspek Penilaian Baru';
    document.getElementById('catActionInput').value = 'add_category';
    document.getElementById('catIdInput').value = '';
    document.getElementById('catNameInput').value = '';
    document.getElementById('catDescInput').value = '';
    document.getElementById('modalCategory').style.display = 'flex';
}

function openEditCategoryModal(id, name, desc, sort) {
    document.getElementById('modalCategoryTitle').innerText = 'Edit Aspek Penilaian';
    document.getElementById('catActionInput').value = 'edit_category';
    document.getElementById('catIdInput').value = id;
    document.getElementById('catNameInput').value = name;
    document.getElementById('catDescInput').value = desc;
    document.getElementById('catSortInput').value = sort;
    document.getElementById('modalCategory').style.display = 'flex';
}

function openAddItemModal(catId) {
    document.getElementById('modalItemTitle').innerText = 'Tambah Butir Indikator Baru';
    document.getElementById('itemActionInput').value = 'add_item';
    document.getElementById('itemIdInput').value = '';
    if (catId > 0) {
        document.getElementById('itemCatSelect').value = catId;
    }
    document.getElementById('itemLabelInput').value = '';
    document.getElementById('itemCodeInput').value = '';
    document.getElementById('itemTextInput').value = '';
    document.getElementById('itemMinInput').value = 1;
    document.getElementById('itemMaxInput').value = 4;
    document.getElementById('itemWeightInput').value = 1.00;
    document.getElementById('itemActiveContainer').style.display = 'none';
    document.getElementById('modalItem').style.display = 'flex';
}

function openEditItemModal(it) {
    document.getElementById('modalItemTitle').innerText = 'Edit Butir Indikator';
    document.getElementById('itemActionInput').value = 'edit_item';
    document.getElementById('itemIdInput').value = it.id;
    document.getElementById('itemCatSelect').value = it.category_id;
    document.getElementById('itemLabelInput').value = it.aspek_label || '';
    document.getElementById('itemCodeInput').value = it.code || '';
    document.getElementById('itemTextInput').value = it.indicator_text || '';
    document.getElementById('itemMinInput').value = it.min_score;
    document.getElementById('itemMaxInput').value = it.max_score;
    document.getElementById('itemWeightInput').value = it.weight;
    document.getElementById('itemSortInput').value = it.sort_order;
    document.getElementById('itemActiveCheckbox').checked = (it.is_active == 1);
    document.getElementById('itemActiveContainer').style.display = 'flex';
    document.getElementById('modalItem').style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
