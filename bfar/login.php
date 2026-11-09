<?php
// Load session authentication helpers and the shared database connection.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$pageTitle = 'Login';
$currentPage = 'login';
$username = '';
$loginError = null;
// Restrict the post-login destination to a safe local PHP page.
$next = safeReturnTarget($_POST['next'] ?? $_GET['next'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read credentials and verify the form's CSRF token before querying user records.
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals(authCsrfToken(), $submittedToken)) {
        $loginError = 'Your session expired. Please try again.';
    } else {
        try {
            // Fetch one account by username, then verify it is active and its password hash matches.
            $userQuery = getDatabaseConnection()->prepare(
                'SELECT id, full_name, username, password_hash, role, is_active
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $userQuery->execute(['username' => $username]);
            $user = $userQuery->fetch(PDO::FETCH_ASSOC);

            if ($user
                && (int) $user['is_active'] === 1
                && password_verify($password, $user['password_hash'])
            ) {
                // Log successful sign-in before creating the authenticated session.
                $auditQuery = getDatabaseConnection()->prepare(
                    'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                     VALUES (:user_id, \'login\', \'user\', :record_id, \'Successful login\')'
                );
                $auditQuery->execute([
                    'user_id' => (int) $user['id'],
                    'record_id' => (int) $user['id'],
                ]);

                session_regenerate_id(true);
                // Keep only the identity and role needed by the authorization helpers.
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                ];

                header('Location: ' . $next);
                exit;
            }

            $loginError = 'Invalid username or password.';
        } catch (PDOException $exception) {
            $loginError = 'Login is temporarily unavailable. Check the database connection and activity_log table.';
        }
    }
}

// Render the login page with any validation or database error beside the form.
require __DIR__ . '/header.php';
?>
<!-- Login form posts credentials back to this page. -->
<div class="container py-5">
    <div class="row">
        <div class="col-12 col-md-4 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h1 class="h4 mb-0">Login</h1>
                </div>
                <div class="card-body">
                    <?php if ($loginError !== null): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <form method="post" action="login.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label class="form-label" for="username">Username</label>
                            <input class="form-control" id="username" name="username" type="text" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/footer.php';
