<?php
$page_identifier = 'embroidery/delete_embroidery_entry.php';
require_once "../../config/db.php";

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    $delete = mysqli_query($conn, "DELETE FROM embroidery_entries WHERE id = $id");
    
    if ($delete) {
        $msg = "Entry deleted successfully!";
    } else {
        $msg = "Error deleting entry: " . mysqli_error($conn);
    }
} else {
    $msg = "Invalid ID.";
}

echo "<script>
        alert('$msg');
        window.location.href = 'list.php';
      </script>";
exit;
?>