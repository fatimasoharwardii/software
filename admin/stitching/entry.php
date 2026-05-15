<?php
$page_identifier = 'stitching/entry.php';
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

// Create required tables if they don't exist (add user_id column to manual_costing)
$conn->query("CREATE TABLE IF NOT EXISTS manual_costing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_no VARCHAR(50),
    cost_type VARCHAR(100),
    manual_rate DECIMAL(12,2) DEFAULT 0,
    auto_rate DECIMAL(12,2) DEFAULT 0,
    difference DECIMAL(12,2) DEFAULT 0,
    is_edited TINYINT DEFAULT 0,
    user_id INT DEFAULT NULL,
    company_id INT NOT NULL
)");

// Ensure required tables have company_id column
$tables = ['jobs', 'stitching_bill_items', 'fabric_issue', 'fabric_purchase', 'parties', 'accounts', 'stitching_posted_bills', 'ledger_transactions', 'manual_costing'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
    // Also ensure user_id column exists for tables that need it
    if ($table == 'manual_costing') {
        $user_check = $conn->query("SHOW COLUMNS FROM `manual_costing` LIKE 'user_id'");
        if ($user_check->num_rows == 0) {
            $conn->query("ALTER TABLE `manual_costing` ADD COLUMN `user_id` INT DEFAULT NULL");
        }
    }
}

$left_job_no = isset($_GET['left_job_no']) ? trim($_GET['left_job_no']) : '';
$serial_search = isset($_GET['serial']) ? trim($_GET['serial']) : '';

if(!empty($serial_search) && empty($left_job_no)){
    $safe_serial = $conn->real_escape_string($serial_search);
    $res = $conn->query("SELECT job_no FROM jobs WHERE serial_no = '$safe_serial' AND company_id = $company_id LIMIT 1");
    if($row = $res->fetch_assoc()){
        $left_job_no = $row['job_no'];
    }
}

$leftJobInfo = null;
$job_not_found = false;
if($left_job_no){
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT * FROM jobs WHERE job_no = '$safe_job' AND company_id = $company_id LIMIT 1");
    $leftJobInfo = $res->fetch_assoc();
    if (!$leftJobInfo) {
        $job_not_found = true;
    }
}

// Delete entry (other tabs)
if(isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $job_no = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';
    $vendor_name = isset($_GET['vendor_name']) ? trim($_GET['vendor_name']) : '';
    $tab_type = isset($_GET['tab_type']) ? trim($_GET['tab_type']) : '';
    $conn->query("DELETE FROM stitching_bill_items WHERE id = $delete_id AND company_id = $company_id");
    if($conn->affected_rows > 0) {
        if($job_no && $vendor_name) {
            $claim_type = ($tab_type == 'material') ? 'Material Bill' : 'Stitching Bill';
            $safe_job = $conn->real_escape_string($job_no);
            $safe_name = $conn->real_escape_string($vendor_name);
            $safe_tab = $conn->real_escape_string($tab_type);
            $res = $conn->query("SELECT SUM(amount) as total, SUM(qty) as total_qty FROM stitching_bill_items WHERE job_no = '$safe_job' AND name = '$safe_name' AND tab_type = '$safe_tab' AND company_id = $company_id");
            $data = $res->fetch_assoc();
            if($data['total'] > 0) {
                $total_amount = $data['total'];
                $total_qty = $data['total_qty'];
                $avg_rate = ($total_qty > 0) ? ($total_amount / $total_qty) : 0;
                $conn->query("UPDATE stitching_posted_bills SET total_amount = $total_amount, qty = $total_qty, rate = $avg_rate WHERE job_no = '$safe_job' AND emp_name = '$safe_name' AND claim_type = '$claim_type' AND company_id = $company_id");
            } else {
                $conn->query("DELETE FROM stitching_posted_bills WHERE job_no = '$safe_job' AND emp_name = '$safe_name' AND claim_type = '$claim_type' AND company_id = $company_id");
            }
        }
        header("Location: entry.php?left_job_no=" . urlencode($job_no));
        exit;
    }
}

// Delete embroidery billing entry
if(isset($_GET['delete_emb_id'])) {
    $delete_id = intval($_GET['delete_emb_id']);
    $res = $conn->query("SELECT * FROM stitching_bill_items WHERE id = $delete_id AND tab_type = 'embroidery_billing' AND company_id = $company_id");
    $entry = $res->fetch_assoc();
    if($entry) {
        $job_no = $entry['job_no'];
        $vendor_name = $entry['name'];
        $conn->query("DELETE FROM stitching_bill_items WHERE id = $delete_id AND company_id = $company_id");
        $safe_job = $conn->real_escape_string($job_no);
        $safe_name = $conn->real_escape_string($vendor_name);
        $res2 = $conn->query("SELECT SUM(amount) as total, SUM(qty) as total_qty FROM stitching_bill_items WHERE job_no = '$safe_job' AND name = '$safe_name' AND tab_type = 'embroidery_billing' AND company_id = $company_id");
        $total_r = $res2->fetch_assoc();
        if($total_r['total'] > 0) {
            $new_rate = ($total_r['total_qty'] > 0) ? ($total_r['total'] / $total_r['total_qty']) : 0;
            $conn->query("UPDATE stitching_posted_bills SET total_amount = {$total_r['total']}, qty = {$total_r['total_qty']}, rate = $new_rate WHERE job_no = '$safe_job' AND emp_name = '$safe_name' AND claim_type = 'Embroidery Bill' AND company_id = $company_id");
        } else {
            $conn->query("DELETE FROM stitching_posted_bills WHERE job_no = '$safe_job' AND emp_name = '$safe_name' AND claim_type = 'Embroidery Bill' AND company_id = $company_id");
        }
    }
    header("Location: entry.php?left_job_no=" . urlencode($job_no) . "&tab=embroidery_billing");
    exit;
}

// Save embroidery billing (one-time) – original unchanged block
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_embroidery_bill'])) {
    $job_no = trim($_POST['job_no']);
    $billing_date = $_POST['billing_date'];
    $bill_no = trim($_POST['bill_no'] ?? '');
    $part_names = $_POST['part_name'] ?? [];
    $stitches = $_POST['stitch_round'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $rounds = $_POST['round'] ?? [];
    $heads = $_POST['head'] ?? [];
    $adjustment = floatval($_POST['adjustment'] ?? 0);
    
    $safe_job = $conn->real_escape_string($job_no);
    $res = $conn->query("SELECT * FROM jobs WHERE job_no = '$safe_job' AND company_id = $company_id");
    $job = $res->fetch_assoc();
    if (!$job) {
        echo "<script>alert('Job not found!'); window.location.href='entry.php?left_job_no=$job_no&tab=embroidery_billing';</script>";
        exit;
    }
    
    $res2 = $conn->query("SELECT id FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type = 'embroidery_billing' AND company_id = $company_id LIMIT 1");
    if ($res2->num_rows > 0) {
        echo "<script>alert('This job has already been billed!'); window.location.href='entry.php?left_job_no=$job_no&tab=embroidery_billing';</script>";
        exit;
    }
    
    $conn->begin_transaction();
    try {
        $tab_type = 'embroidery_billing';
        $name = $job['embroidery_vendor_name'] ?? '';
        $escaped_name = $conn->real_escape_string($name);
        $inserted_count = 0;
        $sub_totals = [];
        
        foreach($part_names as $index => $part) {
            if (empty($part)) continue;
            $part_clean = $conn->real_escape_string($part);
            $stitch = floatval($stitches[$index] ?? 0);
            $rate = floatval($rates[$index] ?? 1.3);
            $round = floatval($rounds[$index] ?? 1);
            $head = floatval($heads[$index] ?? 1);
            $sub_total = ($stitch / 1000) * $rate * $head * $round;
            $sub_totals[] = $sub_total;
            $created_date = $billing_date . ' 00:00:00';
            $safe_bill = $conn->real_escape_string($bill_no);
            
            $sql = "INSERT INTO stitching_bill_items 
                (job_no, tab_type, name, part_name, stitch, round_qty, qty, rate, amount, sub_total, head, created_at, bill_no, company_id, user_id) 
                VALUES ('$safe_job', '$tab_type', '$escaped_name', '$part_clean', $stitch, $round, 1, $rate, $sub_total, $sub_total, $head, '$created_date', '$safe_bill', $company_id, $user_id)";
            if (!$conn->query($sql)) throw new Exception("Insert error: " . $conn->error);
            $inserted_count++;
        }
        if ($inserted_count == 0) throw new Exception("No items to insert!");
        
        $total_amount = array_sum($sub_totals) + $adjustment;
        $total_amount = round($total_amount);
        
        $serial_no = $conn->real_escape_string($job['serial_no'] ?? '');
        $emp_name = $conn->real_escape_string($job['embroidery_vendor_name'] ?? '');
        $claim_item = 'Embroidery Charges';
        $description = "Embroidery Bill - Parts: $inserted_count";
        if($bill_no) $description .= " | Bill No: $bill_no";
        $desc_esc = $conn->real_escape_string($description);
        $claim_type = 'Embroidery Bill';
        $design_name = $conn->real_escape_string($job['design_name'] ?? '');
        $fabric_name = $conn->real_escape_string($job['fabric_name'] ?? '');
        $size = $conn->real_escape_string($job['size'] ?? '');
        $brand_name = $conn->real_escape_string($job['brand_name'] ?? '');
        $claim_date = date('Y-m-d');
        $safe_bill_ins = $conn->real_escape_string($bill_no);
        
        $sql_post = "INSERT INTO stitching_posted_bills 
            (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, claim_type, claim_date, 
             design_name, fabric_name, size, brand_name, status, manual_total, auto_total, difference_total, post_date, bill_no, company_id, user_id) 
            VALUES ('$safe_job', '$serial_no', '$emp_name', '$claim_item', 1, $total_amount, $total_amount,
            '$desc_esc', '$claim_type', '$claim_date', '$design_name', '$fabric_name', '$size', '$brand_name',
            'un_posted', $total_amount, 0, $total_amount, NULL, '$safe_bill_ins', $company_id, $user_id)";
        if (!$conn->query($sql_post)) throw new Exception("Post insert error: " . $conn->error);
        
        $conn->commit();
        echo "<script>
                alert('Embroidery bill saved successfully!\\nTotal Amount: Rs. " . number_format($total_amount,0) . "\\nBill No: " . ($bill_no ?: 'N/A') . "');
                window.location.href = 'entry.php?left_job_no=$job_no&tab=embroidery_billing';
              </script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href='entry.php?left_job_no=$job_no&tab=embroidery_billing';</script>";
        exit;
    }
}

$existing_quantities = [];
if($left_job_no && !$job_not_found) {
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT tab_type, SUM(qty) as total_qty 
        FROM stitching_bill_items 
        WHERE job_no = '$safe_job' AND tab_type NOT IN ('stitching_depart', 'embroidery_billing', 'material') AND company_id = $company_id
        GROUP BY tab_type");
    while($row = $res->fetch_assoc()) {
        $existing_quantities[$row['tab_type']] = intval($row['total_qty']);
    }
}

$left_entries = [];
if($left_job_no && !$job_not_found) {
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT 
        id, created_at, job_no, tab_type, name, qty, rate, amount, 
        part_name, lot_no, department, color, kurti_qty, shalwar_qty, dupatta_qty, 
        stitch, round_qty, head, bill_no
        FROM stitching_bill_items 
        WHERE job_no = '$safe_job' AND company_id = $company_id
        ORDER BY created_at DESC");
    while($row = $res->fetch_assoc()){
        $left_entries[] = $row;
    }
    
    $res2 = $conn->query("SELECT 
        id, created_at, job_no, 'fabric_issue' as tab_type, 
        fabric_name as name, (emb_issue + back_issue + extra_issue) as qty,
        adjust_rate as rate, amount,
        NULL as part_name, lot_no, NULL as department, NULL as color,
        NULL as kurti_qty, NULL as shalwar_qty, NULL as dupatta_qty,
        NULL as stitch, NULL as round_qty, NULL as head, NULL as bill_no
        FROM fabric_issue 
        WHERE job_no = '$safe_job' AND company_id = $company_id
        ORDER BY created_at DESC");
    while($fab = $res2->fetch_assoc()){
        $left_entries[] = $fab;
    }
    
    usort($left_entries, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

$safe_job = $conn->real_escape_string($left_job_no);
$res = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no = '$safe_job' AND company_id = $company_id");
$left_grand_total = $res->fetch_assoc()['total'] ?? 0;

$allTypes = [
    'master' => 'Master',
    'thekdar' => 'Thekdar',
    'overlook' => 'Overlook',
    'handwork' => 'Handwork',   
    'croping' => 'Croping',
    'checking' => 'Checking',
    'pressman' => 'Pressman',
    'packing' => 'Packing',
    'other' => 'Other'
];

$res = $conn->query("SELECT party_name FROM parties WHERE company_id = $company_id ORDER BY party_name");
$vendors_list = [];
while($vendor = $res->fetch_assoc()) {
    $vendors_list[] = $vendor['party_name'];
}

$res = $conn->query("SELECT job_no FROM jobs WHERE company_id = $company_id ORDER BY job_no");
$jobs_suggest_list = [];
while($job_suggest = $res->fetch_assoc()) {
    $jobs_suggest_list[] = $job_suggest['job_no'];
}

$autoRates = [];
$stitchingDetails = [];
$costingItems = ['FABRIC', 'EMB', 'STITCHING', 'HANDWORK', 'OTHER EXP'];
$processes = ['MASTER', 'THEKDAR', 'TAILOR', 'CROPING', 'CHECKING', 'OVERLOCK', 'PRESSMAN', 'PACKING'];

if($left_job_no && !$job_not_found && $leftJobInfo){
    $job_qty = $leftJobInfo['quantity'] ?? 1;
    if($job_qty <= 0) $job_qty = 1;
    $safe_job = $conn->real_escape_string($left_job_no);
    
    $res = $conn->query("SELECT SUM(amount) as total FROM fabric_issue WHERE job_no = '$safe_job' AND company_id = $company_id");
    $autoRates['FABRIC'] = ($res->fetch_assoc()['total'] ?? 0) / $job_qty;
    
    $res = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type = 'embroidery_billing' AND company_id = $company_id");
    $autoRates['EMB'] = ($res->fetch_assoc()['total'] ?? 0) / $job_qty;

    $res = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type = 'handwork' AND company_id = $company_id");
    $autoRates['HANDWORK'] = ($res->fetch_assoc()['total'] ?? 0) / $job_qty;

    $res = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type = 'other' AND company_id = $company_id");
    $autoRates['OTHER EXP'] = ($res->fetch_assoc()['total'] ?? 0) / $job_qty;

    $res = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type = 'stitching_depart' AND company_id = $company_id");
    $tailor_rate = ($res->fetch_assoc()['total'] ?? 0) / $job_qty;
    
    $processTotal = 0;
    foreach($processes as $proc){
        if($proc == 'TAILOR'){
            $rate_per_piece = $tailor_rate;
        } else {
            $tab_key = strtolower(str_replace(' ', '_', $proc));
            if($proc == 'OVERLOCK') $tab_key = 'overlook';
            $res = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type = '$tab_key' AND company_id = $company_id");
            $rate_per_piece = ($res->fetch_assoc()['total'] ?? 0) / $job_qty;
        }
        $stitchingDetails[$proc] = $rate_per_piece;
        $processTotal += $rate_per_piece;
    }
    $autoRates['STITCHING'] = $processTotal;
}

$manualRates = [];
if($left_job_no && !$job_not_found){
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT * FROM manual_costing WHERE job_no = '$safe_job' AND company_id = $company_id");
    while($cost = $res->fetch_assoc()){
        $manualRates[$cost['cost_type']] = floatval($cost['manual_rate']);
    }
}

if(isset($_POST['save_manual_costs'])){
    $job_no_save = $conn->real_escape_string(trim($_POST['job_no']));
    $cost_data = json_decode($_POST['cost_data'], true);
    foreach($cost_data as $cost){
        $type = $conn->real_escape_string($cost['type']);
        $manual_rate = floatval($cost['manual_rate']);
        $auto_rate = floatval($cost['auto_rate']);
        $diff = $auto_rate - $manual_rate;
        $check = $conn->query("SELECT id FROM manual_costing WHERE job_no = '$job_no_save' AND cost_type = '$type' AND company_id = $company_id");
        if($check->num_rows > 0){
            $conn->query("UPDATE manual_costing SET manual_rate = $manual_rate, auto_rate = $auto_rate, difference = $diff, is_edited = 1, user_id = $user_id WHERE job_no = '$job_no_save' AND cost_type = '$type' AND company_id = $company_id");
        } else {
            $conn->query("INSERT INTO manual_costing (job_no, cost_type, manual_rate, auto_rate, difference, is_edited, company_id, user_id) VALUES ('$job_no_save', '$type', $manual_rate, $auto_rate, $diff, 1, $company_id, $user_id)");
        }
    }
    echo "success";
    exit;
}

function fmt($num){ return number_format($num, 2); }

// ==============================================
// *** FINAL IMPROVED FUNCTION ***
// ==============================================
function updateStitchingPostedBillForVendor($conn, $job_no, $vendor_name, $tab_type, $bill_no, $company_id) {
    $user_id = (int)$_SESSION['user_id'];

    $safe_job   = $conn->real_escape_string($job_no);
    $safe_name  = $conn->real_escape_string($vendor_name);
    $safe_tab   = $conn->real_escape_string($tab_type);

    // Calculate total amount and qty for this vendor + tab_type
    $res = $conn->query("SELECT SUM(amount) AS total, SUM(qty) AS total_qty
                         FROM stitching_bill_items
                         WHERE job_no = '$safe_job'
                           AND name = '$safe_name'
                           AND tab_type = '$safe_tab'
                           AND company_id = $company_id");
    if (!$res) {
        throw new Exception("SQL Error in total calculation: " . $conn->error);
    }
    $total_r = $res->fetch_assoc();
    $total_amount = $total_r['total'] ?? 0;
    $total_qty    = $total_r['total_qty'] ?? 0;

    // If no items, delete the posted bill (if any)
    if ($total_amount <= 0) {
        $claim_type = ($tab_type == 'material') ? 'Material Bill' : 'Stitching Bill';
        $del = $conn->query("DELETE FROM stitching_posted_bills
                             WHERE job_no = '$safe_job'
                               AND emp_name = '$safe_name'
                               AND claim_type = '$claim_type'
                               AND company_id = $company_id");
        if (!$del) {
            throw new Exception("Delete posted bill failed: " . $conn->error);
        }
        return true;
    }

    $avg_rate = ($total_qty > 0) ? ($total_amount / $total_qty) : 0;

    // Fetch job details for extra columns
    $res_job = $conn->query("SELECT * FROM jobs WHERE job_no = '$safe_job' AND company_id = $company_id LIMIT 1");
    if (!$res_job || $res_job->num_rows == 0) {
        throw new Exception("Job details not found for job_no = $safe_job");
    }
    $job_details = $res_job->fetch_assoc();

    $serial_no  = $conn->real_escape_string($job_details['serial_no'] ?? 'SERIAL-'.date('Ymd'));
    $claim_type = ($tab_type == 'material') ? 'Material Bill' : 'Stitching Bill';
    $claim_item = ($tab_type == 'material') ? 'Material Charges' : 'Stitching Charges';
    $design     = $conn->real_escape_string($job_details['design_name'] ?? '');
    $fabric     = $conn->real_escape_string($job_details['fabric_name'] ?? '');
    $size       = $conn->real_escape_string($job_details['size'] ?? '');
    $brand      = $conn->real_escape_string($job_details['brand_name'] ?? '');
    $safe_bill  = $conn->real_escape_string($bill_no ?? '');
    $desc       = ucfirst($tab_type) . " bill for job $safe_job - " . ($tab_type=='material' ? 'Item' : 'Vendor') . ": $safe_name";
    $desc_esc   = $conn->real_escape_string($desc);

    // Check if posted bill already exists
    $check = $conn->query("SELECT id, bill_no FROM stitching_posted_bills
                           WHERE job_no = '$safe_job'
                             AND emp_name = '$safe_name'
                             AND claim_type = '$claim_type'
                             AND company_id = $company_id");
    if (!$check) {
        throw new Exception("Check query failed: " . $conn->error);
    }

    if ($check->num_rows > 0) {
        $existing = $check->fetch_assoc();
        // Preserve existing bill_no if no new bill_no is supplied
        $bill_to_use = !empty($bill_no) ? $safe_bill : $existing['bill_no'];
        $sql = "UPDATE stitching_posted_bills SET
                    total_amount = $total_amount,
                    qty = $total_qty,
                    rate = $avg_rate,
                    post_date = CURDATE(),
                    status = 'pending',
                    user_id = $user_id,
                    description = '$desc_esc',
                    bill_no = '$bill_to_use'
                WHERE id = {$existing['id']} AND company_id = $company_id";
    } else {
        // Insert new posted bill
        $bill_to_use = !empty($bill_no) ? $safe_bill : '';
        $sql = "INSERT INTO stitching_posted_bills
                    (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description,
                     claim_type, claim_date, design_name, fabric_name, size, brand_name, status,
                     manual_total, auto_total, difference_total, post_date, bill_no, company_id, user_id)
                VALUES
                    ('$safe_job', '$serial_no', '$safe_name', '$claim_item', $total_qty, $avg_rate, $total_amount,
                     '$desc_esc', '$claim_type', CURDATE(), '$design', '$fabric', '$size', '$brand', 'pending',
                     $total_amount, 0, $total_amount, CURDATE(), '$bill_to_use', $company_id, $user_id)";
    }

    if (!$conn->query($sql)) {
        throw new Exception("Posted bill update/insert failed: " . $conn->error . " Query: " . $sql);
    }

    return true;
}

// Save handler for other tabs (including material tab) – now with try/catch
if(isset($_POST['save_multiple'])) {
    $job_no   = trim($_POST['job_no']);
    $tab_type = trim($_POST['tab_type']);
    $rows     = $_POST['rows'] ?? [];
    $bill_no  = isset($_POST['bill_no']) ? trim($_POST['bill_no']) : null;
    $has_data = false;
    $processed_vendors = [];
    $safe_job = $conn->real_escape_string($job_no);
    $safe_tab = $conn->real_escape_string($tab_type);
    
    $conn->begin_transaction();
    try {
        foreach($rows as $rowId => $row) {
            $name = trim($row['name'] ?? '');
            $qty  = floatval($row['qty'] ?? 0);
            $rate = floatval($row['rate'] ?? 0);
            
            if(empty($name) || $qty <= 0) continue;
            $has_data = true;
            $safe_name = $conn->real_escape_string($name);
            
            if($tab_type != 'material') {
                $check_party = $conn->query("SELECT id FROM parties WHERE party_name = '$safe_name' AND company_id = $company_id");
                if($check_party->num_rows == 0) {
                    $conn->query("INSERT INTO parties (party_name, party_type, company_id, user_id) VALUES ('$safe_name', '$safe_tab', $company_id, $user_id)");
                    if($conn->error) {
                        throw new Exception("Error creating party: " . $conn->error);
                    }
                }
            }
            
            $amount = $qty * $rate;
            $safe_bill_ins = $conn->real_escape_string($bill_no ?? '');
            $insert_sql = "INSERT INTO stitching_bill_items (job_no, tab_type, name, qty, rate, amount, bill_no, created_at, company_id, user_id)
                           VALUES ('$safe_job', '$safe_tab', '$safe_name', $qty, $rate, $amount, '$safe_bill_ins', NOW(), $company_id, $user_id)";
            if(!$conn->query($insert_sql)) {
                throw new Exception("Error saving entry");
            }
            
            if(!in_array($name, $processed_vendors)) $processed_vendors[] = $name;
        }
        
        if(!$has_data) {
            throw new Exception("Please fill at least one row with item name and quantity.");
        }
        
        // Update/insert posted bills for each affected vendor
        foreach($processed_vendors as $vendor) {
            updateStitchingPostedBillForVendor($conn, $job_no, $vendor, $tab_type, $bill_no, $company_id);
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Entries saved successfully!', 'job_no' => $job_no]);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Stitching department – unchanged
$stitching_error = "";
$stitching_success = "";
$saved_kurti = 0;
$saved_shalwar = 0;
$saved_dupatta = 0;
$stitching_recent_entries = [];
if($left_job_no && !$job_not_found){
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT 
        SUM(kurti_qty) as total_kurti,
        SUM(shalwar_qty) as total_shalwar,
        SUM(dupatta_qty) as total_dupatta
        FROM stitching_bill_items 
        WHERE job_no = '$safe_job' AND tab_type = 'stitching_depart' AND company_id = $company_id");
    $row = $res->fetch_assoc();
    $saved_kurti = intval($row['total_kurti'] ?? 0);
    $saved_shalwar = intval($row['total_shalwar'] ?? 0);
    $saved_dupatta = intval($row['total_dupatta'] ?? 0);
    
    $res2 = $conn->query("SELECT * FROM stitching_bill_items 
        WHERE job_no = '$safe_job' AND tab_type = 'stitching_depart' AND company_id = $company_id
        ORDER BY created_at DESC LIMIT 10");
    while($entry = $res2->fetch_assoc()) $stitching_recent_entries[] = $entry;
}

if(isset($_POST['save_stitching']))
{
    $job_no = $_POST['job_no'];
    $job_qty = $leftJobInfo['quantity'] ?? 0;
    $safe_job = $conn->real_escape_string($job_no);
    $res = $conn->query("SELECT SUM(kurti_qty) as total_kurti, SUM(shalwar_qty) as total_shalwar, SUM(dupatta_qty) as total_dupatta FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type='stitching_depart' AND company_id = $company_id");
    $check_row = $res->fetch_assoc();
    $current_saved_kurti = intval($check_row['total_kurti'] ?? 0);
    $current_saved_shalwar = intval($check_row['total_shalwar'] ?? 0);
    $current_saved_dupatta = intval($check_row['total_dupatta'] ?? 0);
    
    $names = $_POST['name'] ?? [];
    $departments = $_POST['department'] ?? [];
    $colors = $_POST['color'] ?? [];
    $kurti_qtys = $_POST['kurti_qty'] ?? [];
    $kurti_rates = $_POST['kurti_rate'] ?? [];
    $shalwar_qtys = $_POST['shalwar_qty'] ?? [];
    $shalwar_rates = $_POST['shalwar_rate'] ?? [];
    $dupatta_qtys = $_POST['dupatta_qty'] ?? [];
    $dupatta_rates = $_POST['dupatta_rate'] ?? [];
    
    $new_kurti = $new_shalwar = $new_dupatta = 0;
    for($i = 0; $i < count($names); $i++) {
        if(empty(trim($names[$i] ?? ''))) continue;
        $new_kurti += floatval($kurti_qtys[$i] ?? 0);
        $new_shalwar += floatval($shalwar_qtys[$i] ?? 0);
        $new_dupatta += floatval($dupatta_qtys[$i] ?? 0);
    }
    $error_msg = "";
    if($new_kurti > 0 && ($current_saved_kurti + $new_kurti) > $job_qty) $error_msg = "Kurti quantity exceeds limit!";
    elseif($new_shalwar > 0 && ($current_saved_shalwar + $new_shalwar) > $job_qty) $error_msg = "Shalwar quantity exceeds limit!";
    elseif($new_dupatta > 0 && ($current_saved_dupatta + $new_dupatta) > $job_qty) $error_msg = "Dupatta quantity exceeds limit!";
    
    if(empty($error_msg)) {
        $conn->begin_transaction();
        $all_success = true;
        $total_amount_all = 0;
        for($i = 0; $i < count($names); $i++) {
            $name = trim($names[$i] ?? '');
            if(empty($name)) continue;
            $department = trim($departments[$i] ?? '');
            $color = trim($colors[$i] ?? '');
            $kurti_qty = floatval($kurti_qtys[$i] ?? 0);
            $kurti_rate = floatval($kurti_rates[$i] ?? 0);
            $shalwar_qty = floatval($shalwar_qtys[$i] ?? 0);
            $shalwar_rate = floatval($shalwar_rates[$i] ?? 0);
            $dupatta_qty = floatval($dupatta_qtys[$i] ?? 0);
            $dupatta_rate = floatval($dupatta_rates[$i] ?? 0);
            $item_pieces = intval($kurti_qty) + intval($shalwar_qty) + intval($dupatta_qty);
            $amount = ($kurti_qty * $kurti_rate) + ($shalwar_qty * $shalwar_rate) + ($dupatta_qty * $dupatta_rate);
            $total_amount_all += $amount;
            $safe_name = $conn->real_escape_string($name);
            $safe_dept = $conn->real_escape_string($department);
            $safe_color = $conn->real_escape_string($color);
            
            $check = $conn->query("SELECT id FROM parties WHERE party_name = '$safe_name' AND company_id = $company_id");
            if($check->num_rows === 0) {
                $conn->query("INSERT INTO parties (party_name, party_type, company_id, user_id) VALUES ('$safe_name', 'stitching_depart', $company_id, $user_id)");
                if($conn->error) { $all_success = false; $error_msg = "Error creating party"; break; }
            }
            
            $sql = "INSERT INTO stitching_bill_items 
                (job_no, tab_type, name, department, color, qty, amount, kurti_qty, kurti_rate, shalwar_qty, shalwar_rate, dupatta_qty, dupatta_rate, created_at, company_id, user_id) 
                VALUES ('$safe_job', 'stitching_depart', '$safe_name', '$safe_dept', '$safe_color', $item_pieces, $amount, 
                $kurti_qty, $kurti_rate, $shalwar_qty, $shalwar_rate, $dupatta_qty, $dupatta_rate, NOW(), $company_id, $user_id)";
            if(!$conn->query($sql)) { $all_success = false; $error_msg = "DB error"; break; }
            
            $conn->query("UPDATE accounts SET balance = balance + $amount WHERE account_name = '$safe_name' AND company_id = $company_id");
            $conn->query("INSERT INTO ledger_transactions (date, from_account, to_account, amount, description, transaction_type, company_id, user_id) VALUES (CURDATE(), '$safe_name', 'Pending Payment', $amount, 'Stitching bill for job $job_no', 'bill', $company_id, $user_id)");
            
            $check_existing = $conn->query("SELECT id, total_amount, qty FROM stitching_posted_bills WHERE job_no = '$safe_job' AND claim_type='Stitching Bill' AND emp_name = '$safe_name' AND company_id = $company_id");
            if($existing = $check_existing->fetch_assoc()){
                $new_total_qty = $existing['qty'] + $item_pieces;
                $new_total_amount = $existing['total_amount'] + $amount;
                $new_rate = ($new_total_qty > 0) ? ($new_total_amount / $new_total_qty) : 0;
                $conn->query("UPDATE stitching_posted_bills SET qty = $new_total_qty, total_amount = $new_total_amount, rate = $new_rate, post_date = CURDATE(), status = 'pending' WHERE id = {$existing['id']} AND company_id = $company_id");
            } else {
                $job_details = $conn->query("SELECT * FROM jobs WHERE job_no = '$safe_job' AND company_id = $company_id")->fetch_assoc();
                $serial_no = $conn->real_escape_string($job_details['serial_no'] ?? 'SERIAL-'.date('Ymd'));
                $new_rate = ($item_pieces > 0) ? ($amount / $item_pieces) : 0;
                $design = $conn->real_escape_string($job_details['design_name'] ?? '');
                $fabric = $conn->real_escape_string($job_details['fabric_name'] ?? '');
                $size = $conn->real_escape_string($job_details['size'] ?? '');
                $brand = $conn->real_escape_string($job_details['brand_name'] ?? '');
                $conn->query("INSERT INTO stitching_posted_bills 
                    (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, claim_type, claim_date, design_name, fabric_name, size, brand_name, status, manual_total, auto_total, difference_total, post_date, company_id, user_id) 
                    VALUES ('$safe_job', '$serial_no', '$safe_name', 'Stitching Charges', $item_pieces, $new_rate, $amount, 'Stitching bill for job $job_no - $name', 'Stitching Bill', CURDATE(), '$design', '$fabric', '$size', '$brand', 'pending', $amount, 0, $amount, CURDATE(), $company_id, $user_id)");
            }
        }
        if($all_success){
            $conn->commit();
            $stitching_success = "Saved! Total: Rs ".number_format($total_amount_all,2);
            header("Location: entry.php?left_job_no=".urlencode($job_no)."&tab=stitching_depart");
            exit;
        } else { $conn->rollback(); $stitching_error = $error_msg; }
    } else { $stitching_error = $error_msg; }
}

// Fabric issue handler – unchanged
$fabric_error = "";
$fabric_success = "";
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS used_meter DECIMAL(12,2) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS remaining_meter DECIMAL(12,2) DEFAULT NULL");
$conn->query("UPDATE fabric_purchase SET remaining_meter = total_meter - COALESCE(used_meter,0) WHERE remaining_meter IS NULL OR remaining_meter != total_meter - COALESCE(used_meter,0)");

if (isset($_POST['save_fabric_issue'])) {
    $job_no = trim($_POST['main_job_no'] ?? '');
    $rows = $_POST['rows'] ?? [];
    $has_data = false;
    $all_success = true;
    $error_msg = "";
    if (empty($job_no)) { $error_msg = "No job selected."; $all_success = false; }
    $safe_job = $conn->real_escape_string($job_no);
    $conn->begin_transaction();
    foreach ($rows as $rowId => $row) {
        $lot = trim($row['lot_no'] ?? '');
        $emb = floatval($row['emb_issue'] ?? 0);
        $back = floatval($row['back_issue'] ?? 0);
        $extra = floatval($row['extra_issue'] ?? 0);
        $rate = floatval($row['adjust_rate'] ?? 0);
        $color_number = floatval($row['color_number'] ?? 1);
        if ($lot === "" || ($emb+$back+$extra)<=0) continue;
        $has_data = true;
        $total_issue = $emb+$back+$extra;
        $total_meter = $total_issue * $color_number;
        $amount = $total_meter * $rate;
        $safe_lot = $conn->real_escape_string($lot);
        
        $job_check = $conn->query("SELECT id FROM jobs WHERE job_no = '$safe_job' AND company_id = $company_id");
        if(!$job = $job_check->fetch_assoc()){ $error_msg = "Job not found"; $all_success=false; break; }
        $job_id = $job['id'];
        
        $purchase = $conn->query("SELECT id, fabric_name, adjust_rate, total_meter, used_meter, COALESCE(remaining_meter, total_meter - COALESCE(used_meter,0)) as remaining_meter FROM fabric_purchase WHERE lot_no = '$safe_lot' AND company_id = $company_id")->fetch_assoc();
        if(!$purchase){ $error_msg="Lot not found"; $all_success=false; break; }
        
        $remaining = floatval($purchase['remaining_meter']);
        if($total_meter > $remaining){ $error_msg="Stock exceeded for lot $lot"; $all_success=false; break; }
        
        $fabric_to_save = $row['fabric_name'] ?: $purchase['fabric_name'];
        $rate_to_save = $rate ?: $purchase['adjust_rate'];
        $safe_fabric = $conn->real_escape_string($fabric_to_save);
        $sql_issue = "INSERT INTO fabric_issue (job_id, job_no, lot_no, fabric_name, emb_issue, back_issue, extra_issue, adjust_rate, color_number, total_meter_with_color, amount, created_at, company_id, user_id) 
                      VALUES ($job_id, '$safe_job', '$safe_lot', '$safe_fabric', $emb, $back, $extra, $rate_to_save, $color_number, $total_meter, $amount, NOW(), $company_id, $user_id)";
        if(!$conn->query($sql_issue)){ $error_msg="Insert error"; $all_success=false; break; }
        
        $new_used = floatval($purchase['used_meter']) + $total_meter;
        $new_remaining = floatval($purchase['total_meter']) - $new_used;
        $conn->query("UPDATE fabric_purchase SET used_meter = $new_used, remaining_meter = $new_remaining WHERE id = {$purchase['id']} AND company_id = $company_id");
    }
    if(!$has_data){ $error_msg="No rows"; $conn->rollback(); }
    elseif($all_success){ $conn->commit(); $fabric_success="Fabric issued!"; header("Location: entry.php?left_job_no=".urlencode($left_job_no)."&tab=fabric_issue"); exit; }
    else { $conn->rollback(); $fabric_error=$error_msg; }
}

$lots_list = [];
$res = $conn->query("SELECT lot_no, fabric_name, adjust_rate, total_meter, COALESCE(used_meter,0) as used_meter, COALESCE(remaining_meter, total_meter - COALESCE(used_meter,0)) AS remaining_meter FROM fabric_purchase WHERE total_meter - COALESCE(used_meter,0) > 0 AND company_id = $company_id ORDER BY id DESC");
while($row = $res->fetch_assoc()){ $row['remaining_meter']=floatval($row['remaining_meter']); $lots_list[]=$row; }

$fabric_job_details = null;
if(!empty($left_job_no) && !$job_not_found){
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT design_name, size, fabric_name FROM jobs WHERE job_no = '$safe_job' AND company_id = $company_id");
    $fabric_job_details = $res->fetch_assoc();
}

$prev_issues = [];
if(!empty($left_job_no) && !$job_not_found){
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT fi.*, j.design_name FROM fabric_issue fi LEFT JOIN jobs j ON fi.job_no = j.job_no WHERE fi.job_no = '$safe_job' AND fi.company_id = $company_id ORDER BY fi.created_at DESC");
    while($issue = $res->fetch_assoc()) $prev_issues[]=$issue;
}

$design_name = $leftJobInfo['design_name'] ?? '';
$combined_issues = [];
if (!empty($design_name) && !$job_not_found) {
    $safe_design = $conn->real_escape_string($design_name);
    $res = $conn->query("SELECT job_no FROM jobs WHERE design_name = '$safe_design' AND company_id = $company_id");
    $same_design_jobs = [];
    while ($dj = $res->fetch_assoc()) {
        $same_design_jobs[] = $dj['job_no'];
    }
    if (!empty($same_design_jobs)) {
        $in_clause = "'" . implode("','", array_map([$conn, 'real_escape_string'], $same_design_jobs)) . "'";
        $res2 = $conn->query("SELECT fi.*, j.design_name, j.size, j.fabric_name, j.job_no as other_job_no
                FROM fabric_issue fi
                LEFT JOIN jobs j ON fi.job_no = j.job_no
                WHERE fi.job_no IN ($in_clause) AND fi.company_id = $company_id
                ORDER BY fi.created_at DESC");
        while ($issue = $res2->fetch_assoc()) {
            $job_key = $issue['other_job_no'] ?? $issue['job_no'];
            if (!isset($combined_issues[$job_key])) {
                $combined_issues[$job_key] = [
                    'job_no' => $job_key,
                    'design_name' => $issue['design_name'],
                    'size' => $issue['size'],
                    'fabric_name' => $issue['fabric_name'],
                    'total_meter' => 0,
                    'total_amount' => 0,
                    'entries' => [],
                    'latest_date' => $issue['created_at']
                ];
            }
            $combined_issues[$job_key]['total_meter'] += floatval($issue['total_meter_with_color'] ?? 0);
            $combined_issues[$job_key]['total_amount'] += floatval($issue['amount'] ?? 0);
            $combined_issues[$job_key]['entries'][] = $issue;
            if (strtotime($issue['created_at']) > strtotime($combined_issues[$job_key]['latest_date'])) {
                $combined_issues[$job_key]['latest_date'] = $issue['created_at'];
            }
        }
    }
    usort($combined_issues, function($a, $b) {
        return strtotime($b['latest_date']) - strtotime($a['latest_date']);
    });
}

// Ensure tables have needed columns
$conn->query("CREATE TABLE IF NOT EXISTS stitching_posted_bills (id INT PRIMARY KEY AUTO_INCREMENT, job_no VARCHAR(50), serial_no VARCHAR(100), emp_name VARCHAR(150), claim_item VARCHAR(150), qty DECIMAL(10,2) DEFAULT 0, rate DECIMAL(10,2) DEFAULT 0, total_amount DECIMAL(12,2) DEFAULT 0, description TEXT, claim_type VARCHAR(100), claim_date DATE, design_name VARCHAR(150), fabric_name VARCHAR(150), size VARCHAR(100), brand_name VARCHAR(100), status VARCHAR(50) DEFAULT 'pending', manual_total DECIMAL(12,2) DEFAULT 0, auto_total DECIMAL(12,2) DEFAULT 0, difference_total DECIMAL(12,2) DEFAULT 0, post_date DATE, bill_no VARCHAR(100) DEFAULT NULL, company_id INT NOT NULL, user_id INT NOT NULL)");
$conn->query("ALTER TABLE stitching_bill_items ADD COLUMN IF NOT EXISTS bill_no VARCHAR(100) DEFAULT NULL");
$conn->query("ALTER TABLE stitching_posted_bills ADD COLUMN IF NOT EXISTS bill_no VARCHAR(100) DEFAULT NULL");

$job_quantity = $leftJobInfo['quantity'] ?? 0;

$embroidery_entries = [];
if($left_job_no && !$job_not_found){
    $safe_job = $conn->real_escape_string($left_job_no);
    $res = $conn->query("SELECT * FROM stitching_bill_items WHERE job_no = '$safe_job' AND tab_type='embroidery_billing' AND company_id = $company_id ORDER BY created_at DESC");
    while($row = $res->fetch_assoc()){
        $embroidery_entries[] = $row;
    }
}
?>
<!-- =============== HTML / JS =============== -->
<!DOCTYPE html>
<html>
<head>
<title>Stitching Billing</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
    :root { --primary: #F39C12; --primary-light: #FEF5E7; --primary-dark: #E67E22; --border: #E9ECEF; --bg-light: #F8F9FA; --text-dark: #2C3E50; --success: #27ae60; --danger: #e74c3c; --info: #3498db; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); color: var(--text-dark); }
    .main-container { margin-left: 14%; padding: 28px 35px; min-height: 100vh; transition: margin-left 0.3s ease; }
    h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 28px; display: flex; align-items: center; gap: 12px; border-bottom: 3px solid var(--primary); padding-bottom: 12px; }
    h2 i { color: var(--primary); }
    .content-row { gap: 30px; }
    .left-column { flex: 1; min-width: 0; }
    .card { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
    .card-header { padding: 16px 20px; background: white; border-bottom: 2px solid var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; }
    .card-header i { color: var(--primary); }
    .card-body { padding: 20px; }
    .nav-tabs { display: flex; flex-wrap: wrap; gap: 3px; border-bottom: 2px solid var(--border); margin-bottom: 24px; position: sticky; z-index: 9; background: #ffffffdd; backdrop-filter: blur(4px); transition: top 0.2s; padding: 5px 0; }
    .nav-tabs .nav-link { padding: 8px 16px; background: transparent; border: none; color: var(--text-dark); font-weight: 600; cursor: pointer; border-radius: 30px; transition: 0.2s; text-decoration: none; font-size: 0.8rem; }
    .nav-tabs .nav-link:hover { background: var(--primary-light); color: var(--primary-dark); }
    .nav-tabs .nav-link.active { background: var(--primary); color: white; box-shadow: 0 2px 6px rgba(243,156,18,0.3); }
    .tab-content { display: none; padding: 20px; border: 1px solid var(--border); border-radius: 20px; margin-bottom: 28px; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
    .fab-issue-row { display: flex; gap: 25px; flex-wrap: wrap; }
    .fab-issue-left { flex: 2; min-width: 280px; }
    .emb-row { display: flex; flex-wrap: wrap; gap: 30px; flex-direction:row; }
    .emb-left { flex: 0 0 70%; max-width: 70%; }
    .emb-right { flex: 0 0 30%; max-width: 30%; }
    @media (max-width: 992px) { .emb-left, .emb-right { flex: 0 0 100%; max-width: 100%; } }
    .fab-issue-right { width: 320px; flex-shrink: 0; }
    .related-card { background: white; border-radius: 16px; border: 1px solid var(--border); overflow: hidden; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .related-card-header { background: var(--primary-light); padding: 12px 15px; border-bottom: 2px solid var(--primary); font-weight: 700; font-size: 0.9rem; }
    .design-issues-list { max-height: 450px; overflow-y: auto; padding: 10px; }
    .design-issue-item { border: 1px solid var(--info); border-radius: 12px; padding: 12px; margin-bottom: 10px; background: white; transition: all 0.2s; }
    .design-issue-item:hover { border-color: var(--primary); box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
    .design-issue-header { display: flex; justify-content: space-between; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px dashed var(--info); font-size: 0.7rem; flex-wrap: wrap; }
    .job-badge { background: var(--info); color: white; padding: 3px 10px; border-radius: 20px; font-size: 0.65rem; }
    .design-name-badge { background: var(--primary); color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; }
    .multiple-entries-badge { background: #f39c12; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.6rem; }
    .design-issue-quantities { margin-top: 8px; padding-top: 5px; border-top: 1px solid var(--info); display: flex; gap: 10px; font-size: 0.7rem; flex-wrap: wrap; }
    .design-issue-total { margin-top: 6px; font-weight: 700; color: var(--info); text-align: right; font-size: 0.75rem; }
    .multi-entry-table, .entries-table, .fi-new-entry-table, .stitching-table, .recent-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .multi-entry-table th, .entries-table th, .fi-new-entry-table th, .stitching-table th, .recent-table th { background: var(--bg-light); padding: 12px 10px; text-align: left; border-bottom: 2px solid var(--primary); font-weight: 600; }
    .multi-entry-table td, .entries-table td, .fi-new-entry-table td, .stitching-table td, .recent-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .stitching-table td:last-child { text-align: center; vertical-align: middle; width: 60px; }
    .stitching-table .btn-remove-row { margin: 0 auto; display: inline-flex; align-items: center; justify-content: center; }
    .multi-entry-table input, .stitching-table input, .fi-new-entry-table input, .fi-new-entry-table select { width: 100%; padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; }
    .add-row-btn { background: none; border: 2px dashed var(--primary); color: var(--primary); padding: 8px 20px; border-radius: 40px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; margin: 10px 0; transition: 0.2s; }
    .add-row-btn:hover { background: var(--primary-light); border-style: solid; }
    .btn-save { background: var(--success); color: white; border: none; border-radius: 40px; padding: 10px 28px; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .warning-message { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 15px; border-radius: 10px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
    .job-total { text-align: right; margin-top: 15px; padding-top: 15px; border-top: 2px solid var(--primary); font-weight: 700; font-size: 1.1rem; }
    .toast-notification { position: fixed; top: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 40px; z-index: 1000; display: none; animation: slideIn 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
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
    .job-info-card-modern { background: #fff8f0; border-radius: 16px; padding: 15px; margin-top: 15px; border: 1px solid #ffe0b5; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .job-image-top { display: flex; justify-content: center; margin-bottom: 15px; }
    .job-thumbnail-large { width: 100px; height: 100px; object-fit: cover; border-radius: 12px; border: 2px solid var(--primary); background: white; }
    .job-details-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 15px; font-size: 0.8rem; }
    .job-detail-item { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 1px dashed #e0c8a0; padding-bottom: 6px; }
    .job-detail-label { font-weight: 600; color: #b45f06; font-size: 0.7rem; text-transform: uppercase; }
    .job-detail-value { font-weight: 700; color: var(--primary-dark); font-size: 0.8rem; }
    @media (max-width: 700px) { .job-details-grid { grid-template-columns: 1fr; gap: 8px; } }
    .excel-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .excel-table th, .excel-table td { border: 1px solid #dee2e6; padding: 6px 10px; vertical-align: middle; }
    .excel-table th { background: #f8f9fc; font-weight: 700; text-align: center; border-bottom: 2px solid #f39c12; }
    .excel-table td:first-child, .excel-table th:first-child { text-align: left; font-weight: 600; background-color: #fcfcfc; }
    .excel-table td:nth-child(2), .excel-table td:nth-child(3), .excel-table td:nth-child(4), .excel-table th:nth-child(2), .excel-table th:nth-child(3), .excel-table th:nth-child(4) { text-align: right; }
    .excel-table tfoot tr.total-row { background-color: #fef5e7; font-weight: 800; border-top: 2px solid #f39c12; }
    .excel-table tfoot tr.total-row td { font-size: 0.85rem; padding: 8px 10px; }
    .party-input { width: 85px; text-align: right; padding: 4px 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.8rem; }
    .edit-buttons { text-align: right; margin-bottom: 8px; }
    .btn-sm-custom { padding: 4px 14px; font-size: 0.7rem; border-radius: 30px; font-weight: 600; border: none; cursor: pointer; }
    .diff-positive { color: #27ae60; font-weight: 600; }
    .diff-negative { color: #e74c3c; font-weight: 600; }
    .btn-action { display: inline-block; padding: 5px 12px; margin: 0 4px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; text-decoration: none; transition: all 0.2s ease; border: none; }
    .btn-edit { background-color: var(--info); color: white; }
    .btn-edit:hover { background-color: #2980b9; color: white; }
    .btn-delete { background-color: var(--danger); color: white; }
    .btn-delete:hover { background-color: #c0392b; color: white; }
    .fi-table-wrapper { overflow-x: auto; margin-bottom: 1rem; }
    .fi-new-entry-table { min-width: 800px; }
    .fi-total-section { background: linear-gradient(135deg, var(--primary-light) 0%, #fff2e0 100%); border-radius: 14px; padding: 12px 20px; margin-top: 20px; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--primary); }
    .fi-total-label { font-weight: 700; color: var(--primary-dark); }
    .fi-total-amount { font-size: 1.2rem; font-weight: 800; color: var(--success); }
    .fi-badge-lot { background: var(--primary); color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
    .prev-issues-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
    .prev-issues-table th { background: var(--bg-light); padding: 8px; border-bottom: 2px solid var(--primary); }
    .prev-issues-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); }
    .fi-empty-state { text-align: center; padding: 20px; color: #999; }
    .stitching-summary { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; margin-bottom: 24px; }
    .stitching-summary-item { background: white; border-radius: 16px; padding: 12px; text-align: center; border: 1px solid var(--border); }
    .stitching-summary-label { font-size: 0.7rem; text-transform: uppercase; color: #666; }
    .stitching-summary-value { font-size: 1.3rem; font-weight: 700; color: var(--primary-dark); }
    .job-info-box { background: var(--primary-light); border-radius: 14px; padding: 12px 16px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .job-info-item { background: white; padding: 5px 14px; border-radius: 30px; font-size: 0.85rem; }
    .grand-total { font-weight: 800; color: var(--success); font-size: 1.2rem; }
    .summary-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
    .summary-table th { background: var(--bg-light); padding: 6px; border-bottom: 1px solid var(--primary); }
    .summary-table td { padding: 4px 6px; border-bottom: 1px solid var(--border); }
    .emb-table-container { overflow-x: auto; margin-bottom: 20px; }
    .emb-table { width: 100%; font-size: 0.8rem; border-collapse: collapse; }
    .emb-table th, .emb-table td { border: 1px solid var(--border); padding: 8px 6px; vertical-align: middle; }
    .emb-table th { background: var(--bg-light); font-weight: 600; white-space: nowrap; }
    .emb-table input { width: 100%; min-width: 80px; padding: 4px 6px; border: 1px solid var(--border); border-radius: 6px; }
    .btn-emb-add, .btn-emb-save { margin-top: 10px; }
    @media (max-width: 1000px) { .main-container { margin-left: 0; padding: 16px; } .costing-row { flex-direction: column; } .costing-col { border-right: none; border-bottom: 1px solid #eceef2; } .search-dual { flex-direction: column; align-items: stretch; } .fab-issue-row { flex-direction: column; } .fab-issue-right { width: 100%; } .emb-row { flex-direction: column; } .excel-table th, .excel-table td { padding: 4px 6px; font-size: 0.7rem; } .party-input { width: 70px; } }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?> 
<div class="main-container">
    <?php if ($job_not_found): ?>
        <div class="alert alert-danger text-center" style="margin: 30px auto; max-width: 600px;">
            <i class="fas fa-exclamation-triangle"></i> Job No <strong><?= htmlspecialchars($left_job_no) ?></strong> not found in the database.
        </div>
    <?php else: ?>
    <h2><i class="fas fa-tshirt"></i> Stitching Billing</h2>
    
    <div class="costing-wrapper" id="costingWrapper">
        <div class="costing-row">
            <div class="costing-col">
                <div class="costing-search-area">
                    <div class="search-dual">
                        <div class="search-group"><label><i class="fas fa-hashtag"></i> Job #</label><input type="text" id="costingJobSearch" list="jobSuggestList" placeholder="Job number" value="<?= htmlspecialchars($left_job_no) ?>"></div>
                        <div class="search-group"><label><i class="fas fa-barcode"></i> Serial #</label><input type="text" id="costingSerialSearch" placeholder="Serial number" value="<?= htmlspecialchars($serial_search) ?>"></div>
                        <button class="costing-load-btn" onclick="loadCostingJob()"><i class="fas fa-search"></i> Load</button>
                    </div>
                    
                    <div class="job-info-card-modern">
                        <div class="job-image-top">
                            <?php 
                            $job_image = '';
                            if($leftJobInfo && !empty($leftJobInfo['image'])){
                                $job_image = "../../assets/uploads/" . $leftJobInfo['image'];
                                if(!file_exists($job_image)) $job_image = '';
                            }
                            if($job_image): ?>
                                <img src="<?= $job_image ?>" class="job-thumbnail-large" alt="Job Image">
                            <?php else: ?>
                                <div class="job-thumbnail-large" style="background:#f0f0f0; display:flex; align-items:center; justify-content:center;">
                                    <i class="fas fa-image fa-3x text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="job-details-grid">
                            <div class="job-detail-item"><span class="job-detail-label">Job No</span><span class="job-detail-value"><?= htmlspecialchars($leftJobInfo['job_no'] ?? '—') ?></span></div>
                            <div class="job-detail-item"><span class="job-detail-label">Serial</span><span class="job-detail-value"><?= htmlspecialchars($leftJobInfo['serial_no'] ?? '—') ?></span></div>
                            <div class="job-detail-item"><span class="job-detail-label">Design</span><span class="job-detail-value"><?= htmlspecialchars($leftJobInfo['design_name'] ?? '—') ?></span></div>
                            <div class="job-detail-item"><span class="job-detail-label">Size</span><span class="job-detail-value"><?= htmlspecialchars($leftJobInfo['size'] ?? '—') ?></span></div>
                            <div class="job-detail-item"><span class="job-detail-label">emb rate</span><span class="job-detail-value"><?= htmlspecialchars($leftJobInfo['embroidery_rate'] ?? '—') ?></span></div>
                            <div class="job-detail-item"><span class="job-detail-label">Qty</span><span class="job-detail-value"><?= intval($leftJobInfo['quantity'] ?? 0) ?> pcs</span></div>
                        </div>
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
                            <?php foreach($costingItems as $item): $auto = $autoRates[$item] ?? 0; $manual = isset($manualRates[$item]) ? $manualRates[$item] : 0; $diff = $manual - $auto; ?>
                            <tr data-item="<?= $item ?>"><td><?= $item ?></td><td class="text-right party-cell"><span class="party-display"><?= fmt($manual) ?></span><input type="number" step="1.0" class="manual-input party-input" value="<?= $manual ?>" style="display:none;"></td><td class="text-right auto-val"><?= fmt($auto) ?></td><td class="text-right diff-val <?= $diff>0?'diff-positive':($diff<0?'diff-negative':'') ?>"><?= ($diff>=0?'+':'').fmt($diff) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><?php $sumParty = array_sum(array_map(function($i)use($manualRates){return $manualRates[$i]??0;},$costingItems)); $sumAuto = array_sum($autoRates); $sumDiff = $sumParty - $sumAuto; ?>
                        <tr class="total-row"><td><strong>TOTAL</strong></td><td id="totalPartyCell" class="text-right"><strong><?= fmt($sumParty) ?></strong></td><td id="totalAutoCell" class="text-right"><strong><?= fmt($sumAuto) ?></strong></td><td id="totalDiffCell" class="text-right <?= $sumDiff>0?'diff-positive':($sumDiff<0?'diff-negative':'') ?>"><strong><?= ($sumDiff>=0?'+':'').fmt($sumDiff) ?></strong></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="costing-col">
                <div style="overflow-x:auto">
                    <table class="excel-table"><thead><tr><th>PROCESS</th><th class="text-right">RATE</th></tr></thead>
                    <tbody><?php $totalStitch=0; foreach($processes as $proc): $rate=$stitchingDetails[$proc]??0; $totalStitch+=$rate; ?>
                    <tr><td style="text-transform:uppercase"><?= $proc ?></td><td class="text-right"><?= fmt($rate) ?></td></tr>
                    <?php endforeach; ?></tbody>
                    <tfoot><tr class="total-row"><td><strong>TOTAL</strong></td><td class="text-right"><strong><?= fmt($totalStitch) ?></strong></td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div id="toastMsg" class="toast-notification"></div>
    
    <div class="content-row">
        <div class="left-column">
            <?php if($left_job_no && $leftJobInfo): ?>
            <div class="nav-tabs" id="stickyTabs">
                <?php foreach($allTypes as $key => $label): ?>
                <a class="nav-link" href="javascript:void(0)" onclick="openTab('<?= $key ?>')"><?= $label ?></a>
                <?php endforeach; ?>
                <a class="nav-link" href="javascript:void(0)" onclick="openTab('material')">Material</a>
                <a class="nav-link" href="javascript:void(0)" onclick="openTab('fabric_issue')">Fabric Issue</a>
                <a class="nav-link" href="javascript:void(0)" onclick="openTab('stitching_depart')">Stitching</a>
                <a class="nav-link" href="javascript:void(0)" onclick="openTab('embroidery_billing')">Emb Billing</a>
            </div>

            <?php foreach($allTypes as $key => $label): ?>
            <div id="<?= $key ?>" class="tab-content">
                <form method="POST" id="form-<?= $key ?>" class="ajax-form" data-tab="<?= $key ?>">
                    <input type="hidden" name="save_multiple" value="1">
                    <input type="hidden" name="job_no" value="<?= htmlspecialchars($left_job_no) ?>">
                    <input type="hidden" name="tab_type" value="<?= $key ?>">
                    <div class="table-responsive"><table class="multi-entry-table" id="table-<?= $key ?>"><thead><tr><th>Vendor Name</th><th>Quantity (Pcs)</th><th>Rate (Rs)</th><th>Amount</th><th></th></tr></thead><tbody id="tbody-<?= $key ?>"></tbody></table></div>
                    <button type="button" class="add-row-btn" onclick="addRow('<?= $key ?>')"><i class="fas fa-plus"></i> Add Row</button>
                    <div id="warning-<?= $key ?>" class="warning-message" style="display:none;"><i class="fas fa-exclamation-triangle"></i><span></span></div>
                    <button type="submit" class="btn-save" id="save-<?= $key ?>"><i class="fas fa-save"></i> Save All Entries</button>
                </form>
            </div>
            <?php endforeach; ?>

            <div id="material" class="tab-content">
                <form method="POST" id="form-material" class="ajax-form" data-tab="material">
                    <input type="hidden" name="save_multiple" value="1">
                    <input type="hidden" name="job_no" value="<?= htmlspecialchars($left_job_no) ?>">
                    <input type="hidden" name="tab_type" value="material">
                    <div class="mb-3">
                        <label for="bill_no_material" class="form-label fw-bold">Bill Number (Manual)</label>
                        <input type="text" name="bill_no" id="bill_no_material" class="form-control" placeholder="Enter bill number">
                    </div>
                    <div class="table-responsive">
                        <table class="multi-entry-table" id="table-material">
                            <thead>
                                <tr><th>Item Name</th><th>Quantity (Pcs/Unit)</th><th>Rate (Rs)</th><th>Amount</th><th></th></tr>
                            </thead>
                            <tbody id="tbody-material"></tbody>
                        </table>
                    </div>
                    <button type="button" class="add-row-btn" onclick="addRow('material')"><i class="fas fa-plus"></i> Add Row</button>
                    <div id="warning-material" class="warning-message" style="display:none;"><i class="fas fa-exclamation-triangle"></i><span></span></div>
                    <button type="submit" class="btn-save" id="save-material"><i class="fas fa-save"></i> Save Material Entries</button>
                </form>
            </div>

            <div id="fabric_issue" class="tab-content">
                <?php if ($fabric_error): ?><div class="alert alert-warning"><?= htmlspecialchars($fabric_error) ?></div><?php endif; ?>
                <?php if ($fabric_success): ?><div class="alert alert-success"><?= htmlspecialchars($fabric_success) ?></div><?php endif; ?>
                <div class="fab-issue-row">
                    <div class="fab-issue-left">
                        <form method="POST" id="fabricIssueForm">
                            <input type="hidden" name="save_fabric_issue" value="1">
                            <input type="hidden" name="main_job_no" value="<?= htmlspecialchars($left_job_no) ?>">
                            <?php if (!empty($left_job_no) && $fabric_job_details): ?>
                                <div class="mb-3"><strong>Design:</strong> <?= htmlspecialchars($fabric_job_details['design_name'] ?? 'N/A') ?> | <strong>Size:</strong> <?= htmlspecialchars($fabric_job_details['size'] ?? 'N/A') ?> | <strong>Fabric:</strong> <?= htmlspecialchars($fabric_job_details['fabric_name'] ?? 'N/A') ?></div>
                            <?php elseif (!empty($left_job_no)): ?><div class="alert alert-danger">Job details not found.</div><?php else: ?><div class="alert alert-danger">No job loaded.</div><?php endif; ?>
                            <div class="fi-table-wrapper">
                                <table class="fi-new-entry-table">
                                    <thead>
                                        <tr><th>Lot No</th><th>Fabric</th><th>Emb (m)</th><th>Back (m)</th><th>Extra (m)</th><th>Rate (Rs/m)</th><th>Colors</th><th>Total Meter</th><th>Amount (Rs)</th><th></th></tr>
                                    </thead>
                                    <tbody id="fiTableBody"></tbody>
                                </table>
                            </div>
                            <button type="button" class="add-row-btn" onclick="fiAddNewRow()"><i class="fas fa-plus"></i> Add Row</button>
                            <div id="fiTotals" style="margin-top:15px;">
                                <div class="fi-total-section"><span class="fi-total-label"><i class="fas fa-calculator"></i> Total Fabric (Meters):</span><span class="fi-total-amount" id="fiTotalMeters">0.00 m</span></div>
                                <div class="fi-total-section"><span class="fi-total-label"><i class="fas fa-rupee-sign"></i> Grand Total Amount:</span><span class="fi-total-amount" id="fiGrandTotalAmount">Rs. 0.00</span></div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary" <?= empty($left_job_no)?'disabled':'' ?>><i class="fas fa-save"></i> Issue Fabric</button>
                                <button type="button" class="btn btn-secondary" onclick="fiResetForm()"><i class="fas fa-times"></i> Reset</button>
                            </div>
                        </form>
                        <?php if (!empty($left_job_no) && !empty($prev_issues)): ?>
                        <div class="mt-4"><h6>Previous Fabric Issues for <?= htmlspecialchars($left_job_no) ?></h6>
                            <div class="table-responsive">
                                <table class="prev-issues-table">
                                    <thead><tr><th>Date</th><th>Lot</th><th>Fabric</th><th>Emb</th><th>Back</th><th>Extra</th><th>Colors</th><th>Total Mtr</th><th>Amount</th></tr></thead>
                                    <tbody><?php $prev_total=0; foreach($prev_issues as $issue): $amount=floatval($issue['amount']??0); $prev_total+=$amount; ?>
                                    <tr><td><?= date('d/m',strtotime($issue['created_at'])) ?></td><td><span class="fi-badge-lot"><?= htmlspecialchars($issue['lot_no']??'') ?></span></td><td><?= htmlspecialchars($issue['fabric_name']??'') ?></td><td><?= number_format($issue['emb_issue']??0,0) ?></td><td><?= number_format($issue['back_issue']??0,0) ?></td><td><?= number_format($issue['extra_issue']??0,0) ?></td><td><?= $issue['color_number']??1 ?></td><td><?= number_format($issue['total_meter_with_color']??0,0) ?></td><td>Rs. <?= number_format($amount,2) ?></td></tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row"><td colspan="8"><strong>Total</strong></td><td><strong>Rs. <?= number_format($prev_total,2) ?></strong></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php elseif (!empty($left_job_no)): ?><div class="fi-empty-state"><i class="fas fa-inbox"></i><p>No previous fabric issues</p></div><?php endif; ?>
                    </div>
                    <div class="fab-issue-right">
                        <div class="related-card">
                            <div class="related-card-header"><i class="fas fa-layer-group"></i> Related Issues (Same Design)</div>
                            <div class="design-issues-list">
                                <?php if (!empty($combined_issues)): ?>
                                    <?php foreach ($combined_issues as $job_key => $job_data): $entry_count = count($job_data['entries']); ?>
                                    <div class="design-issue-item">
                                        <div class="design-issue-header"><span class="job-badge">Job: <?= htmlspecialchars($job_key) ?></span><span><?= date('d M Y', strtotime($job_data['latest_date'])) ?></span><?php if ($entry_count > 1): ?><span class="multiple-entries-badge"><i class="fas fa-layer-group"></i> <?= $entry_count ?> entries</span><?php endif; ?></div>
                                        <div><strong>Design:</strong> <span class="design-name-badge"><?= htmlspecialchars($job_data['design_name'] ?? 'N/A') ?></span></div>
                                        <div><strong>Size:</strong> <?= htmlspecialchars($job_data['size'] ?? 'N/A') ?></div>
                                        <div><strong>Fabric:</strong> <?= htmlspecialchars($job_data['fabric_name'] ?? 'N/A') ?></div>
                                        <?php if ($entry_count > 1): ?>
                                            <div class="mt-2" style="font-size: 0.65rem;"><i class="fas fa-list"></i> <strong>Entries:</strong><ul><?php foreach ($job_data['entries'] as $entry): ?><li>Lot: <?= htmlspecialchars($entry['lot_no'] ?? 'N/A') ?> - <?= number_format($entry['emb_issue'] ?? 0, 0) ?>m Emb, <?= number_format($entry['back_issue'] ?? 0, 0) ?>m Back, <?= number_format($entry['extra_issue'] ?? 0, 0) ?>m Extra, <?= number_format($entry['color_number'] ?? 0, 0) ?> Color</li><?php endforeach; ?></ul></div>
                                        <?php else: $single_entry = $job_data['entries'][0]; ?>
                                        <div><strong>Lot:</strong> <?= htmlspecialchars($single_entry['lot_no'] ?? 'N/A') ?></div>
                                        <div class="design-issue-quantities"><span>Emb: <?= number_format($single_entry['emb_issue'] ?? 0, 0) ?>m</span><span>Back: <?= number_format($single_entry['back_issue'] ?? 0, 0) ?>m</span><span>Extra: <?= number_format($single_entry['extra_issue'] ?? 0, 0) ?>m</span><span>Color: <?= number_format($single_entry['color_number'] ?? 0, 0) ?></span></div>
                                        <?php endif; ?>
                                        <div class="design-issue-total">Total: <?= number_format($job_data['total_meter'], 0) ?>m | Rs. <?= number_format($job_data['total_amount'], 2) ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?><div class="empty-state"><i class="fas fa-inbox"></i><p>No related fabric issues found</p></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="stitching_depart" class="tab-content">
                <?php if ($stitching_error): ?><div class="alert alert-danger"><?= htmlspecialchars($stitching_error) ?></div><?php endif; ?>
                <?php if ($stitching_success): ?><div class="alert alert-success"><?= htmlspecialchars($stitching_success) ?></div><?php endif; ?>
                <div class="stitching-summary"><div class="stitching-summary-item"><div class="stitching-summary-label">Kurti Qty</div><div class="stitching-summary-value"><?= $saved_kurti ?></div><div class="stitching-summary-limit">Limit: <?= $job_quantity ?></div></div>
                <div class="stitching-summary-item"><div class="stitching-summary-label">Shalwar Qty</div><div class="stitching-summary-value"><?= $saved_shalwar ?></div><div class="stitching-summary-limit">Limit: <?= $job_quantity ?></div></div>
                <div class="stitching-summary-item"><div class="stitching-summary-label">Dupatta Qty</div><div class="stitching-summary-value"><?= $saved_dupatta ?></div><div class="stitching-summary-limit">Limit: <?= $job_quantity ?></div></div></div>
                <form method="POST" id="stitchingDeptForm">
                    <input type="hidden" name="save_stitching" value="1">
                    <input type="hidden" name="job_no" value="<?= htmlspecialchars($left_job_no) ?>">
                    <div class="table-responsive">
                        <table class="stitching-table">
                            <thead>
                                <tr><th>Vendor Name</th><th>Dept</th><th>Color</th><th>Kurti Qty</th><th>Kurti Rate</th><th>Shalwar Qty</th><th>Shalwar Rate</th><th>Dupatta Qty</th><th>Dupatta Rate</th><th></th></tr>
                            </thead>
                            <tbody id="stitchingDeptTableBody">
                                <tr>
                                    <td><input type="text" name="name[]" list="partyList" placeholder="Vendor Name" required></td>
                                    <td><input type="text" name="department[]" placeholder="Dept"></td>
                                    <td><input type="text" name="color[]" placeholder="Color"></td>
                                    <td><input type="number" name="kurti_qty[]" step="1" min="0" class="kurti-qty" value="0" onchange="validateStitchingQuantities()"></td>
                                    <td><input type="number" name="kurti_rate[]" step="0.25" min="0" class="kurti-rate" value="0"></td>
                                    <td><input type="number" name="shalwar_qty[]" step="1" min="0" class="shalwar-qty" value="0" onchange="validateStitchingQuantities()"></td>
                                    <td><input type="number" name="shalwar_rate[]" step="0.25" min="0" class="shalwar-rate" value="0"></td>
                                    <td><input type="number" name="dupatta_qty[]" step="1" min="0" class="dupatta-qty" value="0" onchange="validateStitchingQuantities()"></td>
                                    <td><input type="number" name="dupatta_rate[]" step="0.25" min="0" class="dupatta-rate" value="0"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger remove-row-btn" onclick="removeStitchingRow(this)"><i class="fas fa-trash-alt"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-outline-primary rounded-pill mt-2" onclick="addStitchingRow()"><i class="fas fa-plus-circle me-1"></i> Add Row</button>
                    <div id="stitchingWarning" class="alert alert-warning mt-2" style="display:none;"><span id="stitchingWarningMsg"></span></div>
                    <div class="mt-3 d-flex gap-2"><button type="submit" class="btn btn-success" id="stitchingSaveBtn"><i class="fas fa-save"></i> Save Stitching Entries</button><a href="?left_job_no=<?= urlencode($left_job_no) ?>&tab=stitching_depart" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a></div>
                </form>
                <?php if(!empty($stitching_recent_entries)): ?>
                <div class="mt-4"><h6>Recent Stitching Entries</h6><div class="table-responsive"><table class="recent-table"><thead><tr><th>Date</th><th>Name</th><th>Dept</th><th>Color</th><th>Kurti</th><th>Shalwar</th><th>Dupatta</th><th>Amount</th><th>Action</th></tr></thead><tbody><?php foreach($stitching_recent_entries as $entry){ ?><tr><td><?= date('d/m H:i',strtotime($entry['created_at'])) ?></td><td><?= htmlspecialchars($entry['name']) ?></td><td><span class="badge bg-light text-dark"><?= htmlspecialchars($entry['department']?:'-') ?></span></td><td><?= htmlspecialchars($entry['color']?:'-') ?></td><td><?= $entry['kurti_qty'] ?></td><td><?= $entry['shalwar_qty'] ?></td><td><?= $entry['dupatta_qty'] ?></td><td>Rs. <?= number_format($entry['amount'],2) ?></td><td><a href="edit_billing.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i> Edit</a></td></tr><?php } ?></tbody></table></div></div><?php endif; ?>
            </div>

            <div id="embroidery_billing" class="tab-content">
                <?php if(empty($embroidery_entries)): ?>
                    <div class="formula-hint alert alert-info"><i class="fas fa-calculator"></i> Calculation: (Stitches/1000) × Rate × Head × Rounds = Sub Total</div>
                    <div id="jobInfoBoxEmb" class="job-info-box"></div>
                    <div class="emb-row d-flex gap-4">
                        <div class="emb-left">
                            <form method="POST" id="embBillingForm">
                                <input type="hidden" name="save_embroidery_bill" value="1">
                                <input type="hidden" name="job_no" id="embJobNo" value="<?= htmlspecialchars($left_job_no) ?>">
                                <input type="hidden" name="billing_date" value="<?= date('Y-m-d') ?>">
                                <div class="mb-3">
                                    <label for="bill_no_emb" class="form-label fw-bold">Bill Number (Manual)</label>
                                    <input type="text" name="bill_no" id="bill_no_emb" class="form-control" placeholder="Enter bill number">
                                </div>
                                <div class="emb-table-container d-flex flex-column">
                                    <table class="emb-table">
                                        <thead><tr><th>Part Name</th><th>Stitches</th><th>Rate (Rs.)</th><th>Rounds</th><th>Head</th><th>Sub Total (Rs.)</th><th></th></tr></thead>
                                        <tbody id="embBillingTableBody"></tbody>
                                        <tfoot>
                                            <tr><td colspan="5" class="text-end fw-bold">Adjustment:</td><td><input type="number" step="0.01" name="adjustment" id="embAdjustment" class="form-control" value="0.00" oninput="calculateEmbGrandTotal()"></td><td></td></tr>
                                            <tr class="table-info"><td colspan="5" class="text-end fw-bold">GRAND TOTAL (Rounded):</td><td class="fw-bold grand-total" id="embGrandTotal">Rs. 0</td><td></td></tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <button type="button" id="embAddRowBtn" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add Row</button>
                                    <button type="submit" name="save" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Embroidery Bill</button>
                                    <button type="button" id="embClearRowsBtn" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Clear All Rows</button>
                                </div>
                            </form>
                        </div>
                        <div class="emb-right">
                            <div class="card"><div class="card-header"><i class="fas fa-chart-bar"></i> Summary</div><div class="card-body"><div class="table-responsive"><table class="summary-table"><thead><tr><th>Part</th><th class="text-end">Stitches</th><th class="text-end">Rounds</th><th class="text-end">Machine</th></tr></thead><tbody id="embSummaryDetails"><tr><td colspan="4" class="text-center">No data</td></tr></tbody></table></div></div></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-3"><h5><i class="fas fa-thread"></i> Embroidery Billing Entries</h5><span class="badge bg-danger">Already Billed</span></div>
                    <div class="table-responsive"><table class="entries-table"><thead><tr><th>Date</th><th>Part</th><th>Stitches</th><th>Rate</th><th>Rounds</th><th>Head</th><th>Amount</th></tr></thead><tbody><?php foreach($embroidery_entries as $entry): $stitches = $entry['stitch'] ?? ($entry['qty'] ?? 0); $rate = $entry['rate'] ?? 0; $rounds = $entry['round_qty'] ?? 1; $head = $entry['head'] ?? 1; $amount = $entry['amount'] ?? 0; ?><tr><td><?= date('d-m-Y', strtotime($entry['created_at'])) ?></td><td><?= htmlspecialchars($entry['part_name'] ?? 'N/A') ?></td><td><?= number_format($stitches) ?></td><td><?= number_format($rate, 2) ?></td><td><?= $rounds ?></td><td><?= $head ?></td><td>Rs. <?= number_format($amount, 2) ?></td></tr><?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header"><i class="fas fa-list"></i> Recent Entries (All Tabs)</div>
                <div class="card-body">
                    <?php if(!empty($left_entries)): ?>
                    <div class="table-responsive"><table class="entries-table"><thead><tr><th>Date</th><th>Type</th><th>Vendor/Item</th><th>Qty</th><th>Rate</th><th>Amount</th><th>Action</th></tr></thead><tbody><?php foreach($left_entries as $entry): $edit_url = ''; $delete_url = ''; $tab_type = $entry['tab_type']; switch($tab_type) { case 'stitching_depart': $edit_url = "edit_billing.php?id=" . $entry['id']; $delete_url = "?delete_id=" . $entry['id'] . "&job_no=" . urlencode($entry['job_no']) . "&vendor_name=" . urlencode($entry['name']) . "&tab_type=" . urlencode($tab_type); break; case 'embroidery_billing': $edit_url = "edit_emb_bill.php?job_no=" . urlencode($entry['job_no']); $delete_url = "?delete_emb_id=" . $entry['id'] . "&job_no=" . urlencode($entry['job_no']); break; case 'fabric_issue': $edit_url = "edit_fabric_issue.php?id=" . $entry['id']; $delete_url = "delete_issue.php?id=" . $entry['id']; break; case 'material': $edit_url = "edit_material.php?id=" . $entry['id']; $delete_url = "delete_material_entry.php?id=" . $entry['id'] . "&job_no=" . urlencode($entry['job_no']) . "&vendor_name=" . urlencode($entry['name']) . "&tab_type=" . urlencode($tab_type); break; default: $edit_url = "edit_stitching_billing.php?id=" . $entry['id']; $delete_url = "?delete_id=" . $entry['id'] . "&job_no=" . urlencode($entry['job_no']) . "&vendor_name=" . urlencode($entry['name']) . "&tab_type=" . urlencode($tab_type); } ?>
                    <tr><td><?= date('d-m-Y',strtotime($entry['created_at'])) ?></td><td><span class="badge bg-info"><?= ucwords(str_replace('_',' ',$entry['tab_type'])) ?></span></td><td><?= htmlspecialchars($entry['name']) ?></td><td class="text-end"><?= $entry['qty'] ?></td><td class="text-end">Rs <?= number_format($entry['rate'],2) ?></td><td class="text-end amount">Rs <?= number_format($entry['amount'],2) ?></td><td><div class="action-buttons"><a href="<?= $edit_url ?>" class="btn-action btn-edit"><i class="fas fa-edit"></i> Edit</a> <a href="<?= $delete_url ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i> Delete</a></div></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php else: ?><p class="text-muted">No entries found</p><?php endif; ?>
                </div>
            </div>
            <div class="job-total">Total All Entries: <span class="fw-bold">Rs <?= number_format($left_grand_total,2) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<datalist id="jobSuggestList"><?php foreach($jobs_suggest_list as $job): ?><option value="<?= htmlspecialchars($job) ?>"><?php endforeach; ?></datalist>
<datalist id="vendorSuggestList"><?php foreach($vendors_list as $vendor): ?><option value="<?= htmlspecialchars($vendor) ?>"><?php endforeach; ?></datalist>
<datalist id="partyList"><?php foreach($vendors_list as $vendor): ?><option value="<?= htmlspecialchars($vendor) ?>"><?php endforeach; ?></datalist>

<script>
const jobQty = <?= intval($leftJobInfo['quantity'] ?? 0) ?>;
const existingQtys = <?php echo json_encode($existing_quantities); ?>;
let rowCounters = {};
const savedKurti = <?= $saved_kurti ?>;
const savedShalwar = <?= $saved_shalwar ?>;
const savedDupatta = <?= $saved_dupatta ?>;

function showToast(msg, isErr=false){ let t=document.getElementById('toastMsg'); t.textContent=msg; t.style.backgroundColor=isErr?'#e74c3c':'#27ae60'; t.style.display='block'; setTimeout(()=>t.style.display='none',3000); }
function openTab(tab){
    document.querySelectorAll('.tab-content').forEach(t=>t.style.display='none');
    document.getElementById(tab).style.display='block';
    document.querySelectorAll('.nav-link').forEach(l=>l.classList.remove('active'));
    event.target.classList.add('active');
    if(tab !== 'fabric_issue' && tab !== 'stitching_depart' && tab !== 'embroidery_billing' && tab !== 'material' && !rowCounters[tab]){ rowCounters[tab]=0; for(let i=0;i<2;i++) addRow(tab); }
    if(tab === 'material' && !rowCounters['material']){ rowCounters['material']=0; for(let i=0;i<2;i++) addRow('material'); }
    if(tab === 'fabric_issue' && typeof fiAddNewRow === 'function'){ if(document.getElementById('fiTableBody').children.length === 0) for(let i=0;i<4;i++) fiAddNewRow(); }
    if(tab === 'stitching_depart'){ validateStitchingQuantities(); }
    if(tab === 'embroidery_billing' && <?= empty($embroidery_entries) ? 'true' : 'false' ?> && '<?= addslashes($left_job_no) ?>' !== ''){ loadEmbroideryJob(); }
}
function addRow(tab){
    let tbody=document.getElementById('tbody-'+tab);
    let rowId='row_'+tab+'_'+Date.now()+'_'+(rowCounters[tab]||0);
    if(!rowCounters[tab]) rowCounters[tab]=0;
    rowCounters[tab]++;
    let tr=document.createElement('tr'); tr.id=rowId;
    let nameInput = (tab === 'material') 
        ? `<input type="text" name="rows[${rowId}][name]" placeholder="Item name" oninput="calculateRowAmount('${tab}','${rowId}')">`
        : `<input type="text" name="rows[${rowId}][name]" list="vendorSuggestList" placeholder="Vendor name" oninput="calculateRowAmount('${tab}','${rowId}')">`;
    tr.innerHTML = `<td>${nameInput}</td>
                    <td><input type="number" name="rows[${rowId}][qty]" class="qty-${tab}" value="0" step="1" min="0" oninput="calculateRowAmount('${tab}','${rowId}'); checkTabQty('${tab}')"></td>
                    <td><input type="number" name="rows[${rowId}][rate]" class="rate-${tab}" value="0" step="0.25" min="0" oninput="calculateRowAmount('${tab}','${rowId}')"></td>
                    <td><input type="text" name="rows[${rowId}][amount]" class="amount-${tab}" readonly value="Rs 0.00" style="background:#e8f5e9;"></td>
                    <td class="remove-row" onclick="removeRow('${rowId}','${tab}')"><i class="fas fa-trash"></i></td>`;
    tbody.appendChild(tr);
}
function removeRow(rowId,tab){ if(confirm('Remove this row?')){ document.getElementById(rowId).remove(); checkTabQty(tab); } }
function calculateRowAmount(tab,rowId){
    let row=document.getElementById(rowId);
    if(!row) return;
    let qty=parseFloat(row.querySelector('.qty-'+tab)?.value)||0;
    let rate=parseFloat(row.querySelector('.rate-'+tab)?.value)||0;
    row.querySelector('.amount-'+tab).value='Rs '+(qty*rate).toFixed(2);
}
function checkTabQty(tab){
    if(tab === 'material') return;
    let totalNew=0; document.querySelectorAll('#tbody-'+tab+' .qty-'+tab).forEach(inp=>totalNew+=parseFloat(inp.value)||0);
    let existing=existingQtys[tab]||0, total=existing+totalNew;
    let warnDiv=document.getElementById('warning-'+tab), saveBtn=document.getElementById('save-'+tab);
    if(jobQty>0 && total>jobQty){ warnDiv.querySelector('span').innerHTML=`Quantity limit exceeded! Job total: ${jobQty}`; warnDiv.style.display='flex'; if(saveBtn) saveBtn.disabled=true; }
    else { if(warnDiv) warnDiv.style.display='none'; if(saveBtn) saveBtn.disabled=false; }
}
document.querySelectorAll('.ajax-form').forEach(form=>{
    form.addEventListener('submit',function(e){ e.preventDefault(); let tab=this.dataset.tab; let hasData=false; document.querySelectorAll('#tbody-'+tab+' .qty-'+tab).forEach(inp=>{if(parseFloat(inp.value)>0) hasData=true;}); if(!hasData){ alert('Add at least one entry'); return; } let totalNew=0; document.querySelectorAll('#tbody-'+tab+' .qty-'+tab).forEach(inp=>totalNew+=parseFloat(inp.value)||0); let existing=existingQtys[tab]||0; if(tab !== 'material' && jobQty>0 && existing+totalNew>jobQty){ alert('Quantity limit exceeded'); return; } let fd=new FormData(this); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){ showToast(d.message); setTimeout(()=>location.href='?left_job_no='+encodeURIComponent(d.job_no),500); } else showToast(d.message,true); }); });
});
function loadCostingJob(){ let jobNo = document.getElementById('costingJobSearch').value.trim(); let serialNo = document.getElementById('costingSerialSearch').value.trim(); if(jobNo !== "") window.location.href = '?left_job_no=' + encodeURIComponent(jobNo); else if(serialNo !== "") window.location.href = '?serial=' + encodeURIComponent(serialNo); else alert('Enter either Job # or Serial #'); }

let originalManualValues = {};
function enableCostEditing(){
    document.querySelectorAll('#costingTableMain .party-display').forEach(span=>span.style.display='none');
    document.querySelectorAll('#costingTableMain .party-input').forEach(inp=>{ inp.style.display='inline-block'; let row=inp.closest('tr'); if(row) originalManualValues[row.dataset.item]=inp.value; });
    document.getElementById('editRatesBtn').style.display='none';
    document.getElementById('saveRatesBtn').style.display='inline-block';
    document.getElementById('cancelEditBtn').style.display='inline-block';
}
function cancelCostEditing(){ location.reload(); }
function saveAllRates(){
    const jobNo = '<?= addslashes($left_job_no) ?>';
    if(!jobNo){ alert('No job loaded'); return; }
    const rows = document.querySelectorAll('#costingTableMain tbody tr');
    let costData=[], newPartySum=0;
    rows.forEach(row=>{
        const item=row.dataset.item;
        const autoRate=parseFloat(row.querySelector('.auto-val').innerText.replace(/,/g,''))||0;
        let manualInput=row.querySelector('.party-input');
        let manualRate=parseFloat(manualInput.value)||0;
        newPartySum+=manualRate;
        costData.push({type:item, manual_rate:manualRate, auto_rate:autoRate});
        let displaySpan=row.querySelector('.party-display');
        displaySpan.innerText=manualRate.toFixed(2);
        let diff=manualRate-autoRate;
        let diffCell=row.querySelector('.diff-val');
        diffCell.innerText=(diff>=0?'+':'')+diff.toFixed(2);
        diffCell.className='text-right diff-val '+(diff>0?'diff-positive':(diff<0?'diff-negative':''));
    });
    let totalAuto=0; document.querySelectorAll('#costingTableMain .auto-val').forEach(el=>totalAuto+=parseFloat(el.innerText.replace(/,/g,'')));
    document.getElementById('totalPartyCell').innerHTML='<strong>'+newPartySum.toFixed(2)+'</strong>';
    document.getElementById('totalAutoCell').innerHTML='<strong>'+totalAuto.toFixed(2)+'</strong>';
    let totalDiff=newPartySum-totalAuto;
    let totalDiffCell=document.getElementById('totalDiffCell');
    totalDiffCell.innerHTML='<strong>'+(totalDiff>=0?'+':'')+totalDiff.toFixed(2)+'</strong>';
    totalDiffCell.className='text-right '+(totalDiff>0?'diff-positive':(totalDiff<0?'diff-negative':''));
    fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'save_manual_costs=1&job_no='+encodeURIComponent(jobNo)+'&cost_data='+encodeURIComponent(JSON.stringify(costData))})
    .then(res=>res.text()).then(data=>{if(data.trim()==='success'){ showToast('Party rates saved!'); location.reload(); } else alert('Error: '+data);}).catch(err=>alert('Request failed'));
}

const lotsData = <?= json_encode($lots_list) ?>;
let fiRowCount=0;
function getLotOptions(){ let ops='<option value="">Select Lot</option>'; if(lotsData.length) lotsData.forEach(lot=>{ let r=lot.remaining_meter||0; ops+=`<option value="${lot.lot_no}" data-fabric="${lot.fabric_name||''}" data-rate="${lot.adjust_rate||0}" data-remaining="${r}">${lot.lot_no} (${r.toFixed(0)}m left)</option>`;}); return ops; }
function fiCalculateRowTotal(rowId){ let emb=parseFloat(document.querySelector(`.fi-emb-${rowId}`)?.value)||0; let back=parseFloat(document.querySelector(`.fi-back-${rowId}`)?.value)||0; let extra=parseFloat(document.querySelector(`.fi-extra-${rowId}`)?.value)||0; let colors=parseFloat(document.querySelector(`.fi-color-num-${rowId}`)?.value)||1; let rate=parseFloat(document.querySelector(`.fi-rate-${rowId}`)?.value)||0; let totalMeter=(emb+back+extra)*colors; let amount=totalMeter*rate; document.querySelector(`.fi-total-meter-${rowId}`).value=totalMeter.toFixed(2); document.querySelector(`.fi-amount-${rowId}`).value='Rs. '+amount.toFixed(2); fiCalculateGrandTotal(); return amount; }
function fiCalculateGrandTotal(){ let gt=0, totalMeters=0; document.querySelectorAll('[class*="fi-amount-"]').forEach(el=>{ if(el.value && el.value.startsWith('Rs.')) gt+=parseFloat(el.value.replace('Rs.',''))||0; }); document.querySelectorAll('[class*="fi-total-meter-"]').forEach(el=>{ totalMeters+=parseFloat(el.value)||0; }); document.getElementById('fiGrandTotalAmount').innerHTML='Rs. '+gt.toFixed(2); document.getElementById('fiTotalMeters').innerHTML=totalMeters.toFixed(2)+' m'; }
function fiCheckStock(rowId){ let sel=document.querySelector(`#${rowId} .fi-lot-select`); if(!sel||!sel.value) return true; let rem=parseFloat(sel.options[sel.selectedIndex].dataset.remaining)||0; let emb=parseFloat(document.querySelector(`.fi-emb-${rowId}`).value)||0; let back=parseFloat(document.querySelector(`.fi-back-${rowId}`).value)||0; let extra=parseFloat(document.querySelector(`.fi-extra-${rowId}`).value)||0; let colors=parseFloat(document.querySelector(`.fi-color-num-${rowId}`).value)||1; let total=(emb+back+extra)*colors; if(total>rem){ alert(`Stock exceeded! Available: ${rem.toFixed(0)}m`); return false; } fiCalculateRowTotal(rowId); return true; }
function fiUpdateLotInfo(select,rowId){ let sel=select.options[select.selectedIndex]; if(sel.value){ document.querySelector(`.fi-fabric-${rowId}`).value=sel.dataset.fabric||''; document.querySelector(`.fi-rate-${rowId}`).value=sel.dataset.rate||0; } else { document.querySelector(`.fi-fabric-${rowId}`).value=''; document.querySelector(`.fi-rate-${rowId}`).value=''; } fiCalculateRowTotal(rowId); }
function fiAddNewRow(){ let tbody=document.getElementById('fiTableBody'); let rowId='fi_row_'+Date.now()+'_'+fiRowCount; fiRowCount++; let newRow=document.createElement('tr'); newRow.id=rowId; newRow.innerHTML=`<td><select name="rows[${rowId}][lot_no]" class="fi-lot-select" onchange="fiUpdateLotInfo(this,'${rowId}')">${getLotOptions()}</select></td>
        <td><input type="text" name="rows[${rowId}][fabric_name]" class="fi-fabric-${rowId}" readonly placeholder="Auto"></td>
        <td><input type="number" step="0.25" name="rows[${rowId}][emb_issue]" class="fi-emb-${rowId}" value="0" oninput="fiCheckStock('${rowId}')"></td>
        <td><input type="number" step="0.25" name="rows[${rowId}][back_issue]" class="fi-back-${rowId}" value="0" oninput="fiCheckStock('${rowId}')"></td>
        <td><input type="number" step="0.25" name="rows[${rowId}][extra_issue]" class="fi-extra-${rowId}" value="0" oninput="fiCheckStock('${rowId}')"></td>
        <td><input type="number" step="0.25" name="rows[${rowId}][adjust_rate]" class="fi-rate-${rowId}" readonly></td>
        <td><input type="number" step="1" min="1" name="rows[${rowId}][color_number]" class="fi-color-num-${rowId}" value="1" oninput="fiCheckStock('${rowId}')"></td>
        <td><input type="number" step="0.01" name="rows[${rowId}][total_meter]" class="fi-total-meter-${rowId}" readonly value="0" style="background:#e8f5e9; font-weight:bold;"></td>
        <td><input type="text" name="rows[${rowId}][amount]" class="fi-amount-${rowId}" readonly value="Rs. 0.00" style="background:#e8f5e9; font-weight:bold;"></td>
        <td><i class="fas fa-trash remove-row" onclick="fiRemoveRow('${rowId}')"></i></td>`; tbody.appendChild(newRow); }
function fiRemoveRow(rowId){ if(confirm('Remove this row?')){ document.getElementById(rowId).remove(); fiCalculateGrandTotal(); } }
function fiResetForm(){ document.getElementById('fiTableBody').innerHTML=''; fiRowCount=0; for(let i=0;i<4;i++) fiAddNewRow(); fiCalculateGrandTotal(); }

function addStitchingRow(){ let tbody=document.getElementById('stitchingDeptTableBody'); let newRow=document.createElement('tr'); newRow.innerHTML=`<td><input type="text" name="name[]" list="partyList" placeholder="Vendor Name" required></td>
        <td><input type="text" name="department[]" placeholder="Dept"></td>
        <td><input type="text" name="color[]" placeholder="Color"></td>
        <td><input type="number" name="kurti_qty[]" step="1" min="0" class="kurti-qty" value="0" onchange="validateStitchingQuantities()"></td>
        <td><input type="number" name="kurti_rate[]" step="0.25" min="0" class="kurti-rate" value="0"></td>
        <td><input type="number" name="shalwar_qty[]" step="1" min="0" class="shalwar-qty" value="0" onchange="validateStitchingQuantities()"></td>
        <td><input type="number" name="shalwar_rate[]" step="0.25" min="0" class="shalwar-rate" value="0"></td>
        <td><input type="number" name="dupatta_qty[]" step="1" min="0" class="dupatta-qty" value="0" onchange="validateStitchingQuantities()"></td>
        <td><input type="number" name="dupatta_rate[]" step="0.25" min="0" class="dupatta-rate" value="0"></td>
        <td><button type="button" class="btn btn-sm btn-danger remove-row-btn" onclick="removeStitchingRow(this)"><i class="fas fa-trash-alt"></i></button></td>`; tbody.appendChild(newRow); }
function removeStitchingRow(btn){ let tbody=document.getElementById('stitchingDeptTableBody'); if(tbody.children.length>1){ btn.closest('tr').remove(); validateStitchingQuantities(); } else alert('At least one row must remain'); }
function validateStitchingQuantities(){ let totalNewKurti=0,totalNewShalwar=0,totalNewDupatta=0; document.querySelectorAll('#stitchingDeptTableBody .kurti-qty').forEach(inp=>totalNewKurti+=parseInt(inp.value)||0); document.querySelectorAll('#stitchingDeptTableBody .shalwar-qty').forEach(inp=>totalNewShalwar+=parseInt(inp.value)||0); document.querySelectorAll('#stitchingDeptTableBody .dupatta-qty').forEach(inp=>totalNewDupatta+=parseInt(inp.value)||0); let warnings=[], isValid=true; if(totalNewKurti>0 && (savedKurti+totalNewKurti)>jobQty){ warnings.push('Kurti limit exceeded'); isValid=false; } if(totalNewShalwar>0 && (savedShalwar+totalNewShalwar)>jobQty){ warnings.push('Shalwar limit exceeded'); isValid=false; } if(totalNewDupatta>0 && (savedDupatta+totalNewDupatta)>jobQty){ warnings.push('Dupatta limit exceeded'); isValid=false; } let warnDiv=document.getElementById('stitchingWarning'), warnMsg=document.getElementById('stitchingWarningMsg'); if(warnings.length){ warnDiv.style.display='block'; warnMsg.innerHTML=warnings.join('<br>'); document.getElementById('stitchingSaveBtn').disabled=true; } else { warnDiv.style.display='none'; document.getElementById('stitchingSaveBtn').disabled=false; } return isValid; }
document.getElementById('stitchingDeptForm')?.addEventListener('submit',function(e){ if(!validateStitchingQuantities()){ e.preventDefault(); alert('Please fix quantity issues before saving'); } });

const DEFAULT_RATE = 1.30;
window.defaultEmbHead = 1;

function mergePartsByPartName(entries) {
    let map = new Map();
    if (!entries || !Array.isArray(entries)) return [];
    entries.forEach(entry => {
        let part = entry.part || 'Main';
        let key = part.toLowerCase();
        if (!map.has(key)) {
            map.set(key, { part: part, stitches: 0, rounds: 0, head: entry.head_used || 1, machines: [] });
        }
        let item = map.get(key);
        item.stitches += parseFloat(entry.stitches || entry.stitch_done) || 0;
        item.rounds += parseFloat(entry.rounds) || 0;
        if (entry.machine_no && !item.machines.includes(entry.machine_no)) {
            item.machines.push(entry.machine_no);
        }
    });
    return Array.from(map.values());
}
function loadEmbroideryJob() {
    let jobNo = document.getElementById('embJobNo')?.value || '<?= addslashes($left_job_no) ?>';
    if (!jobNo) return;
    fetch(`check_emb_bill_details.php?job_no=${encodeURIComponent(jobNo)}`)
        .then(response => response.json())
        .then(res => {
            if (res.error) { $('#embroidery_billing').prepend('<div class="alert alert-danger">' + res.error + '</div>'); return; }
            if (res.already_billed === true) { location.reload(); return; }
            let merged = mergePartsByPartName(res.entries);
            calculateDefaultHead(merged);
            populateEmbroideryForm(res.job, merged);
        })
        .catch(err => { console.error(err); $('#embroidery_billing').prepend('<div class="alert alert-danger">Error loading job details</div>'); });
}
function calculateDefaultHead(parts) {
    if (!parts || parts.length === 0) { window.defaultEmbHead = 1; return; }
    let headCounts = {};
    parts.forEach(part => { let head = part.head > 0 ? part.head : 1; headCounts[head] = (headCounts[head] || 0) + 1; });
    let maxCount = 0, mostFrequentHead = 1;
    for (let h in headCounts) { if (headCounts[h] > maxCount) { maxCount = headCounts[h]; mostFrequentHead = parseInt(h); } }
    window.defaultEmbHead = mostFrequentHead;
}
function populateEmbroideryForm(job, parts) {
    $('#embJobNo').val(job.job_no);
    let jobHtml = `<div class="job-info-item"><strong>Job:</strong> ${job.job_no}</div>
                   <div class="job-info-item"><strong>Design:</strong> ${job.design_name || 'N/A'}</div>
                   <div class="job-info-item"><strong>Size:</strong> ${job.size || 'N/A'}</div>
                   <div class="job-info-item"><strong>Vendor:</strong> ${job.embroidery_vendor_name || 'N/A'}</div>`;
    $('#jobInfoBoxEmb').html(jobHtml);
    let tableHtml = '', summaryHtml = '';
    if (parts.length === 0) {
        tableHtml = '<tr><td colspan="7" class="text-center">No embroidery entries found.</td></tr>';
        summaryHtml = '<tr><td colspan="4" class="text-center">No data</td></tr>';
    } else {
        parts.forEach(item => {
            let headVal = item.head > 0 ? item.head : 1;
            let subTotal = (item.stitches / 1000) * DEFAULT_RATE * headVal * item.rounds;
            let machineList = item.machines.join(', ');
            summaryHtml += `<tr><td><strong>${escapeHtml(item.part)}</strong></td><td class="text-end">${item.stitches.toLocaleString()}</td><td class="text-end">${item.rounds}</td><td class="text-end">${machineList}</td></tr>`;
            tableHtml += `<tr>
                <td><input type="text" name="part_name[]" class="form-control" value="${escapeHtml(item.part)}"></td>
                <td><input type="number" step="any" name="stitch_round[]" class="form-control stitch-round" value="${item.stitches}" oninput="calculateEmbRow(this)"></td>
                <td><input type="number" step="0.05" name="rate[]" class="form-control rate" value="${DEFAULT_RATE.toFixed(2)}" oninput="calculateEmbRow(this)"></td>
                <td><input type="number" step="0.01" name="round[]" class="form-control round" value="${formatDecimal(item.rounds)}" oninput="calculateEmbRow(this)"></td>
                <td><input type="number" step="1.0" name="head[]" class="form-control head" value="${headVal}" oninput="calculateEmbRow(this)"></td>
                <td><input type="number" step="0.01" name="sub_total[]" class="form-control sub-total text-end" readonly value="${subTotal.toFixed(2)}"></td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeEmbroideryRow(this)"><i class="fas fa-times"></i></button></td>
            </tr>`;
        });
    }
    $('#embSummaryDetails').html(summaryHtml);
    $('#embBillingTableBody').html(tableHtml);
    calculateEmbGrandTotal();
}
function addEmbroideryRow() {
    const tbody = document.getElementById('embBillingTableBody');
    const defaultHead = (window.defaultEmbHead && window.defaultEmbHead > 0) ? window.defaultEmbHead : 1;
    const newRow = document.createElement('tr');
    newRow.innerHTML = `<td><input type="text" name="part_name[]" class="form-control" placeholder="Part name"></td>
        <td><input type="number" step="any" name="stitch_round[]" class="form-control stitch-round" value="0" oninput="calculateEmbRow(this)"></td>
        <td><input type="number" step="0.05" name="rate[]" class="form-control rate" value="${DEFAULT_RATE.toFixed(2)}" oninput="calculateEmbRow(this)"></td>
        <td><input type="number" step="0.01" name="round[]" class="form-control round" value="1" oninput="calculateEmbRow(this)"></td>
        <td><input type="number" step="1.0" name="head[]" class="form-control head" value="${defaultHead}" oninput="calculateEmbRow(this)"></td>
        <td><input type="number" step="0.01" name="sub_total[]" class="form-control sub-total text-end" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeEmbroideryRow(this)"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(newRow);
}
function formatDecimal(value) { let num = parseFloat(value) || 0; return Number.isInteger(num) ? num.toString() : num.toFixed(2).replace(/\.?0+$/, ''); }
function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]); }
function removeEmbroideryRow(btn) { const tbody = document.getElementById('embBillingTableBody'); if (tbody.children.length > 1) { btn.closest('tr').remove(); calculateEmbGrandTotal(); } else { alert('At least one row must remain'); } }
function calculateEmbRow(el) { const row = $(el).closest('tr'); const stitches = parseFloat(row.find('.stitch-round').val()) || 0; const rate = parseFloat(row.find('.rate').val()) || 0; const rounds = parseFloat(row.find('.round').val()) || 1; const head = parseFloat(row.find('.head').val()) || 1; const subTotal = (stitches / 1000) * rate * head * rounds; row.find('.sub-total').val(subTotal.toFixed(2)); calculateEmbGrandTotal(); }
function calculateEmbGrandTotal() { let total = 0; $('.sub-total').each(function() { total += parseFloat($(this).val()) || 0; }); const adj = parseFloat($('#embAdjustment').val()) || 0; const grand = Math.round(total + adj); $('#embGrandTotal').text('Rs. ' + grand); }

$(document).ready(function() { $('#embAddRowBtn').off('click').on('click', addEmbroideryRow); $('#embClearRowsBtn').off('click').on('click', function() { if (confirm('Clear all rows?')) { $('#embBillingTableBody').empty(); addEmbroideryRow(); calculateEmbGrandTotal(); } }); });

function adjustStickyTabs() { var costingWrapper = document.getElementById('costingWrapper'); if (costingWrapper) { var tabs = document.getElementById('stickyTabs'); if (tabs) tabs.style.top = (costingWrapper.offsetHeight + 10) + 'px'; } }
window.addEventListener('load', adjustStickyTabs);
window.addEventListener('resize', adjustStickyTabs);
window.addEventListener('scroll', adjustStickyTabs);

document.addEventListener('DOMContentLoaded',function(){
    const urlParams=new URLSearchParams(window.location.search);
    let activeTab=urlParams.get('tab');
    if(activeTab && document.getElementById(activeTab)) openTab(activeTab);
    else { let firstTab=document.querySelector('.nav-link'); if(firstTab) firstTab.click(); }
    if(document.getElementById('fabric_issue') && lotsData.length>0 && document.getElementById('fiTableBody').children.length===0){ <?php if(!empty($left_job_no)):?> for(let i=0;i<4;i++) fiAddNewRow(); <?php endif; ?> }
    if(document.getElementById('stitching_depart') && document.getElementById('stitchingDeptTableBody').children.length===0) addStitchingRow();
    if(document.getElementById('embroidery_billing') && <?= empty($embroidery_entries) ? 'true' : 'false' ?> && '<?= addslashes($left_job_no) ?>' !== '') { loadEmbroideryJob(); }
    if(document.getElementById('material') && !rowCounters['material']){ rowCounters['material']=0; for(let i=0;i<2;i++) addRow('material'); }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>