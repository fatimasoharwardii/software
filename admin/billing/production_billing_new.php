<?php
$page_identifier = 'billing/production_billing_new.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$can_edit = hasAccess($current_page, true);
$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['jobs', 'claims', 'manual_costing'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Helper to build company filter
function companyFilter($alias = '') {
    global $company_id;
    $prefix = $alias ? $alias . '.' : '';
    return " AND {$prefix}company_id = " . (int)$company_id;
}

// ==================== Get total claim quantity for a job (with company filter) ====================
function getTotalClaimQty($conn, $job_no, $company_id) {
    $stmt = $conn->prepare("SELECT SUM(qty) as total_claim FROM claims WHERE job_no = ? AND company_id = ?");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return floatval($row['total_claim']);
    }
    return 0;
}

// ==================== Get exact total manual rate from costing table (with company filter) ====================
function getPerPieceRate($conn, $job_no, $company_id) {
    $stmt = $conn->prepare("SELECT SUM(manual_rate) as total_rate FROM manual_costing WHERE job_no = ? AND company_id = ?");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $total_rate = floatval($row['total_rate']);
        if ($total_rate > 0) return $total_rate;
    }
    return 0;
}

// ----- FILTER LOGIC -----
$selected_brands = isset($_GET['brand']) ? (array)$_GET['brand'] : [];
$selected_sizes = isset($_GET['size']) ? (array)$_GET['size'] : [];

// Base query for jobs with status 'Checking' AND company_id = current company
$base_sql = "SELECT * FROM jobs WHERE status = 'Checking'" . companyFilter();
if (!empty($selected_brands)) {
    $brand_list = implode("','", array_map(function($b) use ($conn) {
        return mysqli_real_escape_string($conn, $b);
    }, $selected_brands));
    $base_sql .= " AND brand_name IN ('$brand_list')";
}
if (!empty($selected_sizes)) {
    $size_list = implode("','", array_map(function($s) use ($conn) {
        return mysqli_real_escape_string($conn, $s);
    }, $selected_sizes));
    $base_sql .= " AND size IN ('$size_list')";
}
$base_sql .= " ORDER BY job_no ASC";

$jobs_result = $conn->query($base_sql);
$total_jobs = $jobs_result ? $jobs_result->num_rows : 0;
$total_qty = 0;
$total_claim_qty = 0;
$total_bill_qty = 0;
$total_amount_sum = 0;
$jobs_data = [];

if ($jobs_result) {
    while ($job = $jobs_result->fetch_assoc()) {
        $total_qty += intval($job['quantity']);
        $claim_qty = getTotalClaimQty($conn, $job['job_no'], $company_id);
        $total_claim_qty += $claim_qty;
        $bill_qty = $job['quantity'] - $claim_qty;
        if ($bill_qty < 0) $bill_qty = 0;
        $total_bill_qty += $bill_qty;

        $job['per_piece_rate'] = getPerPieceRate($conn, $job['job_no'], $company_id);
        $job['claim_qty'] = $claim_qty;
        $job['bill_qty'] = $bill_qty;
        $job['amount'] = $bill_qty * $job['per_piece_rate'];
        $total_amount_sum += $job['amount'];
        $jobs_data[] = $job;
    }
}

// Fetch distinct brands and sizes for filter (only from current company)
$brands_result = $conn->query("SELECT DISTINCT brand_name FROM jobs WHERE brand_name IS NOT NULL AND brand_name != '' AND company_id = $company_id ORDER BY brand_name");
$brands = [];
while ($row = $brands_result->fetch_assoc()) $brands[] = $row['brand_name'];

$sizes_result = $conn->query("SELECT DISTINCT size FROM jobs WHERE size IS NOT NULL AND size != '' AND company_id = $company_id ORDER BY size");
$sizes = [];
while ($row = $sizes_result->fetch_assoc()) $sizes[] = $row['size'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Production Billing | Stitching Jobs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        :root {
            --primary: #F39C12;
            --primary-light: #FEF5E7;
            --primary-dark: #E67E22;
            --success: #27ae60;
            --success-light: #e8f5e9;
            --danger: #e74c3c;
            --info: #3498db;
            --border: #e9ecef;
            --bg-light: #f8f9fa;
            --text-dark: #2c3e50;
            --text-muted: #6c757d;
            --shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
        }
        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
            transition: 0.3s;
        }
        .page-header {
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h1 i {
            color: var(--primary);
            font-size: 1.8rem;
        }
        .page-header small {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 400;
            margin-left: 8px;
        }
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        .filter-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--primary-dark);
            margin-bottom: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 8px;
        }
        .filter-title i {
            font-size: 0.85rem;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-group label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
            display: block;
        }
        .filter-group label i {
            color: var(--primary);
            margin-right: 4px;
        }
        .multi-select-dropdown {
            position: relative;
            width: 100%;
        }
        .multi-select-btn {
            width: 100%;
            padding: 9px 12px;
            font-size: 0.85rem;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            background: white;
            text-align: left;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.2s;
        }
        .multi-select-btn:hover {
            border-color: var(--primary);
        }
        .multi-select-dropdown .dropdown-content {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            z-index: 1000;
            max-height: 220px;
            overflow-y: auto;
            display: none;
        }
        .multi-select-dropdown .dropdown-content.show {
            display: block;
        }
        .multi-select-dropdown .dropdown-content .form-check {
            padding: 8px 12px;
            margin-left: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .multi-select-dropdown .dropdown-content .form-check:hover {
            background: var(--primary-light);
        }
        .multi-select-dropdown .dropdown-content .form-check-input {
            width: 14px;
            height: 14px;
            margin-top: 0;
        }
        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 9px 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 12px;
            border: none;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(243,156,18,0.3);
        }
        .btn-secondary {
            background: #e9ecef;
            color: var(--text-dark);
        }
        .btn-secondary:hover {
            background: #dee2e6;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 16px 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
        }
        .stat-card h6 {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        .stat-number {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1.2;
        }
        .stat-number small {
            font-size: 0.8rem;
            font-weight: 500;
        }
        .action-bar {
            background: white;
            border-radius: 20px;
            padding: 16px 24px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid var(--border);
        }
        .select-all {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .select-all input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        .select-all label {
            font-weight: 600;
            margin: 0;
            cursor: pointer;
        }
        #selectedCount {
            background: var(--primary-light);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary-dark);
        }
        .btn-bill {
            background: var(--success);
            color: white;
            border: none;
            padding: 10px 28px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 0.85rem;
            transition: 0.2s;
        }
        .btn-bill:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .btn-bill:not(:disabled):hover {
            background: #219653;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(39,174,96,0.3);
        }
        .table-card {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .table-header {
            padding: 14px 20px;
            background: var(--bg-light);
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 0.8rem;
        }
        .table-header i {
            color: var(--primary);
            margin-right: 6px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 1100px;
            margin-bottom: 0;
        }
        .table th {
            background: var(--bg-light);
            padding: 12px 12px;
            border-bottom: 2px solid var(--primary);
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--text-dark);
        }
        .table td {
            padding: 12px 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .table tbody tr:hover {
            background: var(--primary-light);
        }
        .badge-info {
            background: var(--info);
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        @media (max-width: 1200px) {
            .main-container { margin-left: 10%; padding: 20px; }
        }
        @media (max-width: 992px) {
            .main-container { margin-left: 0; padding: 20px; margin-top: 60px; }
            .stats-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr; }
            .action-bar { flex-direction: column; align-items: stretch; }
            .btn-bill { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h1><i class="fas fa-clipboard-list"></i> Production Billing <small>Checking Jobs</small></h1>
    </div>

    <!-- Filter Section -->
    <div class="filter-card">
        <div class="filter-title"><i class="fas fa-sliders-h"></i> Filter Jobs</div>
        <form method="GET" action="" id="filterForm">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-trademark"></i> Brand</label>
                    <div class="multi-select-dropdown" data-field="brand">
                        <button type="button" class="multi-select-btn">
                            <span>Select Brands</span> <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-content">
                            <?php foreach($brands as $b): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($b) ?>" id="brand_<?= md5($b) ?>" <?= in_array($b, $selected_brands) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="brand_<?= md5($b) ?>"><?= htmlspecialchars($b) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-ruler"></i> Size</label>
                    <div class="multi-select-dropdown" data-field="size">
                        <button type="button" class="multi-select-btn">
                            <span>Select Sizes</span> <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-content">
                            <?php foreach($sizes as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($s) ?>" id="size_<?= md5($s) ?>" <?= in_array($s, $selected_sizes) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="size_<?= md5($s) ?>"><?= htmlspecialchars($s) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                    <a href="production_billing_new.php" class="btn btn-secondary"><i class="fas fa-eraser"></i> Clear Filters</a>
                </div>
            </div>
            <div id="hidden-inputs-container"></div>
        </form>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card"><h6><i class="fas fa-briefcase"></i> Total Jobs</h6><div class="stat-number"><?= $total_jobs ?></div></div>
        <div class="stat-card"><h6><i class="fas fa-boxes"></i> Original Qty</h6><div class="stat-number"><?= number_format($total_qty) ?> <small>pcs</small></div></div>
        <div class="stat-card"><h6><i class="fas fa-check-circle"></i> Claim Pcs</h6><div class="stat-number"><?= number_format($total_claim_qty) ?> <small>pcs</small></div></div>
        <div class="stat-card"><h6><i class="fas fa-file-invoice-dollar"></i> Billable Qty</h6><div class="stat-number"><?= number_format($total_bill_qty) ?> <small>pcs</small></div></div>
    </div>

    <?php if ($total_jobs > 0): ?>
    <form method="POST" action="production_invoice_new.php">
        <div class="action-bar">
            <div class="select-all">
                <input type="checkbox" id="selectAll">
                <label for="selectAll"><strong>Select All</strong></label>
                <span id="selectedCount">0 selected</span>
            </div>
            <button type="submit" name="generate_bill" class="btn-bill" id="generateBtn" disabled>
                <i class="fas fa-file-invoice"></i> Generate Production Bill
            </button>
        </div>
        <div class="table-card">
            <div class="table-header"><i class="fas fa-list-ul"></i> Jobs Ready for Billing (Rate = Party Total from Costing Table)</div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-check-square"></i></th>
                            <th>Job No</th>
                            <th>Design</th>
                            <th>Brand</th>
                            <th>Size</th>
                            <th class="text-end">Original Qty</th>
                            <th class="text-end">Claim Pcs</th>
                            <th class="text-end">Bill Qty</th>
                            <th class="text-end">Rate (Rs/pc)</th>
                            <th class="text-end">Amount (Rs)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs_data as $job): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_jobs[]" value="<?= $job['id'] ?>" class="job-checkbox"></td>
                            <td><strong><?= htmlspecialchars($job['job_no']) ?></strong></td>
                            <td><?= htmlspecialchars($job['design_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($job['brand_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($job['size'] ?? '-') ?></td>
                            <td class="text-end"><?= number_format($job['quantity']) ?></td>
                            <td class="text-end"><?= number_format($job['claim_qty']) ?></td>
                            <td class="text-end"><strong><?= number_format($job['bill_qty']) ?></strong></td>
                            <td class="text-end"><strong><?= $job['per_piece_rate'] > 0 ? number_format($job['per_piece_rate'], 2) : '<span class="text-muted">0.00</span>' ?></strong></td>
                            <td class="text-end"><?= number_format($job['amount'], 2) ?></td>
                            <td><span class="badge-info">Checking</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <p>No jobs with 'Checking' status matching the selected filters.</p>
        <a href="production_billing_new.php" class="btn btn-primary mt-3">Reset Filters</a>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Multi-select dropdown with checkboxes
document.querySelectorAll('.multi-select-dropdown').forEach(dropdown => {
    const btn = dropdown.querySelector('.multi-select-btn');
    const content = dropdown.querySelector('.dropdown-content');
    const checkboxes = content.querySelectorAll('input[type="checkbox"]');
    
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.multi-select-dropdown .dropdown-content').forEach(c => {
            if (c !== content) c.classList.remove('show');
        });
        content.classList.toggle('show');
    });
    
    function updateButtonText() {
        const selected = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
        const span = btn.querySelector('span');
        if (selected.length === 0) span.innerHTML = 'All';
        else if (selected.length === 1) span.innerHTML = selected[0];
        else span.innerHTML = selected.length + ' selected';
    }
    checkboxes.forEach(cb => cb.addEventListener('change', updateButtonText));
    updateButtonText();
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.multi-select-dropdown')) {
        document.querySelectorAll('.multi-select-dropdown .dropdown-content').forEach(c => c.classList.remove('show'));
    }
});

// On form submit, collect selected values into hidden inputs
const filterForm = document.getElementById('filterForm');
if (filterForm) {
    filterForm.addEventListener('submit', function(e) {
        const container = document.getElementById('hidden-inputs-container');
        container.innerHTML = '';
        const fields = ['brand', 'size'];
        fields.forEach(field => {
            const dropdown = document.querySelector(`.multi-select-dropdown[data-field="${field}"]`);
            if (dropdown) {
                const selected = Array.from(dropdown.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                if (selected.length > 0) {
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

// Selection logic
$(function(){
    function updateCount() {
        let c = $('.job-checkbox:checked').length;
        $('#selectedCount').text(c + ' selected');
        $('#generateBtn').prop('disabled', c === 0);
    }
    $('#selectAll').change(function(){
        $('.job-checkbox').prop('checked', this.checked);
        updateCount();
    });
    $('.job-checkbox').change(updateCount);
    updateCount();
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>