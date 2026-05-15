<?php
// Explicit page identifier for permission system
$page_identifier = 'claims/edit.php';

require_once "../../config/db.php";
require_once "../../includes/functions.php";
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

// Get the ID from URL – could be stitching_bill_items.id OR claims.id
$passed_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$passed_id) {
    die("Invalid request. ID not provided.");
}

// Try to fetch from stitching_bill_items first
$stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE id = ? AND company_id = ? AND tab_type = 'claim_billing'");
$stmt->bind_param("ii", $passed_id, $company_id);
$stmt->execute();
$current_claim = $stmt->get_result()->fetch_assoc();

// If not found, maybe it's a claims.id – look up in claims table and get corresponding stitching_bill_items record
if (!$current_claim) {
    $stmt_claim = $conn->prepare("SELECT * FROM claims WHERE id = ? AND company_id = ?");
    $stmt_claim->bind_param("ii", $passed_id, $company_id);
    $stmt_claim->execute();
    $claim_record = $stmt_claim->get_result()->fetch_assoc();

    if (!$claim_record) {
        die("Claim not found or does not belong to your company.");
    }

    // Now find the matching stitching_bill_items entry (same job_no, claim_item, emp_name, latest created_at)
    $job_no = $claim_record['job_no'];
    $emp_name = $claim_record['emp_name'];
    $claim_item = $claim_record['claim_item'];
    $stmt_find = $conn->prepare("SELECT * FROM stitching_bill_items 
        WHERE job_no = ? AND name = ? AND part_name = ? AND tab_type = 'claim_billing' AND company_id = ? 
        ORDER BY created_at DESC LIMIT 1");
    $stmt_find->bind_param("sssi", $job_no, $emp_name, $claim_item, $company_id);
    $stmt_find->execute();
    $current_claim = $stmt_find->get_result()->fetch_assoc();

    if (!$current_claim) {
        die("No stitching entry found for this claim. Please edit manually.");
    }
}

// Now $current_claim is always a stitching_bill_items row
$item_id = $current_claim['id'];
$job_no = $current_claim['job_no'];
$old_qty = floatval($current_claim['qty']);
$old_rate = floatval($current_claim['rate']);
$old_amount = $old_qty * $old_rate;
$old_emp = $current_claim['name'];
$old_claim_item = $current_claim['part_name'];
$old_claim_date = $current_claim['created_at'];   // or dedicated date field if exists

// Fetch job details
$stmt_job = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ?");
$stmt_job->bind_param("si", $job_no, $company_id);
$stmt_job->execute();
$job_info = $stmt_job->get_result()->fetch_assoc();

if (!$job_info) {
    die("Associated job not found.");
}

// Calculate available quantity (excluding this claim)
$stmt_prod = $conn->prepare("SELECT SUM(qty) as total_production FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'production_billing' AND company_id = ?");
$stmt_prod->bind_param("si", $job_no, $company_id);
$stmt_prod->execute();
$prod_data = $stmt_prod->get_result()->fetch_assoc();
$production_qty = floatval($prod_data['total_production'] ?? 0);

$stmt_other = $conn->prepare("SELECT SUM(qty) as total_claimed FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'claim_billing' AND company_id = ? AND id != ?");
$stmt_other->bind_param("sii", $job_no, $company_id, $item_id);
$stmt_other->execute();
$other_claims = $stmt_other->get_result()->fetch_assoc();
$claimed_others = floatval($other_claims['total_claimed'] ?? 0);

$total_qty = floatval($job_info['quantity'] ?? 0);
$remaining_without_self = max(0, $total_qty - ($production_qty + $claimed_others));

// Employee list
$emps = $conn->prepare("SELECT account_name FROM accounts WHERE account_type IN ('employee','vendor','customer') AND company_id = ? ORDER BY account_name");
$emps->bind_param("i", $company_id);
$emps->execute();
$emp_res = $emps->get_result();
$emp_list = [];
while ($e = $emp_res->fetch_assoc()) $emp_list[] = $e['account_name'];

// Job suggestions
$jobs = $conn->prepare("SELECT job_no FROM jobs WHERE company_id = ? ORDER BY job_no");
$jobs->bind_param("i", $company_id);
$jobs->execute();
$job_list_res = $jobs->get_result();
$job_list = [];
while ($j = $job_list_res->fetch_assoc()) $job_list[] = $j['job_no'];

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_claim'])) {
    $serial_no = mysqli_real_escape_string($conn, $_POST['serial_no']);
    $claim_date = mysqli_real_escape_string($conn, $_POST['claim_date']);
    $claim_item = mysqli_real_escape_string($conn, $_POST['claim_item']);
    $new_qty = floatval($_POST['qty']);
    $new_rate = floatval($_POST['rate']);
    $emp_name = mysqli_real_escape_string($conn, $_POST['emp_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $new_amount = $new_qty * $new_rate;
    $user_id = (int)$_SESSION['user_id'];

    if ($new_qty > $remaining_without_self) {
        $error_msg = "Quantity exceeds available limit. Max allowed: $remaining_without_self pcs.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Update stitching_bill_items
            $upd = $conn->prepare("UPDATE stitching_bill_items SET job_no=?, name=?, part_name=?, qty=?, rate=?, amount=?, created_at=? WHERE id=? AND company_id=?");
            $upd->bind_param("sssddssii", $job_no, $emp_name, $claim_item, $new_qty, $new_rate, $new_amount, $claim_date, $item_id, $company_id);
            $upd->execute();

            // 2. Update claims table – find by old data
            $find = $conn->prepare("SELECT id FROM claims WHERE job_no=? AND claim_item=? AND emp_name=? AND company_id=? ORDER BY created_at DESC LIMIT 1");
            $find->bind_param("sssi", $job_no, $old_claim_item, $old_emp, $company_id);
            $find->execute();
            $claim_row = $find->get_result()->fetch_assoc();

            if ($claim_row) {
                $cid = $claim_row['id'];
                $upd2 = $conn->prepare("UPDATE claims SET serial_no=?, claim_item=?, qty=?, rate=?, amount=?, claim_date=?, description=?, emp_name=?, status='pending' WHERE id=? AND company_id=?");
                $upd2->bind_param("ssdddsssii", $serial_no, $claim_item, $new_qty, $new_rate, $new_amount, $claim_date, $description, $emp_name, $cid, $company_id);
                $upd2->execute();
            } else {
                $job_id = $job_info['id'];
                $ins = $conn->prepare("INSERT INTO claims (job_id, job_no, serial_no, claim_item, qty, rate, amount, claim_date, created_at, claim_type, description, emp_name, status, company_id, user_id) VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?,'pending',?,?)");
                $ins->bind_param("isssdddssssi", $job_id, $job_no, $serial_no, $claim_item, $new_qty, $new_rate, $new_amount, $claim_date, $claim_item, $description, $emp_name, $company_id, $user_id);
                $ins->execute();
            }

            // 3. Adjust stitching_posted_bills
            $bill = $conn->prepare("SELECT id, total_amount, qty FROM stitching_posted_bills WHERE job_no=? AND claim_type=? AND company_id=?");
            $bill->bind_param("ssi", $job_no, $old_claim_item, $company_id);
            $bill->execute();
            $bill_data = $bill->get_result()->fetch_assoc();

            $desc_str = "Claim: $claim_item, Qty:$new_qty, Rate:$new_rate, Emp:$emp_name";
            if ($bill_data) {
                $new_total = floatval($bill_data['total_amount']) - $old_amount + $new_amount;
                $new_total_qty = floatval($bill_data['qty']) - $old_qty + $new_qty;
                $upd_bill = $conn->prepare("UPDATE stitching_posted_bills SET emp_name=?, qty=?, rate=?, total_amount=?, description=CONCAT(?, IFNULL(description,'')), claim_date=?, claim_type=?, claim_type='claim', status='pending' WHERE id=? AND company_id=?");
                $upd_bill->bind_param("sdddsssii", $emp_name, $new_total_qty, $new_rate, $new_total, $desc_str, $claim_date, $claim_item, $bill_data['id'], $company_id);
                $upd_bill->execute();
            } else {
                $design = $job_info['design_name'] ?? '';
                $fabric = $job_info['fabric_name'] ?? '';
                $size   = $job_info['size'] ?? '';
                $brand  = $job_info['brand_name'] ?? '';
                $ins_bill = $conn->prepare("INSERT INTO stitching_posted_bills (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, claim_date, description, claim_type, design_name, fabric_name, size, brand_name, status, claim_type, company_id, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending','claim',?,?)");
                $ins_bill->bind_param("ssssdddssssssii", $job_no, $serial_no, $emp_name, $claim_item, $new_qty, $new_rate, $new_amount, $claim_date, $desc_str, $claim_item, $design, $fabric, $size, $brand, $company_id, $user_id);
                $ins_bill->execute();
            }

            $conn->commit();
            $success_msg = "Claim updated successfully!";
            // Refresh data for display
            $old_qty = $new_qty;
            $old_rate = $new_rate;
            $old_amount = $new_amount;
            $old_emp = $emp_name;
            $old_claim_item = $claim_item;
            $old_claim_date = $claim_date;
            $remaining_without_self = max(0, $total_qty - ($production_qty + $claimed_others + $new_qty));
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Update failed: " . $e->getMessage();
        }
    }
}

function fmt($num) { return number_format($num, 2); }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Claim</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root { --primary: #F39C12; --primary-light: #FEF5E7; --primary-dark: #E67E22; --border: #E9ECEF; --bg-light: #F8F9FA; --text-dark: #2C3E50; --success: #27ae60; --danger: #e74c3c; --info: #3498db; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); color: var(--text-dark); }
        .main-container { margin-left: 14%; padding: 28px 35px; min-height: 100vh; }
        h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 28px; display: flex; align-items: center; gap: 12px; border-bottom: 3px solid var(--primary); padding-bottom: 12px; }
        h2 i { color: var(--primary); }
        .card { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
        .card-header { padding: 16px 20px; background: linear-gradient(to right, #fff, var(--primary-light)); border-bottom: 2px solid var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .card-header i { color: var(--primary); }
        .card-body { padding: 20px; }
        .form-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #6c757d; margin-bottom: 6px; }
        .form-label i { color: var(--primary); width: 16px; }
        .form-control, .form-select { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 0.9rem; }
        .btn { padding: 10px 20px; font-size: 0.85rem; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-success { background: var(--success); color: white; }
        .btn-secondary { background: #e9ecef; color: var(--text-dark); }
        .row { display: flex; flex-wrap: wrap; margin: -8px; }
        .col-md-6 { width: 50%; padding: 8px; }
        .col-md-12 { width: 100%; padding: 8px; }
        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-danger { background: #fee; border-left: 4px solid var(--danger); color: #c00; }
        .alert-success { background: #efe; border-left: 4px solid var(--success); color: #090; }
        @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .col-md-6 { width: 100%; } }
    </style>
    <datalist id="employeeList">
        <?php foreach ($emp_list as $e): ?><option value="<?= htmlspecialchars($e) ?>"><?php endforeach; ?>
    </datalist>
    <datalist id="jobSuggestList">
        <?php foreach ($job_list as $j): ?><option value="<?= htmlspecialchars($j) ?>"><?php endforeach; ?>
    </datalist>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <h2><i class="fas fa-edit"></i> Edit Claim</h2>
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-edit"></i> Edit Claim (Stitching ID: <?= $item_id ?>, Job: <?= htmlspecialchars($job_no) ?>)</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="job_no" value="<?= htmlspecialchars($job_no) ?>">
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-barcode"></i> Serial No</label>
                        <input type="text" name="serial_no" class="form-control" value="<?= htmlspecialchars($claim_record['serial_no'] ?? $current_claim['serial_no'] ?? "CLM-".date('Ymd')."-001") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-calendar"></i> Claim Date</label>
                        <input type="date" name="claim_date" class="form-control" value="<?= date('Y-m-d', strtotime($old_claim_date)) ?>" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-user"></i> Employee Name</label>
                        <input type="text" name="emp_name" class="form-control" list="employeeList" value="<?= htmlspecialchars($old_emp) ?>" autocomplete="off" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-tag"></i> Claim Item</label>
                        <select name="claim_item" class="form-select" required>
                            <option value="">Select Claim Type</option>
                            <?php
                            $claim_types = ['Stitching Charges', 'Cutting Charges', 'Embroidery Charges', 'Printing Charges', 'Packing Charges', 'Pressman Charges', 'Checking Charges', 'Handwork Charges', 'Material Charges', 'Fabric Charges', 'Other'];
                            foreach ($claim_types as $ct) {
                                $sel = ($old_claim_item == $ct) ? 'selected' : '';
                                echo "<option value=\"$ct\" $sel>$ct</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-cubes"></i> Quantity (Pcs)</label>
                        <input type="number" step="1" min="0" name="qty" id="claim_qty" class="form-control" value="<?= $old_qty ?>" required oninput="calcAmount()">
                        <small class="text-muted">Available (excluding this claim): <?= $remaining_without_self ?> pcs</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-tag"></i> Rate (per piece)</label>
                        <input type="number" step="0.25" min="0" name="rate" id="claim_rate" class="form-control" value="<?= $old_rate ?>" required oninput="calcAmount()">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> Total Amount</label>
                        <input type="text" id="claim_amount" class="form-control bg-light" readonly value="<?= fmt($old_amount) ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-file-alt"></i> Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Additional details..."><?= htmlspecialchars($current_claim['description'] ?? $claim_record['description'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" name="update_claim" class="btn btn-success" id="saveClaimBtn"><i class="fas fa-save"></i> Update Claim</button>
                    <a href="list.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function calcAmount() {
    let qty = parseFloat(document.getElementById('claim_qty').value) || 0;
    let rate = parseFloat(document.getElementById('claim_rate').value) || 0;
    document.getElementById('claim_amount').value = (qty * rate).toFixed(2);
    let remaining = <?= $remaining_without_self ?>;
    if (qty > remaining && remaining > 0) {
        document.getElementById('claim_qty').classList.add('is-invalid');
        document.getElementById('saveClaimBtn').disabled = true;
    } else {
        document.getElementById('claim_qty').classList.remove('is-invalid');
        document.getElementById('saveClaimBtn').disabled = false;
    }
}
document.addEventListener('DOMContentLoaded', calcAmount);
</script>
</body>
</html>