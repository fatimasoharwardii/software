<?php
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id'];

// Ensure required tables have company_id column
$tables = ['fabric_sale', 'fabric_purchase', 'accounts', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Ensure required columns in fabric_purchase (if missing)
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS sold_meter DECIMAL(12,2) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS remaining_meter_sold DECIMAL(12,2) DEFAULT NULL");

// Update remaining_meter_sold for all records (safe)
$conn->query("UPDATE fabric_purchase 
    SET remaining_meter_sold = total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)
    WHERE remaining_meter_sold IS NULL OR remaining_meter_sold != total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)");

// Get all lots with available stock (only current company)
$lots_stmt = $conn->prepare("SELECT 
    id,
    lot_no, 
    fabric_name, 
    color, 
    adjust_rate as rate,
    total_meter,
    COALESCE(used_meter, 0) as used_meter,
    COALESCE(sold_meter, 0) as sold_meter,
    (total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)) as available_meter
FROM fabric_purchase 
WHERE (total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)) > 0
AND company_id = ?
ORDER BY lot_no DESC");
$lots_stmt->bind_param("i", $company_id);
$lots_stmt->execute();
$lots_result = $lots_stmt->get_result();

// Get all parties (only current company)
$parties_stmt = $conn->prepare("SELECT account_name FROM accounts WHERE company_id = ? ORDER BY account_name");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties_result = $parties_stmt->get_result();

// Function to generate auto bill number (company-aware)
function generateBillNumber($conn, $company_id) {
    $currentYearMonth = date('Ym');
    $prefix = "FS-" . $currentYearMonth . "-";
    
    $stmt = $conn->prepare("SELECT bill_no FROM fabric_sale WHERE bill_no LIKE ? AND company_id = ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param("si", $like, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $lastNumber = intval(substr($row['bill_no'], strlen($prefix)));
        $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '0001';
    }
    return $prefix . $newNumber;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    $lot_no = $_POST['lot_no'];
    $fabric_name = $_POST['fabric_name'];
    $color = $_POST['color'];
    $color_count = floatval($_POST['color_count'] ?? 1);
    $party_name = trim($_POST['party_name']);
    $sale_date = $_POST['sale_date'];
    $quantity = floatval($_POST['quantity']);
    $rate = floatval($_POST['rate']);
    $total_amount = $quantity * $rate * $color_count;
    
    $bill_no = generateBillNumber($conn, $company_id);
    
    if (empty($party_name)) {
        $error = "Party name is required";
    } elseif ($quantity <= 0) {
        $error = "Quantity must be greater than 0";
    } elseif (empty($lot_no)) {
        $error = "Please select a lot number";
    } else {
        // Verify party exists under current company
        $party_check = $conn->prepare("SELECT account_name FROM accounts WHERE account_name = ? AND company_id = ?");
        $party_check->bind_param("si", $party_name, $company_id);
        $party_check->execute();
        if ($party_check->get_result()->num_rows == 0) {
            $error = "Party '$party_name' does not exist. Please add this party first.";
        } else {
            mysqli_begin_transaction($conn);
            try {
                // Get purchase record with current stock (company‑aware)
                $purchase_stmt = $conn->prepare("SELECT id, total_meter, used_meter, sold_meter, fabric_name, color, adjust_rate 
                                                FROM fabric_purchase 
                                                WHERE lot_no = ? AND company_id = ? LIMIT 1");
                $purchase_stmt->bind_param("si", $lot_no, $company_id);
                $purchase_stmt->execute();
                $purchase = $purchase_stmt->get_result()->fetch_assoc();
                
                if (!$purchase) {
                    throw new Exception("Lot not found");
                }
                
                $current_sold = floatval($purchase['sold_meter'] ?? 0);
                $current_used = floatval($purchase['used_meter'] ?? 0);
                $total_meter = floatval($purchase['total_meter']);
                $available = $total_meter - $current_used - $current_sold;
                
                if ($quantity > $available) {
                    throw new Exception("Insufficient stock! Available: " . number_format($available, 2) . "m");
                }
                
                $purchase_id = $purchase['id']; // ✅ fix: store in variable
                
                // Insert into fabric_sale
                $insert_sale = $conn->prepare("INSERT INTO fabric_sale 
                    (lot_no, fabric_name, color, color_count, party_name, bill_no, sale_date, quantity, rate, total_amount, purchase_id, company_id, user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_sale->bind_param("sssdssddddiii", 
                    $lot_no, $fabric_name, $color, $color_count, $party_name, $bill_no, 
                    $sale_date, $quantity, $rate, $total_amount, $purchase_id, $company_id, $user_id);
                if (!$insert_sale->execute()) {
                    throw new Exception("Error inserting sale: " . $insert_sale->error);
                }
                
                // Update sold_meter in fabric_purchase
                $new_sold = $current_sold + $quantity;
                $new_remaining = $total_meter - $current_used - $new_sold;
                $update_purchase = $conn->prepare("UPDATE fabric_purchase 
                    SET sold_meter = ?, remaining_meter_sold = ?
                    WHERE id = ? AND company_id = ?");
                $update_purchase->bind_param("ddii", $new_sold, $new_remaining, $purchase['id'], $company_id);
                if (!$update_purchase->execute()) {
                    throw new Exception("Error updating stock: " . $update_purchase->error);
                }
                
                // Insert into stitching_posted_bills as pending
                $serial_no = $bill_no;
                $description = "Fabric Sale - Lot: $lot_no, Fabric: $fabric_name, Color: $color, Colors: $color_count, Qty: $quantity m, Bill: $bill_no";
                $claim_item = "Fabric Sale"; // ✅ store literal in variable
                $job_no = "SALE-$bill_no";
                $claim_type = "fabric_sale";
                $status = "pending";
                
                $insert_bill = $conn->prepare("INSERT INTO stitching_posted_bills 
                    (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, claim_type, claim_date, fabric_name, status, company_id, user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_bill->bind_param("ssssddssssssii", 
                    $job_no, $serial_no, $party_name, $claim_item, $quantity, $rate, $total_amount,
                    $description, $claim_type, $sale_date, $fabric_name, $status, $company_id, $user_id);
                if (!$insert_bill->execute()) {
                    throw new Exception("Error inserting post bill: " . $insert_bill->error);
                }
                
                mysqli_commit($conn);
                
                echo "<script>
                        alert('Fabric sold successfully!\\nBill No: $bill_no\\nParty: $party_name\\nQuantity: " . number_format($quantity, 2) . " m\\nTotal: Rs. " . number_format($total_amount, 2) . "\\n\\nNote: Bill is pending. Please post it to update ledger.');
                        window.location.href = 'fabric_sale_list.php';
                      </script>";
                exit();
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Fabric Sale</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        --danger-light: #ffebee;
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

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-header h2 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text-dark);
    }

    .page-header h2 i {
        color: var(--primary);
    }

    .btn-list {
        background: var(--success);
        color: white;
        border: none;
        border-radius: 12px;
        padding: 10px 20px;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-list:hover {
        background: #219a52;
        transform: translateY(-2px);
        color: white;
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
        margin-bottom: 16px;
    }

    .form-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .form-row .form-group {
        flex: 1;
        margin-bottom: 0;
        min-width: 120px;
    }

    label {
        display: block;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        margin-bottom: 5px;
    }

    label i {
        color: var(--primary);
        width: 16px;
        margin-right: 4px;
    }

    .required::after {
        content: '*';
        color: var(--danger);
        margin-left: 4px;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 8px 12px;
        font-size: 0.8rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        transition: all 0.2s;
        background: white;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .form-control[readonly] {
        background: var(--bg-light);
    }

    .auto-bill {
        background: var(--primary-light);
        border-color: var(--primary);
        font-weight: 600;
        color: var(--primary-dark);
    }

    .stock-info {
        background: var(--primary-light);
        border-radius: 10px;
        padding: 10px 14px;
        margin: 16px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        border-left: 3px solid var(--primary);
        font-size: 0.75rem;
    }

    .quantity-exceed {
        border-color: var(--danger) !important;
        background-color: var(--danger-light) !important;
    }

    .party-warning {
        background: #fff3cd;
        border-radius: 8px;
        padding: 6px 10px;
        margin-top: 6px;
        font-size: 0.65rem;
        color: #856404;
        display: flex;
        align-items: center;
        gap: 5px;
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
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--primary-dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(243,156,18,0.3);
    }

    .summary-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px dashed var(--border);
    }

    .summary-item:last-child {
        border-bottom: none;
    }

    .summary-label {
        font-size: 0.7rem;
        font-weight: 500;
        color: var(--text-muted);
    }

    .summary-value {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--primary-dark);
    }

    .summary-value.total {
        font-size: 1.2rem;
        color: var(--success);
    }

    .formula-hint {
        background: var(--bg-light);
        border-left: 3px solid var(--primary);
        padding: 10px 15px;
        margin-bottom: 20px;
        border-radius: 10px;
        font-size: 0.7rem;
    }

    .btn {
        padding: 8px 20px;
        font-size: 0.8rem;
        font-weight: 600;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
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

    .btn-info {
        background: var(--info);
        color: white;
    }

    .action-buttons {
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .alert {
        padding: 10px 16px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        border: none;
        animation: slideIn 0.3s ease;
    }

    .alert-danger {
        background: #fef5e7;
        color: #856404;
        border-left: 4px solid var(--danger);
    }

    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .text-muted {
        font-size: 0.6rem;
        color: var(--text-muted);
        margin-top: 4px;
        display: block;
    }

    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; padding: 20px; }
    }

    @media (max-width: 992px) {
        .main-container { margin-left: 0; padding: 16px; margin-top: 60px; }
        .two-column-layout { flex-direction: column; gap: 20px; }
        .summary-card { position: static; }
        .form-row { flex-direction: column; gap: 12px; }
        .form-row .form-group { width: 100%; }
        .action-buttons .btn { flex: 1; }
    }

    @media (max-width: 768px) {
        .card-body { padding: 20px; }
        .action-buttons { flex-direction: column; }
        .action-buttons .btn { width: 100%; justify-content: center; }
    }
</style>

<datalist id="party-list">
    <?php 
    if($parties_result && $parties_result->num_rows > 0) {
        $parties_result->data_seek(0);
        while($party = $parties_result->fetch_assoc()): 
    ?>
        <option value="<?= htmlspecialchars($party['account_name']) ?>">
    <?php 
        endwhile;
    }
    ?>
</datalist>

<datalist id="lot-list">
    <?php 
    if($lots_result && $lots_result->num_rows > 0) {
        $lots_result->data_seek(0);
        while($lot = $lots_result->fetch_assoc()): 
    ?>
        <option value="<?= htmlspecialchars($lot['lot_no']) ?>" 
                data-fabric="<?= htmlspecialchars($lot['fabric_name']) ?>"
                data-color="<?= htmlspecialchars($lot['color']) ?>"
                data-rate="<?= $lot['rate'] ?>"
                data-available="<?= $lot['available_meter'] ?>">
    <?php 
        endwhile;
    }
    ?>
</datalist>

</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-shopping-cart"></i> Fabric Sale</h2>
        <a href="fabric_sale_list.php" class="btn-list"><i class="fas fa-list"></i> Sale List</a>
    </div>

    <?php if (isset($error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="formula-hint">
        <i class="fas fa-calculator"></i> <strong>Formula:</strong> Quantity (m) × Rate × Colors = Total Amount
        <span style="float: right;"><i class="fas fa-boxes"></i> Stock is automatically deducted from fabric purchase</span>
    </div>

    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-plus-circle"></i> New Fabric Sale</h4>
        </div>
        <div class="card-body">
            <form method="POST" id="saleForm">
                <div class="two-column-layout">
                    <div class="form-column">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-receipt"></i> Bill No (Auto)</label>
                                <input type="text" name="bill_no" class="form-control auto-bill" value="<?= generateBillNumber($conn, $company_id) ?>" readonly>
                                <small class="text-muted"><i class="fas fa-info-circle"></i> Auto-generated bill number</small>
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-calendar"></i> Sale Date</label>
                                <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-layer-group"></i> Lot No</label>
                                <input type="text" name="lot_no" id="lot_no" class="form-control" list="lot-list" placeholder="Select lot" required autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tshirt"></i> Fabric</label>
                                <input type="text" name="fabric_name" id="fabric_name" class="form-control" readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-palette"></i> Color</label>
                                <input type="text" name="color" id="color" class="form-control" required readonly>
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-hashtag"></i> Colors</label>
                                <input type="number" step="1" min="1" name="color_count" id="color_count" class="form-control" value="1" required oninput="calculateTotal()">
                                <small class="text-muted">Number of colors</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Rate (Rs./m)</label>
                                <input type="number" step="1" name="rate" id="rate" class="form-control" placeholder="0.00" oninput="calculateTotal()">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-ruler"></i> Quantity (m)</label>
                                <input type="number" step="0.25" name="quantity" id="quantity" class="form-control" placeholder="0.00" required oninput="calculateTotal()">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-user"></i> Party</label>
                                <input type="text" name="party_name" id="party_name" class="form-control" list="party-list" placeholder="Select party" required autocomplete="off">
                                <div id="partyWarning" class="party-warning" style="display: none;">
                                    <i class="fas fa-exclamation-triangle"></i> Party not found! Add new party.
                                </div>
                            </div>
                        </div>

                        <div id="stockInfo" class="stock-info" style="display: none;">
                            <i class="fas fa-boxes"></i>
                            <span id="stockText"></span>
                        </div>
                    </div>

                    <div class="summary-column">
                        <div class="summary-card">
                            <div class="summary-title">
                                <i class="fas fa-calculator"></i> Amount Summary
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Bill Number</span>
                                <span class="summary-value" id="summaryBillNo"><?= generateBillNumber($conn, $company_id) ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Quantity (m)</span>
                                <span class="summary-value" id="summaryQty">0.00</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Rate (Rs./m)</span>
                                <span class="summary-value" id="summaryRate">0.00</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Number of Colors</span>
                                <span class="summary-value" id="summaryColors">1</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Sub Total</span>
                                <span class="summary-value" id="summarySubTotal">Rs. 0.00</span>
                            </div>
                            <div class="summary-item" style="border-top: 2px solid var(--primary); margin-top: 5px; padding-top: 12px;">
                                <span class="summary-label" style="font-weight: 700;">Total Amount</span>
                                <span class="summary-value total" id="summaryTotal">Rs. 0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" name="save" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Save Sale
                    </button>
                    <a href="fabric_sale_list.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let maxAvailable = 0;

function calculateTotal() {
    const qty = parseFloat(document.getElementById('quantity').value) || 0;
    const rate = parseFloat(document.getElementById('rate').value) || 0;
    const colors = parseFloat(document.getElementById('color_count').value) || 1;
    const subTotal = qty * rate;
    const total = subTotal * colors;
    
    document.getElementById('summaryQty').textContent = qty.toFixed(2);
    document.getElementById('summaryRate').textContent = rate.toFixed(2);
    document.getElementById('summaryColors').textContent = colors;
    document.getElementById('summarySubTotal').textContent = 'Rs. ' + subTotal.toFixed(2);
    document.getElementById('summaryTotal').textContent = 'Rs. ' + total.toFixed(2);
    
    checkStock();
}

function checkStock() {
    const qty = parseFloat(document.getElementById('quantity').value) || 0;
    const qtyInput = document.getElementById('quantity');
    const saveBtn = document.getElementById('saveBtn');
    const partyName = document.getElementById('party_name').value;
    const partyOptions = document.getElementById('party-list').options;
    let partyExists = false;
    
    for(let i = 0; i < partyOptions.length; i++) {
        if(partyOptions[i].value === partyName) {
            partyExists = true;
            break;
        }
    }
    
    if(maxAvailable > 0 && qty > maxAvailable) {
        qtyInput.classList.add('quantity-exceed');
        saveBtn.disabled = true;
    } else {
        qtyInput.classList.remove('quantity-exceed');
        if(partyExists && qty > 0 && maxAvailable > 0 && qty <= maxAvailable) {
            saveBtn.disabled = false;
        } else {
            saveBtn.disabled = true;
        }
    }
}

document.getElementById('lot_no').addEventListener('input', function() {
    const selected = this.value;
    const options = document.getElementById('lot-list').options;
    let found = false;
    
    for(let i = 0; i < options.length; i++) {
        if(options[i].value === selected) {
            document.getElementById('fabric_name').value = options[i].dataset.fabric || '';
            document.getElementById('color').value = options[i].dataset.color || '';
            document.getElementById('rate').value = options[i].dataset.rate || 0;
            maxAvailable = parseFloat(options[i].dataset.available) || 0;
            
            const stockDiv = document.getElementById('stockInfo');
            const stockText = document.getElementById('stockText');
            stockText.innerHTML = `<strong>Available Stock:</strong> ${maxAvailable.toFixed(2)} meters`;
            stockDiv.style.display = 'flex';
            found = true;
            break;
        }
    }
    
    if(!found && selected) {
        document.getElementById('fabric_name').value = '';
        document.getElementById('color').value = '';
        document.getElementById('rate').value = '';
        document.getElementById('stockInfo').style.display = 'none';
        maxAvailable = 0;
    }
    
    calculateTotal();
    checkStock();
});

document.getElementById('party_name').addEventListener('input', function() {
    const partyName = this.value;
    const partyOptions = document.getElementById('party-list').options;
    let exists = false;
    
    for(let i = 0; i < partyOptions.length; i++) {
        if(partyOptions[i].value === partyName) {
            exists = true;
            break;
        }
    }
    
    const partyWarning = document.getElementById('partyWarning');
    const saveBtn = document.getElementById('saveBtn');
    const qty = parseFloat(document.getElementById('quantity').value) || 0;
    
    if(partyName && !exists) {
        partyWarning.style.display = 'flex';
        saveBtn.disabled = true;
    } else {
        partyWarning.style.display = 'none';
        if(qty > 0 && qty <= maxAvailable) {
            saveBtn.disabled = false;
        }
    }
});

document.getElementById('quantity').addEventListener('input', function() {
    calculateTotal();
    checkStock();
});

document.getElementById('rate').addEventListener('input', calculateTotal);
document.getElementById('color_count').addEventListener('input', calculateTotal);

document.getElementById('saleForm').addEventListener('submit', function(e) {
    const qty = parseFloat(document.getElementById('quantity').value) || 0;
    const lotNo = document.getElementById('lot_no').value;
    const party = document.getElementById('party_name').value;
    const color = document.getElementById('color').value;
    const partyOptions = document.getElementById('party-list').options;
    let partyExists = false;
    
    for(let i = 0; i < partyOptions.length; i++) {
        if(partyOptions[i].value === party) partyExists = true;
    }
    
    if(!partyExists) {
        e.preventDefault();
        alert('Party not found! Please add this party first.');
        return false;
    }
    
    if(!color) {
        e.preventDefault();
        alert('Please select a lot with color information');
        return false;
    }
    
    if(qty <= 0) {
        e.preventDefault();
        alert('Please enter a valid quantity');
        return false;
    }
    
    if(!lotNo) {
        e.preventDefault();
        alert('Please select a lot number');
        return false;
    }
    
    if(qty > maxAvailable) {
        e.preventDefault();
        alert('Quantity exceeds available stock! Available: ' + maxAvailable.toFixed(2) + ' meters');
        return false;
    }
});

document.getElementById('summaryBillNo').textContent = document.querySelector('input[name="bill_no"]').value;
calculateTotal();
</script>

</body>
</html>