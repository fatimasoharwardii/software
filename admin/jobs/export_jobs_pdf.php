<?php
$page_identifier = 'jobs/export_jobs_pdf.php';
require_once "../../config/db.php";
require_once "../../config/auth.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

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

// Build the redirect URL for dashboard (correct path)
$redirect_url = 'dashboard.php';
$params = [];
if ($status_filter) $params[] = 'status=' . urlencode($status_filter);
if ($cmt_party_filter) $params[] = 'cmt_party=' . urlencode($cmt_party_filter);
if ($embroidery_vendor_filter) $params[] = 'embroidery_vendor=' . urlencode($embroidery_vendor_filter);

// If filters exist, add them to URL
if (!empty($params)) {
    $redirect_url .= '?' . implode('&', $params);
}

// Include PDF library (you need to install dompdf)
require_once '../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Initialize Dompdf
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

// Build HTML content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . $title . '</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            margin: 20px;
        }
        h2 {
            color: #F39C12;
            text-align: center;
            margin-bottom: 20px;
        }
        .report-info {
            margin-bottom: 20px;
            text-align: center;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background: #F39C12;
            color: white;
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
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
    <h2>' . $title . '</h2>
    <div class="report-info">
        Generated on: ' . date('d-m-Y H:i:s') . '<br>
        Total Records: ' . ($jobs_result ? $jobs_result->num_rows : 0) . '
    </div>
     <table>
        <thead>
             <tr>
                <th>Job No</th>
                <th>Design Name</th>
                <th>Brand Name</th>';

if ($embroidery_vendor_column && $status_filter == 'Embroidery') {
    $html .= '<th>Embroidery Vendor</th>';
}
if ($cmt_party_column && $status_filter == 'CMT') {
    $html .= '<th>CMT Party</th>';
}

$html .= '
                <th>Status</th>
                <th>Quantity</th>
                <th>Start Date</th>
             </tr>
        </thead>
        <tbody>';

if ($jobs_result && $jobs_result->num_rows > 0) {
    while($job = $jobs_result->fetch_assoc()) {
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
        
        $html .= '<tr>
            <td>' . htmlspecialchars($job['job_no']) . '</td>
            <td>' . htmlspecialchars($job['design_name'] ?? 'N/A') . '</td>
            <td>' . $brand_name . '</td>';
        
        if ($embroidery_vendor_column && $status_filter == 'Embroidery') {
            $html .= '<td>' . htmlspecialchars($embroidery_vendor_name) . '</td>';
        }
        if ($cmt_party_column && $status_filter == 'CMT') {
            $html .= '<td>' . htmlspecialchars($cmt_party_name) . '</td>';
        }
        
        $html .= '<td>' . htmlspecialchars($job['status']) . '</td>
            <td>' . $job['quantity'] . '</td>
            <td>' . htmlspecialchars($job['start_date'] ?? 'N/A') . '</td>
        </tr>';
    }
} else {
    $colspan = 5;
    if ($embroidery_vendor_column && $status_filter == 'Embroidery') $colspan++;
    if ($cmt_party_column && $status_filter == 'CMT') $colspan++;
    $html .= '<tr><td colspan="' . $colspan . '" style="text-align: center;">No records found</td></tr>';
}

$html .= '
        </tbody>
     </table>
    <div class="footer">
        Garment ERP System - Admin Dashboard
    </div>
</body>
</html>';

// Load HTML to Dompdf
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'landscape');

// Render PDF
$dompdf->render();

// Get the PDF content
$pdf_content = $dompdf->output();

// Save PDF to temporary file
$temp_file = tempnam(sys_get_temp_dir(), 'pdf_');
file_put_contents($temp_file, $pdf_content);

// Set headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $title . '.pdf"');
header('Content-Length: ' . filesize($temp_file));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output PDF
readfile($temp_file);

// Delete temporary file
unlink($temp_file);

// Close database connection
$conn->close();

// JavaScript redirect to dashboard after download
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PDF Export Complete</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin-top: 50px;
            background: #f4f4f4;
        }
        .message-box {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: inline-block;
            max-width: 500px;
        }
        h3 {
            color: #28a745;
            margin-bottom: 15px;
        }
        p {
            color: #666;
            margin-bottom: 20px;
        }
        a {
            color: #F39C12;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .loader {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #F39C12;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="message-box">
        <h3><i class="fas fa-check-circle"></i> PDF Export Complete!</h3>
        <div class="loader"></div>
        <p>Your file has been downloaded successfully.<br>You will be redirected to dashboard in <span id="countdown">3</span> seconds...</p>
        <p><a href="' . $redirect_url . '">Click here if not redirected automatically</a></p>
    </div>
    
    <script>
        let seconds = 3;
        const countdownElement = document.getElementById("countdown");
        
        const interval = setInterval(function() {
            seconds--;
            if (countdownElement) {
                countdownElement.textContent = seconds;
            }
            if (seconds <= 0) {
                clearInterval(interval);
                window.location.href = "' . $redirect_url . '";
            }
        }, 1000);
        
        // Also redirect after 3 seconds as backup
        setTimeout(function() {
            window.location.href = "' . $redirect_url . '";
        }, 3000);
    </script>
</body>
</html>';
?>