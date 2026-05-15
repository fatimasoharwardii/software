<?php

session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Content-Type: application/json");
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['jobs', 'stitching_bill_items', 'embroidery_entries'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$job_no = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';

if (empty($job_no)) {
    header("Content-Type: application/json");
    echo json_encode(['error' => 'Job number is required']);
    exit;
}

// Get job details (with company filter)
$job_stmt = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ?");
$job_stmt->bind_param("si", $job_no, $company_id);
$job_stmt->execute();
$job = $job_stmt->get_result()->fetch_assoc();

if (!$job) {
    header("Content-Type: application/json");
    echo json_encode(['error' => 'Job not found']);
    exit;
}

// Get already billed quantity (embroidery_billing) with company filter
$billed_stmt = $conn->prepare("SELECT SUM(qty) as total_billed FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'embroidery_billing' AND company_id = ?");
$billed_stmt->bind_param("si", $job_no, $company_id);
$billed_stmt->execute();
$billed_data = $billed_stmt->get_result()->fetch_assoc();
$already_billed = floatval($billed_data['total_billed'] ?? 0);
$remaining_qty = floatval($job['quantity']) - $already_billed;

// Get embroidery entries for this job (with company filter)
$entries_stmt = $conn->prepare("SELECT * FROM embroidery_entries WHERE job_no = ? AND company_id = ? ORDER BY id");
$entries_stmt->bind_param("si", $job_no, $company_id);
$entries_stmt->execute();
$entries_result = $entries_stmt->get_result();
$entries = [];
while ($row = $entries_result->fetch_assoc()) {
    $entries[] = $row;
}

header("Content-Type: application/json");
echo json_encode([
    'job' => $job,
    'entries' => $entries,
    'already_billed' => $already_billed,
    'remaining_qty' => $remaining_qty
]);
?>