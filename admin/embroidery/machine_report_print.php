<?php
$page_identifier = 'embroidery/machine_report_print.php';
require_once "../../config/db.php";

// Get selected date
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('-1 day'));
$display_date = date('d/m/Y', strtotime($selected_date));
$selected_month = date('Y-m', strtotime($selected_date));
$previous_month = date('Y-m', strtotime('-1 month', strtotime($selected_date)));

// Get current month name
$current_month_name = date('F Y', strtotime($selected_date));
$previous_month_name = date('F Y', strtotime('-1 month', strtotime($selected_date)));

// Get all machines with their day and night shift stitches for selected date
$machines_query = "SELECT 
    m.id,
    m.machine_no,
    COALESCE(SUM(CASE WHEN e.shift = 'day' THEN e.stitch_done ELSE 0 END), 0) as day_stitches,
    COALESCE(SUM(CASE WHEN e.shift = 'night' THEN e.stitch_done ELSE 0 END), 0) as night_stitches,
    COALESCE(SUM(e.stitch_done), 0) as total_stitches,
    COALESCE((
        SELECT AVG(stitch_done) 
        FROM embroidery_entries 
        WHERE machine_id = m.id 
        AND shift = 'day' 
        AND DATE_FORMAT(entry_date, '%Y-%m') = '$selected_month'
    ), 0) as day_current_avg,
    COALESCE((
        SELECT AVG(stitch_done) 
        FROM embroidery_entries 
        WHERE machine_id = m.id 
        AND shift = 'night' 
        AND DATE_FORMAT(entry_date, '%Y-%m') = '$selected_month'
    ), 0) as night_current_avg,
    COALESCE((
        SELECT AVG(stitch_done) 
        FROM embroidery_entries 
        WHERE machine_id = m.id 
        AND shift = 'day' 
        AND DATE_FORMAT(entry_date, '%Y-%m') = '$previous_month'
    ), 0) as day_previous_avg,
    COALESCE((
        SELECT AVG(stitch_done) 
        FROM embroidery_entries 
        WHERE machine_id = m.id 
        AND shift = 'night' 
        AND DATE_FORMAT(entry_date, '%Y-%m') = '$previous_month'
    ), 0) as night_previous_avg
    FROM machines m
    LEFT JOIN embroidery_entries e ON m.id = e.machine_id AND DATE(e.entry_date) = '$selected_date'
    GROUP BY m.id, m.machine_no
    ORDER BY m.id ASC";

$result = mysqli_query($conn, $machines_query);

// Get overall totals for selected date
$overall_query = "SELECT 
    COALESCE(SUM(CASE WHEN shift = 'day' THEN stitch_done ELSE 0 END), 0) as total_day,
    COALESCE(SUM(CASE WHEN shift = 'night' THEN stitch_done ELSE 0 END), 0) as total_night
    FROM embroidery_entries 
    WHERE DATE(entry_date) = '$selected_date'";

$overall = mysqli_fetch_assoc(mysqli_query($conn, $overall_query));

// Get overall averages for current month
$overall_avg_query = "SELECT 
    COALESCE(AVG(CASE WHEN shift = 'day' THEN stitch_done END), 0) as avg_day_current,
    COALESCE(AVG(CASE WHEN shift = 'night' THEN stitch_done END), 0) as avg_night_current
    FROM embroidery_entries 
    WHERE DATE_FORMAT(entry_date, '%Y-%m') = '$selected_month'";

$overall_avg = mysqli_fetch_assoc(mysqli_query($conn, $overall_avg_query));

function numberToWords($number) {
    $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
    return $f->format($number);
}

$total_day_sum = 0;
$total_night_sum = 0;
$machines_data = [];
while($row = mysqli_fetch_assoc($result)) {
    $total_day_sum += $row['day_stitches'];
    $total_night_sum += $row['night_stitches'];
    $machines_data[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Y.S Embroidery Report - <?= $display_date ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            background: white;
            padding: 20px;
        }

        .print-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #000;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }

        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .header h2 {
            font-size: 14px;
            font-weight: normal;
        }

        .report-info {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            border: 1px solid #ddd;
            padding: 15px;
            background: #fafafa;
        }

        .info-left, .info-right {
            width: 48%;
        }

        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .info-label {
            width: 100px;
            font-weight: bold;
        }

        .info-value {
            flex: 1;
        }

        .stats-summary {
            display: flex;
            margin-bottom: 30px;
            gap: 1px;
            background: #ddd;
            border: 1px solid #ddd;
        }

        .stat-item {
            flex: 1;
            background: white;
            padding: 15px;
            text-align: center;
        }

        .stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: bold;
            color: #666;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 22px;
            font-weight: bold;
            color: #000;
        }

        .stat-desc {
            font-size: 9px;
            color: #888;
            margin-top: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 11px;
        }

        th, td {
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: center;
        }

        th {
            background: #e8e8e8;
            font-weight: bold;
        }

        .machine-no {
            text-align: left;
            font-weight: bold;
            background: #f5f5f5;
        }

        .text-end {
            text-align: right;
        }

        .total-row {
            font-weight: bold;
            background: #e8e8e8;
        }

        .amount-in-words {
            margin: 20px 0;
            padding: 12px;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            background: #f9f9f9;
            font-size: 12px;
        }

        .footer {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }

        .signature {
            width: 200px;
            text-align: center;
            font-size: 11px;
        }

        .signature-line {
            margin-top: 40px;
            border-top: 1px solid #000;
            padding-top: 5px;
        }

        .print-btn {
            margin: 20px 0;
            text-align: center;
        }

        .print-btn button {
            padding: 10px 30px;
            font-size: 14px;
            cursor: pointer;
            margin: 0 5px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
        }

        .btn-print {
            background: #28a745;
            color: white;
        }

        .btn-close {
            background: #dc3545;
            color: white;
        }

        @media print {
            .print-btn {
                display: none;
            }
            body {
                padding: 0;
            }
            .print-container {
                border: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-btn">
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
        <button onclick="window.close()" class="btn-close">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <div class="print-container">
        <div class="header">
            <h1>Y.S EMBROIDERY REPORT</h1>
            <h2>Production Summary Report</h2>
        </div>

        <div class="report-info">
            <div class="info-left">
                <div class="info-row">
                    <span class="info-label">Report Date:</span>
                    <span class="info-value"><?= $display_date ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Current Month:</span>
                    <span class="info-value"><?= $current_month_name ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Previous Month:</span>
                    <span class="info-value"><?= $previous_month_name ?></span>
                </div>
            </div>
            <div class="info-right">
                <div class="info-row">
                    <span class="info-label">Generated By:</span>
                    <span class="info-value">Garment ERP System</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Print Date:</span>
                    <span class="info-value"><?= date('d-m-Y H:i:s') ?></span>
                </div>
            </div>
        </div>

        <div class="stats-summary">
            <div class="stat-item">
                <div class="stat-label">Day Shift Stitches</div>
                <div class="stat-value"><?= number_format($overall['total_day'] ?? 0) ?></div>
                <div class="stat-desc">For <?= $display_date ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Night Shift Stitches</div>
                <div class="stat-value"><?= number_format($overall['total_night'] ?? 0) ?></div>
                <div class="stat-desc">For <?= $display_date ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Monthly Avg (Day)</div>
                <div class="stat-value"><?= number_format($overall_avg['avg_day_current'] ?? 0, 0) ?></div>
                <div class="stat-desc"><?= $current_month_name ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Monthly Avg (Night)</div>
                <div class="stat-value"><?= number_format($overall_avg['avg_night_current'] ?? 0, 0) ?></div>
                <div class="stat-desc"><?= $current_month_name ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Machine No</th>
                    <th>Day Stitches</th>
                    <th>Night Stitches</th>
                    <th>Avg/Day (Current)</th>
                    <th>Avg/Night (Current)</th>
                    <th>Avg/Day (Previous)</th>
                    <th>Avg/Night (Previous)</th>
                    <th>Trend</th>
                 </tr>
            </thead>
            <tbody>
                <?php if(count($machines_data) > 0): ?>
                    <?php foreach($machines_data as $row): 
                        $day_trend = $row['day_current_avg'] - $row['day_previous_avg'];
                        $day_trend_percent = $row['day_previous_avg'] > 0 ? ($day_trend / $row['day_previous_avg']) * 100 : 0;
                    ?>
                    <tr>
                        <td class="machine-no"><?= htmlspecialchars($row['machine_no']) ?></td>
                        <td><strong><?= number_format($row['day_stitches']) ?></strong></td>
                        <td><strong><?= number_format($row['night_stitches']) ?></strong></td>
                        <td><?= number_format($row['day_current_avg'], 0) ?></td>
                        <td><?= number_format($row['night_current_avg'], 0) ?></td>
                        <td><?= number_format($row['day_previous_avg'], 0) ?></td>
                        <td><?= number_format($row['night_previous_avg'], 0) ?></td>
                        <td><?= $day_trend >= 0 ? '▲' : '▼' ?> <?= number_format(abs($day_trend_percent), 0) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            No production data found for <?= $display_date ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php if(count($machines_data) > 0): ?>
            <tfoot>
                <tr class="total-row">
                    <td class="text-end"><strong>TOTAL</strong></td>
                    <td><strong><?= number_format($total_day_sum) ?></strong></td>
                    <td><strong><?= number_format($total_night_sum) ?></strong></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

      

        <?php if($total_day_sum > 0 || $total_night_sum > 0): ?>
        <div style="margin: 20px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #000;">
            <strong>Summary:</strong><br>
            • Total Day Shift Production: <?= number_format($total_day_sum) ?> stitches<br>
            • Total Night Shift Production: <?= number_format($total_night_sum) ?> stitches<br>
            • Total Combined Production: <?= number_format($total_day_sum + $total_night_sum) ?> stitches<br>
            • Average per Machine: <?= number_format(($total_day_sum + $total_night_sum) / max(count($machines_data), 1), 0) ?> stitches
        </div>
        <?php endif; ?>

        <div class="footer">
            <div class="signature">
                <div>______________________</div>
                <div>Prepared By</div>
            </div>
            <div class="signature">
                <div>______________________</div>
                <div>Authorized Signatory</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 20px; font-size: 10px; color: #666;">
            Garment ERP System - Computer Generated Report<br>
            This is a system generated report and does not require physical signature
        </div>
    </div>

    <script>
        // Auto print when page loads (optional - uncomment if needed)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
   

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>