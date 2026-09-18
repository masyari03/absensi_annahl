<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Tambah Tahun Ajaran';


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $name =
        trim(
            $_POST['name'] ?? ''
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


    try {

        $pdo->beginTransaction();


        if (
            $status === 'active'
        ) {

            $pdo->exec("
                UPDATE academic_years
                SET status = 'closed'
                WHERE status = 'active'
            ");

        }


        $stmt =
            $pdo->prepare("
                INSERT INTO academic_years
                (
                    name,
                    start_date,
                    end_date,
                    status
                )
                VALUES (?, ?, ?, ?)
            ");

        $stmt->execute([
            $name,
            $startDate,
            $endDate,
            $status
        ]);


        $pdo->commit();


        flash(
            'success',
            'Tahun ajaran berhasil dibuat.'
        );


        redirect(
            'index.php'
        );

    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();

        }

        die(
            $e->getMessage()
        );

    }

}


require '../../includes/header.php';

?>


<div class="card">

<h3>
    Tambah Tahun Ajaran
</h3>


<form method="POST">

<div class="form-grid">


<div class="form-group">

<label>
    Nama Tahun Ajaran
</label>

<input
    type="text"
    name="name"
    placeholder="2026/2027"
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

<option value="draft">
    Draft
</option>

<option value="active">
    Aktif
</option>

</select>

</div>


<div class="form-group">

<label>
    Tanggal Mulai
</label>

<input
    type="date"
    name="start_date"
    required
>

</div>


<div class="form-group">

<label>
    Tanggal Selesai
</label>

<input
    type="date"
    name="end_date"
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