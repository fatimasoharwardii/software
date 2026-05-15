<?php
$page_identifier = 'salary/operator_salary.php';
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
$tables = ['salaries', 'embroidery_entries', 'machines', 'jobs', 'stitching_posted_bills'];
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
$operator_name = isset($_GET['operator']) ? trim($_GET['operator']) : '';
$view_salary_id = isset($_GET['view_salary']) ? intval($_GET['view_salary']) : 0;

// Fetch all operators for datalist (company filtered)
$operators_stmt = $conn->prepare("SELECT DISTINCT operator_name FROM embroidery_entries WHERE operator_name IS NOT NULL AND operator_name != '' AND company_id = ? ORDER BY operator_name");
$operators_stmt->bind_param("i", $company_id);
$operators_stmt->execute();
$operators_list = $operators_stmt->get_result();

// Check if salary already exists for this operator and month (company filtered)
$existing_salary = null;
if ($operator_name && $month) {
    $check_salary = $conn->prepare("SELECT * FROM salaries WHERE operator_name = ? AND month = ? AND company_id = ?");
    $check_salary->bind_param("ssi", $operator_name, $month, $company_id);
    $check_salary->execute();
    $existing_result = $check_salary->get_result();
    if ($existing_result->num_rows > 0) {
        $existing_salary = $existing_result->fetch_assoc();
    }
    $check_salary->close();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_salary'])) {
    $operator_name = trim($_POST['operator_name'] ?? '');
    $month = trim($_POST['month'] ?? '');
    $machine_allowance = floatval($_POST['machine_allowance'] ?? 0);
    $sunday_bonus = floatval($_POST['sunday_bonus'] ?? 0);
    $attendance_bonus = floatval($_POST['attendance_bonus'] ?? 0);
    $total_base_salary = floatval($_POST['total_base_salary'] ?? 0);
    $total_machine_bonus = floatval($_POST['total_machine_bonus'] ?? 0);
    $total_salary = floatval($_POST['total_salary'] ?? 0);

    // Check if salary already exists (company filtered)
    $check_query = $conn->prepare("SELECT id FROM salaries WHERE operator_name = ? AND month = ? AND company_id = ?");
    $check_query->bind_param("ssi", $operator_name, $month, $company_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    if ($check_result->num_rows > 0) {
        $existing_id = $check_result->fetch_assoc()['id'];
        $message = '<div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> 
            <strong>Salary already exists!</strong><br>
            Salary for ' . htmlspecialchars($operator_name) . ' for month ' . date('M Y', strtotime($month)) . ' has already been calculated.<br>
            <a href="?operator=' . urlencode($operator_name) . '&month=' . $month . '&view_salary=' . $existing_id . '" class="btn btn-sm btn-primary mt-2">View Existing Salary</a>
        </div>';
    } else {
        $start_date = date('Y-m-01', strtotime($month));
        $end_date = date('Y-m-t', strtotime($month));

        // Aggregate stats (company filtered)
        $stats_stmt = $conn->prepare("SELECT 
            SUM(stitch_done) as total_stitches,
            COUNT(DISTINCT DATE(entry_date)) as total_days,
            SUM(CASE WHEN DAYOFWEEK(entry_date) = 1 THEN stitch_done ELSE 0 END) as sunday_stitches,
            COUNT(DISTINCT CASE WHEN DAYOFWEEK(entry_date) = 1 THEN DATE(entry_date) END) as sunday_count
            FROM embroidery_entries 
            WHERE operator_name = ? AND entry_date BETWEEN ? AND ? AND company_id = ?");
        $stats_stmt->bind_param("sssi", $operator_name, $start_date, $end_date, $company_id);
        $stats_stmt->execute();
        $stats = $stats_stmt->get_result()->fetch_assoc();
        $stats_stmt->close();

        $days_in_month = date('t', strtotime($month));
        $attendance_percentage = ($stats['total_days'] / $days_in_month) * 100;

        // Get current user ID
        $user_id = (int)$_SESSION['user_id'];

        // Start transaction
        $conn->begin_transaction();
        try {
            // ---- FIXED: assign array values to local variables ----
            $total_stitches   = (int)($stats['total_stitches'] ?? 0);
            $total_days       = (int)($stats['total_days'] ?? 0);
            $sunday_stitches  = (int)($stats['sunday_stitches'] ?? 0);
            $sunday_count     = (int)($stats['sunday_count'] ?? 0);

            // Insert into salaries table (with company_id and user_id)
            $bonus = 0;
            $insert_salary = $conn->prepare("INSERT INTO salaries 
                (operator_name, month, total_stitches, total_days, sunday_stitches, sunday_count, 
                 base_salary, machine_bonus, bonus, allowance, sunday_bonus, attendance_bonus, 
                 attendance_percentage, total_salary, status, created_at, company_id, user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?)");

            // Corrected type string: ssiiiiddddddddii (16 placeholders)
            $insert_salary->bind_param("ssiiiiddddddddii",
                $operator_name,
                $month,
                $total_stitches,
                $total_days,
                $sunday_stitches,
                $sunday_count,
                $total_base_salary,
                $total_machine_bonus,
                $bonus,
                $machine_allowance,
                $sunday_bonus,
                $attendance_bonus,
                $attendance_percentage,
                $total_salary,
                $company_id,
                $user_id
            );

            if (!$insert_salary->execute()) {
                throw new Exception("Error saving salary: " . $insert_salary->error);
            }
            $salary_id = $insert_salary->insert_id;
            $insert_salary->close();

            // Generate bill entry in stitching_posted_bills (company filtered)
            $serial_no = 'SAL-' . date('Ymd') . '-' . str_pad($salary_id, 4, '0', STR_PAD_LEFT);
            $claim_date = date('Y-m-d');
            $description = "Salary for $operator_name - Month: " . date('M Y', strtotime($month));
            $job_no = "SALARY-$salary_id";

            // Check existing columns in stitching_posted_bills (optional)
            $columns_check = $conn->query("SHOW COLUMNS FROM stitching_posted_bills");
            $existing_columns = [];
            while ($col = $columns_check->fetch_assoc()) {
                $existing_columns[] = $col['Field'];
            }

            // Build dynamic bill INSERT (WITH user_id)
            $bill_fields = [];
            $bill_values = [];
            $bill_types = "";

            // Required fields
            $bill_fields[] = "job_no";       $bill_values[] = $job_no;               $bill_types .= "s";
            $bill_fields[] = "serial_no";    $bill_values[] = $serial_no;            $bill_types .= "s";
            $bill_fields[] = "emp_name";     $bill_values[] = $operator_name;        $bill_types .= "s";
            $bill_fields[] = "claim_item";   $bill_values[] = "Operator Salary";     $bill_types .= "s";
            $bill_fields[] = "qty";          $bill_values[] = 1;                     $bill_types .= "i";
            $bill_fields[] = "rate";         $bill_values[] = $total_salary;         $bill_types .= "d";
            $bill_fields[] = "total_amount"; $bill_values[] = $total_salary;         $bill_types .= "d";
            $bill_fields[] = "description";  $bill_values[] = $description;          $bill_types .= "s";
            $bill_fields[] = "status";       $bill_values[] = "pending";             $bill_types .= "s";
            $bill_fields[] = "company_id";   $bill_values[] = $company_id;           $bill_types .= "i";
            // *** ADDED user_id to fix FK constraint ***
            $bill_fields[] = "user_id";      $bill_values[] = $user_id;              $bill_types .= "i";

            // Add optional columns only if they exist
            if (in_array('claim_type', $existing_columns)) {
                $bill_fields[] = "claim_type";  $bill_values[] = "salary";           $bill_types .= "s";
            }
            if (in_array('claim_date', $existing_columns)) {
                $bill_fields[] = "claim_date";  $bill_values[] = $claim_date;        $bill_types .= "s";
            }
            if (in_array('fabric_name', $existing_columns)) {
                $bill_fields[] = "fabric_name"; $bill_values[] = '';                  $bill_types .= "s";
            }
            if (in_array('created_at', $existing_columns)) {
                $bill_fields[] = "created_at";  $bill_values[] = date('Y-m-d H:i:s');$bill_types .= "s";
            }

            $insert_bill_sql = "INSERT INTO stitching_posted_bills (" . implode(", ", $bill_fields) . ") VALUES (" . implode(", ", array_fill(0, count($bill_fields), "?")) . ")";
            $insert_bill = $conn->prepare($insert_bill_sql);

            // Create references to avoid "cannot be passed by reference" error
            $refs = [];
            foreach ($bill_values as $k => $v) {
                $refs[$k] = &$bill_values[$k];
            }
            $insert_bill->bind_param($bill_types, ...$refs);

            if (!$insert_bill->execute()) {
                throw new Exception("Error inserting bill: " . $insert_bill->error);
            }
            $insert_bill->close();

            $conn->commit();
            $message = '<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <strong>Salary saved successfully!</strong><br>
                Operator: ' . htmlspecialchars($operator_name) . '<br>
                Month: ' . date('M Y', strtotime($month)) . '<br>
                Total Salary: Rs ' . number_format($total_salary, 2) . '<br>
                Bill Serial No: ' . $serial_no . '
            </div>';

            // Redirect to clear POST and show the new salary
            header("Location: ?operator=" . urlencode($operator_name) . "&month=" . $month);
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

// Fetch data for display (new calculation or existing)
$daily_data = [];
$total_stitches = 0;
$total_days = 0;
$sunday_stitches = 0;
$sunday_count = 0;
$total_base_salary = 0;
$total_machine_bonus = 0;
$grand_total_raw = 0;
$grand_total = 0;
$days_in_month = 0;
$attendance_percentage = 0;

// If viewing existing salary
if ($view_salary_id > 0) {
    $view_stmt = $conn->prepare("SELECT * FROM salaries WHERE id = ? AND company_id = ?");
    $view_stmt->bind_param("ii", $view_salary_id, $company_id);
    $view_stmt->execute();
    $view_result = $view_stmt->get_result();
    $view_salary = $view_result->fetch_assoc();
    $view_stmt->close();

    if ($view_salary) {
        $operator_name = $view_salary['operator_name'];
        $month = $view_salary['month'];
        $total_stitches = $view_salary['total_stitches'];
        $total_days = $view_salary['total_days'];
        $sunday_stitches = $view_salary['sunday_stitches'];
        $total_base_salary = $view_salary['base_salary'];
        $total_machine_bonus = $view_salary['machine_bonus'];
        $grand_total = $view_salary['total_salary'];
        $attendance_percentage = $view_salary['attendance_percentage'];
        $days_in_month = date('t', strtotime($month));
        $existing_salary = $view_salary;
    }
} 
// New calculation
elseif ($operator_name && $month && !$existing_salary) {
    $start_date = date('Y-m-01', strtotime($month));
    $end_date = date('Y-m-t', strtotime($month));
    $days_in_month = date('t', strtotime($month));

    $entries_stmt = $conn->prepare("SELECT 
        e.id,
        e.entry_date,
        e.job_no,
        e.machine_no,
        e.shift,
        e.stitch_done,
        e.op_rate,
        e.machine_bonus,
        m.head,
        j.design_name
        FROM embroidery_entries e
        LEFT JOIN machines m ON e.machine_id = m.id AND m.company_id = e.company_id
        LEFT JOIN jobs j ON e.job_no = j.job_no AND j.company_id = e.company_id
        WHERE e.operator_name = ? 
        AND e.entry_date BETWEEN ? AND ?
        AND e.company_id = ?
        ORDER BY e.entry_date DESC, e.job_no");
    $entries_stmt->bind_param("sssi", $operator_name, $start_date, $end_date, $company_id);
    $entries_stmt->execute();
    $entries_result = $entries_stmt->get_result();

    $grouped_data = [];
    $unique_dates = [];

    while ($row = $entries_result->fetch_assoc()) {
        $date = $row['entry_date'];
        $job = $row['job_no'];
        $key = $date . '_' . $job;

        if (!isset($grouped_data[$key])) {
            $grouped_data[$key] = [
                'date' => $date,
                'job_no' => $job,
                'design_name' => $row['design_name'] ?? 'N/A',
                'day_of_week' => date('w', strtotime($date)) + 1,
                'entries' => [],
                'total_stitches' => 0,
                'total_base_salary' => 0,
                'total_machine_bonus' => 0
            ];
            $unique_dates[$date] = true;
        }

        $rate_per_1000 = $row['op_rate'] ?? 50;
        $base_salary_entry = ($row['stitch_done'] / 1000) * $rate_per_1000;
        $machine_bonus_entry = floatval($row['machine_bonus'] ?? 0);

        $grouped_data[$key]['entries'][] = [
            'machine' => $row['machine_no'],
            'shift' => $row['shift'],
            'stitch_done' => $row['stitch_done'],
            'op_rate' => $row['op_rate'],
            'base_salary' => $base_salary_entry,
            'machine_bonus' => $machine_bonus_entry
        ];

        $grouped_data[$key]['total_stitches'] += $row['stitch_done'];
        $grouped_data[$key]['total_base_salary'] += $base_salary_entry;
        $grouped_data[$key]['total_machine_bonus'] += $machine_bonus_entry;

        $total_stitches += $row['stitch_done'];
        $total_base_salary += $base_salary_entry;
        $total_machine_bonus += $machine_bonus_entry;

        if ($grouped_data[$key]['day_of_week'] == 1) {
            $sunday_stitches += $row['stitch_done'];
        }
    }
    $entries_stmt->close();

    $daily_data = $grouped_data;
    $total_days = count($unique_dates);
    $sunday_count = count(array_filter($grouped_data, function($item) { return $item['day_of_week'] == 1; }));
    $attendance_percentage = ($total_days / $days_in_month) * 100;
    $grand_total_raw = $total_base_salary + $total_machine_bonus;
    $grand_total = round($grand_total_raw / 10) * 10;
}

// Recent records (company filtered)
$history_stmt = $conn->prepare("SELECT * FROM salaries WHERE company_id = ? ORDER BY id DESC LIMIT 10");
$history_stmt->bind_param("i", $company_id);
$history_stmt->execute();
$history_query = $history_stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Operator Salary</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    .page-header h2 i {
        color: var(--primary);
        font-size: 1.4rem;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 0.85rem;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border-left: 4px solid var(--success);
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid var(--danger);
    }

    .alert-warning {
        background: #fff3cd;
        color: #856404;
        border-left: 4px solid var(--warning);
    }

    .alert-info {
        background: #d1ecf1;
        color: #0c5460;
        border-left: 4px solid var(--info);
    }

    .search-card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
    }

    .search-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .form-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .form-group {
        flex: 1;
        min-width: 180px;
    }

    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 4px;
    }

    .form-label i {
        color: var(--primary);
        width: 14px;
    }

    .form-control {
        width: 100%;
        padding: 8px 12px;
        font-size: 1rem;
        border: 1px solid var(--border);
        border-radius: 8px;
    }

    .btn {
        padding: 8px 18px;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
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
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-warning {
        background: var(--warning);
        color: white;
    }

    .btn-sm {
        padding: 4px 10px;
        font-size: 0.75rem;
    }

    .summary-row {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .summary-item {
        background: white;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px 14px;
        flex: 1;
        min-width: 110px;
    }

    .summary-label {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .summary-value {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--primary-dark);
    }

    .table-container {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 20px;
    }

    .table-header {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        background: var(--bg-light);
        font-size: 0.85rem;
        font-weight: 600;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        min-width: 800px;
    }

    .table th {
        background: var(--bg-light);
        padding: 10px 12px;
        border-bottom: 2px solid var(--primary);
        font-weight: 600;
        text-align: left;
    }

    .table td {
        padding: 8px 12px;
        border-bottom: 1px solid var(--border);
        text-align: left;
    }

    .total-row {
        background: var(--primary-light);
        font-weight: 700;
    }

    .sunday-row td:first-child {
        border-left: 3px solid var(--danger);
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .badge-bonus {
        background: #6f42c1;
        color: white;
    }

    .bonus-section {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
    }

    .bonus-grid {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin: 12px 0;
    }

    .bonus-item {
        flex: 1;
        min-width: 140px;
    }

    .bonus-item label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        display: block;
        margin-bottom: 4px;
    }

    .bonus-item input {
        width: 100%;
        padding: 8px 12px;
        font-size: 1rem;
        border: 1px solid var(--border);
        border-radius: 6px;
    }

    .bonus-item input:focus {
        border-color: var(--primary);
        outline: none;
    }

    .bonus-item small {
        font-size: 0.65rem;
        color: var(--text-muted);
        display: block;
        margin-top: 3px;
    }

    .bonus-item input[readonly] {
        background: var(--bg-light);
        font-weight: 600;
        color: var(--primary);
    }

    .total-box {
        background: var(--primary);
        color: white;
        padding: 14px 18px;
        border-radius: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 1rem;
    }

    .total-box .value {
        font-size: 1.5rem;
        font-weight: 700;
    }

    .existing-salary-card {
        background: var(--primary-light);
        border: 1px solid var(--primary);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 16px;
            margin-top: 60px;
        }
        .form-row {
            flex-direction: column;
        }
        .form-group {
            width: 100%;
        }
        .bonus-grid {
            flex-direction: column;
        }
        .summary-row {
            flex-direction: column;
        }
    }
</style>

<datalist id="operator-list">
    <?php while ($op = $operators_list->fetch_assoc()): ?>
    <option value="<?= htmlspecialchars($op['operator_name']) ?>">
    <?php endwhile; ?>
</datalist>

</head>
<body>

<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-calculator"></i> Operator Salary</h2>
        <?php if ($existing_salary && !$view_salary_id): ?>
        <a href="?operator=<?= urlencode($operator_name) ?>&month=<?= $month ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Calculation
        </a>
        <?php endif; ?>
    </div>

    <?= $message ?>

    <!-- Search Section -->
    <div class="search-card">
        <div class="search-title"><i class="fas fa-search"></i> Select Operator & Month</div>
        <form method="GET" class="form-row">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-user"></i> Operator Name</label>
                <input type="text" name="operator" class="form-control" list="operator-list" value="<?= htmlspecialchars($operator_name) ?>" required>
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
    <!-- Existing Salary Alert -->
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Salary already exists!</strong><br>
        Salary for <?= htmlspecialchars($operator_name) ?> for month <?= date('M Y', strtotime($month)) ?> has already been calculated.<br>
        <a href="?operator=<?= urlencode($operator_name) ?>&month=<?= $month ?>&view_salary=<?= $existing_salary['id'] ?>" class="btn btn-primary btn-sm mt-2">
            <i class="fas fa-eye"></i> View Existing Salary
        </a>
        <a href="?operator=<?= urlencode($operator_name) ?>&month=<?= $month ?>" class="btn btn-warning btn-sm mt-2">
            <i class="fas fa-plus"></i> Create New
        </a>
    </div>
    <?php endif; ?>

    <?php if (($operator_name && $month && !empty($daily_data)) || ($view_salary_id && $view_salary)): ?>

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
            <div class="summary-label">Total Stitches</div>
            <div class="summary-value"><?= number_format($total_stitches) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Days Worked</div>
            <div class="summary-value"><?= $total_days ?> / <?= $days_in_month ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Sunday Stitches</div>
            <div class="summary-value"><?= number_format($sunday_stitches) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Machine Bonus</div>
            <div class="summary-value">Rs <?= number_format($total_machine_bonus, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Base Salary</div>
            <div class="summary-value">Rs <?= number_format($total_base_salary, 2) ?></div>
        </div>
    </div>

    <?php if (!empty($daily_data) && !$view_salary_id): ?>
    <!-- Daily Table (Only for new calculation) -->
    <div class="table-container">
        <div class="table-header"><i class="fas fa-calendar-week"></i> Daily Breakdown</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Job No</th>
                        <th>Design</th>
                        <th>Stitches</th>
                        <th>Base</th>
                        <th>Machine Bonus</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_data as $item): 
                        $is_sunday = ($item['day_of_week'] == 1);
                    ?>
                    <tr class="<?= $is_sunday ? 'sunday-row' : '' ?>">
                        <td><?= date('d M', strtotime($item['date'])) ?><?= $is_sunday ? ' <span class="badge" style="background:#e74c3c; color:white;">Sun</span>' : '' ?></td>
                        <td><strong><?= htmlspecialchars($item['job_no']) ?></strong></td>
                        <td><?= htmlspecialchars($item['design_name']) ?></td>
                        <td><?= number_format($item['total_stitches']) ?></td>
                        <td>Rs <?= number_format($item['total_base_salary'], 2) ?></td>
                        <td><?php if ($item['total_machine_bonus'] > 0): ?><span class="badge badge-bonus">+ Rs <?= number_format($item['total_machine_bonus'], 2) ?></span><?php else: ?>-<?php endif; ?></td>
                        <td class="text-end"><strong>Rs <?= number_format($item['total_base_salary'] + $item['total_machine_bonus'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4"><strong>TOTAL</strong></td>
                        <td><strong>Rs <?= number_format($total_base_salary, 2) ?></strong></td>
                        <td><strong>Rs <?= number_format($total_machine_bonus, 2) ?></strong></td>
                        <td class="text-end"><strong>Rs <?= number_format($grand_total_raw, 2) ?></strong></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="6"><strong>ROUNDED TOTAL (Nearest 10)</strong></td>
                        <td class="text-end"><strong style="color: var(--primary); font-size: 1.1rem;">Rs <?= number_format($grand_total, 2) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bonus Section (Only for new calculation) -->
    <?php if (!$view_salary_id): ?>
    <form method="POST" id="salaryForm">
        <input type="hidden" name="operator_name" value="<?= htmlspecialchars($operator_name) ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <input type="hidden" name="total_base_salary" value="<?= $total_base_salary ?>">
        <input type="hidden" name="total_machine_bonus" value="<?= $total_machine_bonus ?>">
        <input type="hidden" name="total_salary" id="total_salary" value="<?= $grand_total ?>">
        
        <div class="bonus-section">
            <div class="search-title"><i class="fas fa-gift"></i> Bonuses & Allowances</div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Machine Bonus</label>
                    <input type="text" class="form-control" value="Rs <?= number_format($total_machine_bonus, 2) ?>" readonly>
                </div>
                <div class="bonus-item">
                    <label>Sunday Bonus</label>
                    <input type="number" step="0.01" name="sunday_bonus" id="sunday_bonus" value="0" onkeyup="calculateTotal()">
                    <small>Suggested: Rs <?= number_format(($sunday_stitches / 1000) * 50 * 0.5, 2) ?></small>
                </div>
                <div class="bonus-item">
                    <label>Attendance Bonus</label>
                    <input type="number" step="0.01" name="attendance_bonus" id="attendance_bonus" value="0" onkeyup="calculateTotal()">
                    <small><?= number_format($attendance_percentage, 1) ?>% attendance</small>
                </div>
            </div>
            
            <div class="total-box">
                <span><i class="fas fa-calculator"></i> Total Salary</span>
                <span class="value" id="total_display">Rs <?= number_format($grand_total, 2) ?></span>
            </div>
            
            <div style="margin-top: 16px; text-align: right;">
                <button type="submit" name="save_salary" class="btn btn-success">
                    <i class="fas fa-save"></i> Save Salary & Create Bill
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php elseif ($operator_name && $month && !$existing_salary && !$daily_data): ?>
    <div class="alert alert-info">No embroidery entries found for this operator in the selected month.</div>
    <?php endif; ?>

    <!-- Recent Records -->
    <?php if ($history_query && $history_query->num_rows > 0): ?>
    <div class="table-container">
        <div class="table-header"><i class="fas fa-history"></i> Recent Salary Records</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Operator</th>
                        <th>Month</th>
                        <th>Stitches</th>
                        <th>Base</th>
                        <th>Machine Bonus</th>
                        <th>Total</th>
                        <th>Bill Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($record = $history_query->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['operator_name']) ?></td>
                        <td><?= date('M Y', strtotime($record['month'] . '-01')) ?></td>
                        <td><?= number_format($record['total_stitches']) ?></td>
                        <td>Rs <?= number_format($record['base_salary'], 2) ?></td>
                        <td>Rs <?= number_format($record['machine_bonus'] ?? 0, 2) ?></td>
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
                            <a href="?operator=<?= urlencode($record['operator_name']) ?>&month=<?= $record['month'] ?>&view_salary=<?= $record['id'] ?>" class="btn btn-primary btn-sm">
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
function calculateTotal() {
    let base = parseFloat(document.querySelector('input[name="total_base_salary"]').value) || 0;
    let machineBonus = parseFloat(document.querySelector('input[name="total_machine_bonus"]').value) || 0;
    let sundayBonus = parseFloat(document.getElementById('sunday_bonus').value) || 0;
    let attendanceBonus = parseFloat(document.getElementById('attendance_bonus').value) || 0;
    
    let totalRaw = base + machineBonus + sundayBonus + attendanceBonus;
    let total = Math.round(totalRaw / 10) * 10;
    document.getElementById('total_salary').value = total;
    document.getElementById('total_display').textContent = 'Rs ' + total.toFixed(2);
}

document.addEventListener('DOMContentLoaded', calculateTotal);
</script>
</body>
</html>