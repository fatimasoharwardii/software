<?php
$page_identifier = 'billing/view_bill.php';
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
$tables = ['stitching_posted_bills', 'stitching_bill_items'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: post_bill.php");
    exit;
}

// Fetch bill details with company filter
$stmt = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

if (!$bill) {
    header("Location: post_bill.php");
    exit;
}

// Fetch all stitching bill items for this job (they also have company_id, but job_no is enough because bill already filtered)
$stmt2 = $conn->prepare("SELECT * FROM stitching_bill_items WHERE job_no = ? AND company_id = ? ORDER BY created_at DESC");
$stmt2->bind_param("si", $bill['job_no'], $company_id);
$stmt2->execute();
$items_result = $stmt2->get_result();
$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Calculate totals
$total_qty = 0;
$total_amount = 0;
foreach ($items as $item) {
    $total_qty += floatval($item['qty']);
    $total_amount += floatval($item['amount']);
}
?>
<!DOCTYPE html>
<html>
<head>
<title>View Bill - <?= htmlspecialchars($bill['job_no']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-dark: #E67E22;
        --primary-light: #FEF5E7;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
        --info: #3498db;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --bg-light: #F8F9FA;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        min-height: 100vh;
        padding: 30px;
    }

    .bill-container {
        max-width: 1200px;
        margin-left:16%;
    }

    .bill-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        overflow: hidden;
        margin-bottom: 30px;
    }

    .bill-header {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        padding: 25px 30px;
        color: white;
        position: relative;
    }

    .bill-header h2 {
        font-size: 1.8rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .bill-header h2 i {
        font-size: 2rem;
    }

    .bill-status {
        position: absolute;
        top: 25px;
        right: 30px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 20px;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 600;
        background: rgba(255,255,255,0.2);
        backdrop-filter: blur(5px);
    }

    .status-badge.posted {
        background: rgba(39,174,96,0.9);
    }

    .status-badge.pending {
        background: rgba(243,156,18,0.9);
    }

    .bill-body {
        padding: 30px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--border);
    }

    .info-item {
        background: var(--bg-light);
        padding: 12px 18px;
        border-radius: 12px;
        border-left: 4px solid var(--primary);
    }

    .info-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.5px;
        margin-bottom: 5px;
    }

    .info-label i {
        color: var(--primary);
        margin-right: 5px;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .info-value.amount {
        font-size: 1.3rem;
        color: var(--primary-dark);
    }

    .section-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin: 25px 0 15px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--primary);
        display: inline-block;
    }

    .section-title i {
        color: var(--primary);
        margin-right: 8px;
    }

    .items-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        margin-top: 15px;
    }

    .items-table th {
        background: var(--bg-light);
        padding: 12px 15px;
        text-align: left;
        border-bottom: 2px solid var(--primary);
        font-weight: 700;
        color: var(--text-dark);
    }

    .items-table td {
        padding: 10px 15px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .items-table tr:hover {
        background: var(--primary-light);
    }

    .type-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        background: var(--primary-light);
        color: var(--primary-dark);
    }

    .totals-row {
        background: var(--primary-light);
        font-weight: 700;
    }

    .totals-row td {
        border-top: 2px solid var(--primary);
        padding: 12px 15px;
    }

    .summary-box {
        background: linear-gradient(135deg, var(--primary-light) 0%, #fff 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        border: 1px solid var(--primary);
    }

    .summary-item {
        text-align: center;
        flex: 1;
        min-width: 150px;
    }

    .summary-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 5px;
    }

    .summary-value {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--primary-dark);
    }

    .action-buttons {
        display: flex;
        gap: 15px;
        margin-top: 25px;
        justify-content: flex-end;
    }

    .btn {
        padding: 10px 24px;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-warning {
        background: var(--warning);
        color: white;
    }

    @media print {
        body {
            background: white;
            padding: 0;
        }
        .no-print {
            display: none !important;
        }
        .bill-card {
            box-shadow: none;
            margin: 0;
        }
        .bill-header {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .status-badge {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .btn {
            display: none;
        }
        .action-buttons {
            display: none;
        }
    }

    @media (max-width: 768px) {
        body {
            padding: 15px;
        }
        .bill-header h2 {
            font-size: 1.2rem;
        }
        .bill-status {
            position: static;
            margin-top: 15px;
        }
        .bill-header {
            flex-direction: column;
            text-align: center;
        }
        .info-grid {
            grid-template-columns: 1fr;
        }
        .summary-box {
            flex-direction: column;
        }
        .summary-item {
            width: 100%;
        }
        .items-table {
            font-size: 0.7rem;
        }
        .items-table th,
        .items-table td {
            padding: 6px 8px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .action-buttons .btn {
            justify-content: center;
        }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="bill-container">
    <div class="bill-card">
        <div class="bill-header">
            <h2>
                <i class="fas fa-file-invoice"></i>
                STITCHING BILL
            </h2>
            <div class="bill-status">
                <span class="status-badge <?= $bill['status'] == 'posted' ? 'posted' : 'pending' ?>">
                    <i class="fas <?= $bill['status'] == 'posted' ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                    <?= ucfirst($bill['status'] ?? 'Pending') ?>
                </span>
            </div>
        </div>

        <div class="bill-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-hashtag"></i> Bill ID</div>
                    <div class="info-value">#<?= $bill['id'] ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-barcode"></i> Job No</div>
                    <div class="info-value"><?= htmlspecialchars($bill['job_no'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-qrcode"></i> Serial No</div>
                    <div class="info-value"><?= htmlspecialchars($bill['serial_no'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-user"></i> Party Name</div>
                    <div class="info-value"><?= htmlspecialchars($bill['emp_name'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-tag"></i> Bill Type</div>
                    <div class="info-value"><?= htmlspecialchars($bill['claim_type'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-calendar"></i> Bill Date</div>
                    <div class="info-value"><?= date('d-m-Y', strtotime($bill['claim_date'])) ?></div>
                </div>
                <?php if($bill['post_date'] && $bill['post_date'] != '0000-00-00'): ?>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-calendar-check"></i> Posted Date</div>
                    <div class="info-value"><?= date('d-m-Y', strtotime($bill['post_date'])) ?></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-palette"></i> Design</div>
                    <div class="info-value"><?= htmlspecialchars($bill['design_name'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-cut"></i> Fabric</div>
                    <div class="info-value"><?= htmlspecialchars($bill['fabric_name'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-ruler"></i> Size</div>
                    <div class="info-value"><?= htmlspecialchars($bill['size'] ?? '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-trademark"></i> Brand</div>
                    <div class="info-value"><?= htmlspecialchars($bill['brand_name'] ?? '-') ?></div>
                </div>
            </div>

            <div class="section-title">
                <i class="fas fa-list-ul"></i> Stitching Bill Items
            </div>

            <div class="table-responsive">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Vendor Name</th>
                            <th>Kurti</th>
                            <th>Shalwar</th>
                            <th>Dupatta</th>
                            <th>Total Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        $total_qty_sum = 0;
                        $total_amount_sum = 0;
                        foreach ($items as $item): 
                            $kurti_qty = floatval($item['kurti_qty'] ?? 0);
                            $shalwar_qty = floatval($item['shalwar_qty'] ?? 0);
                            $dupatta_qty = floatval($item['dupatta_qty'] ?? 0);
                            $item_qty = $kurti_qty + $shalwar_qty + $dupatta_qty;
                            if ($item_qty == 0) $item_qty = floatval($item['qty'] ?? 0);
                            
                            $total_qty_sum += $item_qty;
                            $total_amount_sum += floatval($item['amount'] ?? 0);
                        ?>
                        <tr>
                            <td><?= $sn++ ?></td>
                            <td><?= date('d-m-Y', strtotime($item['created_at'])) ?></td>
                            <td><span class="type-badge"><?= ucwords(str_replace('_', ' ', $item['tab_type'] ?? 'Stitching')) ?></span></td>
                            <td><strong><?= htmlspecialchars($item['name'] ?? '-') ?></strong></td>
                            <td><?= $kurti_qty > 0 ? number_format($kurti_qty) : '-' ?></td>
                            <td><?= $shalwar_qty > 0 ? number_format($shalwar_qty) : '-' ?></td>
                            <td><?= $dupatta_qty > 0 ? number_format($dupatta_qty) : '-' ?></td>
                            <td><?= number_format($item_qty, 0) ?></td>
                            <td>Rs <?= number_format(floatval($item['rate'] ?? 0), 2) ?></td>
                            <td class="amount">Rs <?= number_format(floatval($item['amount'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                <i class="fas fa-inbox" style="font-size: 2rem; color: #ccc;"></i>
                                <p>No stitching items found for this job</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($items)): ?>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="7"><strong>TOTAL</strong></td>
                            <td><strong><?= number_format($total_qty_sum, 0) ?></strong></td>
                            <td>-</td>
                            <td><strong>Rs <?= number_format($total_amount_sum, 2) ?></strong></td>
                        </table>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <div class="section-title">
                <i class="fas fa-chart-line"></i> Costing Summary
            </div>

            <div class="summary-box">
                <div class="summary-item">
                    <div class="summary-label">Total Quantity</div>
                    <div class="summary-value"><?= number_format(floatval($bill['qty'] ?? $total_qty_sum), 0) ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Average Rate</div>
                    <div class="summary-value">Rs <?= number_format(floatval($bill['rate'] ?? 0), 2) ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Amount</div>
                    <div class="summary-value">Rs <?= number_format(floatval($bill['total_amount'] ?? $total_amount_sum), 2) ?></div>
                </div>
                <?php if (floatval($bill['manual_total'] ?? 0) > 0): ?>
                <div class="summary-item">
                    <div class="summary-label">Manual Total</div>
                    <div class="summary-value">Rs <?= number_format(floatval($bill['manual_total']), 2) ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Difference</div>
                    <div class="summary-value">Rs <?= number_format(floatval($bill['difference_total']), 2) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($bill['description'])): ?>
            <div style="margin-top: 20px; padding: 15px; background: var(--bg-light); border-radius: 10px;">
                <div class="info-label"><i class="fas fa-align-left"></i> Description</div>
                <div style="margin-top: 5px;"><?= nl2br(htmlspecialchars($bill['description'])) ?></div>
            </div>
            <?php endif; ?>

            <div class="action-buttons no-print">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Bill
                </button>
                <a href="post_bill.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php if ($bill['status'] != 'posted'): ?>
                <a href="post_bill.php?action=post&id=<?= $bill['id'] ?>" class="btn btn-success" onclick="return confirm('Are you sure you want to post this bill?')">
                    <i class="fas fa-check"></i> Post Bill
                </a>
                <?php else: ?>
                <a href="post_bill.php?action=unpost&id=<?= $bill['id'] ?>" class="btn btn-warning" onclick="return confirm('Are you sure you want to unpost this bill?')">
                    <i class="fas fa-undo"></i> Unpost Bill
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>