<?php
$page_identifier = 'stitching/get_fabric_data.php';
require_once "../../config/db.php";

header('Content-Type: application/json');

if(isset($_GET['lot_no']) && !empty($_GET['lot_no'])) {
    $lot_no = mysqli_real_escape_string($conn, trim($_GET['lot_no']));

    // Get fabric purchase data with remaining meter from fabric_purchase
    // Fallback keeps compatibility if remaining_meter is null.
    $query = $conn->query("
        SELECT fp.id,
               fp.lot_no,
               fp.fabric_name,
               fp.color,
               fp.adjust_rate,
               fp.total_meter,
               COALESCE(fp.used_meter, 0) as used_meter,
               COALESCE(fp.remaining_meter, fp.total_meter - COALESCE(fp.used_meter, 0)) as remaining_meter
        FROM fabric_purchase fp
        WHERE fp.lot_no = '$lot_no'
        LIMIT 1
    ");

    if($query && $query->num_rows > 0) {
        $data = $query->fetch_assoc();

        echo json_encode([
            'success' => true,
            'id' => $data['id'],
            'lot_no' => $data['lot_no'],
            'fabric_name' => $data['fabric_name'],
            'color' => $data['color'],
            'adjust_rate' => $data['adjust_rate'],
            'remaining_meter' => $data['remaining_meter'],
            'total_meter' => $data['total_meter'],
            'used_meter' => $data['used_meter']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Lot number not found'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Lot number is required'
    ]);
}
?>
