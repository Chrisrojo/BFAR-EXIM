<?php
// Require an authenticated user before querying permit and contact details.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$pdo = getDatabaseConnection();
// Read and normalize optional search filters before building the parameterized query.
$type = in_array($_GET['type'] ?? 'both', ['outgoing', 'incoming', 'both'], true) ? $_GET['type'] ?? 'both' : 'both';
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$speciesId = filter_var($_GET['species_id'] ?? null, FILTER_VALIDATE_INT);
$speciesId = $speciesId && $speciesId > 0 ? $speciesId : null;
$speciesName = $speciesId === null ? trim((string) ($_GET['species_name'] ?? '')) : '';
$ltpCode = trim((string) ($_GET['ltp_code'] ?? ''));
$contactName = trim((string) ($_GET['contact_name'] ?? ''));
$place = trim((string) ($_GET['place'] ?? ''));
$validDate = static function (string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo)) $dateTo = '';
$speciesName = mb_substr($speciesName, 0, 120);
$ltpCode = mb_substr($ltpCode, 0, 40);
$contactName = mb_substr($contactName, 0, 100);
$place = mb_substr($place, 0, 80);

$directions = [
    'outgoing' => [
        'label' => 'Out-going',
        'permit_table' => 'outgoing_permits',
        'item_table' => 'outgoing_items',
        'place_municipality' => 'destination_municipality',
        'place_province' => 'destination_province',
        'place_barangay' => 'destination_barangay',
        'remarks' => 'p.remarks',
    ],
    'incoming' => [
        'label' => 'In-coming',
        'permit_table' => 'incoming_permits',
        'item_table' => 'incoming_items',
        'place_municipality' => 'source_municipality',
        'place_province' => 'source_province',
        'place_barangay' => 'source_barangay',
        'remarks' => 'NULL',
    ],
];
// Generate one compatible query branch per selected permit direction.
$selectedDirections = $type === 'both' ? array_keys($directions) : [$type];
$branches = [];
$queryParameters = [];

foreach ($selectedDirections as $direction) {
    $config = $directions[$direction];
    $prefix = $direction === 'outgoing' ? 'out' : 'in';
    $conditions = ['p.is_archived = 0'];

    if ($dateFrom !== '') {
        $key = $prefix . '_date_from';
        $conditions[] = 'p.shipment_date >= :' . $key;
        $queryParameters[$key] = $dateFrom;
    }
    if ($dateTo !== '') {
        $key = $prefix . '_date_to';
        $conditions[] = 'p.shipment_date <= :' . $key;
        $queryParameters[$key] = $dateTo;
    }
    if ($speciesId !== null) {
        $key = $prefix . '_species_id';
        $conditions[] = 'i.species_id = :' . $key;
        $queryParameters[$key] = $speciesId;
    }
    if ($speciesName !== '') {
        $key = $prefix . '_species_name';
        $conditions[] = 'COALESCE(i.english_name, s.english_name) = :' . $key;
        $queryParameters[$key] = $speciesName;
    }
    if ($ltpCode !== '') {
        $key = $prefix . '_ltp_code';
        $conditions[] = 'p.ltp_code LIKE :' . $key;
        $queryParameters[$key] = '%' . $ltpCode . '%';
    }
    if ($contactName !== '') {
        $shipperKey = $prefix . '_shipper_name';
        $consigneeKey = $prefix . '_consignee_name';
        $conditions[] = '(sh.name LIKE :' . $shipperKey . ' OR co.name LIKE :' . $consigneeKey . ')';
        $queryParameters[$shipperKey] = '%' . $contactName . '%';
        $queryParameters[$consigneeKey] = '%' . $contactName . '%';
    }
    if ($place !== '') {
        $key = $prefix . '_place';
        $conditions[] = 'COALESCE(NULLIF(p.' . $config['place_municipality'] . ', \'\'), \'Unknown\') LIKE :' . $key;
        $queryParameters[$key] = '%' . $place . '%';
    }

    $branches[] = 'SELECT \'' . $config['label'] . '\' AS direction_label,
            \'' . $direction . '\' AS direction_key,
            p.id AS permit_id, p.shipment_date, p.ltp_code, p.amount,
            p.operating_unit, p.issuing_province, p.ltp_issuance_location,
            p.product_form, p.product_category, p.cit_cha_classification,
            p.shipper_id, p.consignee_id,
            p.source_barangay, p.source_municipality, p.source_province, p.source_region,
            p.destination_barangay, p.destination_municipality, p.destination_province, p.destination_region,
            p.purpose_of_transport, p.spsic_no, p.inspected, ' . $config['remarks'] . ' AS remarks,
            p.created_by, p.created_at, p.is_archived,
            sh.name AS shipper_name, sh.sex AS shipper_sex, sh.client_type AS shipper_client_type,
            sh.company AS shipper_company, sh.contact_number AS shipper_contact_number,
            sh.email AS shipper_email, sh.municipality AS shipper_municipality, sh.province AS shipper_province,
            co.name AS consignee_name, co.contact_number AS consignee_contact_number,
            co.municipality AS consignee_municipality, co.province AS consignee_province,
            u.full_name AS created_by_name,
            i.species_id, COALESCE(i.english_name, s.english_name) AS species_name,
            COALESCE(i.scientific_name, s.scientific_name) AS scientific_name,
            COALESCE(i.local_name, s.local_name) AS local_name,
            i.volume_kg, i.volume_pcs, i.market_value
        FROM ' . $config['permit_table'] . ' AS p
        INNER JOIN ' . $config['item_table'] . ' AS i ON i.permit_id = p.id
        LEFT JOIN species AS s ON s.id = i.species_id
        LEFT JOIN shippers AS sh ON sh.id = p.shipper_id
        LEFT JOIN consignees AS co ON co.id = p.consignee_id
        LEFT JOIN users AS u ON u.id = p.created_by
        WHERE ' . implode(' AND ', $conditions);
}

$unionQuery = implode(' UNION ALL ', $branches);
// Count matching item rows and distinct permits so results can be paginated accurately.
$countQuery = $pdo->prepare(
    'SELECT COUNT(*) AS item_count,
            COUNT(DISTINCT direction_key, permit_id) AS permit_count
     FROM (' . $unionQuery . ') AS matching_items'
);
$countQuery->execute($queryParameters);
$resultCounts = $countQuery->fetch(PDO::FETCH_ASSOC);
$totalResults = (int) $resultCounts['item_count'];
$totalPermits = (int) $resultCounts['permit_count'];
$perPage = 20;
$totalPages = max(1, (int) ceil($totalResults / $perPage));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? min($page, $totalPages) : 1;
$resultQuery = $pdo->prepare(
    'SELECT * FROM (' . $unionQuery . ') AS matching_items
     ORDER BY shipment_date DESC, permit_id DESC, species_name
     LIMIT :result_limit OFFSET :result_offset'
);
foreach ($queryParameters as $key => $value) {
    $resultQuery->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$resultQuery->bindValue(':result_limit', $perPage, PDO::PARAM_INT);
$resultQuery->bindValue(':result_offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$resultQuery->execute();
$results = $resultQuery->fetchAll(PDO::FETCH_ASSOC);

// Group result rows by permit; each visible permit can then show its full item list in a details dialog.
$records = [];
foreach ($results as $result) {
    $key = $result['direction_key'] . '-' . $result['permit_id'];
    if (!isset($records[$key])) {
        $records[$key] = $result;
        $records[$key]['items'] = [];
    }
}

// Fetch all species lines for each visible permit so a filtered result's modal still shows the full record.
foreach ($selectedDirections as $direction) {
    $permitIds = [];
    foreach ($records as $record) {
        if ($record['direction_key'] === $direction) $permitIds[] = (int) $record['permit_id'];
    }
    $permitIds = array_values(array_unique($permitIds));
    if ($permitIds === []) continue;
    $marks = implode(',', array_fill(0, count($permitIds), '?'));
    $table = $directions[$direction]['item_table'];
    $itemsQuery = $pdo->prepare(
        'SELECT i.permit_id,
                COALESCE(i.english_name, s.english_name) AS species_name,
                COALESCE(i.scientific_name, s.scientific_name) AS scientific_name,
                COALESCE(i.local_name, s.local_name) AS local_name,
                i.volume_kg, i.volume_pcs, i.market_value
         FROM ' . $table . ' AS i
         LEFT JOIN species AS s ON s.id = i.species_id
         WHERE i.permit_id IN (' . $marks . ')
         ORDER BY i.permit_id, species_name'
    );
    foreach ($permitIds as $index => $permitId) {
        $itemsQuery->bindValue($index + 1, $permitId, PDO::PARAM_INT);
    }
    $itemsQuery->execute();
    while ($item = $itemsQuery->fetch(PDO::FETCH_ASSOC)) {
        $recordKey = $direction . '-' . $item['permit_id'];
        if (isset($records[$recordKey])) $records[$recordKey]['items'][] = $item;
    }
}

$speciesOptions = $pdo->query('SELECT id, english_name FROM species WHERE is_active = 1 ORDER BY english_name')->fetchAll(PDO::FETCH_ASSOC);
$activeSpeciesNames = array_column($speciesOptions, 'english_name');
$pageParameters = $_GET;
unset($pageParameters['page']);
$pageBaseUrl = 'search.php' . ($pageParameters === [] ? '' : '?' . http_build_query($pageParameters) . '&');
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$displayPlace = static function (array $row): string {
    $municipality = $row['direction_key'] === 'outgoing' ? $row['destination_municipality'] : $row['source_municipality'];
    $province = $row['direction_key'] === 'outgoing' ? $row['destination_province'] : $row['source_province'];
    return implode(', ', array_filter([$municipality, $province], static fn ($value) => $value !== null && $value !== ''));
};

$pageTitle = 'Search Records';
$currentPage = 'search';
require __DIR__ . '/header.php';
?>
<!-- Search filters and paginated result rows are rendered from the normalized query state. -->
<div class="container py-4 overflow-hidden">
    <h1 class="h2 mb-4">Search Records</h1>
    <section class="card mb-4" aria-label="Search filters">
        <div class="card-body">
            <form method="get" action="search.php" class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="both" <?= $type === 'both' ? 'selected' : '' ?>>Both</option>
                        <option value="outgoing" <?= $type === 'outgoing' ? 'selected' : '' ?>>Out-going</option>
                        <option value="incoming" <?= $type === 'incoming' ? 'selected' : '' ?>>In-coming</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2"><label class="form-label" for="date_from">Date from</label><input class="form-control" id="date_from" name="date_from" type="date" value="<?= $escape($dateFrom) ?>"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="date_to">Date to</label><input class="form-control" id="date_to" name="date_to" type="date" value="<?= $escape($dateTo) ?>"></div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label" for="species_id">Species</label>
                    <select class="form-select" id="species_id" name="species_id">
                        <option value="" <?= $speciesId === null && $speciesName === '' ? 'selected' : '' ?>>All species</option>
                        <?php if ($speciesName !== ''): ?><option value="" selected>Filtered: <?= $escape($speciesName) ?></option><?php endif; ?>
                        <?php foreach ($speciesOptions as $species): ?>
                            <option value="<?= (int) $species['id'] ?>" <?= (string) $speciesId === (string) $species['id'] ? 'selected' : '' ?>><?= $escape($species['english_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($speciesName !== ''): ?>
                    <input type="hidden" name="species_name" value="<?= $escape($speciesName) ?>">
                <?php endif; ?>
                <div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="ltp_code">LTP code</label><input class="form-control" id="ltp_code" name="ltp_code" maxlength="40" value="<?= $escape($ltpCode) ?>"></div>
                <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="contact_name">Shipper or consignee</label><input class="form-control" id="contact_name" name="contact_name" value="<?= $escape($contactName) ?>"></div>
                <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="place">Destination or origin municipality</label><input class="form-control" id="place" name="place" value="<?= $escape($place) ?>"></div>
                <div class="col-12 col-lg-auto d-flex gap-2"><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-secondary" href="search.php">Reset</a></div>
            </form>
        </div>
    </section>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Search results</h2>
        <span class="text-muted small"><?= number_format($totalResults) ?> item<?= $totalResults === 1 ? '' : 's' ?> across <?= number_format($totalPermits) ?> permit<?= $totalPermits === 1 ? '' : 's' ?></span>
    </div>
    <div class="card"><div class="card-body overflow-hidden">
        <div class="table-responsive overflow-auto">
            <table class="table table-striped table-hover align-middle">
                <thead><tr><th scope="col">Type</th><th scope="col">Date</th><th scope="col">LTP code</th><th scope="col">Species</th><th class="text-end" scope="col">kg</th><th class="text-end" scope="col">Value (PhP)</th><th scope="col">Place</th><th scope="col"><span class="visually-hidden">Details</span></th></tr></thead>
                <tbody>
                    <?php if ($results === []): ?><tr><td class="text-center py-4" colspan="8">No records match these filters.</td></tr><?php endif; ?>
                    <?php foreach ($results as $result): ?>
                        <?php $modalId = 'record-' . $result['direction_key'] . '-' . (int) $result['permit_id']; ?>
                        <tr>
                            <td><span class="badge <?= $result['direction_key'] === 'outgoing' ? 'bg-primary' : 'bg-success' ?>"><?= $escape($result['direction_label']) ?></span></td>
                            <td><?= $escape(date('M j, Y', strtotime($result['shipment_date']))) ?></td>
                            <td><?= $escape($result['ltp_code']) ?></td>
                            <td><?= $escape($result['species_name']) ?></td>
                            <td class="text-end"><?= number_format((float) $result['volume_kg'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $result['market_value'], 2) ?></td>
                            <td><?= $escape($displayPlace($result)) ?></td>
                            <td><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#<?= $modalId ?>">View</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Search result pages"><ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $escape($pageBaseUrl) ?>page=<?= max(1, $page - 1) ?>">Previous</a></li>
                <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?><li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $escape($pageBaseUrl) ?>page=<?= $pageNumber ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a></li><?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $escape($pageBaseUrl) ?>page=<?= min($totalPages, $page + 1) ?>">Next</a></li>
            </ul></nav>
        <?php endif; ?>
    </div></div>

    <?php foreach ($records as $recordKey => $record): ?>
        <?php $modalId = 'record-' . $record['direction_key'] . '-' . (int) $record['permit_id']; ?>
        <div class="modal fade" id="<?= $modalId ?>" tabindex="-1" aria-labelledby="<?= $modalId ?>-title" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header">
                    <div><span class="badge <?= $record['direction_key'] === 'outgoing' ? 'bg-primary' : 'bg-success' ?> mb-2"><?= $escape($record['direction_label']) ?></span><h2 class="modal-title h5" id="<?= $modalId ?>-title">Permit <?= $escape($record['ltp_code']) ?></h2></div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6"><h3 class="h6">Permit details</h3><dl class="row mb-0">
                            <dt class="col-sm-5">Shipment date</dt><dd class="col-sm-7"><?= $escape(date('M j, Y', strtotime($record['shipment_date']))) ?></dd>
                            <dt class="col-sm-5">Amount</dt><dd class="col-sm-7"><?= $record['amount'] === null ? 'Not set' : 'PhP ' . number_format((float) $record['amount'], 2) ?></dd>
                            <dt class="col-sm-5">Operating unit</dt><dd class="col-sm-7"><?= $escape($record['operating_unit']) ?></dd>
                            <dt class="col-sm-5">Issuing province</dt><dd class="col-sm-7"><?= $escape($record['issuing_province']) ?></dd>
                            <dt class="col-sm-5">LTP issuance location</dt><dd class="col-sm-7"><?= $escape($record['ltp_issuance_location']) ?></dd>
                            <dt class="col-sm-5">Inspection</dt><dd class="col-sm-7"><?= $escape($record['inspected']) ?></dd>
                            <dt class="col-sm-5">Entered by</dt><dd class="col-sm-7"><?= $escape($record['created_by_name']) ?></dd>
                            <dt class="col-sm-5">Created</dt><dd class="col-sm-7"><?= $escape($record['created_at']) ?></dd>
                            <dt class="col-sm-5">Purpose</dt><dd class="col-sm-7"><?= $escape($record['purpose_of_transport']) ?></dd>
                            <dt class="col-sm-5">SPSIC no.</dt><dd class="col-sm-7"><?= $escape($record['spsic_no']) ?></dd>
                        </dl></div>
                        <div class="col-md-6"><h3 class="h6">Product details</h3><dl class="row mb-0">
                            <dt class="col-sm-5">Product form</dt><dd class="col-sm-7"><?= $escape($record['product_form']) ?></dd>
                            <dt class="col-sm-5">Product category</dt><dd class="col-sm-7"><?= $escape($record['product_category']) ?></dd>
                            <dt class="col-sm-5">CIT-CHA classification</dt><dd class="col-sm-7"><?= $escape($record['cit_cha_classification']) ?></dd>
                            <dt class="col-sm-5">Source</dt><dd class="col-sm-7"><?= $escape(implode(', ', array_filter([$record['source_barangay'], $record['source_municipality'], $record['source_province'], $record['source_region']]))) ?></dd>
                            <dt class="col-sm-5">Destination</dt><dd class="col-sm-7"><?= $escape(implode(', ', array_filter([$record['destination_barangay'], $record['destination_municipality'], $record['destination_province'], $record['destination_region']]))) ?></dd>
                            <?php if ($record['direction_key'] === 'outgoing'): ?><dt class="col-sm-5">Remarks</dt><dd class="col-sm-7"><?= $escape($record['remarks']) ?></dd><?php endif; ?>
                        </dl></div>
                        <div class="col-md-6"><h3 class="h6">Shipper</h3><dl class="row mb-0">
                            <dt class="col-sm-5">Name</dt><dd class="col-sm-7"><?= $escape($record['shipper_name']) ?></dd>
                            <dt class="col-sm-5">Sex / client type</dt><dd class="col-sm-7"><?= $escape(implode(' / ', array_filter([$record['shipper_sex'], $record['shipper_client_type']]))) ?></dd>
                            <dt class="col-sm-5">Company</dt><dd class="col-sm-7"><?= $escape($record['shipper_company']) ?></dd>
                            <dt class="col-sm-5">Contact number</dt><dd class="col-sm-7"><?= $escape($record['shipper_contact_number']) ?></dd>
                            <dt class="col-sm-5">Email</dt><dd class="col-sm-7"><?= $escape($record['shipper_email']) ?></dd>
                            <dt class="col-sm-5">Address</dt><dd class="col-sm-7"><?= $escape(implode(', ', array_filter([$record['shipper_municipality'], $record['shipper_province']]))) ?></dd>
                        </dl></div>
                        <div class="col-md-6"><h3 class="h6">Consignee</h3><dl class="row mb-0">
                            <dt class="col-sm-5">Name</dt><dd class="col-sm-7"><?= $escape($record['consignee_name']) ?></dd>
                            <dt class="col-sm-5">Contact number</dt><dd class="col-sm-7"><?= $escape($record['consignee_contact_number']) ?></dd>
                            <dt class="col-sm-5">Address</dt><dd class="col-sm-7"><?= $escape(implode(', ', array_filter([$record['consignee_municipality'], $record['consignee_province']]))) ?></dd>
                        </dl></div>
                    </div>
                    <h3 class="h6 mt-4">Species and volumes</h3>
                    <div class="table-responsive overflow-auto"><table class="table table-sm table-striped table-hover"><thead><tr><th scope="col">Species</th><th scope="col">Scientific name</th><th scope="col">Local name</th><th class="text-end" scope="col">kg</th><th class="text-end" scope="col">pcs</th><th class="text-end" scope="col">Value (PhP)</th></tr></thead><tbody>
                        <?php foreach ($record['items'] as $item): ?><tr><td><?= $escape($item['species_name']) ?></td><td><?= $escape($item['scientific_name']) ?></td><td><?= $escape($item['local_name']) ?></td><td class="text-end"><?= number_format((float) $item['volume_kg'], 2) ?></td><td class="text-end"><?= number_format((int) $item['volume_pcs']) ?></td><td class="text-end"><?= number_format((float) $item['market_value'], 2) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>
                <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<?php
require __DIR__ . '/footer.php';
