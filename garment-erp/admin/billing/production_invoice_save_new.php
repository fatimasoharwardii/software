<?php
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

// ---------- ACCESS CONTROL ----------
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}


$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];   // required by foreign key

header('Content-Type: application/json');

// ---------- VALIDATION ----------
if (!isset($_POST['save_bill'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request – no save_bill flag']);
    exit;
}

$invoice_no   = trim($_POST['invoice_no'] ?? '');
$billing_date = trim($_POST['billing_date'] ?? '');
$customer_name = trim($_POST['customer_name'] ?? '');
$jobs_raw     = $_POST['jobs'] ?? [];

// Check each field individually to return a helpful error
if ($invoice_no === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required field: invoice_no']);
    exit;
}
if ($customer_name === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required field: customer_name']);
    exit;
}
if (empty($jobs_raw)) {
    echo json_encode(['success' => false, 'error' => 'Missing required field: jobs (empty array)']);
    exit;
}

// ---------- SANITISE ----------
$invoice_no   = $conn->real_escape_string($invoice_no);
$billing_date = $conn->real_escape_string($billing_date);
$customer_name = $conn->real_escape_string($customer_name);

$conn->begin_transaction();

try {
    $bill_count = 0;

    foreach ($jobs_raw as $job) {
        // Ensure expected keys exist
        if (!isset($job['job_no'], $job['quantity'], $job['rate'])) {
            continue;   // skip malformed entries
        }

        $job_no = $conn->real_escape_string($job['job_no']);
        $qty    = floatval($job['quantity']);
        $rate   = floatval($job['rate']);
        $amount = $qty * $rate;
        $tab_type = 'production_bill';   // fixed for this billing

        // ----- 1. Insert into stitching_bill_items (WITH bill_no) -----
        $sql_item = "INSERT INTO stitching_bill_items 
                     (job_no, name, qty, rate, amount, tab_type, company_id, user_id, bill_no)
                     VALUES 
                     ('$job_no', '$customer_name', $qty, $rate, $amount, '$tab_type', $company_id, $user_id, '$invoice_no')";

        if (!$conn->query($sql_item)) {
            throw new Exception("Failed to insert stitching_bill_items for job $job_no: " . $conn->error);
        }

        // ----- 2. Update / Insert into stitching_posted_bills (with user_id) -----
        // Get some job details for extra columns
        $job_info = $conn->query("SELECT serial_no, design_name, fabric_name, size, brand_name 
                                 FROM jobs 
                                 WHERE job_no = '$job_no' AND company_id = $company_id LIMIT 1");
        $details = ($job_info && $job_info->num_rows > 0) ? $job_info->fetch_assoc() : [];
        $serial_no   = $conn->real_escape_string($details['serial_no'] ?? 'SERIAL-' . date('Ymd'));
        $design_name = $conn->real_escape_string($details['design_name'] ?? '');
        $fabric_name = $conn->real_escape_string($details['fabric_name'] ?? '');
        $size        = $conn->real_escape_string($details['size'] ?? '');
        $brand_name  = $conn->real_escape_string($details['brand_name'] ?? '');
        $desc        = "Production invoice $invoice_no for job $job_no";

        // Check if posted bill already exists for this vendor + job
        $check = $conn->query("SELECT id FROM stitching_posted_bills 
                              WHERE job_no = '$job_no' AND emp_name = '$customer_name' AND company_id = $company_id");
        $exists = ($check && $check->num_rows > 0);

        if ($exists) {
            // Update existing record
            $sql_posted = "UPDATE stitching_posted_bills SET
                total_amount = $amount,
                qty = $qty,
                rate = $rate,
                description = '$desc',
                design_name = '$design_name',
                fabric_name = '$fabric_name',
                size = '$size',
                brand_name = '$brand_name',
                status = 'pending',
                manual_total = $amount,
                difference_total = $amount,
                post_date = CURDATE(),
                claim_type = 'Production Bill',
                user_id = $user_id
                WHERE job_no = '$job_no' AND emp_name = '$customer_name' AND company_id = $company_id";
        } else {
            // Insert new record
            $sql_posted = "INSERT INTO stitching_posted_bills
                (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description,
                 claim_type, claim_date, design_name, fabric_name, size, brand_name, status,
                 manual_total, auto_total, difference_total, post_date, company_id, user_id)
                VALUES
                ('$job_no', '$serial_no', '$customer_name', 'Stitching Charges', $qty, $rate, $amount, '$desc',
                 'Production Bill', CURDATE(), '$design_name', '$fabric_name', '$size', '$brand_name', 'pending',
                 $amount, 0, $amount, CURDATE(), $company_id, $user_id)";
        }

        if (!$conn->query($sql_posted)) {
            throw new Exception("Failed to update/insert stitching_posted_bills for job $job_no: " . $conn->error);
        }

        // ----- 3. Update job status -----
        $conn->query("UPDATE jobs SET status = 'closed' WHERE job_no = '$job_no' AND company_id = $company_id");

        $bill_count++;
    }

    $conn->commit();
    echo json_encode(['success' => true, 'count' => $bill_count]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}