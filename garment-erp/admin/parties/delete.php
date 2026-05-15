<?php
$page_identifier = 'parties/delete.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_msg'] = "Invalid party ID.";
    header("Location: list.php");
    exit;
}

// Check if party exists and belongs to this company
$check = $conn->prepare("SELECT * FROM parties WHERE id = ? AND company_id = ?");
$check->bind_param("ii", $id, $company_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_msg'] = "Party not found or does not belong to your company.";
    header("Location: list.php");
    exit;
}

$party = $result->fetch_assoc();
$check->close();

$conn->begin_transaction();
try {
    // 1. Delete the party
    $delParty = $conn->prepare("DELETE FROM parties WHERE id = ? AND company_id = ?");
    $delParty->bind_param("ii", $id, $company_id);
    if (!$delParty->execute()) {
        throw new Exception("Failed to delete party: " . $delParty->error);
    }
    $delParty->close();

    // 2. Delete the corresponding account (match by party name and type, company_id)
    $safeName = $conn->real_escape_string($party['party_name']);
    $safeType = $conn->real_escape_string($party['party_type']);
    $delAccount = $conn->query("DELETE FROM accounts WHERE account_name = '$safeName' AND account_type = '$safeType' AND company_id = $company_id");
    if (!$delAccount) {
        throw new Exception("Failed to delete account: " . $conn->error);
    }

    $conn->commit();
    $_SESSION['success_msg'] = "Party and its account deleted successfully.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_msg'] = "Error: " . $e->getMessage();
}

header("Location: list.php");
exit;
?>