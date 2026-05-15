<?php
$page_identifier = 'ledger/ledger_view.php';
session_start();
include("../../config/db.php");
include("../../includes/functions.php");

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure accounts and stitching_posted_bills have company_id
$tables = ['accounts', 'stitching_posted_bills', 'ledger_transactions'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$filter = $_GET['filter'] ?? 'all';
$group_by = $_GET['group_by'] ?? 'detailed';
$custom_from = $_GET['from_date'] ?? '';
$custom_to = $_GET['to_date'] ?? '';

// Export CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Re-fetch data similarly (not shown here to avoid duplication; we'll generate CSV directly)
    // For brevity, we redirect to an export script. But we can implement inline.
    // We'll create a simple CSV output using the same data generation logic.
    // Since the data generation is long, we'll redirect to a separate script.
    header("Location: ledger_export.php?id=$id&filter=$filter&group_by=$group_by&from_date=$custom_from&to_date=$custom_to");
    exit;
}

if (!function_exists('getDateRange')) {
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
}

if (!function_exists('getFilterDisplayName')) {
    function getFilterDisplayName($filter, $custom_from, $custom_to) {
        if (!empty($custom_from) && !empty($custom_to)) return "Custom ($custom_from to $custom_to)";
        $names = ['all'=>'All Records','today'=>'Today','last_day'=>'Last Day','this_week'=>'This Week','last_week'=>'Last Week','this_month'=>'This Month','last_month'=>'Last Month','last_3_month'=>'Last 3 Months','last_6_month'=>'Last 6 Months','this_year'=>'This Year','last_year'=>'Last Year'];
        return $names[$filter] ?? 'All Records';
    }
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
            $opening_balance = $current_balance - (($total_bills_sum - $total_fabric_sum) - $total_payments_sum);
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
?>
<!DOCTYPE html>
<html>
<head>
<title>Ledger Details - <?= htmlspecialchars($account['account_name']??'') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root { --primary: #E67E22; --primary-light: #FEF5E7; --primary-soft: #FDEBD0; --primary-dark: #B95A00; --text-dark: #1A2C3E; --bg-page: #F8FAFE; --bg-card: #fff; --success: #2C7A4B; --danger: #C0392B; --shadow-sm: 0 2px 8px rgba(0,0,0,0.03); --shadow-md: 0 4px 12px rgba(0,0,0,0.05); }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background: var(--bg-page); font-family: 'Inter', sans-serif; font-size:15px; }
    .container { margin-left:17%; width:81%; max-width:1400px; padding:28px 32px; min-height:100vh; }
    .page-header h2 { font-size:1.75rem; font-weight:700; display:flex; align-items:center; gap:12px; color:var(--text-dark); }
    .page-header h2 i { color:var(--primary); }
    /* ========== IMPROVED FILTER BAR STYLING ========== */
    .filter-bar {
        background: var(--bg-card);
        border-radius: 20px;
        padding: 20px 28px;
        margin-bottom: 28px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        border: 1px solid #edf2f9;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
    }
    .filter-group {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }
    .filter-group label {
        font-weight: 600;
        color: #4a5568;
        font-size: 0.85rem;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .filter-group label i { color: var(--primary); font-size: 0.9rem; }
    .filter-group input[type="date"] {
        padding: 8px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc;
        font-size: 0.85rem;
        transition: 0.2s;
        width: 150px;
        color: #2d3748;
    }
    .filter-group input[type="date"]:focus {
        border-color: var(--primary);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(230,126,34,0.1);
        outline: none;
    }
    .filter-group span {
        color: #a0aec0;
        font-weight: 500;
        font-size: 0.85rem;
    }
    .filter-select {
        padding: 8px 36px 8px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 12px center;
        appearance: none;
        font-size: 0.85rem;
        color: #2d3748;
        font-weight: 500;
        transition: 0.2s;
        min-width: 160px;
    }
    .filter-select:focus {
        border-color: var(--primary);
        background-color: #fff;
        box-shadow: 0 0 0 3px rgba(230,126,34,0.1);
        outline: none;
    }
    .btn-export {
        background: linear-gradient(135deg, #2C7A4B 0%, #1E5A38 100%);
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: 0.2s;
        box-shadow: 0 2px 6px rgba(44,122,75,0.2);
        white-space: nowrap;
    }
    .btn-export:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(44,122,75,0.3);
        color: white;
    }
    .filter-badge {
        background: var(--primary-light);
        padding: 8px 18px;
        border-radius: 40px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--primary-dark);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--primary-soft);
    }
    .filter-badge i { color: var(--primary); }
    /* ========== END OF FILTER STYLING ========== */

    .account-card { background:var(--bg-card); border-radius:20px; padding:24px 28px; margin-bottom:28px; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; border-left:5px solid var(--primary); box-shadow:var(--shadow-md); }
    .account-info { display:flex; gap:40px; flex-wrap:wrap; }
    .info-label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#6C86A3; }
    .info-value { font-size:1.05rem; font-weight:600; }
    .type-badge { padding:5px 14px; border-radius:40px; font-size:0.75rem; font-weight:700; }
    .type-employee { background:#E8F0FE; color:#1A5D9C; }
    .type-vendor { background:#FFF3E0; color:#C45C00; }
    .type-customer { background:#E3F7EC; color:#1E6F3F; }
    .balance-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:22px; margin-bottom:32px; }
    .balance-card { background:var(--bg-card); border-radius:20px; padding:20px 24px; border-top:3px solid var(--primary); box-shadow:var(--shadow-sm); }
    .balance-card .value { font-size:1.8rem; font-weight:800; }
    .balance-card .value.credit { color:var(--success); }
    .balance-card .value.debit { color:var(--danger); }
    .section-card { background:var(--bg-card); border-radius:20px; margin-bottom:32px; overflow:auto; box-shadow:var(--shadow-md); }
    .section-header { background:var(--primary-soft); padding:14px 24px; font-weight:700; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; }
    .data-table { width:100%; border-collapse:collapse; font-size:0.85rem; min-width:1000px; }
    .data-table th { background:#F1F6FD; padding:12px; text-align:center; border-bottom:2px solid var(--primary); }
    .data-table td { padding:10px; border-bottom:1px solid #eee; text-align:center; }
    .amount-col { text-align:right; }
    .bill-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:40px; font-size:0.7rem; font-weight:600; }
    .bill-stitching { background:#E8F0FE; color:#1A5D9C; }
    .bill-fabric { background:#FFF3E0; color:#C45C00; }
    .bill-payment { background:#EFF2F6; color:#4A627A; }
    .bill-opening { background:#FEF5E7; color:#B95A00; }
    .running-balance { font-weight:700; padding:4px 10px; border-radius:40px; display:inline-block; }
    .running-balance.positive { background:#E6F4EA; color:var(--success); }
    .running-balance.negative { background:#FEF2F0; color:var(--danger); }
    .btn { padding:8px 18px; border-radius:10px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
    .btn-primary { background:var(--primary); color:white; }
    .btn-primary:hover { background:var(--primary-dark); }
    .btn-secondary { background:#EDF2F7; color:var(--text-dark); }
    .btn-success { background:var(--success); color:white; }
    .btn-sm { padding:5px 12px; font-size:0.7rem; }
    .action-buttons { display:flex; gap:16px; margin-top:20px; flex-wrap:wrap; }
    .modal-dialog-wide { max-width:90%; width:1200px; }
    @media (max-width:992px) { .container { margin-left:0; width:100%; } .filter-bar { flex-direction:column; align-items:stretch; } .filter-group { flex-direction:column; align-items:stretch; } }
</style>
</head>
<body>
<?php include("../../includes/navbar.php"); ?>
<div class="container">
    <div class="page-header"><h2><i class="fas fa-book-open"></i> Ledger Details</h2></div>
    <div class="filter-bar">
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> Date Range:</label>
            <input type="date" id="custom_from" value="<?= htmlspecialchars($custom_from) ?>" placeholder="From">
            <span>to</span>
            <input type="date" id="custom_to" value="<?= htmlspecialchars($custom_to) ?>" placeholder="To">
            <select class="filter-select" id="dateFilter" onchange="applyFilters()">
                <option value="all" <?= $filter=='all'?'selected':'' ?>>All Records</option>
                <option value="today" <?= $filter=='today'?'selected':'' ?>>Today</option>
                <option value="last_day" <?= $filter=='last_day'?'selected':'' ?>>Last Day</option>
                <option value="this_week" <?= $filter=='this_week'?'selected':'' ?>>This Week</option>
                <option value="last_week" <?= $filter=='last_week'?'selected':'' ?>>Last Week</option>
                <option value="this_month" <?= $filter=='this_month'?'selected':'' ?>>This Month</option>
                <option value="last_month" <?= $filter=='last_month'?'selected':'' ?>>Last Month</option>
                <option value="last_3_month" <?= $filter=='last_3_month'?'selected':'' ?>>Last 3 Months</option>
                <option value="last_6_month" <?= $filter=='last_6_month'?'selected':'' ?>>Last 6 Months</option>
                <option value="this_year" <?= $filter=='this_year'?'selected':'' ?>>This Year</option>
                <option value="last_year" <?= $filter=='last_year'?'selected':'' ?>>Last Year</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-chart-line"></i> View Type:</label>
            <select class="filter-select" id="groupBySelect" onchange="applyFilters()">
                <option value="detailed" <?= $group_by=='detailed'?'selected':'' ?>>Detailed</option>
                <option value="weekly" <?= $group_by=='weekly'?'selected':'' ?>>Weekly Summary</option>
                <option value="monthly" <?= $group_by=='monthly'?'selected':'' ?>>Monthly Summary</option>
                <option value="quarterly" <?= $group_by=='quarterly'?'selected':'' ?>>Quarterly Summary</option>
                <option value="half_yearly" <?= $group_by=='half_yearly'?'selected':'' ?>>Half‑Yearly Summary</option>
                <option value="yearly" <?= $group_by=='yearly'?'selected':'' ?>>Yearly Summary</option>
            </select>
            <button class="btn-export" onclick="exportToCSV()"><i class="fas fa-file-excel"></i> Export to Excel</button>
        </div>
        <div class="filter-badge"><i class="fas fa-filter"></i> Showing: <?= getFilterDisplayName($filter, $custom_from, $custom_to) ?> <?php if($isFiltered && $start_date && $end_date): ?>(<?= date('d M Y',strtotime($start_date))?> - <?= date('d M Y',strtotime($end_date))?>)<?php endif; ?></div>
    </div>
    <?php if($account): ?>
    <div class="account-card">
        <div class="account-info">
            <div><span class="info-label"><i class="fas fa-user"></i> Account Name</span><div class="info-value"><?= htmlspecialchars($account['account_name']) ?></div></div>
            <div><span class="info-label"><i class="fas fa-tag"></i> Type</span><div class="type-badge type-<?= $account['account_type'] ?>"><?= ucfirst($account['account_type']) ?></div></div>
            <div><span class="info-label"><i class="fas fa-phone"></i> Phone</span><div class="info-value"><?= htmlspecialchars($account['phone']??'-') ?></div></div>
        </div>
        <div><a href="payment_entry.php?account_id=<?= $id ?>" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add Payment</a></div>
    </div>
    <div class="balance-grid">
        <div class="balance-card"><div class="label"><i class="fas fa-chart-line"></i> Current Balance</div><div class="value <?= $total_pending_amount>=0?'credit':'debit' ?>">Rs. <?= number_format(abs($total_pending_amount),2) ?> <small>(<?= $total_pending_amount>=0?'receivable':'payable' ?>)</small></div></div>
    </div>
    <div class="section-card">
        <div class="section-header">
            <span><i class="fas fa-list-ul"></i> <?= $group_by=='detailed'?'Complete Statement (Running Balance)':'Period-wise Summary ('.ucfirst(str_replace('_',' ',$group_by)).')' ?></span>
            <span class="badge bg-primary text-white rounded-pill px-3"><?= $group_by=='detailed'?count($entries_with_balance):count($grouped_data??0) ?> entries</span>
        </div>
        <div class="section-body">
            <?php if($group_by=='detailed'): ?>
                <?php if(!empty($entries_with_balance)): ?>
                <table class="data-table">
                    <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Job No</th><th>Qty</th><th>Rate</th><th class="amount-col">Bill (Rs)</th><th class="amount-col">Credit (Rs)</th><th class="amount-col">Balance (Rs)</th><th>Description</th></tr></thead>
                    <tbody><?php $c=1; foreach($entries_with_balance as $e): $bal_class=$e['running_balance']>=0?'positive':'negative'; ?>
                        <tr class="<?= $e['type']=='opening'?'opening-row':'' ?>"><td><?= $c++ ?></td><td><?= $e['type']=='opening'?'-':date('d-m-Y',strtotime($e['date'])) ?></td>
                        <td><?php if($e['type']=='opening'): ?><span class="bill-badge bill-opening"><i class="fas fa-database"></i> Opening</span><?php elseif($e['type']=='bill'): ?><span class="bill-badge bill-stitching"><i class="fas fa-file-invoice"></i> Bill</span><?php elseif(($e['claim_type']??'')=='fabric_sale'): ?><span class="bill-badge bill-fabric"><i class="fas fa-tag"></i> Fabric Sale</span><?php else: ?><span class="bill-badge bill-payment"><i class="fas fa-money-bill-wave"></i> Payment</span><?php endif; ?></td>
                        <td><?= ($e['type']=='bill'||($e['claim_type']??'')=='fabric_sale')?'<span class="job-no">'.htmlspecialchars($e['job_no']??'-').'</span>':'-' ?></td>
                        <td><?= ($e['type']=='bill'||($e['claim_type']??'')=='fabric_sale')?number_format($e['qty'],0):'-' ?></td>
                        <td><?= ($e['type']=='bill'||($e['claim_type']??'')=='fabric_sale')?'Rs. '.number_format($e['rate'],2):'-' ?></td>
                        <td class="amount-col"><?= $e['type']=='bill'?'Rs. '.number_format($e['bill_amount'],2):'-' ?></td>
                        <td class="amount-col <?= isset($e['transaction_type'])&&$e['transaction_type']=='debit'?'debit':'credit' ?>"><?= $e['type']!='opening'?'Rs. '.number_format(abs($e['paid_amount']),2):'-' ?></td>
                        <td class="amount-col"><span class="running-balance <?= $bal_class ?>">Rs. <?= number_format(abs($e['running_balance']),2) ?><?= $e['running_balance']<0?' <small>(Payable)</small>':($e['running_balance']>0?' <small>(Receivable)</small>':'') ?></span></td>
                        <td><small><?= htmlspecialchars(substr($e['description'],0,50)) ?></small></td></tr>
                    <?php endforeach; ?></tbody>
                    <tfoot><tr><td colspan="6" class="text-end"><strong>TOTAL</strong></td><td class="amount-col"><strong>Rs. <?= number_format($total_bill_amount,2) ?></strong></td><td class="amount-col"><strong>Rs. <?= number_format($total_paid_amount,2) ?></strong></td><td class="amount-col"><span class="running-balance <?= $total_pending_amount>=0?'positive':'negative' ?>">Rs. <?= number_format(abs($total_pending_amount),2) ?> (<?= $total_pending_amount>=0?'Receivable':'Payable' ?>)</span></td><td></tr></tfoot>
                </table>
                <?php else: ?><div class="empty-state p-5 text-center">No records found</div><?php endif; ?>
            <?php else: ?>
                <?php if(!empty($grouped_data)): ?>
                <table class="data-table">
                    <thead><tr><th>#</th><th>Period</th><th class="amount-col">Total Bills</th><th class="amount-col">Total Payments</th><th class="amount-col">Net Change</th><th class="amount-col">Opening Balance</th><th class="amount-col">Closing Balance</th><th>Action</th></tr></thead>
                    <tbody><?php $pc=1; foreach($grouped_data as $idx=>$g): ?>
                        <tr><td><?= $pc++ ?></td><td style="text-align:left; font-weight:600"><?= htmlspecialchars($g['period_label']) ?></td>
                        <td class="amount-col">Rs. <?= number_format($g['total_bills'],2) ?></td>
                        <td class="amount-col <?= $g['total_payments']>=0?'credit':'debit' ?>">Rs. <?= number_format(abs($g['total_payments']),2) ?></td>
                        <td class="amount-col <?= $g['net_change']>=0?'credit':'debit' ?>">Rs. <?= number_format(abs($g['net_change']),2) ?> <?= $g['net_change']>=0?'(+)':'(-)' ?></td>
                        <td class="amount-col <?= $g['opening_balance']>=0?'credit':'debit' ?>">Rs. <?= number_format(abs($g['opening_balance']),2) ?></td>
                        <td class="amount-col <?= $g['closing_balance']>=0?'credit':'debit' ?>"><strong>Rs. <?= number_format(abs($g['closing_balance']),2) ?></strong></td>
                        <td><button class="btn btn-primary btn-sm view-detail-btn" data-period-index="<?= $idx ?>"><i class="fas fa-eye"></i> View Details</button></td></tr>
                    <?php endforeach; ?></tbody>
                    <tfoot><tr><td colspan="2" class="text-end"><strong>GRAND TOTAL</strong></td><td class="amount-col"><strong>Rs. <?= number_format($grouped_total_bills??0,2) ?></strong></td><td class="amount-col"><strong>Rs. <?= number_format($grouped_total_payments??0,2) ?></strong></td><td colspan="4"></td></tr></tfoot>
                </table>
                <?php else: ?><div class="empty-state p-5 text-center">No data for selected grouping</div><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="action-buttons"><a href="ledger_list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a></div>
    <?php else: ?><div class="alert alert-danger">Account not found</div><?php endif; ?>
</div>
<div class="modal fade" id="periodDetailModal" tabindex="-1"><div class="modal-dialog modal-dialog-wide modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Period Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="periodDetailBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function applyFilters(){
    var f = document.getElementById('dateFilter').value;
    var g = document.getElementById('groupBySelect').value;
    var from = document.getElementById('custom_from').value;
    var to = document.getElementById('custom_to').value;
    var url = new URL(window.location.href);
    url.searchParams.set('filter', f);
    url.searchParams.set('group_by', g);
    if (from) url.searchParams.set('from_date', from); else url.searchParams.delete('from_date');
    if (to) url.searchParams.set('to_date', to); else url.searchParams.delete('to_date');
    window.location.href = url.toString();
}
function exportToCSV(){
    var url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    window.open(url.toString(), '_blank');
}
var groupedData=<?php echo json_encode($grouped_data?:[]); ?>;
function showPeriodDetails(idx){ var p=groupedData[idx]; if(!p) return; var html='<h6>'+p.period_label+'</h6><table class="table table-sm table-bordered"><thead><tr><th>#</th><th>Date</th><th>Type</th><th>Job No</th><th>Qty</th><th>Rate</th><th>Bill (Rs)</th><th>Payment (Rs)</th><th>Balance (Rs)</th><th>Description</th></tr></thead><tbody>';
var bal=p.opening_balance; var cnt=1;
for(var i=0;i<p.entries.length;i++){ var e=p.entries[i]; 
    var typeLabel=''; if(e.type=='bill') typeLabel='Bill'; else if(e.claim_type=='fabric_sale') typeLabel='Fabric Sale'; else typeLabel='Payment';
    var payClass=(e.paid_amount>0)?'text-success':(e.paid_amount<0?'text-danger':'');
    html+='<tr><td>'+cnt+++'</td><td>'+(e.date?new Date(e.date).toLocaleDateString('en-GB'):'-')+'</td><td>'+typeLabel+'</td><td>'+(e.job_no&&e.job_no!='-'?e.job_no:'-')+'</td><td>'+(e.qty?e.qty:'-')+'</td><td>'+(e.rate?'Rs.'+parseFloat(e.rate).toFixed(2):'-')+'</td><td class="text-end">'+(e.bill_amount?'Rs.'+parseFloat(e.bill_amount).toFixed(2):'-')+'</td><td class="text-end '+payClass+'">'+(e.paid_amount!=0?'Rs.'+Math.abs(parseFloat(e.paid_amount)).toFixed(2):'-')+'</td>';
    if(e.type=='bill') bal+=parseFloat(e.bill_amount); else bal+=parseFloat(e.paid_amount);
    var balClass=(bal>=0)?'text-success':'text-danger';
    html+='<td class="text-end '+balClass+'"><strong>Rs.'+Math.abs(bal).toFixed(2)+(bal<0?' (Payable)':(bal>0?' (Receivable)':''))+'</strong></td><td>'+(e.description?e.description.substring(0,50):'')+'</td></tr>';
} html+='</tbody></table>'; document.getElementById('periodDetailBody').innerHTML=html; new bootstrap.Modal(document.getElementById('periodDetailModal')).show(); }
document.addEventListener('DOMContentLoaded',function(){ document.querySelectorAll('.view-detail-btn').forEach(btn=>{ btn.addEventListener('click',function(){ var idx=this.getAttribute('data-period-index'); if(idx!==null) showPeriodDetails(parseInt(idx)); }); }); });
</script>
</body>
</html>