<?php
$page_identifier = 'stitching/delete_issue.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure fabric_issue table has company_id column
$check = $conn->query("SHOW COLUMNS FROM fabric_issue LIKE 'company_id'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE fabric_issue ADD COLUMN company_id INT DEFAULT NULL");
    $conn->query("UPDATE fabric_issue SET company_id = 1 WHERE company_id IS NULL");
    $conn->query("ALTER TABLE fabric_issue MODIFY company_id INT NOT NULL");
}

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "No issue ID provided.";
    header("Location: issue_list.php");
    exit();
}

$issue_id = intval($_GET['id']);

// First, fetch issue details to verify ownership and get info for message
$fetch_stmt = $conn->prepare("SELECT job_no, lot_no FROM fabric_issue WHERE id = ? AND company_id = ?");
$fetch_stmt->bind_param("ii", $issue_id, $company_id);
$fetch_stmt->execute();
$fetch_result = $fetch_stmt->get_result();

if ($fetch_result->num_rows == 0) {
    $_SESSION['error'] = "Fabric issue not found or does not belong to your company.";
    header("Location: issue_list.php");
    exit();
}

$issue_data = $fetch_result->fetch_assoc();

// Delete the issue
$delete_stmt = $conn->prepare("DELETE FROM fabric_issue WHERE id = ? AND company_id = ?");
$delete_stmt->bind_param("ii", $issue_id, $company_id);

if ($delete_stmt->execute()) {
    $_SESSION['success'] = "Fabric issue #" . htmlspecialchars($issue_data['job_no']) . " (Lot: " . htmlspecialchars($issue_data['lot_no']) . ") has been deleted successfully.";
} else {
    $_SESSION['error'] = "Failed to delete fabric issue. Error: " . $delete_stmt->error;
}

$delete_stmt->close();
$fetch_stmt->close();

header("Location: issue_list.php");
exit();
?>