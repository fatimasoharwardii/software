<?php
$page_identifier = 'stitching/edit_stitching_billing.php';
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
$tables = ['stitching_bill_items', 'jobs', 'parties', 'stitching_posted_bills'];
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
    header("Location: list.php");
    exit;
}

// Fetch entry details with company check
$stmt = $conn->prepare("SELECT sbi.*, j.job_no, j.design_name, j.size, j.quantity 
                        FROM stitching_bill_items sbi
                        LEFT JOIN jobs j ON sbi.job_no = j.job_no
                        WHERE sbi.id = ? AND sbi.company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$entry) {
    header("Location: list.php");
    exit;
}

// Get existing quantities by tab for this job (excluding current entry) with company filter
$existing_tab_stmt = $conn->prepare("SELECT tab_type, SUM(qty) as total_qty 
                                     FROM stitching_bill_items 
                                     WHERE job_no = ? AND tab_type != 'stitching_depart' AND id != ? AND company_id = ?
                                     GROUP BY tab_type");
$existing_tab_stmt->bind_param("sii", $entry['job_no'], $id, $company_id);
$existing_tab_stmt->execute();
$tab_result = $existing_tab_stmt->get_result();
$existing_quantities = [];
while ($row = $tab_result->fetch_assoc()) {
    $existing_quantities[$row['tab_type']] = intval($row['total_qty']);
}
$existing_tab_stmt->close();

// Fetch all parties for suggestions (company filtered)
$parties_stmt = $conn->prepare("SELECT account_name FROM accounts WHERE company_id = ? ORDER BY account_name ASC");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties = $parties_stmt->get_result();

// Helper function to update stitching posted bill for a specific vendor (company‑aware)
function updateStitchingPostedBillForVendor($conn, $job_no, $vendor_name, $tab_type, $company_id) {
    // Get total amount and quantity for this vendor and job (company filtered)
    $total_stmt = $conn->prepare("SELECT SUM(amount) as total, SUM(qty) as total_qty 
                                  FROM stitching_bill_items 
                                  WHERE job_no = ? AND name = ? AND tab_type = ? AND company_id = ?");
    $total_stmt->bind_param("sssi", $job_no, $vendor_name, $tab_type, $company_id);
    $total_stmt->execute();
    $total_r = $total_stmt->get_result()->fetch_assoc();
    $total_stmt->close();
    $total_amount = $total_r['total'] ?? 0;
    $total_qty = $total_r['total_qty'] ?? 0;
    $avg_rate = ($total_qty > 0) ? ($total_amount / $total_qty) : 0;

    // Get job details for posted bill (company filtered)
    $job_stmt = $conn->prepare("SELECT serial_no, design_name, fabric_name, size, brand_name FROM jobs WHERE job_no = ? AND company_id = ?");
    $job_stmt->bind_param("si", $job_no, $company_id);
    $job_stmt->execute();
    $job_details = $job_stmt->get_result()->fetch_assoc();
    $job_stmt->close();
    $serial_no = $job_details['serial_no'] ?? 'SERIAL-' . date('Ymd');

    // Check if record exists for this vendor (company filtered)
    $check_bill = $conn->prepare("SELECT id FROM stitching_posted_bills WHERE job_no = ? AND emp_name = ? AND company_id = ?");
    $check_bill->bind_param("ssi", $job_no, $vendor_name, $company_id);
    $check_bill->execute();
    $exists = $check_bill->get_result()->num_rows > 0;
    $check_bill->close();

    if ($exists) {
        $update_bill = $conn->prepare("UPDATE stitching_posted_bills SET 
            total_amount = ?, qty = ?, rate = ?, post_date = CURDATE(), status = 'pending'
            WHERE job_no = ? AND emp_name = ? AND company_id = ?");
        $update_bill->bind_param("ddsssi", $total_amount, $total_qty, $avg_rate, $job_no, $vendor_name, $company_id);
        return $update_bill->execute();
    } else {
        $insert_bill = $conn->prepare("INSERT INTO stitching_posted_bills 
            (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, 
             claim_type, claim_date, design_name, fabric_name, size, brand_name, status, 
             manual_total, auto_total, difference_total, post_date, company_id) 
            VALUES (?, ?, ?, 'Stitching Charges', ?, ?, ?, ?, 'Stitching Bill', CURDATE(), ?, ?, ?, ?, 'pending', ?, 0, ?, CURDATE(), ?)");
        $desc = "Stitching bill for job $job_no - Vendor: $vendor_name";
        $manual_total = $total_amount;
        $difference_total = $total_amount; // auto_total = 0
        $insert_bill->bind_param("sssdddsdssssdddi", 
            $job_no, $serial_no, $vendor_name, $total_qty, $avg_rate, $total_amount, $desc,
            $job_details['design_name'] ?? '', $job_details['fabric_name'] ?? '', 
            $job_details['size'] ?? '', $job_details['brand_name'] ?? '',
            $manual_total, $difference_total, $company_id);
        return $insert_bill->execute();
    }
}

// Handle update
if (isset($_POST['update'])) {
    $job_no = trim($_POST['job_no'] ?? '');
    $tab_type = trim($_POST['tab_type'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $qty = floatval($_POST['qty'] ?? 0);
    $rate = floatval($_POST['rate'] ?? 0);
    $amount = $qty * $rate;

    $old_name = $entry['name'];
    $old_qty = $entry['qty'];
    $old_rate = $entry['rate'];

    // Verify party exists in accounts (company filtered)
    $party_check = $conn->prepare("SELECT id FROM accounts WHERE account_name = ? AND company_id = ?");
    $party_check->bind_param("si", $name, $company_id);
    $party_check->execute();
    if ($party_check->get_result()->num_rows == 0) {
        $error = "Party '$name' does not exist. Please add it first in Parties section.";
    } else {
        // Check quantity limit
        $job_qty = intval($entry['quantity'] ?? 0);
        $existing_qty = intval($existing_quantities[$tab_type] ?? 0);
        $total_qty = $existing_qty + $qty;

        if ($job_qty > 0 && $total_qty > $job_qty) {
            $error = "Quantity limit exceeded! Job total: $job_qty pcs, Existing in $tab_type: $existing_qty pcs, New: $qty pcs would make $total_qty pcs.";
        } else {
            // Update the item (with company check)
            $update_stmt = $conn->prepare("UPDATE stitching_bill_items SET
                       name = ?, qty = ?, rate = ?, amount = ?
                       WHERE id = ? AND company_id = ?");
            // FIXED: type string must match 6 bound variables
            $update_stmt->bind_param("sdddii", $name, $qty, $rate, $amount, $id, $company_id);
            if ($update_stmt->execute()) {
                // Update posted bills for both old and new vendor names
                if ($old_name != $name) {
                    updateStitchingPostedBillForVendor($conn, $job_no, $old_name, $tab_type, $company_id);
                    updateStitchingPostedBillForVendor($conn, $job_no, $name, $tab_type, $company_id);
                } else {
                    updateStitchingPostedBillForVendor($conn, $job_no, $name, $tab_type, $company_id);
                }

                $_SESSION['success_msg'] = "Entry updated successfully!";
                header("Location: list.php");
                exit;
            } else {
                $error = "Error updating entry: " . $update_stmt->error;
            }
            $update_stmt->close();
        }
    }
    $party_check->close();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Stitching Entry</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original) */
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #B26000;
        --border: #E5E7E9;
        --bg-light: #F8F9F9;
        --text-dark: #2C3E50;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background-color: #f5f5f5;
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
        font-size: 2rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--text-dark);
        margin: 0;
    }

    .page-header h2 i {
        color: var(--primary);
    }

    .card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow: hidden;
        margin-bottom: 20px;
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
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--primary);
    }

    .card-body {
        padding: 24px;
    }

    .form-label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 6px;
    }

    .form-label i {
        color: var(--primary);
        width: 20px;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid var(--border);
        border-radius: 6px;
        font-size: 0.95rem;
        transition: all 0.2s;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
    }

    .form-control[readonly] {
        background: var(--bg-light);
        border-color: var(--border);
    }

    .form-control.qty-exceed {
        border-color: var(--danger);
        background-color: #fee;
    }

    .job-info {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 24px;
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 0.75rem;
        color: #666;
        text-transform: uppercase;
    }

    .info-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--primary);
    }

    .type-badge {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 30px;
        font-size: 0.9rem;
        font-weight: 600;
        background: var(--primary-light);
        color: var(--primary-dark);
        margin-bottom: 20px;
    }

    .qty-summary {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 12px 15px;
        margin-bottom: 20px;
    }

    .qty-summary h5 {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--primary);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .qty-summary h5 i {
        color: var(--primary);
    }

    .qty-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 6px;
    }

    .qty-item {
        background: white;
        border: 1px solid var(--border);
        border-radius: 4px;
        padding: 4px 8px;
        font-size: 0.8rem;
        display: flex;
        justify-content: space-between;
    }

    .qty-item .label {
        font-weight: 500;
    }

    .qty-item .value {
        font-weight: 600;
        color: var(--primary);
    }

    .qty-item .value.warning {
        color: var(--danger);
    }

    .warning-message {
        background: #fee;
        border: 1px solid #fcc;
        color: #c00;
        padding: 8px 12px;
        border-radius: 4px;
        margin: 15px 0;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .warning-message i {
        color: #c00;
    }

    .btn {
        padding: 10px 24px;
        font-size: 0.95rem;
        font-weight: 600;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #95a5a6;
        color: white;
    }

    .btn-secondary:hover {
        background: #7f8c8d;
        transform: translateY(-2px);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #c0392b;
        transform: translateY(-2px);
    }

    .btn:disabled {
        background: #ccc;
        cursor: not-allowed;
        opacity: 0.6;
        transform: none;
    }

    .row {
        display: flex;
        flex-wrap: wrap;
        margin: -8px;
    }

    .col-md-3, .col-md-4, .col-md-6, .col-md-12 {
        padding: 8px;
    }

    .col-md-3 { width: 25%; }
    .col-md-4 { width: 33.333%; }
    .col-md-6 { width: 50%; }
    .col-md-12 { width: 100%; }

    .alert {
        padding: 12px 20px;
        border-radius: 6px;
        margin-bottom: 20px;
    }

    .alert-success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .alert-danger {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .alert-warning {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        color: #856404;
    }

    .total-display {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 15px;
        margin-top: 15px;
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--success);
    }

    .total-display i {
        color: var(--primary);
        margin-right: 8px;
    }

    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; }
    }

    @media (max-width: 900px) {
        .main-container { margin-left: 0; padding: 16px; }
        .col-md-3, .col-md-4, .col-md-6 { width: 100%; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .btn { width: 100%; }
        .job-info { flex-direction: column; gap: 15px; }
        .qty-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-edit"></i> Edit Stitching Entry #<?= $id ?></h2>
        <a href="list.php?job_no=<?= urlencode($entry['job_no']) ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-info-circle"></i> Entry Details</h4>
        </div>
        <div class="card-body">
            <!-- Job Info -->
            <div class="job-info">
                <div class="info-item">
                    <span class="info-label">Job No</span>
                    <span class="info-value"><?= htmlspecialchars($entry['job_no']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Design</span>
                    <span class="info-value"><?= htmlspecialchars($entry['design_name'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Size</span>
                    <span class="info-value"><?= htmlspecialchars($entry['size'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total Job Qty</span>
                    <span class="info-value"><?= $entry['quantity'] ?? 'N/A' ?></span>
                </div>
            </div>

            <!-- Quantity Summary -->
            <?php if (!empty($existing_quantities)): ?>
            <div class="qty-summary">
                <h5><i class="fas fa-chart-pie"></i> Current Quantities by Tab (excluding this entry)</h5>
                <div class="qty-grid">
                    <?php foreach ($existing_quantities as $tab => $qty): ?>
                    <div class="qty-item">
                        <span class="label"><?= ucwords(str_replace('_', ' ', $tab)) ?>:</span>
                        <span class="value"><?= $qty ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Type Badge -->
            <div class="type-badge">
                <i class="fas fa-tag"></i> 
                <?= ucwords(str_replace('_', ' ', $entry['tab_type'])) ?>
            </div>

            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="job_no" value="<?= htmlspecialchars($entry['job_no']) ?>">
                <input type="hidden" name="tab_type" value="<?= htmlspecialchars($entry['tab_type']) ?>">

                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-user"></i> Name *</label>
                        <input type="text" name="name" class="form-control" list="partyList" 
                               value="<?= htmlspecialchars($entry['name']) ?>" required>
                        <small class="text-muted">Must exist in Parties</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-sort-numeric-up"></i> Quantity</label>
                        <input type="number" step="1" min="0" name="qty" class="form-control" 
                               id="qtyInput" value="<?= $entry['qty'] ?>" oninput="checkQty(); calculateTotal()" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> Rate</label>
                        <input type="number" step="0.01" min="0" name="rate" class="form-control" 
                               id="rateInput" value="<?= $entry['rate'] ?>" oninput="calculateTotal()" required>
                    </div>
                </div>

                <!-- Warning Message -->
                <div id="warningMessage" class="warning-message" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span></span>
                </div>

                <!-- Total Display -->
                <div class="total-display">
                    <i class="fas fa-calculator"></i>
                    Total Amount: Rs. <span id="totalAmount"><?= number_format($entry['amount'] ?? 0, 2) ?></span>
                </div>

                <!-- Form Buttons -->
                <div class="mt-4 d-flex gap-2 flex-wrap">
                    <button type="submit" name="update" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Update Entry
                    </button>
                    <a href="list.php?job_no=<?= urlencode($entry['job_no']) ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <a href="delete_stitching_entry.php?id=<?= $id ?>" class="btn btn-danger ms-auto" 
                       onclick="return confirm('Are you sure you want to delete this entry? This action cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete Entry
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="partyList">
    <?php while ($party = $parties->fetch_assoc()): ?>
    <option value="<?= htmlspecialchars($party['account_name']) ?>">
    <?php endwhile; ?>
</datalist>

<script>
const jobQty = <?= intval($entry['quantity'] ?? 0) ?>;
const tabType = '<?= $entry['tab_type'] ?>';
const existingQty = <?= intval($existing_quantities[$entry['tab_type']] ?? 0) ?>;

function checkQty() {
    const newQty = parseFloat(document.getElementById('qtyInput').value) || 0;
    const totalQty = existingQty + newQty;
    
    const warningDiv = document.getElementById('warningMessage');
    const warningSpan = warningDiv.querySelector('span');
    const saveBtn = document.getElementById('saveBtn');
    const qtyInput = document.getElementById('qtyInput');
    
    if (jobQty > 0 && totalQty > jobQty) {
        warningSpan.innerHTML = `⚠️ Quantity limit exceeded! Job total: ${jobQty} pcs, Existing in this tab: ${existingQty} pcs, New: ${newQty} pcs would make ${totalQty} pcs.`;
        warningDiv.style.display = 'flex';
        saveBtn.disabled = true;
        qtyInput.classList.add('qty-exceed');
        return false;
    } else if (newQty < 0) {
        warningSpan.innerHTML = `⚠️ Quantity cannot be negative`;
        warningDiv.style.display = 'flex';
        saveBtn.disabled = true;
        qtyInput.classList.add('qty-exceed');
        return false;
    } else {
        warningDiv.style.display = 'none';
        saveBtn.disabled = false;
        qtyInput.classList.remove('qty-exceed');
        return true;
    }
}

function calculateTotal() {
    const qty = parseFloat(document.getElementById('qtyInput').value) || 0;
    const rate = parseFloat(document.getElementById('rateInput').value) || 0;
    const total = qty * rate;
    document.getElementById('totalAmount').textContent = total.toFixed(2);
}

function validateForm() {
    const newQty = parseFloat(document.getElementById('qtyInput').value) || 0;
    const totalQty = existingQty + newQty;
    
    if (jobQty > 0 && totalQty > jobQty) {
        alert(`Quantity limit exceeded! Job total: ${jobQty} pcs, Existing in this tab: ${existingQty} pcs, New: ${newQty} pcs would make ${totalQty} pcs.`);
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();
    checkQty();
});
</script>

</body>
</html>