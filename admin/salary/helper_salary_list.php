<?php
$page_identifier = 'salary/helper_salary_list.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure salaries table has company_id column
$check = $conn->query("SHOW COLUMNS FROM salaries LIKE 'company_id'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE salaries ADD COLUMN company_id INT DEFAULT NULL");
    $conn->query("UPDATE salaries SET company_id = 1 WHERE company_id IS NULL");
    $conn->query("ALTER TABLE salaries MODIFY company_id INT NOT NULL");
}

// Handle delete (with associated bill removal)
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Fetch the salary record first to confirm it exists and belongs to company
    $check_stmt = $conn->prepare("SELECT id FROM salaries WHERE id = ? AND role_type = 'helper' AND company_id = ?");
    $check_stmt->bind_param("ii", $delete_id, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $conn->begin_transaction();
        try {
            // Delete the salary record
            $del_stmt = $conn->prepare("DELETE FROM salaries WHERE id = ? AND role_type = 'helper' AND company_id = ?");
            $del_stmt->bind_param("ii", $delete_id, $company_id);
            if (!$del_stmt->execute()) {
                throw new Exception("Failed to delete salary record.");
            }
            $del_stmt->close();
            
            // Also delete the corresponding bill from stitching_posted_bills
            $job_no = "SALARY-H-" . $delete_id;
            $del_bill = $conn->prepare("DELETE FROM stitching_posted_bills WHERE job_no = ? AND company_id = ?");
            $del_bill->bind_param("si", $job_no, $company_id);
            $del_bill->execute();  // It's okay if no bill exists (e.g., previously deleted)
            $del_bill->close();
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            // Optionally set an error message, but we'll just redirect
        }
    }
    $check_stmt->close();
    
    header("Location: helper_salary_list.php");
    exit;
}

// Handle post/unpost (unchanged)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'post') {
        $status = 'posted';
    } elseif ($action == 'unpost') {
        $status = 'pending';
    } else {
        $status = '';
    }
    if ($status) {
        $upd_stmt = $conn->prepare("UPDATE salaries SET status = ? WHERE id = ? AND role_type = 'helper' AND company_id = ?");
        $upd_stmt->bind_param("sii", $status, $id, $company_id);
        $upd_stmt->execute();
    }
    header("Location: helper_salary_list.php");
    exit;
}

// Get filter values
$search_helper = isset($_GET['search_helper']) ? trim($_GET['search_helper']) : '';
$month_filter = isset($_GET['month']) ? trim($_GET['month']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build dynamic WHERE clause
$where = ["role_type = 'helper'", "company_id = ?"];
$params = [$company_id];
$types = "i";

if (!empty($search_helper)) {
    $where[] = "party_name LIKE ?";
    $params[] = "%$search_helper%";
    $types .= "s";
}
if (!empty($month_filter)) {
    $where[] = "month = ?";
    $params[] = $month_filter;
    $types .= "s";
}
if (!empty($status_filter)) {
    $where[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

// Main query
$sql = "SELECT * FROM salaries WHERE $where_clause ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Count query
$count_sql = "SELECT COUNT(*) as total, SUM(total_salary) as grand_total FROM salaries WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$totals = $count_stmt->get_result()->fetch_assoc();
if (!$totals) {
    $totals = ['total' => 0, 'grand_total' => 0];
}

// Get distinct helpers for filter dropdown
$helpers_stmt = $conn->prepare("SELECT DISTINCT party_name FROM salaries WHERE role_type = 'helper' AND company_id = ? ORDER BY party_name");
$helpers_stmt->bind_param("i", $company_id);
$helpers_stmt->execute();
$helpers_list = $helpers_stmt->get_result();

// Get distinct months for filter dropdown
$months_stmt = $conn->prepare("SELECT DISTINCT month FROM salaries WHERE role_type = 'helper' AND company_id = ? ORDER BY month DESC");
$months_stmt->bind_param("i", $company_id);
$months_stmt->execute();
$months_list = $months_stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Helper Salary List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* (CSS unchanged – same as original) */
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }

        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
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
            color: var(--primary-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h2 i {
            color: var(--primary);
        }

        .btn-add {
            background: var(--success);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-add:hover {
            background: #219a52;
            transform: translateY(-2px);
            color: white;
        }

        .summary-grid {
            display: flex;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            flex: 1;
            min-width: 180px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }

        .summary-card .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .summary-card .value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .filter-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary);
            display: inline-block;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
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
            margin-bottom: 4px;
            letter-spacing: 0.5px;
        }

        .filter-item label i {
            color: var(--primary);
            margin-right: 4px;
        }

        .filter-item input, .filter-item select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
        }

        .filter-item input:focus, .filter-item select:focus {
            border-color: var(--primary);
            outline: none;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .table-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .table-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-light);
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .salary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            min-width: 900px;
        }

        .salary-table th {
            background: var(--bg-light);
            padding: 12px 16px;
            text-align: left;
            border-bottom: 2px solid var(--primary);
            font-weight: 700;
            color: var(--text-dark);
        }

        .salary-table td {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .salary-table tr:hover {
            background: var(--primary-light);
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-posted {
            background: #d4edda;
            color: #155724;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.7rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }

        .btn-edit {
            background: var(--info);
            color: white;
        }

        .btn-edit:hover {
            background: #2980b9;
            transform: translateY(-1px);
            color: white;
        }

        .btn-delete {
            background: var(--danger);
            color: white;
        }

        .btn-delete:hover {
            background: #c0392b;
            transform: translateY(-1px);
            color: white;
        }

        .btn-post {
            background: var(--success);
            color: white;
        }

        .btn-post:hover {
            background: #219a52;
            transform: translateY(-1px);
            color: white;
        }

        .btn-unpost {
            background: var(--warning);
            color: white;
        }

        .btn-unpost:hover {
            background: #e08e0b;
            transform: translateY(-1px);
            color: white;
        }

        .total-box {
            background: var(--primary-light);
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 20px;
            text-align: right;
            border: 1px solid var(--primary);
        }

        .total-box strong {
            color: var(--primary-dark);
            font-size: 1rem;
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
            .main-container {
                margin-left: 10%;
                padding: 20px;
            }
        }

        @media (max-width: 992px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
                margin-top: 60px;
            }
            .summary-grid {
                flex-direction: column;
            }
            .filter-row {
                flex-direction: column;
            }
            .filter-item {
                width: 100%;
            }
            .action-buttons {
                flex-direction: column;
            }
            .btn-sm {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2>
            <i class="fas fa-user-friends"></i>
            Helper Salary List
        </h2>
        <a href="helper_salary.php" class="btn-add">
            <i class="fas fa-plus-circle"></i> Add New Salary
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="label">Total Records</div>
            <div class="value"><?= number_format($totals['total'] ?? 0) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Amount</div>
            <div class="value">Rs. <?= number_format($totals['grand_total'] ?? 0, 2) ?></div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-card">
        <div class="filter-title">
            <i class="fas fa-filter"></i> Filter Salaries
        </div>
        <form method="GET" class="filter-row">
            <div class="filter-item">
                <label><i class="fas fa-user"></i> Helper Name</label>
                <input type="text" name="search_helper" list="helperList" 
                       value="<?= htmlspecialchars($search_helper) ?>" placeholder="Search helper...">
                <datalist id="helperList">
                    <?php 
                    if ($helpers_list && $helpers_list->num_rows > 0) {
                        $helpers_list->data_seek(0);
                        while ($hl = $helpers_list->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($hl['party_name']) ?>">
                        <?php endwhile;
                    } ?>
                </datalist>
            </div>
            <div class="filter-item">
                <label><i class="fas fa-calendar"></i> Month</label>
                <select name="month">
                    <option value="">All Months</option>
                    <?php 
                    if ($months_list && $months_list->num_rows > 0) {
                        $months_list->data_seek(0);
                        while ($m = $months_list->fetch_assoc()): ?>
                        <option value="<?= $m['month'] ?>" <?= $month_filter == $m['month'] ? 'selected' : '' ?>>
                            <?= date('F Y', strtotime($m['month'] . '-01')) ?>
                        </option>
                        <?php endwhile;
                    } ?>
                </select>
            </div>
            <div class="filter-item">
                <label><i class="fas fa-tag"></i> Status</label>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="posted" <?= $status_filter == 'posted' ? 'selected' : '' ?>>Posted</option>
                </select>
            </div>
            <div class="filter-item">
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <a href="helper_salary_list.php" class="btn-filter" style="background: #6c757d;">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Salary Table -->
    <div class="table-card">
        <div class="table-header">
            <span><i class="fas fa-list"></i> Helper Salary Records</span>
            <span class="text-muted" style="font-size: 0.7rem;">Total: <?= $result ? $result->num_rows : 0 ?> records</span>
        </div>
        <div class="table-responsive">
            <?php if ($result && $result->num_rows > 0): ?>
            <table class="salary-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Helper Name</th>
                        <th>Month</th>
                        <th>Days Worked</th>
                        <th>Total Stitches</th>
                        <th>Basic Salary</th>
                        <th>Bonus</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    while ($row = $result->fetch_assoc()): 
                        $bonus_total = ($row['bonus'] ?? 0) + ($row['allowance'] ?? 0) + ($row['attendance_bonus'] ?? 0) + ($row['double_duty_bonus'] ?? 0) + ($row['other_bonus'] ?? 0);
                    ?>
                    <tr>
                        <td>#<?= $counter++ ?></td>
                        <td><strong><?= htmlspecialchars($row['party_name']) ?></strong></td>
                        <td><?= date('F Y', strtotime($row['month'] . '-01')) ?> (<?= $row['month'] ?>)</td>
                        <td><?= $row['total_days'] ?> / <?= date('t', strtotime($row['month'] . '-01')) ?> days</td>
                        <td><?= number_format($row['total_stitches'] ?? 0) ?></td>
                        <td>Rs. <?= number_format($row['base_salary'] ?? 0, 2) ?></td>
                        <td>Rs. <?= number_format($bonus_total, 2) ?></td>
                        <td><strong>Rs. <?= number_format($row['total_salary'], 2) ?></strong></td>
                        <td>
                            <?php if ($row['status'] == 'posted'): ?>
                                <span class="badge badge-posted">
                                    <i class="fas fa-check-circle"></i> Posted
                                </span>
                            <?php else: ?>
                                <span class="badge badge-pending">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="helper_salary_edit.php?id=<?= $row['id'] ?>" class="btn-sm btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                
                                <a href="?delete_id=<?= $row['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('Delete this salary record?')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <div class="total-box">
                <strong>Total Amount: Rs. <?= number_format($totals['grand_total'] ?? 0, 2) ?></strong>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No helper salary records found</p>
                <a href="helper_salary.php" class="btn-add" style="display: inline-flex;">
                    <i class="fas fa-plus-circle"></i> Add First Salary
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>