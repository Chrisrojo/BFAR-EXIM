<?php
// This endpoint serves asynchronous consignee creation from permit forms.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRecordEditor();

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $respond(405, ['error' => 'Use the consignee form to add a consignee.']);
}

if (!hash_equals(authCsrfToken(), (string) ($_POST['csrf_token'] ?? ''))) {
    $respond(403, ['error' => 'Your session expired. Reload the page and try again.']);
}

$values = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'sex' => trim((string) ($_POST['sex'] ?? '')),
    'company' => trim((string) ($_POST['company'] ?? '')),
    'contact_number' => trim((string) ($_POST['contact_number'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'barangay' => trim((string) ($_POST['barangay'] ?? '')),
    'municipality' => trim((string) ($_POST['municipality'] ?? '')),
    'province' => trim((string) ($_POST['province'] ?? '')),
];

if ($values['name'] === '' || mb_strlen($values['name']) > 100) {
    $respond(422, ['error' => 'Enter a consignee name up to 100 characters.']);
}
if (!in_array($values['sex'], ['', 'Male', 'Female'], true)) {
    $respond(422, ['error' => 'Choose a valid sex or leave it blank.']);
}
if (mb_strlen($values['company']) > 100
    || mb_strlen($values['contact_number']) > 20
    || mb_strlen($values['email']) > 100
    || mb_strlen($values['barangay']) > 100
    || mb_strlen($values['municipality']) > 80
) {
    $respond(422, ['error' => 'One or more consignee fields exceed the allowed length.']);
}
if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    $respond(422, ['error' => 'Enter a valid email address or leave the email field blank.']);
}

$pdo = getDatabaseConnection();
$provinceQuery = $pdo->prepare('SELECT province_name FROM provinces WHERE province_name = :province');
$provinceQuery->execute(['province' => $values['province']]);
if ($values['province'] !== '' && !$provinceQuery->fetchColumn()) {
    $respond(422, ['error' => 'Choose a province from the list.']);
}

$currentUser = authCurrentUser();
try {
    $pdo->beginTransaction();
    $insertConsignee = $pdo->prepare(
        'INSERT INTO consignees (name, sex, company, contact_number, email, barangay, municipality, province)
         VALUES (:name, :sex, :company, :contact_number, :email, :barangay, :municipality, :province)'
    );
    $insertConsignee->execute([
        'name' => $values['name'],
        'sex' => $values['sex'] !== '' ? $values['sex'] : null,
        'company' => $values['company'] !== '' ? $values['company'] : null,
        'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
        'email' => $values['email'] !== '' ? $values['email'] : null,
        'barangay' => $values['barangay'] !== '' ? $values['barangay'] : null,
        'municipality' => $values['municipality'] !== '' ? $values['municipality'] : null,
        'province' => $values['province'] !== '' ? $values['province'] : null,
    ]);
    $consigneeId = (int) $pdo->lastInsertId();

    $logQuery = $pdo->prepare(
        'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
         VALUES (:user_id, \'create\', \'consignee\', :record_id, \'Added consignee from permit form\')'
    );
    $logQuery->execute([
        'user_id' => (int) $currentUser['id'],
        'record_id' => $consigneeId,
    ]);
    $pdo->commit();

    $respond(201, [
        'consignee' => [
            'id' => $consigneeId,
            'name' => $values['name'],
            'barangay' => $values['barangay'],
            'municipality' => $values['municipality'],
            'province' => $values['province'],
        ],
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Consignee creation failed: ' . $exception->getMessage());
    $respond(500, ['error' => 'Consignee could not be saved. Please try again.']);
}
