<?php
$page_identifier = 'ledger/payment_entry.php';
session_start();
include("../../config/db.php");
include("../../includes/functions.php");

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id']; // ✅ ADDED: user_id for foreign key

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

$error = '';
$success = '';

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Payment recorded successfully!";
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $date = $_POST['date'];
    $from_name = trim($_POST['from_account'] ?? '');
    $to_name = trim($_POST['to_account'] ?? '');
    $amount = floatval($_POST['amount']);
    $desc = $_POST['description'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? 'transfer';

    // Get From Account (with company filter)
    $stmt_from = $conn->prepare("SELECT id, account_name, account_type, balance FROM accounts WHERE account_name = ? AND company_id = ? LIMIT 1");
    $stmt_from->bind_param("si", $from_name, $company_id);
    $stmt_from->execute();
    $fromRes = $stmt_from->get_result();

    // Get To Account (with company filter)
    $stmt_to = $conn->prepare("SELECT id, account_name, account_type, balance FROM accounts WHERE account_name = ? AND company_id = ? LIMIT 1");
    $stmt_to->bind_param("si", $to_name, $company_id);
    $stmt_to->execute();
    $toRes = $stmt_to->get_result();

    $from_id = 0;
    $to_id = 0;
    $from_balance = 0;
    $to_balance = 0;

    if ($fromRes && $fromRes->num_rows > 0) {
        $fromData = $fromRes->fetch_assoc();
        $from_id = $fromData['id'];
        $from_balance = $fromData['balance'];
    }

    if ($toRes && $toRes->num_rows > 0) {
        $toData = $toRes->fetch_assoc();
        $to_id = $toData['id'];
        $to_balance = $toData['balance'];
    }

    // Validation
    if (empty($from_name)) {
        $error = "From account is required";
    } elseif (empty($to_name)) {
        $error = "To account is required";
    } elseif ($amount <= 0) {
        $error = "Amount must be greater than 0";
    } elseif ($from_id == 0) {
        $error = "From account not found in your company";
    } elseif ($to_id == 0) {
        $error = "To account not found in your company";
    } elseif ($from_id == $to_id) {
        $error = "From and To accounts cannot be the same";
    } else {
        $conn->begin_transaction();
        try {
            // ✅ Insert Transaction with user_id
            $stmt = $conn->prepare("INSERT INTO ledger_transactions
                (date, from_account, to_account, amount, description, transaction_type, created_at, company_id, user_id)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
            $stmt->bind_param("siidssii", $date, $from_id, $to_id, $amount, $desc, $transaction_type, $company_id, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert transaction record");
            }

            // Update From Account (Sender) - Decrease balance (with company check)
            $stmt2 = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND company_id = ?");
            $stmt2->bind_param("dii", $amount, $from_id, $company_id);
            if (!$stmt2->execute()) {
                throw new Exception("Failed to update sender account balance");
            }

            // Update To Account (Receiver) - Increase balance (with company check)
            $stmt3 = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND company_id = ?");
            $stmt3->bind_param("dii", $amount, $to_id, $company_id);
            if (!$stmt3->execute()) {
                throw new Exception("Failed to update receiver account balance");
            }

            $conn->commit();
            header("Location: payment_entry.php?success=1");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

// Fetch all accounts for dropdown (only current company)
$stmt = $conn->prepare("SELECT account_name, account_type, balance FROM accounts WHERE company_id = ? ORDER BY account_type, account_name ASC");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$accounts = $stmt->get_result();

// Get account balances for quick view (company filtered)
$balance_stmt = $conn->prepare("SELECT 
    SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as total_credit,
    SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END) as total_debit,
    SUM(balance) as net_balance
FROM accounts WHERE company_id = ?");
$balance_stmt->bind_param("i", $company_id);
$balance_stmt->execute();
$balance_totals = $balance_stmt->get_result()->fetch_assoc();
if (!$balance_totals) $balance_totals = ['total_credit' => 0, 'total_debit' => 0, 'net_balance' => 0];
?>
<!DOCTYPE html>
<html>
<head>
<title>Payment Entry - Elegant Transaction</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original) */
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
        --info: #3498db;
        --bg-light: #F8F9FA;
        --credit: #27ae60;
        --debit: #e74c3c;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
        font-family: 'Segoe UI', 'Inter', system-ui, -apple-system, sans-serif;
        min-height: 100vh;
    }

    .main-container {
        margin-left: 14%;
        padding: 2rem 1.5rem;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .center-wrapper {
        width: 100%;
        max-width: 580px;
        margin: 0 auto;
    }

    .stats-row {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        justify-content: center;
    }

    .stat-card {
        background: rgba(255,255,255,0.95);
        backdrop-filter: blur(2px);
        border-radius: 1.25rem;
        padding: 0.9rem 1rem;
        border: 1px solid rgba(243,156,18,0.2);
        flex: 1;
        min-width: 110px;
        text-align: center;
        transition: all 0.25s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }

    .stat-card:hover {
        transform: translateY(-3px);
        border-color: var(--primary);
        box-shadow: 0 8px 20px rgba(243,156,18,0.1);
    }

    .stat-icon {
        font-size: 1.2rem;
        color: var(--primary);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 0.65rem;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        margin-bottom: 0.3rem;
    }

    .stat-value {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .stat-value.negative {
        color: var(--danger);
    }

    .payment-card {
        background: white;
        border-radius: 1.75rem;
        box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1), 0 0 0 1px rgba(243,156,18,0.05);
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .payment-card:hover {
        box-shadow: 0 25px 40px -12px rgba(0,0,0,0.15);
    }

    .card-header {
        background: linear-gradient(120deg, var(--primary) 0%, var(--primary-dark) 100%);
        padding: 1.2rem 1.5rem;
        text-align: center;
    }

    .card-header h3 {
        color: white;
        font-size: 1.2rem;
        font-weight: 600;
        margin: 0;
        letter-spacing: -0.2px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
    }

    .card-header h3 i {
        font-size: 1.3rem;
        filter: drop-shadow(0 2px 2px rgba(0,0,0,0.1));
    }

    .card-body {
        padding: 1.8rem 2rem;
    }

    .form-group {
        margin-bottom: 1.2rem;
    }

    label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        margin-bottom: 0.4rem;
    }

    label i {
        color: var(--primary);
        width: 1.2rem;
        margin-right: 0.2rem;
    }

    .required::after {
        content: '*';
        color: var(--danger);
        margin-left: 0.25rem;
    }

    input, textarea, select {
        width: 100%;
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
        border: 1.5px solid var(--border);
        border-radius: 1rem;
        transition: all 0.2s ease;
        background: white;
        font-family: inherit;
    }

    input:focus, textarea:focus, select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.15);
    }

    textarea {
        resize: vertical;
        min-height: 80px;
    }

    .radio-group {
        display: flex;
        gap: 1.2rem;
        flex-wrap: wrap;
        margin-top: 0.2rem;
    }

    .radio-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .radio-item input {
        width: auto;
        height: auto;
        margin: 0;
        cursor: pointer;
        accent-color: var(--primary);
        transform: scale(1.1);
    }

    .radio-item label {
        margin: 0;
        text-transform: none;
        font-weight: 500;
        font-size: 0.85rem;
        cursor: pointer;
        display: inline;
        color: var(--text-dark);
    }

    .btn {
        width: 100%;
        padding: 0.75rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        border: none;
        border-radius: 1rem;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        text-decoration: none;
        margin-top: 0.5rem;
    }

    .btn-primary {
        background: linear-gradient(120deg, var(--primary), var(--primary-dark));
        color: white;
        box-shadow: 0 4px 10px rgba(243,156,18,0.2);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px rgba(243,156,18,0.3);
        background: linear-gradient(120deg, var(--primary-dark), var(--primary));
    }

    .btn-secondary {
        background: #f8f9fa;
        color: var(--text-dark);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: #e9ecef;
        transform: translateY(-1px);
        border-color: var(--primary-light);
    }

    .alert {
        padding: 0.8rem 1rem;
        border-radius: 1rem;
        margin-bottom: 1.2rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-size: 0.8rem;
        border-left: 4px solid;
    }

    .alert-error {
        background: #fef5e7;
        color: #856404;
        border-left-color: var(--danger);
    }

    .alert-success {
        background: #eef7ea;
        color: #2c6e2c;
        border-left-color: var(--success);
    }

    .warning-message {
        color: var(--warning);
        font-size: 0.65rem;
        margin-top: 0.4rem;
        display: none;
        align-items: center;
        gap: 0.3rem;
    }

    .warning-message.show {
        display: flex;
    }

    .info-box {
        background: var(--primary-light);
        border-radius: 1rem;
        padding: 0.8rem 1rem;
        border-left: 3px solid var(--primary);
        margin-top: 1.2rem;
    }

    .info-box ul {
        margin: 0.5rem 0 0 1rem;
        font-size: 0.7rem;
        color: var(--text-muted);
    }

    .info-box li {
        margin: 0.2rem 0;
    }

    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 1rem;
            margin-top: 60px;
        }
        .center-wrapper {
            max-width: 500px;
        }
        .card-body {
            padding: 1.5rem;
        }
    }

    @media (max-width: 576px) {
        .stats-row {
            flex-direction: column;
            gap: 0.7rem;
        }
        .radio-group {
            gap: 0.8rem;
        }
        .btn {
            padding: 0.6rem 0.8rem;
        }
        .card-body {
            padding: 1.2rem;
        }
    }
</style>
</head>
<body>
<?php include("../../includes/navbar.php"); ?>

<div class="main-container">
    <div class="center-wrapper">
        <div style="text-align: center; margin-bottom: 1.2rem;">
            <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--primary-dark); margin:0; letter-spacing: -0.3px;">
                <i class="fas fa-hand-holding-usd" style="color: var(--primary); margin-right: 6px;"></i>
                Payment Entry
            </h2>
            <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.2rem;">Record a new transaction between accounts</p>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-arrow-down" style="color: var(--debit);"></i></div>
                <div class="stat-label">Total Debit</div>
                <div class="stat-value" style="color: var(--debit);">Rs. <?= number_format($balance_totals['total_debit'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-arrow-up" style="color: var(--credit);"></i></div>
                <div class="stat-label">Total Credit</div>
                <div class="stat-value" style="color: var(--credit);">Rs. <?= number_format($balance_totals['total_credit'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-label">Net Balance</div>
                <div class="stat-value <?= ($balance_totals['net_balance'] ?? 0) < 0 ? 'negative' : '' ?>">
                    Rs. <?= number_format($balance_totals['net_balance'] ?? 0, 2) ?>
                </div>
            </div>
        </div>

        <div class="payment-card">
            <div class="card-header">
                <h3><i class="fas fa-receipt"></i> New Transaction</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="paymentForm">
                    <div class="form-group">
                        <label class="required"><i class="fas fa-calendar-alt"></i> Date</label>
                        <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="required"><i class="fas fa-exchange-alt"></i> Transaction Type</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" name="transaction_type" value="transfer" id="type_transfer" checked>
                                <label for="type_transfer"><i class="fas fa-exchange-alt"></i> Transfer</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" name="transaction_type" value="cash" id="type_cash">
                                <label for="type_cash"><i class="fas fa-money-bill-wave"></i> Cash</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" name="transaction_type" value="check" id="type_check">
                                <label for="type_check"><i class="fas fa-check-circle"></i> Check</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="required"><i class="fas fa-arrow-up" style="color: var(--debit);"></i> From Account</label>
                        <input type="text" name="from_account" list="accounts_list" id="from_account" 
                               value="" placeholder="Type or select account" required autocomplete="off">
                        <div class="warning-message" id="fromBalanceWarning">
                            <i class="fas fa-exclamation-triangle"></i> <span></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="required"><i class="fas fa-arrow-down" style="color: var(--credit);"></i> To Account</label>
                        <input type="text" name="to_account" list="accounts_list" id="to_account" 
                               value="" placeholder="Type or select account" required autocomplete="off">
                        <div class="warning-message" id="toBalanceWarning">
                            <i class="fas fa-exclamation-triangle"></i> <span></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="required"><i class="fas fa-rupee-sign"></i> Amount</label>
                        <input type="number" name="amount" step="0.01" min="0.01" 
                               value="" placeholder="0.00" required id="amount">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-file-alt"></i> Description</label>
                        <textarea name="description" rows="2" placeholder="Optional notes"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Payment
                    </button>

                    <div style="display: flex; gap: 0.8rem; margin-top: 0.5rem;">
                        <a href="ledger_list.php" class="btn btn-secondary" style="margin:0;">
                            <i class="fas fa-book"></i> Back to Ledger
                        </a>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='payment_entry.php'" style="margin:0;">
                            <i class="fas fa-undo-alt"></i> Reset Form
                        </button>
                    </div>
                </form>

                <div class="info-box">
                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                        <i class="fas fa-info-circle" style="color: var(--primary); font-size: 0.7rem;"></i>
                        <strong style="font-size: 0.7rem;">Transaction Rules</strong>
                    </div>
                    <ul>
                        <li>Both accounts must exist in the system</li>
                        <li>Amount must be greater than zero</li>
                        <li>From & To accounts cannot be the same</li>
                        <li><span style="color: var(--warning);">⚠️ Negative balance is allowed (overdraft)</span></li>
                        <li><span style="color: var(--success);">✅ After successful payment, page resets automatically</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<datalist id="accounts_list">
    <?php 
    $accounts->data_seek(0);
    while ($row = $accounts->fetch_assoc()): 
        $balance = $row['balance'];
        $balance_class = $balance >= 0 ? 'Credit' : 'Debit';
    ?>
    <option value="<?= htmlspecialchars($row['account_name']) ?>" 
            data-type="<?= $row['account_type'] ?>" 
            data-balance="<?= $row['balance'] ?>">
        <?= htmlspecialchars($row['account_name']) ?> (<?= ucfirst($row['account_type']) ?> - <?= $balance_class ?>: Rs. <?= number_format(abs($balance), 2) ?>)
    </option>
    <?php endwhile; ?>
</datalist>

<script>
const accounts = {};

<?php 
$accounts->data_seek(0);
while ($row = $accounts->fetch_assoc()): 
?>
accounts['<?= addslashes($row['account_name']) ?>'] = {
    type: '<?= $row['account_type'] ?>',
    balance: <?= $row['balance'] ?>
};
<?php endwhile; ?>

document.getElementById('from_account').addEventListener('input', function() {
    const accountName = this.value;
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const warningDiv = document.getElementById('fromBalanceWarning');
    const warningSpan = warningDiv.querySelector('span');
    
    if (accounts[accountName]) {
        const balance = accounts[accountName].balance;
        const newBalance = balance - amount;
        
        if (amount > 0 && newBalance < 0) {
            warningSpan.innerHTML = `⚠️ After transaction: Rs. ${newBalance.toFixed(2)} (Negative allowed)`;
            warningDiv.classList.add('show');
        } else {
            warningDiv.classList.remove('show');
        }
    } else {
        warningDiv.classList.remove('show');
    }
});

document.getElementById('to_account').addEventListener('input', function() {
    const fromAccount = document.getElementById('from_account').value;
    const toAccount = this.value;
    const warningDiv = document.getElementById('toBalanceWarning');
    const warningSpan = warningDiv.querySelector('span');
    
    if (fromAccount && toAccount && fromAccount === toAccount) {
        warningSpan.innerHTML = 'Cannot be the same account!';
        warningDiv.classList.add('show');
        warningDiv.style.color = 'var(--danger)';
    } else {
        warningDiv.classList.remove('show');
        warningDiv.style.color = 'var(--warning)';
    }
});

document.getElementById('amount').addEventListener('input', function() {
    const amount = parseFloat(this.value) || 0;
    const fromAccount = document.getElementById('from_account').value;
    const warningDiv = document.getElementById('fromBalanceWarning');
    const warningSpan = warningDiv.querySelector('span');
    
    if (accounts[fromAccount] && amount > 0) {
        const balance = accounts[fromAccount].balance;
        const newBalance = balance - amount;
        
        if (newBalance < 0) {
            warningSpan.innerHTML = `⚠️ After transaction: Rs. ${newBalance.toFixed(2)} (Negative allowed)`;
            warningDiv.classList.add('show');
        } else {
            warningDiv.classList.remove('show');
        }
    }
});

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const fromAccount = document.getElementById('from_account').value;
    const toAccount = document.getElementById('to_account').value;
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    
    if (fromAccount === toAccount) {
        e.preventDefault();
        alert('Error: From and To accounts cannot be the same!');
        return false;
    }
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Error: Amount must be greater than 0!');
        return false;
    }
    
    return true;
});
</script>
</body>
</html>