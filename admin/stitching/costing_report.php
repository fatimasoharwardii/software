<?php
$page_identifier = 'stitching/costing_report.php';
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
$tables = ['jobs', 'stitching_bill_items', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$job_no = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';
$report_type = isset($_GET['report_type']) ? trim($_GET['report_type']) : 'summary'; // summary or detailed

// Get job info (company filtered)
$jobInfo = null;
if ($job_no) {
    $stmt = $conn->prepare("SELECT * FROM jobs WHERE job_no = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $jobInfo = $result->fetch_assoc();
    }
    $stmt->close();
}

// Fetch all stitching bill items (company filtered)
$stitching_items = [];
if ($job_no) {
    $stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE job_no = ? AND company_id = ?");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $stitching_items[] = $row;
    }
    $stmt->close();
}

// Compute per‑type summary (similar to getPerPieceSummaryByType but company‑aware)
function computePerPieceSummary($conn, $job_no, $items, $job_qty) {
    $summary = [];
    $totalAmount = 0;
    foreach ($items as $item) {
        $type = $item['tab_type'];
        if (!isset($summary[$type])) {
            $summary[$type] = [
                'total_amount' => 0,
                'per_piece' => 0,
                'entries' => 0,
                'vendors' => 0,
                'vendors_list' => []
            ];
        }
        $amount = floatval($item['amount'] ?? 0) + floatval($item['sub_total'] ?? 0);
        $summary[$type]['total_amount'] += $amount;
        $summary[$type]['entries']++;
        
        $vendor = $item['name'] ?? $item['party_name'] ?? 'Unknown';
        if (!in_array($vendor, $summary[$type]['vendors_list'])) {
            $summary[$type]['vendors_list'][] = $vendor;
        }
        $totalAmount += $amount;
    }
    foreach ($summary as $type => &$data) {
        $data['vendors'] = count($data['vendors_list']);
        unset($data['vendors_list']);
        $data['per_piece'] = ($job_qty > 0) ? $data['total_amount'] / $job_qty : 0;
    }
    unset($data);
    return $summary;
}

$stitchingCosts = [];
$job_qty = $jobInfo ? floatval($jobInfo['quantity']) : 0;
if (!empty($stitching_items)) {
    $stitchingCosts = computePerPieceSummary($conn, $job_no, $stitching_items, $job_qty);
}

// Define all possible types for display
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
    'fabric_issue' => 'Fabric Issue',
    'emb_bill' => 'Emb Bill',
    'other' => 'Other',
    'stitching_depart' => 'Stitching'
];

// Function to fetch detailed entries per type (for detailed view)
function getDetailedEntriesByType($conn, $job_no, $type, $company_id) {
    $stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE job_no = ? AND tab_type = ? AND company_id = ?");
    $stmt->bind_param("ssi", $job_no, $type, $company_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Per-Piece Costing Report</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { font-family: Arial, Helvetica, sans-serif; background: #f4f6f9; padding: 20px; }
.card { border: 1px solid #dee2e6; border-radius: 0.5rem; margin-bottom: 20px; }
.card-header { background: #6c757d; color: #fff; font-weight: 500; }
.table thead th { background: #e9ecef; }
.chart-container { position: relative; width: 100%; height: 400px; margin-bottom: 20px; }
.costing-box { background: #fff; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #007bff; }
.costing-box h5 { margin-bottom: 10px; color: #007bff; }
.metric { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
.metric-label { font-weight: 500; color: #666; }
.metric-value { font-weight: 600; color: #333; }
.highlight { background: #fff3cd; padding: 10px; border-radius: 4px; margin-bottom: 10px; border-left: 3px solid #ffc107; }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="container-fluid">
    <h2>Per-Piece Costing Report</h2>
    
    <!-- Job Selection -->
    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Job Number</label>
                    <input type="text" name="job_no" class="form-control" value="<?= htmlspecialchars($job_no) ?>" placeholder="Enter job no...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Report Type</label>
                    <select name="report_type" class="form-control">
                        <option value="summary" <?=($report_type==='summary')?'selected':''?>>Summary</option>
                        <option value="detailed" <?=($report_type==='detailed')?'selected':''?>>Detailed</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                </div>
            </form>
        </div>
    </div>

    <?php if($jobInfo && $job_no): ?>
    
    <!-- Job Info Panel -->
    <div class="card">
        <div class="card-header">Job Information</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Job No:</strong> <?= htmlspecialchars($jobInfo['job_no']) ?><br>
                    <strong>Design:</strong> <?= htmlspecialchars($jobInfo['design_name'] ?? 'N/A') ?>
                </div>
                <div class="col-md-3">
                    <strong>Size:</strong> <?= htmlspecialchars($jobInfo['size'] ?? 'N/A') ?><br>
                    <strong>Quantity:</strong> <?= intval($jobInfo['quantity']) ?> pcs
                </div>
                <div class="col-md-3">
                    <strong>Vendor:</strong> <?= htmlspecialchars($jobInfo['embroidery_vendor_name'] ?? 'N/A') ?><br>
                    <strong>Status:</strong> <?= htmlspecialchars($jobInfo['status'] ?? 'N/A') ?>
                </div>
                <div class="col-md-3">
                    <strong>Created:</strong> <?= htmlspecialchars($jobInfo['created_at'] ?? 'N/A') ?>
                </div>
            </div>
        </div>
    </div>

    <?php if(!empty($stitchingCosts)): ?>
    
    <?php if($report_type === 'summary'): ?>
    
    <!-- SUMMARY REPORT -->
    
    <!-- Overall Summary Box -->
    <div class="card">
        <div class="card-header">Overall Costing Summary</div>
        <div class="card-body">
            <?php 
            $grandTotal = 0;
            $grandPerPiece = 0;
            $typeCount = count($stitchingCosts);
            $totalEntries = 0;
            foreach($stitchingCosts as $type => $cost) {
                $grandTotal += $cost['total_amount'];
                $totalEntries += $cost['entries'];
            }
            $grandPerPiece = ($jobInfo['quantity'] > 0) ? $grandTotal / $jobInfo['quantity'] : 0;
            ?>
            <div class="row">
                <div class="col-md-3">
                    <div class="costing-box">
                        <h5>Total Cost</h5>
                        <div style="font-size: 28px; color: #007bff; font-weight: bold;">
                            ₨<?= number_format($grandTotal, 2) ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="costing-box">
                        <h5>Per-Piece Cost</h5>
                        <div style="font-size: 28px; color: #28a745; font-weight: bold;">
                            ₨<?= number_format($grandPerPiece, 2) ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="costing-box">
                        <h5>Types Count</h5>
                        <div style="font-size: 28px; color: #ffc107; font-weight: bold;">
                            <?= $typeCount ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="costing-box">
                        <h5>Total Entries</h5>
                        <div style="font-size: 28px; color: #6c757d; font-weight: bold;">
                            <?= $totalEntries ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Type-wise Breakdown -->
    <div class="card">
        <div class="card-header">Per-Type Breakdown</div>
        <div class="card-body">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-end">Per-Piece</th>
                        <th class="text-center">% of Total</th>
                        <th class="text-center">Entries</th>
                        <th class="text-center">Vendors</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($allTypes as $typeKey => $typeName):
                    $cost = $stitchingCosts[$typeKey] ?? null;
                    $hasData = $cost !== null;
                    $totalAmount = $cost['total_amount'] ?? 0;
                    $perPiece = $cost['per_piece'] ?? 0;
                    $entries = $cost['entries'] ?? 0;
                    $vendors = $cost['vendors'] ?? 0;
                    $percentage = $grandTotal > 0 ? round(($totalAmount / $grandTotal) * 100, 1) : 0;
                    $cellStyle = $hasData ? '' : 'style="background:#ffebee;color:#dc3545;"';
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($typeName) ?></strong></td>
                        <td class="text-end" <?= $cellStyle ?>><?= $hasData ? '₨'.number_format($totalAmount, 2) : 'NOT SET' ?></td>
                        <td class="text-end" <?= $cellStyle ?> style="color: #2196F3; font-weight: 600;"><?= $hasData ? '₨'.number_format($perPiece, 2) : 'NOT SET' ?></td>
                        <td class="text-center">
                            <?php if($hasData && $percentage > 0): ?>
                                <span class="badge bg-info">
                                    <?= $percentage ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" <?= $cellStyle ?>><?= $hasData ? $entries : '0' ?></td>
                        <td class="text-center" <?= $cellStyle ?>><?= $hasData ? $vendors : '0' ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="table-light" style="font-weight: 600;">
                        <td>TOTAL</td>
                        <td class="text-end">₨<?= number_format($grandTotal, 2) ?></td>
                        <td class="text-end" style="color: #d32f2f;">₨<?= number_format($grandPerPiece, 2) ?></td>
                        <td class="text-center">100%</td>
                        <td class="text-center" colspan="2"><?= $totalEntries ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Chart -->
    <div class="card">
        <div class="card-header">Cost Distribution Chart</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="costChart"></canvas>
            </div>
        </div>
    </div>

    <?php elseif($report_type === 'detailed'): ?>
    
    <!-- DETAILED REPORT -->
    
    <div class="card">
        <div class="card-header">Detailed Cost Analysis</div>
        <div class="card-body">
        <?php foreach($stitchingCosts as $type => $cost): 
            $typeEntries = getDetailedEntriesByType($conn, $job_no, $type, $company_id);
        ?>
            <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #e0e0e0;">
                <h5 style="color: #007bff; margin-bottom: 10px;">
                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) ?>
                </h5>
                <div class="highlight">
                    <strong>Total Amount:</strong> ₨<?= number_format($cost['total_amount'], 2) ?> | 
                    <strong>Per-Piece:</strong> ₨<?= number_format($cost['per_piece'], 2) ?> | 
                    <strong>Entries:</strong> <?= $cost['entries'] ?> | 
                    <strong>Vendors:</strong> <?= $cost['vendors'] ?>
                </div>
                
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr><th>Vendor/Name</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th><th class="text-right">Sub Total</th></tr></thead>
                    <tbody>
                    <?php if(!empty($typeEntries)): 
                        foreach($typeEntries as $entry):
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($entry['name'] ?? $entry['lot_no'] ?? 'N/A') ?></td>
                            <td class="text-right"><?= $entry['qty'] ?></td>
                            <td class="text-right">₨<?= number_format($entry['rate'] ?? 0, 2) ?></td>
                            <td class="text-right">₨<?= number_format($entry['amount'] ?? 0, 2) ?></td>
                            <td class="text-right">₨<?= number_format($entry['sub_total'] ?? 0, 2) ?></td>
                        </tr>
                    <?php 
                        endforeach;
                    else:
                    ?>
                        <tr><td colspan="5" class="text-center">No detailed entries</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php endif; ?>

    <?php else: ?>
    <!-- No data -->
    <div class="alert alert-warning">
        <strong>No stitching bill entries found</strong> for job: <?= htmlspecialchars($job_no) ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- No job selected -->
    <div class="alert alert-info">
        <strong>Enter a job number</strong> to generate the per-piece costing report.
    </div>
    <?php endif; ?>

</div>

<script>
<?php if($job_no && !empty($stitchingCosts)): ?>
// Chart.js setup
var ctx = document.getElementById('costChart');
if(ctx) {
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [<?php 
                $labels = [];
                foreach($stitchingCosts as $type => $cost) {
                    $labels[] = "'".addslashes(ucfirst(str_replace('_', ' ', $type)))."'";
                }
                echo implode(', ', $labels);
            ?>],
            datasets: [{
                data: [<?php 
                    $data = [];
                    foreach($stitchingCosts as $type => $cost) {
                        $data[] = $cost['total_amount'];
                    }
                    echo implode(', ', $data);
                ?>],
                backgroundColor: [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                    '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384',
                    '#36A2EB', '#FFCE56', '#FF9F40'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}
<?php endif; ?>
</script>

</body>
</html>