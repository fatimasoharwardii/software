<?php
$page_identifier = 'ledger/delete_transaction.php';
session_start();
require_once "../../config/db.php";       // $conn already defined

// Check login
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please login first.";
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    $_SESSION['error_msg'] = "Invalid transaction ID.";
    header("Location: transaction_history.php");
    exit;
}

// Fetch transaction details (only if it belongs to this company)
$query = "SELECT lt.*, 
                 a1.account_name AS from_name, 
                 a2.account_name AS to_name
          FROM ledger_transactions lt
          JOIN accounts a1 ON lt.from_account = a1.id AND a1.company_id = $company_id
          JOIN accounts a2 ON lt.to_account   = a2.id AND a2.company_id = $company_id
          WHERE lt.id = $id 
            AND lt.company_id = $company_id";

$result = $conn->query($query);
if (!$result || $result->num_rows === 0) {
    $_SESSION['error_msg'] = "Transaction not found or you do not have access.";
    header("Location: transaction_history.php");
    exit;
}

$transaction = $result->fetch_assoc();

// Start transaction to keep data consistent
$conn->begin_transaction();

try {
    $from_account_id = $transaction['from_account'];
    $to_account_id   = $transaction['to_account'];
    $amount          = $transaction['amount'];

    // 1. Reverse the account balances
    $revert_from = "UPDATE accounts 
                    SET balance = balance + $amount 
                    WHERE id = $from_account_id 
                      AND company_id = $company_id";
    if (!$conn->query($revert_from)) {
        throw new Exception("Failed to revert from account balance: " . $conn->error);
    }

    $revert_to = "UPDATE accounts 
                  SET balance = balance - $amount 
                  WHERE id = $to_account_id 
                    AND company_id = $company_id";
    if (!$conn->query($revert_to)) {
        throw new Exception("Failed to revert to account balance: " . $conn->error);
    }

    // 2. (Optional) Delete any child records that reference this transaction
    //    agar stitching_posted_bills mein transaction_id column hai to use hatao
    $col_check = $conn->query("SHOW COLUMNS FROM stitching_posted_bills LIKE 'transaction_id'");
    if ($col_check->num_rows > 0) {
        $conn->query("DELETE FROM stitching_posted_bills 
                      WHERE transaction_id = $id 
                        AND company_id = $company_id");
    }

    // 3. Delete the ledger transaction itself
    $delete = "DELETE FROM ledger_transactions 
               WHERE id = $id 
                 AND company_id = $company_id";
    if (!$conn->query($delete)) {
        // Agar foreign key constraint fail kare (errno 1451) to specific message do
        if ($conn->errno == 1451) {
            throw new Exception(
                "Cannot delete this transaction because other records still depend on it. 
                 Please remove those references first."
            );
        }
        throw new Exception("Failed to delete transaction: " . $conn->error);
    }

    // Sab kuch sahi to commit karo
    $conn->commit();
    $_SESSION['success_msg'] = "Transaction deleted successfully. Account balances have been reverted.";

} catch (Exception $e) {
    // Koi bhi error aaye to rollback karo aur error message set karo
    $conn->rollback();
    $_SESSION['error_msg'] = "Error deleting transaction: " . $e->getMessage();
}

// Wapas transaction list par le jao
header("Location: transaction_history.php");
exit;