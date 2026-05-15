<?php
$page_identifier = 'jobs/edit.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['jobs', 'accounts', 'fabric_issue', 'claims'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Ensure serial_no column exists
$conn->query("ALTER TABLE jobs ADD COLUMN IF NOT EXISTS serial_no VARCHAR(50) DEFAULT NULL AFTER job_no");

// Helper to get image path
function getImagePath($imageName) {
    if (empty($imageName)) return false;
    $path = "../../assets/uploads/" . $imageName;
    return file_exists($path) ? $path : false;
}

// Check if delete action is requested
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Verify job belongs to company
    $check_stmt = $conn->prepare("SELECT id, image FROM jobs WHERE id = ? AND company_id = ?");
    $check_stmt->bind_param("ii", $delete_id, $company_id);
    $check_stmt->execute();
    $job_to_delete = $check_stmt->get_result()->fetch_assoc();
    
    if ($job_to_delete) {
        // Delete image file
        if (!empty($job_to_delete['image']) && file_exists("../../assets/uploads/" . $job_to_delete['image'])) {
            unlink("../../assets/uploads/" . $job_to_delete['image']);
        }
        
        // Delete related records (fabric_issue, claims) with company check
        $del1 = $conn->prepare("DELETE FROM fabric_issue WHERE job_id = ? AND company_id = ?");
        $del1->bind_param("ii", $delete_id, $company_id);
        $del1->execute();
        
        $del2 = $conn->prepare("DELETE FROM claims WHERE job_id = ? AND company_id = ?");
        $del2->bind_param("ii", $delete_id, $company_id);
        $del2->execute();
        
        // Delete job
        $del_stmt = $conn->prepare("DELETE FROM jobs WHERE id = ? AND company_id = ?");
        $del_stmt->bind_param("ii", $delete_id, $company_id);
        if ($del_stmt->execute()) {
            $_SESSION['success'] = "Job and related records deleted successfully!";
        }
    }
    header("Location: list.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header("Location: list.php");
    exit();
}

// Fetch job details with company check
$stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    header("Location: list.php");
    exit();
}

// Fetch design images for auto-load (only current company)
$design_images_stmt = $conn->prepare("SELECT DISTINCT design_name, image FROM jobs WHERE design_name IS NOT NULL AND design_name != '' AND image IS NOT NULL AND image != '' AND company_id = ? ORDER BY design_name");
$design_images_stmt->bind_param("i", $company_id);
$design_images_stmt->execute();
$design_images_res = $design_images_stmt->get_result();
$design_images = [];
while ($d = $design_images_res->fetch_assoc()) {
    $design_images[$d['design_name']] = $d['image'];
}

// Fetch parties for CMT (accounts, only current company)
$parties_stmt = $conn->prepare("SELECT account_name FROM accounts WHERE company_id = ? ORDER BY account_name");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties_result = $parties_stmt->get_result();

// Related records counts (with company check)
$related_data = [];
$total_related = 0;
$tables_check = ['fabric_issue' => 'Fabric Issue', 'claims' => 'Claims'];
foreach ($tables_check as $table => $label) {
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) as total FROM $table WHERE job_id = ? AND company_id = ?");
    $cnt_stmt->bind_param("ii", $id, $company_id);
    $cnt_stmt->execute();
    $cnt = $cnt_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    if ($cnt > 0) {
        $related_data[$table] = ['label' => $label, 'count' => $cnt];
        $total_related += $cnt;
    }
}

// Handle update
if (isset($_POST['update'])) {
    $job_no     = trim($_POST['job_no'] ?? '');
    $serial_no  = trim($_POST['serial_no'] ?? '');
    $design     = trim($_POST['design_name'] ?? '');
    $brand      = trim($_POST['brand_name'] ?? '');
    $size       = trim($_POST['size'] ?? '');
    $qty        = floatval($_POST['quantity'] ?? 0);
    $fabric     = trim($_POST['fabric_name'] ?? '');
    $rate       = floatval($_POST['embroidery_rate'] ?? 0);
    $vendor     = trim($_POST['embroidery_vendor_name'] ?? '');
    $status     = trim($_POST['status'] ?? '');
    $cmt_party  = trim($_POST['cmt_party'] ?? '');
    $current_datetime = date('Y-m-d H:i:s');
    
    // Image handling
    $imageName = $row['image']; // keep existing by default
    if (!empty($_FILES['image']['name'])) {
        // Delete old image
        if (!empty($row['image']) && file_exists("../../assets/uploads/" . $row['image'])) {
            unlink("../../assets/uploads/" . $row['image']);
        }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imageName = time() . '_' . uniqid() . '.' . $ext;
        $upload_dir = "../../assets/uploads/";
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $imageName);
    } else {
        // No new image – if design changed, try to reuse existing design image
        if ($design != $row['design_name'] && !empty($design)) {
            $img_stmt = $conn->prepare("SELECT image FROM jobs WHERE design_name = ? AND image IS NOT NULL AND image != '' AND company_id = ? ORDER BY id DESC LIMIT 1");
            $img_stmt->bind_param("si", $design, $company_id);
            $img_stmt->execute();
            $img_res = $img_stmt->get_result();
            if ($img_res->num_rows) {
                $imageName = $img_res->fetch_assoc()['image'];
            }
        }
    }
    
    // Build update query dynamically
    $update_sql = "UPDATE jobs SET 
        job_no = ?, serial_no = ?, design_name = ?, brand_name = ?,
        size = ?, quantity = ?, fabric_name = ?, embroidery_rate = ?,
        embroidery_vendor_name = ?, status = ?, image = ?, updated_at = ?";
    $params = [$job_no, $serial_no, $design, $brand, $size, $qty, $fabric, $rate, $vendor, $status, $imageName, $current_datetime];
    // Correct types: job_no(s), serial_no(s), design(s), brand(s), size(s),
    // quantity(d – double), fabric_name(s), rate(d), vendor(s), status(s), image(s), updated_at(s)
    $types = "sssssdsdssss";   // exactly 12 characters
    
    if ($status == 'CMT' && !empty($cmt_party)) {
        $update_sql .= ", cmt_party = ?";
        $params[] = $cmt_party;
        $types .= "s";          // one more string
        if (empty($row['cmt_date'])) {
            $update_sql .= ", cmt_date = CURDATE()";
        }
    }
    if ($status == 'Stitching' && empty($row['stitching_date'])) {
        $update_sql .= ", stitching_date = CURDATE()";
    }
    $update_sql .= " WHERE id = ? AND company_id = ?";
    $params[] = $id;
    $params[] = $company_id;
    $types .= "ii";             // two integers for WHERE
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param($types, ...$params);
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "Job updated successfully!";
        header("Location: list.php");
        exit();
    } else {
        $error_message = "Error: " . $update_stmt->error;
    }
}

$current_image_path = getImagePath($row['image']);
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Job</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original) */
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --success: #27ae60;
        --danger: #e74c3c;
        --bg-light: #F8F9FA;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, sans-serif;
    }

    .main-container {
        margin-left: 14%;
        padding: 20px 24px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .page-header h4 {
        color: var(--primary-dark);
        font-size: 1.4rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .page-header h4 i {
        color: var(--primary);
        font-size: 1.5rem;
    }

    input[name="job_no"] {
        background: #E8F8F5 !important;
        border: 2px solid #27AE60 !important;
        font-size: 1.2rem !important;
        font-weight: 600 !important;
        color: #1E8449 !important;
        border-radius: 10px !important;
        text-align: center !important;
    }

    input[name="job_no"]:focus {
        background: #D5F5E3 !important;
        border-color: #2ECC71 !important;
        box-shadow: 0 0 0 3px rgba(46, 204, 113, 0.2) !important;
        outline: none !important;
    }

    .card {
        background: white;
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .card-header {
        background: white;
        padding: 14px 20px;
        border-bottom: 2px solid var(--primary);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .card-header h4 {
        color: var(--primary-dark);
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h4 i {
        color: var(--primary);
        font-size: 1.1rem;
    }

    .card-body {
        padding: 20px;
    }

    .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 16px;
    }

    .form-group {
        flex: 1;
        min-width: 180px;
    }

    label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    label i {
        color: var(--primary);
        width: 15px;
        margin-right: 5px;
        font-size: 0.75rem;
    }

    .required::after {
        content: '*';
        color: var(--danger);
        margin-left: 4px;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 8px 12px;
        font-size: 0.9rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        transition: all 0.2s;
        background: white;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .image-preview {
        margin-top: 8px;
        padding: 8px;
        background: var(--bg-light);
        border-radius: 8px;
        text-align: center;
        border: 1px solid var(--border);
    }

    .image-preview img {
        max-width: 70px;
        max-height: 70px;
        border-radius: 6px;
    }

    .current-image {
        background: var(--primary-light);
        border-color: var(--primary);
    }

    .new-image-preview {
        background: #e8f5e9;
        border-color: var(--success);
    }
    
    .design-image-preview {
        background: #e3f2fd;
        border-color: var(--info);
    }

    .cmt-section {
        margin-top: 16px;
        padding: 16px;
        background: var(--primary-light);
        border-radius: 12px;
        border: 1px solid rgba(243,156,18,0.2);
    }

    .cmt-section.hidden {
        display: none;
    }

    .cmt-section label {
        color: var(--primary-dark);
        font-size: 0.75rem;
    }

    .cmt-section input {
        font-size: 0.9rem;
    }

    .cmt-info {
        background: white;
        padding: 8px 12px;
        border-radius: 8px;
        margin-top: 10px;
        font-size: 0.75rem;
        border-left: 3px solid var(--primary);
    }

    .btn {
        padding: 9px 22px;
        font-size: 0.85rem;
        font-weight: 600;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: var(--bg-light);
        color: var(--text-dark);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--border);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
    }

    .form-actions {
        margin-top: 24px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .text-muted {
        font-size: 0.7rem;
        color: var(--text-muted);
        margin-top: 4px;
    }

    .alert {
        padding: 12px 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.9rem;
        border: none;
        animation: slideIn 0.3s ease;
    }

    .alert-danger {
        background: #fef5e7;
        color: #856404;
        border-left: 4px solid var(--danger);
    }

    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-content {
        border-radius: 16px;
        border: none;
    }

    .modal-header {
        background: var(--danger);
        color: white;
        border-radius: 16px 16px 0 0;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .warning-box {
        background: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 12px;
        border-radius: 8px;
        margin: 12px 0;
    }

    .related-records-list {
        margin-top: 8px;
        padding-left: 20px;
    }

    .related-records-list li {
        margin: 4px 0;
        font-size: 0.85rem;
    }

    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
            padding: 16px 20px;
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 16px;
            margin-top: 60px;
        }
        .form-row {
            flex-direction: column;
            gap: 12px;
        }
        .form-group {
            min-width: 100%;
        }
        .form-actions {
            flex-direction: column;
        }
        .form-actions .btn {
            width: 100%;
            justify-content: center;
        }
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 768px) {
        .card-body {
            padding: 16px;
        }
        .card-header {
            padding: 12px 16px;
        }
        .page-header h4 {
            font-size: 1.2rem;
        }
    }
</style>

<datalist id="party-list">
    <?php if($parties_result && $parties_result->num_rows > 0): $parties_result->data_seek(0); while($party = $parties_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($party['account_name']) ?>">
    <?php endwhile; endif; ?>
</datalist>
<datalist id="design-list">
    <?php 
    $design_list_stmt = $conn->prepare("SELECT DISTINCT design_name FROM jobs WHERE design_name IS NOT NULL AND design_name != '' AND company_id = ? ORDER BY design_name");
    $design_list_stmt->bind_param("i", $company_id);
    $design_list_stmt->execute();
    $design_list_res = $design_list_stmt->get_result();
    while ($d = $design_list_res->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($d['design_name']) ?>">
    <?php endwhile; ?>
</datalist>


<datalist id="brand-list">
    <?php
   $brand_list_stmt = $conn->prepare("SELECT DISTINCT brand_name FROM jobs WHERE brand_name IS NOT NULL AND brand_name != '' AND company_id = ? ORDER BY brand_name");
    $brand_list_stmt->bind_param("i", $company_id);
    $brand_list_stmt->execute();
    $brand_list_res = $brand_list_stmt->get_result();
    while ($b = $brand_list_res->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($b['brand_name']) ?>">
    <?php endwhile; ?>
</datalist>
   
</datalist>
<datalist id="fabric-list">
    <?php
    $fabric_list_stmt = $conn->prepare("SELECT DISTINCT fabric_name FROM jobs WHERE fabric_name IS NOT NULL AND fabric_name != '' AND company_id = ? ORDER BY fabric_name");
    $fabric_list_stmt->bind_param("i", $company_id);
    $fabric_list_stmt->execute();
    $fabric_list_res = $fabric_list_stmt->get_result();
    while ($f = $fabric_list_res->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($f['fabric_name']) ?>">
    <?php endwhile; ?>
</datalist>
        

<datalist id="vendor-list">
    <?php
    $vendor_list_stmt = $conn->prepare("SELECT DISTINCT embroidery_vendor_name FROM jobs WHERE embroidery_vendor_name IS NOT NULL AND embroidery_vendor_name != '' AND company_id = ? ORDER BY embroidery_vendor_name");
    $vendor_list_stmt->bind_param("i", $company_id);
    $vendor_list_stmt->execute();
    $vendor_list_res = $vendor_list_stmt->get_result();
    while ($v = $vendor_list_res->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($v['embroidery_vendor_name']) ?>">
    <?php endwhile; ?>
</datalist>
    
<datalist id="size-list">
    <option value="3 PIECES"><option value="2 PIECES"><option value="22-28"><option value="30-36">
    <option value="KURTI"><option value="XL"><option value="L"><option value="M"><option value="S">
</datalist>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h4><i class="fas fa-edit"></i> Edit Job #<?php echo htmlspecialchars($row['job_no']); ?></h4>
        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
            <i class="fas fa-trash-alt"></i> Delete Job
        </button>
    </div>

    <?php if(isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>
<datalist id="size-list">
    <option value="3 PIECES"><option value="2 PIECES"><option value="22-28"><option value="30-36">
    <option value="KURTI"><option value="XL"><option value="L"><option value="M"><option value="S">
</datalist>
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-briefcase"></i> Job Details</h4>
            <small class="text-muted">
                <i class="fas fa-clock"></i> Created: <?php echo isset($row['created_at']) ? date('d-m-Y', strtotime($row['created_at'])) : 'N/A'; ?>
            </small>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="editJobForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="required"><i class="fas fa-hashtag"></i> Job No</label>
                        <input type="text" name="job_no" value="<?php echo htmlspecialchars($row['job_no']); ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> Serial Number</label>
                        <input type="text" name="serial_no" value="<?php echo htmlspecialchars($row['serial_no'] ?? ''); ?>" class="form-control" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-palette"></i> Design Name</label>
                        <input type="text" name="design_name" id="design_name" value="<?php echo htmlspecialchars($row['design_name']); ?>" class="form-control" list="design-list" autocomplete="off">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Brand Name</label>
                        <input type="text" name="brand_name" value="<?php echo htmlspecialchars($row['brand_name']); ?>" class="form-control" list="brand-list" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-ruler"></i> Size</label>
                        <input type="text" name="size" value="<?php echo htmlspecialchars($row['size']); ?>" class="form-control" list="size-list" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-boxes"></i> Quantity</label>
                        <input type="number" name="quantity" value="<?php echo $row['quantity']; ?>" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-tshirt"></i> Fabric Name</label>
                        <input type="text" name="fabric_name" value="<?php echo htmlspecialchars($row['fabric_name']); ?>" class="form-control" list="fabric-list" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Embroidery Rate</label>
                        <input type="number" step="0.01" name="embroidery_rate" value="<?php echo $row['embroidery_rate']; ?>" class="form-control">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Embroidery Vendor</label>
                        <input type="text" name="embroidery_vendor_name" value="<?php echo htmlspecialchars($row['embroidery_vendor_name']); ?>" class="form-control" list="vendor-list" autocomplete="off">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="required"><i class="fas fa-chart-line"></i> Status</label>
                        <select name="status" id="status_select" class="form-control" required>
                            <option <?php if($row['status']=="Embroidery") echo "selected"; ?>>Embroidery</option>
                            <option <?php if($row['status']=="Backup") echo "selected"; ?>>Backup</option>
                            <option <?php if($row['status']=="Ready") echo "selected"; ?>>Ready</option>
                            <option <?php if($row['status']=="Stitching") echo "selected"; ?>>Stitching</option>
                            <option <?php if($row['status']=="Incomplete") echo "selected"; ?>>Incomplete</option>
                            <option <?php if($row['status']=="Checking") echo "selected"; ?>>Checking</option>
                            <option <?php if($row['status']=="CMT") echo "selected"; ?>>CMT</option>
                            <option <?php if($row['status']=="Close") echo "selected"; ?>>Close</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-image"></i> Design Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*" id="image_input">
                        <div id="image_preview_container">
                            <?php if($current_image_path): ?>
                                <div class="image-preview current-image" id="current_image">
                                    <img src="<?php echo $current_image_path; ?>" alt="Current Job Image">
                                    <small>Current Image</small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div id="new_image_preview"></div>
                        <small class="text-muted">Leave empty to keep current image or auto-load from design</small>
                    </div>
                </div>

                <div id="design_image_preview" class="image-preview design-image-preview" style="display: none;">
                    <img id="design_preview_img" src="" alt="Design Image">
                    <small>Auto-loaded from design</small>
                </div>

                <div id="cmt_section" class="cmt-section <?php echo ($row['status'] == 'CMT') ? '' : 'hidden'; ?>">
                    <label class="required"><i class="fas fa-handshake"></i> CMT Party Name</label>
                    <input type="text" name="cmt_party" id="cmt_party" class="form-control" 
                           list="party-list" placeholder="Select or type CMT party name"
                           value="<?php echo htmlspecialchars($row['cmt_party'] ?? ''); ?>">
                    <div class="text-muted">
                        <i class="fas fa-info-circle"></i> Select from existing parties
                    </div>
                    <div id="cmt_warning" class="text-danger" style="font-size: 0.75rem; margin-top: 5px; display: none;">
                        <i class="fas fa-exclamation-triangle"></i> Party not found!
                    </div>
                    <?php if(!empty($row['cmt_date'])): ?>
                        <div class="cmt-info">
                            <i class="fas fa-calendar-check"></i> 
                            <strong>CMT Since:</strong> <?php echo date('d-m-Y', strtotime($row['cmt_date'])); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update" class="btn btn-primary" id="save_btn">
                        <i class="fas fa-save"></i> Update Job
                    </button>
                    <a href="list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong>Job #<?php echo htmlspecialchars($row['job_no']); ?></strong>?</p>
                <?php if($total_related > 0): ?>
                <div class="warning-box">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?php echo $total_related; ?> related record(s)</strong> will be deleted:
                    <ul class="related-records-list">
                        <?php foreach($related_data as $data): ?>
                            <li><?php echo $data['label']; ?>: <?php echo $data['count']; ?> record(s)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <p class="text-danger mt-2"><i class="fas fa-skull-crosswalk"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="?delete=<?php echo $id; ?>" class="btn btn-danger">Delete Job</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const designImages = <?php echo json_encode($design_images); ?>;

function getDesignImagePath(imageName) {
    if(!imageName) return false;
    return "../../assets/uploads/" + imageName;
}

const designNameInput = document.getElementById('design_name');
const designPreviewDiv = document.getElementById('design_image_preview');
const designPreviewImg = document.getElementById('design_preview_img');
const currentImageDiv = document.getElementById('current_image');

function loadDesignImage() {
    const designName = designNameInput.value.trim();
    if(designName && designImages[designName]) {
        const imagePath = getDesignImagePath(designImages[designName]);
        fetch(imagePath, { method: 'HEAD' })
            .then(response => {
                if(response.ok) {
                    designPreviewImg.src = imagePath;
                    designPreviewDiv.style.display = 'block';
                } else {
                    designPreviewDiv.style.display = 'none';
                }
            })
            .catch(() => {
                designPreviewDiv.style.display = 'none';
            });
    } else {
        designPreviewDiv.style.display = 'none';
    }
}

if(designNameInput) {
    designNameInput.addEventListener('change', loadDesignImage);
    designNameInput.addEventListener('blur', loadDesignImage);
}

const statusSelect = document.getElementById('status_select');
const cmtSection = document.getElementById('cmt_section');
const cmtPartyInput = document.getElementById('cmt_party');
const cmtWarning = document.getElementById('cmt_warning');
const partyList = document.getElementById('party-list');
const saveBtn = document.getElementById('save_btn');

function toggleCMTSection() {
    if (statusSelect.value === 'CMT') {
        cmtSection.classList.remove('hidden');
        cmtPartyInput.required = true;
    } else {
        cmtSection.classList.add('hidden');
        cmtPartyInput.required = false;
        cmtWarning.style.display = 'none';
    }
}

function checkPartyExists(partyName) {
    if (!partyName) return true;
    const options = partyList.options;
    for(let i = 0; i < options.length; i++) {
        if(options[i].value === partyName) return true;
    }
    return false;
}

if(cmtPartyInput) {
    cmtPartyInput.addEventListener('input', function() {
        const partyName = this.value;
        if (statusSelect.value === 'CMT' && partyName) {
            if (!checkPartyExists(partyName)) {
                cmtWarning.style.display = 'block';
                if(saveBtn) saveBtn.disabled = true;
            } else {
                cmtWarning.style.display = 'none';
                if(saveBtn) saveBtn.disabled = false;
            }
        }
    });
}

const form = document.getElementById('editJobForm');
if(form) {
    form.addEventListener('submit', function(e) {
        if (statusSelect.value === 'CMT') {
            const partyName = cmtPartyInput.value.trim();
            if (!partyName) {
                e.preventDefault();
                alert('Please enter CMT party name');
                return false;
            }
            if (!checkPartyExists(partyName)) {
                e.preventDefault();
                alert('Party not found! Please add this party first.');
                return false;
            }
        }
        return true;
    });
}

statusSelect.addEventListener('change', toggleCMTSection);
toggleCMTSection();

const imageInput = document.getElementById('image_input');
const newImagePreview = document.getElementById('new_image_preview');
if(imageInput) {
    imageInput.addEventListener('change', function(e) {
        const file = this.files[0];
        if(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                newImagePreview.innerHTML = `
                    <div class="image-preview new-image-preview">
                        <img src="${e.target.result}" alt="New Image">
                        <small>New Image (Will overwrite)</small>
                    </div>
                `;
                if(designPreviewDiv) designPreviewDiv.style.display = 'none';
            };
            reader.readAsDataURL(file);
        } else {
            newImagePreview.innerHTML = '';
            loadDesignImage();
        }
    });
}
</script>
</body>
</html>