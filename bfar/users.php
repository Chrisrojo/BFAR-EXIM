<?php
// Only administrators can create, edit, deactivate, or reset user accounts.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireAdministrator();

$pdo = getDatabaseConnection();
$currentUser = authCurrentUser();
$flash = $_SESSION['users_flash'] ?? null;
unset($_SESSION['users_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Route each protected form submission through its named management action.
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $targetId = $targetId && $targetId > 0 ? $targetId : null;
    $errorMessage = null;

    if (!hash_equals(authCsrfToken(), $submittedToken)) {
        $errorMessage = 'Your session expired. Please reload the page and try again.';
    } else {
        try {
            if ($action === 'create' || $action === 'edit') {
                // Validate profile fields and enforce the username and password rules.
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $role = (string) ($_POST['role'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                $validUsername = preg_match('/\A[A-Za-z0-9_.-]{3,60}\z/', $username) === 1;

                if ($fullName === '' || mb_strlen($fullName) > 150) {
                    $errorMessage = 'Enter a full name up to 150 characters.';
                } elseif (!$validUsername) {
                    $errorMessage = 'Username must be 3 to 60 characters using letters, numbers, periods, underscores, or hyphens.';
                } elseif (!in_array($role, ['admin', 'encoder'], true)) {
                    $errorMessage = 'Choose either the admin or encoder role.';
                } elseif ($action === 'create' && mb_strlen($password) < 8) {
                    $errorMessage = 'New passwords must be at least 8 characters.';
                } elseif ($action === 'edit' && !$targetId) {
                    $errorMessage = 'The selected user could not be found.';
                } else {
                    $duplicateQuery = $pdo->prepare(
                        'SELECT id FROM users WHERE username = :username AND (:user_id IS NULL OR id <> :user_id) LIMIT 1'
                    );
                    $duplicateQuery->execute(['username' => $username, 'user_id' => $action === 'edit' ? $targetId : null]);
                    if ($duplicateQuery->fetchColumn()) {
                        $errorMessage = 'That username is already in use.';
                    } else {
                        // Store only a password hash; never persist the submitted password itself.
                        $pdo->beginTransaction();
                        if ($action === 'create') {
                            $insertUser = $pdo->prepare(
                                'INSERT INTO users (full_name, username, password_hash, role, is_active)
                                 VALUES (:full_name, :username, :password_hash, :role, 1)'
                            );
                            $insertUser->execute([
                                'full_name' => $fullName,
                                'username' => $username,
                                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                'role' => $role,
                            ]);
                            $targetId = (int) $pdo->lastInsertId();
                            $logAction = 'create';
                            $logDetails = 'Created user account';
                            $successMessage = 'User account created.';
                        } else {
                            $updateUser = $pdo->prepare(
                                'UPDATE users SET full_name = :full_name, username = :username, role = :role WHERE id = :id'
                            );
                            $updateUser->execute([
                                'full_name' => $fullName,
                                'username' => $username,
                                'role' => $role,
                                'id' => $targetId,
                            ]);
                            $logAction = 'update';
                            $logDetails = 'Updated user account';
                            $successMessage = 'User account updated.';
                        }

                        $logQuery = $pdo->prepare(
                            'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                             VALUES (:user_id, :action, \'user\', :record_id, :details)'
                        );
                        $logQuery->execute([
                            'user_id' => (int) $currentUser['id'],
                            'action' => $logAction,
                            'record_id' => $targetId,
                            'details' => $logDetails,
                        ]);
                        $pdo->commit();
                    }
                }
            } elseif ($action === 'toggle_status') {
                // Lock the target row to avoid concurrent status changes overwriting each other.
                if (!$targetId) {
                    $errorMessage = 'The selected user could not be found.';
                } elseif ($targetId === (int) $currentUser['id']) {
                    $errorMessage = 'You cannot deactivate your own account.';
                } else {
                    $pdo->beginTransaction();
                    $statusQuery = $pdo->prepare('SELECT is_active FROM users WHERE id = :id FOR UPDATE');
                    $statusQuery->execute(['id' => $targetId]);
                    $activeStatus = $statusQuery->fetchColumn();
                    if ($activeStatus === false) {
                        $pdo->rollBack();
                        $errorMessage = 'The selected user could not be found.';
                    } else {
                        $newStatus = (int) $activeStatus === 1 ? 0 : 1;
                        $pdo->prepare('UPDATE users SET is_active = :status WHERE id = :id')->execute([
                            'status' => $newStatus,
                            'id' => $targetId,
                        ]);
                        $logQuery = $pdo->prepare(
                            'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                             VALUES (:user_id, :action, \'user\', :record_id, :details)'
                        );
                        $logQuery->execute([
                            'user_id' => (int) $currentUser['id'],
                            'action' => $newStatus === 1 ? 'reactivate' : 'deactivate',
                            'record_id' => $targetId,
                            'details' => $newStatus === 1 ? 'Reactivated user account' : 'Deactivated user account',
                        ]);
                        $pdo->commit();
                        $successMessage = $newStatus === 1 ? 'User account reactivated.' : 'User account deactivated.';
                    }
                }
            } elseif ($action === 'reset_password') {
                // Require matching password values before storing a new secure hash.
                $password = (string) ($_POST['password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
                if (!$targetId) {
                    $errorMessage = 'The selected user could not be found.';
                } elseif (mb_strlen($password) < 8) {
                    $errorMessage = 'Passwords must be at least 8 characters.';
                } elseif (!hash_equals($password, $confirmPassword)) {
                    $errorMessage = 'The password confirmation does not match.';
                } else {
                    $pdo->beginTransaction();
                    $updatePassword = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                    $updatePassword->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $targetId,
                    ]);
                    if ($updatePassword->rowCount() !== 1) {
                        $pdo->rollBack();
                        $errorMessage = 'The selected user could not be found.';
                    } else {
                        $logQuery = $pdo->prepare(
                            'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                             VALUES (:user_id, \'reset_password\', \'user\', :record_id, \'Reset user password\')'
                        );
                        $logQuery->execute([
                            'user_id' => (int) $currentUser['id'],
                            'record_id' => $targetId,
                        ]);
                        $pdo->commit();
                        $successMessage = 'User password reset.';
                    }
                }
            } else {
                $errorMessage = 'Unknown user-management action.';
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMessage = $exception->getCode() === '23000'
                ? 'That username is already in use.'
                : 'The user account could not be updated. Please try again.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMessage = 'The user account could not be updated. Please try again.';
        }
    }

    $_SESSION['users_flash'] = $errorMessage !== null
        ? ['type' => 'danger', 'message' => $errorMessage]
        : ['type' => 'success', 'message' => $successMessage ?? 'User account updated.'];
    header('Location: users.php');
    exit;
}

$users = $pdo->query(
    'SELECT id, full_name, username, role, is_active, created_at
     FROM users
     ORDER BY is_active DESC, role = \'admin\' DESC, full_name'
)->fetchAll(PDO::FETCH_ASSOC);
// Escape dynamic text consistently when inserting database values into HTML.
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = authCsrfToken();
$pageTitle = 'Users';
$currentPage = 'users';
require __DIR__ . '/header.php';
?>
<!-- The account table links to per-user dialogs for editing and status/password actions. -->
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h2 mb-0">Users</h1>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#add-user-modal">Add User</button>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $escape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= $escape($flash['message']) ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead><tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $userId = (int) $user['id']; $active = (int) $user['is_active'] === 1; $isCurrentUser = $userId === (int) $currentUser['id']; ?>
                        <tr>
                            <td><?= $escape($user['full_name']) ?><?= $isCurrentUser ? ' <span class="text-muted small">(you)</span>' : '' ?></td>
                            <td><?= $escape($user['username']) ?></td>
                            <td><span class="badge <?= $user['role'] === 'admin' ? 'bg-primary' : 'bg-info text-dark' ?>"><?= $escape(ucfirst($user['role'])) ?></span></td>
                            <td><?php if ($active): ?><span class="badge bg-success">Active</span><?php else: ?><span class="badge bg-danger">Inactive</span><?php endif; ?></td>
                            <td><div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#edit-user-<?= $userId ?>">Edit</button>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#status-user-<?= $userId ?>" <?= $isCurrentUser ? 'disabled title="You cannot deactivate your own account"' : '' ?>><?= $active ? 'Deactivate' : 'Reactivate' ?></button>
                                <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#reset-password-<?= $userId ?>">Reset Password</button>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($users === []): ?><tr><td colspan="5" class="text-center py-4">No user accounts found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div></div>
</div>

<!-- The create dialog submits a new account to the same CSRF-protected handler. -->
<div class="modal fade" id="add-user-modal" tabindex="-1" aria-labelledby="add-user-modal-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post" action="users.php">
        <div class="modal-header"><h2 class="modal-title h5" id="add-user-modal-title">Add User</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="create">
            <div class="mb-3"><label class="form-label" for="new-full-name">Full name</label><input class="form-control" id="new-full-name" name="full_name" maxlength="150" required></div>
            <div class="mb-3"><label class="form-label" for="new-username">Username</label><input class="form-control" id="new-username" name="username" minlength="3" maxlength="60" autocomplete="off" required></div>
            <div class="mb-3"><label class="form-label" for="new-password">Password</label><input class="form-control" id="new-password" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
            <div><label class="form-label" for="new-role">Role</label><select class="form-select" id="new-role" name="role" required><option value="encoder">Encoder</option><option value="admin">Admin</option></select></div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create User</button></div>
    </form>
</div></div></div>

<?php foreach ($users as $user): ?>
    <?php $userId = (int) $user['id']; $active = (int) $user['is_active'] === 1; $isCurrentUser = $userId === (int) $currentUser['id']; ?>
    <div class="modal fade" id="edit-user-<?= $userId ?>" tabindex="-1" aria-labelledby="edit-user-<?= $userId ?>-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
        <form method="post" action="users.php">
            <div class="modal-header"><h2 class="modal-title h5" id="edit-user-<?= $userId ?>-title">Edit User</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" value="<?= $userId ?>">
                <div class="mb-3"><label class="form-label" for="edit-full-name-<?= $userId ?>">Full name</label><input class="form-control" id="edit-full-name-<?= $userId ?>" name="full_name" value="<?= $escape($user['full_name']) ?>" maxlength="150" required></div>
                <div class="mb-3"><label class="form-label" for="edit-username-<?= $userId ?>">Username</label><input class="form-control" id="edit-username-<?= $userId ?>" name="username" value="<?= $escape($user['username']) ?>" minlength="3" maxlength="60" required></div>
                <div><label class="form-label" for="edit-role-<?= $userId ?>">Role</label><select class="form-select" id="edit-role-<?= $userId ?>" name="role"><option value="encoder" <?= $user['role'] === 'encoder' ? 'selected' : '' ?>>Encoder</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></div>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
        </form>
    </div></div></div>

    <div class="modal fade" id="status-user-<?= $userId ?>" tabindex="-1" aria-labelledby="status-user-<?= $userId ?>-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
        <form method="post" action="users.php">
            <div class="modal-header"><h2 class="modal-title h5" id="status-user-<?= $userId ?>-title"><?= $active ? 'Deactivate' : 'Reactivate' ?> user</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p>Are you sure you want to <?= $active ? 'deactivate' : 'reactivate' ?> the account for <strong><?= $escape($user['username']) ?></strong>?</p><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= $userId ?>"></div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn <?= $active ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= $active ? 'Deactivate' : 'Reactivate' ?></button></div>
        </form>
    </div></div></div>

    <div class="modal fade" id="reset-password-<?= $userId ?>" tabindex="-1" aria-labelledby="reset-password-<?= $userId ?>-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
        <form method="post" action="users.php">
            <div class="modal-header"><h2 class="modal-title h5" id="reset-password-<?= $userId ?>-title">Reset Password</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p>Set a new password for <strong><?= $escape($user['username']) ?></strong>.</p><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= $userId ?>">
                <div class="mb-3"><label class="form-label" for="reset-password-value-<?= $userId ?>">New password</label><input class="form-control" id="reset-password-value-<?= $userId ?>" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
                <div><label class="form-label" for="reset-password-confirm-<?= $userId ?>">Confirm new password</label><input class="form-control" id="reset-password-confirm-<?= $userId ?>" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Reset Password</button></div>
        </form>
    </div></div></div>
<?php endforeach; ?>

<?php
require __DIR__ . '/footer.php';
