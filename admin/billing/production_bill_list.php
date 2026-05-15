<?php
$page_identifier = 'billing/production_bill_list.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Fetch all production bills from stitching_bill_items
$sql = "SELECT sbi.*, j.design_name, j.size, j.fabric_name 
FROM stitching_bill_items sbi
LEFT JOIN jobs j ON sbi.job_no = j.job_no AND j.company_id = sbi.company_id
WHERE sbi.company_id = $company_id AND sbi.tab_type = 'production_bill'
ORDER BY sbi.id DESC";
$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Production Bills</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #F39C12;
            --primary-dark: #B26000;
            --border: #E5E7E9;
            --bg-light: #F8F9F9;
            --text-dark: #2C3E50;
            --danger: #e74c3c;
            --warning: #f39c12;
            --success: #27ae60;
        }
        body {
            background: #f5f5f5;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text-dark);
        }
        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h2 {
            font-size: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h2 i { color: var(--primary); }
        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        .card-header {
            padding: 16px 20px;
            border-bottom: 2px solid var(--primary);
            background: white;
        }
        .card-header h4 {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        .table th {
            background: var(--bg-light);
            font-size: 0.85rem;
            font-weight: 600;
            padding: 12px 10px;
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
        }
        .table td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .table tbody tr:hover { background: #fcf3e3; }
        .badge-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-na { background: #e9ecef; color: #495057; }
        .btn {
            padding: 6px 15px;
            border-radius: 6px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .empty-state {
            text-align: center; padding: 60px 20px; color: #999;
        }
        .empty-state i { font-size: 4rem; margin-bottom: 15px; color: #bbb; }
        @media (max-width: 992px) {
            .main-container { margin-left: 0; margin-top: 60px; padding: 16px; }
            .page-header h2 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-file-invoice"></i> Production Bills</h2>
        <a href="production_billing_new.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Create New Bill
        </a>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-list"></i> All Bills</h4>
        </div>
        <div class="card-body p-0">
            <?php if ($result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Invoice No</th>
                            <th>Date</th>
                            <th>Customer / Vendor</th>
                            <th>Job No</th>
                            <th>Design</th>
                            <th>Size</th>
                            <th>Fabric</th>
                            <th>Qty</th>
                            <th>Rate (Rs)</th>
                            <th>Amount (Rs)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['bill_no'] ?? 'N/A') ?></td>
                            <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                            <td><?= htmlspecialchars($row['name'] ?? $row['party_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row['job_no']) ?></td>
                            <td><?= htmlspecialchars($row['design_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['size'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['fabric_name'] ?? '-') ?></td>
                            <td><?= number_format($row['qty'] ?? 0, 2) ?></td>
                            <td><?= number_format($row['rate'] ?? 0, 2) ?></td>
                            <td><strong><?= number_format($row['amount'] ?? 0, 2) ?></strong></td>
                            <td>
                                <span class="badge-status badge-na">N/A</span>
                            </td>
                            <td>
                                <a href="edit_production_bill.php?id=<?= $row['id'] ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="del_bill.php?id=<?= $row['id'] ?>" 
                                   class="btn btn-danger btn-sm" 
                                   onclick="return confirm('Delete this entry? This cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h5>No production bills found</h5>
                    <p>Create your first bill using the button above.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>