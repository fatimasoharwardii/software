<?php
$page_identifier = 'dashboard/dashboard.php';
require_once "../../config/db.php";
require_once "../../includes/functions.php";
require_once "../../includes/auth.php";

// Get company_id for filtering (multi-company support)
$company_id = $_SESSION['company_id'] ?? 0;
$company_id = intval($company_id); // safety

// ----- Column detection -----
$jobs_columns_check = $conn->query("SHOW COLUMNS FROM jobs");
$jobs_columns = [];
while($col = $jobs_columns_check->fetch_assoc()) $jobs_columns[] = $col['Field'];

$cmt_party_column = in_array('cmt_party', $jobs_columns) ? 'cmt_party' : (in_array('cmt_party_id', $jobs_columns) ? 'cmt_party_id' : null);
$embroidery_vendor_column = in_array('embroidery_vendor_name', $jobs_columns) ? 'embroidery_vendor_name' : (in_array('embroidery_vendor_id', $jobs_columns) ? 'embroidery_vendor_id' : null);
$fabric_column = 'fabric_name';

// ----- Statistics (filtered by company_id) -----
$total_running = $conn->query("SELECT COUNT(*) AS cnt, SUM(quantity) AS tqty FROM jobs WHERE status IN ('Embroidery','Ready','Stitching','Incomplete','CMT') AND company_id = $company_id")->fetch_assoc();
$total_jobs = $total_running['cnt'];
$total_qty  = $total_running['tqty'] ?? 0;

$pending_bills = $conn->query("SELECT COUNT(*) FROM stitching_posted_bills WHERE (status='pending' OR status IS NULL) AND company_id = $company_id")->fetch_assoc()['COUNT(*)'];

$statuses = ['Embroidery'=>0,'Backup'=>0,'Ready'=>0,'Stitching'=>0,'Incomplete'=>0,'Checking'=>0,'CMT'=>0];
$status_data = [];
$res = $conn->query("SELECT status, COUNT(*) AS cnt, SUM(quantity) AS tqty FROM jobs WHERE status IN ('Embroidery','Backup','Ready','Stitching','Incomplete','Checking','CMT') AND company_id = $company_id GROUP BY status");
while($row = $res->fetch_assoc()) { $statuses[$row['status']] = $row['cnt']; $status_data[$row['status']] = ['count'=>$row['cnt'],'total_qty'=>$row['tqty']??0]; }

// Brands, Fabrics, Parties (filtered by company_id)
$brands = []; $res = $conn->query("SELECT DISTINCT brand_name FROM jobs WHERE brand_name IS NOT NULL AND brand_name!='' AND company_id = $company_id ORDER BY brand_name"); while($row=$res->fetch_assoc()) $brands[]=$row['brand_name'];
$fabrics = []; $res = $conn->query("SELECT DISTINCT $fabric_column FROM jobs WHERE $fabric_column IS NOT NULL AND $fabric_column!='' AND company_id = $company_id ORDER BY $fabric_column"); while($row=$res->fetch_assoc()) $fabrics[]=$row[$fabric_column];
$cmt_parties = []; 
if ($cmt_party_column) {
    $res = $conn->query("SELECT DISTINCT $cmt_party_column FROM jobs WHERE status='CMT' AND $cmt_party_column IS NOT NULL AND $cmt_party_column!='' AND company_id = $company_id ORDER BY $cmt_party_column");
    while($row = $res->fetch_assoc()) $cmt_parties[] = $row[$cmt_party_column];
}
$embroidery_vendors = []; 
if ($embroidery_vendor_column) {
    if ($embroidery_vendor_column=='embroidery_vendor_id') {
        $res = $conn->query("SELECT DISTINCT j.$embroidery_vendor_column AS vid, p.party_name FROM jobs j LEFT JOIN parties p ON j.$embroidery_vendor_column=p.id WHERE j.status='Embroidery' AND j.company_id = $company_id ORDER BY p.party_name");
        while($row=$res->fetch_assoc()) $embroidery_vendors[] = $row;
    } else {
        $res = $conn->query("SELECT DISTINCT $embroidery_vendor_column AS vname FROM jobs WHERE status='Embroidery' AND $embroidery_vendor_column IS NOT NULL AND $embroidery_vendor_column!='' AND company_id = $company_id ORDER BY $embroidery_vendor_column");
        while($row=$res->fetch_assoc()) $embroidery_vendors[] = $row['vname'];
    }
}

// ----- URL filters -----
$selected_fabrics = isset($_GET['fabric']) ? explode(',', $_GET['fabric']) : [];
$selected_cmt_party_search = isset($_GET['cmt_party']) ? trim($_GET['cmt_party']) : '';
$selected_emb_vendor = $_GET['embroidery_vendor'] ?? '';
$selected_brand = $_GET['brand'] ?? '';
$status_filter = $_GET['status'] ?? '';
$all_jobs_filter = $_GET['all_jobs'] ?? '';

// ----- Decide which jobs to show -----
$show_running_default = ($status_filter === '' && $all_jobs_filter === '');
if ($show_running_default) {
    $all_jobs_filter = '1';
}

// ----- Machine Performance (filtered by company_id) -----
$machine_stats = [];
$yesterday = date('Y-m-d', strtotime('-1 day'));
$yd = $conn->query("SELECT machine_no, SUM(stitch_done) AS stitches FROM embroidery_entries WHERE entry_date='$yesterday' AND company_id = $company_id GROUP BY machine_no");
if ($yd) while ($r = $yd->fetch_assoc()) $machine_stats[$r['machine_no']]['last_day'] = $r['stitches'];

$curMonth = date('Y-m');
$avgQ = $conn->query("SELECT machine_no, AVG(daily) AS avg_stitches FROM (SELECT machine_no, entry_date, SUM(stitch_done) AS daily FROM embroidery_entries WHERE entry_date LIKE '$curMonth%' AND company_id = $company_id GROUP BY machine_no, entry_date) t GROUP BY machine_no");
if ($avgQ) while ($r = $avgQ->fetch_assoc()) $machine_stats[$r['machine_no']]['monthly_avg'] = round($r['avg_stitches']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f7fa; font-family: 'Inter', sans-serif; color: #1e293b; }
        .main-container { margin-left: 14%; padding: 28px 32px; min-height: 100vh; transition: margin-left 0.2s ease; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 28px; }
        .header-title h1 { font-size: 1.8rem; font-weight: 700; background: linear-gradient(135deg, #F39C12, #E67E22); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0; }
        .header-title p { font-size: 0.85rem; color: #64748b; margin: 4px 0 0; }
        .user-badge { background: white; padding: 8px 20px; border-radius: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); font-weight: 500; font-size: 0.85rem; border: 1px solid #FEF5E7; }
        .user-badge i { color: #F39C12; margin-right: 8px; }
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border-radius: 24px; padding: 20px; transition: all 0.2s; border: 1px solid #e9ecef; box-shadow: 0 1px 3px rgba(0,0,0,0.02); cursor: pointer; }
        .stat-card:hover { transform: translateY(-3px); border-color: #F39C12; box-shadow: 0 12px 24px -12px rgba(0,0,0,0.1); }
        .stat-icon { width: 48px; height: 48px; background: #FEF5E7; border-radius: 30px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; }
        .stat-icon i { font-size: 1.4rem; color: #F39C12; }
        .stat-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; color: #64748b; margin-bottom: 6px; }
        .stat-number { font-size: 2rem; font-weight: 800; color: #2C3E50; line-height: 1.2; }
        .stat-sub { font-size: 0.7rem; color: #94a3b8; margin-top: 4px; }
        .section-header { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .section-header h3 { font-size: 1.1rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; color: #2C3E50; }
        .section-header h3 i { color: #F39C12; }
        .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; background: white; padding: 16px 24px; border-radius: 20px; margin-bottom: 24px; border: 1px solid #e9ecef; }
        .filter-group { min-width: 160px; }
        .filter-group label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 4px; }
        .filter-control { width: 100%; padding: 8px 12px; border-radius: 40px; border: 1px solid #e9ecef; background: white; font-size: 0.8rem; }
        .btn-sm-custom { padding: 6px 20px; border-radius: 40px; font-weight: 600; font-size: 0.75rem; border: none; cursor: pointer; transition: 0.2s; }
        .btn-primary-custom { background: #F39C12; color: white; }
        .btn-primary-custom:hover { background: #E67E22; transform: translateY(-1px); }
        .btn-outline-custom { background: white; border: 1px solid #e9ecef; color: #334155; }
        .btn-outline-custom:hover { background: #FEF5E7; border-color: #F39C12; }
        .jobs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 22px; margin-bottom: 40px; }
        .job-card { background: white; border-radius: 24px; overflow: hidden; transition: all 0.2s; border: 1px solid #e9ecef; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .job-card:hover { transform: translateY(-4px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.1); border-color: #F39C12; }
        .card-image { height: 140px; background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; position: relative; }
        .card-image img { width: 100%; height: 100%; object-fit: cover; }
        .card-badge { position: absolute; top: 12px; right: 12px; padding: 4px 12px; border-radius: 30px; font-size: 0.65rem; font-weight: 600; background: rgba(255,255,255,0.9); backdrop-filter: blur(2px); color: #1e293b; }
        .card-content { padding: 16px; }
        .job-title { font-weight: 700; font-size: 1rem; display: flex; justify-content: space-between; margin-bottom: 10px; }
        .job-no { background: #FEF5E7; padding: 2px 10px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; color: #F39C12; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.75rem; }
        .info-label { color: #64748b; }
        .info-value { font-weight: 500; color: #2C3E50; }
        .card-footer { margin-top: 12px; padding-top: 12px; border-top: 1px solid #e9ecef; }
        .view-link { display: inline-flex; align-items: center; gap: 6px; font-size: 0.7rem; font-weight: 600; color: #F39C12; text-decoration: none; }
        .view-link:hover { text-decoration: underline; color: #E67E22; }
        .machine-card { background: white; border-radius: 24px; border: 1px solid #e9ecef; overflow: hidden; margin-top: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: 0.2s; }
        .machine-header { padding: 16px 24px; background: #FEF5E7; border-bottom: 1px solid #e9ecef; font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; }
        .machine-header i { color: #F39C12; }
        .machine-table { width: 100%; font-size: 0.8rem; border-collapse: collapse; }
        .machine-table th { padding: 14px 20px; background: #ffffff; font-weight: 600; color: #475569; border-bottom: 1px solid #e9ecef; text-transform: uppercase; letter-spacing: 0.3px; font-size: 0.7rem; }
        .machine-table td { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .machine-table tbody tr:hover { background: #FEF5E7; }
        .machine-table tbody tr:last-child td { border-bottom: none; }
        .text-end { text-align: right; }
        .machine-table .machine-name { font-weight: 600; color: #2C3E50; }
        .machine-table .machine-name i { margin-right: 8px; color: #F39C12; }
        .total-row { background: #FEF5E7; font-weight: 700; }
        .empty-state { text-align: center; padding: 50px 20px; background: white; border-radius: 24px; border: 1px solid #e9ecef; }
        .empty-state i { font-size: 2.5rem; color: #cbd5e1; margin-bottom: 12px; }
        @media (max-width: 1200px) { .main-container { margin-left: 10%; padding: 20px; } }
        @media (max-width: 992px) { .main-container { margin-left: 0; padding: 20px; margin-top: 70px; } }
        @media (max-width: 640px) { .jobs-grid { grid-template-columns: 1fr; } .filter-bar { flex-direction: column; } .filter-group { width: 100%; } .machine-table th, .machine-table td { padding: 10px 12px; } }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="dashboard-header">
        <div class="header-title">
            <h1>Dashboard</h1>
            <p>Production overview · job tracking · machine performance</p>
        </div>
        <div class="user-badge">
            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Admin') ?>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="section-header">
        <h3><i class="fas fa-chart-simple"></i> Job Status Summary</h3>
    </div>
    <div class="stats-row mb-4">
        <div class="stat-card" onclick="window.location.href='?all_jobs=1'">
            <div class="stat-icon"><i class="fas fa-play-circle"></i></div>
            <div class="stat-label">All Running</div>
            <div class="stat-number"><?= $total_jobs ?></div>
            <div class="stat-sub">Embroidery · Ready · Stitching · Incomplete · CMT</div>
        </div>
        <?php
        $colors = ['Embroidery'=>'#9b59b6','Backup'=>'#3498db','Ready'=>'#2ecc71','Stitching'=>'#e74c3c','Incomplete'=>'#f39c12','Checking'=>'#1abc9c','CMT'=>'#34495e'];
        foreach(['Embroidery','Backup','Ready','Stitching','Incomplete','Checking','CMT'] as $st):
            $cnt = $statuses[$st]??0;
            $qty = $status_data[$st]['total_qty']??0;
        ?>
        <div class="stat-card" onclick="window.location.href='?status=<?= $st ?>'">
            <div class="stat-icon" style="background:<?= $colors[$st] ?>10;"><i class="fas fa-tag" style="color:<?= $colors[$st] ?>;"></i></div>
            <div class="stat-label"><?= $st ?></div>
            <div class="stat-number"><?= $cnt ?></div>
            <div class="stat-sub"><?= number_format($qty) ?> pcs</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <?php if ($fabric_column && !empty($fabrics)): ?>
        <div class="filter-group">
            <label>Fabric</label>
            <div class="dropdown">
                <button class="filter-control dropdown-toggle" type="button" data-bs-toggle="dropdown" style="text-align:left;">
                    <?= !empty($selected_fabrics) ? count($selected_fabrics).' selected' : 'All Fabrics' ?>
                </button>
                <ul class="dropdown-menu p-2">
                    <?php foreach($fabrics as $f): $chk = in_array($f, $selected_fabrics) ? 'checked' : ''; ?>
                    <li><div class="form-check"><input class="form-check-input fabric-checkbox" type="checkbox" value="<?= htmlspecialchars($f) ?>" <?= $chk ?>> <label class="form-check-label"><?= htmlspecialchars($f) ?></label></div></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($brands)): ?>
        <div class="filter-group">
            <label>Brand</label>
            <select class="filter-control" id="brandSelect">
                <option value="">All Brands</option>
                <?php foreach($brands as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $selected_brand==$b?'selected':'' ?>><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($cmt_party_column && ($status_filter=='CMT' || $all_jobs_filter=='1')): ?>
        <div class="filter-group">
            <label>CMT Party</label>
            <input type="text" class="filter-control" id="cmtPartyInput" placeholder="Search party" value="<?= htmlspecialchars($selected_cmt_party_search) ?>">
        </div>
        <?php endif; ?>

        <?php if (!empty($embroidery_vendors) && ($status_filter=='Embroidery' || $all_jobs_filter=='1')): ?>
        <div class="filter-group">
            <label>Embroidery Vendor</label>
            <select class="filter-control" id="embVendorSelect">
                <option value="">All Vendors</option>
                <?php foreach($embroidery_vendors as $v):
                    $val = is_array($v) ? $v['vid'] : $v;
                    $name = is_array($v) ? $v['party_name'] : $v;
                ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= $selected_emb_vendor==$val?'selected':'' ?>><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="filter-group" style="min-width:auto;">
            <button class="btn-sm-custom btn-primary-custom" onclick="applyFilters()">Apply</button>
            <button class="btn-sm-custom btn-outline-custom ms-1" onclick="clearFilters()">Clear</button>
        </div>
    </div>

    <?php
    // Build main job query with company_id filter
    $query = "SELECT * FROM jobs";
    $where = [];
    
    // Base company filter
    $where[] = "company_id = $company_id";
    
    if ($all_jobs_filter == '1') {
        $where[] = "status IN ('Embroidery','Ready','Stitching','Incomplete','CMT')";
    } elseif ($status_filter) {
        $where[] = "status='".mysqli_real_escape_string($conn, $status_filter)."'";
    } else {
        $where[] = "status IN ('Embroidery','Ready','Stitching','Incomplete','CMT')";
    }
    
    if ($fabric_column && !empty($selected_fabrics)) {
        $esc = array_map(function($f) use($conn){ return mysqli_real_escape_string($conn,$f); }, $selected_fabrics);
        $where[] = "$fabric_column IN ('".implode("','",$esc)."')";
    }
    
    if ($selected_brand) {
        $where[] = "brand_name='".mysqli_real_escape_string($conn, $selected_brand)."'";
    }
    
    if ($cmt_party_column && $selected_cmt_party_search !== '') {
        $search = mysqli_real_escape_string($conn, $selected_cmt_party_search);
        $where[] = "$cmt_party_column LIKE '%$search%'";
    }
    
    if ($embroidery_vendor_column && $selected_emb_vendor) {
        $v = mysqli_real_escape_string($conn, $selected_emb_vendor);
        $where[] = "$embroidery_vendor_column='$v'";
    }
    
    $query .= " WHERE ".implode(" AND ", $where);
    $query .= " ORDER BY id DESC";
    $jobs_result = $conn->query($query);

    $title = 'Running Jobs';
    if ($status_filter) $title = "$status_filter Jobs";
    if (!empty($selected_fabrics)) $title .= " (Fabric: ".implode(', ',$selected_fabrics).")";
    if ($selected_brand) $title .= " (Brand: $selected_brand)";
    ?>

    <div class="section-header">
        <h3><i class="fas fa-list-ul"></i> <?= $title ?> <span class="text-muted small">(<?= $jobs_result ? $jobs_result->num_rows : 0 ?>)</span></h3>
        <?php if($status_filter || $all_jobs_filter || !empty($selected_fabrics) || $selected_brand || $selected_cmt_party_search || $selected_emb_vendor): ?>
        <a href="dashboard.php" class="view-link"><i class="fas fa-arrow-left"></i> Back to Main</a>
        <?php endif; ?>
    </div>

    <div class="jobs-grid">
        <?php if($jobs_result && $jobs_result->num_rows > 0):
            while($job = $jobs_result->fetch_assoc()):
                $img = ""; $has_img = false;
                $file = $job['design_image'] ?? $job['image'] ?? '';
                if ($file) { $path = "../../assets/uploads/".$file; if (file_exists($path)) { $img = $path; $has_img = true; } }
                $embDisp = 'N/A'; if ($embroidery_vendor_column && !empty($job[$embroidery_vendor_column])) $embDisp = $job[$embroidery_vendor_column];
                $cmtDisp = 'N/A'; if ($cmt_party_column && !empty($job[$cmt_party_column])) $cmtDisp = $job[$cmt_party_column];
                $statusColors = ['Embroidery'=>'#9b59b6','Backup'=>'#3498db','Ready'=>'#2ecc71','Stitching'=>'#e74c3c','Incomplete'=>'#f39c12','Checking'=>'#1abc9c','CMT'=>'#34495e'];
                $sc = $statusColors[$job['status']] ?? '#64748b';
        ?>
        <div class="job-card">
            <div class="card-image" style="background:linear-gradient(135deg, <?= $sc ?>20, <?= $sc ?>05);">
                <?php if($has_img): ?>
                    <img src="<?= $img ?>" alt="<?= htmlspecialchars($job['job_no']) ?>">
                <?php else: ?>
                    <i class="fas fa-tshirt fa-3x" style="color:<?= $sc ?>;"></i>
                <?php endif; ?>
                <span class="card-badge" style="background:<?= $sc ?>; color:white;"><?= $job['status'] ?></span>
            </div>
            <div class="card-content">
                <div class="job-title">
                    <span class="job-no"><?= htmlspecialchars($job['job_no']) ?></span>
                </div>
                <div class="info-row"><span class="info-label">Design:</span><span class="info-value"><?= htmlspecialchars(substr($job['design_name']??'N/A',0,25)) ?></span></div>
                <div class="info-row"><span class="info-label">Brand:</span><span class="info-value"><?= htmlspecialchars($job['brand_name']??($job['brand']??'N/A')) ?></span></div>
                <div class="info-row"><span class="info-label">Fabric:</span><span class="info-value"><?= htmlspecialchars($job['fabric_name']??'N/A') ?></span></div>
                <div class="info-row"><span class="info-label">Size:</span><span class="info-value"><?= htmlspecialchars($job['size']??'N/A') ?></span></div>
                <div class="info-row"><span class="info-label">Qty:</span><span class="info-value"><?= number_format($job['quantity']) ?></span></div>
                <div class="info-row"><span class="info-label">Vendor:</span><span class="info-value"><?= htmlspecialchars(substr($embDisp,0,20)) ?></span></div>
                <?php if($job['status']=='CMT'): ?>
                <div class="info-row"><span class="info-label">CMT Party:</span><span class="info-value"><?= htmlspecialchars(substr($cmtDisp,0,20)) ?></span></div>
                <?php endif; ?>
                <div class="card-footer">
                    <a href="../jobs/view.php?id=<?= $job['id'] ?>" class="view-link"><i class="fas fa-eye"></i> View Details</a>
                </div>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h5>No Jobs Found</h5>
            <p class="text-muted">Try changing your filter criteria.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Machine Performance Section -->
    <div class="section-header mt-3">
        <h3><i class="fas fa-microchip"></i> Machine Performance</h3>
    </div>
    <div class="machine-card">
        <div class="machine-header">
            <i class="fas fa-chart-line"></i> Embroidery Machines – Yesterday & Monthly Average
        </div>
        <?php if (!empty($machine_stats)): ?>
        <table class="machine-table">
            <thead>
                <tr><th>Machine No</th><th class="text-end">Yesterday Stitches</th><th class="text-end">Monthly Avg (stitches/day)</th></tr>
            </thead>
            <tbody>
                <?php 
                $total_yesterday = 0;
                $total_monthly_avg = 0;
                foreach ($machine_stats as $mach => $st):
                    $yest = $st['last_day'] ?? 0;
                    $avg = $st['monthly_avg'] ?? 0;
                    $total_yesterday += $yest;
                    $total_monthly_avg += $avg;
                ?>
                <tr>
                    <td class="machine-name"><i class="fas fa-microchip"></i> <?= htmlspecialchars($mach) ?></td>
                    <td class="text-end"><?= number_format($yest) ?> <span class="text-muted">stitches</span></td>
                    <td class="text-end"><?= number_format($avg) ?> <span class="text-muted">stitches</span></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td class="fw-bold">Total / Average</td>
                    <td class="text-end fw-bold"><?= number_format($total_yesterday) ?> <span class="text-muted">stitches</span></td>
                    <td class="text-end fw-bold"><?= number_format($total_yesterday > 0 ? round($total_monthly_avg / count($machine_stats)) : 0) ?> <span class="text-muted">stitches</span></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state py-4" style="background:transparent; border:none;">
            <i class="fas fa-chart-line"></i>
            <p class="text-muted">No machine data available for yesterday or this month.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function applyFilters() {
    let url = new URLSearchParams(window.location.search);
    let fabrics = [];
    $('.fabric-checkbox:checked').each(function(){ fabrics.push($(this).val()); });
    fabrics.length ? url.set('fabric', fabrics.join(',')) : url.delete('fabric');
    let cmtParty = $('#cmtPartyInput').val().trim();
    if (cmtParty) url.set('cmt_party', cmtParty); else url.delete('cmt_party');
    let brand = $('#brandSelect').val();
    if (brand) url.set('brand', brand); else url.delete('brand');
    let embVendor = $('#embVendorSelect').val();
    if (embVendor) url.set('embroidery_vendor', embVendor); else url.delete('embroidery_vendor');
    window.location.search = url.toString();
}
function clearFilters() {
    let url = new URLSearchParams(window.location.search);
    url.delete('fabric');
    url.delete('cmt_party');
    url.delete('brand');
    url.delete('embroidery_vendor');
    window.location.search = url.toString();
}
</script>
<?php include "../../includes/footer.php"; ?>
</body>
</html>