<?php
// Load authentication, database, and species helpers before processing the form.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/species_catalog.php';
requireRecordEditor();

// Keep shared request data available to both the form logic and the HTML below.
$pdo = getDatabaseConnection();
$user = authCurrentUser();
$csrfToken = authCsrfToken();
// Accept the permit ID from the URL when editing, or from the hidden form field after a POST.
$recordId = filter_var($_GET['id'] ?? $_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
$recordId = $recordId && $recordId > 0 ? $recordId : null;
$errors = [];
$formMessage = null;
$permit = null;
$items = [];
$existingContactIds = ['shipper_id' => 0, 'consignee_id' => 0];

// Load an existing active permit and its species rows to prefill the edit form.
if ($recordId !== null) {
    $permitQuery = $pdo->prepare('SELECT * FROM incoming_permits WHERE id = :id AND is_archived = 0');
    $permitQuery->execute(['id' => $recordId]);
    $permit = $permitQuery->fetch(PDO::FETCH_ASSOC);
    if (!$permit) {
        http_response_code(404);
        exit('In-coming record not found.');
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
         FROM incoming_items AS i
         INNER JOIN species AS s ON s.id = i.species_id
         WHERE i.permit_id = :id ORDER BY i.id'
    );
    $itemsQuery->execute(['id' => $recordId]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
}

// Use the province table both to populate the dropdown and to look up each province's region.
$provinceRows = $pdo->query('SELECT province_name, region_name FROM provinces ORDER BY province_name')->fetchAll(PDO::FETCH_ASSOC);
$provinceRegions = [];
foreach ($provinceRows as $provinceRow) {
    $provinceRegions[$provinceRow['province_name']] = $provinceRow['region_name'];
}

// Process either a contact added from a modal or the main permit submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject requests without the session's CSRF token before changing any data.
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $formMessage = 'Your session expired. Reload the form and try again.';
    } else {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'add_shipper' || $action === 'add_consignee') {
            // Normalize contact fields before validating and inserting the new contact.
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
                // Save the shipper and its activity record, then return to the permit with the shipper selected.
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
                     VALUES (:user_id, \'create\', \'shipper\', :record_id, \'Added shipper from in-coming form\')'
                );
                $logContact->execute(['user_id' => (int) $user['id'], 'record_id' => $newContactId]);
                $_SESSION['incoming_form_flash'] = ['type' => 'success', 'message' => 'Shipper added.'];
                header('Location: incoming_form.php' . ($recordId ? '?id=' . $recordId . '&' : '?') . 'shipper_id=' . $newContactId);
                exit;
            } else {
                // Save the consignee and its activity record, then return with the consignee selected.
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
                     VALUES (:user_id, \'create\', \'consignee\', :record_id, \'Added consignee from in-coming form\')'
                );
                $logContact->execute(['user_id' => (int) $user['id'], 'record_id' => $newContactId]);
                $_SESSION['incoming_form_flash'] = ['type' => 'success', 'message' => 'Consignee added.'];
                header('Location: incoming_form.php' . ($recordId ? '?id=' . $recordId . '&' : '?') . 'consignee_id=' . $newContactId);
                exit;
            }
        } else {
            // Trim submitted permit fields so validation and database values use consistent input.
            $values = [
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
                'source_region' => trim((string) ($_POST['source_region'] ?? '')),
                'destination_barangay' => trim((string) ($_POST['destination_barangay'] ?? '')),
                'destination_municipality' => trim((string) ($_POST['destination_municipality'] ?? '')),
                'consignee_id' => trim((string) ($_POST['consignee_id'] ?? '')),
                'purpose_of_transport' => trim((string) ($_POST['purpose_of_transport'] ?? '')),
                'spsic_no' => trim((string) ($_POST['spsic_no'] ?? '')),
                'inspected' => trim((string) ($_POST['inspected'] ?? 'Inspected')),
            ];
            // Species arrive as a separate list because one permit can contain multiple species.
            $postedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

            // Validate permit dates, unique LTP code, field lengths, required fields, and contact IDs.
            $date = DateTime::createFromFormat('!Y-m-d', $values['shipment_date']);
            if (!$date || $date->format('Y-m-d') !== $values['shipment_date']) {
                $errors['shipment_date'] = 'Enter a valid shipment date.';
            }
            // LTP codes must be unique; when editing, exclude the current permit from the duplicate check.
            if ($values['ltp_code'] === '' || mb_strlen($values['ltp_code']) > 40) {
                $errors['ltp_code'] = 'Enter an LTP code up to 40 characters.';
            } else {
                $duplicateQuery = $pdo->prepare('SELECT id FROM incoming_permits WHERE ltp_code = :code AND (:record_id IS NULL OR id <> :record_id) LIMIT 1');
                $duplicateQuery->execute(['code' => $values['ltp_code'], 'record_id' => $recordId]);
                if ($duplicateQuery->fetchColumn()) {
                    $errors['ltp_code'] = 'This LTP code is already in use.';
                }
            }
            // Reject invalid amounts and text longer than the database columns allow.
            if ($values['amount'] !== '' && (!is_numeric($values['amount']) || (float) $values['amount'] < 0 || (float) $values['amount'] > 9999999999.99)) {
                $errors['amount'] = 'Enter a valid non-negative amount.';
            }
            foreach (['operating_unit' => 80, 'ltp_issuance_location' => 80, 'product_form' => 30, 'product_category' => 30, 'cit_cha_classification' => 30, 'source_barangay' => 80, 'source_municipality' => 80, 'destination_barangay' => 100, 'destination_municipality' => 80, 'purpose_of_transport' => 40, 'spsic_no' => 40] as $field => $maxLength) {
                if (mb_strlen($values[$field]) > $maxLength) $errors[$field] = 'This field must be ' . $maxLength . ' characters or fewer.';
            }
            if ($values['operating_unit'] === '') $errors['operating_unit'] = 'Enter the operating unit.';
            if ($values['issuing_province'] === '' || !isset($provinceRegions[$values['issuing_province']])) $errors['issuing_province'] = 'Choose the province that issued the LTP.';
            if ($values['source_municipality'] === '') $errors['source_municipality'] = 'Enter the origin municipality or city.';
            if ($values['destination_municipality'] === '') $errors['destination_municipality'] = 'Enter the destination municipality or city.';
            if (!filter_var($values['shipper_id'], FILTER_VALIDATE_INT)) $errors['shipper_id'] = 'Choose a saved shipper or add a new one.';
            if (!filter_var($values['consignee_id'], FILTER_VALIDATE_INT)) $errors['consignee_id'] = 'Choose a saved consignee or add a new one.';
            if (!in_array($values['inspected'], ['Inspected', 'Not Inspected'], true)) $errors['inspected'] = 'Choose an inspection status.';
            if ($values['purpose_of_transport'] === '') $errors['purpose_of_transport'] = 'Enter the purpose of transport.';

            // Confirm the selected contact IDs actually exist, rather than trusting the submitted form.
            foreach (['shipper_id' => 'shippers', 'consignee_id' => 'consignees'] as $contactField => $tableName) {
                if (!isset($errors[$contactField])) {
                    $contactQuery = $pdo->prepare(
                        'SELECT id FROM ' . $tableName . ' WHERE id = :id AND (is_active = 1 OR id = :existing_id)'
                    );
                    $contactQuery->execute([
                        'id' => $values[$contactField],
                        'existing_id' => $existingContactIds[$contactField],
                    ]);
                    if (!$contactQuery->fetchColumn()) $errors[$contactField] = 'Choose a saved contact or add a new one.';
                }
            }

            // Validate each species row and convert numeric form values before saving.
            $cleanItems = [];
            foreach ($postedItems as $row) {
                if (!is_array($row)) continue;
                $speciesId = filter_var($row['species_id'] ?? null, FILTER_VALIDATE_INT);
                $englishName = trim((string) ($row['english_name'] ?? ''));
                $scientificName = trim((string) ($row['scientific_name'] ?? ''));
                $localName = trim((string) ($row['local_name'] ?? ''));
                $kgText = trim((string) ($row['volume_kg'] ?? ''));
                $pcsText = trim((string) ($row['volume_pcs'] ?? ''));
                $valueText = trim((string) ($row['market_value'] ?? ''));
                // Ignore a completely empty row, such as the unused row left on a form.
                if (!$speciesId && $englishName === '' && $scientificName === '' && $localName === '' && $kgText === '' && $pcsText === '' && $valueText === '') continue;
                if ($englishName === '' || mb_strlen($englishName) > 100 || mb_strlen($scientificName) > 150 || mb_strlen($localName) > 100) {
                    $errors['items'] = 'Enter a species name (up to 100 characters) and keep scientific and local names within their field limits.';
                    continue;
                }
                // Convert blank quantities to zero, and mark malformed numbers invalid.
                $kg = $kgText === '' ? 0 : (is_numeric($kgText) ? (float) $kgText : -1);
                $pcs = $pcsText === '' ? 0 : filter_var($pcsText, FILTER_VALIDATE_INT);
                $value = $valueText === '' ? 0 : (is_numeric($valueText) ? (float) $valueText : -1);
                $kgPrecisionValid = $kgText === '' || preg_match('/\A(?:\d+(?:\.\d{1,2})?|\.\d{1,2})\z/', $kgText) === 1;
                if ($kg < 0 || $kg > 99999999.99 || !$kgPrecisionValid || $pcs === false || $pcs < 0 || $value < 0 || $value > 999999999999.99) {
                    $errors['items'] = 'Species volumes and market values must be valid non-negative numbers.';
                    continue;
                }
                if ($kg == 0 && $pcs == 0) {
                    $errors['items'] = 'Enter kilograms or pieces for each species.';
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
            if ($cleanItems === []) $errors['items'] = 'Add at least one species row with a volume.';

            // Do not start database writes until all permit and item validation succeeds.
            if ($errors === []) {
                // Build the permit column/value map; empty optional fields become SQL NULL.
                $originRegion = $provinceRegions[$values['issuing_province']];
                $permitData = [
                    'shipment_date' => $values['shipment_date'],
                    'amount' => $values['amount'] === '' ? null : $values['amount'],
                    'ltp_code' => $values['ltp_code'],
                    'operating_unit' => $values['operating_unit'],
                    'issuing_province' => $values['issuing_province'],
                    'ltp_issuance_location' => $values['ltp_issuance_location'] ?: null,
                    'product_form' => $values['product_form'] ?: null,
                    'product_category' => $values['product_category'] ?: null,
                    'cit_cha_classification' => $values['cit_cha_classification'] ?: null,
                    'shipper_id' => $values['shipper_id'],
                    'source_barangay' => $values['source_barangay'] ?: null,
                    'source_municipality' => $values['source_municipality'],
                    'source_province' => $values['issuing_province'],
                    'source_region' => $originRegion,
                    'destination_barangay' => $values['destination_barangay'] ?: null,
                    'destination_municipality' => $values['destination_municipality'],
                    'destination_province' => 'Siquijor',
                    'destination_region' => $provinceRegions['Siquijor'] ?? null,
                    'consignee_id' => $values['consignee_id'],
                    'purpose_of_transport' => $values['purpose_of_transport'],
                    'spsic_no' => $values['spsic_no'] ?: null,
                    'inspected' => $values['inspected'],
                ];
                try {
                    // Save the permit, item rows, and audit log as one transaction.
                    $pdo->beginTransaction();
                    if ($recordId === null) {
                        // New permits receive a creator ID and use an INSERT.
                        $permitData['created_by'] = (int) $user['id'];
                        $insertPermit = $pdo->prepare(
                            'INSERT INTO incoming_permits (' . implode(', ', array_keys($permitData)) . ')
                             VALUES (:' . implode(', :', array_keys($permitData)) . ')'
                        );
                        $insertPermit->execute($permitData);
                        $recordId = (int) $pdo->lastInsertId();
                        $auditAction = 'create';
                    } else {
                        // Lock and recheck the permit before replacing its data, preventing edits to archived records.
                        $activePermit = $pdo->prepare('SELECT id FROM incoming_permits WHERE id = :id AND is_archived = 0 FOR UPDATE');
                        $activePermit->execute(['id' => $recordId]);
                        if (!$activePermit->fetchColumn()) {
                            throw new RuntimeException('This in-coming record is no longer active.');
                        }
                        $assignments = array_map(static fn ($field) => $field . ' = :' . $field, array_keys($permitData));
                        $permitData['id'] = $recordId;
                        $updatePermit = $pdo->prepare('UPDATE incoming_permits SET ' . implode(', ', $assignments) . ' WHERE id = :id AND is_archived = 0');
                        $updatePermit->execute($permitData);
                        $pdo->prepare('DELETE FROM incoming_items WHERE permit_id = :id')->execute(['id' => $recordId]);
                        $auditAction = 'update';
                    }
                    // Add each species line; new names are added to the catalog by the shared helper.
                    $insertItem = $pdo->prepare(
                        'INSERT INTO incoming_items (permit_id, species_id, english_name, scientific_name, local_name, volume_kg, volume_pcs, market_value)
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
                         VALUES (:user_id, :action, \'incoming_permit\', :record_id, :details)'
                    );
                    $logQuery->execute([
                        'user_id' => (int) $user['id'],
                        'action' => $auditAction,
                        'record_id' => $recordId,
                        'details' => $auditAction === 'create' ? 'Created in-coming permit' : 'Updated in-coming permit',
                    ]);
                    // Commit all related writes together, then redirect to the incoming-record list.
                    $pdo->commit();
                    $_SESSION['incoming_flash'] = ['type' => 'success', 'message' => $auditAction === 'create' ? 'In-coming record saved.' : 'In-coming record updated.'];
                    header('Location: incoming.php');
                    exit;
                } catch (Throwable $exception) {
                    // Roll back the permit and item changes together if any database write fails.
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($exception instanceof PDOException && $exception->getCode() === '23000' && str_contains($exception->getMessage(), 'ltp')) {
                        $errors['ltp_code'] = 'This LTP code is already in use.';
                    } else {
                        $formMessage = $exception instanceof RuntimeException
                            ? $exception->getMessage()
                            : 'The record could not be saved. Check the entered values and try again.';
                    }
                }
            }
            // Preserve submitted values so the user can correct validation errors without retyping.
            $permit = array_merge($permit ?? [], $values);
            $items = $postedItems;
        }
    }
}

// Load dropdown data and one blank species row when creating a new permit.
$flash = $_SESSION['incoming_form_flash'] ?? null;
unset($_SESSION['incoming_form_flash']);
$speciesRows = $pdo->query('SELECT id, english_name, scientific_name, local_name FROM species WHERE is_active = 1 ORDER BY english_name')->fetchAll(PDO::FETCH_ASSOC);
$shipperQuery = $pdo->prepare('SELECT id, name, company, municipality, province, is_active FROM shippers WHERE is_active = 1 OR id = :existing_id ORDER BY name');
$shipperQuery->execute(['existing_id' => $existingContactIds['shipper_id']]);
$shippers = $shipperQuery->fetchAll(PDO::FETCH_ASSOC);
$consigneeQuery = $pdo->prepare('SELECT id, name, municipality, province, is_active FROM consignees WHERE is_active = 1 OR id = :existing_id ORDER BY name');
$consigneeQuery->execute(['existing_id' => $existingContactIds['consignee_id']]);
$consignees = $consigneeQuery->fetchAll(PDO::FETCH_ASSOC);
if ($items === []) $items = [['species_id' => '', 'volume_kg' => '', 'volume_pcs' => '', 'market_value' => '']];

// Prefer submitted values after an error; otherwise use the saved permit or the supplied default.
$value = static function (string $field, $default = '') use (&$permit): string {
    $posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST[$field] ?? null) : null;
    if ($posted !== null) return is_scalar($posted) ? (string) $posted : (string) $default;
    return isset($permit[$field]) && $permit[$field] !== null ? (string) $permit[$field] : (string) $default;
};
// Add Bootstrap error styling and feedback only for fields that failed validation.
$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$fieldFeedback = static fn (string $field): string => isset($errors[$field]) ? '<div class="invalid-feedback">' . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8') . '</div>' : '';
$restoreContactField = isset($_GET['shipper_id']) ? 'shipper_id' : (isset($_GET['consignee_id']) ? 'consignee_id' : '');
$restoreDraft = is_array($flash);
$pageTitle = $recordId === null ? 'Add In-coming Record' : 'Edit In-coming Record';
$currentPage = 'incoming';
require __DIR__ . '/header.php';
?>
<!-- Main permit form: values are rendered safely and validation errors stay next to their fields. -->
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center gap-3 mb-4"><h1 class="h2 mb-0"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1><a class="btn btn-secondary" href="incoming.php">Cancel</a></div>
    <?php if (is_array($flash)): ?><div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($formMessage !== null): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($formMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <!-- The hidden values identify the request and protect it against cross-site request forgery. -->
    <form method="post" action="incoming_form.php<?= $recordId ? '?id=' . $recordId : '' ?>" id="incoming-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="record_id" value="<?= $recordId ?? '' ?>"><input type="hidden" name="action" value="save">
        <!-- Shipment and issuing-office information for this permit. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Permit Details</h2></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="shipment_date">Shipment date</label><input class="form-control<?= $fieldClass('shipment_date') ?>" id="shipment_date" name="shipment_date" type="date" value="<?= htmlspecialchars($value('shipment_date'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('shipment_date') ?></div>
            <div class="col-md-4"><label class="form-label" for="amount">Amount</label><input class="form-control<?= $fieldClass('amount') ?>" id="amount" name="amount" type="number" min="0" step="0.01" value="<?= htmlspecialchars($value('amount'), ENT_QUOTES, 'UTF-8') ?>"><?= $fieldFeedback('amount') ?></div>
            <div class="col-md-4"><label class="form-label" for="ltp_code">LTP code from origin office</label><input class="form-control<?= $fieldClass('ltp_code') ?>" id="ltp_code" name="ltp_code" maxlength="40" placeholder="LTP07-NERPFOS-2131" value="<?= htmlspecialchars($value('ltp_code'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('ltp_code') ?></div>
            <div class="col-md-4"><label class="form-label" for="operating_unit">Operating unit</label><input class="form-control<?= $fieldClass('operating_unit') ?>" id="operating_unit" name="operating_unit" maxlength="80" placeholder="PFO Negros Oriental" value="<?= htmlspecialchars($value('operating_unit'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('operating_unit') ?></div>
            <div class="col-md-4"><label class="form-label" for="issuing_province">Issuing province</label><select class="form-select<?= $fieldClass('issuing_province') ?>" id="issuing_province" name="issuing_province" required><option value="">Choose origin province</option><?php foreach ($provinceRows as $province): ?><option value="<?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?>" data-region="<?= htmlspecialchars($province['region_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $value('issuing_province') === $province['province_name'] ? 'selected' : '' ?>><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('issuing_province') ?></div>
            <div class="col-md-4"><label class="form-label" for="ltp_issuance_location">Location of LTP issuance</label><input class="form-control" id="ltp_issuance_location" name="ltp_issuance_location" maxlength="80" value="<?= htmlspecialchars($value('ltp_issuance_location'), ENT_QUOTES, 'UTF-8') ?>"></div>
        </div></div></section>

        <!-- Product classification and repeatable species rows. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Product Details</h2></div><div class="card-body">
            <div class="row g-3 mb-4">
                <div class="col-md-4"><label class="form-label" for="product_form">Product form</label><input class="form-control" id="product_form" name="product_form" maxlength="30" value="<?= htmlspecialchars($value('product_form'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label" for="product_category">Product category</label><input class="form-control" id="product_category" name="product_category" maxlength="30" value="<?= htmlspecialchars($value('product_category'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label" for="cit_cha_classification">CIT-CHA classification</label><input class="form-control" id="cit_cha_classification" name="cit_cha_classification" maxlength="30" value="<?= htmlspecialchars($value('cit_cha_classification'), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <h3 class="h6">Species rows</h3>
            <p class="form-text">Choose a catalog species, or leave it unselected and enter a new species name to add it to the catalog for future permits. New species are grouped as Other.</p>
            <?php if (isset($errors['items'])): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($errors['items'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <!-- Each species row submits one item; the names and quantities are saved together. -->
            <div id="species-rows" class="vstack gap-3">
                <?php foreach ($items as $index => $item): ?>
                <div class="border rounded p-3 species-row"><div class="row g-3 align-items-end">
                    <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-species">Species catalog entry (optional)</label><select class="form-select species-select" id="items-<?= (int) $index ?>-species" name="items[<?= (int) $index ?>][species_id]"><option value="">Enter a new species below</option><?php foreach ($speciesRows as $species): ?><option value="<?= (int) $species['id'] ?>" data-english="<?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?>" data-scientific="<?= htmlspecialchars($species['scientific_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-local="<?= htmlspecialchars($species['local_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($item['species_id'] ?? '') === (string) $species['id'] ? 'selected' : '' ?>><?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-english">Species name</label><input class="form-control english-name" id="items-<?= (int) $index ?>-english" name="items[<?= (int) $index ?>][english_name]" maxlength="100" value="<?= htmlspecialchars((string) ($item['english_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-scientific">Scientific name</label><input class="form-control scientific-name" id="items-<?= (int) $index ?>-scientific" name="items[<?= (int) $index ?>][scientific_name]" maxlength="150" value="<?= htmlspecialchars((string) ($item['scientific_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="items-<?= (int) $index ?>-local">Local name</label><input class="form-control local-name" id="items-<?= (int) $index ?>-local" name="items[<?= (int) $index ?>][local_name]" maxlength="100" value="<?= htmlspecialchars((string) ($item['local_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-md-3"><button class="btn btn-outline-danger remove-species" type="button">Remove species</button></div>
                    <div class="col-md-3"><label class="form-label" for="items-<?= (int) $index ?>-kg">Volume kg</label><input class="form-control" id="items-<?= (int) $index ?>-kg" name="items[<?= (int) $index ?>][volume_kg]" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) ($item['volume_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="items-<?= (int) $index ?>-pcs">Volume pcs</label><input class="form-control" id="items-<?= (int) $index ?>-pcs" name="items[<?= (int) $index ?>][volume_pcs]" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($item['volume_pcs'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="items-<?= (int) $index ?>-value">Market value</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" id="items-<?= (int) $index ?>-value" name="items[<?= (int) $index ?>][market_value]" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) ($item['market_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div></div>
                </div></div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-outline-primary mt-3" id="add-species" type="button">Add another species</button>
        </div></section>

        <!-- The selected shipper is stored by ID; the modal can add one without losing permit values. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Shipper</h2></div><div class="card-body"><div class="row g-3 align-items-end">
            <div class="col-md-8"><label class="form-label" for="shipper_id">Saved shipper</label><select class="form-select<?= $fieldClass('shipper_id') ?>" id="shipper_id" name="shipper_id" required><option value="">Choose a shipper</option><?php foreach ($shippers as $shipper): ?><option value="<?= (int) $shipper['id'] ?>" <?= $value('shipper_id', $_GET['shipper_id'] ?? '') === (string) $shipper['id'] ? 'selected' : '' ?>><?= htmlspecialchars($shipper['name'] . ($shipper['company'] ? ' - ' . $shipper['company'] : '') . ((int) $shipper['is_active'] === 1 ? '' : ' (Inactive - existing permit)'), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('shipper_id') ?></div>
            <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" data-bs-toggle="modal" data-bs-target="#shipper-modal">Add new shipper</button></div>
        </div></div></section>

        <!-- Origin details; province selection also determines the displayed region. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Product Source</h2></div><div class="card-body"><div class="row g-3">
            <div class="col-md-3"><label class="form-label" for="source_barangay">Barangay</label><input class="form-control" id="source_barangay" name="source_barangay" maxlength="80" value="<?= htmlspecialchars($value('source_barangay'), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-md-3"><label class="form-label" for="source_municipality">Origin municipality/city</label><input class="form-control<?= $fieldClass('source_municipality') ?>" id="source_municipality" name="source_municipality" maxlength="80" value="<?= htmlspecialchars($value('source_municipality'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('source_municipality') ?></div>
            <div class="col-md-3"><label class="form-label" for="source_province_display">Origin province</label><input class="form-control" id="source_province_display" type="text" value="<?= htmlspecialchars($value('issuing_province'), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
            <div class="col-md-3"><label class="form-label" for="source_region">Origin region</label><input class="form-control" id="source_region" type="text" value="<?= htmlspecialchars($value('source_region'), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
        </div></div></section>

        <!-- Destination is fixed to Siquijor for incoming permits. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Product Destination</h2></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="destination_barangay">Barangay</label><input class="form-control" id="destination_barangay" name="destination_barangay" maxlength="100" value="<?= htmlspecialchars($value('destination_barangay'), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="destination_municipality">Municipality/city</label><input class="form-control<?= $fieldClass('destination_municipality') ?>" id="destination_municipality" name="destination_municipality" maxlength="80" value="<?= htmlspecialchars($value('destination_municipality', 'Larena'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('destination_municipality') ?></div>
            <div class="col-md-4"><label class="form-label" for="destination_province_display">Province</label><input class="form-control" id="destination_province_display" type="text" value="Siquijor" readonly></div>
            <input type="hidden" name="destination_region" value="<?= htmlspecialchars($provinceRegions['Siquijor'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div></div></section>

        <!-- The consignee receiving the incoming shipment. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Consignee</h2></div><div class="card-body"><div class="row g-3 align-items-end">
            <div class="col-md-8"><label class="form-label" for="consignee_id">Saved consignee</label><select class="form-select<?= $fieldClass('consignee_id') ?>" id="consignee_id" name="consignee_id" required><option value="">Choose a consignee</option><?php foreach ($consignees as $consignee): ?><option value="<?= (int) $consignee['id'] ?>" <?= $value('consignee_id', $_GET['consignee_id'] ?? '') === (string) $consignee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($consignee['name'] . ($consignee['municipality'] ? ' - ' . $consignee['municipality'] : '') . ((int) $consignee['is_active'] === 1 ? '' : ' (Inactive - existing permit)'), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?= $fieldFeedback('consignee_id') ?></div>
            <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" data-bs-toggle="modal" data-bs-target="#consignee-modal">Add new consignee</button></div>
        </div></div></section>

        <!-- Final transport details and inspection status before saving the permit. -->
        <section class="card mb-4"><div class="card-header bg-primary text-white"><h2 class="h5 mb-0">Transport and Inspection</h2></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="purpose_of_transport">Purpose of transport</label><input class="form-control<?= $fieldClass('purpose_of_transport') ?>" id="purpose_of_transport" name="purpose_of_transport" maxlength="40" value="<?= htmlspecialchars($value('purpose_of_transport', 'Commercial'), ENT_QUOTES, 'UTF-8') ?>" required><?= $fieldFeedback('purpose_of_transport') ?></div>
            <div class="col-md-4"><label class="form-label" for="spsic_no">SPSIC no. (optional)</label><input class="form-control" id="spsic_no" name="spsic_no" maxlength="40" value="<?= htmlspecialchars($value('spsic_no'), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="inspected">Inspection status</label><select class="form-select" id="inspected" name="inspected"><option value="Inspected" <?= $value('inspected', 'Inspected') === 'Inspected' ? 'selected' : '' ?>>Inspected</option><option value="Not Inspected" <?= $value('inspected', 'Inspected') === 'Not Inspected' ? 'selected' : '' ?>>Not Inspected</option></select></div>
        </div></div></section>
        <div class="d-flex gap-2 mb-4"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-secondary" href="incoming.php">Cancel</a></div>
    </form>
</div>

<!-- Contact modals submit separately from the permit form. -->
<div class="modal fade" id="shipper-modal" tabindex="-1" aria-labelledby="shipper-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
    <!-- This form posts asynchronously to shipper_create.php; its JavaScript handler updates the shipper dropdown. -->
    <form method="post" action="shipper_create.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header"><h2 class="modal-title h5" id="shipper-modal-title">Add new shipper</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="alert alert-danger d-none shipper-save-feedback" role="alert"></div><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="shipper-name">Name</label><input class="form-control" id="shipper-name" name="name" maxlength="100" required></div>
            <div class="col-md-3"><label class="form-label" for="shipper-sex">Sex</label><select class="form-select" id="shipper-sex" name="sex"><option value="">Not specified</option><option>Female</option><option>Male</option></select></div>
            <div class="col-md-3"><label class="form-label" for="shipper-client-type">Client type</label><input class="form-control" id="shipper-client-type" name="client_type" maxlength="30" value="Regular"></div>
            <div class="col-md-6"><label class="form-label" for="shipper-company">Company</label><input class="form-control" id="shipper-company" name="company" maxlength="100"></div>
            <div class="col-md-6"><label class="form-label" for="shipper-phone">Contact number</label><input class="form-control" id="shipper-phone" name="contact_number" maxlength="20" type="tel"></div>
            <div class="col-md-6"><label class="form-label" for="shipper-email">Email</label><input class="form-control" id="shipper-email" name="email" maxlength="100" type="email"></div>
            <div class="col-md-3"><label class="form-label" for="shipper-municipality">Municipality/City</label><input class="form-control" id="shipper-municipality" name="municipality" maxlength="80"></div>
            <div class="col-md-3"><label class="form-label" for="shipper-province">Province</label><select class="form-select" id="shipper-province" name="province"><option value="">Choose a province</option><?php foreach ($provinceRows as $province): ?><option><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        </div></div>
        <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save shipper</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="consignee-modal" tabindex="-1" aria-labelledby="consignee-modal-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <!-- Adding a consignee uses a regular POST; JavaScript saves and restores the unfinished permit draft. -->
    <form method="post" action="incoming_form.php<?= $recordId ? '?id=' . $recordId : '' ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="add_consignee">
        <div class="modal-header"><h2 class="modal-title h5" id="consignee-modal-title">Add new consignee</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="consignee-name">Name</label><input class="form-control" id="consignee-name" name="name" maxlength="100" required></div>
            <div class="col-12"><label class="form-label" for="consignee-phone">Contact number</label><input class="form-control" id="consignee-phone" name="contact_number" maxlength="20" type="tel"></div>
            <div class="col-md-6"><label class="form-label" for="consignee-municipality">Municipality/City</label><input class="form-control" id="consignee-municipality" name="municipality" maxlength="80"></div>
            <div class="col-md-6"><label class="form-label" for="consignee-province">Province</label><select class="form-select" id="consignee-province" name="province"><option value="">Choose a province</option><?php foreach ($provinceRows as $province): ?><option><?= htmlspecialchars($province['province_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        </div></div>
        <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save consignee</button></div>
    </form>
</div></div></div>

<!-- JavaScript clones this template whenever the user adds another species row. -->
<template id="species-row-template">
    <div class="border rounded p-3 species-row"><div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-species">Species catalog entry (optional)</label><select class="form-select species-select" id="items-__INDEX__-species" name="items[__INDEX__][species_id]"><option value="">Enter a new species below</option><?php foreach ($speciesRows as $species): ?><option value="<?= (int) $species['id'] ?>" data-english="<?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?>" data-scientific="<?= htmlspecialchars($species['scientific_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-local="<?= htmlspecialchars($species['local_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($species['english_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-english">Species name</label><input class="form-control english-name" id="items-__INDEX__-english" name="items[__INDEX__][english_name]" maxlength="100" required></div>
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-scientific">Scientific name</label><input class="form-control scientific-name" id="items[__INDEX__]-scientific" name="items[__INDEX__][scientific_name]" maxlength="150"></div>
        <div class="col-md-3"><label class="form-label" for="items-__INDEX__-local">Local name</label><input class="form-control local-name" id="items-__INDEX__-local" name="items[__INDEX__][local_name]" maxlength="100"></div>
        <div class="col-md-3"><button class="btn btn-outline-danger remove-species" type="button">Remove species</button></div>
        <div class="col-md-3"><label class="form-label" for="items-__INDEX__-kg">Volume kg</label><input class="form-control" id="items-__INDEX__-kg" name="items[__INDEX__][volume_kg]" type="number" min="0" step="0.01"></div>
        <div class="col-md-3"><label class="form-label" for="items-__INDEX__-pcs">Volume pcs</label><input class="form-control" id="items-__INDEX__-pcs" name="items[__INDEX__][volume_pcs]" type="number" min="0" step="1"></div>
        <div class="col-md-4"><label class="form-label" for="items-__INDEX__-value">Market value</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" id="items-__INDEX__-value" name="items[__INDEX__][market_value]" type="number" min="0" step="0.01"></div></div>
    </div></div>
</template>
<script>
// Keep references to the species list and its row template for the dynamic row controls.
const speciesRows = document.getElementById('species-rows');
const speciesTemplate = document.getElementById('species-row-template');
let nextSpeciesIndex = speciesRows.querySelectorAll('.species-row').length;

function updateSpeciesNames(select, replaceExisting = false) {
    // Copy catalog names into the row while preserving manually entered names when appropriate.
    const row = select.closest('.species-row');
    const option = select.selectedOptions[0];
    const englishName = row.querySelector('.english-name');
    const scientificName = row.querySelector('.scientific-name');
    const localName = row.querySelector('.local-name');
    if (replaceExisting || !englishName.value) englishName.value = option?.dataset.english ?? '';
    if (replaceExisting || !scientificName.value) scientificName.value = option?.dataset.scientific ?? '';
    if (replaceExisting || !localName.value) localName.value = option?.dataset.local ?? '';
}

speciesRows.addEventListener('change', (event) => {
    // Selecting a catalog entry fills its English, scientific, and local names.
    if (event.target.matches('.species-select')) updateSpeciesNames(event.target, true);
});
speciesRows.addEventListener('input', (event) => {
    // If the English name is changed manually, clear the old catalog selection.
    if (event.target.matches('.english-name')) {
        const row = event.target.closest('.species-row');
        const select = row.querySelector('.species-select');
        const selectedName = select.selectedOptions[0]?.dataset.english ?? '';
        if (event.target.value.trim().toLocaleLowerCase() !== selectedName.toLocaleLowerCase()) select.value = '';
    }
});
speciesRows.addEventListener('click', (event) => {
    // Remove only the row whose remove button was clicked.
    const removeButton = event.target.closest('.remove-species');
    if (removeButton) removeButton.closest('.species-row').remove();
});
speciesRows.querySelectorAll('.species-select').forEach(updateSpeciesNames);
document.getElementById('add-species').addEventListener('click', () => {
    // Replace the template placeholder with a unique index before inserting a new row.
    speciesRows.insertAdjacentHTML('beforeend', speciesTemplate.innerHTML.replaceAll('__INDEX__', String(nextSpeciesIndex++)));
});
document.getElementById('issuing_province').addEventListener('change', (event) => {
    // Keep the read-only origin fields in sync with the selected issuing province.
    const option = event.target.selectedOptions[0];
    document.getElementById('source_province_display').value = option?.value ?? '';
    document.getElementById('source_region').value = option?.dataset.region ?? '';
});
document.querySelectorAll('#consignee-modal form').forEach((contactForm) => {
    contactForm.addEventListener('submit', () => {
        // Save the permit form temporarily so adding a consignee does not lose entered values.
        const entries = Array.from(new FormData(document.getElementById('incoming-form')).entries());
        sessionStorage.setItem('incoming-permit-draft', JSON.stringify(entries));
    });
});
const shipperModal = document.getElementById('shipper-modal');
const shipperForm = shipperModal.querySelector('form');
shipperForm.addEventListener('submit', async (event) => {
    // Add a shipper through the JSON endpoint without reloading the permit form.
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
        // Show the server's validation or save error inside the modal.
        const result = await response.json();
        if (!response.ok) throw new Error(result.error ?? 'Shipper could not be saved. Please try again.');

        const select = document.getElementById('shipper_id');
        const option = document.createElement('option');
        option.value = String(result.shipper.id);
        option.textContent = result.shipper.name + (result.shipper.company ? ' - ' + result.shipper.company : '');
        // Insert and select the new shipper so the permit can continue immediately.
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
// After a contact was added through a redirect, rebuild dynamic rows and restore the saved draft.
if (<?= $restoreDraft ? 'true' : 'false' ?>) {
    // Read the saved values and recreate any species rows before restoring their fields.
    const savedDraft = sessionStorage.getItem('incoming-permit-draft');
    if (savedDraft) {
        const entries = JSON.parse(savedDraft);
        const itemIndexes = entries.map(([name]) => name.match(/^items\[(\d+)\]/)?.[1]).filter((index) => index !== undefined);
        const requiredRows = itemIndexes.length ? Math.max(...itemIndexes.map(Number)) + 1 : 1;
        while (speciesRows.querySelectorAll('.species-row').length < requiredRows) {
            speciesRows.insertAdjacentHTML('beforeend', speciesTemplate.innerHTML.replaceAll('__INDEX__', String(nextSpeciesIndex++)));
        }
        entries.forEach(([name, fieldValue]) => {
            // Keep the newly added contact selected instead of overwriting it with the old draft value.
            if (name === <?= json_encode($restoreContactField) ?>) return;
            const field = document.getElementById('incoming-form').elements.namedItem(name);
            if (field) field.value = fieldValue;
        });
        document.getElementById('issuing_province').dispatchEvent(new Event('change'));
        speciesRows.querySelectorAll('.species-select').forEach(updateSpeciesNames);
        // Remove the temporary draft after restoration so it is not reused on a later visit.
        sessionStorage.removeItem('incoming-permit-draft');
    }
}
</script>

<?php
require __DIR__ . '/footer.php';
