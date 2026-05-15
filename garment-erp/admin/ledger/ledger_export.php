<?php
session_start();
include("../../config/db.php");
include("../../includes/functions.php");

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id (already handled in ledger_view.php, but for safety)
$tables = ['accounts', 'stitching_posted_bills', 'ledger_transactions'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Get parameters
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$filter = $_GET['filter'] ?? 'all';
$group_by = $_GET['group_by'] ?? 'detailed';
$custom_from = $_GET['from_date'] ?? '';
$custom_to = $_GET['to_date'] ?? '';

// Helper functions (copied from ledger_view.php)
function getDateRange($filter, $custom_from, $custom_to) {
    if (!empty($custom_from) && !empty($custom_to)) {
        return ['start' => $custom_from, 'end' => $custom_to];
    }
    $today = date('Y-m-d');
    $result = ['start' => null, 'end' => null];
    switch($filter) {
        case 'today': $result['start'] = $today; $result['end'] = $today; break;
        case 'last_day': $result['start'] = date('Y-m-d', strtotime('-1 day')); $result['end'] = date('Y-m-d', strtotime('-1 day')); break;
        case 'this_week': $result['start'] = date('Y-m-d', strtotime('monday this week')); $result['end'] = date('Y-m-d', strtotime('sunday this week')); break;
        case 'last_week': $result['start'] = date('Y-m-d', strtotime('monday last week')); $result['end'] = date('Y-m-d', strtotime('sunday last week')); break;
        case 'this_month': $result['start'] = date('Y-m-01'); $result['end'] = date('Y-m-t'); break;
        case 'last_month': $result['start'] = date('Y-m-01', strtotime('first day of last month')); $result['end'] = date('Y-m-t', strtotime('last day of last month')); break;
        case 'last_3_month': $result['start'] = date('Y-m-d', strtotime('-3 months')); $result['end'] = $today; break;
        case 'last_6_month': $result['start'] = date('Y-m-d', strtotime('-6 months')); $result['end'] = $today; break;
        case 'this_year': $result['start'] = date('Y-01-01'); $result['end'] = date('Y-12-31'); break;
        case 'last_year': $result['start'] = date('Y-01-01', strtotime('-1 year')); $result['end'] = date('Y-12-31', strtotime('-1 year')); break;
        default: $result['start'] = null; $result['end'] = null;
    }
    return $result;
}

$dateRange = getDateRange($filter, $custom_from, $custom_to);
$start_date = $dateRange['start'];
$end_date = $dateRange['end'];
$isFiltered = ($filter != 'all' || (!empty($custom_from) && !empty($custom_to)));

$account = null;
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM accounts WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if ($account) {
        $account_name = $account['account_name'];
        $current_balance = floatval($account['balance']);
        $bills = []; $fabric_sales = []; $transactions = []; $all_entries = []; $opening_balance = 0;
        
        if ($isFiltered && $start_date) {
            // Opening balance before start_date
            $bills_before = 0; $fabric_before = 0; $ledger_net_before = 0;
            $stmt_bills = $conn->prepare("SELECT SUM(total_amount) as sum_total FROM stitching_posted_bills WHERE emp_name = ? AND status='posted' AND claim_type != 'fabric_sale' AND claim_date < ? AND company_id = ?");
            $stmt_bills->bind_param("ssi", $account_name, $start_date, $company_id);
            $stmt_bills->execute();
            $res = $stmt_bills->get_result();
            if ($row = $res->fetch_assoc()) $bills_before = floatval($row['sum_total'] ?? 0);
            
            $stmt_fabric = $conn->prepare("SELECT SUM(total_amount) as sum_total FROM stitching_posted_bills WHERE emp_name = ? AND status='posted' AND claim_type='fabric_sale' AND claim_date < ? AND company_id = ?");
            $stmt_fabric->bind_param("ssi", $account_name, $start_date, $company_id);
            $stmt_fabric->execute();
            $res = $stmt_fabric->get_result();
            if ($row = $res->fetch_assoc()) $fabric_before = floatval($row['sum_total'] ?? 0);
            
            $stmt_ledger = $conn->prepare("SELECT SUM(CASE WHEN to_account=? THEN amount ELSE 0 END) as credit_sum, SUM(CASE WHEN from_account=? THEN amount ELSE 0 END) as debit_sum FROM ledger_transactions WHERE (from_account=? OR to_account=?) AND date < ? AND company_id = ?");
            $stmt_ledger->bind_param("iiiiis", $id, $id, $id, $id, $start_date, $company_id);
            $stmt_ledger->execute();
            $res = $stmt_ledger->get_result();
            if ($row = $res->fetch_assoc()) $ledger_net_before = floatval($row['credit_sum']??0) - floatval($row['debit_sum']??0);
            $opening_balance = $bills_before + $fabric_before + $ledger_net_before;
            
            // Data within range
            $stmt_bills_range = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE emp_name = ? AND status='posted' AND claim_type != 'fabric_sale' AND claim_date BETWEEN ? AND ? AND company_id = ? ORDER BY claim_date,id");
            $stmt_bills_range->bind_param("sssi", $account_name, $start_date, $end_date, $company_id);
            $stmt_bills_range->execute();
            $bills_result = $stmt_bills_range->get_result();
            while ($bill = $bills_result->fetch_assoc()) $bills[] = $bill;
            
            $stmt_fabric_range = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE emp_name = ? AND status='posted' AND claim_type='fabric_sale' AND claim_date BETWEEN ? AND ? AND company_id = ? ORDER BY claim_date,id");
            $stmt_fabric_range->bind_param("sssi", $account_name, $start_date, $end_date, $company_id);
            $stmt_fabric_range->execute();
            $fabric_result = $stmt_fabric_range->get_result();
            while ($sale = $fabric_result->fetch_assoc()) $fabric_sales[] = $sale;
            
            $stmt_trans = $conn->prepare("SELECT lt.*, a1.account_name as from_name, a2.account_name as to_name FROM ledger_transactions lt LEFT JOIN accounts a1 ON lt.from_account = a1.id LEFT JOIN accounts a2 ON lt.to_account = a2.id WHERE (lt.from_account = ? OR lt.to_account = ?) AND lt.date BETWEEN ? AND ? AND lt.company_id = ? ORDER BY lt.date, lt.id");
            $stmt_trans->bind_param("iissi", $id, $id, $start_date, $end_date, $company_id);
            $stmt_trans->execute();
            $trans_result = $stmt_trans->get_result();
            while ($trans = $trans_result->fetch_assoc()) $transactions[] = $trans;
        } else {
            // All mode
            $stmt_bills_all = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE emp_name = ? AND status='posted' AND claim_type != 'fabric_sale' AND company_id = ? ORDER BY claim_date,id");
            $stmt_bills_all->bind_param("si", $account_name, $company_id);
            $stmt_bills_all->execute();
            $bills_result = $stmt_bills_all->get_result();
            while ($bill = $bills_result->fetch_assoc()) $bills[] = $bill;
            
            $stmt_fabric_all = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE emp_name = ? AND status='posted' AND claim_type='fabric_sale' AND company_id = ? ORDER BY claim_date,id");
            $stmt_fabric_all->bind_param("si", $account_name, $company_id);
            $stmt_fabric_all->execute();
            $fabric_result = $stmt_fabric_all->get_result();
            while ($sale = $fabric_result->fetch_assoc()) $fabric_sales[] = $sale;
            
            $stmt_trans_all = $conn->prepare("SELECT lt.*, a1.account_name as from_name, a2.account_name as to_name FROM ledger_transactions lt LEFT JOIN accounts a1 ON lt.from_account = a1.id LEFT JOIN accounts a2 ON lt.to_account = a2.id WHERE (lt.from_account = ? OR lt.to_account = ?) AND lt.company_id = ? ORDER BY lt.date, lt.id");
            $stmt_trans_all->bind_param("iii", $id, $id, $company_id);
            $stmt_trans_all->execute();
            $trans_result = $stmt_trans_all->get_result();
            while ($trans = $trans_result->fetch_assoc()) $transactions[] = $trans;
            
            $total_bills_sum = array_sum(array_column($bills, 'total_amount'));
            $total_fabric_sum = array_sum(array_column($fabric_sales, 'total_amount'));
            $total_payments_sum = 0;
            foreach ($transactions as $t) {
                if ($t['from_account'] == $id) $total_payments_sum += floatval($t['amount']);
                elseif ($t['to_account'] == $id) $total_payments_sum -= floatval($t['amount']);
            }
            $opening_balance = $current_balance - (($total_bills_sum + $total_fabric_sum) - $total_payments_sum);
        }
        
        // Build entries
        $all_entries[] = ['type'=>'opening','date'=>'','job_no'=>'-','claim_type'=>'','qty'=>0,'rate'=>0,'bill_amount'=>0,'paid_amount'=>0,'description'=>'Opening Balance','opening_balance'=>$opening_balance];
        foreach ($bills as $bill) $all_entries[] = ['type'=>'bill','id'=>$bill['id'],'date'=>$bill['claim_date'],'job_no'=>$bill['job_no'],'claim_type'=>$bill['claim_type'],'qty'=>$bill['qty'],'rate'=>$bill['rate'],'bill_amount'=>floatval($bill['total_amount']),'paid_amount'=>0,'description'=>'Bill - '.ucfirst(str_replace('_',' ',$bill['claim_type'])),'status'=>$bill['status']];
        foreach ($fabric_sales as $sale) $all_entries[] = ['type'=>'transaction','date'=>$sale['claim_date'],'job_no'=>$sale['job_no']??'-','claim_type'=>'fabric_sale','qty'=>$sale['qty'],'rate'=>$sale['rate'],'bill_amount'=>0,'paid_amount'=>floatval($sale['total_amount']),'description'=>'Fabric Sale - '.htmlspecialchars($sale['description']??''),'transaction_type'=>'credit'];
        foreach ($transactions as $trans) {
            if ($trans['from_account'] == $id) $all_entries[] = ['type'=>'transaction','date'=>$trans['date'],'job_no'=>'-','claim_type'=>'','qty'=>0,'rate'=>0,'bill_amount'=>0,'paid_amount'=>-floatval($trans['amount']),'description'=>'Payment to '.htmlspecialchars($trans['to_name']??'').' - '.htmlspecialchars($trans['description']??''),'transaction_type'=>'debit'];
            elseif ($trans['to_account'] == $id) $all_entries[] = ['type'=>'transaction','date'=>$trans['date'],'job_no'=>'-','claim_type'=>'','qty'=>0,'rate'=>0,'bill_amount'=>0,'paid_amount'=>floatval($trans['amount']),'description'=>'Payment from '.htmlspecialchars($trans['from_name']??'').' - '.htmlspecialchars($trans['description']??''),'transaction_type'=>'credit'];
        }
        usort($all_entries, function($a,$b){ if($a['type']=='opening') return -1; if($b['type']=='opening') return 1; return strtotime($a['date'])-strtotime($b['date']); });
        
        $running_balance = $opening_balance;
        $entries_with_balance = []; $total_bill_amount=0; $total_paid_amount=0;
        foreach ($all_entries as $e){
            if ($e['type']=='opening') { $e['running_balance']=$running_balance; $entries_with_balance[]=$e; continue; }
            if ($e['type']=='bill') { $running_balance+=$e['bill_amount']; $total_bill_amount+=$e['bill_amount']; }
            else { $running_balance+=$e['paid_amount']; $total_paid_amount+=abs($e['paid_amount']); }
            $e['running_balance']=$running_balance;
            $entries_with_balance[]=$e;
        }
        $total_pending_amount = $running_balance;
        
        // Grouping
        $grouped_data = null; $periods = [];
        if ($group_by != 'detailed') {
            foreach ($entries_with_balance as $e) {
                if ($e['type']=='opening') continue;
                $date = $e['date']; if (empty($date)) continue;
                $period_key=''; $period_label='';
                switch($group_by){
                    case 'weekly':
                        $year = date('Y',strtotime($date)); $week = date('W',strtotime($date));
                        $period_key = $year.'-W'.str_pad($week,2,'0',STR_PAD_LEFT);
                        $start_of_week = date('Y-m-d',strtotime($year.'W'.str_pad($week,2,'0',STR_PAD_LEFT)));
                        $end_of_week = date('Y-m-d',strtotime($start_of_week.' +6 days'));
                        $period_label = "Week $week ($start_of_week to $end_of_week)";
                        break;
                    case 'monthly':
                        $period_key = date('Y-m',strtotime($date));
                        $month_start = date('Y-m-01',strtotime($date));
                        $month_end = date('Y-m-t',strtotime($date));
                        $period_label = date('F Y',strtotime($date))." ($month_start to $month_end)";
                        break;
                    case 'quarterly':
                        $year = date('Y',strtotime($date)); $month = date('n',strtotime($date)); $quarter = ceil($month/3);
                        $period_key = $year.'-Q'.$quarter;
                        $start_q = date('Y-m-d',strtotime($year.'-'.(($quarter-1)*3+1).'-01'));
                        $end_q = date('Y-m-d',strtotime($start_q.' +2 months +1 day -1 day'));
                        $quarter_names = ['Q1 (Jan-Mar)','Q2 (Apr-Jun)','Q3 (Jul-Sep)','Q4 (Oct-Dec)'];
                        $period_label = $quarter_names[$quarter-1]." $year ($start_q to $end_q)";
                        break;
                    case 'half_yearly':
                        $year = date('Y',strtotime($date)); $month = date('n',strtotime($date)); $half = ($month<=6)?1:2;
                        $period_key = $year.'-H'.$half;
                        $start_h = ($half==1)?$year.'-01-01':$year.'-07-01';
                        $end_h = ($half==1)?$year.'-06-30':$year.'-12-31';
                        $period_label = ($half==1?'First Half (Jan-Jun)':'Second Half (Jul-Dec)')." $year ($start_h to $end_h)";
                        break;
                    case 'yearly':
                        $period_key = date('Y',strtotime($date));
                        $period_label = "Year $period_key (".$period_key.'-01-01 to '.$period_key.'-12-31)';
                        break;
                }
                if (!isset($periods[$period_key])) $periods[$period_key] = ['label'=>$period_label,'total_bills'=>0,'total_payments'=>0,'net_change'=>0,'entries'=>[]];
                if ($e['type']=='bill') { $periods[$period_key]['total_bills']+=$e['bill_amount']; $periods[$period_key]['net_change']+=$e['bill_amount']; }
                else { $periods[$period_key]['total_payments']+=$e['paid_amount']; $periods[$period_key]['net_change']+=$e['paid_amount']; }
                $periods[$period_key]['entries'][] = $e;
            }
            ksort($periods);
            $running = $opening_balance;
            $grouped_data = [];
            foreach ($periods as $key=>$p){
                $closing = $running + $p['net_change'];
                $grouped_data[] = ['period_key'=>$key,'period_label'=>$p['label'],'total_bills'=>$p['total_bills'],'total_payments'=>$p['total_payments'],'net_change'=>$p['net_change'],'opening_balance'=>$running,'closing_balance'=>$closing,'entries'=>$p['entries']];
                $running = $closing;
            }
            $grouped_total_bills = array_sum(array_column($grouped_data,'total_bills'));
            $grouped_total_payments = array_sum(array_column($grouped_data,'total_payments'));
        }
    }
}

// --- STYLED HTML EXPORT AS EXCEL (.xls) ---
if (!$account) {
    die("Account not found.");
}

$filename = 'ledger_' . $account['account_name'] . '_' . date('Y-m-d') . '.xls';

// Send headers to treat the HTML as an Excel file
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");

// HTML template with styling
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ledger Export</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #FDEBD0; color: #B95A00; border: 1px solid #E67E22; padding: 8px; font-weight: bold; text-align: center; }
        td { border: 1px solid #ddd; padding: 6px; text-align: center; }
        .amount-col { text-align: right; }
        .bill-stitching { background-color: #E8F0FE; color: #1A5D9C; }
        .bill-fabric { background-color: #FFF3E0; color: #C45C00; }
        .bill-payment { background-color: #EFF2F6; color: #4A627A; }
        .bill-opening { background-color: #FEF5E7; color: #B95A00; }
        .running-balance-positive { background-color: #E6F4EA; color: #2C7A4B; font-weight: bold; }
        .running-balance-negative { background-color: #FEF2F0; color: #C0392B; font-weight: bold; }
        .total-row { background-color: #FEF5E7; font-weight: bold; }
        h3 { color: #B95A00; margin-bottom: 5px; }
    </style>
</head>
<body>
    <h3>Ledger: <?= htmlspecialchars($account['account_name']) ?> (<?= ucfirst($account['account_type']) ?>)</h3>
    <p>Current Balance: Rs. <?= number_format($current_balance, 2) ?> | <?= $group_by == 'detailed' ? 'Detailed Statement' : ucfirst(str_replace('_', ' ', $group_by)) . ' Summary' ?></p>

    <?php if ($group_by == 'detailed'): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Job No</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>Bill (Rs)</th>
                    <th>Credit/Payment (Rs)</th>
                    <th>Running Balance (Rs)</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php $c=1; foreach($entries_with_balance as $e): 
                    $bal_class = $e['running_balance'] >= 0 ? 'running-balance-positive' : 'running-balance-negative';
                ?>
                <tr>
                    <td><?= $c++ ?></td>
                    <td><?= $e['type'] == 'opening' ? '-' : date('d-m-Y', strtotime($e['date'])) ?></td>
                    <td>
                        <?php if($e['type'] == 'opening'): ?>
                            <span class="bill-opening">Opening</span>
                        <?php elseif($e['type'] == 'bill'): ?>
                            <span class="bill-stitching">Bill</span>
                        <?php elseif(($e['claim_type']??'') == 'fabric_sale'): ?>
                            <span class="bill-fabric">Fabric Sale</span>
                        <?php else: ?>
                            <span class="bill-payment">Payment</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ($e['type'] == 'bill' || ($e['claim_type']??'') == 'fabric_sale') ? htmlspecialchars($e['job_no']) : '-' ?></td>
                    <td><?= ($e['type'] == 'bill' || ($e['claim_type']??'') == 'fabric_sale') ? number_format($e['qty'], 0) : '-' ?></td>
                    <td><?= ($e['type'] == 'bill' || ($e['claim_type']??'') == 'fabric_sale') ? 'Rs. ' . number_format($e['rate'], 2) : '-' ?></td>
                    <td class="amount-col"><?= $e['type'] == 'bill' ? 'Rs. ' . number_format($e['bill_amount'], 2) : '-' ?></td>
                    <td class="amount-col"><?= $e['type'] != 'opening' ? 'Rs. ' . number_format(abs($e['paid_amount']), 2) : '-' ?></td>
                    <td class="amount-col <?= $bal_class ?>">Rs. <?= number_format($e['running_balance'], 2) ?></td>
                    <td><?= htmlspecialchars(substr($e['description'], 0, 50)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="6"><strong>TOTAL</strong></td>
                    <td class="amount-col"><strong>Rs. <?= number_format($total_bill_amount, 2) ?></strong></td>
                    <td class="amount-col"><strong>Rs. <?= number_format($total_paid_amount, 2) ?></strong></td>
                    <td class="amount-col <?= $total_pending_amount >= 0 ? 'running-balance-positive' : 'running-balance-negative' ?>">
                        <strong>Rs. <?= number_format($total_pending_amount, 2) ?></strong>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Total Bills</th>
                    <th>Total Payments</th>
                    <th>Net Change</th>
                    <th>Opening Balance</th>
                    <th>Closing Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($grouped_data as $g): ?>
                <tr>
                    <td style="text-align:left;"><?= htmlspecialchars($g['period_label']) ?></td>
                    <td class="amount-col">Rs. <?= number_format($g['total_bills'], 2) ?></td>
                    <td class="amount-col">Rs. <?= number_format($g['total_payments'], 2) ?></td>
                    <td class="amount-col">Rs. <?= number_format($g['net_change'], 2) ?></td>
                    <td class="amount-col">Rs. <?= number_format($g['opening_balance'], 2) ?></td>
                    <td class="amount-col"><strong>Rs. <?= number_format($g['closing_balance'], 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td><strong>GRAND TOTAL</strong></td>
                    <td class="amount-col"><strong>Rs. <?= number_format($grouped_total_bills, 2) ?></strong></td>
                    <td class="amount-col"><strong>Rs. <?= number_format($grouped_total_payments, 2) ?></strong></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</body>
</html>