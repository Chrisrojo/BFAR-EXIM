<?php
// This endpoint serves asynchronous shipper creation from both permit forms.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRecordEditor();

header('Content-Type: application/json; charset=utf-8');

// Return a consistent JSON response and stop processing the request.
$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Only form submissions may create a shipper.
    header('Allow: POST');
    $respond(405, ['error' => 'Use the shipper form to add a shipper.']);
}

if (!hash_equals(authCsrfToken(), (string) ($_POST['csrf_token'] ?? ''))) {
    $respond(403, ['error' => 'Your session expired. Reload the page and try again.']);
}

$values = [
    // Trim all submitted fields before applying length and format checks.
    'name' => trim((string) ($_POST['name'] ?? '')),
    'sex' => trim((string) ($_POST['sex'] ?? '')),
    'client_type' => trim((string) ($_POST['client_type'] ?? 'Regular')) ?: 'Regular',
    'company' => trim((string) ($_POST['company'] ?? '')),
    'contact_number' => trim((string) ($_POST['contact_number'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'municipality' => trim((string) ($_POST['municipality'] ?? '')),
    'province' => trim((string) ($_POST['province'] ?? '')),
];

if ($values['name'] === '' || mb_strlen($values['name']) > 100) {
    $respond(422, ['error' => 'Enter a shipper name up to 100 characters.']);
}
if (!in_array($values['sex'], ['', 'Male', 'Female'], true)) {
    $respond(422, ['error' => 'Choose a valid sex or leave it blank.']);
}
if (mb_strlen($values['client_type']) > 30
    || mb_strlen($values['company']) > 100
    || mb_strlen($values['contact_number']) > 20
    || mb_strlen($values['email']) > 100
    || mb_strlen($values['municipality']) > 80
) {
    $respond(422, ['error' => 'One or more shipper fields exceed the allowed length.']);
}
if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    $respond(422, ['error' => 'Enter a valid email address or leave the email field blank.']);
}

$pdo = getDatabaseConnection();
// Ensure the supplied province came from the database-backed dropdown.
$provinceQuery = $pdo->prepare('SELECT province_name FROM provinces WHERE province_name = :province');
$provinceQuery->execute(['province' => $values['province']]);
if ($values['province'] !== '' && !$provinceQuery->fetchColumn()) {
    $respond(422, ['error' => 'Choose a province from the list.']);
}

$currentUser = authCurrentUser();
try {
    // Store the shipper and audit event atomically so a partial create is not left behind.
    $pdo->beginTransaction();
    $insertShipper = $pdo->prepare(
        'INSERT INTO shippers (name, sex, client_type, company, contact_number, email, municipality, province)
         VALUES (:name, :sex, :client_type, :company, :contact_number, :email, :municipality, :province)'
    );
    $insertShipper->execute([
        'name' => $values['name'],
        'sex' => $values['sex'] !== '' ? $values['sex'] : null,
        'client_type' => $values['client_type'],
        'company' => $values['company'] !== '' ? $values['company'] : null,
        'contact_number' => $values['contact_number'] !== '' ? $values['contact_number'] : null,
        'email' => $values['email'] !== '' ? $values['email'] : null,
        'municipality' => $values['municipality'] !== '' ? $values['municipality'] : null,
        'province' => $values['province'] !== '' ? $values['province'] : null,
    ]);
    $shipperId = (int) $pdo->lastInsertId();

    $logQuery = $pdo->prepare(
        'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
         VALUES (:user_id, \'create\', \'shipper\', :record_id, \'Added shipper from permit form\')'
    );
    $logQuery->execute([
        'user_id' => (int) $currentUser['id'],
        'record_id' => $shipperId,
    ]);
    $pdo->commit();

    // Return only the values the open permit form needs to add the new dropdown option.
    $respond(201, [
        'shipper' => [
            'id' => $shipperId,
            'name' => $values['name'],
            'company' => $values['company'],
        ],
    ]);
} catch (Throwable $exception) {
    // Roll back on any database error and log details server-side, not in the browser response.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Shipper creation failed: ' . $exception->getMessage());
    $respond(500, ['error' => 'Shipper could not be saved. Please try again.']);
}
