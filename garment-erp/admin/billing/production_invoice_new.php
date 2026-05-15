<?php
$page_identifier = 'billing/production_invoice_new.php';
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

// Ensure jobs table has company_id column
$check = $conn->query("SHOW COLUMNS FROM `jobs` LIKE 'company_id'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE `jobs` ADD COLUMN `company_id` INT DEFAULT NULL");
    $conn->query("UPDATE `jobs` SET `company_id` = 1 WHERE `company_id` IS NULL");
    $conn->query("ALTER TABLE `jobs` MODIFY `company_id` INT NOT NULL");
}

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

if (!isset($_POST['generate_bill']) || empty($_POST['selected_jobs'])) {
    header("Location: production_billing_new.php");
    exit();
}

$selected_ids = array_unique($_POST['selected_jobs']);
$jobs = [];
foreach ($selected_ids as $id) {
    $id = intval($id);
    $stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND status = 'Checking' AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    if ($job) {
        $rate = getPerPieceRate($conn, $job['job_no'], $company_id);
        $claim_qty = getTotalClaimQty($conn, $job['job_no'], $company_id);
        $bill_qty = $job['quantity'] - $claim_qty;
        if ($bill_qty < 0) $bill_qty = 0;
        
        $job['manual_rate'] = $rate;
        $job['claim_qty'] = $claim_qty;
        $job['bill_qty'] = $bill_qty;
        $jobs[] = $job;
    }
}

if (empty($jobs)) {
    header("Location: production_billing_new.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Production Invoice</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .main-container { margin-left: 14%; padding: 24px 32px; }
        .invoice-card { max-width: 1200px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .invoice-header { background: linear-gradient(135deg, #F39C12, #E67E22); color: white; padding: 30px; text-align: center; }
        .invoice-body { padding: 30px; }
        .invoice-info { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
        .info-box { background: #FEF5E7; padding: 12px 20px; border-radius: 12px; flex: 1; min-width: 180px; }
        .info-box .label { font-size: 0.7rem; text-transform: uppercase; color: #666; }
        .info-box .value input { border: none; background: transparent; font-weight: bold; width: 100%; font-size: 1rem; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.85rem; }
        .items-table th, .items-table td { padding: 10px 8px; border-bottom: 1px solid #e0e0e0; vertical-align: middle; }
        .items-table th { background: #FEF5E7; font-weight: 600; }
        .items-table input { width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 0.85rem; }
        .totals { background: #FEF5E7; padding: 20px; border-radius: 12px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; }
        .grand { border-top: 2px solid #F39C12; margin-top: 10px; padding-top: 15px; font-size: 1.2rem; font-weight: bold; }
        .amount-words { background: #f8f9fa; padding: 12px; border-radius: 10px; margin-top: 15px; border-left: 3px solid #F39C12; }
        .action-buttons { display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
        .btn-success { background: #27ae60; color: white; }
        .btn-primary { background: #F39C12; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        @media (max-width: 992px) { .main-container { margin-left: 0; margin-top: 60px; } }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="invoice-card" id="invoiceContent">
        <div class="invoice-header">
            <h2><i class="fas fa-tshirt"></i> GARMENT ERP</h2>
            <p>123 Fashion Street, Garment City | Phone: +92 XXX XXXXXXX | Email: info@garment.com</p>
        </div>
        <div class="invoice-body">
            <div class="invoice-info">
                <div class="info-box"><div class="label">Invoice No</div><div class="value"><input type="text" id="invoice_no" value="INV-<?= date('Ymd') ?>-<?= rand(100,999) ?>"></div></div>
                <div class="info-box"><div class="label">Invoice Date</div><div class="value"><input type="date" id="invoice_date" value="<?= date('Y-m-d') ?>"></div></div>
                <div class="info-box"><div class="label">Customer Name</div><div class="value"><input type="text" id="customer_name" value="<?= htmlspecialchars($jobs[0]['brand_name'] ?? 'Walk-in Customer') ?>"></div></div>
            </div>

            <table class="items-table">
                <thead>
                    <tr><th>Job No</th><th>Design</th><th>Size</th><th>Fabric</th><th>Original Qty</th><th>Claim Pcs</th><th>Bill Qty</th><th>Rate (Rs/pc)</th><th>Total (Rs)</th></tr>
                </thead>
                <tbody id="itemsBody">
                    <?php foreach($jobs as $job): ?>
                    <tr data-job="<?= htmlspecialchars($job['job_no']) ?>">
                        <td><?= htmlspecialchars($job['job_no']) ?></td>
                        <td><?= htmlspecialchars($job['design_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($job['size'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($job['fabric_name'] ?? '-') ?></td>
                        <td class="original-qty"><?= number_format($job['quantity']) ?></td>
                        <td class="claim-qty"><?= number_format($job['claim_qty']) ?></td>
                        <td class="bill-qty"><?= number_format($job['bill_qty']) ?></td>
                        <td><input type="number" step="0.01" value="<?= $job['manual_rate'] ?>" class="rate-input" style="width:100px;"></td>
                        <td class="total-amount"><?= number_format($job['bill_qty'] * $job['manual_rate'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="total-row"><span>Subtotal:</span> <span id="subtotal">0.00</span></div>
                <div class="total-row grand"><strong>Grand Total:</strong> <strong id="grandTotal">0.00</strong></div>
            </div>
            <div class="amount-words"><i class="fas fa-file-alt"></i> <strong>Amount in Words:</strong> <span id="words">Zero Rupees Only</span></div>
            <div class="amount-words" style="margin-top: 10px;">
                <strong>Notes:</strong><br>
                <small>1. Payment due within 15 days.<br>2. Please quote invoice number when making payment.<br>3. Thank you for your business!</small>
            </div>
        </div>
    </div>
    <div class="action-buttons no-print">
        <button class="btn btn-primary" onclick="updateTotals()"><i class="fas fa-sync-alt"></i> Update Totals</button>
        <button class="btn btn-success" onclick="saveBill()"><i class="fas fa-save"></i> Save Bill & Close Job</button>
        <button class="btn btn-primary" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <a href="production_billing_new.php" class="btn btn-secondary">Back</a>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function numberToWords(num) {
    if (num === 0) return 'Zero';
    const ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    const tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    function convert(n) {
        if (n < 20) return ones[n];
        if (n < 100) return tens[Math.floor(n/10)] + (n%10 ? ' ' + ones[n%10] : '');
        if (n < 1000) return ones[Math.floor(n/100)] + ' Hundred' + (n%100 ? ' ' + convert(n%100) : '');
        if (n < 100000) return convert(Math.floor(n/1000)) + ' Thousand' + (n%1000 ? ' ' + convert(n%1000) : '');
        return convert(Math.floor(n/100000)) + ' Lakh' + (n%100000 ? ' ' + convert(n%100000) : '');
    }
    return convert(Math.round(num)) + ' Rupees Only';
}

function updateTotals() {
    let subtotal = 0;
    $('#itemsBody tr').each(function() {
        let billQty = parseInt($(this).find('.bill-qty').text().replace(/,/g, '')) || 0;
        let rate = parseFloat($(this).find('.rate-input').val()) || 0;
        let total = billQty * rate;
        $(this).find('.total-amount').text(total.toFixed(2));
        subtotal += total;
    });
    $('#subtotal').text(subtotal.toFixed(2));
    $('#grandTotal').text(subtotal.toFixed(2));
    $('#words').text(numberToWords(subtotal));
}

function saveBill() {
    let jobs = [];
    $('#itemsBody tr').each(function() {
        let jobNo = $(this).data('job');
        let billQty = parseInt($(this).find('.bill-qty').text().replace(/,/g, '')) || 0;
        let rate = parseFloat($(this).find('.rate-input').val()) || 0;
        jobs.push({ job_no: jobNo, quantity: billQty, rate: rate });
    });
    $.ajax({
        url: 'production_invoice_save_new.php',
        type: 'POST',
        data: {
            save_bill: 1,
            invoice_no: $('#invoice_no').val(),
            billing_date: $('#invoice_date').val(),
            customer_name: $('#customer_name').val(),
            jobs: jobs
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) alert('Saved! ' + res.count + ' job(s) billed.');
            else alert('Error: ' + res.error);
            if (res.success) window.location.href = 'production_billing_new.php';
        },
        error: function() { alert('AJAX error'); }
    });
}

function exportToPDF() {
    let jobs = [];
    $('#itemsBody tr').each(function() {
        let jobNo = $(this).data('job');
        let design = $(this).find('td:nth-child(2)').text();
        let size = $(this).find('td:nth-child(3)').text();
        let fabric = $(this).find('td:nth-child(4)').text();
        let billQty = parseInt($(this).find('.bill-qty').text().replace(/,/g, '')) || 0;
        let rate = parseFloat($(this).find('.rate-input').val()) || 0;
        jobs.push({
            job_no: jobNo,
            description: design + ' (' + size + ', ' + fabric + ')',
            quantity: billQty,
            rate: rate,
            amount: billQty * rate
        });
    });
    
    let data = {
        invoice_no: $('#invoice_no').val(),
        invoice_date: $('#invoice_date').val(),
        customer_name: $('#customer_name').val(),
        jobs: jobs,
        total: parseFloat($('#grandTotal').text())
    };
    
    let form = $('<form>', { action: 'production_invoice_pdf_new.php', method: 'POST', target: '_blank' });
    form.append($('<input>', { type: 'hidden', name: 'data', value: JSON.stringify(data) }));
    $('body').append(form);
    form.submit();
    form.remove();
}

$(document).ready(function() {
    $(document).on('input', '.rate-input', updateTotals);
    updateTotals();
});
</script>
</body>
</html>