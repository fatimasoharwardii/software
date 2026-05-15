<?php 
$page_identifier = 'jobs/list.php';
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

// Check if updated_at column exists (optional, not security critical)
$has_updated_at = $conn->query("SHOW COLUMNS FROM jobs LIKE 'updated_at'")->num_rows > 0;
// Ensure serial_no column exists
$conn->query("ALTER TABLE jobs ADD COLUMN IF NOT EXISTS serial_no VARCHAR(50) DEFAULT NULL AFTER job_no");

// Handle bulk status update (company‑aware)
if (isset($_POST['bulk_status_update']) && isset($_POST['selected_jobs']) && isset($_POST['new_status'])) {
    $selected_jobs = array_map('intval', $_POST['selected_jobs']);
    $new_status = trim($_POST['new_status']);
    $cmt_party = isset($_POST['cmt_party']) ? trim($_POST['cmt_party']) : '';
    $current_datetime = date('Y-m-d H:i:s');
    
    if (!empty($selected_jobs)) {
        // First verify that all selected jobs belong to current company
        $placeholders = implode(',', array_fill(0, count($selected_jobs), '?'));
        $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM jobs WHERE id IN ($placeholders) AND company_id = ?");
        $params = array_merge($selected_jobs, [$company_id]);
        $types = str_repeat('i', count($selected_jobs)) . 'i';
        $check_stmt->bind_param($types, ...$params);
        $check_stmt->execute();
        $check = $check_stmt->get_result()->fetch_assoc();
        if ($check['cnt'] == count($selected_jobs)) {
            $id_list = implode(',', $selected_jobs);
            $update_fields = "status = ?, updated_at = ?";
            $update_params = [$new_status, $current_datetime];
            $update_types = "ss";
            if ($new_status == 'CMT' && !empty($cmt_party)) {
                $update_fields .= ", cmt_party = ?";
                $update_params[] = $cmt_party;
                $update_types .= "s";
            }
            $update_sql = "UPDATE jobs SET $update_fields WHERE id IN ($id_list) AND company_id = ?";
            $update_params[] = $company_id;
            $update_types .= "i";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param($update_types, ...$update_params);
            if ($update_stmt->execute()) {
                $success_message = "Status updated successfully for selected jobs!";
                if ($new_status == 'CMT' && !empty($cmt_party)) {
                    $success_message .= " CMT Party: " . htmlspecialchars($cmt_party);
                }
            } else {
                $error_message = "Error updating status: " . $update_stmt->error;
            }
            $update_stmt->close();
        } else {
            $error_message = "Some selected jobs do not belong to your company. Update aborted.";
        }
        $check_stmt->close();
    }
}

// Fetch all parties for CMT dropdown (only current company)
$parties_stmt = $conn->prepare("SELECT account_name FROM accounts WHERE company_id = ? ORDER BY account_name");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties_result = $parties_stmt->get_result();

// Fetch distinct values for multi-select dropdowns (only current company)
$distinct_job_no = [];
$stmt = $conn->prepare("SELECT DISTINCT job_no FROM jobs WHERE company_id = ? ORDER BY job_no");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_job_no[] = $row['job_no'];
$stmt->close();

$distinct_serial_no = [];
$stmt = $conn->prepare("SELECT DISTINCT serial_no FROM jobs WHERE serial_no IS NOT NULL AND serial_no != '' AND company_id = ? ORDER BY serial_no");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_serial_no[] = $row['serial_no'];
$stmt->close();

$distinct_design = [];
$stmt = $conn->prepare("SELECT DISTINCT design_name FROM jobs WHERE company_id = ? ORDER BY design_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_design[] = $row['design_name'];
$stmt->close();

$distinct_brand = [];
$stmt = $conn->prepare("SELECT DISTINCT brand_name FROM jobs WHERE brand_name IS NOT NULL AND brand_name != '' AND company_id = ? ORDER BY brand_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_brand[] = $row['brand_name'];
$stmt->close();

$distinct_vendor = [];
$stmt = $conn->prepare("SELECT DISTINCT embroidery_vendor_name FROM jobs WHERE embroidery_vendor_name IS NOT NULL AND embroidery_vendor_name != '' AND company_id = ? ORDER BY embroidery_vendor_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_vendor[] = $row['embroidery_vendor_name'];
$stmt->close();

$distinct_size = [];
$stmt = $conn->prepare("SELECT DISTINCT size FROM jobs WHERE size IS NOT NULL AND size != '' AND company_id = ? ORDER BY size");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_size[] = $row['size'];
$stmt->close();

$distinct_fabric = [];
$stmt = $conn->prepare("SELECT DISTINCT fabric_name FROM jobs WHERE fabric_name IS NOT NULL AND fabric_name != '' AND company_id = ? ORDER BY fabric_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $distinct_fabric[] = $row['fabric_name'];
$stmt->close();

// Filter values (arrays for multi-select fields – from GET, already safe for use in IN clause after escaping)
$filter_job_no = isset($_GET['job_no']) && is_array($_GET['job_no']) ? $_GET['job_no'] : [];
$filter_serial_no = isset($_GET['serial_no']) && is_array($_GET['serial_no']) ? $_GET['serial_no'] : [];
$filter_design = isset($_GET['design']) && is_array($_GET['design']) ? $_GET['design'] : [];
$filter_brand = isset($_GET['brand']) && is_array($_GET['brand']) ? $_GET['brand'] : [];
$filter_vendor = isset($_GET['vendor']) && is_array($_GET['vendor']) ? $_GET['vendor'] : [];
$filter_size = isset($_GET['size']) && is_array($_GET['size']) ? $_GET['size'] : [];
$filter_fabric = isset($_GET['fabric']) && is_array($_GET['fabric']) ? $_GET['fabric'] : [];
$filter_status = isset($_GET['status']) && is_array($_GET['status']) ? $_GET['status'] : [];
$filter_from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$filter_to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

$where_conditions = ["company_id = ?"];
$params = [$company_id];
$types = "i";

if (!empty($filter_job_no)) {
    $placeholders = implode(',', array_fill(0, count($filter_job_no), '?'));
    $where_conditions[] = "job_no IN ($placeholders)";
    $params = array_merge($params, $filter_job_no);
    $types .= str_repeat('s', count($filter_job_no));
}
if (!empty($filter_serial_no)) {
    $placeholders = implode(',', array_fill(0, count($filter_serial_no), '?'));
    $where_conditions[] = "serial_no IN ($placeholders)";
    $params = array_merge($params, $filter_serial_no);
    $types .= str_repeat('s', count($filter_serial_no));
}
if (!empty($filter_design)) {
    $placeholders = implode(',', array_fill(0, count($filter_design), '?'));
    $where_conditions[] = "design_name IN ($placeholders)";
    $params = array_merge($params, $filter_design);
    $types .= str_repeat('s', count($filter_design));
}
if (!empty($filter_brand)) {
    $placeholders = implode(',', array_fill(0, count($filter_brand), '?'));
    $where_conditions[] = "brand_name IN ($placeholders)";
    $params = array_merge($params, $filter_brand);
    $types .= str_repeat('s', count($filter_brand));
}
if (!empty($filter_vendor)) {
    $placeholders = implode(',', array_fill(0, count($filter_vendor), '?'));
    $where_conditions[] = "embroidery_vendor_name IN ($placeholders)";
    $params = array_merge($params, $filter_vendor);
    $types .= str_repeat('s', count($filter_vendor));
}
if (!empty($filter_size)) {
    $placeholders = implode(',', array_fill(0, count($filter_size), '?'));
    $where_conditions[] = "size IN ($placeholders)";
    $params = array_merge($params, $filter_size);
    $types .= str_repeat('s', count($filter_size));
}
if (!empty($filter_fabric)) {
    $placeholders = implode(',', array_fill(0, count($filter_fabric), '?'));
    $where_conditions[] = "fabric_name IN ($placeholders)";
    $params = array_merge($params, $filter_fabric);
    $types .= str_repeat('s', count($filter_fabric));
}
if (!empty($filter_status)) {
    $placeholders = implode(',', array_fill(0, count($filter_status), '?'));
    $where_conditions[] = "status IN ($placeholders)";
    $params = array_merge($params, $filter_status);
    $types .= str_repeat('s', count($filter_status));
}
if (!empty($filter_from_date)) {
    $where_conditions[] = "job_date >= ?";
    $params[] = $filter_from_date;
    $types .= "s";
}
if (!empty($filter_to_date)) {
    $where_conditions[] = "job_date <= ?";
    $params[] = $filter_to_date;
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where_conditions);
$sql = "SELECT * FROM jobs $where_sql ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Job List | Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original) */
    :root {
        --primary: #F39C12;
        --primary-hover: #e08e0b;
        --light-bg: #f9fafb;
        --border: #e5e7eb;
        --text-dark: #1f2937;
        --text-muted: #6b7280;
        --success: #10b981;
        --danger: #ef4444;
        --info: #3b82f6;
        --warning: #f59e0b;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; color: var(--text-dark); font-size: 0.85rem; }
    .main-container { margin-left: 17%; padding: 20px 28px; transition: margin-left 0.2s ease; min-height: 100vh; }
    .page-header { margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .page-header h4 { color: var(--primary); font-size: 1.3rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 8px; }
    .page-header h4 i { font-size: 1.4rem; }
    .card { background: white; border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
    .filter-card { background: white; border-radius: 16px; padding: 18px 20px; margin-bottom: 22px; border: 1px solid var(--border); }
    .filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; }
    .filter-group { flex: 1; min-width: 160px; }
    .filter-group label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; display: block; }
    .multi-select-dropdown { position: relative; width: 100%; }
    .multi-select-btn { width: 100%; padding: 6px 10px; font-size: 0.75rem; border-radius: 12px; border: 1px solid var(--border); background: white; text-align: left; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
    .multi-select-btn span { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .multi-select-dropdown .dropdown-content { position: absolute; top: 100%; left: 0; right: auto; background: white; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1050; max-height: 250px; overflow-y: auto; display: none; min-width: 200px; }
    .filter-group:last-child .multi-select-dropdown .dropdown-content,
    .filter-group:nth-last-child(2) .multi-select-dropdown .dropdown-content { left: auto; right: 0; }
    .multi-select-dropdown .dropdown-content.show { display: block; }
    .multi-select-dropdown .dropdown-content .form-check { padding: 8px 12px; margin: 0; display: flex; align-items: center; gap: 8px; }
    .multi-select-dropdown .dropdown-content .form-check:hover { background: #fef5e7; }
    .multi-select-dropdown .dropdown-content .form-check-input { margin-top: 0; width: 15px; height: 15px; cursor: pointer; margin-left: 14px; }
    .multi-select-dropdown .dropdown-content .form-check-label { font-size: 0.75rem; font-weight: normal; text-transform: none; color: var(--text-dark); cursor: pointer; }
    .filter-buttons { display: flex; gap: 8px; align-items: center; margin-top: 28px; }
    .btn-filter { background: var(--primary); color: white; border: none; padding: 6px 16px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
    .btn-reset { background: #e2e8f0; color: #1e293b; border: none; padding: 6px 16px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
    input[type="date"] { width: 100%; padding: 6px 10px; font-size: 0.75rem; border-radius: 12px; border: 1px solid var(--border); background: white; font-family: inherit; }
    .table-responsive { overflow-x: auto; }
    .table { margin-bottom: 0; font-size: 0.75rem; border-collapse: collapse; width: 100%; }
    .table thead th { background: #f8fafc; color: #1e293b; font-weight: 600; font-size: 0.7rem; letter-spacing: 0.3px; text-transform: uppercase; padding: 10px 8px; border-bottom: 1px solid var(--border); white-space: nowrap; }
    .table tbody td { padding: 8px 8px; vertical-align: middle; border-bottom: 1px solid #edf2f7; font-size: 0.73rem; color: #0f172a; font-weight: 500; white-space: nowrap; }
    .table tbody tr:hover { background-color: #fffbeb; }
    .job-number { font-weight: 700; color: var(--primary); font-size: 0.78rem; }
    .serial-number { font-family: 'SF Mono', monospace; font-size: 0.7rem; background: #f1f5f9; padding: 2px 8px; border-radius: 30px; color: #334155; }
    .status-badge { padding: 3px 10px; border-radius: 40px; font-size: 0.68rem; font-weight: 600; display: inline-block; min-width: 85px; text-align: center; text-transform: uppercase; letter-spacing: 0.2px; }
    .status-Embroidery { background: #e0f2fe; color: #0c4a6e; border-left: 2px solid #0ea5e9; }
    .status-Backup { background: #e2e8f0; color: #1e293b; border-left: 2px solid #64748b; }
    .status-Ready { background: #dcfce7; color: #14532d; border-left: 2px solid #10b981; }
    .status-Stitching { background: #ffedd5; color: #9a3412; border-left: 2px solid #f97316; }
    .status-Incomplete { background: #fef9c3; color: #854d0e; border-left: 2px solid #eab308; }
    .status-Checking { background: #d1fae5; color: #064e3b; border-left: 2px solid #14b8a6; }
    .status-Close { background: #fee2e2; color: #7f1d1d; border-left: 2px solid #ef4444; }
    .status-CMT { background: #f3e8ff; color: #4c1d95; border-left: 2px solid #8b5cf6; }
    .status-update-date { font-size: 0.6rem; color: #5b6e8c; display: block; margin-top: 4px; background: none; padding: 0; white-space: normal; }
    .duration-badge { padding: 2px 8px; border-radius: 30px; font-size: 0.65rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; color: #1e293b; }
    .duration-fresh { background: #dcfce7; color: #166534; }
    .duration-week { background: #ffedd5; color: #9a3412; }
    .duration-old { background: #fee2e2; color: #b91c1c; }
    .duration-normal { background: #e0f2fe; color: #0369a1; }
    .action-buttons { display: flex; gap: 6px; }
    .btn-sm { padding: 4px 10px; font-size: 0.68rem; border-radius: 30px; font-weight: 500; }
    .btn-warning { background: #fef3c7; border: none; color: #b45309; }
    .btn-warning:hover { background: #fde68a; color: #92400e; }
    .btn-info { background: #e0f2fe; border: none; color: #0c4a6e; }
    .btn-info:hover { background: #bae6fd; }
    .bulk-action-bar { background: white; padding: 10px 20px; border-radius: 50px; margin-bottom: 20px; display: none; align-items: center; gap: 16px; border: 1px solid var(--border); flex-wrap: wrap; }
    .bulk-action-bar.show { display: flex; }
    .form-select, .form-control { font-size: 0.75rem; border-radius: 40px; border-color: #cbd5e1; padding: 5px 12px; }
    .badge.bg-primary { background: var(--primary) !important; padding: 5px 14px; font-size: 0.7rem; font-weight: 600; border-radius: 30px; }
    .btn-primary { background: var(--primary); border-radius: 40px; padding: 5px 16px; font-size: 0.72rem; font-weight: 600; }
    .btn-outline-primary { border-radius: 40px; font-size: 0.72rem; padding: 5px 14px; }
    .btn-secondary { border-radius: 40px; font-size: 0.72rem; }
    .btn-success { border-radius: 40px; font-size: 0.75rem; padding: 6px 16px; }
    .btn-export { background: #1e293b; color: white; border: none; padding: 6px 16px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
    .job-checkbox, .form-check-input { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }
    .alert { border-radius: 20px; font-size: 0.75rem; padding: 8px 16px; }
    .modal-content { border-radius: 20px; }
    .modal-header { background: var(--primary); border-radius: 20px 20px 0 0; padding: 12px 20px; }
    .modal-title { font-size: 0.95rem; font-weight: 600; }
    @media (max-width: 1300px) { .main-container { margin-left: 12%; padding: 18px; } }
    @media (max-width: 1024px) { .main-container { margin-left: 0; padding: 16px; } }
    @media (max-width: 640px) {
        .table thead th { font-size: 0.65rem; padding: 6px 4px; }
        .table tbody td { padding: 6px 4px; font-size: 0.68rem; }
        .status-badge { min-width: 68px; font-size: 0.62rem; padding: 2px 5px; }
        .duration-badge { font-size: 0.58rem; padding: 2px 6px; }
        .serial-number { font-size: 0.6rem; }
        .job-number { font-size: 0.72rem; }
        .btn-sm { padding: 3px 8px; font-size: 0.62rem; }
        .multi-select-dropdown .dropdown-content { min-width: 180px; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">

<div class="page-header">
    <h4><i class="fas fa-briefcase me-2"></i>ALL JOBS</h4>
    <div>
        <button class="btn btn-outline-primary me-2" id="toggleSelectionBtn" onclick="toggleSelectionMode()">
            <i class="fas fa-check-double"></i> SELECT MULTIPLE
        </button>
        <a href="add.php" class="btn btn-success">
            <i class="fas fa-plus"></i> ADD JOB
        </a>
    </div>
</div>

<?php if(isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if(isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Filter Card -->
<div class="filter-card">
    <form method="GET" action="" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label><i class="fas fa-hashtag"></i> Job No</label>
                <div class="multi-select-dropdown" data-field="job_no">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_job_no as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="job_no_<?= md5($val) ?>" <?= in_array($val, $filter_job_no) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="job_no_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-barcode"></i> Serial No</label>
                <div class="multi-select-dropdown" data-field="serial_no">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_serial_no as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="serial_no_<?= md5($val) ?>" <?= in_array($val, $filter_serial_no) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="serial_no_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-palette"></i> Design</label>
                <div class="multi-select-dropdown" data-field="design">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_design as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="design_<?= md5($val) ?>" <?= in_array($val, $filter_design) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="design_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-trademark"></i> Brand</label>
                <div class="multi-select-dropdown" data-field="brand">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_brand as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="brand_<?= md5($val) ?>" <?= in_array($val, $filter_brand) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="brand_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="filter-row mt-2">
            <div class="filter-group">
                <label><i class="fas fa-building"></i> Vendor</label>
                <div class="multi-select-dropdown" data-field="vendor">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_vendor as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="vendor_<?= md5($val) ?>" <?= in_array($val, $filter_vendor) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vendor_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-ruler"></i> Size</label>
                <div class="multi-select-dropdown" data-field="size">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_size as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="size_<?= md5($val) ?>" <?= in_array($val, $filter_size) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="size_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tshirt"></i> Fabric</label>
                <div class="multi-select-dropdown" data-field="fabric">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <?php foreach($distinct_fabric as $val): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($val) ?>" id="fabric_<?= md5($val) ?>" <?= in_array($val, $filter_fabric) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fabric_<?= md5($val) ?>"><?= htmlspecialchars($val) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-flag-checkered"></i> Status</label>
                <div class="multi-select-dropdown" data-field="status">
                    <button type="button" class="multi-select-btn"><span>All</span> <i class="fas fa-chevron-down"></i></button>
                    <div class="dropdown-content">
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Embroidery" id="status_embroidery" <?= in_array('Embroidery', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_embroidery">Embroidery</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Backup" id="status_backup" <?= in_array('Backup', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_backup">Backup</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Ready" id="status_ready" <?= in_array('Ready', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_ready">Ready</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Stitching" id="status_stitching" <?= in_array('Stitching', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_stitching">Stitching</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Incomplete" id="status_incomplete" <?= in_array('Incomplete', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_incomplete">Incomplete</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Checking" id="status_checking" <?= in_array('Checking', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_checking">Checking</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="Close" id="status_close" <?= in_array('Close', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_close">Close</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" value="CMT" id="status_cmt" <?= in_array('CMT', $filter_status) ? 'checked' : '' ?>><label class="form-check-label" for="status_cmt">CMT</label></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="filter-row mt-2">
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> From Date</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($filter_from_date) ?>" class="form-control">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> To Date</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($filter_to_date) ?>" class="form-control">
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
                <a href="?" class="btn-reset"><i class="fas fa-undo-alt"></i> Reset</a>
                <button type="button" class="btn-export" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> Export PDF</button>
            </div>
        </div>
        <div id="hidden-inputs-container"></div>
    </form>
</div>

<!-- Bulk Action Bar -->
<div class="bulk-action-bar" id="bulkActionBar">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="selectAllCheckbox" onchange="selectAllJobs(this)">
        <label class="form-check-label" for="selectAllCheckbox"><strong>SELECT ALL</strong></label>
    </div>
    <div class="flex-grow-1"></div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span id="selectedCount" class="badge bg-primary p-2">0 SELECTED</span>
        <select class="form-select" id="bulkStatusSelect" style="width: 180px;">
            <option value="">CHANGE STATUS</option>
            <option value="Embroidery">EMBROIDERY</option>
            <option value="Backup">BACKUP</option>
            <option value="Ready">READY</option>
            <option value="Stitching">STITCHING</option>
            <option value="Incomplete">INCOMPLETE</option>
            <option value="Checking">CHECKING</option>
            <option value="Close">CLOSE</option>
            <option value="CMT">CMT (CUT-MAKE-TRIM)</option>
        </select>
        <button class="btn btn-primary" onclick="checkAndApplyBulkStatus()"><i class="fas fa-save"></i> APPLY</button>
        <button class="btn btn-secondary" onclick="cancelSelection()"><i class="fas fa-times"></i> CANCEL</button>
    </div>
</div>

<div class="card">
    <form method="POST" id="bulkUpdateForm">
        <input type="hidden" name="bulk_status_update" value="1">
        <input type="hidden" name="new_status" id="newStatusInput">
        <input type="hidden" name="cmt_party" id="cmtPartyInput">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th width="40" class="selection-col" style="display: none;"><input class="form-check-input" type="checkbox" onchange="toggleHeaderCheckbox(this)"></th>
                        <th>JOB NO</th>
                        <th>SERIAL NO</th>
                        <th>DESIGN</th>
                        <th>BRAND</th>
                        <th>EMBROIDERY VENDOR</th>
                        <th>SIZE</th>
                        <th>FABRIC</th>
                        <th>QTY</th>
                        <th>START DATE</th>
                        <th>DURATION</th>
                        <th>STATUS</th>
                        <th>ACTION</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()):
                        $status = strtoupper($row['status'] ?? 'EMBROIDERY');
                        $status_class = 'status-' . str_replace(' ', '', ucfirst(strtolower($status)));
                        $job_date = $row['job_date'] ?? $row['created_at'] ?? date('Y-m-d');
                        $current_date = new DateTime();
                        $job_date_obj = new DateTime($job_date);
                        $interval = $current_date->diff($job_date_obj);
                        $days_passed = $interval->days;
                        if($days_passed == 0) { $duration_display = "TODAY"; $duration_class = "duration-fresh"; }
                        elseif($days_passed == 1) { $duration_display = "1 DAY AGO"; $duration_class = "duration-fresh"; }
                        elseif($days_passed <= 7) { $duration_display = $days_passed . " DAYS AGO"; $duration_class = "duration-normal"; }
                        elseif($days_passed <= 30) {
                            $weeks = floor($days_passed / 7);
                            $remaining_days = $days_passed % 7;
                            $duration_display = ($weeks?$weeks." WEEK".($weeks>1?"S":"")." ":"").($remaining_days?$remaining_days." DAY".($remaining_days>1?"S":""):"")." AGO";
                            $duration_class = "duration-week";
                        } else {
                            $months = floor($days_passed / 30);
                            $remaining_days = $days_passed % 30;
                            $duration_display = ($months?$months." MONTH".($months>1?"S":"")." ":"").($remaining_days?$remaining_days." DAY".($remaining_days>1?"S":""):"")." AGO";
                            $duration_class = "duration-old";
                        }
                        $formatted_date = date('d-m-Y', strtotime($job_date));
                        $status_update_date = '';
                        if($has_updated_at && !empty($row['updated_at'])) {
                            $update_datetime = new DateTime($row['updated_at']);
                            $status_update_date = $update_datetime->format('d-m-Y H:i');
                        }
                        $serial_display = !empty($row['serial_no']) ? strtoupper(htmlspecialchars($row['serial_no'])) : '—';
                    ?>
                    <tr id="job-row-<?php echo $row['id']; ?>">
                        <td class="selection-col" style="display: none;"><input class="form-check-input job-checkbox" type="checkbox" name="selected_jobs[]" value="<?php echo $row['id']; ?>" onchange="updateSelectedCount()"></td>
                        <td><strong class="job-number"><?php echo strtoupper(htmlspecialchars($row['job_no'])); ?></strong></td>
                        <td><span class="serial-number"><?php echo $serial_display; ?></span></td>
                        <td><?php echo strtoupper(htmlspecialchars($row['design_name'])); ?></td>
                        <td><?php echo strtoupper(htmlspecialchars($row['brand_name'] ?? 'N/A')); ?></td>
                        <td><?php echo strtoupper(htmlspecialchars($row['embroidery_vendor_name'] ?? 'N/A')); ?></td>
                        <td><?php echo strtoupper(htmlspecialchars($row['size'] ?? 'N/A')); ?></td>
                        <td><?php echo strtoupper(htmlspecialchars($row['fabric_name'] ?? 'N/A')); ?></td>
                        <td><?php echo $row['quantity']; ?></td>
                        <td class="job-date"><i class="fas fa-calendar-alt"></i> <?php echo $formatted_date; ?></td>
                        <td><span class="duration-badge <?php echo $duration_class; ?>" title="Job created on <?php echo $formatted_date; ?>"><i class="fas fa-clock"></i> <?php echo $duration_display; ?></span></td>
                        <td>
                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $status; ?></span>
                            <?php if($status_update_date): ?><span class="status-update-date"><i class="fas fa-history"></i> UPDATED: <?php echo $status_update_date; ?></span><?php endif; ?>
                        </td>
                        <td><div class="action-buttons"><a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm" title="EDIT"><i class="fas fa-edit"></i></a><a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm" title="VIEW"><i class="fas fa-eye"></i></a></div></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="13" class="text-center py-4"><i class="fas fa-folder-open fa-2x text-muted mb-2"></i><p class="text-muted">NO JOBS FOUND. CLICK "ADD JOB" TO CREATE ONE.</p><a href="add.php" class="btn btn-success btn-sm mt-2"><i class="fas fa-plus"></i> ADD JOB</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

</div>

<!-- CMT Modal -->
<div class="modal fade" id="bulkCMTModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-handshake"></i> SET CMT PARTY FOR MULTIPLE JOBS</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> YOU ARE ABOUT TO CHANGE STATUS TO CMT FOR <strong id="bulkJobCount">0</strong> SELECTED JOB(S).</div>
                <div class="mb-3">
                    <label for="bulkCmtPartyName" class="form-label"><i class="fas fa-building"></i> CMT PARTY NAME <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="bulkCmtPartyName" list="party-list" required placeholder="SELECT OR TYPE CMT PARTY NAME" style="text-transform: uppercase;">
                    <div class="form-text text-muted"><i class="fas fa-info-circle"></i> THIS PARTY WILL BE ASSIGNED TO ALL SELECTED JOBS</div>
                    <div id="bulkCmtWarning" class="text-danger" style="font-size: 0.75rem; margin-top: 5px; display: none;"><i class="fas fa-exclamation-triangle"></i> PARTY NOT FOUND! PLEASE ADD THIS PARTY FIRST IN PARTIES SECTION.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> CANCEL</button>
                <button type="button" class="btn btn-primary" onclick="submitBulkCMT()"><i class="fas fa-save"></i> UPDATE TO CMT</button>
            </div>
        </div>
    </div>
</div>

<datalist id="party-list">
    <?php if($parties_result && $parties_result->num_rows > 0): $parties_result->data_seek(0); while($party = $parties_result->fetch_assoc()): ?>
        <option value="<?= strtoupper(htmlspecialchars($party['account_name'])) ?>">
    <?php endwhile; endif; ?>
</datalist>

<script>
// Multi-select dropdown logic (unchanged)
document.querySelectorAll('.multi-select-dropdown').forEach(dropdown => {
    const btn = dropdown.querySelector('.multi-select-btn');
    const content = dropdown.querySelector('.dropdown-content');
    const checkboxes = content.querySelectorAll('input[type="checkbox"]');
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.multi-select-dropdown .dropdown-content').forEach(c => { if(c !== content) c.classList.remove('show'); });
        content.classList.toggle('show');
    });
    function updateButtonText() {
        const selected = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
        const span = btn.querySelector('span') || btn;
        if(selected.length === 0) span.innerHTML = 'All';
        else if(selected.length === 1) span.innerHTML = selected[0];
        else span.innerHTML = selected.length + ' selected';
    }
    checkboxes.forEach(cb => cb.addEventListener('change', updateButtonText));
    updateButtonText();
});
document.addEventListener('click', function(e) {
    if(!e.target.closest('.multi-select-dropdown')) {
        document.querySelectorAll('.multi-select-dropdown .dropdown-content').forEach(c => c.classList.remove('show'));
    }
});
const filterForm = document.getElementById('filterForm');
if(filterForm) {
    filterForm.addEventListener('submit', function(e) {
        const container = document.getElementById('hidden-inputs-container');
        container.innerHTML = '';
        const fields = ['job_no', 'serial_no', 'design', 'brand', 'vendor', 'size', 'fabric', 'status'];
        fields.forEach(field => {
            const dropdown = document.querySelector(`.multi-select-dropdown[data-field="${field}"]`);
            if(dropdown) {
                const selected = Array.from(dropdown.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                if(selected.length > 0) {
                    selected.forEach(value => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = `${field}[]`;
                        hidden.value = value;
                        container.appendChild(hidden);
                    });
                }
            }
        });
    });
}
</script>

<script>
// Bulk update and selection mode (unchanged)
let selectionMode = false;
let pendingBulkStatus = null;
function checkAndApplyBulkStatus() {
    const selectedStatus = document.getElementById('bulkStatusSelect').value;
    const selectedJobs = document.querySelectorAll('.job-checkbox:checked');
    if(selectedJobs.length === 0) { alert('PLEASE SELECT AT LEAST ONE JOB.'); return; }
    if(!selectedStatus) { alert('PLEASE SELECT A STATUS.'); return; }
    if(selectedStatus === 'CMT') {
        document.getElementById('bulkJobCount').innerText = selectedJobs.length;
        document.getElementById('bulkCmtPartyName').value = '';
        document.getElementById('bulkCmtWarning').style.display = 'none';
        new bootstrap.Modal(document.getElementById('bulkCMTModal')).show();
        pendingBulkStatus = selectedStatus;
    } else {
        if(confirm('ARE YOU SURE YOU WANT TO CHANGE STATUS OF ' + selectedJobs.length + ' SELECTED JOB(S) TO ' + selectedStatus.toUpperCase() + '?')) {
            document.getElementById('newStatusInput').value = selectedStatus;
            document.getElementById('cmtPartyInput').value = '';
            document.getElementById('bulkUpdateForm').submit();
        }
    }
}
function submitBulkCMT() {
    let cmtParty = document.getElementById('bulkCmtPartyName').value.trim();
    const selectedJobs = document.querySelectorAll('.job-checkbox:checked');
    if(!cmtParty) { alert('PLEASE ENTER CMT PARTY NAME.'); return; }
    cmtParty = cmtParty.toUpperCase();
    document.getElementById('bulkCmtPartyName').value = cmtParty;
    const partyList = document.getElementById('party-list');
    let partyExists = false;
    for(let i = 0; i < partyList.options.length; i++) {
        if(partyList.options[i].value === cmtParty) { partyExists = true; break; }
    }
    if(!partyExists) { document.getElementById('bulkCmtWarning').style.display = 'block'; return; }
    if(confirm('ARE YOU SURE YOU WANT TO CHANGE STATUS OF ' + selectedJobs.length + ' SELECTED JOB(S) TO CMT WITH PARTY: ' + cmtParty + '?')) {
        document.getElementById('newStatusInput').value = 'CMT';
        document.getElementById('cmtPartyInput').value = cmtParty;
        document.getElementById('bulkUpdateForm').submit();
    }
}
document.getElementById('bulkCmtPartyName')?.addEventListener('input', function() {
    let partyName = this.value.trim().toUpperCase();
    this.value = partyName;
    const partyList = document.getElementById('party-list');
    let partyExists = false;
    for(let i = 0; i < partyList.options.length; i++) {
        if(partyList.options[i].value === partyName) { partyExists = true; break; }
    }
    if(partyName && !partyExists) { document.getElementById('bulkCmtWarning').style.display = 'block'; }
    else { document.getElementById('bulkCmtWarning').style.display = 'none'; }
});
function toggleSelectionMode() {
    selectionMode = !selectionMode;
    const selectionCols = document.querySelectorAll('.selection-col');
    const toggleBtn = document.getElementById('toggleSelectionBtn');
    selectionCols.forEach(col => col.style.display = selectionMode ? 'table-cell' : 'none');
    if(selectionMode) {
        toggleBtn.innerHTML = '<i class="fas fa-times"></i> CANCEL SELECTION';
        toggleBtn.classList.remove('btn-outline-primary');
        toggleBtn.classList.add('btn-outline-danger');
        document.getElementById('bulkActionBar').classList.add('show');
    } else {
        toggleBtn.innerHTML = '<i class="fas fa-check-double"></i> SELECT MULTIPLE';
        toggleBtn.classList.remove('btn-outline-danger');
        toggleBtn.classList.add('btn-outline-primary');
        document.getElementById('bulkActionBar').classList.remove('show');
        document.querySelectorAll('.job-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAllCheckbox').checked = false;
        updateSelectedCount();
    }
}
function selectAllJobs(checkbox) {
    document.querySelectorAll('.job-checkbox').forEach(cb => cb.checked = checkbox.checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const selected = document.querySelectorAll('.job-checkbox:checked').length;
    document.getElementById('selectedCount').innerHTML = selected + ' SELECTED';
    const totalCheckboxes = document.querySelectorAll('.job-checkbox').length;
    const headerCheckbox = document.querySelector('th .form-check-input');
    if(headerCheckbox) {
        if(selected === 0) { headerCheckbox.checked = false; headerCheckbox.indeterminate = false; }
        else if(selected === totalCheckboxes) { headerCheckbox.checked = true; headerCheckbox.indeterminate = false; }
        else { headerCheckbox.indeterminate = true; }
    }
}
function toggleHeaderCheckbox(checkbox) {
    document.querySelectorAll('.job-checkbox').forEach(cb => cb.checked = checkbox.checked);
    updateSelectedCount();
}
function cancelSelection() { if(selectionMode) toggleSelectionMode(); }
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.selection-col').forEach(col => col.style.display = 'none');
});

function exportToPDF() {
    const params = new URLSearchParams();
    const fields = ['job_no', 'serial_no', 'design', 'brand', 'vendor', 'size', 'fabric', 'status'];
    fields.forEach(field => {
        const dropdown = document.querySelector(`.multi-select-dropdown[data-field="${field}"]`);
        if (dropdown) {
            const checkboxes = dropdown.querySelectorAll('input[type="checkbox"]:checked');
            checkboxes.forEach(cb => {
                params.append(`${field}[]`, cb.value);
            });
        }
    });
    const fromDate = document.querySelector('input[name="from_date"]').value;
    const toDate = document.querySelector('input[name="to_date"]').value;
    if (fromDate) params.append('from_date', fromDate);
    if (toDate) params.append('to_date', toDate);
    window.open('export.php?' + params.toString(), '_blank');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>