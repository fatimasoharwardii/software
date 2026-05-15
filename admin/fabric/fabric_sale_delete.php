<?php
$page_identifier = 'fabric/fabric_sale_delete.php';
require_once "../../config/db.php";

// Handle Delete
if(isset($_GET['id']) && is_numeric($_GET['id'])) {
    $sale_id = mysqli_real_escape_string($conn, $_GET['id']);
    
    // Get sale details before deleting
    $sale_query = mysqli_query($conn, "SELECT * FROM fabric_sale WHERE id = $sale_id");
    $sale = mysqli_fetch_assoc($sale_query);
    
    if($sale) {
        mysqli_begin_transaction($conn);
        
        try {
            // Reverse sold_meter in fabric_purchase
            $update_purchase = "UPDATE fabric_purchase SET 
                                sold_meter = COALESCE(sold_meter, 0) - " . floatval($sale['quantity']) . ",
                                remaining_meter_sold = total_meter - COALESCE(used_meter, 0) - (COALESCE(sold_meter, 0) - " . floatval($sale['quantity']) . ")
                                WHERE lot_no = '" . mysqli_real_escape_string($conn, $sale['lot_no']) . "'";
            
            if (!mysqli_query($conn, $update_purchase)) {
                throw new Exception("Error updating stock: " . mysqli_error($conn));
            }
            
            // Reverse account balance
            $update_account = "UPDATE accounts SET balance = balance - " . floatval($sale['total_amount']) . " 
                              WHERE account_name = '" . mysqli_real_escape_string($conn, $sale['party_name']) . "'";
            if (!mysqli_query($conn, $update_account)) {
                throw new Exception("Error updating account balance: " . mysqli_error($conn));
            }
            
            // Delete related ledger transaction
            $delete_ledger = "DELETE FROM ledger_transactions 
                             WHERE description LIKE '%" . mysqli_real_escape_string($conn, $sale['lot_no']) . "%' 
                             AND from_account = '" . mysqli_real_escape_string($conn, $sale['party_name']) . "'
                             AND amount = " . floatval($sale['total_amount']);
            mysqli_query($conn, $delete_ledger);
            
            // Delete from stitching_posted_bills
            $delete_bill = "DELETE FROM stitching_posted_bills 
                           WHERE job_no = 'SALE-" . mysqli_real_escape_string($conn, $sale['lot_no']) . "'
                           AND emp_name = '" . mysqli_real_escape_string($conn, $sale['party_name']) . "'
                           AND total_amount = " . floatval($sale['total_amount']);
            mysqli_query($conn, $delete_bill);
            
            // Delete the sale record
            $delete_sale = "DELETE FROM fabric_sale WHERE id = $sale_id";
            if (!mysqli_query($conn, $delete_sale)) {
                throw new Exception("Error deleting sale: " . mysqli_error($conn));
            }
            
            mysqli_commit($conn);
            
            // Redirect with success message
            header("Location: fabric_sale_list.php?delete_success=1");
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
            header("Location: fabric_sale_list.php?delete_error=" . urlencode($error));
            exit;
        }
    } else {
        header("Location: fabric_sale_list.php?delete_error=Record not found");
        exit;
    }
} else {
    header("Location: fabric_sale_list.php");
    exit;
}
?>