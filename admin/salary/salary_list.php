<?php
$page_identifier = 'salary/salary_list.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure salaries and stitching_posted_bills tables have company_id column
$tables = ['salaries', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Handle Delete
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Fetch the salary record (with company check)
    $check_stmt = $conn->prepare("SELECT id, operator_name, month FROM salaries WHERE id = ? AND company_id = ?");
    $check_stmt->bind_param("ii", $delete_id, $company_id);
    $check_stmt->execute();
    $salary = $check_stmt->get_result()->fetch_assoc();
    
    if ($salary) {
        $conn->begin_transaction();
        try {
            // 1. Delete the salary record
            $del_salary = $conn->prepare("DELETE FROM salaries WHERE id = ? AND company_id = ?");
            $del_salary->bind_param("ii", $delete_id, $company_id);
            if (!$del_salary->execute()) {
                throw new Exception("Failed to delete salary record.");
            }
            $del_salary->close();
            
            // 2. Delete the associated bill using job_no pattern (SALARY-<salary_id>)
            $job_no = "SALARY-" . $delete_id;
            $del_bill = $conn->prepare("DELETE FROM stitching_posted_bills WHERE job_no = ? AND company_id = ?");
            $del_bill->bind_param("si", $job_no, $company_id);
            $del_bill->execute(); // It's okay if no bill exists
            $del_bill->close();
            
            $conn->commit();
            $success = "Salary record deleted successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error deleting record: " . $e->getMessage();
        }
    } else {
        $error = "Record not found or does not belong to your company.";
    }
    
    // Redirect to avoid resubmission
    header("Location: salary_list.php");
    exit();
}

// Get filter values
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$operator_filter = isset($_GET['operator']) ? trim($_GET['operator']) : '';
$month_filter = isset($_GET['month']) ? trim($_GET['month']) : '';

// Build WHERE clause
$where = ["s.company_id = ?"];
$params = [$company_id];
$types = "i";

if (!empty($status_filter)) {
    $where[] = "s.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}
if (!empty($operator_filter)) {
    $where[] = "s.operator_name LIKE ?";
    $params[] = "%$operator_filter%";
    $types .= "s";
}
if (!empty($month_filter)) {
    $where[] = "s.month = ?";
    $params[] = $month_filter;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

// Main query
$sql = "SELECT s.*, spb.status as bill_status, spb.id as bill_id 
        FROM salaries s 
        LEFT JOIN stitching_posted_bills spb ON s.bill_id = spb.id AND spb.company_id = s.company_id
        WHERE $where_clause
        ORDER BY s.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Count query
$count_sql = "SELECT COUNT(*) as total, SUM(s.total_salary) as grand_total FROM salaries s WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$totals = $count_stmt->get_result()->fetch_assoc();
if (!$totals) {
    $totals = ['total' => 0, 'grand_total' => 0];
}

// Get distinct operators for filter
$ops_stmt = $conn->prepare("SELECT DISTINCT operator_name FROM salaries WHERE company_id = ? ORDER BY operator_name");
$ops_stmt->bind_param("i", $company_id);
$ops_stmt->execute();
$ops_list = $ops_stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Salary Records</title>
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
        min-height: 100vh;
    }

    .main-container {
        margin-left: 14%;
        padding: 24px 28px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .page-header {
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-header h2 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .page-header h2 i {
        color: var(--primary);
        font-size: 1.6rem;
    }

    .alert {
        padding: 12px 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: none;
        animation: slideIn 0.3s ease;
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

    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 18px 20px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .summary-icon {
        width: 48px;
        height: 48px;
        background: var(--primary-light);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
    }

    .summary-icon i {
        font-size: 1.3rem;
        color: var(--primary);
    }

    .summary-label {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
    }

    .summary-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--primary-dark);
    }

    .filter-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .filter-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 16px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--primary);
    }

    .filter-title i {
        margin-right: 6px;
    }

    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: flex-end;
    }

    .filter-item {
        flex: 1;
        min-width: 160px;
    }

    .filter-item label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    .filter-item label i {
        color: var(--primary);
        margin-right: 4px;
    }

    .filter-item .form-control,
    .filter-item .form-select {
        width: 100%;
        padding: 10px 14px;
        font-size: 0.85rem;
        border: 1.5px solid var(--border);
        border-radius: 10px;
    }

    .filter-item .form-control:focus,
    .filter-item .form-select:focus {
        border-color: var(--primary);
        outline: none;
    }

    .filter-buttons {
        display: flex;
        gap: 10px;
    }

    .table-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--border);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .table-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        background: var(--bg-light);
        font-size: 0.9rem;
        font-weight: 700;
    }

    .table-header i {
        color: var(--primary);
        margin-right: 8px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        min-width: 1100px;
    }

    .table th {
        background: var(--bg-light);
        padding: 14px 16px;
        border-bottom: 2px solid var(--primary);
        font-weight: 700;
        text-align: left;
        white-space: nowrap;
    }

    .table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .table tbody tr:hover {
        background: var(--primary-light);
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .badge-posted {
        background: #d4edda;
        color: #27ae60;
    }

    .badge-pending {
        background: #fff3cd;
        color: #f39c12;
    }

    .btn {
        padding: 8px 16px;
        font-size: 0.8rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-success { background: var(--success); color: white; }
    .btn-warning { background: var(--warning); color: white; }
    .btn-danger { background: var(--danger); color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-sm { padding: 5px 12px; font-size: 0.75rem; }

    .action-buttons {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .empty-state {
        text-align: center;
        padding: 48px;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; padding: 20px; }
    }

    @media (max-width: 992px) {
        .main-container { margin-left: 0; padding: 16px; margin-top: 60px; }
        .filter-row { flex-direction: column; }
        .filter-item { width: 100%; }
        .filter-buttons { width: 100%; }
        .filter-buttons .btn { flex: 1; justify-content: center; }
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-money-bill-wave"></i> Salary Records</h2>
        <a href="operator_salary.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Salary
        </a>
    </div>

    <?php if(isset($success)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if(isset($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-icon"><i class="fas fa-users"></i></div>
            <div class="summary-label">Total Salaries</div>
            <div class="summary-value"><?= number_format($totals['total'] ?? 0) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-icon"><i class="fas fa-rupee-sign"></i></div>
            <div class="summary-label">Total Amount</div>
            <div class="summary-value">Rs <?= number_format($totals['grand_total'] ?? 0, 2) ?></div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-card">
        <div class="filter-title"><i class="fas fa-filter"></i> Filter Salaries</div>
        <form method="GET" action="salary_list.php">
            <div class="filter-row">
                <div class="filter-item">
                    <label><i class="fas fa-user"></i> Operator</label>
                    <input type="text" name="operator" class="form-control" list="operatorList" value="<?= htmlspecialchars($operator_filter) ?>" placeholder="Search operator...">
                    <datalist id="operatorList">
                        <?php if ($ops_list && $ops_list->num_rows > 0):
                            while ($op = $ops_list->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($op['operator_name']) ?>">
                        <?php endwhile; endif; ?>
                    </datalist>
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-calendar"></i> Month</label>
                    <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month_filter) ?>">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-tag"></i> Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="posted" <?= $status_filter == 'posted' ? 'selected' : '' ?>>Posted</option>
                    </select>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                    <a href="salary_list.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Salary Table -->
    <div class="table-card">
        <div class="table-header"><i class="fas fa-list"></i> All Salary Records</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Operator</th>
                        <th>Month</th>
                        <th>Stitches</th>
                        <th>Base Salary</th>
                        <th>Machine Bonus</th>
                        <th>Sunday Bonus</th>
                        <th>Attendance Bonus</th>
                        <th>Total Salary</th>
                        <th>Created Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td><strong><?= htmlspecialchars($row['operator_name']) ?></strong></td>
                            <td><?= date('M Y', strtotime($row['month'] . '-01')) ?></td>
                            <td><?= number_format($row['total_stitches']) ?></td>
                            <td>Rs <?= number_format($row['base_salary'], 2) ?></td>
                            <td>Rs <?= number_format($row['machine_bonus'], 2) ?></td>
                            <td>Rs <?= number_format($row['sunday_bonus'] ?? 0, 2) ?></td>
                            <td>Rs <?= number_format($row['attendance_bonus'] ?? 0, 2) ?></td>
                            <td><strong class="text-success">Rs <?= number_format($row['total_salary'], 2) ?></strong></td>
                            <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                            <td>
                                <?php if ($row['status'] == 'posted'): ?>
                                    <span class="badge badge-posted"><i class="fas fa-check-circle"></i> Posted</span>
                                <?php else: ?>
                                    <span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="edit_salary.php?id=<?= $row['id'] ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if (isset($row['bill_id']) && $row['bill_id'] > 0): ?>
                                    <a href="../stitching/view_bill.php?id=<?= $row['bill_id'] ?>" class="btn btn-warning btn-sm" target="_blank">
                                        <i class="fas fa-file-invoice"></i> Bill
                                    </a>
                                    <?php endif; ?>
                                    <a href="salary_list.php?delete_id=<?= $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this salary record?')">
                                        <i class="fas fa-trash"></i> Del
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" class="empty-state">
                                <i class="fas fa-money-bill-wave"></i>
                                <div>No salary records found</div>
                                <a href="salary.php" class="btn btn-primary btn-sm mt-3">Create New Salary</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>