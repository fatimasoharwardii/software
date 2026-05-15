<?php
$page_identifier = 'salary/helper_salary.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id']; // admin ka user_id

// Ensure required tables have company_id column
$tables = ['salaries', 'embroidery_entries', 'accounts'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$message = '';
$month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$helper_name = isset($_GET['helper']) ? trim($_GET['helper']) : '';
$view_salary_id = isset($_GET['view_salary']) ? intval($_GET['view_salary']) : 0;

// Helper suggestions (embroidery_entries + accounts)
$helper_list = [];
$helper_query = $conn->query("
    SELECT DISTINCT helper_name 
    FROM embroidery_entries 
    WHERE helper_name IS NOT NULL AND helper_name != '' AND company_id = $company_id 
    UNION 
    SELECT DISTINCT account_name 
    FROM accounts 
    WHERE account_type = 'employee' AND account_name IS NOT NULL AND account_name != '' AND company_id = $company_id 
    ORDER BY helper_name ASC
");
while ($h = $helper_query->fetch_assoc()) {
    $helper_list[] = $h['helper_name'];
}

// Check if salary already exists
$existing_salary = null;
if ($helper_name && $month) {
    $check_salary = $conn->prepare("SELECT * FROM salaries WHERE party_name = ? AND month = ? AND role_type = 'helper' AND company_id = ?");
    $check_salary->bind_param("ssi", $helper_name, $month, $company_id);
    $check_salary->execute();
    $existing_result = $check_salary->get_result();
    if ($existing_result->num_rows > 0) {
        $existing_salary = $existing_result->fetch_assoc();
    }
    $check_salary->close();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_salary'])) {
    $helper_name = trim($_POST['helper_name'] ?? '');
    $month = trim($_POST['month'] ?? '');
    $basic_salary = floatval($_POST['basic_salary'] ?? 0);
    $attendance_bonus = floatval($_POST['attendance_bonus'] ?? 0);
    $double_duty_bonus = floatval($_POST['double_duty_bonus'] ?? 0);

    // Double-check existence
    $check_query = $conn->prepare("SELECT id FROM salaries WHERE party_name = ? AND month = ? AND role_type = 'helper' AND company_id = ?");
    $check_query->bind_param("ssi", $helper_name, $month, $company_id);
    $check_query->execute();
    $check_result = $check_query->get_result();

    if ($check_result->num_rows > 0) {
        $existing = $check_result->fetch_assoc();
        $message = '<div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> 
            <strong>Salary already exists!</strong><br>
            Salary for ' . htmlspecialchars($helper_name) . ' for month ' . date('M Y', strtotime($month)) . ' has already been calculated.<br>
            <a href="?helper=' . urlencode($helper_name) . '&month=' . $month . '&view_salary=' . $existing['id'] . '" class="btn btn-sm btn-primary mt-2">View Existing Salary</a>
        </div>';
    } else {
        $start_date = date('Y-m-01', strtotime($month));
        $end_date = date('Y-m-t', strtotime($month));

        // Embroidery entries for this helper
        $entries_stmt = $conn->prepare("SELECT 
            DATE(entry_date) as date,
            DAYOFWEEK(entry_date) as day_of_week,
            COUNT(DISTINCT machine_id) as machine_count,
            SUM(stitch_done) as total_stitches
            FROM embroidery_entries 
            WHERE helper_name = ? 
            AND entry_date BETWEEN ? AND ?
            AND company_id = ?
            GROUP BY DATE(entry_date)
            ORDER BY entry_date");
        $entries_stmt->bind_param("sssi", $helper_name, $start_date, $end_date, $company_id);
        $entries_stmt->execute();
        $entries_result = $entries_stmt->get_result();

        $total_days = 0;
        $double_duty_days = 0;
        $total_stitches = 0;
        $sunday_count = 0;
        while ($row = $entries_result->fetch_assoc()) {
            $total_days++;
            $total_stitches += $row['total_stitches'];
            if ($row['machine_count'] > 1) $double_duty_days++;
            if ($row['day_of_week'] == 1) $sunday_count++;
        }
        $entries_stmt->close();

        $days_in_month = date('t', strtotime($month));
        $per_day_rate = $basic_salary / $days_in_month;
        $base_salary_calculated = $per_day_rate * $total_days;
        $total_salary = $base_salary_calculated + $attendance_bonus + $double_duty_bonus;
        $attendance_percentage = ($total_days / $days_in_month) * 100;

        $conn->begin_transaction();
        try {
            // Insert into salaries (with user_id)
            $insert_salary = $conn->prepare("INSERT INTO salaries 
                (party_name, role_type, month, total_stitches, total_days, sunday_count, 
                 base_salary, total_salary, status, attendance_percentage, 
                 attendance_bonus, double_duty_bonus, created_at, company_id, user_id) 
                VALUES (?, 'helper', ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), ?, ?)");
            $insert_salary->bind_param("ssiiidddiiii", 
                $helper_name, $month, $total_stitches, $total_days, $sunday_count,
                $base_salary_calculated, $total_salary, $attendance_percentage,
                $attendance_bonus, $double_duty_bonus, $company_id, $user_id);
            if (!$insert_salary->execute()) {
                throw new Exception("Error saving salary: " . $insert_salary->error);
            }
            $salary_id = $insert_salary->insert_id;
            $insert_salary->close();

            // Bill entry in stitching_posted_bills (with user_id)
            $serial_no = 'SAL-H-' . date('Ymd') . '-' . str_pad($salary_id, 4, '0', STR_PAD_LEFT);
            $claim_date = date('Y-m-d');
            $description = "Helper Salary for $helper_name - Month: " . date('M Y', strtotime($month));
            $job_no = "SALARY-H-$salary_id";

            $columns_check = $conn->query("SHOW COLUMNS FROM stitching_posted_bills");
            $existing_columns = [];
            while ($col = $columns_check->fetch_assoc()) {
                $existing_columns[] = $col['Field'];
            }

            $bill_fields = [];
            $bill_values = [];
            $bill_types = "";

            // Required columns
            $bill_fields[] = "job_no"; $bill_values[] = $job_no; $bill_types .= "s";
            $bill_fields[] = "serial_no"; $bill_values[] = $serial_no; $bill_types .= "s";
            $bill_fields[] = "emp_name"; $bill_values[] = $helper_name; $bill_types .= "s";
            $bill_fields[] = "claim_item"; $bill_values[] = "Helper Salary"; $bill_types .= "s";
            $bill_fields[] = "qty"; $bill_values[] = 1; $bill_types .= "i";
            $bill_fields[] = "rate"; $bill_values[] = $total_salary; $bill_types .= "d";
            $bill_fields[] = "total_amount"; $bill_values[] = $total_salary; $bill_types .= "d";
            $bill_fields[] = "description"; $bill_values[] = $description; $bill_types .= "s";
            $bill_fields[] = "status"; $bill_values[] = "pending"; $bill_types .= "s";
            $bill_fields[] = "company_id"; $bill_values[] = $company_id; $bill_types .= "i";
            $bill_fields[] = "user_id"; $bill_values[] = $user_id; $bill_types .= "i"; // Fix foreign key

            if (in_array('claim_type', $existing_columns)) {
                $bill_fields[] = "claim_type"; $bill_values[] = "helper_salary"; $bill_types .= "s";
            }
            if (in_array('claim_date', $existing_columns)) {
                $bill_fields[] = "claim_date"; $bill_values[] = $claim_date; $bill_types .= "s";
            }
            if (in_array('created_at', $existing_columns)) {
                $bill_fields[] = "created_at"; $bill_values[] = date('Y-m-d H:i:s'); $bill_types .= "s";
            }

            $insert_bill_sql = "INSERT INTO stitching_posted_bills (" . implode(", ", $bill_fields) . ") VALUES (" . implode(", ", array_fill(0, count($bill_fields), "?")) . ")";
            $insert_bill = $conn->prepare($insert_bill_sql);
            $insert_bill->bind_param($bill_types, ...$bill_values);
            if (!$insert_bill->execute()) {
                throw new Exception("Error inserting bill: " . $insert_bill->error);
            }
            $insert_bill->close();

            $conn->commit();
            $message = '<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <strong>Salary saved successfully!</strong><br>
                Helper: ' . htmlspecialchars($helper_name) . '<br>
                Month: ' . date('M Y', strtotime($month)) . '<br>
                Total Salary: Rs ' . number_format($total_salary, 2) . '<br>
                Bill Serial No: ' . $serial_no . '
            </div>';

            header("Location: ?helper=" . urlencode($helper_name) . "&month=" . $month);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <strong>Error:</strong> ' . $e->getMessage() . '
            </div>';
        }
    }
}

// Display data
$daily_data = [];
$total_days = 0;
$double_duty_days = 0;
$total_stitches = 0;
$sunday_count = 0;
$base_salary_calculated = 0;
$total_salary = 0;
$days_in_month = 0;
$attendance_percentage = 0;
$attendance_bonus = 0;
$double_duty_bonus = 0;

if ($view_salary_id > 0) {
    $view_stmt = $conn->prepare("SELECT * FROM salaries WHERE id = ? AND role_type = 'helper' AND company_id = ?");
    $view_stmt->bind_param("ii", $view_salary_id, $company_id);
    $view_stmt->execute();
    $view_result = $view_stmt->get_result();
    $view_salary = $view_result->fetch_assoc();
    $view_stmt->close();

    if ($view_salary) {
        $helper_name = $view_salary['party_name'];
        $month = $view_salary['month'];
        $total_days = $view_salary['total_days'];
        $total_stitches = $view_salary['total_stitches'];
        $sunday_count = $view_salary['sunday_count'];
        $base_salary_calculated = $view_salary['base_salary'];
        $total_salary = $view_salary['total_salary'];
        $attendance_percentage = $view_salary['attendance_percentage'];
        $attendance_bonus = $view_salary['attendance_bonus'] ?? 0;
        $double_duty_bonus = $view_salary['double_duty_bonus'] ?? 0;
        $days_in_month = date('t', strtotime($month));
        $existing_salary = $view_salary;
    }
} elseif ($helper_name && $month && !$existing_salary) {
    $start_date = date('Y-m-01', strtotime($month));
    $end_date = date('Y-m-t', strtotime($month));
    $days_in_month = date('t', strtotime($month));

    $entries_stmt = $conn->prepare("SELECT 
        DATE(entry_date) as date,
        DAYOFWEEK(entry_date) as day_of_week,
        DAYNAME(entry_date) as day_name,
        COUNT(DISTINCT machine_id) as machine_count,
        GROUP_CONCAT(DISTINCT machine_no) as machines,
        SUM(stitch_done) as total_stitches
        FROM embroidery_entries 
        WHERE helper_name = ? 
        AND entry_date BETWEEN ? AND ?
        AND company_id = ?
        GROUP BY DATE(entry_date)
        ORDER BY entry_date");
    $entries_stmt->bind_param("sssi", $helper_name, $start_date, $end_date, $company_id);
    $entries_stmt->execute();
    $entries_result = $entries_stmt->get_result();

    while ($row = $entries_result->fetch_assoc()) {
        $daily_data[] = $row;
        $total_days++;
        $total_stitches += $row['total_stitches'];
        if ($row['machine_count'] > 1) $double_duty_days++;
        if ($row['day_of_week'] == 1) $sunday_count++;
    }
    $entries_stmt->close();

    $base_salary = 25000;
    $per_day_rate = $base_salary / $days_in_month;
    $base_salary_calculated = $per_day_rate * $total_days;
    $attendance_percentage = ($total_days / $days_in_month) * 100;
    $total_salary = $base_salary_calculated;
}

// Recent records
$history_stmt = $conn->prepare("SELECT * FROM salaries WHERE role_type = 'helper' AND company_id = ? ORDER BY id DESC LIMIT 10");
$history_stmt->bind_param("i", $company_id);
$history_stmt->execute();
$history_query = $history_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Helper Salary</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
        --info: #3498db;
        --bg-light: #F8F9FA;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .main-container {
        margin-left: 14%;
        padding: 20px 24px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }
    .page-header {
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .page-header h2 {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .page-header h2 i { color: var(--primary); font-size: 1.4rem; }
    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.85rem; }
    .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
    .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
    .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid var(--warning); }
    .alert-info { background: #d1ecf1; color: #0c5460; border-left: 4px solid var(--info); }
    .search-card {
        background: white; border: 1px solid var(--border); border-radius: 12px;
        padding: 16px; margin-bottom: 20px;
    }
    .search-title {
        font-size: 0.85rem; font-weight: 700; color: var(--primary-dark);
        margin-bottom: 12px; display: flex; align-items: center; gap: 6px;
    }
    .form-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .form-group { flex: 1; min-width: 180px; }
    .form-label {
        display: block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
        color: var(--text-muted); margin-bottom: 4px;
    }
    .form-label i { color: var(--primary); width: 14px; }
    .form-control { width: 100%; padding: 8px 12px; font-size: 1rem; border: 1px solid var(--border); border-radius: 8px; }
    .btn { padding: 8px 18px; font-size: 0.9rem; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-success { background: var(--success); color: white; }
    .btn-sm { padding: 4px 10px; font-size: 0.75rem; }
    .summary-row { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .summary-item {
        background: white; border: 1px solid var(--border); border-radius: 10px;
        padding: 10px 14px; flex: 1; min-width: 110px;
    }
    .summary-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; }
    .summary-value { font-size: 1.1rem; font-weight: 700; color: var(--primary-dark); }
    .table-container { background: white; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 20px; }
    .table-header { padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--bg-light); font-size: 0.85rem; font-weight: 600; }
    .table-responsive { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 800px; }
    .table th { background: var(--bg-light); padding: 10px 12px; border-bottom: 2px solid var(--primary); font-weight: 600; text-align: left; }
    .table td { padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: left; }
    .total-row { background: var(--primary-light); font-weight: 700; }
    .sunday-row td:first-child { border-left: 3px solid var(--danger); }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
    .bonus-section { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 20px; }
    .bonus-grid { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
    .bonus-item { flex: 1; min-width: 140px; }
    .bonus-item label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 4px; }
    .bonus-item input { width: 100%; padding: 8px 12px; font-size: 1rem; border: 1px solid var(--border); border-radius: 6px; }
    .bonus-item input:focus { border-color: var(--primary); outline: none; }
    .bonus-item small { font-size: 0.65rem; color: var(--text-muted); display: block; margin-top: 3px; }
    .total-box { background: var(--primary); color: white; padding: 14px 18px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; font-size: 1rem; }
    .total-box .value { font-size: 1.5rem; font-weight: 700; }
    .existing-salary-card { background: var(--primary-light); border: 1px solid var(--primary); border-radius: 12px; padding: 16px; margin-bottom: 20px; }
    @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .form-row { flex-direction: column; } .form-group { width: 100%; } .bonus-grid { flex-direction: column; } .summary-row { flex-direction: column; } }
</style>

<datalist id="helperList">
    <?php foreach ($helper_list as $hl): ?>
    <option value="<?= htmlspecialchars($hl) ?>">
    <?php endforeach; ?>
</datalist>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-user-friends"></i> Helper Salary</h2>
        <?php if ($existing_salary && !$view_salary_id): ?>
        <a href="?helper=<?= urlencode($helper_name) ?>&month=<?= $month ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Calculation
        </a>
        <?php endif; ?>
    </div>

    <?= $message ?>

    <!-- Search Section -->
    <div class="search-card">
        <div class="search-title"><i class="fas fa-search"></i> Select Helper & Month</div>
        <form method="GET" class="form-row">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-user"></i> Helper Name</label>
                <input type="text" name="helper" class="form-control" list="helperList" value="<?= htmlspecialchars($helper_name) ?>" required autocomplete="off" placeholder="Type or select helper">
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-calendar"></i> Month</label>
                <input type="month" name="month" class="form-control" value="<?= $month ?>" required>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary"><i class="fas fa-calculator"></i> Calculate</button>
            </div>
        </form>
    </div>

    <?php if ($existing_salary && !$view_salary_id): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Salary already exists!</strong><br>
        Salary for <?= htmlspecialchars($helper_name) ?> for month <?= date('M Y', strtotime($month)) ?> has already been calculated.<br>
        <a href="?helper=<?= urlencode($helper_name) ?>&month=<?= $month ?>&view_salary=<?= $existing_salary['id'] ?>" class="btn btn-primary btn-sm mt-2">
            <i class="fas fa-eye"></i> View Existing Salary
        </a>
        <!-- Remove "Create New" to prevent accidental duplicate; just view option -->
    </div>
    <?php endif; ?>

    <?php if (($helper_name && $month && !empty($daily_data)) || ($view_salary_id && $view_salary)): ?>
    <!-- Existing Salary Card -->
    <?php if ($view_salary_id && $view_salary): ?>
    <div class="existing-salary-card">
        <div class="search-title"><i class="fas fa-history"></i> Existing Salary Record</div>
        <div class="summary-row" style="margin-bottom: 0;">
            <div class="summary-item">
                <div class="summary-label">Created Date</div>
                <div class="summary-value"><?= date('d-m-Y', strtotime($view_salary['created_at'])) ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Status</div>
                <div class="summary-value">
                    <?php if ($view_salary['status'] == 'posted'): ?>
                        <span class="badge" style="background:#d4edda; color:#155724;">Posted</span>
                    <?php else: ?>
                        <span class="badge" style="background:#fff3cd; color:#856404;">Pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Row -->
    <div class="summary-row">
        <div class="summary-item">
            <div class="summary-label">Days Worked</div>
            <div class="summary-value"><?= $total_days ?> / <?= $days_in_month ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Total Stitches</div>
            <div class="summary-value"><?= number_format($total_stitches) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Sundays</div>
            <div class="summary-value"><?= $sunday_count ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Base Salary</div>
            <div class="summary-value">Rs <?= number_format($base_salary_calculated, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Attendance</div>
            <div class="summary-value"><?= round($attendance_percentage, 1) ?>%</div>
        </div>
    </div>

    <?php if (!empty($daily_data) && !$view_salary_id): ?>
    <div class="table-container">
        <div class="table-header"><i class="fas fa-calendar-week"></i> Daily Breakdown</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Date</th><th>Day</th><th>Machines</th><th>Stitches</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_data as $day): ?>
                    <tr class="<?= $day['day_of_week'] == 1 ? 'sunday-row' : '' ?>">
                        <td><?= date('d M Y', strtotime($day['date'])) ?></td>
                        <td><?= $day['day_name'] ?></td>
                        <td><?= $day['machine_count'] ?> (<?= $day['machines'] ?>)</td>
                        <td><?= number_format($day['total_stitches']) ?></td>
                        <td>
                            <?php if ($day['machine_count'] > 1): ?><span class="badge" style="background:var(--warning); color:#856404;">Double Duty</span><?php endif; ?>
                            <?php if ($day['day_of_week'] == 1): ?><span class="badge" style="background:var(--danger); color:white;">Sunday</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$view_salary_id): ?>
    <form method="POST" id="salaryForm">
        <input type="hidden" name="helper_name" value="<?= htmlspecialchars($helper_name) ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <div class="bonus-section">
            <div class="search-title"><i class="fas fa-gift"></i> Bonuses & Allowances</div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Basic Monthly Salary (Rs.)</label>
                    <input type="number" step="100" min="0" name="basic_salary" id="basic_salary" class="form-control" value="25000" oninput="calculateSalary()" required>
                    <small>Enter full monthly salary</small>
                </div>
            </div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Double Duty Bonus</label>
                    <input type="number" step="100" name="double_duty_bonus" id="double_duty" class="form-control" value="0" oninput="calculateSalary()">
                    <small><?= $double_duty_days ?> days on 2+ machines</small>
                </div>
                <div class="bonus-item">
                    <label>Attendance Bonus</label>
                    <input type="number" step="100" name="attendance_bonus" id="attendance_bonus" class="form-control" value="0" oninput="calculateSalary()">
                    <small><?= $total_days ?>/<?= $days_in_month ?> days (<?= round($attendance_percentage,1) ?>%)</small>
                </div>
            </div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Per Day Rate</label>
                    <input type="text" id="per_day" class="form-control" readonly value="0">
                </div>
                <div class="bonus-item">
                    <label>Calculated Base Salary</label>
                    <input type="text" id="base_calc" class="form-control" readonly value="0">
                </div>
            </div>
            <div class="total-box">
                <span><i class="fas fa-calculator"></i> Total Salary</span>
                <span class="value" id="totalDisplay">Rs. 0</span>
            </div>
            <div style="margin-top: 16px; text-align: right;">
                <button type="submit" name="save_salary" class="btn btn-success">
                    <i class="fas fa-save"></i> Save Salary & Create Bill
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php elseif ($helper_name && $month && !$existing_salary && !$daily_data): ?>
    <div class="alert alert-info">No embroidery entries found for this helper in the selected month.</div>
    <?php endif; ?>

    <!-- Recent Records -->
    <?php if ($history_query && $history_query->num_rows > 0): ?>
    <div class="table-container">
        <div class="table-header"><i class="fas fa-history"></i> Recent Helper Salary Records</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Helper</th><th>Month</th><th>Days</th><th>Stitches</th><th>Base Salary</th><th>Total</th><th>Created Date</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while ($record = $history_query->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['party_name']) ?></td>
                        <td><?= date('M Y', strtotime($record['month'] . '-01')) ?></td>
                        <td><?= $record['total_days'] ?> / <?= date('t', strtotime($record['month'])) ?></td>
                        <td><?= number_format($record['total_stitches'] ?? 0) ?></td>
                        <td>Rs <?= number_format($record['base_salary'] ?? 0, 2) ?></td>
                        <td><strong>Rs <?= number_format($record['total_salary'], 2) ?></strong></td>
                        <td><?= date('d-m-Y', strtotime($record['created_at'])) ?></td>
                        <td>
                            <?php if ($record['status'] == 'posted'): ?>
                                <span class="badge" style="background:#d4edda; color:#155724;">Posted</span>
                            <?php else: ?>
                                <span class="badge" style="background:#fff3cd; color:#856404;">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?helper=<?= urlencode($record['party_name']) ?>&month=<?= $record['month'] ?>&view_salary=<?= $record['id'] ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const daysInMonth = <?= $days_in_month ?>;
const workedDays = <?= $total_days ?>;
function calculateSalary() {
    const basic = parseFloat(document.getElementById('basic_salary').value) || 0;
    const perDay = basic / daysInMonth;
    const baseCalc = perDay * workedDays;
    document.getElementById('per_day').value = 'Rs. ' + perDay.toFixed(2);
    document.getElementById('base_calc').value = 'Rs. ' + baseCalc.toFixed(2);
    const attendance = parseFloat(document.getElementById('attendance_bonus').value) || 0;
    const doubleDuty = parseFloat(document.getElementById('double_duty').value) || 0;
    const total = baseCalc + attendance + doubleDuty;
    document.getElementById('totalDisplay').textContent = 'Rs. ' + total.toFixed(2);
}
document.addEventListener('DOMContentLoaded', calculateSalary);
</script>
</body>
</html>