<?php
// Session helpers provide the CSRF token and session lifecycle.
require_once __DIR__ . '/auth.php';

// Logout must be submitted as a form so it cannot be triggered by a simple link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
// Reject forged logout requests before clearing the active session.
if (!hash_equals(authCsrfToken(), $submittedToken)) {
    http_response_code(400);
    exit('Invalid logout request.');
}

$_SESSION = [];
// Rotate and destroy the session so its authenticated identity cannot be reused.
session_regenerate_id(true);
session_destroy();
header('Location: index.php');
exit;