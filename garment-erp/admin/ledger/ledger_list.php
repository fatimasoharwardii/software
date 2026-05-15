<?php
$page_identifier = 'ledger/ledger_list.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['accounts', 'ledger_transactions'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Initialize filters
$account_type_filter = isset($_GET['account_type']) ? trim($_GET['account_type']) : '';
$balance_type_filter = isset($_GET['balance_type']) ? trim($_GET['balance_type']) : '';
$search_filter = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build WHERE clause for accounts with company filter
$accounts_where = ["company_id = ?"];
$accounts_params = [$company_id];
$accounts_types = "i";

if (!empty($account_type_filter)) {
    $accounts_where[] = "account_type = ?";
    $accounts_params[] = $account_type_filter;
    $accounts_types .= "s";
}
if (!empty($search_filter)) {
    $accounts_where[] = "account_name LIKE ?";
    $accounts_params[] = "%$search_filter%";
    $accounts_types .= "s";
}
if ($balance_type_filter == 'credit') {
    $accounts_where[] = "balance > 0";
} elseif ($balance_type_filter == 'debit') {
    $accounts_where[] = "balance < 0";
} elseif ($balance_type_filter == 'zero') {
    $accounts_where[] = "balance = 0";
}

$accounts_sql = "SELECT id, account_name, account_type, balance FROM accounts WHERE " . implode(" AND ", $accounts_where) . " ORDER BY account_type, account_name ASC";
$accounts_stmt = $conn->prepare($accounts_sql);
$accounts_stmt->bind_param($accounts_types, ...$accounts_params);
$accounts_stmt->execute();
$accounts_result = $accounts_stmt->get_result();

// Totals query (same conditions)
$totals_where = ["company_id = ?"];
$totals_params = [$company_id];
$totals_types = "i";
if (!empty($account_type_filter)) {
    $totals_where[] = "account_type = ?";
    $totals_params[] = $account_type_filter;
    $totals_types .= "s";
}
if (!empty($search_filter)) {
    $totals_where[] = "account_name LIKE ?";
    $totals_params[] = "%$search_filter%";
    $totals_types .= "s";
}
$totals_sql = "SELECT 
    COUNT(*) as total_accounts,
    SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as total_credit,
    SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END) as total_debit,
    SUM(balance) as net_balance,
    COUNT(CASE WHEN account_type = 'employee' THEN 1 END) as employee_count,
    COUNT(CASE WHEN account_type = 'vendor' THEN 1 END) as vendor_count,
    COUNT(CASE WHEN account_type = 'party' THEN 1 END) as party_count
FROM accounts WHERE " . implode(" AND ", $totals_where);
$totals_stmt = $conn->prepare($totals_sql);
$totals_stmt->bind_param($totals_types, ...$totals_params);
$totals_stmt->execute();
$totals = $totals_stmt->get_result()->fetch_assoc();
if (!$totals) $totals = ['total_accounts' => 0, 'total_credit' => 0, 'total_debit' => 0, 'net_balance' => 0, 'employee_count' => 0, 'vendor_count' => 0, 'party_count' => 0];

// Transactions query with company filter
$trans_where = ["lt.company_id = ?"];
$trans_params = [$company_id];
$trans_types = "i";

if (!empty($date_from)) {
    $trans_where[] = "DATE(lt.date) >= ?";
    $trans_params[] = $date_from;
    $trans_types .= "s";
}
if (!empty($date_to)) {
    $trans_where[] = "DATE(lt.date) <= ?";
    $trans_params[] = $date_to;
    $trans_types .= "s";
}
if (!empty($search_filter)) {
    $trans_where[] = "(a1.account_name LIKE ? OR a2.account_name LIKE ? OR lt.description LIKE ?)";
    $like = "%$search_filter%";
    $trans_params[] = $like;
    $trans_params[] = $like;
    $trans_params[] = $like;
    $trans_types .= "sss";
}

$trans_sql = "SELECT 
    lt.id,
    lt.date,
    a1.account_name as from_name,
    a1.account_type as from_type,
    a2.account_name as to_name,
    a2.account_type as to_type,
    lt.amount,
    lt.description
FROM ledger_transactions lt
JOIN accounts a1 ON lt.from_account = a1.id
JOIN accounts a2 ON lt.to_account = a2.id
WHERE " . implode(" AND ", $trans_where) . "
ORDER BY lt.date DESC, lt.id DESC LIMIT 50";
$trans_stmt = $conn->prepare($trans_sql);
$trans_stmt->bind_param($trans_types, ...$trans_params);
$trans_stmt->execute();
$transactions_result = $trans_stmt->get_result();

// Transaction totals (same filters, ignoring LIMIT)
$trans_totals_where = ["lt.company_id = ?"];
$trans_totals_params = [$company_id];
$trans_totals_types = "i";
if (!empty($date_from)) {
    $trans_totals_where[] = "DATE(lt.date) >= ?";
    $trans_totals_params[] = $date_from;
    $trans_totals_types .= "s";
}
if (!empty($date_to)) {
    $trans_totals_where[] = "DATE(lt.date) <= ?";
    $trans_totals_params[] = $date_to;
    $trans_totals_types .= "s";
}
$trans_totals_sql = "SELECT COUNT(*) as total_transactions, SUM(lt.amount) as total_amount FROM ledger_transactions lt WHERE " . implode(" AND ", $trans_totals_where);
$trans_totals_stmt = $conn->prepare($trans_totals_sql);
$trans_totals_stmt->bind_param($trans_totals_types, ...$trans_totals_params);
$trans_totals_stmt->execute();
$trans_totals = $trans_totals_stmt->get_result()->fetch_assoc();
if (!$trans_totals) $trans_totals = ['total_transactions' => 0, 'total_amount' => 0];
?>

<!DOCTYPE html>
<html>
<head>
<title>Ledger Accounts - Khata System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --border: #E9ECEF;
        --bg-light: #F8F9FA;
        --success: #27ae60;
        --danger: #e74c3c;
        --info: #3498db;
        --payable: #C0392B;
        --receivable: #2C7A4B;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
        color: var(--text-dark);
    }

    .main-container {
        margin-left: 14%;
        padding: 28px 32px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .page-header {
        margin-bottom: 28px;
    }

    .page-header h2 {
        font-size: 1.6rem;
        font-weight: 600;
        margin: 0 0 6px 0;
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--text-dark);
    }

    .page-header h2 i {
        color: var(--primary);
    }

    .page-header p {
        color: var(--text-muted);
        font-size: 0.85rem;
        margin: 0;
    }

    .filter-form {
        background: white;
        border-radius: 16px;
        padding: 20px 24px;
        margin-bottom: 28px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        border: 1px solid var(--border);
    }

    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 16px;
    }

    .filter-item {
        flex: 1;
        min-width: 160px;
    }

    .filter-item label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    .filter-item label i {
        color: var(--primary);
        width: 16px;
        margin-right: 4px;
    }

    .filter-item input,
    .filter-item select {
        width: 100%;
        padding: 8px 12px;
        border: 1.5px solid var(--border);
        border-radius: 10px;
        font-size: 0.85rem;
        transition: all 0.2s;
        background: white;
    }

    .filter-item input:focus,
    .filter-item select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .filter-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .btn {
        padding: 8px 18px;
        font-size: 0.8rem;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(243,156,18,0.2);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-success:hover {
        background: #219a52;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #f1f3f5;
        color: var(--text-dark);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: #e9ecef;
    }

    .btn-sm {
        padding: 5px 12px;
        font-size: 0.7rem;
    }

    .summary-row {
        display: flex;
        gap: 20px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }

    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 16px 20px;
        flex: 1;
        min-width: 140px;
        border-left: 3px solid var(--primary);
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.06);
    }

    .stat-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .stat-label i {
        color: var(--primary);
        font-size: 0.8rem;
    }

    .stat-value {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .stat-card.payable .stat-value {
        color: var(--payable);
    }

    .stat-card.receivable .stat-value {
        color: var(--receivable);
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 32px 0 20px 0;
    }

    .section-title i {
        font-size: 1.3rem;
        color: var(--primary);
    }

    .section-title h3 {
        font-size: 1.2rem;
        font-weight: 600;
        color: var(--text-dark);
        margin: 0;
    }

    .table-wrapper {
        background: white;
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        margin-bottom: 32px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    th {
        background: var(--bg-light);
        padding: 14px 16px;
        font-weight: 600;
        color: var(--text-dark);
        border-bottom: 2px solid var(--primary);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    tr:last-child td {
        border-bottom: none;
    }

    tr:hover td {
        background: var(--primary-light);
    }

    .account-type-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .type-employee {
        background: #e3f2fd;
        color: #1565c0;
    }

    .type-vendor {
        background: #fff3e0;
        color: #e65100;
    }

    .type-party {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .type-other {
        background: #f3e5f5;
        color: #6a1b9a;
    }

    .balance {
        font-weight: 700;
        text-align: right;
    }

    .balance.payable {
        color: var(--payable);
    }

    .balance.receivable {
        color: var(--receivable);
    }

    .balance small {
        font-weight: normal;
        font-size: 0.75rem;
        opacity: 0.8;
        margin-left: 4px;
    }

    .balance-zero {
        color: var(--text-muted);
        font-weight: 700;
        text-align: right;
    }

    .amount {
        font-weight: 600;
        text-align: right;
        color: var(--primary-dark);
    }

    .date {
        color: var(--text-muted);
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .description {
        color: var(--text-muted);
        max-width: 250px;
        word-break: break-word;
    }

    .action-buttons {
        display: flex;
        gap: 6px;
        justify-content: center;
    }

    .btn-icon {
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 0.7rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        text-decoration: none;
        transition: 0.2s;
    }

    .btn-view {
        background: var(--info);
        color: white;
    }

    .btn-view:hover {
        background: #2980b9;
        transform: translateY(-1px);
    }

    .btn-edit {
        background: var(--primary);
        color: white;
    }

    .btn-edit:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .info-box {
        background: white;
        border-radius: 16px;
        padding: 20px 24px;
        border: 1px solid var(--border);
        border-left: 3px solid var(--primary);
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        margin-top: 20px;
    }

    .info-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--primary-dark);
        margin-bottom: 12px;
    }

    .info-box ul {
        margin: 0 0 0 20px;
    }

    .info-box li {
        margin: 8px 0;
        font-size: 0.8rem;
        color: var(--text-muted);
        line-height: 1.5;
    }

    .info-box strong {
        color: var(--primary-dark);
    }

    .empty-state {
        text-align: center;
        padding: 48px 20px;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 3rem;
        color: #dee2e6;
        margin-bottom: 12px;
    }

    .empty-state p {
        font-size: 0.85rem;
        margin: 0;
    }

    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 20px;
        }
        .filter-item {
            min-width: 100%;
        }
        .summary-row {
            flex-direction: column;
        }
        .table-wrapper {
            overflow-x: auto;
        }
        table {
            min-width: 700px;
        }
    }
</style>
</head>
<body>
<?php include("../../includes/navbar.php"); ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-book"></i> Ledger Accounts</h2>
        <p>Manage vendor, party and employee accounts – track balances and transactions</p>
    </div>

    <!-- Filter Form -->
    <div class="filter-form">
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-item">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search_filter) ?>" placeholder="Account name or description">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-tag"></i> Account Type</label>
                    <select name="account_type">
                        <option value="">All Types</option>
                        <option value="employee" <?= $account_type_filter == 'employee' ? 'selected' : '' ?>>Employee</option>
                        <option value="vendor" <?= $account_type_filter == 'vendor' ? 'selected' : '' ?>>Vendor</option>
                        <option value="party" <?= $account_type_filter == 'party' ? 'selected' : '' ?>>Party</option>
                        <option value="other" <?= $account_type_filter == 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-chart-bar"></i> Balance Type</label>
                    <select name="balance_type">
                        <option value="">All Balances</option>
                        <option value="credit" <?= $balance_type_filter == 'credit' ? 'selected' : '' ?>>Payable (You owe)</option>
                        <option value="debit" <?= $balance_type_filter == 'debit' ? 'selected' : '' ?>>Receivable (They owe)</option>
                        <option value="zero" <?= $balance_type_filter == 'zero' ? 'selected' : '' ?>>Zero Balance</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                <a href="ledger_list.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                <a href="payment_entry.php" class="btn btn-success"><i class="fas fa-plus"></i> New Transaction</a>
            </div>
        </form>
    </div>

   

    <!-- Accounts Table -->
    <div class="section-title">
        <i class="fas fa-users"></i>
        <h3>All Accounts (<?= $totals['total_accounts'] ?? 0 ?>)</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Account Name</th><th>Type</th><th style="text-align: right;">Current Balance</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if($accounts_result && $accounts_result->num_rows > 0): ?>
                    <?php while($row = $accounts_result->fetch_assoc()): 
                        $balance = floatval($row['balance']);
                        $type_class = 'type-' . $row['account_type'];
                        if ($balance == 0) {
                            $balance_display = '<span class="balance-zero">Rs. 0.00</span>';
                        } else {
                            $balance_class = $balance > 0 ? 'receivable' : 'payable';
                            $balance_label = $balance > 0 ? 'receivable' : 'payable';
                            $balance_display = '<span class="balance ' . $balance_class . '">Rs. ' . number_format(abs($balance), 2) . ' <small>(' . $balance_label . ')</small></span>';
                        }
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($row['account_name']) ?></td>
                        <td><span class="account-type-badge <?= $type_class ?>"><?= ucfirst($row['account_type']) ?></span></td>
                        <td><?= $balance_display ?></td>
                        <td class="action-buttons">
                            <a href="ledger_view.php?id=<?= $row['id'] ?>" class="btn-icon btn-view" title="View Details"><i class="fas fa-eye"></i> View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="empty-state"><i class="fas fa-folder-open"></i><p>No accounts found</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Transactions Table -->
    <div class="section-title">
        <i class="fas fa-exchange-alt"></i>
        <h3>Recent Transactions (<?= $trans_totals['total_transactions'] ?? 0 ?>)</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Date</th><th>From Account</th><th>To Account</th><th>Description</th><th style="text-align: right;">Amount</th></tr>
            </thead>
            <tbody>
                <?php if($transactions_result && $transactions_result->num_rows > 0): ?>
                    <?php while($row = $transactions_result->fetch_assoc()): ?>
                    <tr>
                        <td class="date"><i class="far fa-calendar-alt me-1"></i> <?= date('d-m-Y', strtotime($row['date'])) ?></td>
                        <td><span class="account-type-badge type-<?= $row['from_type'] ?>"><?= substr($row['from_type'], 0, 1) ?></span> <?= htmlspecialchars($row['from_name']) ?></td>
                        <td><span class="account-type-badge type-<?= $row['to_type'] ?>"><?= substr($row['to_type'], 0, 1) ?></span> <?= htmlspecialchars($row['to_name']) ?></td>
                        <td class="description"><i class="far fa-file-alt me-1"></i> <?= htmlspecialchars($row['description'] ?? '-') ?></td>
                        <td class="amount">Rs. <?= number_format($row['amount'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="empty-state"><i class="fas fa-exchange-alt"></i><p>No transactions found</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Info Box -->
    <div class="info-box">
        <div class="info-title"><i class="fas fa-info-circle"></i> How Khata (Ledger) Works</div>
        <ul>
            <li><strong>Employees:</strong> Payments made can reduce or increase their balance.</li>
            <li><strong>Payable Balance – green:</strong> You owe money to that person/party.</li>
            <li><strong>Receivable Balance – red:</strong> They owe money to you.</li>
            <li><strong>Vendors/Parties:</strong> When work is done, amounts are added to track payments owed.</li>
            <li><strong>Complete audit trail</strong> – every transaction affects both accounts.</li>
        </ul>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>