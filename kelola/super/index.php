<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Kelola Akses Super Admin';

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$msg = '';

// Proses Hapus
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: ?msg=deleted");
    exit;
}

// Proses Simpan (Tambah / Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? 'admin';
    $password = $_POST['password'] ?? '';
    
    if (empty($_POST['id'])) {
        // Tambah Baru Menggunakan hashPassword() agar sesuai sistem utama
        $hash = hashPassword($password);
        $stmt = $pdo->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $username, $hash, $role]);
        header("Location: ?msg=added");
        exit;
    } else {
        // Edit
        if (!empty($password)) {
            $hash = hashPassword($password);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, password = ? WHERE id = ?");
            $stmt->execute([$name, $username, $role, $hash, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ? WHERE id = ?");
            $stmt->execute([$name, $username, $role, $_POST['id']]);
        }
        header("Location: ?msg=updated");
        exit;
    }
}

// Ambil Data untuk List atau Edit
$users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$editData = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f9; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #007BFF; color: #fff; }
        .btn { padding: 6px 12px; text-decoration: none; color: #fff; border-radius: 4px; display: inline-block; }
        .btn-blue { background: #007BFF; border: none; cursor: pointer; }
        .btn-red { background: #DC3545; }
        .btn-green { background: #28A745; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Manajemen Akun (Bypass Login)</h2>
    
    <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
        <h3><?= $editData ? 'Edit Pengguna' : 'Tambah Pengguna Baru' ?></h3>
        <form method="POST" action="?action=save">
            <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="name" required value="<?= $editData['name'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required value="<?= $editData['username'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" required>
                    <option value="super_admin" <?= ($editData['role'] ?? '') == 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                    <option value="admin" <?= ($editData['role'] ?? '') == 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="staff" <?= ($editData['role'] ?? '') == 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
            </div>
            <div class="form-group">
                <label>Password <?= $editData ? '(Kosongkan jika tidak ingin diubah)' : '' ?></label>
                <input type="password" name="password" <?= $editData ? '' : 'required' ?>>
            </div>
            <button type="submit" class="btn btn-green">Simpan Data</button>
            <?php if($editData): ?>
                <a href="?" class="btn btn-blue" style="background:#6c757d;">Batal Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <h3>Daftar Akun Sistem</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nama</th>
                <th>Username</th>
                <th>Role</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><strong><?= strtoupper($u['role']) ?></strong></td>
                <td>
                    <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-blue">Edit</a>
                    <a href="?action=delete&id=<?= $u['id'] ?>" class="btn btn-red" onclick="return confirm('Hapus akun ini?')">Hapus</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>