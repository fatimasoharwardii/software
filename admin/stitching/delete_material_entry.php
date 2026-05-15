<?php
$page_identifier = 'stitching/delete_material_entry.php';
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

// Get entry ID
$entry_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$entry_id) {
    $_SESSION['error_msg'] = "Invalid entry ID.";
    header("Location: entry.php");
    exit;
}

// Fetch the material entry with company check
$fetch_stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE id = ? AND tab_type = 'material' AND company_id = ?");
$fetch_stmt->bind_param("ii", $entry_id, $company_id);
$fetch_stmt->execute();
$entry = $fetch_stmt->get_result()->fetch_assoc();

if (!$entry) {
    $_SESSION['error_msg'] = "Material entry not found or does not belong to your company.";
    header("Location: entry.php");
    exit;
}
$fetch_stmt->close();

$job_no = $entry['job_no'];
$vendor = $entry['name'];
$deleted_amount = $entry['amount'];

// Verify that the job_no belongs to the same company (optional but recommended)
$job_check = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
$job_check->bind_param("si", $job_no, $company_id);
$job_check->execute();
if ($job_check->get_result()->num_rows == 0) {
    $_SESSION['error_msg'] = "Job does not belong to your company.";
    header("Location: entry.php");
    exit;
}
$job_check->close();

// Begin transaction
$conn->begin_transaction();
try {
    // 1. Delete the material item
    $del_stmt = $conn->prepare("DELETE FROM stitching_bill_items WHERE id = ? AND tab_type = 'material' AND company_id = ?");
    $del_stmt->bind_param("ii", $entry_id, $company_id);
    if (!$del_stmt->execute()) {
        throw new Exception("Failed to delete item: " . $del_stmt->error);
    }
    $del_stmt->close();

    // 2. Recalculate total amount & qty for this vendor under the same job_no and tab_type='material'
    $total_stmt = $conn->prepare("SELECT SUM(amount) AS total, SUM(qty) AS total_qty 
                                  FROM stitching_bill_items 
                                  WHERE job_no = ? AND name = ? AND tab_type = 'material' AND company_id = ?");
    $total_stmt->bind_param("ssi", $job_no, $vendor, $company_id);
    $total_stmt->execute();
    $total_r = $total_stmt->get_result()->fetch_assoc();
    $total_stmt->close();

    $new_total = $total_r['total'] ?? 0;
    $new_qty = $total_r['total_qty'] ?? 0;
    $avg_rate = ($new_qty > 0) ? ($new_total / $new_qty) : 0;

    $claim_type = 'Material Bill';

    // 3. Update or delete the corresponding posted bill (company filtered)
    $check_bill = $conn->prepare("SELECT id FROM stitching_posted_bills 
                                  WHERE job_no = ? AND emp_name = ? AND claim_type = ? AND company_id = ?");
    $check_bill->bind_param("sssi", $job_no, $vendor, $claim_type, $company_id);
    $check_bill->execute();
    $bill_result = $check_bill->get_result();
    $check_bill->close();

    if ($bill_result && $bill_result->num_rows > 0) {
        $bill_row = $bill_result->fetch_assoc();
        $bill_id = $bill_row['id'];

        if ($new_total > 0) {
            // Update existing bill with new totals
            $update_bill = $conn->prepare("UPDATE stitching_posted_bills SET 
                                total_amount = ?,
                                qty = ?,
                                rate = ?,
                                status = 'pending',
                                post_date = CURDATE()
                            WHERE id = ? AND company_id = ?");
            $update_bill->bind_param("ddiii", $new_total, $new_qty, $avg_rate, $bill_id, $company_id);
            if (!$update_bill->execute()) {
                throw new Exception("Failed to update posted bill: " . $update_bill->error);
            }
            $update_bill->close();
        } else {
            // No material items left -> delete the posted bill
            $delete_bill = $conn->prepare("DELETE FROM stitching_posted_bills WHERE id = ? AND company_id = ?");
            $delete_bill->bind_param("ii", $bill_id, $company_id);
            if (!$delete_bill->execute()) {
                throw new Exception("Failed to delete posted bill: " . $delete_bill->error);
            }
            $delete_bill->close();
        }
    } // if no posted bill existed, nothing to update

    $conn->commit();
    $_SESSION['success_msg'] = "Material entry deleted successfully.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_msg'] = "Error deleting entry: " . $e->getMessage();
}

// Redirect back to the material tab of the job (using the job_no from the entry)
header("Location: entry.php?left_job_no=" . urlencode($job_no) . "&tab=material");
exit();
?>