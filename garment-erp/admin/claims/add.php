<?php
// Explicit page identifier for permission system
$page_identifier = 'claims/add.php';

// Include database and functions FIRST
require_once "../../config/db.php";
require_once "../../includes/functions.php";

// Include authentication (handles login & permission)
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

$job_no = isset($_GET['job_no']) ? $_GET['job_no'] : '';
$serial_search = isset($_GET['serial']) ? $_GET['serial'] : '';

// if serial provided, find job_no (with company filter)
if (!empty($serial_search) && empty($job_no)) {
    $ser_esc = mysqli_real_escape_string($conn, $serial_search);
    $stmt = $conn->prepare("SELECT job_no FROM jobs WHERE serial_no = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("si", $ser_esc, $company_id);
    $stmt->execute();
    $job_row = $stmt->get_result()->fetch_assoc();
    if ($job_row) $job_no = $job_row['job_no'];
}

// fetch job information (with company filter)
$jobInfo = null;
if ($job_no) {
    $stmt = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $jobInfo = $stmt->get_result()->fetch_assoc();
}

// Get claimed quantity, production quantity, etc. (with company filter)
$claimed_qty = 0;
$remaining_qty = 0;
$production_qty = 0;
$total_qty = 0;

if ($jobInfo) {
    $stmt = $conn->prepare("SELECT SUM(qty) as total_claimed FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'claim_billing' AND company_id = ?");
    $stmt->bind_param("si", $jobInfo['job_no'], $company_id);
    $stmt->execute();
    $claim_data = $stmt->get_result()->fetch_assoc();
    $claimed_qty = floatval($claim_data['total_claimed'] ?? 0);

    $stmt = $conn->prepare("SELECT SUM(qty) as total_production FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'production_billing' AND company_id = ?");
    $stmt->bind_param("si", $jobInfo['job_no'], $company_id);
    $stmt->execute();
    $prod_data = $stmt->get_result()->fetch_assoc();
    $production_qty = floatval($prod_data['total_production'] ?? 0);

    $total_qty = floatval($jobInfo['quantity'] ?? 0);
    $remaining_qty = max(0, $total_qty - ($production_qty + $claimed_qty));
}

// Get per-piece summary for costing tables (auto rates)
$stitchingCosts = [];
if ($job_no) {
    $stitchingCosts = getPerPieceSummaryByType($conn, $job_no);

    $stmt = $conn->prepare("SELECT SUM(total_meter_with_color) as total_fabric, SUM(amount) as total_amount FROM fabric_issue WHERE job_no = ? AND company_id = ?");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $fabric_data = $stmt->get_result()->fetch_assoc();
    if ($fabric_data && $fabric_data['total_amount'] > 0) {
        $job_qty = $jobInfo['quantity'] ?? 1;
        if ($job_qty > 0) {
            $stitchingCosts['fabric_issue']['per_piece'] = $fabric_data['total_amount'] / $job_qty;
            $stitchingCosts['fabric_issue']['total'] = $fabric_data['total_amount'];
        }
    }
}

// Fetch manual costs for job
$manual_costs = [];
if ($job_no) {
    $stmt = $conn->prepare("SELECT * FROM manual_costing WHERE job_no = ? AND company_id = ?");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $cost_result = $stmt->get_result();
    while ($cost = $cost_result->fetch_assoc()) {
        $manual_costs[$cost['cost_type']] = $cost;
    }
}

// Fetch employees (accounts) from same company
$employees_query = $conn->prepare("SELECT account_name FROM accounts WHERE account_type IN ('employee', 'vendor', 'customer') AND company_id = ? ORDER BY account_name");
$employees_query->bind_param("i", $company_id);
$employees_query->execute();
$employees_result = $employees_query->get_result();
$employees_list = [];
while ($emp = $employees_result->fetch_assoc()) {
    $employees_list[] = $emp['account_name'];
}

// Fetch all jobs for suggestions (only from same company)
$jobs_suggest_query = $conn->prepare("SELECT job_no FROM jobs WHERE company_id = ? ORDER BY job_no");
$jobs_suggest_query->bind_param("i", $company_id);
$jobs_suggest_query->execute();
$jobs_suggest_result = $jobs_suggest_query->get_result();
$jobs_suggest_list = [];
while ($job_suggest = $jobs_suggest_result->fetch_assoc()) {
    $jobs_suggest_list[] = $job_suggest['job_no'];
}

// Save manual costing (AJAX endpoint)
if (isset($_POST['save_manual_costs'])) {
    $job_no = mysqli_real_escape_string($conn, $_POST['job_no']);
    $cost_data = json_decode($_POST['cost_data'], true);
    foreach ($cost_data as $cost) {
        $type = mysqli_real_escape_string($conn, $cost['type']);
        $manual_rate = floatval($cost['manual_rate']);
        $auto_rate = floatval($cost['auto_rate']);
        $diff = $auto_rate - $manual_rate;

        $check = $conn->prepare("SELECT id FROM manual_costing WHERE job_no = ? AND cost_type = ? AND company_id = ?");
        $check->bind_param("ssi", $job_no, $type, $company_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $update = $conn->prepare("UPDATE manual_costing SET manual_rate = ?, auto_rate = ?, difference = ?, is_edited = 1 WHERE job_no = ? AND cost_type = ? AND company_id = ?");
            $update->bind_param("ddsssi", $manual_rate, $auto_rate, $diff, $job_no, $type, $company_id);
            $update->execute();
        } else {
            $insert = $conn->prepare("INSERT INTO manual_costing (job_no, cost_type, manual_rate, auto_rate, difference, is_edited, company_id) VALUES (?, ?, ?, ?, ?, 1, ?)");
            $insert->bind_param("ssdddi", $job_no, $type, $manual_rate, $auto_rate, $diff, $company_id);
            $insert->execute();
        }
    }
    echo "success";
    exit;
}

// Handle claim submission
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['save_claim'])) {
    $job_no = mysqli_real_escape_string($conn, $_POST['job_no']);
    $serial_no = mysqli_real_escape_string($conn, $_POST['serial_no']);
    $claim_date = mysqli_real_escape_string($conn, $_POST['claim_date']);
    $claim_item = mysqli_real_escape_string($conn, $_POST['claim_item']);
    $qty = floatval($_POST['qty']);
    $rate = floatval($_POST['rate']);
    $emp_name = mysqli_real_escape_string($conn, $_POST['emp_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $amount = $qty * $rate;

    // Get the logged-in user ID from session
    $user_id = (int)$_SESSION['user_id'];

    $job_stmt = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ?");
    $job_stmt->bind_param("si", $job_no, $company_id);
    $job_stmt->execute();
    $job_details = $job_stmt->get_result()->fetch_assoc();

    if (!$job_details) {
        $error_message = "Job not found or does not belong to your company!";
    } else {
        $emp_check = $conn->prepare("SELECT account_name FROM accounts WHERE account_name = ? AND company_id = ? LIMIT 1");
        $emp_check->bind_param("si", $emp_name, $company_id);
        $emp_check->execute();
        if ($emp_check->get_result()->num_rows == 0) {
            $error_message = "Employee '$emp_name' does not exist in the system for your company.";
        } else {
            $prod_stmt = $conn->prepare("SELECT SUM(qty) as total_production FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'production_billing' AND company_id = ?");
            $prod_stmt->bind_param("si", $job_no, $company_id);
            $prod_stmt->execute();
            $prod_data = $prod_stmt->get_result()->fetch_assoc();
            $production_qty = floatval($prod_data['total_production'] ?? 0);

            $existing_claim_stmt = $conn->prepare("SELECT SUM(qty) as total_claimed FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'claim_billing' AND company_id = ?");
            $existing_claim_stmt->bind_param("si", $job_no, $company_id);
            $existing_claim_stmt->execute();
            $existing_claim = $existing_claim_stmt->get_result()->fetch_assoc();
            $total_claimed_so_far = floatval($existing_claim['total_claimed'] ?? 0);

            $total_used = $production_qty + $total_claimed_so_far;
            $remaining = $job_details['quantity'] - $total_used;

            if ($remaining <= 0) {
                $error_message = "Cannot add claim! All pieces have been used.";
            } elseif ($qty > $remaining) {
                $error_message = "Quantity limit exceeded! Remaining: $remaining pcs.";
            } else {
                $conn->begin_transaction();
                try {
                    // Insert into stitching_bill_items (includes user_id)
                    $insert_items = $conn->prepare("INSERT INTO stitching_bill_items 
                        (job_no, tab_type, name, part_name, qty, rate, amount, created_at, company_id, user_id) 
                        VALUES (?, 'claim_billing', ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_items->bind_param("sssdddsii",
                        $job_no, $emp_name, $claim_item, $qty, $rate, $amount, $claim_date, $company_id, $user_id);
                    if (!$insert_items->execute()) throw new Exception("Error inserting claim: " . $insert_items->error);

                    // Insert into claims table (includes user_id)
                    $job_id = $job_details['id'];
                    $insert_claim = $conn->prepare("INSERT INTO claims 
                        (job_id, job_no, serial_no, claim_item, qty, rate, amount, claim_date, 
                         created_at, claim_type, description, emp_name, status, company_id, user_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'pending', ?, ?)");
                    $insert_claim->bind_param("isssdddssssii",
                        $job_id, $job_no, $serial_no, $claim_item, $qty, $rate, $amount, $claim_date,
                        $claim_item, $description, $emp_name, $company_id, $user_id);
                    if (!$insert_claim->execute()) throw new Exception("Error inserting into claims: " . $insert_claim->error);

                    // ✅ Update or insert into stitching_posted_bills (claim_type = 'claim' always)
                    $check_bill = $conn->prepare("SELECT id, total_amount, qty as existing_qty FROM stitching_posted_bills WHERE job_no = ? AND claim_type = 'claim' AND company_id = ?");
                    $check_bill->bind_param("si", $job_no, $company_id);
                    $check_bill->execute();
                    $existing_bill = $check_bill->get_result()->fetch_assoc();

                    if ($existing_bill) {
                        $new_total = floatval($existing_bill['total_amount']) + $amount;
                        $new_qty = floatval($existing_bill['existing_qty']) + $qty;
                        $update_bill = $conn->prepare("UPDATE stitching_posted_bills SET 
                            emp_name = ?, qty = ?, rate = ?, total_amount = ?, 
                            description = CONCAT(IFNULL(description,''), ' | New Claim: $claim_item Qty:$qty Rate:$rate'),
                            claim_date = ?, status = 'pending', claim_type = 'claim'
                            WHERE id = ? AND company_id = ?");
                        $update_bill->bind_param("sdddssi",
                            $emp_name, $new_qty, $rate, $new_total, $claim_date, $existing_bill['id'], $company_id);
                        if (!$update_bill->execute()) throw new Exception("Error updating bill: " . $update_bill->error);
                    } else {
                        // Fix: assign all dynamic values to plain variables first
                        $bill_description = "Claim: $claim_item, Qty: $qty, Rate: $rate, Employee: $emp_name";
                        $design_name     = $job_details['design_name'] ?? '';
                        $fabric_name     = $job_details['fabric_name'] ?? '';
                        $size_name       = $job_details['size'] ?? '';
                        $brand_name      = $job_details['brand_name'] ?? '';

                        // INSERT with claim_type = 'claim'
                        $insert_bill = $conn->prepare("INSERT INTO stitching_posted_bills 
                            (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, 
                             claim_date, description, claim_type, design_name, fabric_name, size, brand_name, status, company_id, user_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'claim', ?, ?, ?, ?, 'pending', ?, ?)");
                        $insert_bill->bind_param("ssssdddssssssii",
                            $job_no, $serial_no, $emp_name, $claim_item, $qty, $rate, $amount, $claim_date,
                            $bill_description, $design_name, $fabric_name,
                            $size_name, $brand_name, $company_id, $user_id);
                        if (!$insert_bill->execute()) throw new Exception("Error inserting bill: " . $insert_bill->error);
                    }

                    $conn->commit();
                    $success_message = "Claim added successfully!";
                    // Refresh data
                    $claim_sum = $conn->prepare("SELECT SUM(qty) as total_claimed FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'claim_billing' AND company_id = ?");
                    $claim_sum->bind_param("si", $job_no, $company_id);
                    $claim_sum->execute();
                    $claim_data = $claim_sum->get_result()->fetch_assoc();
                    $claimed_qty = floatval($claim_data['total_claimed'] ?? 0);
                    $remaining_qty = max(0, $total_qty - ($production_qty + $claimed_qty));

                    echo "<script>
                            alert('Claim added successfully!\\nRemaining quantity: " . $remaining_qty . " pcs');
                            window.location.href = 'add.php?job_no=" . urlencode($job_no) . "';
                          </script>";
                    exit();

                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Get recent claims (from same company)
$recent_claims = [];
if ($job_no) {
    $recent_stmt = $conn->prepare("SELECT * FROM stitching_bill_items 
                                   WHERE job_no = ? AND tab_type = 'claim_billing' AND company_id = ? 
                                   ORDER BY created_at DESC LIMIT 10");
    $recent_stmt->bind_param("si", $job_no, $company_id);
    $recent_stmt->execute();
    $recent_result = $recent_stmt->get_result();
    while ($rc = $recent_result->fetch_assoc()) {
        $recent_claims[] = $rc;
    }
}

function fmt($num) { return number_format($num, 2); }
?>

<!-- HTML same as before -->

<!DOCTYPE html>
<html>
<head>
<title>Add Claim - Stitching Claim Entry</title>
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
    .costing-wrapper { background: white; border-radius: 20px; margin-bottom: 30px; box-shadow: 0 6px 14px rgba(0,0,0,0.05); overflow: hidden; position: sticky; top: 2px; z-index: 10; }
    .costing-row { display: flex; flex-wrap: wrap; }
    .costing-col { flex: 1; padding: 15px 12px; border-right: 1px solid #eceef2; }
    .costing-col:last-child { border-right: none; }
    .costing-search-area { display: flex; flex-direction: column; gap: 12px; }
    .search-dual { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
    .search-dual .search-group { flex: 1; min-width: 120px; }
    .search-group label { font-size: 0.7rem; font-weight: 700; color: #b45f06; margin-bottom: 4px; display: block; }
    .search-dual input { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 30px; font-size: 0.8rem; }
    .costing-load-btn { background: var(--primary); border: none; padding: 8px 24px; border-radius: 30px; color: white; font-weight: 600; cursor: pointer; height: 42px; white-space: nowrap; }
    .mini-job-info { background: #fff8f0; border-radius: 14px; padding: 8px 12px; font-size: 0.7rem; border: 1px solid #ffe0b5; margin-top: 8px; display: flex; gap: 10px; align-items: center; }
    .job-thumbnail { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 1px solid var(--primary); background: white; }
    .excel-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .excel-table th, .excel-table td { border: 1px solid #dee2e6; padding: 6px 10px; vertical-align: middle; }
    .excel-table th { background: #f8f9fc; font-weight: 700; text-align: center; border-bottom: 2px solid #f39c12; }
    .excel-table td:first-child, .excel-table th:first-child { text-align: left; font-weight: 600; background-color: #fcfcfc; }
    .excel-table td:nth-child(2), .excel-table td:nth-child(3), .excel-table td:nth-child(4),
    .excel-table th:nth-child(2), .excel-table th:nth-child(3), .excel-table th:nth-child(4) { text-align: right; }
    .excel-table tfoot tr.total-row { background-color: #fef5e7; font-weight: 800; border-top: 2px solid #f39c12; }
    .party-input { width: 85px; text-align: right; padding: 4px 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.8rem; }
    .edit-buttons { text-align: right; margin-bottom: 8px; }
    .btn-sm-custom { padding: 4px 14px; font-size: 0.7rem; border-radius: 30px; font-weight: 600; border: none; cursor: pointer; }
    .diff-positive { color: #27ae60; font-weight: 600; }
    .diff-negative { color: #e74c3c; font-weight: 600; }
    .card { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
    .card-header { padding: 16px 20px; background: white; border-bottom: 2px solid var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; }
    .card-header i { color: var(--primary); }
    .card-body { padding: 20px; }
    .job-info-box { background: linear-gradient(135deg, var(--primary-light) 0%, #fff2e0 100%); border: 1px solid var(--primary); border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .info-card { background: white; border-radius: 10px; padding: 10px 15px; border: 1px solid var(--primary); }
    .info-card .label { font-size: 0.65rem; color: #888; text-transform: uppercase; }
    .info-card .value { font-size: 1rem; font-weight: 700; color: var(--primary-dark); }
    .form-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #6c757d; margin-bottom: 6px; }
    .form-label i { color: var(--primary); width: 16px; }
    .form-control, .form-select { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 0.9rem; }
    .btn { padding: 10px 20px; font-size: 0.85rem; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-success { background: var(--success); color: white; }
    .btn-secondary { background: #e9ecef; color: var(--text-dark); }
    .row { display: flex; flex-wrap: wrap; margin: -8px; }
    .col-md-6, .col-md-12 { padding: 8px; }
    .col-md-6 { width: 50%; }
    .col-md-12 { width: 100%; }
    .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-danger { background: #fee; border-left: 4px solid var(--danger); color: #c00; }
    .alert-success { background: #efe; border-left: 4px solid var(--success); color: #090; }
    .recent-claims { margin-top: 25px; border-top: 2px solid var(--primary); padding-top: 15px; }
    .recent-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .recent-table th { background: var(--bg-light); padding: 10px; text-align: left; border-bottom: 2px solid var(--primary); }
    .recent-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); }
    @media (max-width: 1200px) { .main-container { margin-left: 10%; padding: 20px; } }
    @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .costing-row { flex-direction: column; } .col-md-6 { width: 100%; } }
</style>

<datalist id="jobSuggestList">
    <?php foreach ($jobs_suggest_list as $job): ?>
    <option value="<?= htmlspecialchars($job) ?>">
    <?php endforeach; ?>
</datalist>
<datalist id="employeeList">
    <?php foreach ($employees_list as $emp): ?>
    <option value="<?= htmlspecialchars($emp) ?>">
    <?php endforeach; ?>
</datalist>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <h2><i class="fas fa-file-invoice"></i> Stitching Claim Entry</h2>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <!-- Costing Table (same as original) -->
    <div class="costing-wrapper" id="costingWrapper">
        <div class="costing-row">
            <div class="costing-col">
                <div class="costing-search-area">
                    <div class="search-dual">
                        <div class="search-group"><label><i class="fas fa-hashtag"></i> Job #</label><input type="text" id="costingJobSearch" list="jobSuggestList" placeholder="Job number" value="<?= htmlspecialchars($job_no) ?>"></div>
                        <div class="search-group"><label><i class="fas fa-barcode"></i> Serial #</label><input type="text" id="costingSerialSearch" placeholder="Serial number" value="<?= htmlspecialchars($serial_search) ?>"></div>
                        <button class="costing-load-btn" onclick="loadCostingJob()"><i class="fas fa-search"></i> Load</button>
                    </div>
                    <div class="mini-job-info">
                        <?php 
                        $job_image = '';
                        if ($jobInfo && !empty($jobInfo['image'])) {
                            $job_image = "../../assets/uploads/" . $jobInfo['image'];
                            if (!file_exists($job_image)) $job_image = '';
                        }
                        if ($job_image): ?>
                            <img src="<?= $job_image ?>" class="job-thumbnail" alt="Job Image">
                        <?php endif; ?>
                        <table style="width:100%">
                            <tr><td style="width:35%">Job No</td><td><strong><?= htmlspecialchars($jobInfo['job_no'] ?? '') ?></strong></td><td style="width:35%">Serial</td><td><?= htmlspecialchars($jobInfo['serial_no'] ?? '—') ?></td></tr>
                            <tr><td>Design</td><td><?= htmlspecialchars($jobInfo['design_name'] ?? '—') ?></td><td>Size</td><td><?= htmlspecialchars($jobInfo['size'] ?? '—') ?></td></tr>
                            <tr><td>Fabric</td><td><?= htmlspecialchars($jobInfo['fabric_name'] ?? '—') ?></td><td>Qty</td><td><?= intval($jobInfo['quantity'] ?? 0) ?> pcs</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="costing-col">
                <div class="edit-buttons">
                    <button id="editRatesBtn" class="btn-sm-custom" style="background:#f39c12;color:#fff;" onclick="enableCostEditing()"><i class="fas fa-edit"></i> Edit Party Rates</button>
                    <button id="saveRatesBtn" class="btn-sm-custom" style="background:#27ae60;color:#fff;display:none;" onclick="saveAllRates()"><i class="fas fa-save"></i> Save</button>
                    <button id="cancelEditBtn" class="btn-sm-custom" style="background:#7f8c8d;color:#fff;display:none;" onclick="cancelCostEditing()"><i class="fas fa-times"></i> Cancel</button>
                </div>
                <div style="overflow-x:auto">
                    <table class="excel-table" id="costingTableMain">
                        <thead><tr><th>ITEM</th><th class="text-right">PARTY</th><th class="text-right">COSTING</th><th class="text-right">DIFF</th></tr></thead>
                        <tbody id="costingTbody">
                            <?php 
                            $costingItems = ['FABRIC', 'EMB', 'STITCHING', 'HANDWORK', 'MATERIAL', 'OTHER EXP'];
                            foreach ($costingItems as $item): 
                                $auto = 0;
                                if ($item == 'FABRIC') $auto = $stitchingCosts['fabric_issue']['per_piece'] ?? 0;
                                elseif ($item == 'EMB') $auto = $stitchingCosts['emb']['per_piece'] ?? 0;
                                elseif ($item == 'STITCHING') $auto = $stitchingCosts['stitching_depart']['per_piece'] ?? 0;
                                elseif ($item == 'HANDWORK') $auto = $stitchingCosts['handwork']['per_piece'] ?? 0;
                                elseif ($item == 'MATERIAL') $auto = $stitchingCosts['material']['per_piece'] ?? 0;
                                elseif ($item == 'OTHER EXP') $auto = $stitchingCosts['other']['per_piece'] ?? 0;

                                $manual = isset($manual_costs[$item]) ? floatval($manual_costs[$item]['manual_rate']) : 0;
                                $diff = $manual - $auto;
                            ?>
                            <tr data-item="<?= $item ?>" data-auto="<?= $auto ?>">
                                <td><?= $item ?></td>
                                <td class="text-right party-cell"><span class="party-display"><?= fmt($manual) ?></span><input type="number" step="1.0" class="manual-input party-input" value="<?= $manual ?>" style="display:none;"></td>
                                <td class="text-right auto-val"><?= fmt($auto) ?></td>
                                <td class="text-right diff-val <?= $diff>0?'diff-positive':($diff<0?'diff-negative':'') ?>"><?= ($diff>=0?'+':'').fmt($diff) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <?php 
                            $sumParty = 0; $sumAuto = 0;
                            foreach ($costingItems as $item) {
                                $sumParty += isset($manual_costs[$item]) ? floatval($manual_costs[$item]['manual_rate']) : 0;
                                if ($item == 'FABRIC') $sumAuto += $stitchingCosts['fabric_issue']['per_piece'] ?? 0;
                                elseif ($item == 'EMB') $sumAuto += $stitchingCosts['emb']['per_piece'] ?? 0;
                                elseif ($item == 'STITCHING') $sumAuto += $stitchingCosts['stitching_depart']['per_piece'] ?? 0;
                                elseif ($item == 'HANDWORK') $sumAuto += $stitchingCosts['handwork']['per_piece'] ?? 0;
                                elseif ($item == 'MATERIAL') $sumAuto += $stitchingCosts['material']['per_piece'] ?? 0;
                                elseif ($item == 'OTHER EXP') $sumAuto += $stitchingCosts['other']['per_piece'] ?? 0;
                            }
                            $sumDiff = $sumParty - $sumAuto;
                            ?>
                            <tr class="total-row">
                                <td><strong>TOTAL</strong></td>
                                <td id="totalPartyCell" class="text-right"><strong><?= fmt($sumParty) ?></strong></td>
                                <td id="totalAutoCell" class="text-right"><strong><?= fmt($sumAuto) ?></strong></td>
                                <td id="totalDiffCell" class="text-right <?= $sumDiff>0?'diff-positive':($sumDiff<0?'diff-negative':'') ?>"><strong><?= ($sumDiff>=0?'+':'').fmt($sumDiff) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="costing-col">
                <div style="overflow-x:auto">
                    <table class="excel-table">
                        <thead><tr><th>PROCESS</th><th class="text-right">RATE</th></tr></thead>
                        <tbody>
                            <?php 
                            $processes = ['MASTER', 'THEKDAR', 'TAILOR', 'CROPING', 'CHECKING', 'OVERLOCK', 'PRESSMAN', 'PACKING'];
                            $totalStitch = 0;
                            foreach ($processes as $proc): 
                                $rate = 0;
                                if ($proc == 'MASTER') $rate = $stitchingCosts['master']['per_piece'] ?? 0;
                                elseif ($proc == 'THEKDAR') $rate = $stitchingCosts['thekdar']['per_piece'] ?? 0;
                                elseif ($proc == 'TAILOR') $rate = $stitchingCosts['stitching_depart']['per_piece'] ?? 0;
                                elseif ($proc == 'CROPING') $rate = $stitchingCosts['croping']['per_piece'] ?? 0;
                                elseif ($proc == 'CHECKING') $rate = $stitchingCosts['checking']['per_piece'] ?? 0;
                                elseif ($proc == 'OVERLOCK') $rate = $stitchingCosts['overlook']['per_piece'] ?? 0;
                                elseif ($proc == 'PRESSMAN') $rate = $stitchingCosts['pressman']['per_piece'] ?? 0;
                                elseif ($proc == 'PACKING') $rate = $stitchingCosts['packing']['per_piece'] ?? 0;
                                $totalStitch += $rate;
                            ?>
                            <tr><td><?= $proc ?></td><td class="text-right"><?= fmt($rate) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr class="total-row"><td><strong>TOTAL</strong></td><td class="text-right"><strong><?= fmt($totalStitch) ?></strong></td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Claim Form -->
    <?php if ($job_no && $jobInfo && $remaining_qty > 0): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-plus-circle"></i> Add New Claim</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="job_no" value="<?= htmlspecialchars($jobInfo['job_no']) ?>">
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-barcode"></i> Serial No</label>
                        <input type="text" name="serial_no" class="form-control" value="CLM-<?= date('Ymd') ?>-001" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-calendar"></i> Claim Date</label>
                        <input type="date" name="claim_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-user"></i> Employee Name</label>
                        <input type="text" name="emp_name" class="form-control" list="employeeList" placeholder="Select employee" autocomplete="off" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-tag"></i> Claim Item</label>
                        <select name="claim_item" class="form-select" required>
                            <option value="">Select Claim Type</option>
                            <option value="Stitching Charges">Stitching Charges</option>
                            <option value="Cutting Charges">Cutting Charges</option>
                            <option value="Embroidery Charges">Embroidery Charges</option>
                            <option value="Printing Charges">Printing Charges</option>
                            <option value="Packing Charges">Packing Charges</option>
                            <option value="Pressman Charges">Pressman Charges</option>
                            <option value="Checking Charges">Checking Charges</option>
                            <option value="Handwork Charges">Handwork Charges</option>
                            <option value="Material Charges">Material Charges</option>
                            <option value="Fabric Charges">Fabric Charges</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-cubes"></i> Quantity (Pcs)</label>
                        <input type="number" step="1" min="0" name="qty" id="claim_qty" class="form-control" value="0" required oninput="calcAmount()">
                        <small class="text-muted">Max available: <?= $remaining_qty ?> pcs</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-tag"></i> Rate (per piece)</label>
                        <input type="number" step="0.25" min="0" name="rate" id="claim_rate" class="form-control" value="0" required oninput="calcAmount()">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> Total Amount</label>
                        <input type="text" id="claim_amount" class="form-control bg-light" readonly value="0.00">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-file-alt"></i> Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Enter any additional details..."></textarea>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" name="save_claim" class="btn btn-success" id="saveClaimBtn"><i class="fas fa-save"></i> Save Claim</button>
                    <button type="reset" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Claims -->
    <?php if (!empty($recent_claims)): ?>
    <div class="recent-claims">
        <h5><i class="fas fa-history"></i> Recent Claims</h5>
        <div class="table-responsive">
            <table class="recent-table">
                <thead><tr><th>Date</th><th>Employee</th><th>Item</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_claims as $claim): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($claim['created_at'])) ?></td>
                        <td><?= htmlspecialchars($claim['name']) ?></td>
                        <td><?= htmlspecialchars($claim['part_name'] ?? $claim['name']) ?></td>
                        <td><?= $claim['qty'] ?></td>
                        <td>Rs <?= number_format($claim['rate'], 2) ?></td>
                        <td>Rs <?= number_format($claim['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function calcAmount() {
    let qty = parseFloat(document.getElementById('claim_qty').value) || 0;
    let rate = parseFloat(document.getElementById('claim_rate').value) || 0;
    let amount = qty * rate;
    document.getElementById('claim_amount').value = amount.toFixed(2);
    let remaining = <?= $remaining_qty ?>;
    if (qty > remaining && remaining > 0) {
        document.getElementById('claim_qty').classList.add('is-invalid');
        document.getElementById('saveClaimBtn').disabled = true;
    } else {
        document.getElementById('claim_qty').classList.remove('is-invalid');
        document.getElementById('saveClaimBtn').disabled = false;
    }
}

function loadCostingJob() {
    let jobNo = document.getElementById('costingJobSearch').value.trim();
    let serialNo = document.getElementById('costingSerialSearch').value.trim();
    if (jobNo !== "") window.location.href = 'add.php?job_no=' + encodeURIComponent(jobNo);
    else if (serialNo !== "") window.location.href = 'add.php?serial=' + encodeURIComponent(serialNo);
    else alert('Enter either Job # or Serial #');
}

let originalManualValues = {};
function enableCostEditing() {
    document.querySelectorAll('#costingTableMain .party-display').forEach(span => span.style.display = 'none');
    document.querySelectorAll('#costingTableMain .party-input').forEach(inp => {
        inp.style.display = 'inline-block';
        let row = inp.closest('tr');
        if (row) originalManualValues[row.dataset.item] = inp.value;
    });
    document.getElementById('editRatesBtn').style.display = 'none';
    document.getElementById('saveRatesBtn').style.display = 'inline-block';
    document.getElementById('cancelEditBtn').style.display = 'inline-block';
}

function cancelCostEditing() { location.reload(); }

function saveAllRates() {
    const jobNo = '<?= addslashes($job_no) ?>';
    if (!jobNo){ alert('No job loaded'); return; }
    const rows = document.querySelectorAll('#costingTableMain tbody tr');
    let costData = [], newPartySum = 0;
    rows.forEach(row => {
        const item = row.dataset.item;
        const autoRate = parseFloat(row.dataset.auto) || 0;
        let manualInput = row.querySelector('.party-input');
        let manualRate = parseFloat(manualInput.value) || 0;
        newPartySum += manualRate;
        costData.push({type: item, manual_rate: manualRate, auto_rate: autoRate});
        let displaySpan = row.querySelector('.party-display');
        displaySpan.innerText = manualRate.toFixed(2);
        let diff = manualRate - autoRate;
        let diffCell = row.querySelector('.diff-val');
        diffCell.innerText = (diff >= 0 ? '+' : '') + diff.toFixed(2);
        diffCell.className = 'text-right diff-val ' + (diff > 0 ? 'diff-positive' : (diff < 0 ? 'diff-negative' : ''));
    });
    let totalAuto = 0;
    document.querySelectorAll('#costingTableMain .auto-val').forEach(el => totalAuto += parseFloat(el.innerText.replace(/,/g, '')));
    document.getElementById('totalPartyCell').innerHTML = '<strong>' + newPartySum.toFixed(2) + '</strong>';
    document.getElementById('totalAutoCell').innerHTML = '<strong>' + totalAuto.toFixed(2) + '</strong>';
    let totalDiff = newPartySum - totalAuto;
    let totalDiffCell = document.getElementById('totalDiffCell');
    totalDiffCell.innerHTML = '<strong>' + (totalDiff >= 0 ? '+' : '') + totalDiff.toFixed(2) + '</strong>';
    totalDiffCell.className = 'text-right ' + (totalDiff > 0 ? 'diff-positive' : (totalDiff < 0 ? 'diff-negative' : ''));

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'save_manual_costs=1&job_no=' + encodeURIComponent(jobNo) + '&cost_data=' + encodeURIComponent(JSON.stringify(costData))
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === 'success') {
            alert('Party rates saved!');
            location.reload();
        } else alert('Error: ' + data);
    })
    .catch(err => alert('Request failed'));
}

document.addEventListener('DOMContentLoaded', function() { calcAmount(); });
</script>
</body>
</html>