<?php
// Restrict this page to authorized record editors before loading consignee data.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRecordEditor();

$pdo = getDatabaseConnection();
$currentUser = authCurrentUser();
$csrfToken = authCsrfToken();
$provinceRows = $pdo->query('SELECT province_name FROM provinces ORDER BY province_name')->fetchAll(PDO::FETCH_COLUMN);
$provinceNames = array_fill_keys($provinceRows, true);
$errors = [];
$formMessage = null;
$recordId = filter_var($_GET['id'] ?? $_POST['consignee_id'] ?? null, FILTER_VALIDATE_INT);
$recordId = $recordId && $recordId > 0 ? $recordId : null;
$values = [
    'name' => '',
    'contact_number' => '',
    'municipality' => '',
    'province' => '',
];

if ($recordId !== null) {
    // Load the saved contact when the user opens the edit form.
    $consigneeQuery = $pdo->prepare('SELECT * FROM consignees WHERE id = :id');
    $consigneeQuery->execute(['id' => $recordId]);
    $consignee = $consigneeQuery->fetch(PDO::FETCH_ASSOC);
    if (!$consignee) {
        http_response_code(404);
        exit('Consignee not found.');
    }
    foreach ($values as $field => $default) {
        $values[$field] = (string) ($consignee[$field] ?? $default);
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
        $formMessage = 'The consignee status request is invalid.';
    } else {
        try {
            $pdo->beginTransaction();
            $statusQuery = $pdo->prepare('SELECT is_active FROM consignees WHERE id = :id FOR UPDATE');
            $statusQuery->execute(['id' => $contactId]);
            $currentStatus = $statusQuery->fetchColumn();
            if ($currentStatus === false) {
                throw new RuntimeException('This consignee no longer exists. Refresh the page and try again.');
            }
            if ((int) $currentStatus !== $nextActive) {
                $updateStatus = $pdo->prepare('UPDATE consignees SET is_active = :is_active WHERE id = :id');
                $updateStatus->execute(['is_active' => $nextActive, 'id' => $contactId]);
                $logStatus = $pdo->prepare(
                    'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                     VALUES (:user_id, :action, \'consignee\', :record_id, :details)'
                );
                $logStatus->execute([
                    'user_id' => (int) $currentUser['id'],
                    'action' => $nextActive ? 'restore' : 'archive',
                    'record_id' => $contactId,
                    'details' => $nextActive ? 'Restored consignee' : 'Archived consignee',
                ]);
            }
            $pdo->commit();
            $_SESSION['consignees_flash'] = [
                'type' => 'success',
                'message' => $nextActive ? 'Consignee restored.' : 'Consignee archived.',
            ];
            header('Location: consignees.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Consignee status could not be changed. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'toggle_active') {
    // Normalize form input before validating its length and province selection.
    foreach ($values as $field => $default) {
        $values[$field] = trim((string) ($_POST[$field] ?? $default));
    }

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $formMessage = 'Your session expired. Please reload the page and try again.';
    } elseif ($values['name'] === '' || mb_strlen($values['name']) > 100) {
        $errors['name'] = 'Enter a consignee name up to 100 characters.';
    } elseif (mb_strlen($values['contact_number']) > 20) {
        $errors['contact_number'] = 'Contact number must be 20 characters or fewer.';
    } elseif (mb_strlen($values['municipality']) > 80) {
        $errors['municipality'] = 'Municipality must be 80 characters or fewer.';
    } elseif ($values['province'] !== '' && !isset($provinceNames[$values['province']])) {
        $errors['province'] = 'Choose a province from the list.';
    } else {
        try {
            // Save consignee details and the matching audit event atomically.
            $pdo->beginTransaction();
            $consigneeData = [
                'name' => $values['name'],
                'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
                'municipality' => $values['municipality'] !== '' ? $values['municipality'] : null,
                'province' => $values['province'] !== '' ? $values['province'] : null,
            ];

            if ($recordId === null) {
                $saveConsignee = $pdo->prepare(
                    'INSERT INTO consignees (name, contact_number, municipality, province)
                     VALUES (:name, :contact_number, :municipality, :province)'
                );
                $saveConsignee->execute($consigneeData);
                $recordId = (int) $pdo->lastInsertId();
                $auditAction = 'create';
                $auditDetails = 'Created consignee';
                $successMessage = 'Consignee added.';
            } else {
                $consigneeData['id'] = $recordId;
                $saveConsignee = $pdo->prepare(
                    'UPDATE consignees
                     SET name = :name, contact_number = :contact_number, municipality = :municipality, province = :province
                     WHERE id = :id'
                );
                $saveConsignee->execute($consigneeData);
                if ($saveConsignee->rowCount() === 0) {
                    $existsQuery = $pdo->prepare('SELECT id FROM consignees WHERE id = :id');
                    $existsQuery->execute(['id' => $recordId]);
                    if (!$existsQuery->fetchColumn()) {
                        throw new RuntimeException('This consignee no longer exists. Refresh the page and try again.');
                    }
                }
                $auditAction = 'update';
                $auditDetails = 'Updated consignee details';
                $successMessage = 'Consignee details updated.';
            }

            $logQuery = $pdo->prepare(
                'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                 VALUES (:user_id, :action, \'consignee\', :record_id, :details)'
            );
            $logQuery->execute([
                'user_id' => (int) $currentUser['id'],
                'action' => $auditAction,
                'record_id' => $recordId,
                'details' => $auditDetails,
            ]);
            $pdo->commit();
            $_SESSION['consignees_flash'] = ['type' => 'success', 'message' => $successMessage];
            header('Location: consignees.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Consignee details could not be saved. Please check the entered values and try again.';
        }
    }
}

$flash = $_SESSION['consignees_flash'] ?? null;
unset($_SESSION['consignees_flash']);
$consigneeRows = $pdo->query(
    'SELECT id, name, contact_number, municipality, province, is_active
     FROM consignees ORDER BY name, id'
)->fetchAll(PDO::FETCH_ASSOC);
// Keep a previously saved province visible even if it has since left the province list.
if ($values['province'] !== '' && !isset($provinceNames[$values['province']])) {
    $provinceRows[] = $values['province'];
    sort($provinceRows, SORT_NATURAL | SORT_FLAG_CASE);
}
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pageTitle = $recordId === null ? 'Consignees' : 'Edit Consignee';
$currentPage = 'consignees';
require __DIR__ . '/header.php';
?>
<!-- Combine the consignee add/edit form with the saved contact directory. -->
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h2 mb-0"><?= $recordId === null ? 'Consignees' : 'Edit Consignee' ?></h1>
        <?php if ($recordId !== null): ?><a class="btn btn-outline-secondary" href="consignees.php">Cancel edit</a><?php endif; ?>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $escape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= $escape($flash['message']) ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($formMessage !== null): ?><div class="alert alert-danger" role="alert"><?= $escape($formMessage) ?></div><?php endif; ?>

    <section class="card mb-4">
        <div class="card-header bg-primary text-white"><h2 class="h5 mb-0"><?= $recordId === null ? 'Add consignee' : 'Edit consignee details' ?></h2></div>
        <div class="card-body">
            <form method="post" action="consignees.php<?= $recordId !== null ? '?id=' . $recordId : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <?php if ($recordId !== null): ?><input type="hidden" name="consignee_id" value="<?= (int) $recordId ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="consignee-name">Name</label>
                        <input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="consignee-name" name="name" maxlength="100" value="<?= $escape($values['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= $escape($errors['name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="consignee-phone">Contact number</label>
                        <input class="form-control<?= isset($errors['contact_number']) ? ' is-invalid' : '' ?>" id="consignee-phone" name="contact_number" type="tel" maxlength="20" value="<?= $escape($values['contact_number']) ?>">
                        <?php if (isset($errors['contact_number'])): ?><div class="invalid-feedback"><?= $escape($errors['contact_number']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="consignee-municipality">Municipality/City</label>
                        <input class="form-control<?= isset($errors['municipality']) ? ' is-invalid' : '' ?>" id="consignee-municipality" name="municipality" maxlength="80" value="<?= $escape($values['municipality']) ?>">
                        <?php if (isset($errors['municipality'])): ?><div class="invalid-feedback"><?= $escape($errors['municipality']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="consignee-province">Province</label>
                        <select class="form-select<?= isset($errors['province']) ? ' is-invalid' : '' ?>" id="consignee-province" name="province">
                            <option value="">Choose a province</option>
                            <?php foreach ($provinceRows as $province): ?>
                                <option value="<?= $escape($province) ?>" <?= $values['province'] === $province ? 'selected' : '' ?>><?= $escape($province) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['province'])): ?><div class="invalid-feedback"><?= $escape($errors['province']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mt-3"><button class="btn btn-primary" type="submit"><?= $recordId === null ? 'Add consignee' : 'Save changes' ?></button></div>
            </form>
        </div>
    </section>

    <section class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead><tr><th scope="col">Name</th><th scope="col">Contact</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php foreach ($consigneeRows as $consignee): ?>
                        <tr>
                            <td><?= $escape($consignee['name']) ?></td>
                            <td><?= $escape($consignee['contact_number'] ?: '—') ?></td>
                            <td><?= $escape(implode(', ', array_filter([$consignee['municipality'], $consignee['province']]))) ?: '—' ?></td>
                            <td><span class="badge text-bg-<?= (int) $consignee['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $consignee['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="consignees.php?id=<?= (int) $consignee['id'] ?>">Edit</a>
                                <?php if (authIsAdmin()): ?>
                                    <form class="d-inline" method="post" action="consignees.php" onsubmit="return confirm('<?= (int) $consignee['is_active'] === 1 ? 'Archive' : 'Restore' ?> this consignee?')">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="contact_id" value="<?= (int) $consignee['id'] ?>">
                                        <input type="hidden" name="status" value="<?= (int) $consignee['is_active'] === 1 ? 'archive' : 'restore' ?>">
                                        <button class="btn btn-sm btn-outline-<?= (int) $consignee['is_active'] === 1 ? 'warning' : 'success' ?>" type="submit"><?= (int) $consignee['is_active'] === 1 ? 'Archive' : 'Restore' ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($consigneeRows === []): ?><tr><td class="text-center py-4" colspan="5">No consignees found. Add one using the form above.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div></section>
</div>

<?php
require __DIR__ . '/footer.php';
