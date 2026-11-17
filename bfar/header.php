<?php
// Load session helpers before building navigation for the current user.
require_once __DIR__ . '/auth.php';

// Pages can provide a title and active navigation key; use defaults for callers that do not.
$pageTitle = $pageTitle ?? 'BFAR-EXIM';
$currentPage = $currentPage ?? '';
$currentUser = authCurrentUser();
$isAdmin = authIsAdmin();
$displayName = is_array($currentUser)
    ? ($currentUser['full_name'] ?? $currentUser['username'] ?? 'User')
    : '';

function navLink(string $page, string $label, string $href, string $currentPage): string
{
    // Mark the current destination for both styling and screen-reader navigation.
    $activeClass = $currentPage === $page ? ' active' : '';
    $currentAttribute = $currentPage === $page ? ' aria-current="page"' : '';

    return '<a class="nav-link' . $activeClass . '" href="' . $href . '"' . $currentAttribute . '>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}
?>
<!-- Shared document head and navigation used by the application pages. -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | BFAR-EXIM</title>
    <link rel="stylesheet" href="bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="custom.css?v=2">
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary d-print-none">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <span class="d-block fw-bold">BFAR-EXIM</span>
            <small class="d-block">BFAR Larena, Siquijor</small>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primary-navigation" aria-controls="primary-navigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="primary-navigation">
            <!-- Show only the management links allowed by the signed-in user's role. -->
            <div class="navbar-nav me-auto">
                <?= navLink('dashboard', 'Dashboard', 'index.php', $currentPage) ?>
                <?= navLink('outgoing', 'Out-going Commodities', 'outgoing.php', $currentPage) ?>
                <?= navLink('incoming', 'In-coming Commodities', 'incoming.php', $currentPage) ?>
                <?= navLink('search', 'Search Records', 'search.php', $currentPage) ?>
                <?php if ($isAdmin || (($currentUser['role'] ?? '') === 'encoder')): ?>
                    <?= navLink('shippers', 'Shippers', 'shippers.php', $currentPage) ?>
                    <?= navLink('consignees', 'Consignees', 'consignees.php', $currentPage) ?>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <?= navLink('users', 'Users', 'users.php', $currentPage) ?>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-lg-center mt-3 mt-lg-0">
                <!-- Signed-in users get a logout menu; guests get a login link. -->
                <?php if (is_array($currentUser)): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <form method="post" action="logout.php" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="dropdown-item" type="submit">Logout</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a class="btn btn-light text-primary" href="login.php">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<main class="flex-grow-1">
