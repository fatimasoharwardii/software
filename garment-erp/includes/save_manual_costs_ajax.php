<?php
session_start();
require_once "../../config/db.php";

if(isset($_POST['save_manual_costs'])){
    $job_no_save = mysqli_real_escape_string($conn, $_POST['job_no']);
    $cost_data = json_decode($_POST['cost_data'], true);
    foreach($cost_data as $cost){
        $type = mysqli_real_escape_string($conn, $cost['type']);
        $manual_rate = floatval($cost['manual_rate']);
        $auto_rate = floatval($cost['auto_rate']);
        $diff = $auto_rate - $manual_rate;
        $check = $conn->query("SELECT id FROM manual_costing WHERE job_no='$job_no_save' AND cost_type='$type'");
        if($check->num_rows > 0){
            $conn->query("UPDATE manual_costing SET manual_rate=$manual_rate, auto_rate=$auto_rate, difference=$diff, is_edited=1 WHERE job_no='$job_no_save' AND cost_type='$type'");
        } else {
            $conn->query("INSERT INTO manual_costing (job_no, cost_type, manual_rate, auto_rate, difference, is_edited) VALUES ('$job_no_save', '$type', $manual_rate, $auto_rate, $diff, 1)");
        }
    }
    echo "success";
    exit;
}
?>