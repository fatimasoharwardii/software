<?php
$page_identifier = 'embroidery/report.php';
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
$tables = ['embroidery_entries', 'machines'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Get date range filters (safe – will be used with prepared statements)
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$display_from = date('d M Y', strtotime($date_from));
$display_to = date('d M Y', strtotime($date_to));
$duration_days = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24) + 1;
$current_month = date('Y-m', strtotime($date_to));
$previous_month = date('Y-m', strtotime('-1 month', strtotime($date_to)));

// Prepare main query with company isolation and date range using prepared statements
$main_sql = "SELECT 
    m.id,
    m.machine_no,
    COALESCE(SUM(CASE WHEN e.shift = 'day' THEN e.stitch_done ELSE 0 END), 0) as day_stitches,
    COALESCE(SUM(CASE WHEN e.shift = 'night' THEN e.stitch_done ELSE 0 END), 0) as night_stitches,
    COALESCE(SUM(e.stitch_done), 0) as total_stitches,
    COUNT(DISTINCT DATE(e.entry_date)) as working_days,
    ROUND(COALESCE(SUM(e.stitch_done) / NULLIF(COUNT(DISTINCT DATE(e.entry_date)), 0), 0), 0) as daily_avg,
    COALESCE((
        SELECT AVG(stitch_done) 
        FROM embroidery_entries 
        WHERE machine_id = m.id 
        AND DATE_FORMAT(entry_date, '%Y-%m') = ?
        AND company_id = ?
    ), 0) as current_month_avg,
    COALESCE((
        SELECT AVG(stitch_done) 
        FROM embroidery_entries 
        WHERE machine_id = m.id 
        AND DATE_FORMAT(entry_date, '%Y-%m') = ?
        AND company_id = ?
    ), 0) as previous_month_avg
    FROM machines m
    LEFT JOIN embroidery_entries e ON m.id = e.machine_id 
        AND DATE(e.entry_date) BETWEEN ? AND ?
        AND e.company_id = ?
    WHERE m.company_id = ?
    GROUP BY m.id, m.machine_no
    ORDER BY total_stitches DESC";

$stmt = $conn->prepare($main_sql);
$stmt->bind_param("sissssii", 
    $current_month, $company_id, 
    $previous_month, $company_id,
    $date_from, $date_to, $company_id,
    $company_id
);
$stmt->execute();
$result = $stmt->get_result();

// Overall totals using prepared statements
$overall_sql = "SELECT 
    COALESCE(SUM(CASE WHEN shift = 'day' THEN stitch_done ELSE 0 END), 0) as total_day,
    COALESCE(SUM(CASE WHEN shift = 'night' THEN stitch_done ELSE 0 END), 0) as total_night,
    COALESCE(SUM(stitch_done), 0) as total_all,
    COUNT(DISTINCT DATE(entry_date)) as total_days,
    COUNT(DISTINCT machine_id) as active_machines
    FROM embroidery_entries 
    WHERE DATE(entry_date) BETWEEN ? AND ?
    AND company_id = ?";

$overall_stmt = $conn->prepare($overall_sql);
$overall_stmt->bind_param("ssi", $date_from, $date_to, $company_id);
$overall_stmt->execute();
$overall = $overall_stmt->get_result()->fetch_assoc();
if (!$overall) {
    $overall = [
        'total_day' => 0,
        'total_night' => 0,
        'total_all' => 0,
        'total_days' => 1,
        'active_machines' => 0
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Machine-wise Embroidery Report</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original) */
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #B26000;
        --light-bg: #F9F9F9;
        --border: #E0E0E0;
        --text-dark: #2C3E50;
        --success: #28a745;
        --danger: #e74c3c;
        --info: #17a2b8;
        --card-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: var(--light-bg);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        color: var(--text-dark);
        min-height: 100vh;
    }

    .main-container {
        margin-left: 14%;
        padding: 24px 32px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
    }

    .page-header {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 20px 25px;
        margin-bottom: 24px;
        box-shadow: var(--card-shadow);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-header h4 {
        color: var(--primary);
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 1.5rem;
    }

    .filter-section {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: var(--card-shadow);
    }

    .filter-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary);
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: flex-end;
    }

    .filter-item {
        flex: 1;
        min-width: 180px;
    }

    .filter-item label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    .filter-item label i {
        color: var(--primary);
        width: 18px;
    }

    .filter-item input, .filter-item select {
        width: 100%;
        padding: 8px 12px;
        border: 2px solid var(--border);
        border-radius: 6px;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    .filter-item input:focus, .filter-item select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .filter-buttons {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .btn {
        padding: 8px 16px;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #e9ecef;
        color: var(--text-dark);
    }

    .btn-secondary:hover {
        background: #dee2e6;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        border-left: 4px solid var(--primary);
    }

    .stat-card h6 {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #6b7b8b;
        margin-bottom: 10px;
    }

    .stat-card .stat-number {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--primary);
    }

    .stat-card .stat-sub {
        font-size: 0.75rem;
        color: #6b7b8b;
        margin-top: 5px;
    }

    .stat-card .date-range {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary-dark);
        margin-bottom: 5px;
    }

    .stat-card .duration-bold {
        font-weight: 700;
        color: var(--primary);
        font-size: 1.2rem;
    }

    .content-card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: var(--card-shadow);
        margin-bottom: 24px;
    }

    .card-header {
        background: white;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .card-header h5 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
        font-size: 0.85rem;
    }

    .table thead th {
        background: #f8f9fa;
        padding: 12px 10px;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--primary);
        white-space: nowrap;
        text-align: center;
    }

    .table tbody td {
        padding: 12px 10px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
        text-align: center;
    }

    .table tbody tr:hover {
        background: var(--primary-light);
    }

    .machine-no {
        font-weight: 700;
        color: var(--primary);
        text-align: left !important;
    }

    .day-stitch {
        color: var(--primary);
        font-weight: 600;
    }

    .night-stitch {
        color: var(--info);
        font-weight: 600;
    }

    .trend-up {
        color: var(--success);
    }

    .trend-down {
        color: var(--danger);
    }

    .badge-day {
        background: #FFE4B5;
        color: #B45F06;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 0.7rem;
    }

    .badge-night {
        background: #E8ECF0;
        color: #5D6D7E;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 0.7rem;
    }

    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; }
    }

    @media (max-width: 900px) {
        .main-container { margin-left: 0; padding: 16px; }
        .stats-grid { grid-template-columns: 1fr; }
        .table { font-size: 0.75rem; }
        .filter-grid { flex-direction: column; }
        .filter-item { width: 100%; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h4>
            <i class="fas fa-chart-bar"></i> Machine-wise Embroidery Report
        </h4>
        <div class="action-buttons">
            <a href="machine_report_print.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>" target="_blank" class="btn-print" style="background: #dc3545; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fas fa-print"></i> Print / PDF
            </a>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-title">
            <i class="fas fa-filter"></i> Select Date Range
        </div>
        <form method="GET" id="dateRangeForm">
            <div class="filter-grid">
                <div class="filter-item">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-chart-line"></i> Quick Select</label>
                    <select id="quick_select" class="form-select">
                        <option value="">Select Range</option>
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="last7">Last 7 Days</option>
                        <option value="last30">Last 30 Days</option>
                        <option value="this_month">This Month</option>
                        <option value="last_month">Last Month</option>
                    </select>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="machine_report.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <h6><i class="fas fa-tshirt"></i> Total Stitches</h6>
            <div class="stat-number"><?= number_format($overall['total_all'] ?? 0) ?></div>
            <div class="stat-sub">Day: <?= number_format($overall['total_day'] ?? 0) ?> | Night: <?= number_format($overall['total_night'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <h6><i class="fas fa-chart-line"></i> Daily Average</h6>
            <div class="stat-number"><?= number_format(($overall['total_all'] ?? 0) / max(($overall['total_days'] ?? 1), 1), 0) ?></div>
            <div class="stat-sub">Per day average</div>
        </div>
        <div class="stat-card">
            <h6><i class="fas fa-industry"></i> Active Machines</h6>
            <div class="stat-number"><?= number_format($overall['active_machines'] ?? 0) ?></div>
            <div class="stat-sub">Machines worked</div>
        </div>
        <div class="stat-card">
            <h6><i class="fas fa-calendar-alt"></i> Date Range</h6>
            <div class="date-range"><?= $display_from ?> to <?= $display_to ?></div>
            <div class="duration-bold"><?= $duration_days ?> Days</div>
            <div class="stat-sub">Selected period</div>
        </div>
    </div>

    <!-- Machine-wise Table with Monthly Averages -->
    <div class="content-card">
        <div class="card-header">
            <h5>
                <i class="fas fa-industry"></i> Machine-wise Production
            </h5>
            <small class="text-muted"><?= $display_from ?> to <?= $display_to ?></small>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th rowspan="2">Machine No</th>
                        <th colspan="2">Selected Date Range</th>
                        <th colspan="2">Monthly Averages</th>
                        <th rowspan="2">Trend</th>
                    </tr>
                    <tr style="background: #f5f5f5;">
                        <th>Day Shift</th>
                        <th>Night Shift</th>
                        <th><?= date('M Y', strtotime($date_to)) ?></th>
                        <th><?= date('M Y', strtotime('-1 month', strtotime($date_to))) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_day_all = 0;
                    $total_night_all = 0;
                    
                    if($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $total_day_all += $row['day_stitches'];
                            $total_night_all += $row['night_stitches'];
                            $avg_trend = $row['current_month_avg'] - $row['previous_month_avg'];
                            $trend_percent = $row['previous_month_avg'] > 0 ? ($avg_trend / $row['previous_month_avg']) * 100 : 0;
                    ?>
                    <tr>
                        <td class="machine-no">
                            <i class="fas fa-industry"></i> <?= htmlspecialchars($row['machine_no']) ?>
                        </td>
                        <td class="day-stitch">
                            <span class="badge-day"><i class="fas fa-sun"></i></span><br>
                            <?= number_format($row['day_stitches']) ?>
                        </td>
                        <td class="night-stitch">
                            <span class="badge-night"><i class="fas fa-moon"></i></span><br>
                            <?= number_format($row['night_stitches']) ?>
                        </td>
                        <td><strong><?= number_format($row['current_month_avg'], 0) ?></strong></td>
                        <td><strong><?= number_format($row['previous_month_avg'], 0) ?></strong></td>
                        <td class="<?= $avg_trend >= 0 ? 'trend-up' : 'trend-down' ?>">
                            <?= $avg_trend >= 0 ? '↑' : '↓' ?> <?= number_format(abs($trend_percent), 0) ?>%
                        </td>
                    </tr>
                    <?php 
                        }
                    } else {
                    ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <i class="fas fa-chart-line fa-2x text-muted mb-2"></i>
                            <p>No data found for selected date range</p>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
                <?php if($result && $result->num_rows > 0): ?>
                <tfoot style="background: #f8f9fa; font-weight: 600;">
                    <tr>
                        <td class="text-end"><strong>TOTAL:</strong></td>
                        <td class="day-stitch"><strong><?= number_format($total_day_all) ?></strong></td>
                        <td class="night-stitch"><strong><?= number_format($total_night_all) ?></strong></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script>
// Quick select functionality (unchanged)
document.getElementById('quick_select').addEventListener('change', function() {
    const value = this.value;
    const today = new Date();
    let fromDate = new Date();
    let toDate = new Date();
    
    switch(value) {
        case 'today':
            fromDate = today;
            toDate = today;
            break;
        case 'yesterday':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 1);
            toDate = fromDate;
            break;
        case 'last7':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 6);
            toDate = today;
            break;
        case 'last30':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 29);
            toDate = today;
            break;
        case 'this_month':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
            toDate = today;
            break;
        case 'last_month':
            fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            toDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        default:
            return;
    }
    
    document.querySelector('input[name="date_from"]').value = formatDate(fromDate);
    document.querySelector('input[name="date_to"]').value = formatDate(toDate);
    document.getElementById('dateRangeForm').submit();
});

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>