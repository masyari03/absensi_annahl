<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Edit Tahun Ajaran';


$id =
    (int)($_GET['id'] ?? 0);


$stmt =
    $pdo->prepare("
        SELECT *
        FROM academic_years
        WHERE id = ?
        LIMIT 1
    ");

$stmt->execute([
    $id
]);

$year =
    $stmt->fetch();


if (!$year) {

    die(
        'Tahun ajaran tidak ditemukan.'
    );

}


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $name =
        trim(
            $_POST['name']
            ?? ''
        );

    $startDate =
        $_POST['start_date']
        ?? '';

    $endDate =
        $_POST['end_date']
        ?? '';

    $status =
        $_POST['status']
        ?? 'draft';


    $pdo->beginTransaction();


    if (
        $status === 'active'
    ) {

        $stmt =
            $pdo->prepare("
                UPDATE academic_years

                SET status = 'closed'

                WHERE
                    id <> ?

                AND status = 'active'
            ");

        $stmt->execute([
            $id
        ]);

    }


    $stmt =
        $pdo->prepare("
            UPDATE academic_years

            SET
                name = ?,
                start_date = ?,
                end_date = ?,
                status = ?

            WHERE id = ?
        ");

    $stmt->execute([
        $name,
        $startDate,
        $endDate,
        $status,
        $id
    ]);


    $pdo->commit();


    flash(
        'success',
        'Tahun ajaran berhasil diperbarui.'
    );


    redirect(
        'index.php'
    );
}


require '../../includes/header.php';

?>


<div class="card">

<h3>
    Edit Tahun Ajaran
</h3>


<form method="POST">

<div class="form-grid">


<div class="form-group">

<label>
    Tahun Ajaran
</label>

<input
    type="text"
    name="name"
    value="<?= e($year['name']) ?>"
    required
>

</div>


<div class="form-group">

<label>
    Status
</label>

<select
    name="status"
>

<option
    value="draft"
    <?= $year['status'] === 'draft'
        ? 'selected'
        : ''
    ?>
>
    Draft
</option>

<option
    value="active"
    <?= $year['status'] === 'active'
        ? 'selected'
        : ''
    ?>
>
    Aktif
</option>

<option
    value="closed"
    <?= $year['status'] === 'closed'
        ? 'selected'
        : ''
    ?>
>
    Ditutup
</option>

</select>

</div>


<div class="form-group">

<label>
    Mulai
</label>

<input
    type="date"
    name="start_date"
    value="<?= e(
        $year['start_date']
    ) ?>"
    required
>

</div>


<div class="form-group">

<label>
    Selesai
</label>

<input
    type="date"
    name="end_date"
    value="<?= e(
        $year['end_date']
    ) ?>"
    required
>

</div>


</div>


<div style="margin-top:20px">

<button
    class="btn btn-primary"
>
    Simpan
</button>

<a
    href="index.php"
    class="btn btn-success"
>
    Kembali
</a>

</div>

</form>

</div>


<?php require '../../includes/footer.php'; ?>