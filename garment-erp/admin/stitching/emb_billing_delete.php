<?php
$page_identifier = 'stitching/emb_billing_delete.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['stitching_bill_items', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Accept id (item id) or bill_id
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$job_no_param = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';

// If only item_id is given, fetch job_no from that item (with company check)
$job_no = '';
if ($item_id > 0 && empty($job_no_param)) {
    $stmt = $conn->prepare("SELECT job_no FROM stitching_bill_items WHERE id = ? AND tab_type = 'embroidery_billing' AND company_id = ?");
    $stmt->bind_param("ii", $item_id, $company_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    if ($item) {
        $job_no = $item['job_no'];
    } else {
        // If item not found, try to interpret id as bill_id
        $stmt2 = $conn->prepare("SELECT job_no FROM stitching_posted_bills WHERE id = ? AND claim_type = 'Embroidery Bill' AND company_id = ?");
        $stmt2->bind_param("ii", $item_id, $company_id);
        $stmt2->execute();
        $bill = $stmt2->get_result()->fetch_assoc();
        if ($bill) {
            $job_no = $bill['job_no'];
        }
    }
} else {
    $job_no = $job_no_param;
}

if (empty($job_no)) {
    $_SESSION['error_msg'] = "Invalid request: No job found.";
    header("Location: list.php");
    exit;
}

// Verify job belongs to current company
$job_check = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
$job_check->bind_param("si", $job_no, $company_id);
$job_check->execute();
if ($job_check->get_result()->num_rows == 0) {
    $_SESSION['error_msg'] = "Job does not belong to your company.";
    header("Location: list.php");
    exit;
}
$job_check->close();

// Fetch bill details from posted bills for this job (company filtered)
$bill_stmt = $conn->prepare("SELECT id FROM stitching_posted_bills WHERE job_no = ? AND claim_type = 'Embroidery Bill' AND company_id = ?");
$bill_stmt->bind_param("si", $job_no, $company_id);
$bill_stmt->execute();
$bill = $bill_stmt->get_result()->fetch_assoc();
$bill_id = $bill['id'] ?? 0;
$bill_stmt->close();

// Start transaction
$conn->begin_transaction();
try {
    // Delete all embroidery billing items for this job (company filtered)
    $delete_items = $conn->prepare("DELETE FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'embroidery_billing' AND company_id = ?");
    $delete_items->bind_param("si", $job_no, $company_id);
    if (!$delete_items->execute()) {
        throw new Exception("Error deleting bill items: " . $delete_items->error);
    }
    $delete_items->close();

    // Delete from stitching_posted_bills if exists (company filtered)
    if ($bill_id > 0) {
        $delete_bill = $conn->prepare("DELETE FROM stitching_posted_bills WHERE id = ? AND company_id = ?");
        $delete_bill->bind_param("ii", $bill_id, $company_id);
        if (!$delete_bill->execute()) {
            throw new Exception("Error deleting bill: " . $delete_bill->error);
        }
        $delete_bill->close();
    }

    $conn->commit();
    $_SESSION['success_msg'] = "Bill deleted successfully!";
    header("Location: list.php");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_msg'] = "Error deleting bill: " . $e->getMessage();
    header("Location: list.php");
    exit();
}
?>