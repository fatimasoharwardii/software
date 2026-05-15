<?php
$page_identifier = 'jobs/add.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];

// Ensure required tables have company_id column
$tables = ['jobs', 'accounts'];
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

// ----------------- LIVE DUPLICATE CHECK (AJAX) -----------------
if (isset($_GET['action']) && $_GET['action'] === 'check_job' && isset($_GET['job_no'])) {
    $job_no_check = trim($_GET['job_no']);
    if ($job_no_check === '') {
        echo 'empty';
        exit;
    }
    $check_stmt = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
    $check_stmt->bind_param("si", $job_no_check, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    echo ($check_result->num_rows > 0) ? 'exists' : 'available';
    exit;
}

// Fetch last 5 jobs for current company
$recent_stmt = $conn->prepare("SELECT * FROM jobs WHERE company_id = ? ORDER BY id DESC LIMIT 5");
$recent_stmt->bind_param("i", $company_id);
$recent_stmt->execute();
$recent_result = $recent_stmt->get_result();
$recent_jobs = [];
while ($row = $recent_result->fetch_assoc()) {
    $recent_jobs[] = $row;
}
$latest_job_data = $recent_jobs[0] ?? null;
$latest_job_no   = $latest_job_data['job_no'] ?? '';

// Generate suggested next job number
$suggested_job_no = '';
if ($latest_job_no) {
    preg_match('/(\d+)/', $latest_job_no, $matches);
    if (isset($matches[1])) {
        $next_job_number = $matches[1] + 1;
        $suggested_job_no = preg_replace('/\d+/', $next_job_number, $latest_job_no, 1);
    } else {
        $suggested_job_no = '1';
    }
}

// Fetch distinct design names with images
$designs_stmt = $conn->prepare("SELECT DISTINCT design_name, image FROM jobs WHERE design_name IS NOT NULL AND design_name != '' AND company_id = ? ORDER BY design_name");
$designs_stmt->bind_param("i", $company_id);
$designs_stmt->execute();
$designs_result = $designs_stmt->get_result();

// Fetch distinct brands
$brands_stmt = $conn->prepare("SELECT DISTINCT brand_name FROM jobs WHERE brand_name IS NOT NULL AND brand_name != '' AND company_id = ? ORDER BY brand_name");
$brands_stmt->bind_param("i", $company_id);
$brands_stmt->execute();
$brands_result = $brands_stmt->get_result();

// Fetch distinct fabrics
$fabrics_stmt = $conn->prepare("SELECT DISTINCT fabric_name FROM jobs WHERE fabric_name IS NOT NULL AND fabric_name != '' AND company_id = ? ORDER BY fabric_name");
$fabrics_stmt->bind_param("i", $company_id);
$fabrics_stmt->execute();
$fabrics_result = $fabrics_stmt->get_result();

// Fetch distinct embroidery vendors
$vendors_stmt = $conn->prepare("SELECT DISTINCT embroidery_vendor_name FROM jobs WHERE embroidery_vendor_name IS NOT NULL AND embroidery_vendor_name != '' AND company_id = ? ORDER BY embroidery_vendor_name");
$vendors_stmt->bind_param("i", $company_id);
$vendors_stmt->execute();
$vendors_result = $vendors_stmt->get_result();

// Fetch all parties for CMT
$parties_stmt = $conn->prepare("SELECT account_name FROM accounts WHERE company_id = ? ORDER BY account_name");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties_result = $parties_stmt->get_result();

// Build design images array for JavaScript
$design_images = [];
if ($designs_result) {
    $designs_result->data_seek(0);
    while ($design = $designs_result->fetch_assoc()) {
        if (!empty($design['image'])) {
            $design_images[$design['design_name']] = $design['image'];
        }
    }
}

$success_message = "";
$error_message   = "";

// Upload directory
$upload_dir = __DIR__ . "/../../assets/uploads/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if (isset($_POST['save'])) {
    // Retrieve form data
    $job_no    = trim($_POST['job_no'] ?? '');
    $serial_no = trim($_POST['serial_no'] ?? '');
    $design    = trim($_POST['design_name'] ?? '');
    $brand     = trim($_POST['brand_name'] ?? '');
    $size      = trim($_POST['size'] ?? '');
    $qty       = $_POST['quantity'] !== '' ? floatval($_POST['quantity']) : null;
    $fabric    = trim($_POST['fabric_name'] ?? '');
    $rate      = $_POST['embroidery_rate'] !== '' ? floatval($_POST['embroidery_rate']) : null;
    $vendor    = trim($_POST['embroidery_vendor_name'] ?? '');
    $status    = trim($_POST['status'] ?? '');
    $cmt_party = trim($_POST['cmt_party'] ?? '');
    $job_date  = $_POST['job_date'] ?? date('Y-m-d');

    // Validation
    $errors = [];
    if (empty($job_no)) $errors[] = "Job number is required.";
    if (empty($design)) $errors[] = "Design name is required.";
    if (empty($fabric)) $errors[] = "Fabric name is required.";
    if ($qty === null || $qty < 0) $errors[] = "Quantity must be a positive number.";
    if ($rate === null || $rate < 0) $errors[] = "Embroidery rate must be positive.";
    if (empty($status)) $errors[] = "Status is required.";
    if ($status === 'CMT' && empty($cmt_party)) $errors[] = "CMT Party is required when status is CMT.";

    if (!empty($errors)) {
        $error_message = implode("<br>", $errors);
    } else {
        // Check duplicate job_no for this company
        $check_dup = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
        $check_dup->bind_param("si", $job_no, $company_id);
        $check_dup->execute();
        if ($check_dup->get_result()->num_rows > 0) {
            $error_message = "Job number '$job_no' already exists! Please use a unique job number.";
        }
    }

    // If still no error, proceed with image and insert
    if (empty($error_message)) {
        $imageName = '';
        // Image upload
        if (!empty($_FILES['image']['name'])) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $imageName = time() . '_' . uniqid() . '.' . $ext;
            $upload_path = $upload_dir . $imageName;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                $error_message = "Failed to upload image. Please try again.";
                $imageName = '';
            }
        } elseif (!empty($design)) {
            // Reuse existing design image
            $img_stmt = $conn->prepare("SELECT image FROM jobs WHERE design_name = ? AND image IS NOT NULL AND image != '' AND company_id = ? ORDER BY id DESC LIMIT 1");
            $img_stmt->bind_param("si", $design, $company_id);
            $img_stmt->execute();
            $img_res = $img_stmt->get_result();
            if ($img_res->num_rows > 0) {
                $existing_img = $img_res->fetch_assoc();
                $imageName = $existing_img['image'];
            }
        }
    }

    if (empty($error_message)) {
        // CORRECTED BINDING: all text columns as 's', quantity as 'i' (if integer) or 'd', rate as 'd'
        // Here we use 's' for all strings, 'i' for quantity (since jobs.quantity is likely INT), 'd' for embroidery_rate
        $insert_stmt = $conn->prepare("INSERT INTO jobs 
            (job_no, serial_no, design_name, brand_name, size, quantity, fabric_name, embroidery_rate, embroidery_vendor_name, status, cmt_party, image, job_date, created_at, company_id, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");

      // All text columns 's', quantity 'i', rate 'd', IDs 'i'
$insert_stmt->bind_param("sssssisdsssssii",
    $job_no,      // s
    $serial_no,   // s
    $design,      // s
    $brand,       // s
    $size,        // s
    $qty,         // i – integer
    $fabric,      // s – now a string!
    $rate,        // d – double
    $vendor,      // s
    $status,      // s – now a string!
    $cmt_party,   // s
    $imageName,   // s
    $job_date,    // s
    $company_id,  // i
    $user_id      // i
);

        if ($insert_stmt->execute()) {
            $success_message = "✓ Job entry added successfully!";
            echo "<script>
                    setTimeout(function() {
                        window.location.href = 'list.php';
                    }, 1000);
                  </script>";
        } else {
            $error_message = "Error: " . $insert_stmt->error;
        }
    }
}
?>
<!-- HTML / JS remains unchanged – already provided in the question, but with the corrected form. 
     I'm including the same front-end with the required attributes. -->
<!DOCTYPE html>
<html>
<head>
<title>Add Job</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --border: #E5E7E9;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --success: #27ae60;
        --danger: #e74c3c;
        --bg-light: #F8F9FA;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); font-family: 'Segoe UI', system-ui, sans-serif; }
    .main-container { margin-left: 14%; padding: 24px 32px; min-height: 100vh; transition: margin-left 0.3s ease; }
    .page-header { margin-bottom: 24px; }
    .page-header h2 { font-size: 1.8rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 12px; color: var(--text-dark); }
    .page-header h2 i { color: var(--primary); font-size: 1.8rem; }
    .two-column-layout { display: flex; gap: 30px; flex-wrap: wrap; }
    .form-column { flex: 1.5; min-width: 280px; }
    .recent-column { flex: 0.75; min-width: 280px; }
    .card { background: white; border: none; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden; }
    .card-header { background: white; padding: 16px 24px; border-bottom: 2px solid var(--primary); }
    .card-header h4 { color: var(--primary-dark); font-size: 1.2rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; }
    .card-header h4 i { color: var(--primary); font-size: 1.2rem; }
    .card-body { padding: 24px; }
    .form-row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
    .form-group { flex: 1; min-width: 180px; }
    label { display: block; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 6px; }
    label i { color: var(--primary); width: 16px; margin-right: 5px; font-size: 0.85rem; }
    .required::after { content: ' *'; color: var(--danger); }
    .form-control, .form-select { width: 100%; padding: 10px 14px; font-size: 1rem; border: 1.5px solid var(--border); border-radius: 10px; transition: all 0.2s; background: white; }
    .form-control:focus, .form-select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(243,156,18,0.1); }
    .image-preview { margin-top: 10px; padding: 10px; background: var(--bg-light); border-radius: 10px; text-align: center; border: 1px solid var(--border); }
    .image-preview img { max-width: 100px; max-height: 100px; border-radius: 8px; object-fit: cover; }
    .design-image-preview { background: var(--primary-light); border-color: var(--primary); }
    .cmt-section { margin-top: 16px; padding: 16px; background: var(--primary-light); border-radius: 12px; border: 1px solid rgba(243,156,18,0.2); }
    .cmt-section.hidden { display: none; }
    .cmt-section label { color: var(--primary-dark); font-size: 0.85rem; }
    .cmt-section input { font-size: 1rem; }
    .recent-jobs-list { max-height: 550px; overflow-y: auto; }
    .job-item { background: var(--bg-light); border: 1px solid var(--border); border-radius: 12px; padding: 14px; margin-bottom: 12px; transition: all 0.2s; }
    .job-item:hover { border-color: var(--primary); background: white; transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .job-number { font-weight: 700; color: var(--primary-dark); font-size: 1rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px dashed var(--border); }
    .job-number i { color: var(--primary); font-size: 0.9rem; }
    .job-details { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; font-size: 0.85rem; }
    .job-detail { display: flex; justify-content: space-between; }
    .job-detail .label { color: var(--text-muted); font-size: 0.7rem; }
    .job-detail .value { font-weight: 600; color: var(--text-dark); font-size: 0.8rem; }
    .job-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
    .status-Embroidery { background: #9b59b6; color: white; }
    .status-Ready { background: #2ecc71; color: white; }
    .status-Stitching { background: #e74c3c; color: white; }
    .status-CMT { background: #34495e; color: white; }
    .status-default { background: #95a5a6; color: white; }
    .btn { padding: 10px 24px; font-size: 1rem; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(243,156,18,0.3); }
    .btn-secondary { background: #e9ecef; color: var(--text-dark); border: 1px solid var(--border); }
    .btn-secondary:hover { background: #dee2e6; }
    .action-buttons { margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap; }
    .alert { padding: 12px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; border: none; animation: slideIn 0.3s ease; }
    .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
    .alert-danger { background: #fef5e7; color: #856404; border-left: 4px solid var(--danger); }
    @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .hidden { display: none; }
    .empty-state { text-align: center; padding: 40px; color: var(--text-muted); }
    .empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5; }
    .empty-state p { font-size: 0.9rem; }
    .duplicate-feedback { font-size: 0.8rem; margin-top: 4px; display: none; }
    .is-invalid { border-color: var(--danger) !important; }
    @media (max-width: 1200px) { .main-container { margin-left: 10%; padding: 20px; } }
    @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .two-column-layout { flex-direction: column; gap: 20px; } .form-row { flex-direction: column; gap: 12px; } .form-group { min-width: 100%; } .action-buttons { flex-direction: column; } .action-buttons .btn { width: 100%; justify-content: center; } }
    @media (max-width: 768px) { .card-body { padding: 16px; } .card-header { padding: 12px 16px; } .job-details { grid-template-columns: 1fr; } .page-header h2 { font-size: 1.3rem; } }
</style>

<datalist id="design-list">
    <?php if($designs_result): $designs_result->data_seek(0); while($design = $designs_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($design['design_name']) ?>">
    <?php endwhile; endif; ?>
</datalist>
<datalist id="brand-list">
    <?php if($brands_result): $brands_result->data_seek(0); while($brand = $brands_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($brand['brand_name']) ?>">
    <?php endwhile; endif; ?>
</datalist>
<datalist id="fabric-list">
    <?php if($fabrics_result): $fabrics_result->data_seek(0); while($fabric = $fabrics_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($fabric['fabric_name']) ?>">
    <?php endwhile; endif; ?>
</datalist>
<datalist id="vendor-list">
    <?php if($vendors_result): $vendors_result->data_seek(0); while($vendor = $vendors_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($vendor['embroidery_vendor_name']) ?>">
    <?php endwhile; endif; ?>
</datalist>
<datalist id="party-list">
    <?php if($parties_result): $parties_result->data_seek(0); while($party = $parties_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($party['account_name']) ?>">
    <?php endwhile; endif; ?>
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
        <h2><i class="fas fa-plus-circle"></i> Add New Job</h2>
    </div>

    <?php if($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $success_message ?>
            <div class="spinner-border spinner-border-sm ms-2" role="status"></div>
        </div>
    <?php endif; ?>
    
    <?php if($error_message): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
    <?php endif; ?>

    <div class="two-column-layout">
        <div class="form-column">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-edit"></i> Job Details</h4>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="jobForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-hashtag"></i> Job Number</label>
                                <input type="text" name="job_no" id="job_no" class="form-control" required 
                                       value="<?= htmlspecialchars($suggested_job_no) ?>" autocomplete="off">
                                <div id="jobNoFeedback" class="duplicate-feedback text-danger">
                                    <i class="fas fa-exclamation-circle"></i> This job number already exists!
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-barcode"></i> Serial Number</label>
                                <input type="text" name="serial_no" class="form-control" placeholder="Optional">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-palette"></i> Design Name</label>
                                <input type="text" name="design_name" id="design_name" class="form-control" required list="design-list" autocomplete="off">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-trademark"></i> Brand Name</label>
                                <input type="text" name="brand_name" class="form-control" list="brand-list">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-ruler"></i> Size</label>
                                <input type="text" name="size" class="form-control" list="size-list">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-boxes"></i> Quantity</label>
                                <input type="number" name="quantity" class="form-control" step="1" min="0" value="" required placeholder="e.g., 500">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required"><i class="fas fa-tshirt"></i> Fabric Name</label>
                                <input type="text" name="fabric_name" class="form-control" required list="fabric-list" autocomplete="off" value="">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-tag"></i> Embroidery Rate</label>
                                <input type="number" step="0.01" name="embroidery_rate" class="form-control" required value="" placeholder="Per piece rate">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Embroidery Vendor</label>
                                <input type="text" name="embroidery_vendor_name" class="form-control" list="vendor-list">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Job Date</label>
                                <input type="date" name="job_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label class="required"><i class="fas fa-chart-line"></i> Status</label>
                                <select name="status" id="status_select" class="form-control" required>
                                    <option value="Embroidery">Embroidery</option>
                                    <option value="Backup">Backup</option>
                                    <option value="Ready">Ready</option>
                                    <option value="Stitching">Stitching</option>
                                    <option value="Incomplete">Incomplete</option>
                                    <option value="Checking">Checking</option>
                                    <option value="Close">Close</option>
                                    <option value="CMT">CMT</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> Design Image</label>
                                <input type="file" name="image" class="form-control" id="image-input" accept="image/*">
                                <div id="image-preview-container"></div>
                                <small class="text-muted">Leave empty to auto-use existing design image</small>
                            </div>
                        </div>
                        
                        <div id="cmt_section" class="cmt-section hidden">
                            <label class="required"><i class="fas fa-building"></i> CMT Party Name</label>
                            <input type="text" name="cmt_party" id="cmt_party" class="form-control" list="party-list" placeholder="Select or type party name">
                            <div id="cmt_warning" class="text-danger" style="font-size: 0.8rem; margin-top: 5px; display: none;">
                                <i class="fas fa-exclamation-triangle"></i> Party not found! Please add this party first.
                            </div>
                        </div>
                        
                        <div id="design-image-preview" class="image-preview design-image-preview" style="display: none;">
                            <img id="design-preview-img" src="" alt="Design Image">
                            <small>Auto-loaded from existing design</small>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" name="save" class="btn btn-primary" id="saveBtn">
                                <i class="fas fa-save"></i> Save Job
                            </button>
                            <a href="list.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="recent-column">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-history"></i> Recent Jobs</h4>
                </div>
                <div class="card-body">
                    <div class="recent-jobs-list">
                        <?php if(!empty($recent_jobs)): ?>
                            <?php foreach($recent_jobs as $job): 
                                $status_class = 'status-default';
                                switch($job['status']) {
                                    case 'Embroidery': $status_class = 'status-Embroidery'; break;
                                    case 'Ready': $status_class = 'status-Ready'; break;
                                    case 'Stitching': $status_class = 'status-Stitching'; break;
                                    case 'CMT': $status_class = 'status-CMT'; break;
                                }
                            ?>
                            <div class="job-item">
                                <div class="job-number">
                                    <span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($job['job_no']) ?></span>
                                    <?php if(!empty($job['serial_no'])): ?>
                                        <span class="badge bg-secondary"><i class="fas fa-barcode"></i> <?= htmlspecialchars($job['serial_no']) ?></span>
                                    <?php endif; ?>
                                    <span class="job-status <?= $status_class ?>"><?= $job['status'] ?></span>
                                </div>
                                <div class="job-details">
                                    <div class="job-detail">
                                        <span class="label">Design:</span>
                                        <span class="value"><?= htmlspecialchars($job['design_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="job-detail">
                                        <span class="label">Size:</span>
                                        <span class="value"><?= htmlspecialchars($job['size'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="job-detail">
                                        <span class="label">Quantity:</span>
                                        <span class="value"><?= $job['quantity'] ?> pcs</span>
                                    </div>
                                    <div class="job-detail">
                                        <span class="label">Fabric:</span>
                                        <span class="value" type="text"><?= htmlspecialchars($job['fabric_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="job-detail">
                                        <span class="label">Emb Vendor:</span>
                                        <span class="value"><?= htmlspecialchars($job['embroidery_vendor_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="job-detail">
                                        <span class="label">Created:</span>
                                        <span class="value"><?= date('d-m-Y', strtotime($job['created_at'])) ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No jobs found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const designImages = <?php echo json_encode($design_images); ?>;
function getDesignImagePath(imageName) { return imageName ? "../../assets/uploads/" + imageName : false; }
const designNameInput = document.getElementById('design_name');
const designPreviewDiv = document.getElementById('design-image-preview');
const designPreviewImg = document.getElementById('design-preview-img');
function loadDesignImage() {
    let name = designNameInput.value.trim();
    if (name && designImages[name]) {
        let path = getDesignImagePath(designImages[name]);
        fetch(path,{method:'HEAD'}).then(r=>{ if(r.ok){ designPreviewImg.src=path; designPreviewDiv.style.display='block';} else designPreviewDiv.style.display='none'; }).catch(()=>designPreviewDiv.style.display='none');
    } else designPreviewDiv.style.display='none';
}
if(designNameInput){ designNameInput.addEventListener('change',loadDesignImage); designNameInput.addEventListener('blur',loadDesignImage); }

const statusSelect = document.getElementById('status_select');
const cmtSection = document.getElementById('cmt_section');
const cmtParty = document.getElementById('cmt_party');
function toggleCMT(){ let cmt = statusSelect.value==='CMT'; cmtSection.classList.toggle('hidden',!cmt); cmtParty.required=cmt; }
statusSelect.addEventListener('change',toggleCMT);
toggleCMT();

const imageInput = document.getElementById('image-input');
imageInput?.addEventListener('change', function(e){
    let file = this.files[0];
    if(file){
        let reader = new FileReader();
        reader.onload = function(ev){ document.getElementById('image-preview-container').innerHTML=`<div class="image-preview"><img src="${ev.target.result}"><small>New Image</small></div>`; designPreviewDiv.style.display='none'; };
        reader.readAsDataURL(file);
    } else { document.getElementById('image-preview-container').innerHTML=''; loadDesignImage(); }
});

// Duplicate job check
const jobNoInput = document.getElementById('job_no');
const jobNoFeedback = document.getElementById('jobNoFeedback');
const saveBtn = document.getElementById('saveBtn');
let isJobNoValid = true;
function checkJobNoDuplicate() {
    let val = jobNoInput.value.trim();
    if(!val){ jobNoInput.classList.remove('is-invalid'); jobNoFeedback.style.display='none'; isJobNoValid=true; saveBtn.disabled=false; return; }
    fetch('?action=check_job&job_no='+encodeURIComponent(val)).then(r=>r.text()).then(data=>{
        if(data==='exists'){ jobNoInput.classList.add('is-invalid'); jobNoFeedback.style.display='block'; isJobNoValid=false; saveBtn.disabled=true; }
        else { jobNoInput.classList.remove('is-invalid'); jobNoFeedback.style.display='none'; isJobNoValid=true; saveBtn.disabled=false; }
    }).catch(()=>{});
}
jobNoInput.addEventListener('input',checkJobNoDuplicate);
if(jobNoInput.value.trim()) checkJobNoDuplicate();

document.getElementById('jobForm').addEventListener('submit', function(e){
    if(!isJobNoValid){ e.preventDefault(); alert('Duplicate job number!'); }
});
</script>
</body>
</html>