<?php
// Authorize permit editing and load helpers for species names and database access.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/species_catalog.php';
requireRecordEditor();

$pdo = getDatabaseConnection();
$user = authCurrentUser();
$csrfToken = authCsrfToken();
$recordId = filter_var($_GET['id'] ?? $_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
$recordId = $recordId && $recordId > 0 ? $recordId : null;
$errors = [];
$formMessage = null;
$permit = null;
$items = [];
$existingContactIds = ['shipper_id' => 0, 'consignee_id' => 0];

if ($recordId !== null) {
    // Load the active permit and its saved species rows when editing an existing record.
    $permitQuery = $pdo->prepare('SELECT * FROM outgoing_permits WHERE id = :id AND is_archived = 0');
    $permitQuery->execute(['id' => $recordId]);
    $permit = $permitQuery->fetch(PDO::FETCH_ASSOC);
    if (!$permit) {
        http_response_code(404);
        exit('Out-going record not found.');
    }
    $existingContactIds = [
        'shipper_id' => (int) ($permit['shipper_id'] ?? 0),
        'consignee_id' => (int) ($permit['consignee_id'] ?? 0),
    ];

    $itemsQuery = $pdo->prepare(
        'SELECT i.species_id,
                COALESCE(i.english_name, s.english_name) AS english_name,
                COALESCE(i.scientific_name, s.scientific_name) AS scientific_name,
                COALESCE(i.local_name, s.local_name) AS local_name,
                i.volume_kg, i.volume_pcs, i.market_value
         FROM outgoing_items AS i
         INNER JOIN species AS s ON s.id = i.species_id
         WHERE i.permit_id = :id ORDER BY i.id'
    );
    $itemsQuery->execute(['id' => $recordId]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
}

$provinceRows = $pdo->query('SELECT province_name, region_name FROM provinces ORDER BY province_name')->fetchAll(PDO::FETCH_ASSOC);
$provinceRegions = [];
// Use the province table both to validate selections and to fill each province's region.
foreach ($provinceRows as $provinceRow) {
    $provinceRegions[$provinceRow['province_name']] = $provinceRow['region_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject forged form submissions before processing contacts or permit data.
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        $formMessage = 'Your session expired. Reload the form and try again.';
    } else {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'add_shipper' || $action === 'add_consignee') {
            // Contact creation is handled separately so the permit draft can be restored afterward.
            $name = trim((string) ($_POST['name'] ?? ''));
            $municipality = trim((string) ($_POST['municipality'] ?? ''));
            $province = trim((string) ($_POST['province'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($name === '') {
                $formMessage = 'Enter a name before adding this contact.';
            } elseif ($action === 'add_shipper' && (
                mb_strlen($email) > 100 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            )) {
                $formMessage = 'Enter a valid email address or leave the email field blank.';
            } elseif ($action === 'add_shipper') {
                $insertContact = $pdo->prepare(
                    'INSERT INTO shippers (name, sex, client_type, company, contact_number, email, municipality, province)
                     VALUES (:name, :sex, :client_type, :company, :contact_number, :email, :municipality, :province)'
                );
                $insertContact->execute([
                    'name' => $name,
                    'sex' => in_array($_POST['sex'] ?? '', ['Male', 'Female'], true) ? $_POST['sex'] : null,
                    'client_type' => trim((string) ($_POST['client_type'] ?? 'Regular')) ?: 'Regular',
                    'company' => trim((string) ($_POST['company'] ?? '')) ?: null,
                    'contact_number' => trim((string) ($_POST['contact_number'] ?? '')) ?: null,
                    'email' => $email ?: null,
                    'municipality' => $municipality ?: null,
                    'province' => $province ?: null,
                ]);
                $newContactId = (int) $pdo->lastInsertId();
                $logContact = $pdo->prepare(
                    'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                     VALUES (:user_id, \'create\', \'shipper\', :record_id, \'Added shipper from out-going form\')'
                );
                $logContact->execute(['user_id' => (int) $user['id'], 'record_id' => $newContactId]);
                $_SESSION['outgoing_form_flash'] = ['type' => 'success', 'message' => 'Shipper added.'];
                header('Location: outgoing_form.php' . ($recordId ? '?id=' . $recordId . '&' : '?') . 'shipper_id=' . $newContactId);
                exit;
            } else {
                $insertContact = $pdo->prepare(
                    'INSERT INTO consignees (name, contact_number, municipality, province)
                     VALUES (:name, :contact_number, :municipality, :province)'
                );
                $insertContact->execute([
                    'name' => $name,
                    'contact_number' => trim((string) ($_POST['contact_number'] ?? '')) ?: null,
                    'municipality' => $municipality ?: null,
                    'province' => $province ?: null,
                ]);
                $newContactId = (int) $pdo->lastInsertId();
                $logContact = $pdo->prepare(
                    'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                     VALUES (:user_id, \'create\', \'consignee\', :record_id, \'Added consignee from out-going form\')'
                );
                $logContact->execute(['user_id' => (int) $user['id'], 'record_id' => $newContactId]);
                $_SESSION['outgoing_form_flash'] = ['type' => 'success', 'message' => 'Consignee added.'];
                header('Location: outgoing_form.php' . ($recordId ? '?id=' . $recordId . '&' : '?') . 'consignee_id=' . $newContactId);
                exit;
            }
        } elseif ($action === 'save') {
            // Collect all submitted permit fields and species rows for validation.
            $formValues = [
                'shipment_date' => trim((string) ($_POST['shipment_date'] ?? '')),
                'amount' => trim((string) ($_POST['amount'] ?? '')),
                'ltp_code' => trim((string) ($_POST['ltp_code'] ?? '')),
                'operating_unit' => trim((string) ($_POST['operating_unit'] ?? '')),
                'issuing_province' => trim((string) ($_POST['issuing_province'] ?? '')),
                'ltp_issuance_location' => trim((string) ($_POST['ltp_issuance_location'] ?? '')),
                'product_form' => trim((string) ($_POST['product_form'] ?? '')),
                'product_category' => trim((string) ($_POST['product_category'] ?? '')),
                'cit_cha_classification' => trim((string) ($_POST['cit_cha_classification'] ?? '')),
                'shipper_id' => trim((string) ($_POST['shipper_id'] ?? '')),
                'source_barangay' => trim((string) ($_POST['source_barangay'] ?? '')),
                'source_municipality' => trim((string) ($_POST['source_municipality'] ?? '')),
                'source_province' => trim((string) ($_POST['source_province'] ?? '')),
                'source_region' => trim((string) ($_POST['source_region'] ?? '')),
                'destination_barangay' => trim((string) ($_POST['destination_barangay'] ?? '')),
                'destination_municipality' => trim((string) ($_POST['destination_municipality'] ?? '')),
                'destination_province' => trim((string) ($_POST['destination_province'] ?? '')),
                'destination_region' => trim((string) ($_POST['destination_region'] ?? '')),
                'consignee_id' => trim((string) ($_POST['consignee_id'] ?? '')),
                'purpose_of_transport' => trim((string) ($_POST['purpose_of_transport'] ?? '')),
                'spsic_no' => trim((string) ($_POST['spsic_no'] ?? '')),
                'inspected' => trim((string) ($_POST['inspected'] ?? 'Not Inspected')),
                'remarks' => trim((string) ($_POST['remarks'] ?? '')),
            ];
            $postedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

            $date = DateTime::createFromFormat('!Y-m-d', $formValues['shipment_date']);
            if (!$date || $date->format('Y-m-d') !== $formValues['shipment_date']) {
                $errors['shipment_date'] = 'Enter a valid shipment date.';
            }
            if ($formValues['ltp_code'] === '' || mb_strlen($formValues['ltp_code']) > 40) {
                $errors['ltp_code'] = 'Enter an LTP code up to 40 characters.';
            } else {
                $duplicateQuery = $pdo->prepare('SELECT id FROM outgoing_permits WHERE ltp_code = :code AND (:record_id IS NULL OR id <> :record_id) LIMIT 1');
                $duplicateQuery->execute(['code' => $formValues['ltp_code'], 'record_id' => $recordId]);
                if ($duplicateQuery->fetchColumn()) {
                    $errors['ltp_code'] = 'This LTP code is already in use.';
                }
            }
            if ($formValues['amount'] === '' || !is_numeric($formValues['amount']) || (float) $formValues['amount'] < 0) {
                $errors['amount'] = 'Enter a non-negative amount.';
            }
            if ($formValues['operating_unit'] === '') {
                $errors['operating_unit'] = 'Enter the operating unit.';
            }
            if ($formValues['issuing_province'] === '') {
                $errors['issuing_province'] = 'Enter the issuing province.';
            }
            foreach (['shipper_id', 'consignee_id'] as $requiredId) {
                if (!filter_var($formValues[$requiredId], FILTER_VALIDATE_INT)) {
                    $errors[$requiredId] = 'Choose a saved contact or add a new one.';
                }
            }
            if ($formValues['destination_municipality'] === '') {
                $errors['destination_municipality'] = 'Enter the destination municipality or city.';
            }
            if ($formValues['destination_province'] === '' || !isset($provinceRegions[$formValues['destination_province']])) {
                $errors['destination_province'] = 'Choose a province from the list.';
            }
            if ($formValues['source_province'] !== '' && !isset($provinceRegions[$formValues['source_province']])) {
                $errors['source_province'] = 'Choose a province from the list.';
            }
            if (!in_array($formValues['inspected'], ['Inspected', 'Not Inspected'], true)) {
                $errors['inspected'] = 'Choose an inspection status.';
            }
            if ($formValues['purpose_of_transport'] === '') {
                $errors['purpose_of_transport'] = 'Enter the purpose of transport.';
            }
            foreach ([
                'operating_unit' => 80,
                'issuing_province' => 80,
                'ltp_issuance_location' => 80,
                'product_form' => 30,
                'product_category' => 30,
                'cit_cha_classification' => 30,
                'source_barangay' => 80,
                'source_municipality' => 80,
                'source_province' => 80,
                'source_region' => 80,
                'destination_barangay' => 100,
                'destination_municipality' => 80,
                'destination_province' => 80,
                'destination_region' => 80,
                'purpose_of_transport' => 40,
                'spsic_no' => 40,
                'remarks' => 255,
            ] as $field => $maxLength) {
                if (mb_strlen($formValues[$field]) > $maxLength) {
                    $errors[$field] = 'This field must be ' . $maxLength . ' characters or fewer.';
                }
            }
            if ($formValues['amount'] !== '' && is_numeric($formValues['amount']) && (float) $formValues['amount'] > 9999999999.99) {
                $errors['amount'] = 'The amount is too large.';
            }
            foreach (['shipper_id' => 'shippers', 'consignee_id' => 'consignees'] as $contactField => $tableName) {
                if (!isset($errors[$contactField])) {
                    $contactQuery = $pdo->prepare(
                        'SELECT id FROM ' . $tableName . ' WHERE id = :id AND (is_active = 1 OR id = :existing_id)'
                    );
                    $contactQuery->execute([
                        'id' => $formValues[$contactField],
                        'existing_id' => $existingContactIds[$contactField],
                    ]);
                    if (!$contactQuery->fetchColumn()) {
                        $errors[$contactField] = 'Choose a saved contact or add a new one.';
                    }
                }
            }

            $cleanItems = [];
            foreach ($postedItems as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $speciesId = filter_var($row['species_id'] ?? null, FILTER_VALIDATE_INT);
                $englishName = trim((string) ($row['english_name'] ?? ''));
                $scientificName = trim((string) ($row['scientific_name'] ?? ''));
                $localName = trim((string) ($row['local_name'] ?? ''));
                $kgText = trim((string) ($row['volume_kg'] ?? ''));
                $pcsText = trim((string) ($row['volume_pcs'] ?? ''));
                $valueText = trim((string) ($row['market_value'] ?? ''));
                if (!$speciesId && $englishName === '' && $scientificName === '' && $localName === '' && $kgText === '' && $pcsText === '' && $valueText === '') {
                    continue;
                }
                if ($englishName === '' || mb_strlen($englishName) > 100 || mb_strlen($scientificName) > 150 || mb_strlen($localName) > 100) {
                    $errors['items'] = 'Enter a species name (up to 100 characters) and keep scientific and local names within their field limits.';
                    continue;
                }
                $kg = $kgText === '' ? 0 : (is_numeric($kgText) ? (float) $kgText : -1);
                $pcs = $pcsText === '' ? 0 : filter_var($pcsText, FILTER_VALIDATE_INT);
                $value = $valueText === '' ? 0 : (is_numeric($valueText) ? (float) $valueText : -1);
                if ($kg < 0 || $pcs === false || $pcs < 0 || $value < 0) {
                    $errors['items'] = 'Species volumes and market value must be non-negative numbers.';
                    continue;
                }
                if ($kg > 99999999.99 || $value > 999999999999.99) {
                    $errors['items'] = 'A species volume or market value is too large.';
                    continue;
                }
                if ($speciesId) {
                    $speciesCheck = $pdo->prepare('SELECT id FROM species WHERE id = :id AND is_active = 1');
                    $speciesCheck->execute(['id' => $speciesId]);
                    if (!$speciesCheck->fetchColumn()) {
                        $errors['items'] = 'Choose an active species from the list or enter a new species name.';
                        continue;
                    }
                }
                if ($kg == 0 && $pcs == 0) {
                    $errors['items'] = 'Enter kilograms or pieces for each species.';
                    continue;
                }
                $cleanItems[] = [
                    'species_id' => $speciesId ?: null,
                    'english_name' => $englishName,
                    'scientific_name' => $scientificName ?: null,
                    'local_name' => $localName ?: null,
                    'volume_kg' => $kg,
                    'volume_pcs' => $pcs,
                    'market_value' => $value,
                ];
            }
            if ($cleanItems === []) {
                $errors['items'] = 'Add at least one species row with a volume.';
            }

            if ($errors === []) {
                // Save the permit, its item rows, and its audit entry atomically.
                $formValues['source_region'] = $provinceRegions[$formValues['source_province']] ?? '';
                $formValues['destination_region'] = $provinceRegions[$formValues['destination_province']];
                try {
                    $pdo->beginTransaction();
                    $permitFields = [
                        'shipment_date', 'amount', 'ltp_code', 'operating_unit', 'issuing_province',
                        'ltp_issuance_location', 'product_form', 'product_category', 'cit_cha_classification',
                        'shipper_id', 'source_barangay', 'source_municipality', 'source_province', 'source_region',
                        'destination_barangay', 'destination_municipality', 'destination_province', 'destination_region',
                        'consignee_id', 'purpose_of_transport', 'spsic_no', 'inspected', 'remarks',
                    ];
                    $permitData = [];
                    foreach ($permitFields as $field) {
                        $permitData[$field] = $formValues[$field] === '' ? null : $formValues[$field];
                    }
                    if ($recordId === null) {
                        $permitData['created_by'] = (int) $user['id'];
                        $insertPermit = $pdo->prepare(
                            'INSERT INTO outgoing_permits (' . implode(', ', array_keys($permitData)) . ')
                             VALUES (:' . implode(', :', array_keys($permitData)) . ')'
                        );
                        $insertPermit->execute($permitData);
                        $recordId = (int) $pdo->lastInsertId();
                        $auditAction = 'create';
                    } else {
                        $assignments = array_map(static fn ($field) => $field . ' = :' . $field, array_keys($permitData));
                        $permitData['id'] = $recordId;
                        $updatePermit = $pdo->prepare('UPDATE outgoing_permits SET ' . implode(', ', $assignments) . ' WHERE id = :id AND is_archived = 0');
                        $updatePermit->execute($permitData);
                        $pdo->prepare('DELETE FROM outgoing_items WHERE permit_id = :id')->execute(['id' => $recordId]);
                        $auditAction = 'update';
                    }

                    $insertItem = $pdo->prepare(
                        'INSERT INTO outgoing_items (permit_id, species_id, english_name, scientific_name, local_name, volume_kg, volume_pcs, market_value)
                         VALUES (:permit_id, :species_id, :english_name, :scientific_name, :local_name, :volume_kg, :volume_pcs, :market_value)'
                    );
                    foreach ($cleanItems as $item) {
                        $item['species_id'] = resolveSpeciesCatalogId(
                            $pdo,
                            $item['species_id'],
                            $item['english_name'],
                            $item['scientific_name'],
                            $item['local_name']
                        );
                        $insertItem->execute([
                            'permit_id' => $recordId,
                            'species_id' => $item['species_id'],
                            'english_name' => $item['english_name'],
                            'scientific_name' => $item['scientific_name'],
                            'local_name' => $item['local_name'],
                            'volume_kg' => $item['volume_kg'],
                            'volume_pcs' => $item['volume_pcs'],
                            'market_value' => $item['market_value'],
                        ]);
                    }
                    $logQuery = $pdo->prepare(
                        'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                         VALUES (:user_id, :action, \'outgoing_permit\', :record_id, :details)'
                    );
                    $logQuery->execute([
                        'user_id' => (int) $user['id'],
                        'action' => $auditAction,
                        'record_id' => $recordId,
                        'details' => $auditAction === 'create' ? 'Created out-going permit' : 'Updated out-going permit',
                    ]);
                    $pdo->commit();
                    $_SESSION['outgoing_flash'] = ['type' => 'success', 'message' => $auditAction === 'create' ? 'Out-going record saved.' : 'Out-going record updated.'];
                    header('Location: outgoing.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($exception instanceof PDOException && $exception->getCode() === '23000' && str_contains($exception->getMessage(), 'ltp')) {
                        $errors['ltp_code'] = 'This LTP code is already in use.';
                    } elseif ($exception instanceof RuntimeException) {
                        $formMessage = $exception->getMessage();
                    } else {
                        $formMessage = 'The record could not be saved. Check the entered values and try again.';
                    }
                }
            }
            $permit = array_merge($permit ?? [], $formValues);
            $items = $postedItems;
        }
    }
}

$flash = $_SESSION['outgoing_form_flash'] ?? null;
unset($_SESSION['outgoing_form_flash']);
$speciesRows = $pdo->query('SELECT id, english_name, scientific_name, local_name FROM species WHERE is_active = 1 ORDER BY english_name')->fetchAll(PDO::FETCH_ASSOC);
// Populate contact dropdowns from saved directory records.
$shipperQuery = $pdo->prepare('SELECT id, name, company, municipality, province, is_active FROM shippers WHERE is_active = 1 OR id = :existing_id ORDER BY name');
$shipperQuery->execute(['existing_id' => $existingContactIds['shipper_id']]);
$shippers = $shipperQuery->fetchAll(PDO::FETCH_ASSOC);
$consigneeQuery = $pdo->prepare('SELECT id, name, municipality, province, is_active FROM consignees WHERE is_active = 1 OR id = :existing_id ORDER BY name');
$consigneeQuery->execute(['existing_id' => $existingContactIds['consignee_id']]);
$consignees = $consigneeQuery->fetchAll(PDO::FETCH_ASSOC);

if ($items === []) {
    $items = [['species_id' => '', 'volume_kg' => '', 'volume_pcs' => '', 'market_value' => '']];
}
$oldInput = static function (string $field, $default = '') use (&$permit, &$formMessage): string {
    $source = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST[$field] ?? null) : null;
    if ($source !== null) {
        return is_scalar($source) ? (string) $source : (string) $default;
    }
    return isset($permit[$field]) && $permit[$field] !== null ? (string) $permit[$field] : (string) $default;
};
$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$fieldFeedback = static fn (string $field): string => isset($errors[$field]) ? '<div class="invalid-feedback">' . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8') . '</div>' : '';
$restoreContactField = isset($_GET['shipper_id']) ? 'shipper_id' : (isset($_GET['consignee_id']) ? 'consignee_id' : '');
$restoreDraft = is_array($flash);
$pageTitle = $recordId === null ? 'Add Out-going Record' : 'Edit Out-going Record';
$currentPage = 'outgoing';
require __DIR__ . '/header.php';
?>
<!-- Permit details and species items are submitted together in this edit form. -->
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h2 mb-0"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <a class="btn btn-secondary" href="outgoing.php">Cancel</a>
    </div>
    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($formMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($formMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="outgoing_form.php<?= $recordId ? '?id=' . $recordId : '' ?>" id="outgoing-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="record_id" value="<?= $recordId ?? '' ?>">
        <input type="hidden" name="action" value="save">

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Permit Details</h2></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="shipment_date">Shipment date</label><input class="form-control<?= $fieldClass('shipment_date') ?>" id="shipment_date" name="shipment_date" type="date" value="<?= htmlspecialchars($oldInput('shipment_date'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('shipment_date') ?></div>
                <div class="col-md-4"><label class="form-label" for="amount">Amount</label><input class="form-control<?= $fieldClass('amount') ?>" id="amount" name="amount" type="number" min="0" step="0.01" value="<?= htmlspecialchars($oldInput('amount'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('amount') ?></div>
                <div class="col-md-4"><label class="form-label" for="ltp_code">LTP code</label><input class="form-control<?= $fieldClass('ltp_code') ?>" id="ltp_code" name="ltp_code" maxlength="40" value="<?= htmlspecialchars($oldInput('ltp_code'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('ltp_code') ?></div>
                <div class="col-md-4"><label class="form-label" for="operating_unit">Operating unit</label><input class="form-control<?= $fieldClass('operating_unit') ?>" id="operating_unit" name="operating_unit" value="<?= htmlspecialchars($oldInput('operating_unit', 'PFO-Siquijor'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('operating_unit') ?></div>
                <div class="col-md-4"><label class="form-label" for="issuing_province">Issuing province</label><input class="form-control<?= $fieldClass('issuing_province') ?>" id="issuing_province" name="issuing_province" value="<?= htmlspecialchars($oldInput('issuing_province', 'Siquijor'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('issuing_province') ?></div>
                <div class="col-md-4"><label class="form-label" for="ltp_issuance_location">Location of LTP issuance</label><input class="form-control" id="ltp_issuance_location" name="ltp_issuance_location" value="<?= htmlspecialchars($oldInput('ltp_issuance_location'), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div></div>
        </section>

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Product Details</h2></div>
            <div class="card-body"><div class="row g-3 mb-4">
                <div class="col-md-4"><label class="form-label" for="product_form">Product form</label><input class="form-control" id="product_form" name="product_form" value="<?= htmlspecialchars($oldInput('product_form'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label" for="product_category">Product category</label><input class="form-control" id="product_category" name="product_category" value="<?= htmlspecialchars($oldInput('product_category'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label" for="cit_cha_classification">CIT-CHA classification</label><input class="form-control" id="cit_cha_classification" name="cit_cha_classification" value="<?= htmlspecialchars($oldInput('cit_cha_classification'), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
                <h3 class="h6">Species rows</h3>
                <p class="form-text">Choose a catalog species, or leave it unselected and enter a new species name to add it to the catalog for future permits. New species are grouped as Other.</p>
                <?php if (isset($errors['items'])): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($errors['items'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <div id="species-rows" class="vstack gap-3">
                    <?php foreach ($items as $index => $item): ?>
                        <div class="border rounded p-3 species-row"><div class="row g-3 align-items-end">
                            <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-species">Species catalog entry (optional)</label><select class="form-select species-select" id="items-<?= (int) $index ?>-species" name="items[<?= (int) $index ?>][species_id]"><option value="">Enter a new species below</option><?php foreach ($speciesRows as $species): ?><option value="<?= (int) $species['id'] ?>" data-english="<?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?>" data-scientific="<?= htmlspecialchars($species['scientific_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-local="<?= htmlspecialchars($species['local_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($item['species_id'] ?? '') === (string) $species['id'] ? 'selected' : '' ?>><?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-english">Species name</label><input class="form-control english-name" id="items-<?= (int) $index ?>-english" name="items[<?= (int) $index ?>][english_name]" maxlength="100" value="<?= htmlspecialchars((string) ($item['english_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></div>
                            <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-scientific">Scientific name</label><input class="form-control scientific-name" id="items-<?= (int) $index ?>-scientific" name="items[<?= (int) $index ?>][scientific_name]" maxlength="150" value="<?= htmlspecialchars((string) ($item['scientific_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="items-<?= (int) $index ?>-local">Local name</label><input class="form-control local-name" id="items-<?= (int) $index ?>-local" name="items[<?= (int) $index ?>][local_name]" maxlength="100" value="<?= htmlspecialchars((string) ($item['local_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-md-3"><button class="btn btn-outline-danger remove-species" type="button">Remove species</button></div>
                            <div class="col-md-3"><label class="form-label" for="items-<?= (int) $index ?>-kg">Volume kg</label><input class="form-control" id="items-<?= (int) $index ?>-kg" name="items[<?= (int) $index ?>][volume_kg]" type="number" min="0" step="0.001" value="<?= htmlspecialchars((string) ($item['volume_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="items-<?= (int) $index ?>-pcs">Volume pcs</label><input class="form-control" id="items-<?= (int) $index ?>-pcs" name="items[<?= (int) $index ?>][volume_pcs]" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($item['volume_pcs'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-value">Market value</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" id="items-<?= (int) $index ?>-value" name="items[<?= (int) $index ?>][market_value]" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) ($item['market_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div></div>
                        </div></div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-outline-primary mt-3" id="add-species" type="button">Add another species</button>
            </div>
        </section>

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Shipper</h2></div>
            <div class="card-body"><div class="row g-3 align-items-end">
                <div class="col-md-8"><label class="form-label" for="shipper_id">Saved shipper</label><select class="form-select<?= $fieldClass('shipper_id') ?>" id="shipper_id" name="shipper_id" required><option value="">Choose a shipper</option><?php foreach ($shippers as $shipper): ?><option value="<?= (int) $shipper['id'] ?>" <?= $oldInput('shipper_id', $_GET['shipper_id'] ?? '') === (string) $shipper['id'] ? 'selected' : '' ?>><?= htmlspecialchars($shipper['name'] . ($shipper['company'] ? ' - ' . $shipper['company'] : '') . ((int) $shipper['is_active'] === 1 ? '' : ' (Inactive - existing permit)'), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('shipper_id') ?></div>
                <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" data-bs-toggle="modal" data-bs-target="#shipper-modal">Add new shipper</button></div>
            </div></div>
        </section>

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Product Source</h2></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="source_barangay">Barangay</label><input class="form-control" id="source_barangay" name="source_barangay" value="<?= htmlspecialchars($oldInput('source_barangay'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-3"><label class="form-label" for="source_municipality">Municipality/City</label><input class="form-control" id="source_municipality" name="source_municipality" value="<?= htmlspecialchars($oldInput('source_municipality'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-3"><label class="form-label" for="source_province">Province</label><select class="form-select<?= $fieldClass('source_province') ?> province-select" id="source_province" name="source_province"><option value="">Choose a province</option><?php foreach ($provinceRows as $province): ?><option value="<?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?>" data-region="<?= htmlspecialchars($province['region_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $oldInput('source_province') === $province['province_name'] ? 'selected' : '' ?>><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('source_province') ?></div>
                <div class="col-md-3"><label class="form-label" for="source_region">Region</label><input class="form-control region-field" id="source_region" name="source_region" value="<?= htmlspecialchars($oldInput('source_region'), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
            </div></div>
        </section>

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Product Destination</h2></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="destination_barangay">Barangay</label><input class="form-control" id="destination_barangay" name="destination_barangay" value="<?= htmlspecialchars($oldInput('destination_barangay'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-3"><label class="form-label" for="destination_municipality">Municipality/City</label><input class="form-control<?= $fieldClass('destination_municipality') ?>" id="destination_municipality" name="destination_municipality" value="<?= htmlspecialchars($oldInput('destination_municipality'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('destination_municipality') ?></div>
                <div class="col-md-3"><label class="form-label" for="destination_province">Province</label><select class="form-select<?= $fieldClass('destination_province') ?> province-select" id="destination_province" name="destination_province" required><option value="">Choose a province</option><?php foreach ($provinceRows as $province): ?><option value="<?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?>" data-region="<?= htmlspecialchars($province['region_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $oldInput('destination_province') === $province['province_name'] ? 'selected' : '' ?>><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('destination_province') ?></div>
                <div class="col-md-3"><label class="form-label" for="destination_region">Region</label><input class="form-control region-field" id="destination_region" name="destination_region" value="<?= htmlspecialchars($oldInput('destination_region'), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
            </div></div>
        </section>

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Consignee</h2></div>
            <div class="card-body"><div class="row g-3 align-items-end">
                <div class="col-md-8"><label class="form-label" for="consignee_id">Saved consignee</label><select class="form-select<?= $fieldClass('consignee_id') ?>" id="consignee_id" name="consignee_id" required><option value="">Choose a consignee</option><?php foreach ($consignees as $consignee): ?><option value="<?= (int) $consignee['id'] ?>" <?= $oldInput('consignee_id', $_GET['consignee_id'] ?? '') === (string) $consignee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($consignee['name'] . ($consignee['municipality'] ? ' - ' . $consignee['municipality'] : '') . ((int) $consignee['is_active'] === 1 ? '' : ' (Inactive - existing permit)'), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('consignee_id') ?></div>
                <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" data-bs-toggle="modal" data-bs-target="#consignee-modal">Add new consignee</button></div>
            </div></div>
        </section>

        <section class="card mb-4">
            <div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Transport and Inspection</h2></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="purpose_of_transport">Purpose of transport</label><input class="form-control<?= $fieldClass('purpose_of_transport') ?>" id="purpose_of_transport" name="purpose_of_transport" value="<?= htmlspecialchars($oldInput('purpose_of_transport', 'For commercial'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('purpose_of_transport') ?></div>
                <div class="col-md-4"><label class="form-label" for="spsic_no">SPSIC no. (optional)</label><input class="form-control" id="spsic_no" name="spsic_no" value="<?= htmlspecialchars($oldInput('spsic_no'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label" for="inspected">Inspection status</label><select class="form-select" id="inspected" name="inspected"><option value="Inspected" <?= $oldInput('inspected', 'Inspected') === 'Inspected' ? 'selected' : '' ?>>Inspected</option><option value="Not Inspected" <?= $oldInput('inspected', 'Inspected') === 'Not Inspected' ? 'selected' : '' ?>>Not Inspected</option></select></div>
                <div class="col-12"><label class="form-label" for="remarks">Remarks</label><textarea class="form-control" id="remarks" name="remarks" maxlength="255" rows="3"><?= htmlspecialchars($oldInput('remarks'), ENT_QUOTES, 'UTF-8') ?></textarea></div>
            </div></div>
        </section>

        <div class="d-flex gap-2 mb-4"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-secondary" href="outgoing.php">Cancel</a></div>
    </form>
</div>

<div class="modal fade" id="shipper-modal" tabindex="-1" aria-labelledby="shipper-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" action="shipper_create.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-header"><h2 class="modal-title h5" id="shipper-modal-title">Add new shipper</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="alert alert-danger d-none shipper-save-feedback" role="alert"></div><div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="shipper-name">Name</label><input class="form-control" id="shipper-name" name="name" maxlength="100" required></div>
                <div class="col-md-3"><label class="form-label" for="shipper-sex">Sex</label><select class="form-select" id="shipper-sex" name="sex"><option value="">Not specified</option><option>Female</option><option>Male</option></select></div>
                <div class="col-md-3"><label class="form-label" for="shipper-client-type">Client type</label><input class="form-control" id="shipper-client-type" name="client_type" maxlength="30" value="Regular"></div>
                <div class="col-md-6"><label class="form-label" for="shipper-company">Company</label><input class="form-control" id="shipper-company" name="company" maxlength="100"></div>
                <div class="col-md-6"><label class="form-label" for="shipper-phone">Contact number</label><input class="form-control" id="shipper-phone" name="contact_number" type="tel" maxlength="20"></div>
                <div class="col-md-6"><label class="form-label" for="shipper-email">Email</label><input class="form-control" id="shipper-email" name="email" type="email" maxlength="100"></div>
                <div class="col-md-3"><label class="form-label" for="shipper-municipality">Municipality/City</label><input class="form-control" id="shipper-municipality" name="municipality" maxlength="80"></div>
                <div class="col-md-3"><label class="form-label" for="shipper-province">Province</label><select class="form-select" id="shipper-province" name="province"><option value="">Choose a province</option><?php foreach ($provinceRows as $province): ?><option value="<?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            </div></div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save shipper</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="consignee-modal" tabindex="-1" aria-labelledby="consignee-modal-title" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" action="outgoing_form.php<?= $recordId ? '?id=' . $recordId : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="add_consignee">
            <div class="modal-header"><h2 class="modal-title h5" id="consignee-modal-title">Add new consignee</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-12"><label class="form-label" for="consignee-name">Name</label><input class="form-control" id="consignee-name" name="name" maxlength="100" required></div>
                <div class="col-12"><label class="form-label" for="consignee-phone">Contact number</label><input class="form-control" id="consignee-phone" name="contact_number" type="tel" maxlength="20"></div>
                <div class="col-md-6"><label class="form-label" for="consignee-municipality">Municipality/City</label><input class="form-control" id="consignee-municipality" name="municipality" maxlength="80"></div>
                <div class="col-md-6"><label class="form-label" for="consignee-province">Province</label><select class="form-select" id="consignee-province" name="province"><option value="">Choose a province</option><?php foreach ($provinceRows as $province): ?><option value="<?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            </div></div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save consignee</button></div>
        </form>
    </div></div>
</div>

<template id="species-row-template">
    <div class="border rounded p-3 species-row"><div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-species">Species catalog entry (optional)</label><select class="form-select species-select" id="items-__INDEX__-species" name="items[__INDEX__][species_id]"><option value="">Enter a new species below</option><?php foreach ($speciesRows as $species): ?><option value="<?= (int) $species['id'] ?>" data-english="<?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?>" data-scientific="<?= htmlspecialchars($species['scientific_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-local="<?= htmlspecialchars($species['local_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-english">Species name</label><input class="form-control english-name" id="items-__INDEX__-english" name="items[__INDEX__][english_name]" maxlength="100" required></div>
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-scientific">Scientific name</label><input class="form-control scientific-name" id="items-__INDEX__-scientific" name="items[__INDEX__][scientific_name]" maxlength="150"></div>
        <div class="col-md-3"><label class="form-label" for="items-__INDEX__-local">Local name</label><input class="form-control local-name" id="items-__INDEX__-local" name="items[__INDEX__][local_name]" maxlength="100"></div>
        <div class="col-md-3"><button class="btn btn-outline-danger remove-species" type="button">Remove species</button></div>
        <div class="col-md-3"><label class="form-label" for="items-__INDEX__-kg">Volume kg</label><input class="form-control" id="items-__INDEX__-kg" name="items[__INDEX__][volume_kg]" type="number" min="0" step="0.001"></div>
        <div class="col-md-3"><label class="form-label" for="items-__INDEX__-pcs">Volume pcs</label><input class="form-control" id="items-__INDEX__-pcs" name="items[__INDEX__][volume_pcs]" type="number" min="0" step="1"></div>
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-value">Market value</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" id="items-__INDEX__-value" name="items[__INDEX__][market_value]" type="number" min="0" step="0.01"></div></div>
    </div></div>
</template>

<script>
// Keep catalog names synchronized with a species selection while allowing new names to be typed.
const speciesRows = document.getElementById('species-rows');
const speciesTemplate = document.getElementById('species-row-template');
let nextSpeciesIndex = speciesRows.querySelectorAll('.species-row').length;

function updateSpeciesNames(select, replaceExisting = false) {
    const row = select.closest('.species-row');
    const option = select.selectedOptions[0];
    const englishName = row.querySelector('.english-name');
    const scientificName = row.querySelector('.scientific-name');
    const localName = row.querySelector('.local-name');
    if (replaceExisting || !englishName.value) englishName.value = option?.dataset.english ?? '';
    if (replaceExisting || !scientificName.value) scientificName.value = option?.dataset.scientific ?? '';
    if (replaceExisting || !localName.value) localName.value = option?.dataset.local ?? '';
}

function updateProvinceRegion(select) {
    const target = document.getElementById(select.id.replace('_province', '_region'));
    if (target) {
        target.value = select.selectedOptions[0]?.dataset.region ?? '';
    }
}

speciesRows.addEventListener('change', (event) => {
    if (event.target.matches('.species-select')) updateSpeciesNames(event.target, true);
});
speciesRows.addEventListener('input', (event) => {
    if (event.target.matches('.english-name')) {
        const row = event.target.closest('.species-row');
        const select = row.querySelector('.species-select');
        const selectedName = select.selectedOptions[0]?.dataset.english ?? '';
        if (event.target.value.trim().toLocaleLowerCase() !== selectedName.toLocaleLowerCase()) select.value = '';
    }
});
speciesRows.addEventListener('click', (event) => {
    const removeButton = event.target.closest('.remove-species');
    if (removeButton) removeButton.closest('.species-row').remove();
});
speciesRows.querySelectorAll('.species-select').forEach(updateSpeciesNames);
document.getElementById('add-species').addEventListener('click', () => {
    const rowHtml = speciesTemplate.innerHTML.replaceAll('__INDEX__', String(nextSpeciesIndex++));
    speciesRows.insertAdjacentHTML('beforeend', rowHtml);
});
document.querySelectorAll('.province-select').forEach((select) => {
    select.addEventListener('change', () => updateProvinceRegion(select));
    updateProvinceRegion(select);
});
document.querySelectorAll('#consignee-modal form').forEach((contactForm) => {
    contactForm.addEventListener('submit', () => {
        const entries = Array.from(new FormData(document.getElementById('outgoing-form')).entries());
        sessionStorage.setItem('outgoing-permit-draft', JSON.stringify(entries));
    });
});
const shipperModal = document.getElementById('shipper-modal');
const shipperForm = shipperModal.querySelector('form');
// Create a shipper in the background so the permit page does not reload or lose entered values.
shipperForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = shipperForm.querySelector('[type="submit"]');
    const feedback = shipperForm.querySelector('.shipper-save-feedback');
    submitButton.disabled = true;
    feedback.classList.add('d-none');
    try {
        const response = await fetch(shipperForm.action, {
            method: 'POST',
            body: new FormData(shipperForm),
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error ?? 'Shipper could not be saved. Please try again.');

        const select = document.getElementById('shipper_id');
        const option = document.createElement('option');
        option.value = String(result.shipper.id);
        option.textContent = result.shipper.name + (result.shipper.company ? ' - ' + result.shipper.company : '');
        select.add(option);
        select.value = option.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        shipperForm.reset();
        bootstrap.Modal.getOrCreateInstance(shipperModal).hide();
    } catch (error) {
        feedback.textContent = error instanceof Error ? error.message : 'Shipper could not be saved. Please try again.';
        feedback.classList.remove('d-none');
    } finally {
        submitButton.disabled = false;
    }
});
if (<?= $restoreDraft ? 'true' : 'false' ?>) {
    // Restore the serialized permit draft after a contact was added, then select that contact.
    const savedDraft = sessionStorage.getItem('outgoing-permit-draft');
    if (savedDraft) {
        const entries = JSON.parse(savedDraft);
        const itemIndexes = entries.map(([name]) => name.match(/^items\[(\d+)\]/)?.[1]).filter((index) => index !== undefined);
        const requiredRows = itemIndexes.length ? Math.max(...itemIndexes.map(Number)) + 1 : 1;
        while (speciesRows.querySelectorAll('.species-row').length < requiredRows) {
            const rowHtml = speciesTemplate.innerHTML.replaceAll('__INDEX__', String(nextSpeciesIndex++));
            speciesRows.insertAdjacentHTML('beforeend', rowHtml);
        }
        entries.forEach(([name, fieldValue]) => {
            if (name === <?= json_encode($restoreContactField) ?>) return;
            const field = document.getElementById('outgoing-form').elements.namedItem(name);
            if (field) field.value = fieldValue;
        });
        speciesRows.querySelectorAll('.species-select').forEach(updateSpeciesNames);
        document.querySelectorAll('.province-select').forEach(updateProvinceRegion);
        sessionStorage.removeItem('outgoing-permit-draft');
    }
}
</script>

<?php
require __DIR__ . '/footer.php';
