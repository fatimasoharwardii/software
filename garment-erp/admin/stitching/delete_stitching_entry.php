<?php
$page_identifier = 'stitching/delete_stitching_entry.php';
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

// Get parameters
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'list';
$left_job_no = isset($_GET['left_job_no']) ? trim($_GET['left_job_no']) : '';
$right_job_no = isset($_GET['right_job_no']) ? trim($_GET['right_job_no']) : '';

// Validate ID
if ($id <= 0) {
    $_SESSION['error_msg'] = "Invalid entry ID.";
    header("Location: list.php");
    exit;
}

// Fetch the entry details (with company check)
$fetch_stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE id = ? AND company_id = ?");
$fetch_stmt->bind_param("ii", $id, $company_id);
$fetch_stmt->execute();
$entry = $fetch_stmt->get_result()->fetch_assoc();
$fetch_stmt->close();

if (!$entry) {
    $_SESSION['error_msg'] = "Entry not found or does not belong to your company.";
    header("Location: list.php");
    exit;
}

$job_no = $entry['job_no'];

// Verify that the job belongs to the same company
$job_check = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
$job_check->bind_param("si", $job_no, $company_id);
$job_check->execute();
if ($job_check->get_result()->num_rows == 0) {
    $_SESSION['error_msg'] = "Job does not belong to your company.";
    header("Location: list.php");
    exit;
}
$job_check->close();

// Begin transaction
$conn->begin_transaction();
try {
    // Delete the entry
    $del_stmt = $conn->prepare("DELETE FROM stitching_bill_items WHERE id = ? AND company_id = ?");
    $del_stmt->bind_param("ii", $id, $company_id);
    if (!$del_stmt->execute()) {
        throw new Exception("Failed to delete entry: " . $del_stmt->error);
    }
    $del_stmt->close();

    // Recalculate total for the job (excluding stitching_depart type)
    $total_stmt = $conn->prepare("SELECT SUM(amount) as total FROM stitching_bill_items 
                                  WHERE job_no = ? AND tab_type != 'stitching_depart' AND company_id = ?");
    $total_stmt->bind_param("si", $job_no, $company_id);
    $total_stmt->execute();
    $total_r = $total_stmt->get_result()->fetch_assoc();
    $total_stmt->close();
    $total_amount = $total_r['total'] ?? 0;

    // Check if posted bill exists
    $check_bill = $conn->prepare("SELECT id FROM stitching_posted_bills WHERE job_no = ? AND company_id = ?");
    $check_bill->bind_param("si", $job_no, $company_id);
    $check_bill->execute();
    $existing = $check_bill->get_result()->fetch_assoc();
    $check_bill->close();

    if ($total_amount == 0) {
        // No more items – delete the posted bill if it exists
        if ($existing) {
            $del_bill = $conn->prepare("DELETE FROM stitching_posted_bills WHERE id = ? AND company_id = ?");
            $del_bill->bind_param("ii", $existing['id'], $company_id);
            $del_bill->execute();
            $del_bill->close();
        }
    } else {
        // Update or insert posted bill with new total
        if ($existing) {
            $upd_bill = $conn->prepare("UPDATE stitching_posted_bills 
                                        SET total_amount = ?, post_date = CURDATE(), status = 'pending'
                                        WHERE id = ? AND company_id = ?");
            $upd_bill->bind_param("dii", $total_amount, $existing['id'], $company_id);
            $upd_bill->execute();
            $upd_bill->close();
        } else {
            $ins_bill = $conn->prepare("INSERT INTO stitching_posted_bills (job_no, total_amount, post_date, status, company_id) 
                                        VALUES (?, ?, CURDATE(), 'pending', ?)");
            $ins_bill->bind_param("sdi", $job_no, $total_amount, $company_id);
            $ins_bill->execute();
            $ins_bill->close();
        }
    }

    $conn->commit();
    $_SESSION['success_msg'] = "Entry deleted successfully!";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_msg'] = "Failed to delete entry: " . $e->getMessage();
}

// Redirect based on parameter
if ($redirect == 'list') {
    header("Location: list.php");
} else {
    header("Location: list.php?left_job_no=" . urlencode($left_job_no) . "&right_job_no=" . urlencode($right_job_no));
}
exit();