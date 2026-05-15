<?php
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

// ---------- ACCESS CONTROL ----------
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "You must be logged in.";
    header("Location: ../../login.php");
    exit;
}


$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_msg'] = "Invalid bill ID.";
    header("Location: production_bill_list.php");
    exit;
}

// Fetch the entry to be deleted
$stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE id = ? AND company_id = ? AND tab_type = 'production_bill'");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$entry) {
    $_SESSION['error_msg'] = "Production bill not found or access denied.";
    header("Location: production_bill_list.php");
    exit;
}

$job_no       = $entry['job_no'];
$vendor_name  = $entry['name'];
$deleted_qty  = $entry['qty'];
$deleted_amount = $entry['amount'];

// Start transaction
$conn->begin_transaction();

try {
    // 1. Delete the item
    $del_stmt = $conn->prepare("DELETE FROM stitching_bill_items WHERE id = ? AND company_id = ?");
    $del_stmt->bind_param("ii", $id, $company_id);
    if (!$del_stmt->execute()) {
        throw new Exception("Failed to delete item: " . $del_stmt->error);
    }
    $del_stmt->close();

    // 2. Handle stitching_posted_bills for the same vendor + job
    // Get total qty and amount for remaining items of this vendor & job
    $total_stmt = $conn->prepare("SELECT SUM(qty) as total_qty, SUM(amount) as total_amount 
                                  FROM stitching_bill_items 
                                  WHERE job_no = ? AND name = ? AND company_id = ? AND tab_type = 'production_bill'");
    $total_stmt->bind_param("ssi", $job_no, $vendor_name, $company_id);
    $total_stmt->execute();
    $totals = $total_stmt->get_result()->fetch_assoc();
    $total_stmt->close();

    $remaining_qty    = floatval($totals['total_qty'] ?? 0);
    $remaining_amount = floatval($totals['total_amount'] ?? 0);

    // Check if posted bill exists
    $check_posted = $conn->prepare("SELECT id FROM stitching_posted_bills WHERE job_no = ? AND emp_name = ? AND company_id = ?");
    $check_posted->bind_param("ssi", $job_no, $vendor_name, $company_id);
    $check_posted->execute();
    $posted_result = $check_posted->get_result();
    $posted_exists = ($posted_result->num_rows > 0);
    $check_posted->close();

    if ($posted_exists) {
        if ($remaining_qty > 0) {
            // Update the posted bill with new totals
            $avg_rate = ($remaining_qty > 0) ? ($remaining_amount / $remaining_qty) : 0;
            $update_posted = $conn->prepare("UPDATE stitching_posted_bills SET 
                qty = ?, rate = ?, total_amount = ?, manual_total = ?, difference_total = ?, post_date = CURDATE(), user_id = ?
                WHERE job_no = ? AND emp_name = ? AND company_id = ?");
            $update_posted->bind_param("dddddissi", 
                $remaining_qty, $avg_rate, $remaining_amount, $remaining_amount, $remaining_amount, $user_id,
                $job_no, $vendor_name, $company_id);
            if (!$update_posted->execute()) {
                throw new Exception("Failed to update posted bill: " . $update_posted->error);
            }
            $update_posted->close();
        } else {
            // No items left – delete the posted bill record
            $delete_posted = $conn->prepare("DELETE FROM stitching_posted_bills WHERE job_no = ? AND emp_name = ? AND company_id = ?");
            $delete_posted->bind_param("ssi", $job_no, $vendor_name, $company_id);
            if (!$delete_posted->execute()) {
                throw new Exception("Failed to delete posted bill: " . $delete_posted->error);
            }
            $delete_posted->close();
        }
    }

    $conn->commit();
    $_SESSION['success_msg'] = "Production bill deleted successfully.";

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_msg'] = "Error: " . $e->getMessage();
}

header("Location: production_bill_list.php");
exit;