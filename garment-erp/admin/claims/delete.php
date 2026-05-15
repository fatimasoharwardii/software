<?php
$page_identifier = 'claims/delete.php';
require_once "../../config/db.php";

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    // Get claim details first
    $claim_query = mysqli_query($conn, "SELECT job_no, amount FROM claims WHERE id = $id");
    
    if ($claim_query && mysqli_num_rows($claim_query) > 0) {
        $claim = mysqli_fetch_assoc($claim_query);
        $job_no = $claim['job_no'];
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Delete claim
        $delete = mysqli_query($conn, "DELETE FROM claims WHERE id = $id");
        
        if ($delete) {
            // Update posted bills total
            $total_q = $conn->query("SELECT SUM(amount) as total FROM claims WHERE job_no='$job_no'");
            $total_r = $total_q->fetch_assoc();
            $total_amount = $total_r['total'] ?? 0;
            
            if ($total_amount > 0) {
                $update = $conn->query("UPDATE stitching_posted_bills SET 
                                        total_amount = $total_amount, 
                                        post_date = CURDATE() 
                                        WHERE job_no='$job_no'");
                if (!$update) {
                    mysqli_rollback($conn);
                    $msg = "Error updating posted bill: " . mysqli_error($conn);
                } else {
                    mysqli_commit($conn);
                    $msg = "Claim deleted successfully!";
                }
            } else {
                $delete_posted = $conn->query("DELETE FROM stitching_posted_bills WHERE job_no='$job_no'");
                if (!$delete_posted) {
                    mysqli_rollback($conn);
                    $msg = "Error deleting posted bill: " . mysqli_error($conn);
                } else {
                    mysqli_commit($conn);
                    $msg = "Claim deleted successfully!";
                }
            }
        } else {
            mysqli_rollback($conn);
            $msg = "Error deleting claim: " . mysqli_error($conn);
        }
    } else {
        $msg = "Claim not found.";
    }
} else {
    $msg = "Invalid ID.";
}

echo "<script>
        alert('$msg');
        window.location.href = 'claims_list.php';
      </script>";
exit;
?>