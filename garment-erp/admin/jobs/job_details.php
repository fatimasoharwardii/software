<?php
$page_identifier = 'jobs/job_details.php';
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
$tables = ['jobs', 'embroidery_entries', 'stitching_bill_items'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$job_no = $_GET['job_no'] ?? '';
$job_data = null;
$error = '';

if ($job_no) {
    $stmt = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $job_data = $result->fetch_assoc();
    } else {
        $error = "Job not found";
    }
    $stmt->close();
}

function getValue($data, $key, $default = 'N/A') {
    return isset($data[$key]) && $data[$key] !== null && $data[$key] !== '' ? $data[$key] : $default;
}

function hasValue($data, $key) {
    return isset($data[$key]) && $data[$key] !== null && $data[$key] !== '' && $data[$key] != 0;
}

function formatCurrency($value) {
    return '₨' . number_format($value ?? 0, 2);
}

function formatDate($date) {
    if (!$date || $date == '0000-00-00') return 'NOT SET';
    return date('d-M Y', strtotime($date));
}

// Fetch embroidery entries for this job (company filtered)
$embroidery_entries = [];
if ($job_no) {
    $emb_stmt = $conn->prepare("SELECT * FROM embroidery_entries WHERE job_no = ? AND company_id = ? ORDER BY id DESC");
    $emb_stmt->bind_param("si", $job_no, $company_id);
    $emb_stmt->execute();
    $emb_result = $emb_stmt->get_result();
    while ($row = $emb_result->fetch_assoc()) {
        $embroidery_entries[] = $row;
    }
    $emb_stmt->close();
}

// Fetch stitching bill items for this job (company filtered)
$stitching_items = [];
$stitching_total = 0;
if ($job_no) {
    $stitch_stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE job_no = ? AND company_id = ? ORDER BY id DESC");
    $stitch_stmt->bind_param("si", $job_no, $company_id);
    $stitch_stmt->execute();
    $stitch_result = $stitch_stmt->get_result();
    while ($row = $stitch_result->fetch_assoc()) {
        $stitching_items[] = $row;
        $stitching_total += ($row['amount'] ?? 0) + ($row['sub_total'] ?? 0);
    }
    $stitch_stmt->close();
}

// Group stitching items by type for summary (company filtered)
$stitching_summary = [];
if ($job_no) {
    $summary_stmt = $conn->prepare("SELECT tab_type, 
                                   SUM(amount) as total_amount,
                                   SUM(sub_total) as total_subtotal,
                                   SUM(qty) as total_qty,
                                   SUM(kurti_qty + shalwar_qty + dupatta_qty) as total_pieces
                                   FROM stitching_bill_items 
                                   WHERE job_no = ? AND company_id = ? 
                                   GROUP BY tab_type");
    $summary_stmt->bind_param("si", $job_no, $company_id);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    while ($row = $summary_result->fetch_assoc()) {
        $stitching_summary[] = $row;
    }
    $summary_stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Job Details - <?= htmlspecialchars($job_no) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Color Theme (unchanged) */
    :root {
        --primary: #F39C12;
        --primary-hover: #FFB347;
        --dark-bg: #1E1E1E;
        --light-bg: #F9F9F9;
        --border: #E0E0E0;
        --text-dark: #2C3E50;
        --text-light: #FFFFFF;
        --success: #28a745;
        --danger: #dc3545;
        --info: #17a2b8;
        --warning: #ffc107;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body { 
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
        background: var(--light-bg); 
        color: var(--text-dark);
        margin: 0;
        padding: 0;
    }

    .main-container {
        margin-left: 17%;
        padding: 24px 32px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
    }

    .page-header {
        margin-bottom: 30px;
    }

    .page-header h1 {
        color: var(--primary);
        font-size: 2rem;
        font-weight: 600;
        letter-spacing: -0.02em;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h1 i {
        color: var(--primary);
    }

    .search-box { 
        background: white; 
        padding: 20px; 
        border-radius: 8px; 
        border: 1px solid var(--border);
        margin-bottom: 30px; 
    }

    .search-box form { 
        display: flex; 
        gap: 10px; 
    }

    .search-box input { 
        flex: 1; 
        padding: 10px 12px; 
        border: 1px solid var(--border); 
        border-radius: 4px; 
        font-size: 14px; 
    }

    .search-box input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(243, 156, 18, 0.1);
    }

    .search-box button { 
        padding: 10px 30px; 
        background: var(--primary); 
        color: white; 
        border: none; 
        border-radius: 4px; 
        cursor: pointer; 
        font-weight: 500; 
    }

    .search-box button:hover { 
        background: var(--primary-hover); 
    }

    .job-card { 
        background: white; 
        padding: 30px; 
        border-radius: 8px; 
        border: 1px solid var(--border);
    }

    .section-title { 
        font-size: 18px; 
        font-weight: 600; 
        color: var(--primary); 
        margin-top: 30px; 
        margin-bottom: 15px; 
        border-bottom: 2px solid var(--primary); 
        padding-bottom: 10px; 
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .section-title i {
        color: var(--primary);
    }

    .job-header { 
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%); 
        color: white; 
        padding: 20px; 
        border-radius: 6px; 
        margin-bottom: 25px; 
    }

    .job-header h2 { 
        font-size: 24px; 
        margin-bottom: 10px; 
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .header-info { 
        display: grid; 
        grid-template-columns: repeat(4, 1fr); 
        gap: 20px; 
    }

    .header-info-item { 
        background: rgba(255,255,255,0.1); 
        padding: 12px; 
        border-radius: 4px; 
    }

    .header-info-item label { 
        font-size: 12px; 
        opacity: 0.9; 
        display: block; 
    }

    .header-info-item .value { 
        font-size: 16px; 
        font-weight: 600; 
        display: block; 
        margin-top: 4px; 
    }

    .details-grid { 
        display: grid; 
        grid-template-columns: repeat(4, 1fr); 
        gap: 15px; 
        margin: 20px 0; 
    }

    .detail-box { 
        background: var(--light-bg); 
        padding: 15px; 
        border-radius: 6px; 
        border-left: 3px solid var(--primary); 
    }

    .detail-box.missing { 
        background: #ffebee; 
        border-left-color: var(--danger); 
    }

    .detail-box label { 
        display: block; 
        font-size: 12px; 
        color: #666; 
        margin-bottom: 5px; 
        font-weight: 500; 
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .detail-box .value { 
        display: block; 
        font-size: 16px; 
        font-weight: 600; 
        color: var(--text-dark); 
    }

    .detail-box.missing .value { 
        color: var(--danger); 
    }

    .costs-table, .roles-table, .entries-table { 
        width: 100%; 
        margin: 20px 0; 
        border-collapse: collapse; 
        border: 1px solid var(--border);
    }

    .costs-table th, .roles-table th, .entries-table th { 
        background: var(--primary); 
        color: white; 
        padding: 12px; 
        text-align: left; 
        font-weight: 500; 
    }

    .costs-table td, .roles-table td, .entries-table td { 
        padding: 12px; 
        border-bottom: 1px solid var(--border); 
    }

    .costs-table tr:hover, .roles-table tr:hover, .entries-table tr:hover { 
        background: var(--light-bg); 
    }

    .costs-table tr.empty-row td, .roles-table tr.empty-row td, .entries-table tr.empty-row td { 
        background: #ffebee; 
    }

    .roles-table th { background: var(--success); }
    .entries-table th { background: var(--info); }

    .text-end { text-align: right; }
    .text-center { text-align: center; }

    .badge { 
        display: inline-block; 
        padding: 4px 8px; 
        border-radius: 3px; 
        font-size: 12px; 
        font-weight: 500; 
    }
    .badge-info { background: #d1ecf1; color: #0c5460; }
    .badge-warning { background: #fff3cd; color: #856404; }
    .badge-danger { background: #f8d7da; color: #721c24; }
    .badge-success { background: #d4edda; color: #155724; }
    .badge-primary { background: var(--primary); color: white; }
    .badge-secondary { background: #6c757d; color: white; }

    .action-buttons { 
        margin-top: 30px; 
        display: flex; 
        gap: 10px; 
        flex-wrap: wrap; 
    }

    .action-buttons a { 
        padding: 10px 20px; 
        background: var(--primary); 
        color: white; 
        text-decoration: none; 
        border-radius: 4px; 
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 500;
        transition: background 0.15s;
    }

    .action-buttons a:hover { 
        background: var(--primary-hover); 
    }

    .action-buttons a:nth-child(2) { background: var(--success); }
    .action-buttons a:nth-child(3) { background: var(--info); }
    .action-buttons a:nth-child(4) { background: var(--info); }
    .action-buttons a:nth-child(5) { background: var(--warning); color: var(--text-dark); }

    .nav-tabs { 
        border-bottom: 2px solid var(--border); 
        margin-top: 30px;
    }

    .nav-tabs .nav-link { 
        color: var(--text-dark); 
        font-weight: 500; 
        border: none; 
        padding: 10px 20px; 
        background: transparent;
    }

    .nav-tabs .nav-link:hover {
        color: var(--primary);
    }

    .nav-tabs .nav-link.active { 
        color: var(--primary); 
        border-bottom: 3px solid var(--primary); 
        background: transparent; 
    }

    .tab-pane { 
        padding: 20px 0; 
    }

    .per-piece-cost { 
        font-size: 14px; 
        color: var(--success); 
        font-weight: 600; 
    }

    .error-message { 
        background: #f8d7da; 
        color: #721c24; 
        padding: 15px; 
        border-radius: 4px; 
        margin-bottom: 20px; 
        border: 1px solid #f5c6cb; 
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
        border: 1px solid transparent;
    }

    .alert-info {
        background: #d1ecf1;
        border-color: #bee5eb;
        color: #0c5460;
    }

    tr[style*="background: #e7f3ff"] {
        background: var(--light-bg) !important;
        font-weight: 600;
    }

    tr[style*="background: #d4edda"] {
        background: #d4edda !important;
    }

    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
        }
    }

    @media (max-width: 900px) {
        .main-container {
            margin-left: 0;
            padding: 16px;
        }
        .header-info, .details-grid {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
        .action-buttons a {
            width: 100%;
            justify-content: center;
        }
        .search-box form {
            flex-direction: column;
        }
    }
</style>
</head>
<body>
<?php include("../../includes/navbar.php"); ?>

<div class="main-container">
    <div class="page-header">
        <h1><i class="fas fa-briefcase me-2"></i>Job Details</h1>
    </div>

    <div class="search-box">
        <form method="GET">
            <input type="text" name="job_no" placeholder="Enter Job Number (e.g., JOB001)" value="<?= htmlspecialchars($job_no) ?>" required>
            <button type="submit"><i class="fas fa-search me-2"></i>Search Job</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="error-message"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($job_data): ?>
    <div class="job-card">
        <div class="section-title"><i class="fas fa-info-circle me-2"></i>Basic Information</div>
        <div class="details-grid">
            <div class="detail-box <?= hasValue($job_data, 'job_no') ? '' : 'missing' ?>">
                <label>JOB NO</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'job_no', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'serial_no') ? '' : 'missing' ?>">
                <label>SERIAL NUMBER</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'serial_no', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'design_name') ? '' : 'missing' ?>">
                <label>DESIGN NO</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'design_name', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'fabric_name') ? '' : 'missing' ?>">
                <label>FABRIC TYPE</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'fabric_name', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'size') ? '' : 'missing' ?>">
                <label>SIZE</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'size', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'brand_name') ? '' : 'missing' ?>">
                <label>BRAND</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'brand_name', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'embroidery_vendor_name') ? '' : 'missing' ?>">
                <label>EMB VENDOR</label>
                <div class="value"><?= htmlspecialchars(getValue($job_data, 'embroidery_vendor_name', 'NOT SET')) ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'embroidery_rate') ? '' : 'missing' ?>">
                <label>EMB RATE</label>
                <div class="value"><?= hasValue($job_data, 'embroidery_rate') ? formatCurrency($job_data['embroidery_rate']) : 'NOT SET' ?></div>
            </div>
            <div class="detail-box <?= hasValue($job_data, 'created_at') ? '' : 'missing' ?>">
                <label>START DATE</label>
                <div class="value"><?= formatDate(getValue($job_data, 'created_at')) ?></div>
            </div>
            <div class="detail-box">
                <label>STATUS</label>
                <div class="value">
                    <?php
                    $status = getValue($job_data, 'status', 'pending');
                    $status_class = 'badge-secondary';
                    if ($status == 'completed') $status_class = 'badge-success';
                    if ($status == 'in_progress') $status_class = 'badge-primary';
                    if ($status == 'pending') $status_class = 'badge-warning';
                    ?>
                    <span class="badge <?= $status_class ?>"><?= ucwords(str_replace('_', ' ', $status)) ?></span>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs" id="jobTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="rates-tab" data-bs-toggle="tab" data-bs-target="#rates" type="button" role="tab">Role Rates</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stitching-tab" data-bs-toggle="tab" data-bs-target="#stitching" type="button" role="tab">Stitching Entries</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="embroidery-tab" data-bs-toggle="tab" data-bs-target="#embroidery" type="button" role="tab">Embroidery Entries</button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Role Rates Tab -->
            <div class="tab-pane fade show active" id="rates" role="tabpanel">
                <?php
                $stitchingCosts = getPerPieceSummaryByType($conn, $job_no);
                $allTypes = [
                    'master' => 'Master',
                    'thekdar' => 'Thekdar',
                    'pressman' => 'Pressman',
                    'packing' => 'Packing',
                    'croping' => 'Croping',
                    'checking' => 'Checking',
                    'overlook' => 'Overlook',
                    'handwork' => 'Handwork',
                    'material' => 'Material',
                    'other' => 'Other',
                    'stitching_depart' => 'Stitching'
                ];
                ?>
                <table class="roles-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th class="text-end">Per Piece Rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grandTotal = 0;
                        foreach ($allTypes as $key => $label): 
                            $cost = $stitchingCosts[$key] ?? null;
                            $per_piece = $cost['per_piece'] ?? 0;
                            $total_amt = $cost['total_amount'] ?? 0;
                            $grandTotal += $total_amt;
                            $hasData = $cost !== null;
                            $rowClass = $hasData ? '' : 'empty-row';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><strong><?= $label ?></strong></td>
                            <td class="text-end"><?= $hasData ? formatCurrency($per_piece) : 'NOT SET' ?></td>
                            <td>
                                <?php if ($hasData): ?>
                                    <span class="badge badge-success">SET</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">NOT SET</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-light" style="font-weight: 600;">
                            <td>TOTAL PER JOB</td>
                            <td class="text-end" style="color: var(--primary);"><?= formatCurrency($grandTotal) ?></td>
                            <td></td>
                        </tr>
                        <?php if ($job_data['quantity'] > 0): ?>
                        <tr style="background: #d4edda;">
                            <td>PER PIECE COST</td>
                            <td class="text-end per-piece-cost"><?= formatCurrency($grandTotal / $job_data['quantity']) ?></td>
                            <td></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Stitching Entries Tab -->
            <div class="tab-pane fade" id="stitching" role="tabpanel">
                <?php if (!empty($stitching_items)): ?>
                    <h5 class="mb-3">Stitching Bill Items</h5>
                    <table class="entries-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Name</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Sub Total</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $stitching_grand_total = 0;
                            foreach ($stitching_items as $item): 
                                $total = ($item['amount'] ?? 0) + ($item['sub_total'] ?? 0);
                                $stitching_grand_total += $total;
                            ?>
                            <tr>
                                <td><?= ucwords(str_replace('_', ' ', $item['tab_type'])) ?></td>
                                <td><?= htmlspecialchars($item['name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($item['qty']) ?></td>
                                <td class="text-end"><?= formatCurrency($item['rate'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($item['amount'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($item['sub_total'] ?? 0) ?></td>
                                <td><?= date('d-M Y', strtotime($item['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background: var(--light-bg); font-weight: 600;">
                                <td colspan="5" class="text-end">GRAND TOTAL:</td>
                                <td class="text-end"><?= formatCurrency($stitching_grand_total) ?></td>
                                <td></td>
                            </tr>
                            <?php if ($job_data['quantity'] > 0): ?>
                            <tr style="background: #d4edda;">
                                <td colspan="5" class="text-end">PER PIECE COST:</td>
                                <td class="text-end per-piece-cost"><?= formatCurrency($stitching_grand_total / $job_data['quantity']) ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert alert-info">No stitching entries found for this job.</div>
                <?php endif; ?>
            </div>

            <!-- Embroidery Entries Tab -->
            <div class="tab-pane fade" id="embroidery" role="tabpanel">
                <?php if (!empty($embroidery_entries)): ?>
                    <h5 class="mb-3">Embroidery Entries</h5>
                    <table class="entries-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Machine</th>
                                <th>Shift</th>
                                <th>Part</th>
                                <th class="text-end">Stitch Done</th>
                                <th class="text-end">Per Round</th>
                                <th class="text-end">Rounds</th>
                                <th class="text-end">OP Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_stitches = 0;
                            foreach ($embroidery_entries as $entry): 
                                $total_stitches += $entry['stitch_done'] ?? 0;
                            ?>
                            <tr>
                                <td><?= date('d-M Y', strtotime($entry['entry_date'])) ?></td>
                                <td><?= htmlspecialchars($entry['machine_no'] ?? 'N/A') ?></td>
                                <td><?= ucfirst($entry['shift'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($entry['part'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($entry['stitch_done'] ?? 0) ?></td>
                                <td class="text-end"><?= number_format($entry['per_round'] ?? 0) ?></td>
                                <td class="text-end"><?= number_format($entry['rounds'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($entry['op_rate'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background: var(--light-bg); font-weight: 600;">
                                <td colspan="4" class="text-end">TOTAL STITCHES:</td>
                                <td class="text-end"><?= number_format($total_stitches) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert alert-info">No embroidery entries found for this job.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="action-buttons">
            <a href="list.php"><i class="fas fa-arrow-left"></i>Back to Jobs</a>
            <a href="../stitching/entry.php?job_no=<?= urlencode($job_data['job_no']) ?>"><i class="fas fa-tshirt"></i>Stitching Entry</a>
            <a href="../embroidery/entry.php?job_no=<?= urlencode($job_data['job_no']) ?>"><i class="fas fa-palette"></i>Embroidery Entry</a>
            <a href="../claims/add.php?job_no=<?= urlencode($job_data['job_no']) ?>"><i class="fas fa-file-invoice"></i>Add Claim</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>