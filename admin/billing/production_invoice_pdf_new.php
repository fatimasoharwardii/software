<?php
$page_identifier = 'billing/production_invoice_pdf_new.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

if ($_SERVER['REQUEST_METHOD'] != 'POST' || !isset($_POST['data'])) {
    die("Direct access not allowed.");
}

$data = json_decode($_POST['data'], true);
if (!$data) die("Invalid data.");

// Verify that all jobs in the invoice belong to the current company
$invoice_no = htmlspecialchars($data['invoice_no']);
$invoice_date = htmlspecialchars($data['invoice_date']);
$customer_name = htmlspecialchars($data['customer_name']);
$jobs = $data['jobs'];
$grand_total = floatval($data['total']);
$subtotal = $grand_total;

// Check each job belongs to the user's company
foreach ($jobs as $job) {
    $job_no = $job['job_no'];
    $stmt = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
    $stmt->bind_param("si", $job_no, $company_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        die("Invalid job #" . htmlspecialchars($job_no) . " – does not belong to your company.");
    }
}

// Calculate total quantity across all jobs
$total_quantity = 0;
foreach ($jobs as $job) {
    $total_quantity += intval($job['quantity']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= $invoice_no ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: #f5f2eb;
            font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.3;
            color: #1e1e1e;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 15px;
        }
        .invoice-container {
            max-width: 1000px;
            width: 100%;
            background: #fffef7;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 25px 30px 30px 30px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
            flex-wrap: wrap;
            border-bottom: 1px solid #e0dcd3;
            padding-bottom: 12px;
        }
        .title h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #2c2c2c;
            margin-bottom: 4px;
        }
        .title p {
            font-size: 9pt;
            color: #6b6b6b;
        }
        .invoice-meta {
            text-align: right;
            font-size: 9pt;
        }
        .invoice-meta p {
            margin-bottom: 3px;
        }
        .sender-details {
            margin-bottom: 20px;
            border-bottom: 1px solid #e0dcd3;
            padding-bottom: 12px;
        }
        .sender-details h3 {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .sender-details p {
            margin: 2px 0;
            font-size: 9pt;
            color: #444;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0 15px;
            font-size: 9pt;
        }
        .invoice-table th {
            text-align: left;
            padding: 6px 5px;
            background: #f8f6f0;
            border-bottom: 1px solid #ccc;
            font-weight: 600;
            font-size: 9pt;
        }
        .invoice-table td {
            padding: 6px 5px;
            border-bottom: 1px solid #eae7df;
            vertical-align: top;
        }
        .invoice-table th:last-child,
        .invoice-table td:last-child {
            text-align: right;
        }
        .invoice-table td:nth-child(2),
        .invoice-table td:nth-child(3) {
            text-align: right;
        }
        .invoice-table td:first-child {
            text-align: left;
        }
        .job-ref {
            font-size: 8pt;
            color: #666;
            margin-top: 2px;
        }
        .summary {
            width: 260px;
            margin-left: auto;
            margin-top: 10px;
            margin-bottom: 25px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
            font-size: 9pt;
        }
        .summary-row.total {
            border-top: 2px solid #aaa;
            border-bottom: none;
            margin-top: 5px;
            padding-top: 8px;
            font-weight: 700;
            font-size: 11pt;
        }
        .payment-info {
            margin-top: 20px;
            padding-top: 12px;
            border-top: 1px solid #e0dcd3;
            font-size: 8.5pt;
            color: #444;
        }
        .payment-info h4 {
            font-size: 10pt;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .payment-info p {
            margin: 2px 0;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8pt;
            color: #888;
            border-top: 1px solid #eae7df;
            padding-top: 12px;
        }
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .invoice-container {
                box-shadow: none;
                padding: 15px 20px;
            }
            .summary {
                width: 250px;
            }
        }
    </style>
</head>
<body>
<div class="invoice-container" id="invoiceToPrint">
    <div class="header">
        <div class="title">
            <h1>Invoice</h1>
            <p><?= htmlspecialchars($customer_name) ?></p>
        </div>
        <div class="invoice-meta">
            <p><strong>Invoice #</strong> <?= $invoice_no ?></p>
            <p><strong>Issue Date</strong> <?= $invoice_date ?></p>
        </div>
    </div>

    <div class="sender-details">
        <h3>GARMENT ERP</h3>
        <p>123 Fashion Street, Garment City | Phone: +92 XXX XXXXXXX | Email: info@garment.com</p>
    </div>

    <table class="invoice-table">
        <thead>
            <tr style="background-color: #f0f0f0; text-align: left;">
                <th>Description</th>
                <th>Qty</th>
                <th>Rate (Rs)</th>
                <th>Amount (Rs)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($jobs as $job): ?>
            <tr style="background-color: #f0f0f0; text-align: center;">
                <td><?= htmlspecialchars($job['description']) ?><div class="job-ref">Job #<?= htmlspecialchars($job['job_no']) ?></div></td>
                <td><?= number_format($job['quantity']) ?></td>
                <td><?= number_format($job['rate'], 2) ?></td>
                <td><?= number_format($job['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary">
        <div class="summary-row"><span>Total Quantity (pcs)</span><span><?= number_format($total_quantity) ?></span></div>
        <div class="summary-row"><span>Subtotal</span><span>Rs. <?= number_format($subtotal, 2) ?></span></div>
        <div class="summary-row"><span>Tax (0%)</span><span>Rs. 0.00</span></div>
        <div class="summary-row total"><span>Total</span><span>Rs. <?= number_format($grand_total, 2) ?></span></div>
    </div>

    <div class="payment-info">
        <h4>Payment Information</h4>
        <p><strong>Bank:</strong> Garment Bank Ltd. | <strong>Account Holder:</strong> GARMENT ERP</p>
        <p><strong>Account Number:</strong> 1234-5678-9012-3456 | <strong>Email:</strong> accounts@garment.com</p>
        <p><strong>Payment Instructions:</strong> Please quote invoice number when transferring.</p>
    </div>

    <div class="footer">Thank you for your business!</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    (function() {
        const element = document.getElementById('invoiceToPrint');
        const opt = {
            margin: [0.3, 0.3, 0.3, 0.3],
            filename: 'Invoice_<?= $invoice_no ?>.pdf',
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 1.8, letterRendering: true, useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    })();
</script>
</body>
</html>