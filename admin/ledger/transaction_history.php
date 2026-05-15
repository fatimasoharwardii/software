<?php
$page_identifier = 'ledger/transaction_history.php';
session_start();
include("../../config/db.php");
include("../../includes/functions.php");

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

// Handle delete transaction
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // First, get transaction details (verify it belongs to current company)
    $trans_stmt = $conn->prepare("SELECT from_account, to_account, amount FROM ledger_transactions WHERE id = ? AND company_id = ?");
    $trans_stmt->bind_param("ii", $delete_id, $company_id);
    $trans_stmt->execute();
    $trans = $trans_stmt->get_result()->fetch_assoc();
    
    if ($trans) {
        $conn->begin_transaction();
        try {
            // Reverse balances (add back to from_account, subtract from to_account)
            $reverse_from = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND company_id = ?");
            $reverse_from->bind_param("dii", $trans['amount'], $trans['from_account'], $company_id);
            $reverse_from->execute();
            
            $reverse_to = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND company_id = ?");
            $reverse_to->bind_param("dii", $trans['amount'], $trans['to_account'], $company_id);
            $reverse_to->execute();
            
            // Delete the transaction
            $delete_stmt = $conn->prepare("DELETE FROM ledger_transactions WHERE id = ? AND company_id = ?");
            $delete_stmt->bind_param("ii", $delete_id, $company_id);
            $delete_stmt->execute();
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
        }
    }
    header("Location: payment_list.php");
    exit;
}

// Get filter values (safe – will be used in prepared statements)
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$transaction_type = isset($_GET['transaction_type']) ? trim($_GET['transaction_type']) : '';

// Build base query with company filter
$where_clauses = ["lt.company_id = ?"];
$params = [$company_id];
$types = "i";

if (!empty($from_date)) {
    $where_clauses[] = "lt.date >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if (!empty($to_date)) {
    $where_clauses[] = "lt.date <= ?";
    $params[] = $to_date;
    $types .= "s";
}
if (!empty($transaction_type)) {
    $where_clauses[] = "lt.transaction_type = ?";
    $params[] = $transaction_type;
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Main query
$query = "SELECT lt.*, 
          a1.account_name as from_account_name,
          a2.account_name as to_account_name
          FROM ledger_transactions lt
          LEFT JOIN accounts a1 ON lt.from_account = a1.id AND a1.company_id = lt.company_id
          LEFT JOIN accounts a2 ON lt.to_account = a2.id AND a2.company_id = lt.company_id
          $where_sql
          ORDER BY lt.date DESC, lt.id DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Totals query (same filters)
$total_query = "SELECT SUM(lt.amount) as total_amount FROM ledger_transactions lt
                LEFT JOIN accounts a1 ON lt.from_account = a1.id AND a1.company_id = lt.company_id
                LEFT JOIN accounts a2 ON lt.to_account = a2.id AND a2.company_id = lt.company_id
                $where_sql";
$total_stmt = $conn->prepare($total_query);
$total_stmt->bind_param($types, ...$params);
$total_stmt->execute();
$totals = $total_stmt->get_result()->fetch_assoc();
if (!$totals) $totals = ['total_amount' => 0];
?>
<!DOCTYPE html>
<html>
<head>
<title>Payment List - Ledger Transactions</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --success: #27ae60;
        --danger: #e74c3c;
        --info: #3498db;
        --bg-light: #F8F9FA;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, sans-serif;
    }

    .main-container {
        margin-left: 14%;
        padding: 24px 32px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-header h2 {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h2 i {
        color: var(--primary);
    }

    .btn-add {
        background: var(--success);
        color: white;
        border: none;
        border-radius: 10px;
        padding: 10px 20px;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-add:hover {
        background: #219a52;
        transform: translateY(-2px);
        color: white;
    }

    .card {
        background: white;
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .card-header {
        padding: 16px 20px;
        background: white;
        border-bottom: 2px solid var(--primary);
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header i {
        color: var(--primary);
    }

    .card-body {
        padding: 20px;
    }

    .filter-row {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
        margin-bottom: 20px;
    }

    .filter-item {
        flex: 1;
        min-width: 150px;
    }

    .filter-item label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 4px;
    }

    .filter-item input, .filter-item select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.85rem;
    }

    .btn-filter {
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 8px;
        padding: 8px 20px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        min-width: 800px;
    }

    .table th {
        background: var(--bg-light);
        padding: 12px;
        text-align: left;
        border-bottom: 2px solid var(--primary);
        font-weight: 700;
    }

    .table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .table tr:hover {
        background: var(--primary-light);
    }

    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .badge-transfer {
        background: #e3f2fd;
        color: #1565c0;
    }

    .badge-cash {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .badge-check {
        background: #fff3e0;
        color: #e65100;
    }

    .btn-edit, .btn-delete {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 5px 10px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 0.7rem;
        margin: 0 2px;
        transition: all 0.2s;
    }

    .btn-edit {
        background: var(--info);
        color: white;
    }

    .btn-edit:hover {
        background: #2980b9;
    }

    .btn-delete {
        background: var(--danger);
        color: white;
    }

    .btn-delete:hover {
        background: #c0392b;
    }

    .total-box {
        background: var(--primary-light);
        border-radius: 10px;
        padding: 12px 16px;
        margin-top: 20px;
        text-align: right;
        border: 1px solid var(--primary);
    }

    .total-box strong {
        color: var(--primary-dark);
        font-size: 1rem;
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: var(--text-muted);
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 16px;
            margin-top: 60px;
        }
        .filter-row {
            flex-direction: column;
        }
        .filter-item {
            width: 100%;
        }
    }
</style>
</head>
<body>
<?php include("../../includes/navbar.php"); ?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-list"></i> Payment Transactions</h2>
        <a href="payment_entry.php" class="btn-add"><i class="fas fa-plus"></i> New Payment</a>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-filter"></i> Filter Transactions
        </div>
        <div class="card-body">
            <form method="GET" class="filter-row">
                <div class="filter-item">
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="filter-item">
                    <label>To Date</label>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="filter-item">
                    <label>Transaction Type</label>
                    <select name="transaction_type">
                        <option value="">All</option>
                        <option value="transfer" <?= $transaction_type == 'transfer' ? 'selected' : '' ?>>Transfer</option>
                        <option value="cash" <?= $transaction_type == 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="check" <?= $transaction_type == 'check' ? 'selected' : '' ?>>Check</option>
                    </select>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply Filters</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Transaction History
        </div>
        <div class="card-body">
            <?php if ($result && $result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>From Account</th>
                            <th>To Account</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td><?= date('d-m-Y', strtotime($row['date'])) ?></td>
                            <td><?= htmlspecialchars($row['from_account_name']) ?></td>
                            <td><?= htmlspecialchars($row['to_account_name']) ?></td>
                            <td class="fw-bold text-primary">Rs. <?= number_format($row['amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= $row['transaction_type'] ?>">
                                    <?= ucfirst($row['transaction_type']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars(substr($row['description'] ?? '', 0, 30)) ?>...</td>
                            <td>
                                <a href="edit_payment.php?id=<?= $row['id'] ?>" class="btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="delete_transaction.php?id=<?= $row['id'] ?>" class="btn-delete" onclick="return confirm('Are you sure you want to delete this transaction?')">
                                    <i class="fas fa-trash"></i> Del
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div class="total-box">
                <strong>Total Amount: Rs. <?= number_format($totals['total_amount'] ?? 0, 2) ?></strong>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <p>No transactions found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>