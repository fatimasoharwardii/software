<?php
$page_identifier = 'salary/helper_salary_edit.php';
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

// Ensure monthly_rate column exists
$check_monthly = $conn->query("SHOW COLUMNS FROM `salaries` LIKE 'monthly_rate'");
if ($check_monthly->num_rows == 0) {
    $conn->query("ALTER TABLE `salaries` ADD COLUMN `monthly_rate` DECIMAL(12,2) DEFAULT 0 AFTER `base_salary`");
}

// Load salary record
$stmt = $conn->prepare("SELECT * FROM salaries WHERE id = ? AND role_type = 'helper' AND company_id = ?");
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

$helper_name = $salary['party_name'];
$month = $salary['month'];

// Use monthly_rate if available and >0, else fallback to base_salary (stored) or 25000
$monthly_rate = floatval($salary['monthly_rate'] ?? 0);
if ($monthly_rate <= 0) {
    $monthly_rate = floatval($salary['base_salary'] ?? 25000); // fallback, but better to take a default
    if ($monthly_rate <= 0) $monthly_rate = 25000;
}
$attendance_bonus = floatval($salary['attendance_bonus'] ?? 0);
$double_duty_bonus = floatval($salary['double_duty_bonus'] ?? 0);

$message = '';

// Process update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_salary'])) {
    $new_monthly_rate = floatval($_POST['basic_salary'] ?? 25000);
    $new_attendance_bonus = floatval($_POST['attendance_bonus'] ?? 0);
    $new_double_duty_bonus = floatval($_POST['double_duty_bonus'] ?? 0);

    $start_date = date('Y-m-01', strtotime($month));
    $end_date = date('Y-m-t', strtotime($month));
    $days_in_month = date('t', strtotime($month));

    $entries_stmt = $conn->prepare("SELECT 
        DATE(entry_date) as date,
        DAYOFWEEK(entry_date) as day_of_week,
        COUNT(DISTINCT machine_id) as machine_count,
        SUM(stitch_done) as total_stitches
        FROM embroidery_entries 
        WHERE helper_name = ? 
        AND entry_date BETWEEN ? AND ?
        AND company_id = ?
        GROUP BY DATE(entry_date)");
    $entries_stmt->bind_param("sssi", $helper_name, $start_date, $end_date, $company_id);
    $entries_stmt->execute();
    $entries_result = $entries_stmt->get_result();

    $total_days = 0;
    $total_stitches = 0;
    $sunday_count = 0;
    while ($row = $entries_result->fetch_assoc()) {
        $total_days++;
        $total_stitches += $row['total_stitches'];
        if ($row['day_of_week'] == 1) $sunday_count++;
    }
    $entries_stmt->close();

    $per_day_rate = $new_monthly_rate / $days_in_month;
    $base_salary_calculated = $per_day_rate * $total_days;
    $total_salary = $base_salary_calculated + $new_attendance_bonus + $new_double_duty_bonus;
    $attendance_percentage = ($total_days / $days_in_month) * 100;

    $conn->begin_transaction();
    try {
        $update_salary = $conn->prepare("UPDATE salaries SET 
            total_stitches = ?, total_days = ?, sunday_count = ?, 
            base_salary = ?, monthly_rate = ?, total_salary = ?, 
            attendance_percentage = ?, attendance_bonus = ?, double_duty_bonus = ? 
            WHERE id = ? AND company_id = ?");
        $update_salary->bind_param("iiidddiddii", 
            $total_stitches, $total_days, $sunday_count,
            $base_salary_calculated, $new_monthly_rate, $total_salary,
            $attendance_percentage, $new_attendance_bonus, $new_double_duty_bonus,
            $edit_salary_id, $company_id);
        if (!$update_salary->execute()) {
            throw new Exception("Salary update failed: " . $update_salary->error);
        }
        $update_salary->close();

        $job_no = "SALARY-H-" . $edit_salary_id;
        $description = "Helper Salary for $helper_name - Month: " . date('M Y', strtotime($month));
        $claim_date = date('Y-m-d');
        $bill = $conn->prepare("UPDATE stitching_posted_bills SET 
            emp_name = ?, rate = ?, total_amount = ?, description = ?, claim_date = ? 
            WHERE job_no = ? AND company_id = ?");
        $bill->bind_param("sddsssi", 
            $helper_name, $total_salary, $total_salary, 
            $description, $claim_date, $job_no, $company_id);
        if (!$bill->execute()) {
            throw new Exception("Bill update failed: " . $bill->error);
        }
        $bill->close();

        $conn->commit();
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Salary updated successfully! Redirecting...</div>';
        header("refresh:2;url=helper_salary.php?helper=" . urlencode($helper_name) . "&month=" . $month . "&view_salary=" . $edit_salary_id);
    } catch (Exception $e) {
        $conn->rollback();
        $message = '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
    }
}

// Recalculate for display
$start_date = date('Y-m-01', strtotime($month));
$end_date = date('Y-m-t', strtotime($month));
$days_in_month = date('t', strtotime($month));

$daily_data = [];
$total_days = 0;
$double_duty_days = 0;
$total_stitches = 0;
$sunday_count = 0;

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

$base_salary_calculated = ($monthly_rate / $days_in_month) * $total_days;
$attendance_percentage = ($total_days / $days_in_month) * 100;
$total_salary_display = $base_salary_calculated + $attendance_bonus + $double_duty_bonus;
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Helper Salary</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (same style as before) */
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --border: #E9ECEF;
        --text-muted: #6c757d;
        --success: #27ae60;
        --danger: #e74c3c;
        --bg-light: #F8F9FA;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #f5f7fa; font-family: 'Segoe UI', system-ui, sans-serif; }
    .main-container { margin-left: 14%; padding: 20px 24px; min-height: 100vh; }
    .page-header h2 { color: var(--primary-dark); font-size: 1.3rem; }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 15px; font-size: 0.9rem; }
    .summary-row { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .summary-item { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; flex: 1; min-width: 110px; }
    .summary-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; }
    .summary-value { font-size: 1.1rem; font-weight: 700; color: var(--primary-dark); }
    .table-container { background: white; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 20px; }
    .table-header { padding: 12px 16px; background: var(--bg-light); font-weight: 600; font-size: 0.85rem; }
    .table-responsive { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 600px; }
    .table th { background: var(--bg-light); padding: 10px 12px; border-bottom: 2px solid var(--primary); }
    .table td { padding: 8px 12px; border-bottom: 1px solid var(--border); }
    .total-row { background: var(--primary-light); font-weight: 700; }
    .sunday-row td:first-child { border-left: 3px solid var(--danger); }
    .badge { padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
    .bonus-section { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 20px; }
    .bonus-grid { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
    .bonus-item { flex: 1; min-width: 140px; }
    .bonus-item label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; display: block; }
    .bonus-item input { width: 100%; padding: 8px 12px; font-size: 1rem; border: 1px solid var(--border); border-radius: 6px; }
    .bonus-item input:focus { border-color: var(--primary); outline: none; }
    .bonus-item input[readonly] { background: var(--bg-light); font-weight: 600; color: var(--primary); }
    .total-box { background: var(--primary); color: white; padding: 14px 18px; border-radius: 10px; display: flex; justify-content: space-between; font-size: 1rem; }
    .total-box .value { font-size: 1.5rem; font-weight: 700; }
    .btn { padding: 8px 18px; font-weight: 600; border-radius: 8px; border: none; cursor: pointer; text-decoration: none; }
    .btn-success { background: var(--success); color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    @media (max-width: 992px) { .main-container { margin-left: 0; margin-top: 60px; } }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-edit"></i> Edit Helper Salary</h2>
    </div>

    <?= $message ?>

    <div class="summary-row">
        <div class="summary-item"><div class="summary-label">Helper</div><div class="summary-value"><?= htmlspecialchars($helper_name) ?></div></div>
        <div class="summary-item"><div class="summary-label">Month</div><div class="summary-value"><?= date('F Y', strtotime($month)) ?></div></div>
    </div>

    <div class="summary-row">
        <div class="summary-item"><div class="summary-label">Days Worked</div><div class="summary-value"><?= $total_days ?> / <?= $days_in_month ?></div></div>
        <div class="summary-item"><div class="summary-label">Total Stitches</div><div class="summary-value"><?= number_format($total_stitches) ?></div></div>
        <div class="summary-item"><div class="summary-label">Sundays</div><div class="summary-value"><?= $sunday_count ?></div></div>
        <div class="summary-item"><div class="summary-label">Base Salary</div><div class="summary-value">Rs <?= number_format($base_salary_calculated, 2) ?></div></div>
        <div class="summary-item"><div class="summary-label">Attendance</div><div class="summary-value"><?= round($attendance_percentage, 1) ?>%</div></div>
    </div>

    <?php if (!empty($daily_data)): ?>
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
                            <?php if ($day['machine_count'] > 1): ?><span class="badge bg-warning text-dark">Double Duty</span><?php endif; ?>
                            <?php if ($day['day_of_week'] == 1): ?><span class="badge bg-danger">Sunday</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="bonus-section">
            <div class="table-header" style="margin-bottom:15px;"><i class="fas fa-edit"></i> Edit Salary Details</div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Basic Monthly Salary (Rs.)</label>
                    <input type="number" step="any" min="0" name="basic_salary" id="basic_salary" class="form-control" 
                           value="<?= $monthly_rate ?>" oninput="calcTotal()">
                    <small>Full monthly salary</small>
                </div>
            </div>
            <div class="bonus-grid">
                <div class="bonus-item">
                    <label>Double Duty Bonus</label>
                    <input type="number" step="any" name="double_duty_bonus" id="double_duty" class="form-control" 
                           value="<?= $double_duty_bonus ?>" oninput="calcTotal()">
                    <small><?= $double_duty_days ?> double duty days</small>
                </div>
                <div class="bonus-item">
                    <label>Attendance Bonus</label>
                    <input type="number" step="any" name="attendance_bonus" id="attendance_bonus" class="form-control" 
                           value="<?= $attendance_bonus ?>" oninput="calcTotal()">
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
                <span class="value" id="total_display">Rs <?= number_format($total_salary_display, 2) ?></span>
            </div>
            <div style="margin-top: 16px; text-align: right;">
                <a href="helper_salary.php?helper=<?= urlencode($helper_name) ?>&month=<?= $month ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="update_salary" class="btn btn-success">
                    <i class="fas fa-save"></i> Update Salary & Bill
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    const daysInMonth = <?= $days_in_month ?>;
    const workedDays = <?= $total_days ?>;
    function calcTotal() {
        const basic = parseFloat(document.getElementById('basic_salary').value) || 0;
        const perDay = basic / daysInMonth;
        const baseCalc = perDay * workedDays;
        document.getElementById('per_day').value = 'Rs ' + perDay.toFixed(2);
        document.getElementById('base_calc').value = 'Rs ' + baseCalc.toFixed(2);
        const attBonus = parseFloat(document.getElementById('attendance_bonus').value) || 0;
        const ddBonus = parseFloat(document.getElementById('double_duty').value) || 0;
        const total = baseCalc + attBonus + ddBonus;
        document.getElementById('total_display').textContent = 'Rs ' + total.toFixed(2);
    }
    window.addEventListener('load', calcTotal);
</script>
</body>
</html>