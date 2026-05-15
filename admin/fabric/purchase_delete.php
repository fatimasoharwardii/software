<?php
$page_identifier = 'fabric/purchase_delete.php';
require_once "../../config/db.php";

if(isset($_GET['id'])){
    $id = $_GET['id'];
    mysqli_query($conn, "DELETE FROM fabric_purchase WHERE id='$id'");
}
header('Location: purchase_list.php');
?>