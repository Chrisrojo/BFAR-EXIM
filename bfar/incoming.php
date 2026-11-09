<?php
// Authenticate record access and load the database used by this list.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRecordEditor();

$pdo = getDatabaseConnection();
// Read a one-time message from the previous archive request.
$flash = $_SESSION['incoming_flash'] ?? null;
unset($_SESSION['incoming_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    // Only administrators may archive permits; validate both the CSRF token and record ID.
    requireRecordRemovalPermission();
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $permitId = filter_var($_POST['permit_id'] ?? null, FILTER_VALIDATE_INT);

    if (!hash_equals(authCsrfToken(), $submittedToken) || !$permitId || $permitId < 1) {
        $_SESSION['incoming_flash'] = ['type' => 'danger', 'message' => 'The archive request was invalid. Please try again.'];
    } else {
        try {
            // Mark the permit archived and write its audit event as one transaction.
            $pdo->beginTransaction();
            $archiveQuery = $pdo->prepare('UPDATE incoming_permits SET is_archived = 1 WHERE id = :id AND is_archived = 0');
            $archiveQuery->execute(['id' => $permitId]);
            if ($archiveQuery->rowCount() === 1) {
                $logQuery = $pdo->prepare(
                    'INSERT INTO activity_log (user_id, action, record_type, record_id, details)
                     VALUES (:user_id, \'archive\', \'incoming_permit\', :record_id, \'Archived in-coming permit\')'
                );
                $logQuery->execute(['user_id' => authCurrentUser()['id'], 'record_id' => $permitId]);
                $pdo->commit();
                $_SESSION['incoming_flash'] = ['type' => 'success', 'message' => 'The in-coming record was archived.'];
            } else {
                $pdo->rollBack();
                $_SESSION['incoming_flash'] = ['type' => 'warning', 'message' => 'That record was already archived or could not be found.'];
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['incoming_flash'] = ['type' => 'danger', 'message' => 'The record could not be archived. Please try again.'];
        }
    }

    header('Location: incoming.php');
    exit;
}

// Calculate pagination from active incoming permits; archived records stay in storage but are hidden.
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? $page : 1;
$perPage = 10;
$totalRecords = (int) $pdo->query('SELECT COUNT(*) FROM incoming_permits WHERE is_archived = 0')->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
// Summarize each active permit with its species, totals, shipper, and source location.
$listQuery = $pdo->prepare(
    'SELECT p.id, p.shipment_date, p.ltp_code, p.inspected,
            GROUP_CONCAT(DISTINCT COALESCE(i.english_name, s.english_name) ORDER BY COALESCE(i.english_name, s.english_name) SEPARATOR 0x2c20) AS species,
            COALESCE(SUM(i.volume_kg), 0) AS total_kg,
            COALESCE(SUM(i.market_value), 0) AS total_value,
            sh.name AS shipper_name,
            CONCAT_WS(0x2c20, p.source_municipality, p.source_province) AS origin
     FROM incoming_permits AS p
     LEFT JOIN incoming_items AS i ON i.permit_id = p.id
     LEFT JOIN species AS s ON s.id = i.species_id
     LEFT JOIN shippers AS sh ON sh.id = p.shipper_id
     WHERE p.is_archived = 0
     GROUP BY p.id, p.shipment_date, p.ltp_code, p.inspected, sh.name,
              p.source_municipality, p.source_province
     ORDER BY p.shipment_date DESC, p.id DESC
     LIMIT :limit OFFSET :offset'
);
$listQuery->bindValue('limit', $perPage, PDO::PARAM_INT);
$listQuery->bindValue('offset', $offset, PDO::PARAM_INT);
$listQuery->execute();
$records = $listQuery->fetchAll(PDO::FETCH_ASSOC);

// Provide the shared layout with the page title and active menu item.
$pageTitle = 'In-coming Commodities';
$currentPage = 'incoming';
require __DIR__ . '/header.php';
?>
<!-- Table of active incoming permits and their summary values. -->
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h2 mb-0">In-coming Commodities</h1>
        <a class="btn btn-primary" href="incoming_form.php">Add In-coming Record</a>
    </div>
    <!-- Show the result of the most recent archive action, if one exists. -->
    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead><tr>
                    <th scope="col">Date</th><th scope="col">LTP code</th><th scope="col">Species</th>
                    <th class="text-end" scope="col">Total kg</th><th class="text-end" scope="col">Total value (PhP)</th>
                    <th scope="col">Shipper</th><th scope="col">Origin</th><th scope="col">Inspection</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr></thead>
                <tbody>
                    <?php if ($records === []): ?><tr><td class="text-center py-4" colspan="9">No active in-coming records found.</td></tr><?php endif; ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($record['shipment_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['ltp_code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['species'] ?: 'No species listed', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end"><?= number_format((float) $record['total_kg'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $record['total_value'], 2) ?></td>
                            <td><?= htmlspecialchars($record['shipper_name'] ?? 'Not set', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['origin'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?php if ($record['inspected'] === 'Inspected'): ?><span class="badge bg-success">Inspected</span><?php else: ?><span class="badge bg-secondary">Not Inspected</span><?php endif; ?></td>
                            <td><div class="d-flex gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="incoming_form.php?id=<?= (int) $record['id'] ?>">Edit</a>
                                <?php if (authIsAdmin()): ?><button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#archive-confirmation" data-permit-id="<?= (int) $record['id'] ?>" data-ltp-code="<?= htmlspecialchars($record['ltp_code'], ENT_QUOTES, 'UTF-8') ?>">Archive</button><?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav aria-label="In-coming records pages"><ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="incoming.php?page=<?= max(1, $page - 1) ?>">Previous</a></li>
                <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?><li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="incoming.php?page=<?= $pageNumber ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a></li><?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="incoming.php?page=<?= min($totalPages, $page + 1) ?>">Next</a></li>
            </ul></nav>
        <?php endif; ?>
    </div></div>
</div>
<?php if (authIsAdmin()): ?>
<div class="modal fade" id="archive-confirmation" tabindex="-1" aria-labelledby="archive-confirmation-title" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post" action="incoming.php">
        <div class="modal-header"><h2 class="modal-title h5" id="archive-confirmation-title">Archive in-coming record</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p class="mb-0">Archive permit <strong id="archive-ltp-code"></strong>? It will be hidden from the active list but will not be deleted.</p><input type="hidden" name="action" value="archive"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="permit_id" id="archive-permit-id" value=""></div>
        <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Archive record</button></div>
    </form>
</div></div></div>
<script>
    document.getElementById('archive-confirmation').addEventListener('show.bs.modal', (event) => {
        document.getElementById('archive-permit-id').value = event.relatedTarget.dataset.permitId;
        document.getElementById('archive-ltp-code').textContent = event.relatedTarget.dataset.ltpCode;
    });
</script>
<?php endif; ?>

<?php
require __DIR__ . '/footer.php';
