<?php
require_once "../../config/db.php";



// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$cmt_party_filter = isset($_GET['cmt_party']) ? $_GET['cmt_party'] : '';
$embroidery_vendor_filter = isset($_GET['embroidery_vendor']) ? $_GET['embroidery_vendor'] : '';

// First, check what columns exist in jobs table
$jobs_columns_check = $conn->query("SHOW COLUMNS FROM jobs");
$jobs_columns = [];
if ($jobs_columns_check) {
    while($col = $jobs_columns_check->fetch_assoc()) {
        $jobs_columns[] = $col['Field'];
    }
}

// Determine which column is used for CMT party
$cmt_party_column = null;
if (in_array('cmt_party_id', $jobs_columns)) {
    $cmt_party_column = 'cmt_party_id';
} elseif (in_array('cmt_party', $jobs_columns)) {
    $cmt_party_column = 'cmt_party';
} elseif (in_array('party_id', $jobs_columns)) {
    $cmt_party_column = 'party_id';
} else {
    foreach ($jobs_columns as $col) {
        if (strpos($col, 'party') !== false && $col != 'embroidery_vendor_name') {
            $cmt_party_column = $col;
            break;
        }
    }
}

// Determine which column is used for Embroidery vendor
$embroidery_vendor_column = null;
if (in_array('embroidery_vendor_name', $jobs_columns)) {
    $embroidery_vendor_column = 'embroidery_vendor_name';
} elseif (in_array('embroidery_vendor_id', $jobs_columns)) {
    $embroidery_vendor_column = 'embroidery_vendor_id';
} elseif (in_array('vendor_id', $jobs_columns)) {
    $embroidery_vendor_column = 'vendor_id';
}

// Determine which column is used for Brand name
$brand_column = null;
if (in_array('brand_name', $jobs_columns)) {
    $brand_column = 'brand_name';
} elseif (in_array('brand', $jobs_columns)) {
    $brand_column = 'brand';
}

// Build the query
$query = "SELECT j.* FROM jobs j";
$where_conditions = [];

if ($status_filter) {
    $status_filter_safe = mysqli_real_escape_string($conn, $status_filter);
    $where_conditions[] = "j.status='$status_filter_safe'";
}

if ($cmt_party_filter && $status_filter == 'CMT' && $cmt_party_column) {
    $cmt_party_filter_safe = mysqli_real_escape_string($conn, $cmt_party_filter);
    $where_conditions[] = "j.$cmt_party_column='$cmt_party_filter_safe'";
}

if ($embroidery_vendor_filter && $status_filter == 'Embroidery' && $embroidery_vendor_column) {
    $embroidery_vendor_filter_safe = mysqli_real_escape_string($conn, $embroidery_vendor_filter);
    $where_conditions[] = "j.$embroidery_vendor_column='$embroidery_vendor_filter_safe'";
}

if (count($where_conditions) > 0) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY j.id DESC";
$jobs_result = $conn->query($query);

// Get title
$title = 'Jobs Report';
if ($status_filter) {
    $title = $status_filter . ' Jobs Report';
    if ($status_filter == 'CMT' && $cmt_party_filter && $cmt_party_column) {
        $party_name_query = $conn->query("SELECT party_name FROM parties WHERE id = '$cmt_party_filter_safe'");
        if ($party_name_query && $party_name_query->num_rows > 0) {
            $party = $party_name_query->fetch_assoc();
            $title = 'CMT Jobs Report - ' . $party['party_name'];
        }
    } elseif ($status_filter == 'Embroidery' && $embroidery_vendor_filter && $embroidery_vendor_column) {
        $vendor_name_query = $conn->query("SELECT party_name FROM parties WHERE id = '$embroidery_vendor_filter_safe'");
        if ($vendor_name_query && $vendor_name_query->num_rows > 0) {
            $vendor = $vendor_name_query->fetch_assoc();
            $title = 'Embroidery Jobs Report - ' . $vendor['party_name'];
        }
    }
}

// Set headers for PDF download
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo $title; ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 20px;
            font-size: 12px;
        }
        h2 {
            color: #F39C12;
            text-align: center;
            margin-bottom: 20px;
            font-size: 18px;
        }
        .report-info {
            margin-bottom: 20px;
            text-align: center;
            color: #666;
            font-size: 11px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background: #F39C12;
            color: white;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
            font-size: 12px;
        }
        td {
            padding: 6px 8px;
            border: 1px solid #ddd;
            font-size: 11px;
        }
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
        }
    </style>
</head>
<body>
    <h2><?php echo $title; ?></h2>
    <div class="report-info">
        Generated on: <?php echo date('d-m-Y H:i:s'); ?><br>
        Total Records: <?php echo ($jobs_result ? $jobs_result->num_rows : 0); ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Job No</th>
                <th>Design Name</th>
                <th>Brand Name</th>
                <?php if ($embroidery_vendor_column && $status_filter == 'Embroidery'): ?>
                <th>Embroidery Vendor</th>
                <?php endif; ?>
                <?php if ($cmt_party_column && $status_filter == 'CMT'): ?>
                <th>CMT Party</th>
                <?php endif; ?>
                <th>Status</th>
                <th>Quantity</th>
                <th>Start Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if($jobs_result && $jobs_result->num_rows > 0): ?>
                <?php while($job = $jobs_result->fetch_assoc()): 
                    // Get brand name
                    $brand_name = 'N/A';
                    if ($brand_column && isset($job[$brand_column])) {
                        $brand_name = !empty($job[$brand_column]) ? htmlspecialchars($job[$brand_column]) : 'N/A';
                    }
                    
                    // Get Embroidery vendor name
                    $embroidery_vendor_name = 'N/A';
                    if ($embroidery_vendor_column && $job[$embroidery_vendor_column]) {
                        $vendor_query = $conn->query("SELECT party_name FROM parties WHERE id = '" . $job[$embroidery_vendor_column] . "'");
                        if ($vendor_query && $vendor_query->num_rows > 0) {
                            $vendor = $vendor_query->fetch_assoc();
                            $embroidery_vendor_name = $vendor['party_name'];
                        }
                    }
                    
                    // Get CMT party name
                    $cmt_party_name = 'N/A';
                    if ($cmt_party_column && $job[$cmt_party_column]) {
                        $cmt_party_query = $conn->query("SELECT party_name FROM parties WHERE id = '" . $job[$cmt_party_column] . "'");
                        if ($cmt_party_query && $cmt_party_query->num_rows > 0) {
                            $cmt_party = $cmt_party_query->fetch_assoc();
                            $cmt_party_name = $cmt_party['party_name'];
                        }
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($job['job_no']); ?></td>
                    <td><?php echo htmlspecialchars($job['design_name'] ?? 'N/A'); ?></td>
                    <td><?php echo $brand_name; ?></td>
                    <?php if ($embroidery_vendor_column && $status_filter == 'Embroidery'): ?>
                    <td><?php echo htmlspecialchars($embroidery_vendor_name); ?></td>
                    <?php endif; ?>
                    <?php if ($cmt_party_column && $status_filter == 'CMT'): ?>
                    <td><?php echo htmlspecialchars($cmt_party_name); ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($job['status']); ?></td>
                    <td><?php echo $job['quantity']; ?></td>
                    <td><?php echo htmlspecialchars($job['start_date'] ?? 'N/A'); ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?php 
                        $colspan = 5;
                        if ($embroidery_vendor_column && $status_filter == 'Embroidery') $colspan++;
                        if ($cmt_party_column && $status_filter == 'CMT') $colspan++;
                        echo $colspan;
                    ?>" style="text-align: center;">No records found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="footer">
        Garment ERP System - Admin Dashboard
    </div>
</body>
</html>
<?php
// For printing/saving as PDF, you can use browser's print functionality
// This will open the print dialog where user can save as PDF
?>
<script>
    window.onload = function() {
        window.print();
    }
</script>