<?php
$page_identifier = 'billing/get_job_details.php';
include("../../config/db.php");

header('Content-Type: application/json');

$job_no = isset($_GET['job_no']) ? mysqli_real_escape_string($conn, $_GET['job_no']) : '';

if(empty($job_no)) {
    echo json_encode(['error' => 'Job number is required']);
    exit;
}

// Get job details - Allow both 'Ready' and 'Close' status
$job_query = $conn->query("SELECT * FROM jobs WHERE job_no='$job_no'");
$job = $job_query->fetch_assoc();

if(!$job) {
    echo json_encode(['error' => 'Job not found']);
    exit;
}

// Check if job status is Ready or Close
if($job['status'] != 'Ready' && $job['status'] != 'Close') {
    echo json_encode(['error' => 'Job is not in READY or CLOSE status. Current status: ' . $job['status'] . '. Only Ready or Completed jobs can be billed.']);
    exit;
}

// Get production billed quantity
$production_query = $conn->query("SELECT SUM(qty) as total FROM stitching_bill_items WHERE job_no='$job_no' AND tab_type='production_billing'");
$production = $production_query->fetch_assoc();
$production_billed = floatval($production['total'] ?? 0);

// Get claimed quantity
$claim_query = $conn->query("SELECT SUM(qty) as total FROM stitching_bill_items WHERE job_no='$job_no' AND tab_type='claim_billing'");
$claim = $claim_query->fetch_assoc();
$claimed_qty = floatval($claim['total'] ?? 0);

// Calculate totals
$total_used = $production_billed + $claimed_qty;
$total_quantity = floatval($job['quantity'] ?? 0);
$remaining = $total_quantity - $total_used;

$response = [
    'job_no' => $job['job_no'],
    'status' => $job['status'],
    'design_name' => $job['design_name'] ?? 'N/A',
    'size' => $job['size'] ?? 'N/A',
    'fabric_name' => $job['fabric_name'] ?? $job['fabric_type'] ?? 'N/A',
    'total_quantity' => $total_quantity,
    'production_billed' => $production_billed,
    'claimed_qty' => $claimed_qty,
    'total_used' => $total_used,
    'remaining' => $remaining > 0 ? $remaining : 0
];

echo json_encode($response);
?>