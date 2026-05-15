<?php
$page_identifier = 'billing/post_bill.php';
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
$user_id    = (int)$_SESSION['user_id'];

// 🔒 Validate session user_id exists in users table (prevents FK errors)
$user_check = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$user_check->bind_param("i", $user_id);
$user_check->execute();
if ($user_check->get_result()->num_rows === 0) {
    session_destroy();
    header("Location: ../../login.php?error=invalid_session");
    exit;
}

// ----- Ensure company_id column exists in required tables -----
$tables = ['stitching_posted_bills', 'ledger_transactions'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL AFTER `reference_table`");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Also add other columns if missing (same as before)
$alter_queries = [
    "ALTER TABLE stitching_posted_bills ADD COLUMN IF NOT EXISTS bill_no VARCHAR(100) DEFAULT NULL AFTER id",
    "ALTER TABLE stitching_posted_bills ADD COLUMN IF NOT EXISTS reference_id INT DEFAULT NULL",
    "ALTER TABLE stitching_posted_bills ADD COLUMN IF NOT EXISTS reference_table VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE stitching_posted_bills ADD COLUMN IF NOT EXISTS post_date DATE DEFAULT NULL"
];
foreach($alter_queries as $q) { mysqli_query($conn, $q); }

// Helper: build company filter
function companyFilter($alias = '') {
    global $company_id;
    $prefix = $alias ? $alias . '.' : '';
    return " AND {$prefix}company_id = " . (int)$company_id;
}

// ----- POST BILL (single) -----
if(isset($_GET['action']) && $_GET['action'] == 'post' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // Fetch bill with company filter
    $stmt = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE id = ? AND status != 'posted' AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    
    if($bill) {
        $party = $bill['emp_name'];
        $amount = $bill['total_amount'];
        $job_no = $bill['job_no'];
        $ctype = $bill['claim_type'];
        $bno = !empty($bill['bill_no']) ? $bill['bill_no'] : 'BILL-'.str_pad($id,6,'0',STR_PAD_LEFT);
        
        mysqli_begin_transaction($conn);
        try {
            $update = $conn->prepare("UPDATE stitching_posted_bills SET status = 'posted', post_date = CURDATE() WHERE id = ? AND company_id = ?");
            $update->bind_param("ii", $id, $company_id);
            if(!$update->execute()) throw new Exception("Update failed: " . $update->error);
            
            // Ensure account exists for this company
            $acc_check = $conn->prepare("SELECT id FROM accounts WHERE account_name = ? AND company_id = ?");
            $acc_check->bind_param("si", $party, $company_id);
            $acc_check->execute();
            if($acc_check->get_result()->num_rows == 0) {
                $ins_acc = $conn->prepare("INSERT INTO accounts (account_name, account_type, balance, company_id) VALUES (?, 'vendor', 0, ?)");
                $ins_acc->bind_param("si", $party, $company_id);
                $ins_acc->execute();
            }
            
            // Debit vendor account
            $update_bal = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE account_name = ? AND company_id = ?");
            $update_bal->bind_param("dsi", $amount, $party, $company_id);
            if(!$update_bal->execute()) throw new Exception("Balance update failed");
            
            $desc = "$ctype bill posted for job $job_no (Bill No: $bno) - Amount: ".number_format($amount,2);
            // ✅ Include user_id in the insert
            $trans = $conn->prepare("INSERT INTO ledger_transactions (date, from_account, to_account, amount, description, transaction_type, reference_id, reference_table, company_id, user_id) VALUES (CURDATE(), ?, 'Cash/Bank', ?, ?, 'debit', ?, 'stitching_posted_bills', ?, ?)");
            $trans->bind_param("sdsiii", $party, $amount, $desc, $id, $company_id, $user_id);
            if(!$trans->execute()) throw new Exception("Ledger insert failed");
            
            mysqli_commit($conn);
            $_SESSION['success_msg'] = "Bill posted! Amount Rs. ".number_format($amount,2)." deducted from $party.";
        } catch(Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error_msg'] = "Error: ".$e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Bill already posted or not found for your company.";
    }
    header("Location: post_bill.php");
    exit();
}

// ----- UNPOST BILL (single) -----
if(isset($_GET['action']) && $_GET['action'] == 'unpost' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE id = ? AND status = 'posted' AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    
    if($bill) {
        $party = $bill['emp_name'];
        $amount = $bill['total_amount'];
        $job_no = $bill['job_no'];
        $ctype = $bill['claim_type'];
        $bno = !empty($bill['bill_no']) ? $bill['bill_no'] : 'BILL-'.str_pad($id,6,'0',STR_PAD_LEFT);
        
        mysqli_begin_transaction($conn);
        try {
            $update = $conn->prepare("UPDATE stitching_posted_bills SET status = 'pending', post_date = NULL WHERE id = ? AND company_id = ?");
            $update->bind_param("ii", $id, $company_id);
            if(!$update->execute()) throw new Exception("Update failed");
            
            $update_bal = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE account_name = ? AND company_id = ?");
            $update_bal->bind_param("dsi", $amount, $party, $company_id);
            if(!$update_bal->execute()) throw new Exception("Balance update failed");
            
            $desc = "Unpost - $ctype bill reversed for job $job_no (Bill No: $bno) - Amount: ".number_format($amount,2);
            // ✅ Include user_id in the insert
            $trans = $conn->prepare("INSERT INTO ledger_transactions (date, from_account, to_account, amount, description, transaction_type, reference_id, reference_table, company_id, user_id) VALUES (CURDATE(), 'Cash/Bank', ?, ?, ?, 'reversal', ?, 'stitching_posted_bills', ?, ?)");
            $trans->bind_param("sdsiii", $party, $amount, $desc, $id, $company_id, $user_id);
            if(!$trans->execute()) throw new Exception("Ledger insert failed");
            
            mysqli_commit($conn);
            $_SESSION['success_msg'] = "Bill unposted! Amount Rs. ".number_format($amount,2)." added back to $party.";
        } catch(Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error_msg'] = "Error: ".$e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Bill not posted or not found for your company.";
    }
    header("Location: post_bill.php");
    exit();
}

// Messages
$success_msg = $_SESSION['success_msg'] ?? ''; unset($_SESSION['success_msg']);
$error_msg = $_SESSION['error_msg'] ?? ''; unset($_SESSION['error_msg']);

// Filters
$claim_type = $_GET['claim_type'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Build base WHERE clause with company filter
$where_base = "1=1" . companyFilter('spb');
if($claim_type) $where_base .= " AND spb.claim_type='".mysqli_real_escape_string($conn,$claim_type)."'";
if($from_date) $where_base .= " AND DATE(spb.claim_date)>='$from_date'";
if($to_date) $where_base .= " AND DATE(spb.claim_date)<='$to_date'";

// Fetch unposted bills
$unposted_rows = [];
$res = $conn->query("SELECT spb.* FROM stitching_posted_bills spb WHERE spb.status != 'posted' AND $where_base ORDER BY spb.claim_date DESC, spb.id DESC");
if($res) while($row=mysqli_fetch_assoc($res)) $unposted_rows[] = $row;

// Fetch posted bills
$posted_rows = [];
$res = $conn->query("SELECT spb.* FROM stitching_posted_bills spb WHERE spb.status = 'posted' AND $where_base ORDER BY spb.claim_date DESC, spb.id DESC");
if($res) while($row=mysqli_fetch_assoc($res)) $posted_rows[] = $row;

// Totals
$unposted_total = count($unposted_rows);
$unposted_gt = 0; foreach($unposted_rows as $r) $unposted_gt += $r['total_amount'];
$posted_total = count($posted_rows);
$posted_gt = 0; foreach($posted_rows as $r) $posted_gt += $r['total_amount'];

// Claim types for filter (only from current company)
$types_res = $conn->query("SELECT DISTINCT claim_type FROM stitching_posted_bills WHERE 1=1 " . companyFilter('') . " ORDER BY claim_type");

// Vendor balances (only from current company)
$vendor_balances = [];
$res = $conn->query("SELECT account_name, balance FROM accounts WHERE account_type='vendor' AND company_id = $company_id ORDER BY account_name");
if($res) while($row=mysqli_fetch_assoc($res)) $vendor_balances[$row['account_name']] = $row['balance'];
?>
<!DOCTYPE html>
<html>
<head>
<title>Bill Management - Post/Unpost</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-dark: #E67E22;
        --primary-light: #FEF5E7;
        --success: #27ae60;
        --success-light: #e8f5e9;
        --danger: #e74c3c;
        --danger-light: #f8d7da;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --bg-light: #F8F9FA;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, sans-serif;
        min-height: 100vh;
    }
    .main-container {
        margin-left: 14%;
        padding: 24px 28px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }
    h2 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        border-bottom: 3px solid var(--primary);
        padding-bottom: 12px;
    }
    h2 i { color: var(--primary); font-size: 1.5rem; }
    .alert {
        padding: 12px 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: none;
        font-size: 0.85rem;
    }
    .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
    .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
    .info-alert {
        background: var(--primary-light);
        border-left: 4px solid var(--primary);
        padding: 12px 18px;
        border-radius: 12px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.8rem;
    }
    .filter-card {
        background: white;
        border-radius: 20px;
        padding: 20px 24px;
        margin-bottom: 24px;
        border: 1px solid var(--border);
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .table-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        border: 1px solid var(--border);
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .table-header {
        background: var(--bg-light);
        padding: 14px 20px;
        border-bottom: 1px solid var(--border);
        font-size: 0.9rem;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .table-header i { color: var(--primary); margin-right: 6px; }
    .table-responsive { overflow-x: auto; }
    .table {
        font-size: 0.8rem;
        margin-bottom: 0;
        min-width: 1100px;
    }
    .table th {
        background: var(--bg-light);
        padding: 12px 14px;
        border-bottom: 2px solid var(--primary);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-size: 0.75rem;
    }
    .table td {
        padding: 12px 14px;
        vertical-align: middle;
        border-bottom: 1px solid var(--border);
    }
    .table tbody tr:hover { background: var(--primary-light); }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .badge-light { background: var(--bg-light); color: var(--text-dark); }
    .badge-success { background: var(--success-light); color: var(--success); }
    .badge-warning { background: #fff3cd; color: #856404; }
    .badge-danger { background: var(--danger-light); color: var(--danger); }
    .badge-secondary { background: #e2e3e5; color: #383d41; }
    .job-no, .bill-no {
        font-weight: 600;
        background: var(--primary-light);
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 0.7rem;
        display: inline-block;
        color: var(--primary-dark);
    }
    .job-no i, .bill-no i { margin-right: 4px; font-size: 0.65rem; }
    .amount { font-weight: 700; color: var(--primary-dark); }
    .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
    .btn-sm { padding: 5px 12px; font-size: 0.7rem; border-radius: 30px; }
    .btn-success { background: var(--success); color: white; }
    .btn-success:hover { background: #219653; transform: translateY(-1px); }
    .btn-danger { background: var(--danger); color: white; }
    .btn-danger:hover { background: #c0392b; transform: translateY(-1px); }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .btn-secondary { background: #6c757d; color: white; }
    .form-select, .form-control {
        border-radius: 30px;
        padding: 8px 16px;
        border: 1.5px solid var(--border);
        font-size: 0.8rem;
    }
    .form-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
    @media (max-width: 1200px) { .main-container { margin-left: 10%; padding: 20px; } }
    @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } }
    @media (max-width: 768px) {
        .action-buttons { flex-direction: column; }
        .action-buttons .btn-sm { width: 100%; justify-content: center; }
        .table td, .table th { padding: 8px; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <h2><i class="fas fa-file-invoice-dollar"></i> Bill Management (Post / Unpost)</h2>
    
    <div class="info-alert">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Note:</strong> Bill post karne par vendor ke account se amount <strong class="text-danger">DEDUCT</strong> ho jayegi.<br>
            Unpost karne par amount <strong class="text-success">WAPAS ADD</strong> ho jayegi aur bill wapas pending list mein aa jayega.
        </div>
    </div>

    <?php if($success_msg): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if($error_msg): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-card">
        <form method="GET" action="post_bill.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-list"></i> Bill Type</label>
                <select name="claim_type" class="form-select">
                    <option value="">All Types</option>
                    <?php while($t = mysqli_fetch_assoc($types_res)): ?>
                    <option value="<?= htmlspecialchars($t['claim_type']) ?>" <?= $claim_type==$t['claim_type']?'selected':'' ?>>
                        <?= ucfirst(str_replace('_',' ',$t['claim_type'])) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-calendar-alt"></i> From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-calendar-alt"></i> To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Apply Filters</button>
            </div>
            <div class="col-md-2">
                <a href="post_bill.php" class="btn btn-secondary w-100"><i class="fas fa-times"></i> Clear Filters</a>
            </div>
        </form>
    </div>

    <!-- ==================== UNPOSTED BILLS ==================== -->
    <div class="table-card">
        <div class="table-header">
            <div><i class="fas fa-clock"></i> Unposted Bills (Pending) <span class="badge bg-secondary ms-2"><?= $unposted_total ?> items</span></div>
            <div><span class="badge bg-secondary">Total Amount: Rs <?= number_format($unposted_gt, 2) ?></span></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Job No</th>
                        <th>Bill No</th>
                        <th>Party Name</th>
                        <th>Bill Type</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate (Rs)</th>
                        <th class="text-end">Amount (Rs)</th>
                        <th>Bill Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($unposted_rows as $r):
                        $party = $r['emp_name'];
                        $bal = $vendor_balances[$party] ?? 0;
                        $bno = !empty($r['bill_no']) ? $r['bill_no'] : 'BILL-'.str_pad($r['id'],6,'0',STR_PAD_LEFT);
                        $balance_class = $bal < 0 ? 'badge-danger' : 'badge-success';
                    ?>
                    <tr>
                        <td><span class="job-no"><i class="fas fa-briefcase"></i> <?= htmlspecialchars($r['job_no']) ?></span></td>
                        <td><span class="bill-no"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($bno) ?></span></td>
                        <td><strong><?= htmlspecialchars($party) ?></strong></td>
                        <td><span class="badge badge-light"><?= ucfirst(str_replace('_',' ',$r['claim_type'])) ?></span></td>
                        <td class="text-end"><?= number_format($r['qty']??0, 0) ?></td>
                        <td class="text-end"><?= number_format($r['rate']??0, 2) ?></td>
                        <td class="text-end amount"><strong>Rs <?= number_format($r['total_amount'], 2) ?></strong></td>
                        <td><?= date('d-m-Y', strtotime($r['claim_date'])) ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="post_bill.php?action=post&id=<?= $r['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Post this bill?\nAmount: Rs <?= number_format($r['total_amount'], 2) ?>');">
                                    <i class="fas fa-check"></i> Post
                                </a>
                                <a href="view_bill.php?id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" target="_blank">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($unposted_rows)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>No unposted bills found.<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== POSTED BILLS ==================== -->
    <div class="table-card">
        <div class="table-header">
            <div><i class="fas fa-check-circle"></i> Posted Bills <span class="badge bg-secondary ms-2"><?= $posted_total ?> items</span></div>
            <div><span class="badge bg-secondary">Total Amount: Rs <?= number_format($posted_gt, 2) ?></span></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Job No</th>
                        <th>Bill No</th>
                        <th>Party Name</th>
                        <th>Bill Type</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate (Rs)</th>
                        <th class="text-end">Amount (Rs)</th>
                        <th>Posted Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($posted_rows as $r):
                        $party = $r['emp_name'];
                        $bal = $vendor_balances[$party] ?? 0;
                        $bno = !empty($r['bill_no']) ? $r['bill_no'] : 'BILL-'.str_pad($r['id'],6,'0',STR_PAD_LEFT);
                    ?>
                    <tr>
                        <td><span class="job-no"><i class="fas fa-briefcase"></i> <?= htmlspecialchars($r['job_no']) ?></span></td>
                        <td><span class="bill-no"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($bno) ?></span></td>
                        <td><strong><?= htmlspecialchars($party) ?></strong></td>
                        <td><span class="badge badge-light"><?= ucfirst(str_replace('_',' ',$r['claim_type'])) ?></span></td>
                        <td class="text-end"><?= number_format($r['qty']??0, 0) ?></td>
                        <td class="text-end"><?= number_format($r['rate']??0, 2) ?></td>
                        <td class="text-end amount"><strong>Rs <?= number_format($r['total_amount'], 2) ?></strong></td>
                        <td><?= $r['post_date'] ? date('d-m-Y', strtotime($r['post_date'])) : '-' ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="post_bill.php?action=unpost&id=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Unpost this bill?\nAmount: Rs <?= number_format($r['total_amount'], 2) ?>');">
                                    <i class="fas fa-undo"></i> Unpost
                                </a>
                                <a href="view_bill.php?id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" target="_blank">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($posted_rows)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>No posted bills found.<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>