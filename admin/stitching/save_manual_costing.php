<?php
$page_identifier = 'stitching/save_manual_costing.php';
require_once "../../config/db.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$conn->begin_transaction();

try {
    foreach ($data as $item) {
        $job_no = mysqli_real_escape_string($conn, $item['job_no']);
        $type = mysqli_real_escape_string($conn, $item['type']);
        $manual_rate = floatval($item['manual_rate']);
        $auto_rate = floatval($item['auto_rate']);
        $difference = $auto_rate - $manual_rate;
        
        // Check if record exists
        $check = $conn->query("SELECT id FROM manual_costing WHERE job_no='$job_no' AND cost_type='$type'");
        
        if ($check->num_rows > 0) {
            // Update existing
            $update = "UPDATE manual_costing SET 
                       manual_rate = $manual_rate,
                       auto_rate = $auto_rate,
                       difference = $difference,
                       is_edited = 1,
                       updated_at = NOW()
                       WHERE job_no='$job_no' AND cost_type='$type'";
            
            if (!$conn->query($update)) {
                throw new Exception("Error updating record: " . $conn->error);
            }
        } else {
            // Insert new
            $insert = "INSERT INTO manual_costing (job_no, cost_type, manual_rate, auto_rate, difference, is_edited) 
                       VALUES ('$job_no', '$type', $manual_rate, $auto_rate, $difference, 1)";
            
            if (!$conn->query($insert)) {
                throw new Exception("Error inserting record: " . $conn->error);
            }
        }
        
        // Update job table for embroidery and fabric
        if ($type == 'embroidery') {
            $conn->query("UPDATE jobs SET manual_embroidery_rate = $manual_rate, use_manual_costing = 1 WHERE job_no='$job_no'");
        } elseif ($type == 'fabric') {
            $conn->query("UPDATE jobs SET manual_fabric_cost = $manual_rate, use_manual_costing = 1 WHERE job_no='$job_no'");
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>