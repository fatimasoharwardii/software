<?php
$page_identifier = 'reports/production_report.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['stitching_posted_bills', 'jobs'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Initialize filters
$job_no_filter = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$min_qty = isset($_GET['min_qty']) && $_GET['min_qty'] !== '' ? (float)$_GET['min_qty'] : null;
$max_qty = isset($_GET['max_qty']) && $_GET['max_qty'] !== '' ? (float)$_GET['max_qty'] : null;
$min_cost = isset($_GET['min_cost']) && $_GET['min_cost'] !== '' ? (float)$_GET['min_cost'] : null;
$max_cost = isset($_GET['max_cost']) && $_GET['max_cost'] !== '' ? (float)$_GET['max_cost'] : null;

// Build WHERE conditions for main query
$where = ["spb.claim_type = 'Production Bill'", "spb.status = 'posted'", "j.status = 'Close'", "spb.company_id = ?", "j.company_id = ?"];
$params = [$company_id, $company_id];
$types = "ii";

if (!empty($job_no_filter)) {
    $where[] = "spb.job_no LIKE ?";
    $params[] = "%$job_no_filter%";
    $types .= "s";
}
if (!empty($date_from)) {
    $where[] = "DATE(spb.claim_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if (!empty($date_to)) {
    $where[] = "DATE(spb.claim_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}
if ($min_qty !== null) {
    $where[] = "spb.qty >= ?";
    $params[] = $min_qty;
    $types .= "d";
}
if ($max_qty !== null) {
    $where[] = "spb.qty <= ?";
    $params[] = $max_qty;
    $types .= "d";
}
if ($min_cost !== null) {
    $where[] = "spb.total_amount >= ?";
    $params[] = $min_cost;
    $types .= "d";
}
if ($max_cost !== null) {
    $where[] = "spb.total_amount <= ?";
    $params[] = $max_cost;
    $types .= "d";
}

$where_clause = implode(" AND ", $where);
$sql = "SELECT 
            spb.job_no,
            spb.qty as total_qty,
            spb.total_amount as total_cost,
            1 as total_entries,
            spb.claim_date as first_entry,
            spb.claim_date as last_entry
        FROM stitching_posted_bills spb
        INNER JOIN jobs j ON spb.job_no = j.job_no
        WHERE $where_clause
        ORDER BY spb.total_amount DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Totals query (same conditions)
$total_sql = "SELECT 
                COUNT(spb.job_no) as total_jobs,
                SUM(spb.qty) as grand_qty,
                SUM(spb.total_amount) as grand_cost,
                AVG(spb.total_amount / spb.qty) as avg_cost_per_unit
              FROM stitching_posted_bills spb
              INNER JOIN jobs j ON spb.job_no = j.job_no
              WHERE $where_clause";
$total_stmt = $conn->prepare($total_sql);
$total_stmt->bind_param($types, ...$params);
$total_stmt->execute();
$totals = $total_stmt->get_result()->fetch_assoc();
if (!$totals) {
    $totals = ['total_jobs' => 0, 'grand_qty' => 0, 'grand_cost' => 0, 'avg_cost_per_unit' => 0];
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Production Report (Closed & Production Bill Posted)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Professional Elegant Theme (unchanged) */
    :root {
        --primary: #F39C12;
        --primary-dark: #E67E22;
        --primary-light: #FEF5E7;
        --gray-100: #f8f9fa;
        --gray-200: #e9ecef;
        --gray-700: #495057;
        --dark: #2C3E50;
        --border-radius: 12px;
        --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
        --shadow-md: 0 4px 12px rgba(0,0,0,0.06);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: #f0f2f5;
        font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.9rem;
        color: var(--dark);
        line-height: 1.5;
    }

    .container {
        margin-left: 14%;
        width: 85%;
        max-width: 1400px;
        padding: 1.5rem;
    }

    h2 {
        font-size: 1.6rem;
        font-weight: 700;
        margin-bottom: 1.2rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-left: 4px solid var(--primary);
        padding-left: 1rem;
    }
    h2 i { color: var(--primary); }

    .filter-card {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.2rem 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow-md);
        border: 1px solid rgba(0,0,0,0.03);
    }
    .filter-card h4 {
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--primary-dark);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .filter-item {
        flex: 1;
        min-width: 120px;
    }
    .filter-item label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        margin-bottom: 4px;
        color: #6c757d;
        text-transform: uppercase;
    }
    .filter-item input, .filter-item select {
        width: 100%;
        padding: 0.5rem 0.8rem;
        border: 1px solid var(--gray-200);
        border-radius: 10px;
        font-size: 0.85rem;
        background: white;
        transition: 0.2s;
    }
    .filter-item input:focus, .filter-item select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .btn {
        padding: 0.5rem 1.2rem;
        font-size: 0.8rem;
        font-weight: 600;
        border-radius: 30px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
    }
    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: 0 2px 6px rgba(243,156,18,0.2);
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(243,156,18,0.3);
    }
    .btn-secondary {
        background: var(--gray-200);
        color: var(--dark);
    }
    .btn-secondary:hover {
        background: #dee2e6;
        transform: translateY(-1px);
    }

    .summary-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.8rem;
    }
    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 1rem 1.2rem;
        flex: 1;
        min-width: 160px;
        box-shadow: var(--shadow-sm);
        border: 1px solid rgba(0,0,0,0.03);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    .summary-card .label {
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #6c757d;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .summary-card .label i { color: var(--primary); }
    .summary-card .value {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--dark);
        line-height: 1.2;
    }
    .summary-card .unit { font-size: 0.7rem; color: #6c757d; margin-left: 2px; }

    .table-container {
        background: white;
        border-radius: 16px;
        padding: 0;
        margin-bottom: 1.8rem;
        box-shadow: var(--shadow-sm);
        overflow-x: auto;
        border: 1px solid rgba(0,0,0,0.03);
    }
    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        margin: 0;
    }
    .table th {
        background: var(--gray-100);
        color: #2c3e50;
        padding: 0.9rem 0.8rem;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border-bottom: 2px solid var(--primary);
    }
    .table td {
        padding: 0.7rem 0.8rem;
        border-bottom: 1px solid var(--gray-200);
        vertical-align: middle;
    }
    .table tbody tr {
        transition: background 0.15s;
    }
    .table tbody tr:hover {
        background: var(--primary-light);
        cursor: pointer;
    }

    .text-end { text-align: right; }
    .job-no {
        font-weight: 700;
        color: var(--primary);
    }
    .cost::before {
        content: 'Rs. ';
        font-size: 0.7rem;
        color: #6c757d;
        margin-right: 2px;
    }

    .empty-state {
        text-align: center;
        padding: 2rem;
        color: #6c757d;
    }
    .empty-state i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        opacity: 0.5;
    }

    @media (max-width: 1200px) {
        .container { margin-left: 10%; width: 90%; }
    }
    @media (max-width: 768px) {
        .container { margin-left: 0; width: 100%; padding: 1rem; }
        .filter-item { min-width: 100%; }
        .filter-buttons { width: 100%; display: flex; gap: 8px; }
        .filter-buttons .btn { flex: 1; justify-content: center; }
        .summary-card .value { font-size: 1rem; }
    }
</style>
</head>
<body>

<?php include("../../includes/navbar.php"); ?>

<div class="container">
    <h2><i class="fas fa-chart-line"></i> Production Report <small style="font-size: 0.8rem; color: #6c757d;">(Only Close Jobs with Posted Production Bill)</small></h2>

    <!-- Filter Card -->
    <div class="filter-card">
        <h4><i class="fas fa-filter"></i> Filter Production Data</h4>
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-item"><label><i class="fas fa-hashtag"></i> Job Number</label><input type="text" name="job_no" value="<?= htmlspecialchars($job_no_filter) ?>" placeholder="Job number"></div>
                <div class="filter-item"><label><i class="fas fa-calendar"></i> From Date</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="filter-item"><label><i class="fas fa-calendar"></i> To Date</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
                <div class="filter-item"><label><i class="fas fa-cubes"></i> Min Quantity</label><input type="number" name="min_qty" value="<?= htmlspecialchars($min_qty) ?>" placeholder="Min qty"></div>
                <div class="filter-item"><label><i class="fas fa-cubes"></i> Max Quantity</label><input type="number" name="max_qty" value="<?= htmlspecialchars($max_qty) ?>" placeholder="Max qty"></div>
                <div class="filter-item"><label><i class="fas fa-rupee-sign"></i> Min Cost</label><input type="number" step="0.01" name="min_cost" value="<?= htmlspecialchars($min_cost) ?>" placeholder="Min cost"></div>
                <div class="filter-item"><label><i class="fas fa-rupee-sign"></i> Max Cost</label><input type="number" step="0.01" name="max_cost" value="<?= htmlspecialchars($max_cost) ?>" placeholder="Max cost"></div>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                <a href="production_report.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="summary-row">
        <div class="summary-card"><div class="label"><i class="fas fa-briefcase"></i> Completed Jobs</div><div class="value"><?= number_format($totals['total_jobs'] ?? 0) ?></div></div>
        <div class="summary-card"><div class="label"><i class="fas fa-cubes"></i> Total Quantity</div><div class="value"><?= number_format($totals['grand_qty'] ?? 0) ?><span class="unit">pcs</span></div></div>
        <div class="summary-card"><div class="label"><i class="fas fa-rupee-sign"></i> Total Cost</div><div class="value">Rs. <?= number_format($totals['grand_cost'] ?? 0, 2) ?></div></div>
        <div class="summary-card"><div class="label"><i class="fas fa-chart-bar"></i> Avg Cost/Unit</div><div class="value">Rs. <?= number_format($totals['avg_cost_per_unit'] ?? 0, 2) ?></div></div>
    </div>

    <!-- Production Table -->
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Job No</th>
                    <th class="text-end">Quantity (pcs)</th>
                    <th class="text-end">Total Cost (Rs)</th>
                    <th class="text-end">Cost/Unit (Rs)</th>
                    <th>Billing Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): 
                        $cost_per_unit = $row['total_qty'] > 0 ? $row['total_cost'] / $row['total_qty'] : 0;
                    ?>
                    <tr>
                        <td><span class="job-no"><?= htmlspecialchars($row['job_no']) ?></span></td>
                        <td class="text-end"><?= number_format($row['total_qty']) ?> </td>
                        <td class="text-end cost"><?= number_format($row['total_cost'], 2) ?></td>
                        <td class="text-end cost"><?= number_format($cost_per_unit, 2) ?></td>
                        <td><?= date('d-m-Y', strtotime($row['first_entry'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <br>No completed jobs (Close status & Production Bill posted) found
                            <br><a href="stitching_bill.php" class="btn btn-primary btn-sm mt-2">Add Production Entry</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>