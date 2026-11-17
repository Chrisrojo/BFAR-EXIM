<?php

// Start a session only when one is not already active.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Return the signed-in user from the session, or null if the session has no valid user array.
function authCurrentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;

    return is_array($user) ? $user : null;
}

// Check whether the current user's role is administrator.
function authIsAdmin(): bool
{
    return (authCurrentUser()['role'] ?? '') === 'admin';
}

// Allow only safe local PHP page targets; use the home page for anything else.
function safeReturnTarget($target): string
{
    if (!is_string($target)
        || preg_match('/\A[A-Za-z0-9_-]+\.php(?:\?[A-Za-z0-9_=&.%+-]*)?\z/', $target) !== 1
    ) {
        return 'index.php';
    }

    return $target;
}

// Create a session CSRF token once, then reuse it for later requests.
function authCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Send guests to the login page and preserve their safe requested destination.
function requireLogin(): void
{
    if (authCurrentUser() !== null) {
        return;
    }

    $requestedPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    if (!empty($_SERVER['QUERY_STRING'])) {
        $requestedPage .= '?' . $_SERVER['QUERY_STRING'];
    }

    $target = safeReturnTarget($requestedPage);
    header('Location: login.php?next=' . rawurlencode($target));
    exit;
}

// Render a 403 access-denied page and stop processing the request.
function denyAccess(): void
{
    http_response_code(403);
    $pageTitle = 'Access denied';
    require __DIR__ . '/header.php';
    ?>
<div class="container py-5"><div class="alert alert-danger" role="alert">You do not have permission to view this page.</div></div>

<?php
    require __DIR__ . '/footer.php';
    exit;
}

// Require a signed-in administrator.
function requireAdministrator(): void
{
    requireLogin();

    if (!authIsAdmin()) {
        denyAccess();
    }
}

// Require a signed-in administrator or encoder to edit records.
function requireRecordEditor(): void
{
    requireLogin();

    $role = authCurrentUser()['role'] ?? '';
    if (!in_array($role, ['admin', 'encoder'], true)) {
        denyAccess();
    }
}

// Record removal is restricted to administrators.
function requireRecordRemovalPermission(): void
{
    requireAdministrator();
}