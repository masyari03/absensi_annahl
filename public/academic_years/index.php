<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Tahun Ajaran';


$years =
    $pdo->query("
        SELECT *
        FROM academic_years
        ORDER BY
            start_date DESC
    ")->fetchAll();


require '../../includes/header.php';

?>


<div class="card">

<div class="card-header">

<h3>
    Tahun Ajaran
</h3>

<a
    href="create.php"
    class="btn btn-primary"
>
    + Tambah Tahun Ajaran
</a>

</div>


<div class="table-wrapper">

<table>

<thead>

<tr>

<th>No</th>

<th>Tahun</th>

<th>Mulai</th>

<th>Selesai</th>

<th>Status</th>

<th>Aksi</th>

</tr>

</thead>


<tbody>

<?php foreach (
    $years as $index => $year
): ?>

<tr>

<td>
    <?= $index + 1 ?>
</td>

<td>
    <?= e($year['name']) ?>
</td>

<td>
    <?= e($year['start_date']) ?>
</td>

<td>
    <?= e($year['end_date']) ?>
</td>

<td>

<?php if (
    $year['status'] === 'active'
): ?>

<span class="badge badge-success">
    Aktif
</span>

<?php elseif (
    $year['status'] === 'closed'
): ?>

<span class="badge badge-danger">
    Ditutup
</span>

<?php else: ?>

<span class="badge">
    Draft
</span>

<?php endif; ?>

</td>


<td>

<a
    href="edit.php?id=<?= $year['id'] ?>"
    class="btn btn-success"
>
    Edit
</a>


<?php if (
    $year['status']
    !== 'active'
): ?>

<form
    action="activate.php"
    method="POST"
    style="display:inline"
    onsubmit="
        return confirm(
            'Aktifkan tahun ajaran ini?'
        )
    "
>

<input
    type="hidden"
    name="id"
    value="<?= $year['id'] ?>"
>

<button
    class="btn btn-primary"
>
    Aktifkan
</button>

</form>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>


<?php require '../../includes/footer.php'; ?>