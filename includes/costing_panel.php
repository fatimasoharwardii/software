<?php
require_once "../../config/db.php";
require_once "functions.php"; 

$job_no = isset($_GET['job_no']) ? $_GET['job_no'] : '';
$serial_no = isset($_GET['serial_no']) ? $_GET['serial_no'] : '';

// Fetch job details
$jobInfo = null;
if($job_no){
    $job_no_escaped = mysqli_real_escape_string($conn, $job_no);
    $q = mysqli_query($conn,"SELECT * FROM jobs WHERE job_no='$job_no_escaped' LIMIT 1");
    $jobInfo = mysqli_fetch_assoc($q);
} elseif($serial_no){
    $serial_escaped = mysqli_real_escape_string($conn, $serial_no);
    $q = mysqli_query($conn,"SELECT * FROM jobs WHERE serial_no='$serial_escaped' LIMIT 1");
    $jobInfo = mysqli_fetch_assoc($q);
    if($jobInfo) $job_no = $jobInfo['job_no'];
}

// Auto Rates (same logic)
$autoRates = [];
if($job_no && $jobInfo){
    $job_qty = $jobInfo['quantity'] ?? 1;
    if($job_qty <= 0) $job_qty = 1;

    $fabric_q = $conn->query("SELECT SUM(amount) as total FROM fabric_issue WHERE job_no='$job_no_escaped'");
    $fabric_row = $fabric_q->fetch_assoc();
    $autoRates['FABRIC'] = ($fabric_row['total'] ?? 0) / $job_qty;

    $emb_q = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no='$job_no_escaped' AND tab_type='emb'");
    $emb_row = $emb_q->fetch_assoc();
    $autoRates['EMB'] = ($emb_row['total'] ?? 0) / $job_qty;

    $stitch_q = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no='$job_no_escaped' AND tab_type='stitching_depart'");
    $stitch_row = $stitch_q->fetch_assoc();
    $autoRates['STITCHING'] = ($stitch_row['total'] ?? 0) / $job_qty;

    $hand_q = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no='$job_no_escaped' AND tab_type='handwork'");
    $hand_row = $hand_q->fetch_assoc();
    $autoRates['HANDWORK'] = ($hand_row['total'] ?? 0) / $job_qty;

    $mat_q = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no='$job_no_escaped' AND tab_type='material'");
    $mat_row = $mat_q->fetch_assoc();
    $autoRates['MATERIAL'] = ($mat_row['total'] ?? 0) / $job_qty;

    $other_q = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no='$job_no_escaped' AND tab_type='other'");
    $other_row = $other_q->fetch_assoc();
    $autoRates['OTHER EXP'] = ($other_row['total'] ?? 0) / $job_qty;
}

$manualRates = [];
if($job_no){
    $cost_query = mysqli_query($conn, "SELECT * FROM manual_costing WHERE job_no='$job_no_escaped'");
    while($cost = mysqli_fetch_assoc($cost_query)){
        $manualRates[$cost['cost_type']] = floatval($cost['manual_rate']);
    }
}

$costingItems = ['FABRIC', 'EMB', 'STITCHING', 'HANDWORK', 'MATERIAL', 'OTHER EXP'];

$stitchingDetails = [];
$processes = ['MASTER', 'THEKDAR', 'STITCHING', 'CROPING', 'CHECKING', 'OVERLOCK', 'PRESSMAN', 'PACKING'];
if($job_no && $jobInfo){
    $job_qty = $jobInfo['quantity'] ?? 1;
    if($job_qty <= 0) $job_qty = 1;
    foreach($processes as $proc){
        $tab_key = strtolower(str_replace(' ', '_', $proc));
        if($proc == 'OVERLOCK') $tab_key = 'overlook';
        if($proc == 'STITCHING') $tab_key = 'stitching';
        $q = $conn->query("SELECT SUM(amount) as total FROM stitching_bill_items WHERE job_no='$job_no_escaped' AND tab_type='$tab_key'");
        $row = $q->fetch_assoc();
        $stitchingDetails[$proc] = ($row['total'] ?? 0) / $job_qty;
    }
}

if(isset($_POST['save_manual_costs'])){
    $job_no_save = mysqli_real_escape_string($conn, $_POST['job_no']);
    $cost_data = json_decode($_POST['cost_data'], true);
    foreach($cost_data as $cost){
        $type = mysqli_real_escape_string($conn, $cost['type']);
        $manual_rate = floatval($cost['manual_rate']);
        $auto_rate = floatval($cost['auto_rate']);
        $diff = $auto_rate - $manual_rate;
        $check = $conn->query("SELECT id FROM manual_costing WHERE job_no='$job_no_save' AND cost_type='$type'");
        if($check->num_rows > 0){
            $conn->query("UPDATE manual_costing SET manual_rate=$manual_rate, auto_rate=$auto_rate, difference=$diff, is_edited=1 WHERE job_no='$job_no_save' AND cost_type='$type'");
        } else {
            $conn->query("INSERT INTO manual_costing (job_no, cost_type, manual_rate, auto_rate, difference, is_edited) VALUES ('$job_no_save', '$type', $manual_rate, $auto_rate, $diff, 1)");
        }
    }
    echo "success";
    exit;
}

function fmt($num){
    return number_format($num, 2);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Costing – All in One Row</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { box-sizing: border-box; }
    body {
        background: #eef2f5;
        font-family: 'Segoe UI', system-ui, sans-serif;
        padding: 20px;
        margin: 0;
    }
    .costing-wrapper {
        max-width: 1600px;
        margin-left: 14%;
        background: white;
        border-radius: 20px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        overflow: hidden;
    }
    .two-col-layout {
        display: flex;
        flex-wrap: wrap;
        gap: 0;
    }
    .left-col {
        width: 450px;        /* wider to accommodate three items in one row */
        background: #fafcff;
        border-right: 1px solid #e2e8f0;
        padding: 20px 16px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .right-col {
        flex: 1;
        padding: 16px 20px;
        background: white;
        display: flex;
        flex-direction: row;
        gap: 16px;
        align-items: stretch;
    }
    /* ONE ROW FOR JOB, SERIAL, AND LOAD BUTTON */
    .search-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1px;            /* 1px gap between all three */
        align-items: flex-end;
    }
    .search-group {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .search-group label {
        font-weight: 600;
        font-size: 0.75rem;
        color: #b45f06;
        margin-bottom: 2px;
    }
    .search-group input {
        width: 100%;
        padding: 8px 10px;
        border: 1.5px solid #e2e8f0;
        border-radius: 30px;
        font-size: 0.8rem;
        outline: none;
    }
    .search-group input:focus {
        border-color: #f39c12;
    }
    .load-btn {
        background: #f39c12;
        border: none;
        padding: 8px 20px;
        border-radius: 30px;
        color: white;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        white-space: nowrap;
        height: 42px;        /* match input height */
        margin-bottom: 0;
    }
    /* MINI JOB INFO */
    .job-info-mini {
        background: #fff8f0;
        border-radius: 16px;
        padding: 10px;
        font-size: 0.75rem;
        border: 1px solid #ffe0b5;
    }
    .job-info-mini table {
        width: 100%;
        border-collapse: collapse;
    }
    .job-info-mini td {
        padding: 5px 6px;
        border-bottom: 1px dashed #ffe0b5;
    }
    .job-info-mini td:first-child {
        font-weight: 700;
        color: #b45f06;
        width: 40%;
    }
    /* TABLES STYLING */
    .table-container {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding: 2px;
        background: white;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        height: 37vh;
        overflow: hidden;
    }
    .table-container h4 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 8px 0;
        padding-left: 8px;
        color: #e67e22;
        flex-shrink: 0;
    }
    .excel-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        font-family: 'Segoe UI', 'Consolas', monospace;
    }
    .excel-table th, .excel-table td {
        border: 1px solid #dee2e6;
        padding: 3px 6px;
        vertical-align: middle;
    }
    .excel-table th {
        background: #f8f9fc;
        font-weight: 700;
        text-align: center;
        border-bottom: 2px solid #f39c12;
        font-size: 0.9rem;
    }
    .text-right { text-align: right; }
    .total-row { background-color: #fef5e7; font-weight: 700; border-top: 2px solid #f39c12; }
    .diff-positive { color: #2c7a4d; font-weight: 600; }
    .diff-negative { color: #c92a2a; font-weight: 600; }
    .party-input {
        width: 90px;
        padding: 2px 4px;
        text-align: right;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 0.8rem;
    }
    .edit-buttons {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-bottom: 8px;
        flex-shrink: 0;
    }
    .btn-sm-custom {
        padding: 4px 14px;
        font-size: 0.75rem;
        border-radius: 30px;
        font-weight: 600;
        border: none;
        cursor: pointer;
    }
    .table-scroll {
        flex: 1;
        overflow-y: auto;
        min-height: 0;
    }
    @media (max-width: 900px) {
        .costing-wrapper { margin-left: 2%; }
        .left-col { width: 100%; border-right: none; border-bottom: 1px solid #e2e8f0; }
        .two-col-layout { flex-direction: column; }
        .right-col { flex-direction: column; }
        .table-container { height: auto; min-height: 280px; }
        .search-row { flex-direction: column; gap: 8px; }
        .load-btn { width: 100%; }
    }
</style>
</head>
<body>
<div class="costing-wrapper" style="margin-bottom: 30px; background: white; border-radius: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
    <div style="display: flex; flex-wrap: wrap;">
        <!-- Left side: search + job info -->
        <div style="width: 350px; background:#fafcff; border-right:1px solid #e2e8f0; padding: 20px 16px;">
            <div style="display: flex; gap: 5px; align-items: flex-end;">
                <div style="flex:1">
                    <label style="font-weight:600; font-size:0.75rem; color:#b45f06;">JOB #</label>
                    <input type="text" id="jobSearchInput" list="jobSuggestList" class="form-control" placeholder="Job number" value="<?= htmlspecialchars($job_no) ?>">
                </div>
                <div style="flex:1">
                    <label style="font-weight:600; font-size:0.75rem; color:#b45f06;">SERIAL #</label>
                    <input type="text" id="serialSearchInput" class="form-control" placeholder="Serial number" value="<?= htmlspecialchars($serial_no) ?>">
                </div>
                <button class="btn btn-warning" onclick="loadJob()" style="margin-bottom:2px;"><i class="fas fa-search"></i> Load</button>
            </div>
            <div class="mt-3 p-2 bg-light rounded" style="font-size:0.75rem;">
                <table class="table table-sm small mb-0">
                    <?php if($jobInfo): ?>
                    <tr><td style="width:40%">Job No</td><td><strong><?= htmlspecialchars($jobInfo['job_no']) ?></strong></td></tr>
                    <tr><td>Serial</td><td><?= htmlspecialchars($jobInfo['serial_no'] ?? '—') ?></td></tr>
                    <tr><td>Design</td><td><?= htmlspecialchars($jobInfo['design_name'] ?? '—') ?></td></tr>
                    <tr><td>Fabric</td><td><?= htmlspecialchars($jobInfo['fabric_name'] ?? '—') ?></td></tr>
                    <tr><td>Size</td><td><?= htmlspecialchars($jobInfo['size'] ?? '—') ?></td></tr>
                    <tr><td>Quantity</td><td><?= intval($jobInfo['quantity'] ?? 0) ?> pcs</td></tr>
                    <tr><td>Brand</td><td><?= htmlspecialchars($jobInfo['brand_name'] ?? '—') ?></td></tr>
                    <tr><td>Emb Name</td><td><?= htmlspecialchars($jobInfo['emb_name'] ?? '—') ?></td></tr>
                    <?php else: ?>
                    <tr><td colspan="2" class="text-muted">No job loaded</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <!-- Right side: two tables -->
        <div style="flex:1; padding: 16px 20px;">
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
                <button id="editRatesBtn" class="btn btn-sm btn-warning" onclick="enableEditing()"><i class="fas fa-edit"></i> Edit Party Rates</button>
                <button id="saveRatesBtn" class="btn btn-sm btn-success" style="display:none;" onclick="saveAllRates()"><i class="fas fa-save"></i> Save</button>
                <button id="cancelEditBtn" class="btn btn-sm btn-secondary" style="display:none;" onclick="cancelEditing()"><i class="fas fa-times"></i> Cancel</button>
            </div>
            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                <!-- Main costing table -->
                <div style="flex:1; min-width:280px; border:1px solid #dee2e6; border-radius:8px; padding:8px;">
                    <h5 style="font-size:1rem; font-weight:700; color:#e67e22;"><i class="fas fa-chart-line"></i> Costing Analysis (Rs)</h5>
                    <div style="overflow-x:auto;">
                        <table class="table table-bordered table-sm" id="mainCostingTable">
                            <thead><tr><th>ITEM</th><th class="text-end">PARTY</th><th class="text-end">COSTING</th><th class="text-end">DIFF</th></tr></thead>
                            <tbody id="costingTbody">
                                <?php foreach($costingItems as $item): 
                                    $auto = $autoRates[$item] ?? 0;
                                    $manual = isset($manualRates[$item]) ? $manualRates[$item] : 0;
                                    $diff = $manual - $auto;
                                ?>
                                <tr data-item="<?= $item ?>">
                                    <td><?= $item ?></td>
                                    <td class="text-end party-cell">
                                        <span class="party-display"><?= fmt($manual) ?></span>
                                        <input type="number" step="0.01" class="manual-input form-control form-control-sm" value="<?= $manual ?>" style="display:none; width:100px;">
                                    </td>
                                    <td class="text-end auto-val"><?= fmt($auto) ?></td>
                                    <td class="text-end diff-val <?= $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : '') ?>"><?= ($diff >= 0 ? '+' : '') . fmt($diff) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <?php
                                    $sumParty = array_sum(array_map(function($item) use ($manualRates, $autoRates) {
                                        return $manualRates[$item] ?? 0;
                                    }, $costingItems));
                                    $sumAuto = array_sum($autoRates);
                                    $sumDiff = $sumParty - $sumAuto;
                                ?>
                                <tr class="table-warning">
                                    <td><strong>TOTAL</strong></td>
                                    <td id="totalPartyCell" class="text-end"><strong><?= fmt($sumParty) ?></strong></td>
                                    <td id="totalAutoCell" class="text-end"><strong><?= fmt($sumAuto) ?></strong></td>
                                    <td id="totalDiffCell" class="text-end <?= $sumDiff > 0 ? 'text-success' : ($sumDiff < 0 ? 'text-danger' : '') ?>"><strong><?= ($sumDiff >= 0 ? '+' : '') . fmt($sumDiff) ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <!-- Stitching details table -->
                <div style="flex:1; min-width:250px; border:1px solid #dee2e6; border-radius:8px; padding:8px;">
                    <h5 style="font-size:1rem; font-weight:700; color:#e67e22;"><i class="fas fa-tshirt"></i> Stitching Details (Rs/pc)</h5>
                    <div style="overflow-x:auto;">
                        <table class="table table-bordered table-sm" id="stitchingDetailsTable">
                            <thead><tr><th>PROCESS</th><th class="text-end">RATE</th></tr></thead>
                            <tbody>
                                <?php 
                                    $totalStitch = 0;
                                    foreach($processes as $proc):
                                        $rate = $stitchingDetails[$proc] ?? 0;
                                        $totalStitch += $rate;
                                ?>
                                <tr><td><?= $proc ?></td><td class="text-end auto-stitch-rate"><?= fmt($rate) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-warning"><td><strong>TOTAL</strong></td><td id="totalStitchingVal" class="text-end"><strong><?= fmt($totalStitch) ?></strong></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<datalist id="jobSuggestList">
    <?php 
    $jobs_all = mysqli_query($conn, "SELECT job_no FROM jobs ORDER BY job_no DESC LIMIT 50");
    while($j = mysqli_fetch_assoc($jobs_all)): ?>
    <option value="<?= htmlspecialchars($j['job_no']) ?>">
    <?php endwhile; ?>
</datalist>

<script>
let originalManualValues = {};

function loadJob() {
    let jobNo = document.getElementById('jobSearchInput').value.trim();
    let serialNo = document.getElementById('serialSearchInput').value.trim();
    if (jobNo !== "") {
        window.location.href = '?job_no=' + encodeURIComponent(jobNo);
    } else if (serialNo !== "") {
        window.location.href = '?serial_no=' + encodeURIComponent(serialNo);
    } else {
        alert('Please enter either Job Number or Serial Number');
    }
}

function enableEditing(){
    document.querySelectorAll('.party-display').forEach(span => span.style.display = 'none');
    document.querySelectorAll('.party-input').forEach(inp => {
        inp.style.display = 'inline-block';
        let row = inp.closest('tr');
        if(row) originalManualValues[row.dataset.item] = inp.value;
    });
    document.getElementById('editRatesBtn').style.display = 'none';
    document.getElementById('saveRatesBtn').style.display = 'inline-block';
    document.getElementById('cancelEditBtn').style.display = 'inline-block';
}

function cancelEditing(){
    document.querySelectorAll('.party-display').forEach(span => span.style.display = 'inline');
    document.querySelectorAll('.party-input').forEach(inp => {
        inp.style.display = 'none';
        let row = inp.closest('tr');
        if(row && originalManualValues[row.dataset.item] !== undefined) inp.value = originalManualValues[row.dataset.item];
        let span = inp.parentElement.querySelector('.party-display');
        if(span) span.innerText = parseFloat(inp.value).toFixed(2);
    });
    document.getElementById('editRatesBtn').style.display = 'inline-block';
    document.getElementById('saveRatesBtn').style.display = 'none';
    document.getElementById('cancelEditBtn').style.display = 'none';
    location.reload();
}

function saveAllRates(){
    const jobNo = '<?= addslashes($job_no) ?>';
    if(!jobNo){ alert('No job loaded'); return; }
    const rows = document.querySelectorAll('#mainCostingTable tbody tr');
    let costData = [];
    let newPartySum = 0;
    rows.forEach(row => {
        const item = row.dataset.item;
        const autoRate = parseFloat(row.querySelector('.auto-val').innerText.replace(/,/g,'')) || 0;
        let manualInput = row.querySelector('.party-input');
        let manualRate = parseFloat(manualInput.value) || 0;
        newPartySum += manualRate;
        costData.push({ type: item, manual_rate: manualRate, auto_rate: autoRate });
        let displaySpan = row.querySelector('.party-display');
        displaySpan.innerText = manualRate.toFixed(2);
        let diff = manualRate - autoRate;
        let diffCell = row.querySelector('.diff-val');
        diffCell.innerText = (diff >= 0 ? '+' : '') + diff.toFixed(2);
        diffCell.className = 'text-right diff-val ' + (diff > 0 ? 'diff-positive' : (diff < 0 ? 'diff-negative' : ''));
    });
    let totalAuto = 0;
    document.querySelectorAll('.auto-val').forEach(el => { totalAuto += parseFloat(el.innerText.replace(/,/g,'')); });
    document.getElementById('totalPartyCell').innerHTML = '<strong>' + newPartySum.toFixed(2) + '</strong>';
    document.getElementById('totalAutoCell').innerHTML = '<strong>' + totalAuto.toFixed(2) + '</strong>';
    let totalDiff = newPartySum - totalAuto;
    let totalDiffCell = document.getElementById('totalDiffCell');
    totalDiffCell.innerHTML = '<strong>' + (totalDiff >= 0 ? '+' : '') + totalDiff.toFixed(2) + '</strong>';
    totalDiffCell.className = 'text-right ' + (totalDiff > 0 ? 'diff-positive' : (totalDiff < 0 ? 'diff-negative' : ''));
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'save_manual_costs=1&job_no=' + encodeURIComponent(jobNo) + '&cost_data=' + encodeURIComponent(JSON.stringify(costData))
    })
    .then(res => res.text())
    .then(data => {
        if(data.trim() === 'success'){ alert('Party rates saved!'); location.reload(); }
        else alert('Error: ' + data);
    })
    .catch(err => alert('Request failed'));
}
</script>
</body>
</html>