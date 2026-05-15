<?php
$page_identifier = 'salary/operator_salary_edit.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}


$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id'];

$edit_salary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($edit_salary_id <= 0) {
    die("Invalid salary ID.");
}

// Load salary record
$stmt = $conn->prepare("SELECT * FROM salaries WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $edit_salary_id, $company_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows == 0) {
    die("Salary record not found.");
}
$salary = $res->fetch_assoc();
$stmt->close();

if ($salary['status'] !== 'pending') {
    die("This salary is already posted and cannot be edited.");
}

$operator_name = $salary['operator_name'];
$month = $salary['month'];
$sunday_bonus = $salary['sunday_bonus'] ?? 0;
$attendance_bonus = $salary['attendance_bonus'] ?? 0;
$allowance = $salary['allowance'] ?? 0;

$message = '';

// Process update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_salary'])) {
    $new_sunday_bonus = floatval($_POST['sunday_bonus'] ?? 0);
    $new_attendance_bonus = floatval($_POST['attendance_bonus'] ?? 0);
    $new_allowance = floatval($_POST['allowance'] ?? 0);

    // Recalculate from entries
    $start_date = date('Y-m-01', strtotime($month));
    $end_date = date('Y-m-t', strtotime($month));
    $days_in_month = date('t', strtotime($month));

    // Stats
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

    $total_stitches  = (int)($stats['total_stitches'] ?? 0);
    $total_days      = (int)($stats['total_days'] ?? 0);
    $sunday_stitches = (int)($stats['sunday_stitches'] ?? 0);
    $sunday_count    = (int)($stats['sunday_count'] ?? 0);
    $attendance_percent = ($total_days / $days_in_month) * 100;

    // Recalc base & machine bonus
    $rec_stmt = $conn->prepare("SELECT 
        SUM(stitch_done * op_rate / 1000) as base_salary,
        SUM(machine_bonus) as machine_bonus
        FROM embroidery_entries 
        WHERE operator_name = ? AND entry_date BETWEEN ? AND ? AND company_id = ?");
    $rec_stmt->bind_param("sssi", $operator_name, $start_date, $end_date, $company_id);
    $rec_stmt->execute();
    $rec_data = $rec_stmt->get_result()->fetch_assoc();
    $rec_stmt->close();

    $base_salary = round(floatval($rec_data['base_salary'] ?? 0), 2);
    $machine_bonus = round(floatval($rec_data['machine_bonus'] ?? 0), 2);

    $gross = $base_salary + $machine_bonus + $new_sunday_bonus + $new_attendance_bonus + $new_allowance;
    $total_salary = round($gross / 10) * 10;

    // Update salaries
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE salaries SET 
            total_stitches = ?, total_days = ?, sunday_stitches = ?, sunday_count = ?,
            base_salary = ?, machine_bonus = ?, sunday_bonus = ?, attendance_bonus = ?,
            allowance = ?, attendance_percentage = ?, total_salary = ?
            WHERE id = ? AND company_id = ?");
        $update->bind_param("iiiiddddddiii",
            $total_stitches, $total_days, $sunday_stitches, $sunday_count,
            $base_salary, $machine_bonus, $new_sunday_bonus, $new_attendance_bonus,
            $new_allowance, $attendance_percent, $total_salary,
            $edit_salary_id, $company_id);
        if (!$update->execute()) {
            throw new Exception("Salary update failed: " . $update->error);
        }
        $update->close();

        // Update bill
        $job_no = "SALARY-$edit_salary_id";
        $description = "Salary for $operator_name - Month: " . date('M Y', strtotime($month));
        $claim_date = date('Y-m-d');
        $bill = $conn->prepare("UPDATE stitching_posted_bills SET 
            emp_name = ?, rate = ?, total_amount = ?, description = ?, claim_date = ?
            WHERE job_no = ? AND company_id = ?");
        $bill->bind_param("sddsssi", $operator_name, $total_salary, $total_salary, $description, $claim_date, $job_no, $company_id);
        if (!$bill->execute()) {
            throw new Exception("Bill update failed: " . $bill->error);
        }
        $bill->close();

        $conn->commit();
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Salary updated successfully! Redirecting...</div>';
        header("refresh:2;url=operator_salary.php?operator=".urlencode($operator_name)."&month=".$month."&view_salary=".$edit_salary_id);
    } catch (Exception $e) {
        $conn->rollback();
        $message = '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
    }
}

// ============== DISPLAY DATA (recalculate for display) ==============
$start_date = date('Y-m-01', strtotime($month));
$end_date = date('Y-m-t', strtotime($month));
$days_in_month = date('t', strtotime($month));

// Daily breakdown
$entries_stmt = $conn->prepare("SELECT 
    e.id, e.entry_date, e.job_no, e.machine_no, e.shift, e.stitch_done, e.op_rate, e.machine_bonus,
    m.head, j.design_name
    FROM embroidery_entries e
    LEFT JOIN machines m ON e.machine_id = m.id AND m.company_id = e.company_id
    LEFT JOIN jobs j ON e.job_no = j.job_no AND j.company_id = e.company_id
    WHERE e.operator_name = ? AND e.entry_date BETWEEN ? AND ? AND e.company_id = ?
    ORDER BY e.entry_date DESC, e.job_no");
$entries_stmt->bind_param("sssi", $operator_name, $start_date, $end_date, $company_id);
$entries_stmt->execute();
$entries_result = $entries_stmt->get_result();

$grouped_data = [];
$unique_dates = [];
$total_stitches = 0;
$total_base_salary = 0;
$total_machine_bonus = 0;
$sunday_stitches = 0;

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

$total_days = count($unique_dates);
$sunday_count = count(array_filter($grouped_data, function($item) { return $item['day_of_week'] == 1; }));
$attendance_percentage = ($total_days / $days_in_month) * 100;
$grand_total_raw = $total_base_salary + $total_machine_bonus;
$grand_total = round(($grand_total_raw + $sunday_bonus + $attendance_bonus + $allowance) / 10) * 10;
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Operator Salary</title>
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
        --bg-light: #F8F9FA;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #f5f7fa; font-family: 'Segoe UI', system-ui, sans-serif; }
    .main-container { margin-left: 14%; padding: 20px 24px; min-height: 100vh; }
    .page-header { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .page-header h2 { font-size: 1.3rem; font-weight: 700; color: var(--primary-dark); margin: 0; }
    .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.85rem; }
    .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
    .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
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
    .table td { padding: 8px 12px; border-bottom: 1px solid var(--border); }
    .total-row { background: var(--primary-light); font-weight: 700; }
    .sunday-row td:first-child { border-left: 3px solid var(--danger); }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
    .badge-bonus { background: #6f42c1; color: white; }
    .bonus-section { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 20px; }
    .bonus-grid { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
    .bonus-item { flex: 1; min-width: 140px; }
    .bonus-item label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 4px; }
    .bonus-item input { width: 100%; padding: 8px 12px; font-size: 1rem; border: 1px solid var(--border); border-radius: 6px; }
    .bonus-item input:focus { border-color: var(--primary); outline: none; }
    .bonus-item input[readonly] { background: var(--bg-light); font-weight: 600; color: var(--primary); }
    .total-box { background: var(--primary); color: white; padding: 14px 18px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; font-size: 1rem; }
    .total-box .value { font-size: 1.5rem; font-weight: 700; }
    .btn { padding: 8px 18px; font-size: 0.9rem; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-success { background: var(--success); color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .form-row { flex-direction: column; } }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-edit"></i> Edit Operator Salary</h2>
    </div>

    <?= $message ?>

    <!-- Operator & Month Header -->
    <div class="summary-row">
        <div class="summary-item">
            <div class="summary-label">Operator</div>
            <div class="summary-value"><?= htmlspecialchars($operator_name) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Month</div>
            <div class="summary-value"><?= date('F Y', strtotime($month)) ?></div>
        </div>
    </div>

    <!-- Summary Row (Recalculated) -->
    <div class="summary-row">
        <div class="summary-item"><div class="summary-label">Total Stitches</div><div class="summary-value"><?= number_format($total_stitches) ?></div></div>
        <div class="summary-item"><div class="summary-label">Days Worked</div><div class="summary-value"><?= $total_days ?> / <?= $days_in_month ?></div></div>
        <div class="summary-item"><div class="summary-label">Sunday Stitches</div><div class="summary-value"><?= number_format($sunday_stitches) ?></div></div>
        <div class="summary-item"><div class="summary-label">Machine Bonus</div><div class="summary-value">Rs <?= number_format($total_machine_bonus, 2) ?></div></div>
        <div class="summary-item"><div class="summary-label">Base Salary</div><div class="summary-value">Rs <?= number_format($total_base_salary, 2) ?></div></div>
    </div>

    <!-- Daily Breakdown Table -->
    <?php if (!empty($grouped_data)): ?>
    <div class="table-container">
        <div class="table-header"><i class="fas fa-calendar-week"></i> Daily Breakdown</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Date</th><th>Job No</th><th>Design</th><th>Stitches</th><th>Base</th><th>Machine Bonus</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_data as $item): 
                        $is_sunday = ($item['day_of_week'] == 1);
                    ?>
                    <tr class="<?= $is_sunday ? 'sunday-row' : '' ?>">
                        <td><?= date('d M', strtotime($item['date'])) ?><?= $is_sunday ? ' <span class="badge" style="background:#e74c3c; color:white;">Sun</span>' : '' ?></td>
                        <td><strong><?= htmlspecialchars($item['job_no']) ?></strong></td>
                        <td><?= htmlspecialchars($item['design_name']) ?></td>
                        <td><?= number_format($item['total_stitches']) ?></td>
                        <td>Rs <?= number_format($item['total_base_salary'], 2) ?></td>
                        <td><?= $item['total_machine_bonus'] > 0 ? '<span class="badge badge-bonus">+ Rs '.number_format($item['total_machine_bonus'], 2).'</span>' : '-' ?></td>
                        <td><strong>Rs <?= number_format($item['total_base_salary'] + $item['total_machine_bonus'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4"><strong>TOTAL</strong></td>
                        <td><strong>Rs <?= number_format($total_base_salary, 2) ?></strong></td>
                        <td><strong>Rs <?= number_format($total_machine_bonus, 2) ?></strong></td>
                        <td><strong>Rs <?= number_format($grand_total_raw, 2) ?></strong></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="6"><strong>ROUNDED TOTAL (Nearest 10)</strong></td>
                        <td><strong style="color: var(--primary);">Rs <?= number_format($grand_total, 2) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Editable Bonus Section -->
    <form method="POST">
        <div class="bonus-section">
            <div class="table-header" style="margin-bottom:15px;"><i class="fas fa-gift"></i> Bonuses & Allowances</div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Machine Bonus</label>
                    <input type="text" class="form-control" value="Rs <?= number_format($total_machine_bonus, 2) ?>" readonly>
                </div>
                <div class="bonus-item">
                    <label>Sunday Bonus</label>
                    <input type="number" step="0.01" name="sunday_bonus" class="form-control" value="<?= $sunday_bonus ?>" oninput="calcTotal()">
                </div>
                <div class="bonus-item">
                    <label>Attendance Bonus</label>
                    <input type="number" step="0.01" name="attendance_bonus" class="form-control" value="<?= $attendance_bonus ?>" oninput="calcTotal()">
                    <small><?= number_format($attendance_percentage, 1) ?>% attendance</small>
                </div>
                <div class="bonus-item">
                    <label>Additional Allowance</label>
                    <input type="number" step="0.01" name="allowance" class="form-control" value="<?= $allowance ?>" oninput="calcTotal()">
                </div>
            </div>
            <div class="total-box">
                <span><i class="fas fa-calculator"></i> Total Salary</span>
                <span class="value" id="total_display">Rs <?= number_format($grand_total, 2) ?></span>
            </div>
            <div style="margin-top: 16px; text-align: right;">
                <a href="operator_salary.php?operator=<?= urlencode($operator_name) ?>&month=<?= $month ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="update_salary" class="btn btn-success">
                    <i class="fas fa-save"></i> Update Salary & Bill
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    const baseSalary = <?= $total_base_salary ?>;
    const machineBonus = <?= $total_machine_bonus ?>;
    function calcTotal() {
        let s = parseFloat(document.querySelector('[name="sunday_bonus"]').value) || 0;
        let a = parseFloat(document.querySelector('[name="attendance_bonus"]').value) || 0;
        let al = parseFloat(document.querySelector('[name="allowance"]').value) || 0;
        let raw = baseSalary + machineBonus + s + a + al;
        let total = Math.round(raw / 10) * 10;
        document.getElementById('total_display').textContent = 'Rs ' + total.toFixed(2);
    }
    window.addEventListener('load', calcTotal);
</script>
</body>
</html>