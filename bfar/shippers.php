<?php
// Restrict this page to authorized record editors before loading shipper data.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRecordEditor();

$pdo = getDatabaseConnection();
$currentUser = authCurrentUser();
$csrfToken = authCsrfToken();
$provinceRows = $pdo->query('SELECT province_name FROM provinces ORDER BY province_name')->fetchAll(PDO::FETCH_COLUMN);
$provinceNames = array_fill_keys($provinceRows, true);
// Keep form state and validation errors available for rendering after an unsuccessful submission.
$errors = [];
$formMessage = null;
$recordId = filter_var($_GET['id'] ?? $_POST['shipper_id'] ?? null, FILTER_VALIDATE_INT);
$recordId = $recordId && $recordId > 0 ? $recordId : null;
$values = [
    'name' => '',
    'sex' => '',
    'client_type' => 'Regular',
    'company' => '',
    'contact_number' => '',
    'email' => '',
    'municipality' => '',
    'province' => '',
];

if ($recordId !== null) {
    // Load existing details when the user opens the edit form.
    $shipperQuery = $pdo->prepare('SELECT * FROM shippers WHERE id = :id');
    $shipperQuery->execute(['id' => $recordId]);
    $shipper = $shipperQuery->fetch(PDO::FETCH_ASSOC);
    if (!$shipper) {
        http_response_code(404);
        exit('Shipper not found.');
    }
    foreach ($values as $field => $default) {
        $values[$field] = (string) ($shipper[$field] ?? $default);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    // Match permit records: only administrators may archive or restore contacts.
    requireRecordRemovalPermission();
    $contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT);
    $nextActive = ($_POST['status'] ?? '') === 'restore' ? 1 : 0;
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $formMessage = 'Your session expired. Please reload the page and try again.';
    } elseif (!$contactId || $contactId < 1 || !in_array($_POST['status'] ?? '', ['archive', 'restore'], true)) {
        $formMessage = 'The shipper status request is invalid.';
    } else {
        try {
            $pdo->beginTransaction();
            $statusQuery = $pdo->prepare('SELECT is_active FROM shippers WHERE id = :id FOR UPDATE');
            $statusQuery->execute(['id' => $contactId]);
            $currentStatus = $statusQuery->fetchColumn();
            if ($currentStatus === false) {
                throw new RuntimeException('This shipper no longer exists. Refresh the page and try again.');
            }
            if ((int) $currentStatus !== $nextActive) {
                $updateStatus = $pdo->prepare('UPDATE shippers SET is_active = :is_active WHERE id = :id');
                $updateStatus->execute(['is_active' => $nextActive, 'id' => $contactId]);
                $logStatus = $pdo->prepare(
                    'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                     VALUES (:user_id, :action, \'shipper\', :record_id, :details)'
                );
                $logStatus->execute([
                    'user_id' => (int) $currentUser['id'],
                    'action' => $nextActive ? 'restore' : 'archive',
                    'record_id' => $contactId,
                    'details' => $nextActive ? 'Restored shipper' : 'Archived shipper',
                ]);
            }
            $pdo->commit();
            $_SESSION['shippers_flash'] = [
                'type' => 'success',
                'message' => $nextActive ? 'Shipper restored.' : 'Shipper archived.',
            ];
            header('Location: shippers.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Shipper status could not be changed. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'toggle_active') {
    // Normalize submitted values before validating their lengths and allowed values.
    foreach ($values as $field => $default) {
        $values[$field] = trim((string) ($_POST[$field] ?? $default));
    }

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $formMessage = 'Your session expired. Please reload the page and try again.';
    } elseif ($values['name'] === '' || mb_strlen($values['name']) > 100) {
        $errors['name'] = 'Enter a shipper name up to 100 characters.';
    } elseif (!in_array($values['sex'], ['', 'Male', 'Female'], true)) {
        $errors['sex'] = 'Choose a valid sex or leave it blank.';
    } elseif ($values['client_type'] === '' || mb_strlen($values['client_type']) > 30) {
        $errors['client_type'] = 'Enter a client type up to 30 characters.';
    } elseif (mb_strlen($values['company']) > 100) {
        $errors['company'] = 'Company name must be 100 characters or fewer.';
    } elseif (mb_strlen($values['contact_number']) > 20) {
        $errors['contact_number'] = 'Contact number must be 20 characters or fewer.';
    } elseif (mb_strlen($values['email']) > 100 || ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = 'Enter a valid email address up to 100 characters.';
    } elseif (mb_strlen($values['municipality']) > 80) {
        $errors['municipality'] = 'Municipality must be 80 characters or fewer.';
    } elseif ($values['province'] !== '' && !isset($provinceNames[$values['province']])) {
        $errors['province'] = 'Choose a province from the list.';
    } else {
        try {
            // Save the shipper and its audit event together so they cannot get out of sync.
            $pdo->beginTransaction();
            $shipperData = [
                'name' => $values['name'],
                'sex' => $values['sex'] !== '' ? $values['sex'] : null,
                'client_type' => $values['client_type'],
                'company' => $values['company'] !== '' ? $values['company'] : null,
                'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
                'email' => $values['email'] !== '' ? $values['email'] : null,
                'municipality' => $values['municipality'] !== '' ? $values['municipality'] : null,
                'province' => $values['province'] !== '' ? $values['province'] : null,
            ];
            if ($recordId === null) {
                $saveShipper = $pdo->prepare(
                    'INSERT INTO shippers (name, sex, client_type, company, contact_number, email, municipality, province)
                     VALUES (:name, :sex, :client_type, :company, :contact_number, :email, :municipality, :province)'
                );
                $saveShipper->execute($shipperData);
                $recordId = (int) $pdo->lastInsertId();
                $auditAction = 'create';
                $auditDetails = 'Created shipper';
                $successMessage = 'Shipper added.';
            } else {
                $shipperData['id'] = $recordId;
                $saveShipper = $pdo->prepare(
                    'UPDATE shippers
                     SET name = :name, sex = :sex, client_type = :client_type, company = :company,
                         contact_number = :contact_number, email = :email, municipality = :municipality, province = :province
                     WHERE id = :id'
                );
                $saveShipper->execute($shipperData);
                if ($saveShipper->rowCount() === 0) {
                    $existsQuery = $pdo->prepare('SELECT id FROM shippers WHERE id = :id');
                    $existsQuery->execute(['id' => $recordId]);
                    if (!$existsQuery->fetchColumn()) {
                        throw new RuntimeException('This shipper no longer exists. Refresh the page and try again.');
                    }
                }
                $auditAction = 'update';
                $auditDetails = 'Updated shipper details';
                $successMessage = 'Shipper details updated.';
            }

            $logQuery = $pdo->prepare(
                'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                 VALUES (:user_id, :action, \'shipper\', :record_id, :details)'
            );
            $logQuery->execute([
                'user_id' => (int) $currentUser['id'],
                'action' => $auditAction,
                'record_id' => $recordId,
                'details' => $auditDetails,
            ]);
            $pdo->commit();
            $_SESSION['shippers_flash'] = ['type' => 'success', 'message' => $successMessage];
            header('Location: shippers.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Shipper details could not be saved. Please check the entered values and try again.';
        }
    }
}

$flash = $_SESSION['shippers_flash'] ?? null;
unset($_SESSION['shippers_flash']);
$shipperRows = $pdo->query(
    'SELECT id, name, sex, client_type, company, contact_number, email, municipality, province, is_active
     FROM shippers ORDER BY name, id'
)->fetchAll(PDO::FETCH_ASSOC);
// Preserve a province already saved on the record even if it is no longer in the current province table.
if ($values['province'] !== '' && !isset($provinceNames[$values['province']])) {
    $provinceRows[] = $values['province'];
    sort($provinceRows, SORT_NATURAL | SORT_FLAG_CASE);
}
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pageTitle = $recordId === null ? 'Shippers' : 'Edit Shipper';
$currentPage = 'shippers';
require __DIR__ . '/header.php';
?>
<!-- This page combines the add/edit form with the current shipper directory. -->
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h2 mb-0"><?= $recordId === null ? 'Shippers' : 'Edit Shipper' ?></h1>
        <?php if ($recordId !== null): ?><a class="btn btn-outline-secondary" href="shippers.php">Cancel edit</a><?php endif; ?>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $escape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= $escape($flash['message']) ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($formMessage !== null): ?><div class="alert alert-danger" role="alert"><?= $escape($formMessage) ?></div><?php endif; ?>

    <section class="card mb-4">
        <div class="card-header bg-primary text-white"><h2 class="h5 mb-0"><?= $recordId === null ? 'Add shipper' : 'Edit shipper details' ?></h2></div>
        <div class="card-body">
            <form method="post" action="shippers.php<?= $recordId !== null ? '?id=' . $recordId : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <?php if ($recordId !== null): ?><input type="hidden" name="shipper_id" value="<?= (int) $recordId ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="shipper-name">Name</label>
                        <input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="shipper-name" name="name" maxlength="100" value="<?= $escape($values['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= $escape($errors['name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="shipper-sex">Sex</label>
                        <select class="form-select<?= isset($errors['sex']) ? ' is-invalid' : '' ?>" id="shipper-sex" name="sex">
                            <option value="">Not specified</option>
                            <option value="Male" <?= $values['sex'] === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $values['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                        <?php if (isset($errors['sex'])): ?><div class="invalid-feedback"><?= $escape($errors['sex']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="shipper-client-type">Client type</label>
                        <input class="form-control<?= isset($errors['client_type']) ? ' is-invalid' : '' ?>" id="shipper-client-type" name="client_type" maxlength="30" value="<?= $escape($values['client_type']) ?>" required>
                        <?php if (isset($errors['client_type'])): ?><div class="invalid-feedback"><?= $escape($errors['client_type']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="shipper-company">Company</label>
                        <input class="form-control<?= isset($errors['company']) ? ' is-invalid' : '' ?>" id="shipper-company" name="company" maxlength="100" value="<?= $escape($values['company']) ?>">
                        <?php if (isset($errors['company'])): ?><div class="invalid-feedback"><?= $escape($errors['company']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="shipper-phone">Contact number</label>
                        <input class="form-control<?= isset($errors['contact_number']) ? ' is-invalid' : '' ?>" id="shipper-phone" name="contact_number" maxlength="20" type="tel" value="<?= $escape($values['contact_number']) ?>">
                        <?php if (isset($errors['contact_number'])): ?><div class="invalid-feedback"><?= $escape($errors['contact_number']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="shipper-email">Email</label>
                        <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="shipper-email" name="email" maxlength="100" type="email" value="<?= $escape($values['email']) ?>">
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= $escape($errors['email']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="shipper-municipality">Municipality/City</label>
                        <input class="form-control<?= isset($errors['municipality']) ? ' is-invalid' : '' ?>" id="shipper-municipality" name="municipality" maxlength="80" value="<?= $escape($values['municipality']) ?>">
                        <?php if (isset($errors['municipality'])): ?><div class="invalid-feedback"><?= $escape($errors['municipality']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="shipper-province">Province</label>
                        <select class="form-select<?= isset($errors['province']) ? ' is-invalid' : '' ?>" id="shipper-province" name="province">
                            <option value="">Choose a province</option>
                            <?php foreach ($provinceRows as $province): ?>
                                <option value="<?= $escape($province) ?>" <?= $values['province'] === $province ? 'selected' : '' ?>><?= $escape($province) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['province'])): ?><div class="invalid-feedback"><?= $escape($errors['province']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mt-3"><button class="btn btn-primary" type="submit"><?= $recordId === null ? 'Add shipper' : 'Save changes' ?></button></div>
            </form>
        </div>
    </section>

    <section class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead><tr><th scope="col">Name</th><th scope="col">Company</th><th scope="col">Contact</th><th scope="col">Email</th><th scope="col">Location</th><th scope="col">Client type</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php foreach ($shipperRows as $shipper): ?>
                        <tr>
                            <td><?= $escape($shipper['name']) ?></td>
                            <td><?= $escape($shipper['company'] ?: '—') ?></td>
                            <td><?= $escape($shipper['contact_number'] ?: '—') ?></td>
                            <td><?= $escape($shipper['email'] ?: '—') ?></td>
                            <td><?= $escape(implode(', ', array_filter([$shipper['municipality'], $shipper['province']]))) ?: '—' ?></td>
                            <td><?= $escape($shipper['client_type']) ?></td>
                            <td><span class="badge text-bg-<?= (int) $shipper['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $shipper['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="shippers.php?id=<?= (int) $shipper['id'] ?>">Edit</a>
                                <?php if (authIsAdmin()): ?>
                                    <form class="d-inline" method="post" action="shippers.php" onsubmit="return confirm('<?= (int) $shipper['is_active'] === 1 ? 'Archive' : 'Restore' ?> this shipper?')">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="contact_id" value="<?= (int) $shipper['id'] ?>">
                                        <input type="hidden" name="status" value="<?= (int) $shipper['is_active'] === 1 ? 'archive' : 'restore' ?>">
                                        <button class="btn btn-sm btn-outline-<?= (int) $shipper['is_active'] === 1 ? 'warning' : 'success' ?>" type="submit"><?= (int) $shipper['is_active'] === 1 ? 'Archive' : 'Restore' ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($shipperRows === []): ?><tr><td class="text-center py-4" colspan="8">No shippers found. Add one using the form above.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div></section>
</div>

<?php
require __DIR__ . '/footer.php';
