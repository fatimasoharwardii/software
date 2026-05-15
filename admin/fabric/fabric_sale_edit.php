<?php
// Explicit page identifier for permission system
$page_identifier = 'fabric_sale_edit.php';

// Include database and functions FIRST
require_once "../../config/db.php";
require_once "../../includes/functions.php";

// Include authentication (handles login & permission)
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];

// Get the sale ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: fabric_sale_list.php");
    exit;
}

// Fetch existing sale (company check)
$sale_query = "SELECT * FROM fabric_sale WHERE id = $id AND company_id = $company_id";
$sale_result = $conn->query($sale_query);
$sale = $sale_result->fetch_assoc();
if (!$sale) {
    header("Location: fabric_sale_list.php");
    exit;
}

// Fetch all lots with available stock
$lots_query = "SELECT 
    id, lot_no, fabric_name, color, adjust_rate as rate,
    total_meter, COALESCE(used_meter, 0) as used_meter, COALESCE(sold_meter, 0) as sold_meter,
    (total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)) as available_meter
FROM fabric_purchase 
WHERE company_id = $company_id
ORDER BY lot_no DESC";
$lots_result = $conn->query($lots_query);

// Fetch all parties (accounts)
$parties_query = "SELECT account_name FROM accounts WHERE company_id = $company_id ORDER BY account_name";
$parties_result = $conn->query($parties_query);

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $lot_no           = mysqli_real_escape_string($conn, $_POST['lot_no']);
    $fabric_name      = mysqli_real_escape_string($conn, $_POST['fabric_name']);
    $color            = mysqli_real_escape_string($conn, $_POST['color']);
    $color_count      = (float)($_POST['color_count'] ?? 1);
    $party_name       = mysqli_real_escape_string($conn, trim($_POST['party_name']));
    $sale_date        = mysqli_real_escape_string($conn, $_POST['sale_date']);
    $quantity         = (float)($_POST['quantity'] ?? 0);
    $rate             = (float)($_POST['rate'] ?? 0);
    $total_amount     = $quantity * $rate * $color_count;
    $bill_no          = mysqli_real_escape_string($conn, $sale['bill_no']);
    
    if (empty($party_name)) {
        $error = "Party name is required";
    } elseif ($quantity <= 0) {
        $error = "Quantity must be greater than 0";
    } elseif (empty($lot_no)) {
        $error = "Please select a lot number";
    } else {
        $party_check = $conn->query("SELECT account_name FROM accounts WHERE account_name = '$party_name' AND company_id = $company_id LIMIT 1");
        if ($party_check->num_rows == 0) {
            $error = "Party '$party_name' does not exist. Please add this party first.";
        } else {
            mysqli_begin_transaction($conn);
            try {
                // Get the purchase record for the selected lot
                $purchase_res = $conn->query("SELECT id, total_meter, used_meter, sold_meter, fabric_name, color, adjust_rate 
                                              FROM fabric_purchase 
                                              WHERE lot_no = '$lot_no' AND company_id = $company_id LIMIT 1");
                $purchase = $purchase_res->fetch_assoc();
                if (!$purchase) throw new Exception("Lot not found");
                
                $old_quantity = (float)$sale['quantity'];
                $old_lot_no   = mysqli_real_escape_string($conn, $sale['lot_no']);
                
                // Revert old sale from the old lot if lot changed
                if ($old_lot_no != $lot_no) {
                    $old_purchase_res = $conn->query("SELECT id, sold_meter, used_meter, total_meter 
                                                      FROM fabric_purchase 
                                                      WHERE lot_no = '$old_lot_no' AND company_id = $company_id LIMIT 1");
                    $old_purchase = $old_purchase_res->fetch_assoc();
                    if (!$old_purchase) throw new Exception("Old lot not found");
                    
                    $old_new_sold = (float)$old_purchase['sold_meter'] - $old_quantity;
                    $old_new_remaining = (float)$old_purchase['total_meter'] - (float)$old_purchase['used_meter'] - $old_new_sold;
                    $update_old = "UPDATE fabric_purchase 
                                   SET sold_meter = $old_new_sold, remaining_meter_sold = $old_new_remaining
                                   WHERE id = {$old_purchase['id']} AND company_id = $company_id";
                    if (!$conn->query($update_old)) throw new Exception("Error reverting old lot stock: " . $conn->error);
                } else {
                    $current_sold = (float)$purchase['sold_meter'];
                    $current_used = (float)$purchase['used_meter'];
                    $total_meter  = (float)$purchase['total_meter'];
                    $temp_sold    = $current_sold - $old_quantity;
                    $available_now = $total_meter - $current_used - $temp_sold;
                    if ($quantity > $available_now) {
                        throw new Exception("Insufficient stock! Available: " . number_format($available_now, 2) . "m");
                    }
                }
                
                // Check availability on new lot if changed
                if ($old_lot_no != $lot_no) {
                    $new_current_sold = (float)$purchase['sold_meter'];
                    $new_current_used = (float)$purchase['used_meter'];
                    $new_total_meter  = (float)$purchase['total_meter'];
                    $new_available    = $new_total_meter - $new_current_used - $new_current_sold;
                    if ($quantity > $new_available) {
                        throw new Exception("Insufficient stock on new lot! Available: " . number_format($new_available, 2) . "m");
                    }
                }
                
                // Update fabric_sale record
                $update_sale_sql = "UPDATE fabric_sale SET 
                    lot_no = '$lot_no',
                    fabric_name = '$fabric_name',
                    color = '$color',
                    color_count = $color_count,
                    party_name = '$party_name',
                    sale_date = '$sale_date',
                    quantity = $quantity,
                    rate = $rate,
                    total_amount = $total_amount,
                    purchase_id = {$purchase['id']},
                    updated_at = NOW()
                    WHERE id = $id AND company_id = $company_id";
                if (!$conn->query($update_sale_sql)) throw new Exception("Error updating sale: " . $conn->error);
                
                // Update stock on the lot
                if ($old_lot_no != $lot_no) {
                    $new_sold = (float)$purchase['sold_meter'] + $quantity;
                    $new_remaining = (float)$purchase['total_meter'] - (float)$purchase['used_meter'] - $new_sold;
                    $update_stock = "UPDATE fabric_purchase 
                                     SET sold_meter = $new_sold, remaining_meter_sold = $new_remaining
                                     WHERE id = {$purchase['id']} AND company_id = $company_id";
                    if (!$conn->query($update_stock)) throw new Exception("Error updating new lot stock: " . $conn->error);
                } else {
                    $diff = $quantity - $old_quantity;
                    $new_sold = (float)$purchase['sold_meter'] + $diff;
                    $new_remaining = (float)$purchase['total_meter'] - (float)$purchase['used_meter'] - $new_sold;
                    $update_stock = "UPDATE fabric_purchase 
                                     SET sold_meter = $new_sold, remaining_meter_sold = $new_remaining
                                     WHERE id = {$purchase['id']} AND company_id = $company_id";
                    if (!$conn->query($update_stock)) throw new Exception("Error updating lot stock: " . $conn->error);
                }
                
                // ========== UPDATE STITCHING_POSTED_BILLS – CORRECTED ==========
                // Use serial_no (which stores the bill number) instead of bill_no column
                $description = "Fabric Sale - Lot: $lot_no, Fabric: $fabric_name, Color: $color, Colors: $color_count, Qty: $quantity m, Bill: $bill_no";
                $job_no = "SALE-$bill_no";
                
                // Find existing record by serial_no and claim_type
                $check_bill = $conn->query("SELECT id FROM stitching_posted_bills 
                                            WHERE serial_no = '$bill_no' AND claim_type = 'fabric_sale' AND company_id = $company_id LIMIT 1");
                if ($check_bill && $check_bill->num_rows > 0) {
                    $bill_row = $check_bill->fetch_assoc();
                    $update_post_sql = "UPDATE stitching_posted_bills SET 
                        emp_name = '$party_name',
                        qty = $quantity,
                        rate = $rate,
                        total_amount = $total_amount,
                        description = '$description',
                        claim_date = '$sale_date',
                        fabric_name = '$fabric_name',
                        updated_at = NOW()
                        WHERE id = {$bill_row['id']} AND company_id = $company_id";
                    if (!$conn->query($update_post_sql)) {
                        throw new Exception("Error updating posted bill: " . $conn->error);
                    }
                } else {
                    // Insert only if missing (should not happen for existing sale)
                    $insert_post_sql = "INSERT INTO stitching_posted_bills 
                        (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, 
                         claim_type, claim_date, fabric_name, status, company_id, user_id, created_at)
                        VALUES ('$job_no', '$bill_no', '$party_name', 'Fabric Sale', $quantity, $rate, $total_amount, 
                                '$description', 'fabric_sale', '$sale_date', '$fabric_name', 'pending', $company_id, $user_id, NOW())";
                    if (!$conn->query($insert_post_sql)) {
                        throw new Exception("Error inserting posted bill: " . $conn->error);
                    }
                }
                
                mysqli_commit($conn);
                echo "<script>
                        alert('Fabric sale updated successfully!\\nBill No: $bill_no\\nParty: $party_name\\nQuantity: " . number_format($quantity, 2) . " m\\nTotal: Rs. " . number_format($total_amount, 2) . "');
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
<title>Edit Fabric Sale</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
    /* ===== CSS (unchanged – same as before) ===== */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); font-family: 'Segoe UI', system-ui, sans-serif; }
    .main-container { margin-left: 14%; padding: 24px 32px; min-height: 100vh; transition: margin-left 0.3s ease; }
    .card { background: white; border: none; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
    .card-header { background: white; padding: 20px 28px; border-bottom: 2px solid #F39C12; }
    .card-header h4 { color: #E67E22; font-size: 1.3rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; }
    .card-body { padding: 28px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 15px; }
    .page-header h2 { font-size: 1.5rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; color: #2C3E50; }
    .btn-list { background: #27ae60; color: white; border: none; border-radius: 12px; padding: 10px 20px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
    .btn-list:hover { background: #219a52; transform: translateY(-2px); color: white; }
    .two-column-layout { display: flex; gap: 30px; flex-wrap: wrap; }
    .form-column { flex: 1.5; min-width: 280px; }
    .summary-column { flex: 1; min-width: 280px; }
    .form-group { margin-bottom: 16px; }
    .form-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
    .form-row .form-group { flex: 1; margin-bottom: 0; min-width: 120px; }
    label { display: block; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; margin-bottom: 5px; }
    label i { color: #F39C12; width: 16px; margin-right: 4px; }
    .required::after { content: '*'; color: #e74c3c; margin-left: 4px; }
    .form-control, .form-select { width: 100%; padding: 8px 12px; font-size: 0.8rem; border: 1px solid #E9ECEF; border-radius: 10px; background: white; }
    .form-control:focus, .form-select:focus { border-color: #F39C12; outline: none; box-shadow: 0 0 0 3px rgba(243,156,18,0.1); }
    .form-control[readonly] { background: #F8F9FA; }
    .auto-bill { background: #FEF5E7; border-color: #F39C12; font-weight: 600; color: #E67E22; }
    .stock-info { background: #FEF5E7; border-radius: 10px; padding: 10px 14px; margin: 16px 0; display: flex; align-items: center; gap: 8px; border-left: 3px solid #F39C12; font-size: 0.75rem; }
    .quantity-exceed { border-color: #e74c3c !important; background-color: #ffebee !important; }
    .party-warning { background: #fff3cd; border-radius: 8px; padding: 6px 10px; margin-top: 6px; font-size: 0.65rem; color: #856404; display: flex; align-items: center; gap: 5px; }
    .summary-card { background: linear-gradient(135deg, #FEF5E7 0%, #fff 100%); border-radius: 16px; padding: 20px; border: 1px solid rgba(243,156,18,0.2); position: sticky; top: 20px; }
    .summary-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #E67E22; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; padding-bottom: 10px; border-bottom: 1px solid rgba(243,156,18,0.3); }
    .summary-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dashed #E9ECEF; }
    .summary-label { font-size: 0.7rem; font-weight: 500; color: #6c757d; }
    .summary-value { font-size: 0.9rem; font-weight: 700; color: #E67E22; }
    .summary-value.total { font-size: 1.2rem; color: #27ae60; }
    .formula-hint { background: #F8F9FA; border-left: 3px solid #F39C12; padding: 10px 15px; margin-bottom: 20px; border-radius: 10px; font-size: 0.7rem; }
    .btn { padding: 8px 20px; font-size: 0.8rem; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-primary { background: #F39C12; color: white; }
    .btn-primary:hover { background: #E67E22; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(243,156,18,0.3); }
    .btn-secondary { background: #F8F9FA; color: #2C3E50; border: 1px solid #E9ECEF; }
    .action-buttons { margin-top: 24px; padding-top: 20px; border-top: 1px solid #E9ECEF; display: flex; gap: 12px; flex-wrap: wrap; }
    .alert { padding: 10px 16px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; font-size: 0.8rem; border: none; }
    .alert-danger { background: #fef5e7; color: #856404; border-left: 4px solid #e74c3c; }
    @media (max-width: 1200px) { .main-container { margin-left: 10%; padding: 20px; } }
    @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .two-column-layout { flex-direction: column; } }
</style>
<datalist id="party-list">
    <?php 
    if($parties_result && $parties_result->num_rows > 0) {
        $parties_result->data_seek(0);
        while($party = $parties_result->fetch_assoc()): 
    ?>
        <option value="<?= htmlspecialchars($party['account_name']) ?>">
    <?php endwhile; } ?>
</datalist>
<datalist id="lot-list">
    <?php 
    if($lots_result && $lots_result->num_rows > 0) {
        $lots_result->data_seek(0);
        while($lot = $lots_result->fetch_assoc()): 
            $display_available = $lot['available_meter'];
            if ($lot['lot_no'] == $sale['lot_no']) {
                $display_available += $sale['quantity'];
            }
    ?>
        <option value="<?= htmlspecialchars($lot['lot_no']) ?>" 
                data-fabric="<?= htmlspecialchars($lot['fabric_name']) ?>"
                data-color="<?= htmlspecialchars($lot['color']) ?>"
                data-rate="<?= $lot['rate'] ?>"
                data-available="<?= $display_available ?>">
    <?php endwhile; } ?>
</datalist>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-edit"></i> Edit Fabric Sale</h2>
        <a href="fabric_sale_list.php" class="btn-list"><i class="fas fa-list"></i> Sale List</a>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="formula-hint">
        <i class="fas fa-calculator"></i> <strong>Formula:</strong> Quantity (m) × Rate × Colors = Total Amount
        <span style="float: right;"><i class="fas fa-boxes"></i> Stock is automatically adjusted</span>
    </div>
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-edit"></i> Edit Sale - Bill No: <?= htmlspecialchars($sale['bill_no']) ?></h4>
        </div>
        <div class="card-body">
            <form method="POST" id="saleForm">
                <div class="two-column-layout">
                    <div class="form-column">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-receipt"></i> Bill No</label>
                                <input type="text" class="form-control auto-bill" value="<?= htmlspecialchars($sale['bill_no']) ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-calendar"></i> Sale Date</label>
                                <input type="date" name="sale_date" class="form-control" value="<?= $sale['sale_date'] ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-layer-group"></i> Lot No</label>
                                <input type="text" name="lot_no" id="lot_no" class="form-control" list="lot-list" value="<?= htmlspecialchars($sale['lot_no']) ?>" required autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tshirt"></i> Fabric</label>
                                <input type="text" name="fabric_name" id="fabric_name" class="form-control" value="<?= htmlspecialchars($sale['fabric_name']) ?>" readonly>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-palette"></i> Color</label>
                                <input type="text" name="color" id="color" class="form-control" value="<?= htmlspecialchars($sale['color']) ?>" required readonly>
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-hashtag"></i> Colors</label>
                                <input type="number" step="1" min="1" name="color_count" id="color_count" class="form-control" value="<?= $sale['color_count'] ?>" required oninput="calculateTotal()">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Rate (Rs./m)</label>
                                <input type="number" step="1" name="rate" id="rate" class="form-control" value="<?= $sale['rate'] ?>" oninput="calculateTotal()">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-ruler"></i> Quantity (m)</label>
                                <input type="number" step="0.25" name="quantity" id="quantity" class="form-control" value="<?= $sale['quantity'] ?>" required oninput="calculateTotal()">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-user"></i> Party</label>
                                <input type="text" name="party_name" id="party_name" class="form-control" list="party-list" value="<?= htmlspecialchars($sale['party_name']) ?>" required autocomplete="off">
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
                            <div class="summary-title"><i class="fas fa-calculator"></i> Amount Summary</div>
                            <div class="summary-item"><span class="summary-label">Bill Number</span><span class="summary-value" id="summaryBillNo"><?= htmlspecialchars($sale['bill_no']) ?></span></div>
                            <div class="summary-item"><span class="summary-label">Quantity (m)</span><span class="summary-value" id="summaryQty"><?= number_format($sale['quantity'], 2) ?></span></div>
                            <div class="summary-item"><span class="summary-label">Rate (Rs./m)</span><span class="summary-value" id="summaryRate"><?= number_format($sale['rate'], 2) ?></span></div>
                            <div class="summary-item"><span class="summary-label">Number of Colors</span><span class="summary-value" id="summaryColors"><?= $sale['color_count'] ?></span></div>
                            <div class="summary-item"><span class="summary-label">Sub Total</span><span class="summary-value" id="summarySubTotal">Rs. <?= number_format($sale['quantity'] * $sale['rate'], 2) ?></span></div>
                            <div class="summary-item" style="border-top: 2px solid #F39C12; margin-top: 5px; padding-top: 12px;">
                                <span class="summary-label" style="font-weight: 700;">Total Amount</span>
                                <span class="summary-value total" id="summaryTotal">Rs. <?= number_format($sale['total_amount'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="submit" name="update" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Update Sale</button>
                    <a href="fabric_sale_list.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
let maxAvailable = 0;
let originalQuantity = <?= $sale['quantity'] ?>;
let originalLot = '<?= addslashes($sale['lot_no']) ?>';

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
        if(partyOptions[i].value === partyName) { partyExists = true; break; }
    }
    let availableForCheck = maxAvailable;
    const currentLot = document.getElementById('lot_no').value;
    if (currentLot === originalLot) {
        availableForCheck = maxAvailable + originalQuantity;
    }
    if (availableForCheck > 0 && qty > availableForCheck) {
        qtyInput.classList.add('quantity-exceed');
        saveBtn.disabled = true;
    } else {
        qtyInput.classList.remove('quantity-exceed');
        if (partyExists && qty > 0 && availableForCheck > 0 && qty <= availableForCheck) {
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
            let available = parseFloat(options[i].dataset.available) || 0;
            if (selected === originalLot) {
                maxAvailable = available + originalQuantity;
            } else {
                maxAvailable = available;
            }
            const stockDiv = document.getElementById('stockInfo');
            const stockText = document.getElementById('stockText');
            stockText.innerHTML = `<strong>Available Stock:</strong> ${maxAvailable.toFixed(2)} meters` + (selected === originalLot ? ' (including current sale quantity)' : '');
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
});

document.getElementById('party_name').addEventListener('input', function() {
    const partyName = this.value;
    const partyOptions = document.getElementById('party-list').options;
    let exists = false;
    for(let i = 0; i < partyOptions.length; i++) {
        if(partyOptions[i].value === partyName) { exists = true; break; }
    }
    const partyWarning = document.getElementById('partyWarning');
    const saveBtn = document.getElementById('saveBtn');
    const qty = parseFloat(document.getElementById('quantity').value) || 0;
    if(partyName && !exists) {
        partyWarning.style.display = 'flex';
        saveBtn.disabled = true;
    } else {
        partyWarning.style.display = 'none';
        const currentLot = document.getElementById('lot_no').value;
        let availableCheck = maxAvailable;
        if (currentLot === originalLot) availableCheck = maxAvailable + originalQuantity;
        if(qty > 0 && qty <= availableCheck) saveBtn.disabled = false;
        else saveBtn.disabled = true;
    }
});

document.getElementById('quantity').addEventListener('input', calculateTotal);
document.getElementById('rate').addEventListener('input', calculateTotal);
document.getElementById('color_count').addEventListener('input', calculateTotal);

setTimeout(() => {
    const lotEvent = new Event('input', { bubbles: true });
    document.getElementById('lot_no').dispatchEvent(lotEvent);
}, 100);
calculateTotal();
</script>
</body>
</html>