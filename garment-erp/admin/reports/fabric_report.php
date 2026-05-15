<?php
$page_identifier = 'reports/fabric_report.php';
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
$tables = ['fabric_purchase', 'fabric_issue', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Initialize filters
$fabric_name_filter = isset($_GET['fabric_name']) ? trim($_GET['fabric_name']) : '';
$lot_no_filter = isset($_GET['lot_no']) ? trim($_GET['lot_no']) : '';
$min_rate = isset($_GET['min_rate']) ? floatval($_GET['min_rate']) : '';
$max_rate = isset($_GET['max_rate']) ? floatval($_GET['max_rate']) : '';
$job_no_filter = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// ----------------------------------------------
// Purchase query (with company filter)
// ----------------------------------------------
$purchase_where = ["company_id = ?"];
$purchase_params = [$company_id];
$purchase_types = "i";
if (!empty($fabric_name_filter)) {
    $purchase_where[] = "fabric_name LIKE ?";
    $purchase_params[] = "%$fabric_name_filter%";
    $purchase_types .= "s";
}
if (!empty($lot_no_filter)) {
    $purchase_where[] = "lot_no = ?";
    $purchase_params[] = $lot_no_filter;
    $purchase_types .= "s";
}
if ($min_rate !== '') {
    $purchase_where[] = "rate >= ?";
    $purchase_params[] = $min_rate;
    $purchase_types .= "d";
}
if ($max_rate !== '') {
    $purchase_where[] = "rate <= ?";
    $purchase_params[] = $max_rate;
    $purchase_types .= "d";
}
if (!empty($date_from)) {
    $purchase_where[] = "DATE(created_at) >= ?";
    $purchase_params[] = $date_from;
    $purchase_types .= "s";
}
if (!empty($date_to)) {
    $purchase_where[] = "DATE(created_at) <= ?";
    $purchase_params[] = $date_to;
    $purchase_types .= "s";
}
$purchase_sql = "SELECT * FROM fabric_purchase WHERE " . implode(" AND ", $purchase_where) . " ORDER BY id DESC";
$purchase_stmt = $conn->prepare($purchase_sql);
$purchase_stmt->bind_param($purchase_types, ...$purchase_params);
$purchase_stmt->execute();
$purchase = $purchase_stmt->get_result();

// Purchase totals
$purchase_total_sql = "SELECT COUNT(*) as total_records, SUM(total_meter) as total_meter, SUM(total_meter * rate) as total_value FROM fabric_purchase WHERE " . implode(" AND ", $purchase_where);
$purchase_total_stmt = $conn->prepare($purchase_total_sql);
$purchase_total_stmt->bind_param($purchase_types, ...$purchase_params);
$purchase_total_stmt->execute();
$purchase_totals = $purchase_total_stmt->get_result()->fetch_assoc();

// ----------------------------------------------
// Issue query (with company filter)
// ----------------------------------------------
$issue_where = ["company_id = ?"];
$issue_params = [$company_id];
$issue_types = "i";
if (!empty($fabric_name_filter)) {
    $issue_where[] = "fabric_name LIKE ?";
    $issue_params[] = "%$fabric_name_filter%";
    $issue_types .= "s";
}
if (!empty($job_no_filter)) {
    $issue_where[] = "job_no = ?";
    $issue_params[] = $job_no_filter;
    $issue_types .= "s";
}
if (!empty($date_from)) {
    $issue_where[] = "DATE(created_at) >= ?";
    $issue_params[] = $date_from;
    $issue_types .= "s";
}
if (!empty($date_to)) {
    $issue_where[] = "DATE(created_at) <= ?";
    $issue_params[] = $date_to;
    $issue_types .= "s";
}
$issue_sql = "SELECT * FROM fabric_issue WHERE " . implode(" AND ", $issue_where) . " ORDER BY id DESC";
$issue_stmt = $conn->prepare($issue_sql);
$issue_stmt->bind_param($issue_types, ...$issue_params);
$issue_stmt->execute();
$issue = $issue_stmt->get_result();

// Issue totals
$issue_total_sql = "SELECT COUNT(*) as total_records, SUM(emb_issue + back_issue + extra_issue) as total_issue FROM fabric_issue WHERE " . implode(" AND ", $issue_where);
$issue_total_stmt = $conn->prepare($issue_total_sql);
$issue_total_stmt->bind_param($issue_types, ...$issue_params);
$issue_total_stmt->execute();
$issue_totals = $issue_total_stmt->get_result()->fetch_assoc();

// ----------------------------------------------
// Sale query (stitching_posted_bills with claim_type='fabric_sale')
// ----------------------------------------------
$sale_where = ["spb.claim_type = 'fabric_sale'", "spb.company_id = ?"];
$sale_params = [$company_id];
$sale_types = "i";
if (!empty($fabric_name_filter)) {
    $sale_where[] = "(spb.description LIKE ? OR spb.fabric_name LIKE ?)";
    $like = "%$fabric_name_filter%";
    $sale_params[] = $like;
    $sale_params[] = $like;
    $sale_types .= "ss";
}
if (!empty($date_from)) {
    $sale_where[] = "DATE(spb.claim_date) >= ?";
    $sale_params[] = $date_from;
    $sale_types .= "s";
}
if (!empty($date_to)) {
    $sale_where[] = "DATE(spb.claim_date) <= ?";
    $sale_params[] = $date_to;
    $sale_types .= "s";
}
$sale_sql = "SELECT spb.*, fp.fabric_name 
             FROM stitching_posted_bills spb 
             LEFT JOIN fabric_purchase fp ON spb.description LIKE CONCAT('%', fp.fabric_name, '%') AND fp.company_id = spb.company_id
             WHERE " . implode(" AND ", $sale_where) . "
             ORDER BY spb.id DESC";
$sale_stmt = $conn->prepare($sale_sql);
$sale_stmt->bind_param($sale_types, ...$sale_params);
$sale_stmt->execute();
$sale_result = $sale_stmt->get_result();

// Sale totals
$sale_total_where = ["claim_type = 'fabric_sale'", "company_id = ?"];
$sale_total_params = [$company_id];
$sale_total_types = "i";
if (!empty($fabric_name_filter)) {
    $sale_total_where[] = "description LIKE ?";
    $sale_total_params[] = "%$fabric_name_filter%";
    $sale_total_types .= "s";
}
if (!empty($date_from)) {
    $sale_total_where[] = "DATE(claim_date) >= ?";
    $sale_total_params[] = $date_from;
    $sale_total_types .= "s";
}
if (!empty($date_to)) {
    $sale_total_where[] = "DATE(claim_date) <= ?";
    $sale_total_params[] = $date_to;
    $sale_total_types .= "s";
}
$sale_total_sql = "SELECT COUNT(*) as total_records, SUM(total_amount) as total_amount, SUM(qty) as total_qty 
                   FROM stitching_posted_bills 
                   WHERE " . implode(" AND ", $sale_total_where);
$sale_total_stmt = $conn->prepare($sale_total_sql);
$sale_total_stmt->bind_param($sale_total_types, ...$sale_total_params);
$sale_total_stmt->execute();
$sale_totals = $sale_total_stmt->get_result()->fetch_assoc();

// ----------------------------------------------
// Dropdown data (lots & jobs) – company filtered
// ----------------------------------------------
$lots_stmt = $conn->prepare("SELECT DISTINCT lot_no FROM fabric_purchase WHERE company_id = ? ORDER BY lot_no");
$lots_stmt->bind_param("i", $company_id);
$lots_stmt->execute();
$lots = $lots_stmt->get_result();

$jobs_stmt = $conn->prepare("SELECT DISTINCT job_no FROM fabric_issue WHERE company_id = ? ORDER BY job_no");
$jobs_stmt->bind_param("i", $company_id);
$jobs_stmt->execute();
$jobs = $jobs_stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Fabric Report</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original) */
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

    h3 {
        font-size: 1.2rem;
        font-weight: 600;
        margin: 1.5rem 0 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--dark);
        border-bottom: 2px solid var(--primary);
        padding-bottom: 0.4rem;
        display: inline-block;
    }
    h3 i { color: var(--primary); }

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
        min-width: 160px;
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
        min-width: 140px;
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
    .meter::after { content: ' m'; font-size: 0.7rem; color: #6c757d; margin-left: 2px; }
    .amount::before { content: 'Rs. '; font-size: 0.7rem; color: #6c757d; margin-right: 2px; }

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
        h3 { font-size: 1rem; }
    }
</style>
</head>
<body>

<?php include("../../includes/navbar.php"); ?>

<div class="container">
    <h2><i class="fas fa-chart-line"></i> Fabric Report</h2>

    <!-- Filter Card -->
    <div class="filter-card">
        <h4><i class="fas fa-filter"></i> Filters</h4>
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-item"><label><i class="fas fa-tshirt"></i> Fabric Name</label><input type="text" name="fabric_name" value="<?= htmlspecialchars($fabric_name_filter) ?>" placeholder="Fabric name"></div>
                <div class="filter-item"><label><i class="fas fa-layer-group"></i> Lot No</label><select name="lot_no"><option value="">All Lots</option><?php while($lot = $lots->fetch_assoc()): ?><option value="<?= htmlspecialchars($lot['lot_no']) ?>" <?= $lot_no_filter == $lot['lot_no'] ? 'selected' : '' ?>><?= htmlspecialchars($lot['lot_no']) ?></option><?php endwhile; ?></select></div>
                <div class="filter-item"><label><i class="fas fa-hashtag"></i> Job No</label><select name="job_no"><option value="">All Jobs</option><?php while($job = $jobs->fetch_assoc()): ?><option value="<?= htmlspecialchars($job['job_no']) ?>" <?= $job_no_filter == $job['job_no'] ? 'selected' : '' ?>><?= htmlspecialchars($job['job_no']) ?></option><?php endwhile; ?></select></div>
                <div class="filter-item"><label><i class="fas fa-tag"></i> Min Rate</label><input type="number" step="0.01" name="min_rate" value="<?= htmlspecialchars($min_rate) ?>" placeholder="Min rate"></div>
                <div class="filter-item"><label><i class="fas fa-tag"></i> Max Rate</label><input type="number" step="0.01" name="max_rate" value="<?= htmlspecialchars($max_rate) ?>" placeholder="Max rate"></div>
                <div class="filter-item"><label><i class="fas fa-calendar"></i> From Date</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="filter-item"><label><i class="fas fa-calendar"></i> To Date</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                <a href="fabric_report.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>

    <!-- Purchase Table -->
    <h3><i class="fas fa-shopping-cart"></i> Fabric Purchase</h3>
    <div class="table-container">
        <table class="table">
            <thead><tr><th>Lot No</th><th>Fabric Name</th><th>Color</th><th class="text-end">Total Meter</th><th class="text-end">Rate</th><th class="text-end">Total Value</th><th>Date</th></tr></thead>
            <tbody>
                <?php if ($purchase->num_rows > 0): while ($row = $purchase->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['lot_no']) ?></strong></td>
                    <td><?= htmlspecialchars($row['fabric_name']) ?></td>
                    <td><?= htmlspecialchars($row['color'] ?? '-') ?></td>
                    <td class="text-end meter"><?= number_format($row['total_meter'], 2) ?></td>
                    <td class="text-end amount"><?= number_format($row['rate'], 2) ?></td>
                    <td class="text-end amount"><?= number_format($row['total_meter'] * $row['rate'], 2) ?></td>
                    <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="7" class="empty-state"><i class="fas fa-box-open"></i><br>No purchase records</td><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Issue Table -->
    <h3><i class="fas fa-truck"></i> Fabric Issue</h3>
    <div class="table-container">
        <table class="table">
            <thead><tr><th>Job No</th><th>Fabric Name</th><th>Color</th><th class="text-end">Emb (m)</th><th class="text-end">Back (m)</th><th class="text-end">Extra (m)</th><th class="text-end">Total (m)</th><th>Date</th></tr></thead>
            <tbody>
                <?php if ($issue->num_rows > 0): while ($row = $issue->fetch_assoc()): $total = $row['emb_issue'] + $row['back_issue'] + $row['extra_issue']; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['job_no']) ?></strong></td>
                    <td><?= htmlspecialchars($row['fabric_name']) ?></td>
                    <td><?= htmlspecialchars($row['color'] ?? '-') ?></td>
                    <td class="text-end meter"><?= number_format($row['emb_issue'], 2) ?></td>
                    <td class="text-end meter"><?= number_format($row['back_issue'], 2) ?></td>
                    <td class="text-end meter"><?= number_format($row['extra_issue'], 2) ?></td>
                    <td class="text-end meter"><strong><?= number_format($total, 2) ?></strong></td>
                    <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" class="empty-state"><i class="fas fa-boxes"></i><br>No issue records</td><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Fabric Sale Table -->
    <h3><i class="fas fa-chart-line"></i> Fabric Sale (Posted Bills)</h3>
    <div class="table-container">
        <table class="table">
            <thead><tr><th>Bill No</th><th>Fabric Name</th><th class="text-end">Qty (m)</th><th class="text-end">Rate</th><th class="text-end">Amount</th><th>Date</th><th>Customer</th></tr></thead>
            <tbody>
                <?php if ($sale_result && $sale_result->num_rows > 0): while ($sale = $sale_result->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($sale['bill_no'] ?? 'N/A') ?></strong></td>
                    <td><?= htmlspecialchars($sale['fabric_name'] ?? explode(' ', $sale['description'])[0] ?? 'Fabric') ?></td>
                    <td class="text-end meter"><?= number_format($sale['qty'], 2) ?></td>
                    <td class="text-end amount"><?= number_format($sale['rate'], 2) ?></td>
                    <td class="text-end amount"><?= number_format($sale['total_amount'], 2) ?></td>
                    <td><?= date('d-m-Y', strtotime($sale['claim_date'])) ?></td>
                    <td><?= htmlspecialchars($sale['emp_name'] ?? '-') ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="7" class="empty-state"><i class="fas fa-chart-line"></i><br>No sale records</td><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>