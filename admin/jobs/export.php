<?php
$page_identifier = 'jobs/export.php';
require_once "../../config/db.php";

// ------------------------------------------------------------
// 1. Get filter parameters (exactly as in jobs_list.php)
// ------------------------------------------------------------
$filter_job_no   = isset($_GET['job_no']) && is_array($_GET['job_no']) ? $_GET['job_no'] : [];
$filter_serial_no= isset($_GET['serial_no']) && is_array($_GET['serial_no']) ? $_GET['serial_no'] : [];
$filter_design   = isset($_GET['design']) && is_array($_GET['design']) ? $_GET['design'] : [];
$filter_brand    = isset($_GET['brand']) && is_array($_GET['brand']) ? $_GET['brand'] : [];
$filter_vendor   = isset($_GET['vendor']) && is_array($_GET['vendor']) ? $_GET['vendor'] : [];
$filter_size     = isset($_GET['size']) && is_array($_GET['size']) ? $_GET['size'] : [];
$filter_fabric   = isset($_GET['fabric']) && is_array($_GET['fabric']) ? $_GET['fabric'] : [];
$filter_status   = isset($_GET['status']) && is_array($_GET['status']) ? $_GET['status'] : [];
$filter_from_date= isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$filter_to_date  = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

// ------------------------------------------------------------
// 2. Build WHERE clause (identical to jobs_list.php)
// ------------------------------------------------------------
$where = [];

if (!empty($filter_job_no)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_job_no);
    $where[] = "job_no IN (" . implode(',', $list) . ")";
}
if (!empty($filter_serial_no)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_serial_no);
    $where[] = "serial_no IN (" . implode(',', $list) . ")";
}
if (!empty($filter_design)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_design);
    $where[] = "design_name IN (" . implode(',', $list) . ")";
}
if (!empty($filter_brand)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_brand);
    $where[] = "brand_name IN (" . implode(',', $list) . ")";
}
if (!empty($filter_vendor)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_vendor);
    $where[] = "embroidery_vendor_name IN (" . implode(',', $list) . ")";
}
if (!empty($filter_size)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_size);
    $where[] = "size IN (" . implode(',', $list) . ")";
}
if (!empty($filter_fabric)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_fabric);
    $where[] = "fabric_name IN (" . implode(',', $list) . ")";
}
if (!empty($filter_status)) {
    $list = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $filter_status);
    $where[] = "status IN (" . implode(',', $list) . ")";
}
if (!empty($filter_from_date)) $where[] = "job_date >= '" . mysqli_real_escape_string($conn, $filter_from_date) . "'";
if (!empty($filter_to_date))   $where[] = "job_date <= '" . mysqli_real_escape_string($conn, $filter_to_date) . "'";

$where_sql = count($where) ? "WHERE " . implode(" AND ", $where) : "";
$sql = "SELECT * FROM jobs $where_sql ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

// Check if updated_at column exists
$has_updated_at = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM jobs LIKE 'updated_at'")) > 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Jobs Export</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12pt;
            margin: 20px;
            color: #000;
        }
        .header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h2 { font-size: 18pt; margin: 0; }
        .header p { font-size: 12pt; margin: 5px 0 0; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px 4px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f2f2f2;
            font-weight: bold;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8pt;
            border-top: 1px solid #000;
            padding-top: 8px;
        }
    </style>
</head>
<body>
<div id="exportContent">
    <div class="header">
        <h2>JOBS LIST (EXPORT)</h2>
        <p>Generated on: <?= date('d-m-Y H:i:s') ?></p>
        <?php if(!empty($filter_from_date) || !empty($filter_to_date) || !empty($filter_status) || !empty($filter_brand) || !empty($filter_vendor)): ?>
        <p><strong>Filters applied:</strong> 
        <?php 
            $parts = [];
            if(!empty($filter_from_date)) $parts[] = "From: $filter_from_date";
            if(!empty($filter_to_date))   $parts[] = "To: $filter_to_date";
            if(!empty($filter_status))    $parts[] = "Status: " . implode(', ', $filter_status);
            if(!empty($filter_brand))     $parts[] = "Brand: " . implode(', ', $filter_brand);
            if(!empty($filter_vendor))    $parts[] = "Vendor: " . implode(', ', $filter_vendor);
            if(!empty($filter_design))    $parts[] = "Design: " . implode(', ', $filter_design);
            if(!empty($filter_size))      $parts[] = "Size: " . implode(', ', $filter_size);
            if(!empty($filter_fabric))    $parts[] = "Fabric: " . implode(', ', $filter_fabric);
            echo implode(' | ', $parts);
        ?>
        </p>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>JOB NO</th>
            
                <th>DESIGN</th>
                <th>BRAND</th>
                <th>VENDOR</th>
                <th>SIZE</th>
                <th>FABRIC</th>
                <th>QTY</th>
                <th>START DATE</th>
                <th>STATUS</th>
            
            </tr>
        </thead>
        <tbody>
            <?php if(mysqli_num_rows($result) > 0): ?>
                <?php while($row = mysqli_fetch_assoc($result)):
                    $status = strtoupper($row['status'] ?? 'EMBROIDERY');
                    $job_date = $row['job_date'] ?? $row['created_at'] ?? date('Y-m-d');
                    $formatted_date = date('d-m-Y', strtotime($job_date));
                    $serial_display = !empty($row['serial_no']) ? strtoupper(htmlspecialchars($row['serial_no'])) : '—';
                    $update_date = '';
                    if($has_updated_at && !empty($row['updated_at'])) {
                        $update_date = date('d-m-Y H:i', strtotime($row['updated_at']));
                    }
                ?>
                <tr>
                    <td><?= strtoupper(htmlspecialchars($row['job_no'])) ?></td>
                    <td><?= strtoupper(htmlspecialchars($row['design_name'])) ?></td>
                    <td><?= strtoupper(htmlspecialchars($row['brand_name'] ?? 'N/A')) ?></td>
                    <td><?= strtoupper(htmlspecialchars($row['embroidery_vendor_name'] ?? 'N/A')) ?></td>
                    <td><?= strtoupper(htmlspecialchars($row['size'] ?? 'N/A')) ?></td>
                    <td><?= strtoupper(htmlspecialchars($row['fabric_name'] ?? 'N/A')) ?></td>
                    <td style="text-align:center"><?= $row['quantity'] ?></td>
                    <td style="text-align:center"><?= $formatted_date ?></td>
                    <td><?= $status ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="11" style="text-align:center">No jobs found with the selected filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">Garment ERP System – Job List Report</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    window.onload = function() {
        const element = document.getElementById('exportContent');
        const opt = {
            margin: [0.3, 0.3, 0.3, 0.3],
            filename: 'jobs_export_<?= date('Ymd_His') ?>.pdf',
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 1.8, letterRendering: true, useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
        };
        html2pdf().set(opt).from(element).save();
    };
</script>
</body>
</html>