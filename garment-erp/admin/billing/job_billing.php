<?php
$page_identifier = 'billing/job_billing.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$can_edit = hasAccess($current_page, true);

$job_no = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';
$items = [];
$grand_total = 0;

if ($job_no) {
    // Company filter for isolation
    $company_filter = getCompanyFilter('sbi'); // sbi = stitching_bill_items alias
    // Exclude posted bills? Actually tab_type='posted' doesn't exist; we use status from posted_bills. But original logic used tab_type!='posted'. We'll keep as original but add company filter.
    $stmt = $conn->prepare("SELECT sbi.* FROM stitching_bill_items sbi 
                            WHERE sbi.job_no = ? AND sbi.tab_type != 'posted'
                            $company_filter
                            ORDER BY sbi.id");
    $stmt->bind_param("s", $job_no);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
        $grand_total += ($row['amount'] + $row['sub_total']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Job Billing</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #F39C12;
            --primary-light: #FEF5E7;
            --primary-dark: #B26000;
            --text-dark: #2C3E50;
            --border: #E5E7E9;
            --bg-light: #F8F9F9;
            --success: #27ae60;
            --danger: #e74c3c;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f5f7fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 8px;
        }
        h2 i { color: var(--primary); }
        .card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card-header {
            padding: 14px 20px;
            background: white;
            border-bottom: 2px solid var(--primary);
            font-weight: 600;
        }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        .btn {
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-success:hover {
            background: #219a52;
            transform: translateY(-1px);
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        th {
            background: var(--bg-light);
            padding: 12px;
            border-bottom: 2px solid var(--primary);
            font-weight: 600;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }
        tr:hover td {
            background: var(--primary-light);
        }
        .amount {
            font-weight: 600;
            color: var(--success);
        }
        .grand-total {
            font-weight: 700;
            font-size: 1.1rem;
            background: var(--primary-light);
        }
        .footer-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        @media (max-width: 1200px) {
            .main-container { margin-left: 10%; padding: 20px; }
        }
        @media (max-width: 992px) {
            .main-container { margin-left: 0; padding: 16px; margin-top: 60px; }
        }
        @media (max-width: 768px) {
            .main-container { padding: 12px; }
            th, td { padding: 8px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <h2><i class="fas fa-file-invoice-dollar"></i> Job Billing</h2>

    <div class="card">
        <div class="card-header"><i class="fas fa-search"></i> Search Job</div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Job Number</label>
                    <input type="text" name="job_no" class="form-control" value="<?= htmlspecialchars($job_no) ?>" placeholder="Enter job number">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Load</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($job_no): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-list"></i> Bill Items</div>
        <div class="card-body">
            <?php if (count($items) > 0): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tab Type</th>
                            <th>Name</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['tab_type']) ?></td>
                            <td><?= htmlspecialchars($row['name'] ?? 'N/A') ?></td>
                            <td><?= number_format($row['qty'] ?? 0) ?></td>
                            <td>Rs. <?= number_format($row['rate'] ?? 0, 2) ?></td>
                            <td class="amount">Rs. <?= number_format(($row['amount'] + $row['sub_total']), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total">
                            <td colspan="4" class="text-end fw-bold">Grand Total</td>
                            <td class="amount fw-bold">Rs. <?= number_format($grand_total, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted text-center py-3">No unbilled items found for this job.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($items) > 0): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-receipt"></i> Post Bill</div>
        <div class="card-body">
            <form method="POST" action="post_bill.php">
                <input type="hidden" name="job_no" value="<?= htmlspecialchars($job_no) ?>">
                <input type="hidden" name="grand_total" value="<?= $grand_total ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Bill Type</label>
                        <input type="text" name="bill_type" class="form-control" placeholder="e.g., stitching, embroidery" required>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-check-circle"></i> POST BILL</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>