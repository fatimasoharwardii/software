<?php
$page_identifier = 'jobs/get_design_info.php';
require_once "../../config/db.php";

header('Content-Type: application/json');

if(isset($_GET['design']) && !empty($_GET['design'])) {
    $design_name = mysqli_real_escape_string($conn, $_GET['design']);
    
    // Fetch the latest job with this design name
    $query = "SELECT image FROM jobs WHERE design_name = '$design_name' AND image IS NOT NULL AND image != '' ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        echo json_encode([
            'success' => true,
            'image' => $row['image']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'image' => ''
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'image' => ''
    ]);
}
?>