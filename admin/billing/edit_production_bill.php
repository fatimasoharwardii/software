<?php
$page_identifier = 'billing/edit_production_bill.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) {
    header("Location: production_bill_list.php");
    exit;
}

// Fetch the production bill entry
$stmt = $conn->prepare("SELECT sbi.*, j.design_name, j.size, j.fabric_name, j.quantity AS job_qty
                        FROM stitching_bill_items sbi
                        LEFT JOIN jobs j ON sbi.job_no = j.job_no AND j.company_id = sbi.company_id
                        WHERE sbi.id = ? AND sbi.company_id = ? AND sbi.tab_type = 'production_bill'");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$entry) {
    $_SESSION['error_msg'] = "Record not found.";
    header("Location: production_bill_list.php");
    exit;
}

// --- Calculate remaining allowed quantity ---
$job_no = $entry['job_no'];
$job_qty = floatval($entry['job_qty'] ?? 0);

// Claims from claims table
$claim_stmt = $conn->prepare("SELECT SUM(qty) as total_claim FROM claims WHERE job_no = ? AND company_id = ?");
$claim_stmt->bind_param("si", $job_no, $company_id);
$claim_stmt->execute();
$claim_result = $claim_stmt->get_result()->fetch_assoc();
$claim_qty = floatval($claim_result['total_claim'] ?? 0);
$claim_stmt->close();

// Other production bill items for the same job (excluding current)
$other_bill_stmt = $conn->prepare("SELECT SUM(qty) as other_qty FROM stitching_bill_items WHERE job_no = ? AND company_id = ? AND tab_type = 'production_bill' AND id != ?");
$other_bill_stmt->bind_param("sii", $job_no, $company_id, $id);
$other_bill_stmt->execute();
$other_result = $other_bill_stmt->get_result()->fetch_assoc();
$other_bill_qty = floatval($other_result['other_qty'] ?? 0);
$other_bill_stmt->close();

// Remaining quantity that can be assigned to this entry
$max_allowed = $job_qty - ($claim_qty + $other_bill_qty);
if ($max_allowed < 0) $max_allowed = 0;

// Handle update
if (isset($_POST['update'])) {
    $bill_no   = $conn->real_escape_string(trim($_POST['bill_no'] ?? ''));
    $name      = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $qty       = floatval($_POST['qty'] ?? 0);
    $rate      = floatval($_POST['rate'] ?? 0);
    $amount    = $qty * $rate;

    if ($qty > $max_allowed) {
        $error = "Quantity exceeds remaining limit! Max allowed: $max_allowed pcs.";
    } else {
        $update_sql = "UPDATE stitching_bill_items SET
                        bill_no = '$bill_no',
                        name = '$name',
                        qty = $qty,
                        rate = $rate,
                        amount = $amount
                        WHERE id = $id AND company_id = $company_id AND tab_type = 'production_bill'";

        if ($conn->query($update_sql)) {
            // Update stitching_posted_bills if present
            $check_posted = $conn->query("SELECT id FROM stitching_posted_bills 
                                         WHERE job_no = '$job_no' AND emp_name = '{$entry['name']}' AND company_id = $company_id");
            if ($check_posted && $check_posted->num_rows > 0) {
                $conn->query("UPDATE stitching_posted_bills SET
                    total_amount = $amount, qty = $qty, rate = $rate, user_id = $user_id
                    WHERE job_no = '$job_no' AND emp_name = '{$entry['name']}' AND company_id = $company_id");
            }

            $_SESSION['success_msg'] = "Production bill updated successfully.";
            header("Location: production_bill_list.php");
            exit;
        } else {
            $error = "Update failed: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Production Bill #<?= $id ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .main-container { margin-left: 14%; padding: 24px 32px; }
        .invoice-card { max-width: 1200px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .invoice-header { background: linear-gradient(135deg, #F39C12, #E67E22); color: white; padding: 30px; text-align: center; }
        .invoice-body { padding: 30px; }
        .invoice-info { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
        .info-box { background: #FEF5E7; padding: 12px 20px; border-radius: 12px; flex: 1; min-width: 180px; }
        .info-box .label { font-size: 0.7rem; text-transform: uppercase; color: #666; }
        .info-box .value input { border: none; background: transparent; font-weight: bold; width: 100%; font-size: 1rem; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.85rem; }
        .items-table th, .items-table td { padding: 10px 8px; border-bottom: 1px solid #e0e0e0; vertical-align: middle; }
        .items-table th { background: #FEF5E7; font-weight: 600; }
        .items-table input { width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 0.85rem; }
        .qty-exceed { border-color: #e74c3c !important; background-color: #ffe6e6 !important; }
        .totals { background: #FEF5E7; padding: 20px; border-radius: 12px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; }
        .grand { border-top: 2px solid #F39C12; margin-top: 10px; padding-top: 15px; font-size: 1.2rem; font-weight: bold; }
        .warning-message { background: #fee; border: 1px solid #fcc; color: #c00; padding: 8px 12px; border-radius: 4px; margin: 10px 0; display: none; }
        .action-buttons { display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
        .btn-primary { background: #F39C12; color: white; }
        .btn-primary:hover { background: #B26000; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        @media (max-width: 992px) { .main-container { margin-left: 0; margin-top: 60px; } }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="invoice-card">
        <div class="invoice-header">
            <h2><i class="fas fa-edit"></i> Edit Production Bill #<?= $id ?></h2>
            <p><?= htmlspecialchars($entry['job_no']) ?> - <?= htmlspecialchars($entry['design_name'] ?? 'N/A') ?></p>
        </div>
        <div class="invoice-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <div class="invoice-info">
                <div class="info-box"><div class="label">Invoice No</div><div class="value"><input type="text" name="bill_no" id="bill_no" form="editForm" value="<?= htmlspecialchars($entry['bill_no'] ?? '') ?>"></div></div>
                <div class="info-box"><div class="label">Date</div><div class="value"><input type="date" name="billing_date" id="billing_date" form="editForm" value="<?= date('Y-m-d', strtotime($entry['created_at'])) ?>"></div></div>
                <div class="info-box"><div class="label">Customer / Vendor</div><div class="value"><input type="text" name="name" id="name" form="editForm" value="<?= htmlspecialchars($entry['name'] ?? '') ?>"></div></div>
            </div>

            <!-- Quantity limits info -->
            <div style="background:#F8F9F9; border:1px solid #E5E7E9; border-radius:8px; padding:15px; margin-bottom:20px;">
                <div class="row">
                    <div class="col-md-3"><strong>Job Total:</strong> <?= number_format($job_qty) ?> pcs</div>
                    <div class="col-md-3"><strong>Already Claimed:</strong> <?= number_format($claim_qty) ?> pcs</div>
                    <div class="col-md-3"><strong>Other Bill Qty:</strong> <?= number_format($other_bill_qty) ?> pcs</div>
                    <div class="col-md-3"><strong>Remaining for this entry:</strong> <span style="color:#F39C12;"><?= number_format($max_allowed) ?> pcs</span></div>
                </div>
            </div>

            <form method="POST" id="editForm">
                <input type="hidden" name="update" value="1">

                <table class="items-table">
                    <thead>
                        <tr><th>Job No</th><th>Design</th><th>Size</th><th>Fabric</th><th>Qty</th><th>Rate (Rs/pc)</th><th>Total (Rs)</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($entry['job_no']) ?></td>
                            <td><?= htmlspecialchars($entry['design_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($entry['size'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($entry['fabric_name'] ?? '-') ?></td>
                            <td><input type="number" step="1" min="0" name="qty" id="qtyInput" value="<?= $entry['qty'] ?>" oninput="checkQty(); updateTotal();" class="<?= ($entry['qty'] > $max_allowed) ? 'qty-exceed' : '' ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="rate" id="rateInput" value="<?= $entry['rate'] ?>" oninput="updateTotal();"></td>
                            <td><span id="totalAmount"><?= number_format($entry['amount'], 2) ?></span></td>
                        </tr>
                    </tbody>
                </table>

                <div id="warningMessage" class="warning-message" style="<?= ($entry['qty'] > $max_allowed) ? 'display:block;' : '' ?>">
                    <i class="fas fa-exclamation-triangle"></i> Quantity exceeds remaining limit! Max allowed: <strong><?= number_format($max_allowed) ?> pcs</strong>
                </div>

                <div class="totals">
                    <div class="total-row grand"><strong>Grand Total:</strong> <strong id="grandTotal"><?= number_format($entry['amount'], 2) ?></strong></div>
                </div>
            </form>

            <div class="action-buttons">
                <a href="production_bill_list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
                <button type="submit" form="editForm" class="btn btn-primary" id="saveBtn" <?= ($entry['qty'] > $max_allowed) ? 'disabled' : '' ?>>
                    <i class="fas fa-save"></i> Update Bill
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const maxAllowed = <?= $max_allowed ?>;

function checkQty() {
    let qty = parseFloat(document.getElementById('qtyInput').value) || 0;
    let warning = document.getElementById('warningMessage');
    let saveBtn = document.getElementById('saveBtn');
    let qtyInput = document.getElementById('qtyInput');

    if (qty > maxAllowed) {
        warning.style.display = 'block';
        warning.querySelector('strong').textContent = maxAllowed + ' pcs';
        saveBtn.disabled = true;
        qtyInput.classList.add('qty-exceed');
    } else {
        warning.style.display = 'none';
        saveBtn.disabled = false;
        qtyInput.classList.remove('qty-exceed');
    }
}

function updateTotal() {
    let qty = parseFloat(document.getElementById('qtyInput').value) || 0;
    let rate = parseFloat(document.getElementById('rateInput').value) || 0;
    let total = qty * rate;
    document.getElementById('totalAmount').textContent = total.toFixed(2);
    document.getElementById('grandTotal').textContent = total.toFixed(2);
}
</script>
</body>
</html>