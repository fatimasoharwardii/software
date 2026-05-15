<?php
$page_identifier = 'claims/add.php';

// Include database and functions FIRST
require_once "../../config/db.php";
require_once "../../includes/functions.php";

// Include authentication (handles login & permission)
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['jobs', 'stitching_bill_items', 'stitching_posted_bills', 'claims', 'accounts', 'manual_costing'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Search and filter parameters (safe to use in prepared statements)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build WHERE clause with placeholders
$where = " WHERE c.company_id = ? ";
$params = [$company_id];
$types = "i";

if (!empty($search)) {
    $where .= " AND (c.job_no LIKE ? OR c.claim_type LIKE ? OR c.emp_name LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}
if (!empty($status_filter)) {
    $where .= " AND c.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

// Get total records for pagination
$total_sql = "SELECT COUNT(*) as total FROM claims c $where";
$total_stmt = $conn->prepare($total_sql);
$total_stmt->bind_param($types, ...$params);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch all claims with job details (with company isolation)
$sql = "SELECT c.*, j.design_name, j.fabric_name, j.size 
        FROM claims c
        LEFT JOIN jobs j ON c.job_id = j.id
        $where
        ORDER BY c.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
// Append limit and offset to parameters
$all_params = array_merge($params, [$limit, $offset]);
$all_types = $types . "ii";
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$result = $stmt->get_result();

// Get statistics (filtered by same conditions, not paginated)
$stats_sql = "SELECT 
                COUNT(*) as total_claims,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected_count
              FROM claims c $where";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param($types, ...$params);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc() ?: ['total_claims' => 0, 'total_amount' => 0, 'pending_count' => 0, 'approved_count' => 0, 'rejected_count' => 0];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Claims List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Minimal Orange Theme (unchanged) */
        :root {
            --primary: #F39C12;
            --primary-light: #FEF5E7;
            --primary-dark: #B26000;
            --text-dark: #2C3E50;
            --border: #E5E7E9;
            --bg-light: #F8F9F9;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: var(--text-dark);
        }
        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
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
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header h2 i {
            color: var(--primary);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .stat-title {
            font-size: 0.8rem;
            color: #6b7b8b;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .stat-small {
            font-size: 0.85rem;
            color: var(--primary);
            margin-top: 5px;
        }
        .filter-bar {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-bar form {
            flex: 1;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-bar input, .filter-bar select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .filter-bar input {
            flex: 2;
            min-width: 200px;
        }
        .filter-bar select {
            flex: 1;
            min-width: 150px;
        }
        .filter-bar button {
            padding: 8px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .filter-bar button:hover {
            background: var(--primary-dark);
        }
        .btn-reset {
            padding: 8px 20px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-reset:hover {
            background: #7f8c8d;
            color: white;
        }
        .btn-add {
            padding: 8px 20px;
            background: var(--success);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-add:hover {
            background: #218838;
            color: white;
        }
        .table-container {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        table th {
            background: var(--bg-light);
            padding: 12px;
            font-weight: 600;
            border-bottom: 2px solid var(--primary);
            white-space: nowrap;
        }
        table td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }
        table tr:hover {
            background: var(--bg-light);
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .btn-action {
            padding: 5px 10px;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .btn-action:hover {
            opacity: 0.9;
            color: white;
        }
        .btn-view { background: var(--info); }
        .btn-edit { background: var(--warning); color: #333; }
        .btn-delete { background: var(--danger); }
        .pagination {
            margin-top: 20px;
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-dark);
            text-decoration: none;
        }
        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .summary-badge {
            margin-top: 20px;
            text-align: right;
        }
        .summary-badge .badge {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.9rem;
        }
        @media (max-width: 1200px) {
            .main-container {
                margin-left: 10%;
            }
        }
        @media (max-width: 900px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
            }
            .filter-bar form {
                flex-direction: column;
            }
            .filter-bar input, .filter-bar select {
                width: 100%;
            }
            .action-buttons {
                flex-direction: column;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2>
            <i class="fas fa-file-invoice"></i>
            Claims List
        </h2>
        <div>
            <a href="claims.php" class="btn-add">
                <i class="fas fa-plus"></i> New Claim
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-title">Total Claims</div>
            <div class="stat-value"><?= $stats['total_claims'] ?? 0 ?></div>
            <div class="stat-small">Rs. <?= number_format($stats['total_amount'] ?? 0, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Pending</div>
            <div class="stat-value" style="color: #856404;"><?= $stats['pending_count'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Approved</div>
            <div class="stat-value" style="color: #155724;"><?= $stats['approved_count'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Rejected</div>
            <div class="stat-value" style="color: #721c24;"><?= $stats['rejected_count'] ?? 0 ?></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET">
            <input type="text" name="search" placeholder="Search by Job No, Type, Employee..." value="<?= htmlspecialchars($search) ?>">
            <select name="status">
                <option value="">All Status</option>
                <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button type="submit"><i class="fas fa-filter"></i> Filter</button>
        </form>
        <a href="claims_list.php" class="btn-reset"><i class="fas fa-redo-alt"></i> Reset</a>
    </div>

    <!-- Claims Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Job No</th>
                    <th>Employee</th>
                    <th>Claim Type</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows > 0): 
                    $count = $offset + 1;
                    while($row = $result->fetch_assoc()): 
                ?>
                <tr>
                    <td><?= $count++ ?></td>
                    <td><?= date('d-m-Y', strtotime($row['claim_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($row['job_no']) ?></strong></td>
                    <td><?= htmlspecialchars($row['emp_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['claim_type']) ?></td>
                    <td><?= $row['qty'] ?></td>
                    <td>Rs. <?= number_format($row['rate'] ?? 0, 2) ?></td>
                    <td>Rs. <?= number_format($row['amount'], 2) ?></td>
                    <td>
                        <span class="status-badge status-<?= $row['status'] ?>">
                            <?= ucfirst($row['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="edit_claim.php?id=<?= $row['id'] ?>" class="btn-action btn-edit" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="delete_claim.php?id=<?= $row['id'] ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this claim?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr>
                    <td colspan="10" class="text-center py-4">No claims found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if($total_pages > 1): ?>
    <div class="pagination">
        <?php if($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
        <?php endif; ?>
        
        <?php for($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
            <?php if($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Summary -->
    <?php if($result && $result->num_rows > 0): ?>
    <div class="summary-badge">
        <span class="badge">
            <i class="fas fa-calculator"></i> 
            Total: <?= $stats['total_claims'] ?> claims | Page <?= $page ?> of <?= $total_pages ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>