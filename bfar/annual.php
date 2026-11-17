<?php
// Annual species data is aggregated from the same shipment views as the monthly dashboard.
require_once __DIR__ . '/db.php';

$pageTitle = 'Annual Species Search';
$currentPage = 'dashboard';
$currentYear = (int) date('Y');
$minimumYear = 2000;
$maximumYear = $currentYear + 1;
$defaultStartYear = max($minimumYear, $currentYear - 4);

$yearFrom = isset($_GET['year_from']) && ctype_digit((string) $_GET['year_from'])
    ? (int) $_GET['year_from']
    : $defaultStartYear;
$yearTo = isset($_GET['year_to']) && ctype_digit((string) $_GET['year_to'])
    ? (int) $_GET['year_to']
    : $currentYear;
$yearFrom = ($yearFrom >= $minimumYear && $yearFrom <= $maximumYear) ? $yearFrom : $defaultStartYear;
$yearTo = ($yearTo >= $minimumYear && $yearTo <= $maximumYear) ? $yearTo : $currentYear;
$rangeError = null;
if ($yearFrom > $yearTo) {
    [$yearFrom, $yearTo] = [$yearTo, $yearFrom];
    $rangeError = 'The year range was reordered so the start year comes first.';
}

function loadAnnualSpeciesData(PDO $pdo, string $viewName, int $yearFrom, int $yearTo): array
{
    $query = $pdo->prepare(
        'SELECT yr AS shipment_year, mo AS shipment_month,
                COALESCE(english_name, \'Unknown species\') AS species_name,
                COALESCE(SUM(volume_kg), 0) AS total_kg,
                COALESCE(SUM(market_value), 0) AS total_market_value
         FROM ' . $viewName . '
         WHERE yr BETWEEN :year_from AND :year_to
         GROUP BY yr, mo, english_name
         ORDER BY shipment_year, shipment_month, species_name'
    );
    $query->execute(['year_from' => $yearFrom, 'year_to' => $yearTo]);

    $speciesData = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $period = sprintf('%04d-%02d', (int) $row['shipment_year'], (int) $row['shipment_month']);
        $speciesData[$row['species_name']][$period] = [
            'kg' => (float) $row['total_kg'],
            'marketValue' => (float) $row['total_market_value'],
        ];
    }

    return $speciesData;
}

$periods = [];
$periodDate = new DateTimeImmutable(sprintf('%04d-01-01', $yearFrom));
$lastPeriodDate = new DateTimeImmutable(sprintf('%04d-12-01', $yearTo));
while ($periodDate <= $lastPeriodDate) {
    $periods[] = [
        'value' => $periodDate->format('Y-m'),
        'label' => $periodDate->format('M Y'),
    ];
    $periodDate = $periodDate->modify('+1 month');
}
$periodKeys = array_column($periods, 'value');
$annualData = [];
$databaseError = null;
try {
    $pdo = getDatabaseConnection();
    $annualData = [
        'outgoing' => loadAnnualSpeciesData($pdo, 'v_outgoing_lines', $yearFrom, $yearTo),
        'incoming' => loadAnnualSpeciesData($pdo, 'v_incoming_lines', $yearFrom, $yearTo),
    ];
} catch (PDOException $exception) {
    $databaseError = 'Annual species data is unavailable. Start MySQL in XAMPP and import the latest database/bfar_exim.sql file.';
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$chartJsAvailable = is_file(__DIR__ . '/chartjs/chart.umd.min.js');
$sections = [
    'outgoing' => ['title' => 'OUT-GOING COMMODITIES', 'headerClass' => 'bg-primary'],
    'incoming' => ['title' => 'IN-COMING COMMODITIES', 'headerClass' => 'bg-success'],
];

require __DIR__ . '/header.php';
?>
<div class="container py-4">
    <h1 class="h2 mb-4">Annual Species Search</h1>
    <section class="card mb-4 d-print-none" aria-label="Annual reporting period">
        <div class="card-body">
            <form class="row g-3 align-items-end" method="get" action="annual.php">
                <div class="col-12 col-sm-5 col-lg-3">
                    <label class="form-label" for="year_from">From year</label>
                    <select class="form-select" id="year_from" name="year_from">
                        <?php for ($optionYear = $maximumYear; $optionYear >= $minimumYear; $optionYear--): ?>
                            <option value="<?= $optionYear ?>" <?= $yearFrom === $optionYear ? 'selected' : '' ?>><?= $optionYear ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-5 col-lg-3">
                    <label class="form-label" for="year_to">To year</label>
                    <select class="form-select" id="year_to" name="year_to">
                        <?php for ($optionYear = $maximumYear; $optionYear >= $minimumYear; $optionYear--): ?>
                            <option value="<?= $optionYear ?>" <?= $yearTo === $optionYear ? 'selected' : '' ?>><?= $optionYear ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-auto d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Show</button>
                    <a class="btn btn-secondary" href="index.php">Monthly Dashboard</a>
                </div>
            </form>
        </div>
    </section>

    <?php if ($rangeError !== null): ?>
        <div class="alert alert-info" role="status"><?= $escape($rangeError) ?></div>
    <?php endif; ?>
    <p class="text-muted">Choose a species to view its monthly totals from <?= $yearFrom ?> to <?= $yearTo ?>. Select a point on a chart to view matching records.</p>

    <?php if ($databaseError !== null): ?>
        <div class="alert alert-warning" role="alert"><?= $escape($databaseError) ?></div>
    <?php else: ?>
        <?php if (!$chartJsAvailable): ?>
            <div class="alert alert-warning" role="alert">Graphs are unavailable. Add the local Chart.js bundle at chartjs/chart.umd.min.js.</div>
        <?php endif; ?>
        <div class="row g-4">
            <?php foreach ($sections as $direction => $section): ?>
                <?php
                $speciesRows = $annualData[$direction];
                $speciesNames = array_keys($speciesRows);
                $chartData = [
                    'periods' => $periods,
                    'direction' => $direction,
                    'species' => [],
                ];
                foreach ($speciesRows as $speciesName => $yearlyTotals) {
                    $chartData['species'][] = [
                        'name' => $speciesName,
                        'kg' => array_map(static fn ($period) => $yearlyTotals[$period]['kg'] ?? 0, $periodKeys),
                        'marketValue' => array_map(static fn ($period) => $yearlyTotals[$period]['marketValue'] ?? 0, $periodKeys),
                    ];
                }
                ?>
                <div class="col-12 dashboard-column">
                    <section class="card dashboard-section" aria-label="<?= $escape($section['title']) ?>">
                        <div class="card-header text-white <?= $section['headerClass'] ?>">
                            <h2 class="h5 mb-0"><?= $escape($section['title']) ?></h2>
                        </div>
                        <div class="card-body">
                            <?php if ($speciesNames === []): ?>
                                <div class="alert alert-info" role="status">No records for this year range.</div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label" for="<?= $direction ?>-species">Species to show</label>
                                <select class="form-select" id="<?= $direction ?>-species" <?= $speciesNames === [] ? 'disabled' : '' ?>>
                                    <?php foreach ($speciesNames as $speciesIndex => $speciesName): ?>
                                        <option value="<?= $speciesIndex ?>"><?= $escape($speciesName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="dashboard-chart mb-4">
                                <h3 class="h6">Kilograms by month</h3>
                                <div class="dashboard-chart-canvas">
                                    <canvas id="<?= $direction ?>-annual-kg-chart" aria-label="Monthly kilograms line graph" role="img"></canvas>
                                </div>
                            </div>
                            <div class="dashboard-chart">
                                <h3 class="h6">Market value (PhP) by month</h3>
                                <div class="dashboard-chart-canvas">
                                    <canvas id="<?= $direction ?>-annual-value-chart" aria-label="Monthly market value line graph" role="img"></canvas>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
                <script type="application/json" id="<?= $direction ?>-annual-chart-data"><?= json_encode(
                    $chartData,
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                ) ?></script>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($chartJsAvailable): ?>
<script src="chartjs/chart.umd.min.js"></script>
<?php endif; ?>
<?php if ($databaseError === null && $chartJsAvailable): ?>
<script>
    document.querySelectorAll('[id$="-annual-chart-data"]').forEach((dataElement) => {
        const data = JSON.parse(dataElement.textContent);
        const prefix = dataElement.id.replace('-annual-chart-data', '');
        const speciesSelect = document.getElementById(`${prefix}-species`);
        if (data.species.length === 0) return;
        const kgChart = new Chart(document.getElementById(`${prefix}-annual-kg-chart`), {
            type: 'line',
            data: { labels: data.periods.map((period) => period.label), datasets: [] },
            options: {
                responsive: true,
                animation: false,
                interaction: { mode: 'nearest', intersect: true },
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
        const valueChart = new Chart(document.getElementById(`${prefix}-annual-value-chart`), {
            type: 'line',
            data: { labels: data.periods.map((period) => period.label), datasets: [] },
            options: {
                responsive: true,
                animation: false,
                interaction: { mode: 'nearest', intersect: true },
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
        const openSpeciesRecords = (_event, activeElements) => {
            const point = activeElements[0];
            if (!point) return;
            const species = data.species[Number(speciesSelect.value)];
            const period = data.periods[point.index].value;
            const [year, month] = period.split('-');
            const lastDay = new Date(Number(year), Number(month), 0).getDate();
            const parameters = new URLSearchParams({
                type: data.direction,
                date_from: `${period}-01`,
                date_to: `${period}-${String(lastDay).padStart(2, '0')}`,
                species_name: species.name
            });
            window.location.href = `search.php?${parameters}`;
        };
        kgChart.options.onClick = openSpeciesRecords;
        valueChart.options.onClick = openSpeciesRecords;
        const updateCharts = () => {
            const species = data.species[Number(speciesSelect.value)];
            kgChart.data.datasets = [{
                label: species.name,
                data: species.kg,
                borderColor: '#0d6efd',
                backgroundColor: '#0d6efd',
                tension: 0.2,
                fill: false
            }];
            valueChart.data.datasets = [{
                label: species.name,
                data: species.marketValue,
                borderColor: '#198754',
                backgroundColor: '#198754',
                tension: 0.2,
                fill: false
            }];
            kgChart.update();
            valueChart.update();
        };

        speciesSelect.addEventListener('change', updateCharts);
        updateCharts();
    });
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
