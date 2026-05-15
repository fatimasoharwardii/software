<?php
$page_identifier = 'fabric/purchase_add.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id']; // ✅ Added for foreign key

// Ensure required tables have company_id column
$tables = ['fabric_purchase', 'parties', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Ensure built_num column exists
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS built_num VARCHAR(100)");

// Get last lot number for current company and generate next lot number
$last_lot_stmt = $conn->prepare("SELECT lot_no FROM fabric_purchase WHERE company_id = ? ORDER BY id DESC LIMIT 1");
$last_lot_stmt->bind_param("i", $company_id);
$last_lot_stmt->execute();
$last_lot = $last_lot_stmt->get_result()->fetch_assoc();

$next_lot_no = 'LOT-001';
if ($last_lot && !empty($last_lot['lot_no'])) {
    $last_lot_num = $last_lot['lot_no'];
    preg_match('/(\d+)$/', $last_lot_num, $matches);
    if (!empty($matches[1])) {
        $next_num = intval($matches[1]) + 1;
        $next_lot_no = 'LOT-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    }
}

// Get all fabrics for suggestions (only current company)
$fabrics_stmt = $conn->prepare("SELECT DISTINCT fabric_name FROM fabric_purchase WHERE fabric_name IS NOT NULL AND fabric_name != '' AND company_id = ? ORDER BY fabric_name");
$fabrics_stmt->bind_param("i", $company_id);
$fabrics_stmt->execute();
$fabrics_result = $fabrics_stmt->get_result();
$fabrics = [];
while($f = $fabrics_result->fetch_assoc()) {
    $fabrics[] = $f['fabric_name'];
}

// Get all parties (vendors) for suggestions (only current company)
$parties_stmt = $conn->prepare("SELECT party_name FROM parties WHERE party_type IN ('vendor', 'supplier') AND company_id = ? ORDER BY party_name");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties_result = $parties_stmt->get_result();
$parties = [];
while($p = $parties_result->fetch_assoc()) {
    $parties[] = $p['party_name'];
}

if(isset($_POST['save'])){
    $party      = trim($_POST['party_name'] ?? '');
    $fabric     = trim($_POST['fabric_name'] ?? '');
    $color      = trim($_POST['color'] ?? '');
    $lot        = trim($_POST['lot_no'] ?? '');
    $bill       = trim($_POST['bill_no'] ?? '');
    $challan    = trim($_POST['challan_no'] ?? '');
    $bundle     = trim($_POST['bundle_no'] ?? '');
    $built      = trim($_POST['built_num'] ?? '');
    $meter      = floatval($_POST['total_meter'] ?? 0);
    $rate       = floatval($_POST['rate'] ?? 0);
    $adjust     = floatval($_POST['adjust_rate'] ?? 0);
    $created_at = $_POST['created_at'] ?? date('Y-m-d');

    // Verify party exists and belongs to company
    $party_check = $conn->prepare("SELECT id, party_name FROM parties WHERE party_name = ? AND company_id = ?");
    $party_check->bind_param("si", $party, $company_id);
    $party_check->execute();
    $party_exists = $party_check->get_result()->num_rows > 0;

    if(!$party_exists) {
        $error = "Party '$party' does not exist! Please add this party first in the Parties section before creating a fabric purchase.";
    } else {
        $serial_no = 'FP-' . date('Ymd') . '-' . rand(100, 999);
        $claim_date = $created_at;
        $total_amount = $meter * $rate;  // Final amount = meter × rate (adjustment not added)
        $description = "Fabric Purchase - Party: $party, Fabric: $fabric, Lot: $lot, Meter: $meter";

        mysqli_begin_transaction($conn);
        try {
            // ✅ Insert into fabric_purchase (added user_id)
            $insert_purchase = $conn->prepare("INSERT INTO fabric_purchase
                (party_name, fabric_name, color, lot_no, bill_no, challan_no, bundle_no, built_num, total_meter, rate, adjust_rate, created_at, company_id, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_purchase->bind_param("ssssssssdddsii", 
                $party, $fabric, $color, $lot, $bill, $challan, $bundle, $built,
                $meter, $rate, $adjust, $created_at, $company_id, $user_id);
            if (!$insert_purchase->execute()) {
                throw new Exception("Error inserting purchase: " . $insert_purchase->error);
            }
            $purchase_id = $insert_purchase->insert_id;

            // ✅ Insert into stitching_posted_bills (added user_id)
            $insert_post = $conn->prepare("INSERT INTO stitching_posted_bills 
                (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, claim_type, claim_date, fabric_name, status, company_id, user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $job_no = "PURCHASE-$purchase_id";
            $claim_item = "Fabric Purchase";
            $status = "pending";
            $insert_post->bind_param("ssssddssssssii", 
                $job_no, $serial_no, $party, $claim_item, $meter, $rate, $total_amount,
                $description, $claim_item, $claim_date, $fabric, $status, $company_id, $user_id);
            if (!$insert_post->execute()) {
                throw new Exception("Error inserting post bill: " . $insert_post->error);
            }

            mysqli_commit($conn);
            header("Location: purchase_list.php?success=1");
            exit();
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Add Fabric Purchase</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original for responsiveness) */
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

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        min-height: 100vh;
    }

    .main-container {
        margin-left: 14%;
        padding: 24px 32px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .card {
        background: white;
        border: none;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .card-header {
        background: white;
        padding: 20px 28px;
        border-bottom: 2px solid var(--primary);
    }

    .card-header h4 {
        color: var(--primary-dark);
        font-size: 1.3rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h4 i {
        color: var(--primary);
        font-size: 1.4rem;
    }

    .card-body {
        padding: 28px;
    }

    .two-column-layout {
        display: flex;
        gap: 30px;
        flex-wrap: wrap;
    }

    .form-column {
        flex: 1.5;
        min-width: 280px;
    }

    .summary-column {
        flex: 1;
        min-width: 280px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .form-row .form-group {
        flex: 1;
        margin-bottom: 0;
        min-width: 140px;
    }

    label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    label i {
        color: var(--primary);
        width: 18px;
        margin-right: 4px;
    }

    .required::after {
        content: '*';
        color: var(--danger);
        margin-left: 4px;
    }

    .form-control {
        width: 100%;
        padding: 10px 14px;
        font-size: 0.85rem;
        border: 1px solid var(--border);
        border-radius: 12px;
        transition: all 0.2s;
        background: white;
    }

    .form-control:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .form-control.error {
        border-color: var(--danger);
        background-color: #fff5f5;
    }

    .summary-card {
        background: linear-gradient(135deg, var(--primary-light) 0%, #fff 100%);
        border-radius: 16px;
        padding: 20px;
        border: 1px solid rgba(243,156,18,0.2);
        position: sticky;
        top: 20px;
    }

    .summary-title {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--primary-dark);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(243,156,18,0.3);
    }

    .summary-title i {
        font-size: 1rem;
    }

    .summary-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px dashed var(--border);
    }

    .summary-item:last-child {
        border-bottom: none;
    }

    .summary-label {
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-muted);
    }

    .summary-value {
        font-size: 1rem;
        font-weight: 700;
        color: var(--primary-dark);
    }

    .summary-value.total {
        font-size: 1.2rem;
        color: var(--success);
    }

    .summary-value.adjust {
        color: var(--primary);
        font-size: 0.9rem;
    }

    .badge-info {
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .btn {
        padding: 10px 24px;
        font-size: 0.85rem;
        font-weight: 600;
        border: none;
        border-radius: 12px;
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
        box-shadow: 0 4px 12px rgba(243,156,18,0.3);
    }

    .btn-secondary {
        background: var(--bg-light);
        color: var(--text-dark);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--border);
        transform: translateY(-1px);
    }

    .btn-reset {
        background: #e9ecef;
        color: var(--text-dark);
    }

    .btn-reset:hover {
        background: #dee2e6;
    }

    .action-buttons {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .alert {
        padding: 14px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 0.85rem;
        border: none;
        animation: slideIn 0.3s ease;
    }

    .alert-danger {
        background: #fef5e7;
        color: #856404;
        border-left: 4px solid var(--danger);
    }

    .alert-danger i {
        font-size: 1.1rem;
        color: var(--danger);
    }

    @keyframes slideIn {
        from {
            transform: translateY(-20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .text-muted {
        font-size: 0.65rem;
        color: var(--text-muted);
        margin-top: 5px;
        display: block;
    }

    .party-warning {
        color: var(--danger);
        font-size: 0.7rem;
        margin-top: 5px;
        display: none;
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
        .two-column-layout {
            flex-direction: column;
            gap: 20px;
        }
        .summary-card {
            position: static;
        }
        .form-row {
            flex-direction: column;
            gap: 16px;
        }
        .form-row .form-group {
            width: 100%;
        }
        .action-buttons .btn {
            flex: 1;
        }
    }

    @media (max-width: 768px) {
        .card-body {
            padding: 20px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .action-buttons .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <?php if(isset($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h4>
                <i class="fas fa-plus-circle"></i>
                Add Fabric Purchase
            </h4>
        </div>
        
        <div class="card-body">
            <form method="POST" action="" id="purchaseForm">
                <div class="two-column-layout">
                    <!-- Left Column - Form Fields -->
                    <div class="form-column">
                        <!-- Row 1: Lot No and Date -->
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-layer-group"></i> Lot Number</label>
                                <input type="text" name="lot_no" class="form-control" value="<?= htmlspecialchars($next_lot_no) ?>" required style="font-weight: 600; background: var(--primary-light);">
                                <small class="text-muted"><i class="fas fa-history"></i> Next: <?= htmlspecialchars($next_lot_no) ?></small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Date</label>
                                <input type="date" name="created_at" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-user"></i> Party</label>
                                <input type="text" name="party_name" id="party_name" class="form-control" list="partyList" placeholder="Party name" required autocomplete="off">
                                <datalist id="partyList">
                                    <?php foreach($parties as $party): ?>
                                        <option value="<?= htmlspecialchars($party) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <div id="partyWarning" class="party-warning">
                                    <i class="fas fa-exclamation-triangle"></i> Party not found! Please add this party first in Parties section.
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-tshirt"></i> Fabric</label>
                                <input type="text" name="fabric_name" id="fabric_name" class="form-control" list="fabricList" placeholder="Fabric name" required autocomplete="off">
                                <datalist id="fabricList">
                                    <?php foreach($fabrics as $fabric): ?>
                                        <option value="<?= htmlspecialchars($fabric) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-palette"></i> Color</label>
                                <input type="number" name="color" class="form-control" placeholder="Color">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-receipt"></i> Bill No</label>
                                <input type="number" name="bill_no" class="form-control" placeholder="Bill number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-truck"></i> Challan</label>
                                <input type="number" name="challan_no" class="form-control" placeholder="Challan number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-cubes"></i> Bundle</label>
                                <input type="number" name="bundle_no" class="form-control" placeholder="Bundle number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-cog"></i> Built</label>
                                <input type="text" name="built_num" class="form-control" placeholder="Built number">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-ruler"></i> Meter</label>
                                <input type="number" step="0.01" name="total_meter" id="total_meter" class="form-control" placeholder="0.00" required oninput="calculateTotals()">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-tag"></i> Rate</label>
                                <input type="number" step="0.01" name="rate" id="rate" class="form-control" placeholder="0.00" required oninput="calculateTotals()">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-adjust"></i> Adjustment (Info Only)</label>
                                <input type="number" step="1" name="adjust_rate" id="adjust_rate" class="form-control" placeholder="0.00" value="0" oninput="calculateTotals()">
                                <small class="text-muted">For reference only - not added to total</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="summary-column">
                        <div class="summary-card">
                            <div class="summary-title">
                                <i class="fas fa-calculator"></i>
                                Amount Summary
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Sub Total (Meter × Rate)</span>
                                <span class="summary-value" id="subTotalValue">Rs. 0</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Adjustment</span>
                                <span class="summary-value adjust" id="adjustValue">Rs. 0</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Final Amount</span>
                                <span class="summary-value total" id="finalAmount">Rs. 0</span>
                            </div>
                            
                            <div class="mt-3 pt-2">
                                <span class="badge-info">
                                    <i class="fas fa-info-circle"></i> Adjustment is for reference only
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" name="save" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Save Purchase
                    </button>
                    <a href="purchase_list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <button type="reset" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// List of existing parties from PHP (company filtered)
const existingParties = <?= json_encode($parties) ?>;

function calculateTotals() {
    const meter = parseFloat(document.getElementById('total_meter').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const adjust = parseFloat(document.getElementById('adjust_rate').value) || 0;
    
    const subTotal = meter * rate;
    const finalAmount = subTotal;  // Final amount is sub total only, adjustment not added
    
    document.getElementById('subTotalValue').textContent = 'Rs. ' + subTotal.toFixed(0);
    document.getElementById('adjustValue').textContent = 'Rs. ' + adjust.toFixed(0);
    document.getElementById('finalAmount').textContent = 'Rs. ' + finalAmount.toFixed(0);
}

function checkPartyExists() {
    const partyInput = document.getElementById('party_name');
    const partyValue = partyInput.value.trim();
    const partyWarning = document.getElementById('partyWarning');
    const saveBtn = document.getElementById('saveBtn');
    
    if (partyValue === '') {
        partyWarning.style.display = 'none';
        partyInput.classList.remove('error');
        saveBtn.disabled = false;
        return true;
    }
    
    // Check if party exists in the list (case‑insensitive)
    const partyExists = existingParties.some(party => party.toLowerCase() === partyValue.toLowerCase());
    
    if (!partyExists) {
        partyWarning.style.display = 'block';
        partyInput.classList.add('error');
        saveBtn.disabled = true;
        return false;
    } else {
        partyWarning.style.display = 'none';
        partyInput.classList.remove('error');
        saveBtn.disabled = false;
        return true;
    }
}

// Form validation
document.getElementById('purchaseForm').addEventListener('submit', function(e) {
    const requiredFields = document.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = '#e74c3c';
            isValid = false;
        } else {
            field.style.borderColor = '';
        }
    });
    
    // Check if party exists
    if (!checkPartyExists()) {
        isValid = false;
    }
    
    if (!isValid) {
        e.preventDefault();
        alert('Please fill in all required fields and ensure party exists in the system');
    }
});

// Real‑time party validation
document.getElementById('party_name').addEventListener('input', function() {
    checkPartyExists();
});

document.getElementById('party_name').addEventListener('blur', function() {
    checkPartyExists();
});

// Initialize calculation and validation
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    checkPartyExists();
});
</script>

</body>
</html>