<?php
$page_identifier = 'material/raw_material_delete.php';

require_once "../../config/db.php";
require_once "../../includes/functions.php";
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id']; // not directly used, but kept for consistency

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: raw_material_list.php");
    exit;
}

// Fetch entry details – also get bill_no for stitching bill adjustment
$stmt = $conn->prepare("SELECT r.vendor_id, r.amount, r.qty, r.bill_no, p.party_name AS vendor_name, r.entry_date 
                        FROM raw_material_entries r 
                        JOIN parties p ON r.vendor_id = p.id 
                        WHERE r.id = ? AND r.company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();

if (!$entry) {
    $_SESSION['error_msg'] = "Entry not found.";
    header("Location: raw_material_list.php");
    exit;
}

$conn->begin_transaction();
try {
    // 1. Delete from raw_material_entries
    $del_stmt = $conn->prepare("DELETE FROM raw_material_entries WHERE id = ? AND company_id = ?");
    $del_stmt->bind_param("ii", $id, $company_id);
    $del_stmt->execute();

    // 2. Reverse vendor balance in parties
    $rev_party = $conn->prepare("UPDATE parties SET balance = balance - ? WHERE id = ? AND company_id = ?");
    $rev_party->bind_param("dii", $entry['amount'], $entry['vendor_id'], $company_id);
    $rev_party->execute();

    // 3. Reverse vendor balance in accounts
    $rev_acc = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE account_name = ? AND company_id = ?");
    $rev_acc->bind_param("dsi", $entry['amount'], $entry['vendor_name'], $company_id);
    $rev_acc->execute();

    // 4. Delete related ledger transaction
    $desc_like = "Raw material purchase – total Rs. " . number_format($entry['amount'], 2) . "%";
    $del_ledger = $conn->prepare("DELETE FROM ledger_transactions WHERE description LIKE ? AND company_id = ? AND amount = ?");
    $del_ledger->bind_param("sid", $desc_like, $company_id, $entry['amount']);
    $del_ledger->execute();

    // 5. Adjust stitching_posted_bills (subtract this entry's contribution)
    if (!empty($entry['bill_no'])) {
        // Find the matching stitching bill by vendor and bill_no
        $find_stitch = $conn->prepare("SELECT id, qty, total_amount FROM stitching_posted_bills 
                                       WHERE emp_name = ? AND bill_no = ? AND company_id = ? LIMIT 1");
        $find_stitch->bind_param("ssi", $entry['vendor_name'], $entry['bill_no'], $company_id);
        $find_stitch->execute();
        $stitch = $find_stitch->get_result()->fetch_assoc();

        if ($stitch) {
            $new_qty = max(0, $stitch['qty'] - $entry['qty']);
            $new_amount = max(0, $stitch['total_amount'] - $entry['amount']);

            if ($new_qty <= 0) {
                // Delete the stitching bill row entirely
                $del_stitch = $conn->prepare("DELETE FROM stitching_posted_bills WHERE id = ? AND company_id = ?");
                $del_stitch->bind_param("ii", $stitch['id'], $company_id);
                $del_stitch->execute();
            } else {
                // Update with reduced values
                $upd_stitch = $conn->prepare("UPDATE stitching_posted_bills SET 
                                              qty = ?, total_amount = ?, updated_at = UNIX_TIMESTAMP() 
                                              WHERE id = ? AND company_id = ?");
                $upd_stitch->bind_param("ddii", $new_qty, $new_amount, $stitch['id'], $company_id);
                $upd_stitch->execute();
            }
        }
    }

    $conn->commit();
    $_SESSION['success_msg'] = "Entry deleted successfully.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_msg'] = "Error deleting: " . $e->getMessage();
}

header("Location: raw_material_list.php");
exit;
?>