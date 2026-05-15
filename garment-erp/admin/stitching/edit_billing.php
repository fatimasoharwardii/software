<?php
$page_identifier = 'stitching/edit_billing.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id'];   // ✅ needed for foreign key

// Ensure required tables have company_id column
$tables = ['stitching_bill_items', 'jobs', 'accounts', 'parties', 'stitching_posted_bills'];
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
    header("Location: entry.php");
    exit;
}

// Fetch entry details with company check
$stmt = $conn->prepare("SELECT sbi.*, j.job_no, j.design_name, j.size, j.quantity, j.brand_name, j.fabric_name 
                        FROM stitching_bill_items sbi
                        LEFT JOIN jobs j ON sbi.job_no = j.job_no
                        WHERE sbi.id = ? AND sbi.company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();

if (!$entry) {
    header("Location: entry.php");
    exit;
}
$stmt->close();

// Get existing totals for this job (stitching_depart, excluding current entry)
$existing_totals_stmt = $conn->prepare("SELECT 
    SUM(kurti_qty) as total_kurti,
    SUM(shalwar_qty) as total_shalwar,
    SUM(dupatta_qty) as total_dupatta,
    SUM(amount) as total_amount
    FROM stitching_bill_items 
    WHERE job_no = ? AND tab_type = 'stitching_depart' AND id != ? AND company_id = ?");
$existing_totals_stmt->bind_param("sii", $entry['job_no'], $id, $company_id);
$existing_totals_stmt->execute();
$totals_res = $existing_totals_stmt->get_result()->fetch_assoc();
$existing_totals_stmt->close();

$existing_kurti = intval($totals_res['total_kurti'] ?? 0);
$existing_shalwar = intval($totals_res['total_shalwar'] ?? 0);
$existing_dupatta = intval($totals_res['total_dupatta'] ?? 0);
$existing_amount = floatval($totals_res['total_amount'] ?? 0);

// Verify job belongs to company (already ensured via join)
$job_qty = intval($entry['quantity'] ?? 0);
$parties_stmt = $conn->prepare("SELECT account_name FROM accounts WHERE company_id = ? ORDER BY account_name ASC");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties = $parties_stmt->get_result();

// Calculate remaining for each type
$remaining_kurti = max(0, $job_qty - $existing_kurti);
$remaining_shalwar = max(0, $job_qty - $existing_shalwar);
$remaining_dupatta = max(0, $job_qty - $existing_dupatta);

// Handle update
if (isset($_POST['update'])) {
    $job_no = trim($_POST['job_no'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $kurti_qty = floatval($_POST['kurti_qty'] ?? 0);
    $kurti_rate = floatval($_POST['kurti_rate'] ?? 0);
    $shalwar_qty = floatval($_POST['shalwar_qty'] ?? 0);
    $shalwar_rate = floatval($_POST['shalwar_rate'] ?? 0);
    $dupatta_qty = floatval($_POST['dupatta_qty'] ?? 0);
    $dupatta_rate = floatval($_POST['dupatta_rate'] ?? 0);
    
    $new_total_qty = $kurti_qty + $shalwar_qty + $dupatta_qty;
    $new_amount = ($kurti_qty * $kurti_rate) + ($shalwar_qty * $shalwar_rate) + ($dupatta_qty * $dupatta_rate);
    
    // Verify party exists in accounts (with company filter)
    $party_check = $conn->prepare("SELECT id FROM accounts WHERE account_name = ? AND company_id = ?");
    $party_check->bind_param("si", $name, $company_id);
    $party_check->execute();
    if ($party_check->get_result()->num_rows == 0) {
        $error = "Party '$name' does not exist. Please add it first in Parties section.";
    } else {
        // Calculate totals after update for each type
        $total_after_kurti = $existing_kurti + $kurti_qty;
        $total_after_shalwar = $existing_shalwar + $shalwar_qty;
        $total_after_dupatta = $existing_dupatta + $dupatta_qty;
        
        $error_msg = "";
        if ($job_qty > 0 && $total_after_kurti > $job_qty) {
            $error_msg = "Kurti quantity limit exceeded! Job total: $job_qty pcs, Other entries: $existing_kurti pcs, New: $kurti_qty pcs would make $total_after_kurti pcs.";
        } elseif ($job_qty > 0 && $total_after_shalwar > $job_qty) {
            $error_msg = "Shalwar quantity limit exceeded! Job total: $job_qty pcs, Other entries: $existing_shalwar pcs, New: $shalwar_qty pcs would make $total_after_shalwar pcs.";
        } elseif ($job_qty > 0 && $total_after_dupatta > $job_qty) {
            $error_msg = "Dupatta quantity limit exceeded! Job total: $job_qty pcs, Other entries: $existing_dupatta pcs, New: $dupatta_qty pcs would make $total_after_dupatta pcs.";
        }
        
        if (empty($error_msg)) {
            // Update stitching_bill_items
            $update_stmt = $conn->prepare("UPDATE stitching_bill_items SET
                       name = ?,
                       department = ?,
                       color = ?,
                       kurti_qty = ?,
                       kurti_rate = ?,
                       shalwar_qty = ?,
                       shalwar_rate = ?,
                       dupatta_qty = ?,
                       dupatta_rate = ?,
                       qty = ?,
                       amount = ?
                       WHERE id = ? AND company_id = ?");
            $update_stmt->bind_param("sssddddddddii", 
                $name, $department, $color, $kurti_qty, $kurti_rate, 
                $shalwar_qty, $shalwar_rate, $dupatta_qty, $dupatta_rate, 
                $new_total_qty, $new_amount, $id, $company_id);
            
            if ($update_stmt->execute()) {
                // Update stitching_posted_bills for this specific name (company filtered)
                $name_total_stmt = $conn->prepare("SELECT SUM(qty) as total_qty, SUM(amount) as total_amount 
                                                  FROM stitching_bill_items 
                                                  WHERE job_no = ? 
                                                  AND tab_type = 'stitching_depart'
                                                  AND name = ?
                                                  AND company_id = ?");
                $name_total_stmt->bind_param("ssi", $job_no, $name, $company_id);
                $name_total_stmt->execute();
                $name_total = $name_total_stmt->get_result()->fetch_assoc();
                $name_total_stmt->close();
                
                $name_total_qty = intval($name_total['total_qty'] ?? 0);
                $name_total_amount = floatval($name_total['total_amount'] ?? 0);
                $name_rate = ($name_total_qty > 0) ? ($name_total_amount / $name_total_qty) : 0;
                
                // Check if posted bill exists
                $check_bill = $conn->prepare("SELECT id FROM stitching_posted_bills 
                                            WHERE job_no = ? 
                                            AND claim_type = 'Stitching Bill'
                                            AND emp_name = ?
                                            AND company_id = ?
                                            LIMIT 1");
                $check_bill->bind_param("ssi", $job_no, $name, $company_id);
                $check_bill->execute();
                $bill_row = $check_bill->get_result()->fetch_assoc();
                $check_bill->close();
                
                if ($bill_row) {
                    $update_bill = $conn->prepare("UPDATE stitching_posted_bills SET 
                                                  qty = ?,
                                                  total_amount = ?,
                                                  rate = ?,
                                                  post_date = CURDATE(),
                                                  status = 'pending'
                                                  WHERE id = ? AND company_id = ?");
                    $update_bill->bind_param("dddii", $name_total_qty, $name_total_amount, $name_rate, $bill_row['id'], $company_id);
                    $update_bill->execute();
                    $update_bill->close();
                } else {
                    // Get job details for new bill
                    $job_details_stmt = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ?");
                    $job_details_stmt->bind_param("si", $job_no, $company_id);
                    $job_details_stmt->execute();
                    $job_details = $job_details_stmt->get_result()->fetch_assoc();
                    $job_details_stmt->close();
                    
                    $serial_no = $job_details['serial_no'] ?? 'SERIAL-' . date('Ymd');
                    $descr = "Stitching bill for job $job_no - $name";
                    
                    // Assign nullable fields to simple variables (required for bind_param)
                    $design_name = $job_details['design_name'] ?? '';
                    $fabric_name = $job_details['fabric_name'] ?? '';
                    $size        = $job_details['size'] ?? '';
                    $brand_name  = $job_details['brand_name'] ?? '';
                    
                    // ✅ Add user_id column and placeholder
                    $insert_bill = $conn->prepare("INSERT INTO stitching_posted_bills 
                        (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, 
                         claim_type, claim_date, design_name, fabric_name, size, brand_name, status, 
                         manual_total, auto_total, difference_total, post_date, company_id, user_id) 
                        VALUES (?, ?, ?, 'Stitching Charges', ?, ?, ?, ?, 'Stitching Bill', CURDATE(), ?, ?, ?, ?, 'pending', ?, 0, ?, CURDATE(), ?, ?)");
                    // Updated bind_param: added one "i" for user_id
                    $insert_bill->bind_param("sssdddsssssddii", 
                        $job_no, $serial_no, $name, $name_total_qty, $name_rate, $name_total_amount, $descr,
                        $design_name, $fabric_name, $size, $brand_name,
                        $name_total_amount, $name_total_amount, $company_id, $user_id);
                    $insert_bill->execute();
                    $insert_bill->close();
                }
                
                $success = "Entry updated successfully!";
                
                // Refresh entry data
                $refresh_stmt = $conn->prepare("SELECT sbi.*, j.job_no, j.design_name, j.size, j.quantity, j.brand_name, j.fabric_name 
                                                FROM stitching_bill_items sbi
                                                LEFT JOIN jobs j ON sbi.job_no = j.job_no
                                                WHERE sbi.id = ? AND sbi.company_id = ?");
                $refresh_stmt->bind_param("ii", $id, $company_id);
                $refresh_stmt->execute();
                $entry = $refresh_stmt->get_result()->fetch_assoc();
                $refresh_stmt->close();
                
                // Re‑fetch existing totals
                $refresh_totals = $conn->prepare("SELECT 
                    SUM(kurti_qty) as total_kurti,
                    SUM(shalwar_qty) as total_shalwar,
                    SUM(dupatta_qty) as total_dupatta,
                    SUM(amount) as total_amount
                    FROM stitching_bill_items 
                    WHERE job_no = ? AND tab_type = 'stitching_depart' AND id != ? AND company_id = ?");
                $refresh_totals->bind_param("sii", $entry['job_no'], $id, $company_id);
                $refresh_totals->execute();
                $totals_ref = $refresh_totals->get_result()->fetch_assoc();
                $refresh_totals->close();
                
                $existing_kurti = intval($totals_ref['total_kurti'] ?? 0);
                $existing_shalwar = intval($totals_ref['total_shalwar'] ?? 0);
                $existing_dupatta = intval($totals_ref['total_dupatta'] ?? 0);
                $existing_amount = floatval($totals_ref['total_amount'] ?? 0);
                
                $remaining_kurti = max(0, $job_qty - $existing_kurti);
                $remaining_shalwar = max(0, $job_qty - $existing_shalwar);
                $remaining_dupatta = max(0, $job_qty - $existing_dupatta);
                
            } else {
                $error = "Error updating entry: " . $update_stmt->error;
            }
            $update_stmt->close();
        } else {
            $error = $error_msg;
        }
    }
    $party_check->close();
}

// Calculate current values for display
$current_kurti_qty = $entry['kurti_qty'] ?? 0;
$current_shalwar_qty = $entry['shalwar_qty'] ?? 0;
$current_dupatta_qty = $entry['dupatta_qty'] ?? 0;
$current_entry_qty = $entry['qty'] ?? 0;
$total_kurti_so_far = $existing_kurti + $current_kurti_qty;
$total_shalwar_so_far = $existing_shalwar + $current_shalwar_qty;
$total_dupatta_so_far = $existing_dupatta + $current_dupatta_qty;
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Stitching Entry</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged) */
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #B26000;
        --border: #E5E7E9;
        --bg-light: #F8F9F9;
        --text-dark: #2C3E50;
        --success: #27ae60;
        --danger: #e74c3c;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', system-ui, sans-serif;
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
        font-size: 1.8rem;
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
        border-radius: 12px;
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
        font-size: 1.2rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--primary-dark);
    }

    .card-header h4 i {
        color: var(--primary);
    }

    .card-body {
        padding: 24px;
    }

    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #666;
        margin-bottom: 6px;
    }

    .form-label i {
        color: var(--primary);
        width: 18px;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 8px 12px;
        border: 1.5px solid var(--border);
        border-radius: 8px;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
    }

    .form-control.qty-exceed {
        border-color: var(--danger);
        background-color: #fff3f3;
    }

    .job-info {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 15px 20px;
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 25px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 0.7rem;
        color: #666;
        text-transform: uppercase;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary-dark);
    }

    .qty-summary {
        background: var(--primary-light);
        border: 1px solid var(--primary);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .qty-summary h5 {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--primary-dark);
        margin-bottom: 12px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 5px 0;
        border-bottom: 1px dashed #ddd;
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-label {
        font-weight: 500;
    }

    .summary-value {
        font-weight: 600;
        color: var(--primary-dark);
    }

    .summary-value.remaining {
        color: var(--success);
    }

    .warning-message {
        background: #fee;
        border: 1px solid #fcc;
        color: #c00;
        padding: 10px 15px;
        border-radius: 8px;
        margin: 15px 0;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .total-display {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 12px 18px;
        margin: 15px 0;
        font-size: 1rem;
        font-weight: 600;
    }

    .total-display i {
        color: var(--primary);
        margin-right: 8px;
    }

    .total-display .amount {
        color: var(--success);
        font-size: 1.2rem;
    }

    .btn {
        padding: 10px 24px;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
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
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #95a5a6;
        color: white;
    }

    .btn-secondary:hover {
        background: #7f8c8d;
        transform: translateY(-1px);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #c0392b;
        transform: translateY(-1px);
    }

    .btn:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
    }

    .alert {
        padding: 12px 18px;
        border-radius: 10px;
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

    .row {
        display: flex;
        flex-wrap: wrap;
        margin: -8px;
    }

    .col-md-4 {
        width: 33.333%;
        padding: 8px;
    }

    .col-md-12 {
        width: 100%;
        padding: 8px;
    }

    .mt-4 { margin-top: 20px; }
    .d-flex { display: flex; }
    .gap-2 { gap: 10px; }
    .flex-wrap { flex-wrap: wrap; }
    .ms-auto { margin-left: auto; }
    .text-muted { color: #666; font-size: 0.75rem; margin-top: 4px; }
    .text-center { text-align: center; }

    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; }
    }

    @media (max-width: 900px) {
        .main-container { margin-left: 0; padding: 16px; }
        .col-md-4 { width: 100%; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .btn { width: 100%; justify-content: center; }
        .job-info { flex-direction: column; gap: 12px; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-edit"></i> Edit Stitching Entry</h2>
        <a href="stiching.php?left_job_no=<?= urlencode($entry['job_no']) ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Stitching
        </a>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-tshirt"></i> Edit Stitching Entry #<?= $id ?></h4>
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
                    <span class="info-label">Brand</span>
                    <span class="info-value"><?= htmlspecialchars($entry['brand_name'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Size</span>
                    <span class="info-value"><?= htmlspecialchars($entry['size'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Fabric</span>
                    <span class="info-value"><?= htmlspecialchars($entry['fabric_name'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Job Quantity</span>
                    <span class="info-value"><?= $job_qty ?> pcs</span>
                </div>
            </div>

           
            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="job_no" value="<?= htmlspecialchars($entry['job_no']) ?>">

                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-user"></i> Name *</label>
                        <input type="text" name="name" class="form-control" list="partyList" 
                               value="<?= htmlspecialchars($entry['name']) ?>" required>
                        <small class="text-muted">Party name from accounts</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-building"></i> Department</label>
                        <input type="text" name="department" class="form-control" 
                               value="<?= htmlspecialchars($entry['department'] ?? '') ?>" placeholder="Optional">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-palette"></i> Color</label>
                        <input type="text" name="color" class="form-control" 
                               value="<?= htmlspecialchars($entry['color'] ?? '') ?>" placeholder="Optional">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-tshirt"></i> Kurti Qty <span class="text-muted">(Max: <?= $remaining_kurti ?>)</span></label>
                        <input type="number" step="1" min="0" name="kurti_qty" class="form-control" 
                               id="kurtiQty" value="<?= $entry['kurti_qty'] ?>" oninput="calculateTotal(); checkKurtiQty()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> Kurti Rate</label>
                        <input type="number" step="0.01" min="0" name="kurti_rate" class="form-control" 
                               id="kurtiRate" value="<?= $entry['kurti_rate'] ?>" oninput="calculateTotal()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-chart-line"></i> Kurti Amount</label>
                        <input type="text" class="form-control" id="kurtiAmount" readonly 
                               value="<?= number_format($entry['kurti_qty'] * $entry['kurti_rate'], 2) ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-shoe-prints"></i> Shalwar Qty <span class="text-muted">(Max: <?= $remaining_shalwar ?>)</span></label>
                        <input type="number" step="1" min="0" name="shalwar_qty" class="form-control" 
                               id="shalwarQty" value="<?= $entry['shalwar_qty'] ?>" oninput="calculateTotal(); checkShalwarQty()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> Shalwar Rate</label>
                        <input type="number" step="0.01" min="0" name="shalwar_rate" class="form-control" 
                               id="shalwarRate" value="<?= $entry['shalwar_rate'] ?>" oninput="calculateTotal()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-chart-line"></i> Shalwar Amount</label>
                        <input type="text" class="form-control" id="shalwarAmount" readonly 
                               value="<?= number_format($entry['shalwar_qty'] * $entry['shalwar_rate'], 2) ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-scarf"></i> Dupatta Qty <span class="text-muted">(Max: <?= $remaining_dupatta ?>)</span></label>
                        <input type="number" step="1" min="0" name="dupatta_qty" class="form-control" 
                               id="dupattaQty" value="<?= $entry['dupatta_qty'] ?>" oninput="calculateTotal(); checkDupattaQty()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> Dupatta Rate</label>
                        <input type="number" step="0.01" min="0" name="dupatta_rate" class="form-control" 
                               id="dupattaRate" value="<?= $entry['dupatta_rate'] ?>" oninput="calculateTotal()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-chart-line"></i> Dupatta Amount</label>
                        <input type="text" class="form-control" id="dupattaAmount" readonly 
                               value="<?= number_format($entry['dupatta_qty'] * $entry['dupatta_rate'], 2) ?>">
                    </div>
                </div>

                <!-- Warning Messages -->
                <div id="kurtiWarning" class="warning-message" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span></span>
                </div>
                <div id="shalwarWarning" class="warning-message" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span></span>
                </div>
                <div id="dupattaWarning" class="warning-message" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span></span>
                </div>

                <!-- Total Display -->
                <div class="total-display">
                    <i class="fas fa-calculator"></i>
                    This Entry: <strong id="totalQty"><?= $entry['qty'] ?></strong> pcs &nbsp;|&nbsp;
                    Amount: <strong class="amount" id="totalAmount">Rs. <?= number_format($entry['amount'], 2) ?></strong>
                </div>

                <!-- Form Buttons -->
                <div class="mt-4 d-flex gap-2 flex-wrap">
                    <button type="submit" name="update" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Update Entry
                    </button>
                    <a href="entry.php?left_job_no=<?= urlencode($entry['job_no']) ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <a href="delete_stitching_entry.php?id=<?= $id ?>&job_no=<?= urlencode($entry['job_no']) ?>" 
                       class="btn btn-danger ms-auto" 
                       onclick="return confirm('Are you sure you want to delete this entry? This action cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete Entry
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Datalist for suggestions -->
<datalist id="partyList">
    <?php $parties->data_seek(0); while ($party = $parties->fetch_assoc()): ?>
    <option value="<?= htmlspecialchars($party['account_name']) ?>">
    <?php endwhile; ?>
</datalist>

<script>
const jobQty = <?= $job_qty ?>;
const existingKurti = <?= $existing_kurti ?>;
const existingShalwar = <?= $existing_shalwar ?>;
const existingDupatta = <?= $existing_dupatta ?>;

let kurtiValid = true;
let shalwarValid = true;
let dupattaValid = true;

function calculateTotal() {
    const kurtiQty = parseFloat(document.getElementById('kurtiQty').value) || 0;
    const kurtiRate = parseFloat(document.getElementById('kurtiRate').value) || 0;
    const shalwarQty = parseFloat(document.getElementById('shalwarQty').value) || 0;
    const shalwarRate = parseFloat(document.getElementById('shalwarRate').value) || 0;
    const dupattaQty = parseFloat(document.getElementById('dupattaQty').value) || 0;
    const dupattaRate = parseFloat(document.getElementById('dupattaRate').value) || 0;
    
    const kurtiAmount = kurtiQty * kurtiRate;
    const shalwarAmount = shalwarQty * shalwarRate;
    const dupattaAmount = dupattaQty * dupattaRate;
    
    document.getElementById('kurtiAmount').value = kurtiAmount.toFixed(2);
    document.getElementById('shalwarAmount').value = shalwarAmount.toFixed(2);
    document.getElementById('dupattaAmount').value = dupattaAmount.toFixed(2);
    
    const totalQty = kurtiQty + shalwarQty + dupattaQty;
    const totalAmount = kurtiAmount + shalwarAmount + dupattaAmount;
    
    document.getElementById('totalQty').textContent = totalQty;
    document.getElementById('totalAmount').innerHTML = 'Rs. ' + totalAmount.toFixed(2);
}

function checkKurtiQty() {
    const kurtiQty = parseFloat(document.getElementById('kurtiQty').value) || 0;
    const totalAfter = existingKurti + kurtiQty;
    const warningDiv = document.getElementById('kurtiWarning');
    const warningSpan = warningDiv.querySelector('span');
    const qtyInput = document.getElementById('kurtiQty');
    
    if(jobQty > 0 && totalAfter > jobQty) {
        warningSpan.innerHTML = `⚠️ Kurti quantity limit exceeded! Job total: ${jobQty} pcs, Other entries: ${existingKurti} pcs, This entry: ${kurtiQty} pcs would make ${totalAfter} pcs. Max allowed: ${jobQty - existingKurti} pcs`;
        warningDiv.style.display = 'flex';
        qtyInput.classList.add('qty-exceed');
        kurtiValid = false;
    } else {
        warningDiv.style.display = 'none';
        qtyInput.classList.remove('qty-exceed');
        kurtiValid = true;
    }
    updateSaveButton();
}

function checkShalwarQty() {
    const shalwarQty = parseFloat(document.getElementById('shalwarQty').value) || 0;
    const totalAfter = existingShalwar + shalwarQty;
    const warningDiv = document.getElementById('shalwarWarning');
    const warningSpan = warningDiv.querySelector('span');
    const qtyInput = document.getElementById('shalwarQty');
    
    if(jobQty > 0 && totalAfter > jobQty) {
        warningSpan.innerHTML = `⚠️ Shalwar quantity limit exceeded! Job total: ${jobQty} pcs, Other entries: ${existingShalwar} pcs, This entry: ${shalwarQty} pcs would make ${totalAfter} pcs. Max allowed: ${jobQty - existingShalwar} pcs`;
        warningDiv.style.display = 'flex';
        qtyInput.classList.add('qty-exceed');
        shalwarValid = false;
    } else {
        warningDiv.style.display = 'none';
        qtyInput.classList.remove('qty-exceed');
        shalwarValid = true;
    }
    updateSaveButton();
}

function checkDupattaQty() {
    const dupattaQty = parseFloat(document.getElementById('dupattaQty').value) || 0;
    const totalAfter = existingDupatta + dupattaQty;
    const warningDiv = document.getElementById('dupattaWarning');
    const warningSpan = warningDiv.querySelector('span');
    const qtyInput = document.getElementById('dupattaQty');
    
    if(jobQty > 0 && totalAfter > jobQty) {
        warningSpan.innerHTML = `⚠️ Dupatta quantity limit exceeded! Job total: ${jobQty} pcs, Other entries: ${existingDupatta} pcs, This entry: ${dupattaQty} pcs would make ${totalAfter} pcs. Max allowed: ${jobQty - existingDupatta} pcs`;
        warningDiv.style.display = 'flex';
        qtyInput.classList.add('qty-exceed');
        dupattaValid = false;
    } else {
        warningDiv.style.display = 'none';
        qtyInput.classList.remove('qty-exceed');
        dupattaValid = true;
    }
    updateSaveButton();
}

function updateSaveButton() {
    const saveBtn = document.getElementById('saveBtn');
    if(kurtiValid && shalwarValid && dupattaValid) {
        saveBtn.disabled = false;
    } else {
        saveBtn.disabled = true;
    }
}

function validateForm() {
    const kurtiQty = parseFloat(document.getElementById('kurtiQty').value) || 0;
    const shalwarQty = parseFloat(document.getElementById('shalwarQty').value) || 0;
    const dupattaQty = parseFloat(document.getElementById('dupattaQty').value) || 0;
    
    if(kurtiQty === 0 && shalwarQty === 0 && dupattaQty === 0) {
        alert('Please enter at least one quantity');
        return false;
    }
    
    const totalKurti = existingKurti + kurtiQty;
    const totalShalwar = existingShalwar + shalwarQty;
    const totalDupatta = existingDupatta + dupattaQty;
    
    if(jobQty > 0 && totalKurti > jobQty) {
        alert(`Kurti quantity limit exceeded! Job total: ${jobQty} pcs, Other entries: ${existingKurti} pcs, You entered: ${kurtiQty} pcs would make ${totalKurti} pcs.`);
        return false;
    }
    
    if(jobQty > 0 && totalShalwar > jobQty) {
        alert(`Shalwar quantity limit exceeded! Job total: ${jobQty} pcs, Other entries: ${existingShalwar} pcs, You entered: ${shalwarQty} pcs would make ${totalShalwar} pcs.`);
        return false;
    }
    
    if(jobQty > 0 && totalDupatta > jobQty) {
        alert(`Dupatta quantity limit exceeded! Job total: ${jobQty} pcs, Other entries: ${existingDupatta} pcs, You entered: ${dupattaQty} pcs would make ${totalDupatta} pcs.`);
        return false;
    }
    
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();
    checkKurtiQty();
    checkShalwarQty();
    checkDupattaQty();
});
</script>

</body>
</html>