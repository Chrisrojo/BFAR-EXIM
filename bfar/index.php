<?php
// Dashboard data is read from database views; this page does not modify permit records.
require_once __DIR__ . '/db.php';

$pageTitle = 'Monthly Dashboard';
$currentPage = 'dashboard';

$currentYear = (int) date('Y');
// Accept only sensible reporting periods and fall back to the current month and year.
$month = isset($_GET['month']) && ctype_digit((string) $_GET['month'])
    ? (int) $_GET['month']
    : (int) date('n');
$year = isset($_GET['year']) && ctype_digit((string) $_GET['year'])
    ? (int) $_GET['year']
    : $currentYear;

if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > $currentYear + 1) {
    $year = $currentYear;
}

// These views contain shipment items only; no shipper or consignee details are selected.
function loadDashboardSection(
    PDO $pdo,
    string $viewName,
    string $speciesColumn,
    string $municipalityColumn,
    string $provinceColumn,
    int $year,
    int $month
): array
{
    // Apply the same month/year filter to summary, species, and location totals.
    $periodFilter = ' WHERE yr = :year AND mo = :month';
    $parameters = ['year' => $year, 'month' => $month];

    $summaryQuery = $pdo->prepare(
        'SELECT COUNT(DISTINCT permit_id) AS permit_count,
                COALESCE(SUM(volume_kg), 0) AS total_kg,
                COALESCE(SUM(volume_pcs), 0) AS total_pcs,
                COALESCE(SUM(market_value), 0) AS total_market_value
         FROM ' . $viewName . $periodFilter
    );
    $summaryQuery->execute($parameters);
    $summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);

    $speciesQuery = $pdo->prepare(
        'SELECT COALESCE(' . $speciesColumn . ', \'Unknown species\') AS species_name,
                COALESCE(SUM(volume_kg), 0) AS total_kg,
                COALESCE(SUM(volume_pcs), 0) AS total_pcs,
                COALESCE(SUM(market_value), 0) AS total_market_value
         FROM ' . $viewName . $periodFilter . '
         GROUP BY ' . $speciesColumn . '
         ORDER BY species_name'
    );
    $speciesQuery->execute($parameters);

    $locationQuery = $pdo->prepare(
        'SELECT COALESCE(NULLIF(' . $municipalityColumn . ', \'\'), \'Unknown\') AS municipality,
            COALESCE(NULLIF(' . $provinceColumn . ', \'\'), \'Unknown\') AS province,
                COUNT(DISTINCT permit_id) AS permit_count
         FROM ' . $viewName . $periodFilter . '
         GROUP BY ' . $municipalityColumn . ', ' . $provinceColumn . '
         ORDER BY province, municipality'
    );
    $locationQuery->execute($parameters);

    return [
        'summary' => $summary,
        'species' => $speciesQuery->fetchAll(PDO::FETCH_ASSOC),
        'locations' => $locationQuery->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$dashboardData = [];
$databaseError = null;
try {
    // Load outgoing and incoming aggregates using the matching destination/origin fields.
    $pdo = getDatabaseConnection();
    $dashboardData['outgoing'] = loadDashboardSection(
        $pdo,
        'v_outgoing_lines',
        'english_name',
        'destination_municipality',
        'destination_province',
        $year,
        $month
    );
    $dashboardData['incoming'] = loadDashboardSection(
        $pdo,
        'v_incoming_lines',
        'english_name',
        'source_municipality',
        'source_province',
        $year,
        $month
    );
} catch (PDOException $exception) {
    $databaseError = 'Dashboard data is unavailable. Start MySQL in XAMPP and import the latest database/bfar_exim.sql file.';
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$chartJsAvailable = is_file(__DIR__ . '/chartjs/chart.umd.min.js');
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
$searchUrl = static fn (array $filters): string => 'search.php?' . http_build_query($filters);

require __DIR__ . '/header.php';
?>
<!-- The dashboard shows period selection, report summaries, tables, and chart data. -->
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <h1 class="h2 mb-0" id="page-title">Monthly Dashboard</h1>
        <div class="d-print-none">
            <button class="btn btn-outline-primary d-print-none" type="button" onclick="window.print()">Print Report</button>
            <p class="small text-muted mt-2 mb-0">Choose “Save as PDF” in the print window to save a PDF copy.</p>
        </div>
    </div>

    <section class="d-none d-print-block mb-4" aria-label="Printed report heading">
        <h1 class="h3">BFAR Larena, Siquijor – Monthly Statistics of Out-going and In-coming Commodities</h1>
        <p class="mb-1"><strong>Reporting period:</strong> <?= $escape($monthNames[$month] . ' ' . $year) ?></p>
        <p class="mb-0"><strong>Date printed:</strong> <?= $escape(date('F j, Y g:i A')) ?></p>
    </section>

    <section class="card mb-4 d-print-none" aria-label="Dashboard reporting period">
        <div class="card-body">
            <form class="row g-3 align-items-end" method="get" action="index.php">
                <div class="col-12 col-sm-5 col-lg-4">
                    <label class="form-label" for="month">Month</label>
                    <select class="form-select" id="month" name="month">
                        <?php foreach ($monthNames as $monthNumber => $monthName): ?>
                            <option value="<?= $monthNumber ?>" <?= $month === $monthNumber ? 'selected' : '' ?>><?= $monthName ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label" for="year">Year</label>
                    <select class="form-select" id="year" name="year">
                        <?php for ($optionYear = $currentYear + 1; $optionYear >= 2000; $optionYear--): ?>
                            <option value="<?= $optionYear ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= $optionYear ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-auto">
                        <button class="btn btn-primary d-print-none" type="submit">Show</button>
                </div>
            </form>
        </div>
    </section>

    <?php if ($databaseError !== null): ?>
        <div class="alert alert-warning" role="alert"><?= $escape($databaseError) ?></div>
    <?php else: ?>
        <?php if (!$chartJsAvailable): ?>
            <div class="alert alert-warning" role="alert">Graphs are unavailable. Add the local Chart.js bundle at chartjs/chart.umd.min.js.</div>
        <?php endif; ?>
        <div class="row g-4 dashboard-grid">
            <?php
            $sections = [
                'outgoing' => [
                    'title' => 'OUT-GOING COMMODITIES',
                    'locationTitle' => 'Shipments per destination',
                    'locationKind' => 'Destination',
                    'headerClass' => 'bg-primary',
                    'chartPrefix' => 'outgoing',
                ],
                'incoming' => [
                    'title' => 'IN-COMING COMMODITIES',
                    'locationTitle' => 'Shipments per origin',
                    'locationKind' => 'Origin',
                    'headerClass' => 'bg-success',
                    'chartPrefix' => 'incoming',
                ],
            ];
            foreach ($sections as $direction => $section):
                $sectionData = $dashboardData[$direction];
                $summary = $sectionData['summary'];
                $hasRecords = (int) $summary['permit_count'] > 0;
                $speciesRows = $sectionData['species'];
                $locationRows = $sectionData['locations'];
                $labels = array_column($speciesRows, 'species_name');
                $monthSearchFilters = [
                    'type' => $direction,
                    'date_from' => $monthStart,
                    'date_to' => $monthEnd,
                ];
            ?>
                <div class="col-12 col-lg-6 dashboard-column">
                    <section class="card h-100 dashboard-section" aria-label="<?= $escape($section['title']) ?>">
                        <div class="card-header text-white <?= $section['headerClass'] ?>">
                            <h2 class="h5 mb-0"><?= $escape($section['title']) ?></h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$hasRecords): ?>
                                <div class="alert alert-info" role="status">No records for this month</div>
                            <?php endif; ?>

                            <div class="row g-2 mb-4">
                                <div class="col-6 col-xl-3">
                                    <div class="card h-100"><div class="card-body py-3">
                                        <div class="small text-muted">Permits</div>
                                        <div class="h5 mb-0"><?= number_format((int) $summary['permit_count']) ?></div>
                                    </div></div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="card h-100"><div class="card-body py-3">
                                        <div class="small text-muted">Total kg</div>
                                        <div class="h5 mb-0"><?= number_format((float) $summary['total_kg'], 3) ?></div>
                                    </div></div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="card h-100"><div class="card-body py-3">
                                        <div class="small text-muted">Total pcs</div>
                                        <div class="h5 mb-0"><?= number_format((float) $summary['total_pcs']) ?></div>
                                    </div></div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="card h-100"><div class="card-body py-3">
                                        <div class="small text-muted">Market value (PhP)</div>
                                        <div class="h5 mb-0"><?= number_format((float) $summary['total_market_value'], 2) ?></div>
                                    </div></div>
                                </div>
                            </div>
                            <div class="text-end mb-4 d-print-none"><a class="btn btn-sm btn-outline-primary d-print-none" href="<?= $escape($searchUrl($monthSearchFilters)) ?>">View records for this month</a></div>

                            <h3 class="h6">Totals per species</h3>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-striped table-hover align-middle">
                                    <thead><tr><th scope="col">Species</th><th class="text-end" scope="col">Total kg</th><th class="text-end" scope="col">Total pcs</th><th class="text-end" scope="col">Market value (PhP)</th></tr></thead>
                                    <tbody>
                                        <?php 
                                        foreach ($speciesRows as $speciesRow): ?>
                                          
                                            <?php $speciesSearchUrl = $searchUrl($monthSearchFilters + ['species_name' => $speciesRow['species_name']]); ?>
                                            <tr>
                                                <td><?= $escape($speciesRow['species_name']) ?> <a class="small d-print-none" href="<?= $escape($speciesSearchUrl) ?>">View records</a></td>
                                                <td class="text-end"><?= number_format((float) $speciesRow['total_kg'], 3) ?></td>
                                                <td class="text-end"><?= number_format((float) $speciesRow['total_pcs']) ?></td>
                                                <td class="text-end"><?= number_format((float) $speciesRow['total_market_value'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr class="fw-bold"><th scope="row">Total</th><td class="text-end"><?= number_format((float) $summary['total_kg'], 3) ?></td><td class="text-end"><?= number_format((float) $summary['total_pcs']) ?></td><td class="text-end"><?= number_format((float) $summary['total_market_value'], 2) ?></td></tr></tfoot>
                                </table>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-12">
                                    <div class="dashboard-chart">
                                        <h3 class="h6">Kilograms per species</h3>
                                        <div class="dashboard-chart-canvas"><canvas id="<?= $section['chartPrefix'] ?>-kg-chart" aria-label="Kilograms per species bar graph" role="img"></canvas></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="dashboard-chart">
                                        <h3 class="h6">Market value per species (PhP)</h3>
                                        <div class="dashboard-chart-canvas"><canvas id="<?= $section['chartPrefix'] ?>-value-chart" aria-label="Market value per species bar graph" role="img"></canvas></div>
                                    </div>
                                </div>
                            </div>

                            <h3 class="h6"><?= $escape($section['locationTitle']) ?></h3>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-striped table-hover align-middle">
                                    <thead><tr><th scope="col">Municipality/City</th><th scope="col">Province</th><th class="text-end" scope="col">Shipments</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($locationRows as $locationRow): ?>
                                            <?php $placeSearchUrl = $searchUrl($monthSearchFilters + ['place' => $locationRow['municipality']]); ?>
                                            <tr>
                                                <td><?= $escape($locationRow['municipality']) ?> <a class="small d-print-none" href="<?= $escape($placeSearchUrl) ?>">View records</a></td>
                                                <td><?= $escape($locationRow['province']) ?></td>
                                                <td class="text-end"><?= number_format((int) $locationRow['permit_count']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr class="fw-bold"><th scope="row" colspan="2">Total shipments</th><td class="text-end"><?= number_format(array_sum(array_map(static fn ($row) => (int) $row['permit_count'], $locationRows))) ?></td></tr></tfoot>
                                </table>
                            </div>
                            <div class="dashboard-chart">
                                <h3 class="h6">Shipment count by <?= strtolower($section['locationKind']) ?></h3>
                                <div class="dashboard-chart-canvas"><canvas id="<?= $section['chartPrefix'] ?>-location-chart" aria-label="Shipments per <?= $escape(strtolower($section['locationKind'])) ?> bar graph" role="img"></canvas></div>
                            </div>
                        </div>
                    </section>
                </div>
                <script type="application/json" id="<?= $section['chartPrefix'] ?>-chart-data"><?= json_encode([
                    'speciesLabels' => $labels,
                    'direction' => $direction,
                    'dateFrom' => $monthStart,
                    'dateTo' => $monthEnd,
                    'kg' => array_map('floatval', array_column($speciesRows, 'total_kg')),
                    'marketValue' => array_map('floatval', array_column($speciesRows, 'total_market_value')),
                    'locationLabels' => array_map(static fn ($row) => $row['municipality'] . ', ' . $row['province'], $locationRows),
                    'locationPlaces' => array_column($locationRows, 'municipality'),
                    'shipments' => array_map('intval', array_column($locationRows, 'permit_count')),
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($chartJsAvailable): ?>
<script src="chartjs/chart.umd.min.js"></script>
<?php endif; ?>
<?php if ($databaseError === null && $chartJsAvailable): ?>
<script>
    // Build each chart from its embedded JSON and send chart clicks to matching records.
    document.querySelectorAll('[id$="-chart-data"]').forEach((dataElement) => {
        const data = JSON.parse(dataElement.textContent);
        const prefix = dataElement.id.replace('-chart-data', '');
        const chartOptions = {
            responsive: true,
            animation: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        };
        const openSpeciesRecords = (_event, activeElements) => {
            const index = activeElements[0]?.index;
            if (index === undefined) return;
            const parameters = new URLSearchParams({
                type: data.direction,
                date_from: data.dateFrom,
                date_to: data.dateTo,
                species_name: data.speciesLabels[index]
            });
            window.location.href = `search.php?${parameters}`;
        };

        new Chart(document.getElementById(`${prefix}-kg-chart`), {
            type: 'bar',
            data: { labels: data.speciesLabels, datasets: [{ label: 'Kilograms', data: data.kg, backgroundColor: '#333333', borderColor: '#111111', borderWidth: 1 }] },
            options: { ...chartOptions, onClick: openSpeciesRecords }
        });
        new Chart(document.getElementById(`${prefix}-value-chart`), {
            type: 'bar',
            data: { labels: data.speciesLabels, datasets: [{ label: 'Market value (PhP)', data: data.marketValue, backgroundColor: '#777777', borderColor: '#333333', borderWidth: 1 }] },
            options: { ...chartOptions, onClick: openSpeciesRecords }
        });
        new Chart(document.getElementById(`${prefix}-location-chart`), {
            type: 'bar',
            data: { labels: data.locationLabels, datasets: [{ label: 'Shipments', data: data.shipments, backgroundColor: '#bbbbbb', borderColor: '#666666', borderWidth: 1 }] },
            options: {
                ...chartOptions,
                onClick: (_event, activeElements) => {
                    const index = activeElements[0]?.index;
                    if (index === undefined) return;
                    const parameters = new URLSearchParams({
                        type: data.direction,
                        date_from: data.dateFrom,
                        date_to: data.dateTo,
                        place: data.locationPlaces[index]
                    });
                    window.location.href = `search.php?${parameters}`;
                }
            }
        });
    });
</script>
<?php endif; ?>

<?php
require __DIR__ . '/footer.php';
