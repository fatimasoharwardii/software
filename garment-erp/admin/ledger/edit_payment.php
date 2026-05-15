<?php
$page_identifier = 'ledger/edit_payment.php';
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
$tables = ['ledger_transactions', 'accounts'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    $_SESSION['error_msg'] = "Invalid transaction ID.";
    header("Location: transaction_history.php");
    exit;
}

// Fetch the transaction details (with company filter)
$query = "SELECT lt.*, a1.id as from_id, a1.account_name as from_name, a1.account_type as from_type,
          a2.id as to_id, a2.account_name as to_name, a2.account_type as to_type
          FROM ledger_transactions lt
          JOIN accounts a1 ON lt.from_account = a1.id
          JOIN accounts a2 ON lt.to_account = a2.id
          WHERE lt.id = ? AND lt.company_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows == 0) {
    $_SESSION['error_msg'] = "Transaction not found.";
    header("Location: transaction_history.php");
    exit;
}
$transaction = $result->fetch_assoc();

// Get list of accounts for dropdowns (only current company)
$accounts_stmt = $conn->prepare("SELECT id, account_name, account_type FROM accounts WHERE company_id = ? ORDER BY account_name");
$accounts_stmt->bind_param("i", $company_id);
$accounts_stmt->execute();
$accounts = $accounts_stmt->get_result();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_account = intval($_POST['from_account']);
    $to_account = intval($_POST['to_account']);
    $amount = floatval($_POST['amount']);
    $date = $_POST['date'];
    $description = trim($_POST['description']);

    // Validate
    if ($from_account == $to_account) {
        $_SESSION['error_msg'] = "From and To accounts cannot be the same.";
        header("Location: edit_transaction.php?id=$id");
        exit;
    }
    if ($amount <= 0) {
        $_SESSION['error_msg'] = "Amount must be greater than zero.";
        header("Location: edit_transaction.php?id=$id");
        exit;
    }

    // Verify both accounts belong to current company
    $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM accounts WHERE id IN (?, ?) AND company_id = ?");
    $check_stmt->bind_param("iii", $from_account, $to_account, $company_id);
    $check_stmt->execute();
    $check = $check_stmt->get_result()->fetch_assoc();
    if ($check['cnt'] != 2) {
        $_SESSION['error_msg'] = "Invalid account selection – one or both accounts do not belong to your company.";
        header("Location: edit_transaction.php?id=$id");
        exit;
    }

    // Begin transaction to revert old and apply new
    $conn->begin_transaction();

    try {
        // 1. Revert old transaction balances (old accounts already verified by initial fetch)
        $old_from = $transaction['from_id'];
        $old_to = $transaction['to_id'];
        $old_amount = $transaction['amount'];

        $revert_from = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND company_id = ?");
        $revert_from->bind_param("dii", $old_amount, $old_from, $company_id);
        if (!$revert_from->execute()) throw new Exception("Failed to revert old from_account");

        $revert_to = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND company_id = ?");
        $revert_to->bind_param("dii", $old_amount, $old_to, $company_id);
        if (!$revert_to->execute()) throw new Exception("Failed to revert old to_account");

        // 2. Apply new transaction balances
        $apply_from = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND company_id = ?");
        $apply_from->bind_param("dii", $amount, $from_account, $company_id);
        if (!$apply_from->execute()) throw new Exception("Failed to apply new from_account");

        $apply_to = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND company_id = ?");
        $apply_to->bind_param("dii", $amount, $to_account, $company_id);
        if (!$apply_to->execute()) throw new Exception("Failed to apply new to_account");

        // 3. Update ledger entry
        $update = $conn->prepare("UPDATE ledger_transactions SET 
                                  from_account = ?, to_account = ?, amount = ?, date = ?, description = ?
                                  WHERE id = ? AND company_id = ?");
        $update->bind_param("iidssii", $from_account, $to_account, $amount, $date, $description, $id, $company_id);
        if (!$update->execute()) throw new Exception("Failed to update transaction");

        $conn->commit();
        $_SESSION['success_msg'] = "Transaction updated successfully.";
        header("Location: transaction_history.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_msg'] = "Error updating transaction: " . $e->getMessage();
        header("Location: edit_transaction.php?id=$id");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Transaction</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #B26000;
        --border: #E5E7E9;
        --text-dark: #2C3E50;
        --bg-light: #F8F9F9;
        --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
        --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, sans-serif;
        color: var(--text-dark);
    }
    .container {
        margin-left: 30%;
        width: 85%;
        max-width: 650px;
        padding: 28px 20px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
        align-self: center;
    }
    .edit-card {
        background: white;
        border-radius: 20px;
        box-shadow: var(--shadow-md);
        overflow: hidden;
        border: 1px solid var(--border);
        transition: transform 0.2s;
    }
    .card-header {
        background: var(--primary);
        padding: 18px 25px;
        color: white;
        border-bottom: none;
    }
    .card-header h2 {
        font-size: 1.4rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .card-header h2 i {
        font-size: 1.4rem;
    }
    .card-body {
        padding: 28px 30px;
    }
    .form-group {
        margin-bottom: 22px;
    }
    label {
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 6px;
        display: block;
        color: var(--text-dark);
    }
    label i {
        color: var(--primary);
        width: 20px;
        margin-right: 6px;
    }
    input, select, textarea {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid var(--border);
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.2s;
        background: white;
    }
    input:focus, select:focus, textarea:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }
    textarea {
        resize: vertical;
        min-height: 80px;
    }
    .btn {
        padding: 10px 22px;
        font-size: 0.85rem;
        font-weight: 600;
        border: none;
        border-radius: 40px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
    }
    .btn-primary {
        background: var(--primary);
        color: white;
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(243,156,18,0.3);
    }
    .btn-secondary {
        background: #e9ecef;
        color: var(--text-dark);
    }
    .btn-secondary:hover {
        background: #dee2e6;
        transform: translateY(-2px);
    }
    .btn-group {
        display: flex;
        gap: 12px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    .alert {
        padding: 12px 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: none;
    }
    .alert-success {
        background: #d4edda;
        color: #155724;
        border-left: 4px solid #27ae60;
    }
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid #e74c3c;
    }
    @media (max-width: 1200px) {
        .container { margin-left: 10%; width: 88%; }
    }
    @media (max-width: 992px) {
        .container { margin-left: 0; width: 100%; padding: 20px; margin-top: 15px; }
    }
    @media (max-width: 576px) {
        .card-body { padding: 20px; }
        .btn-group { flex-direction: column; }
        .btn-group .btn { width: 100%; justify-content: center; }
    }
</style>
</head>
<body>
<?php include("../../includes/navbar.php"); ?>

<div class="container">
    <div class="edit-card">
        <div class="card-header">
            <h2><i class="fas fa-edit"></i> Edit Transaction</h2>
        </div>
        <div class="card-body">
            <?php if(isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_msg']) ?>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>

            <?php if(isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_msg']) ?>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Transaction Date</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($transaction['date']) ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-arrow-right"></i> From Account (Debit)</label>
                    <select name="from_account" required>
                        <option value="">-- Select Account --</option>
                        <?php 
                        $accounts->data_seek(0);
                        while($acc = $accounts->fetch_assoc()): ?>
                            <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $transaction['from_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($acc['account_name']) ?> (<?= ucfirst($acc['account_type']) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-arrow-left"></i> To Account (Credit)</label>
                    <select name="to_account" required>
                        <option value="">-- Select Account --</option>
                        <?php 
                        $accounts->data_seek(0);
                        while($acc = $accounts->fetch_assoc()): ?>
                            <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $transaction['to_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($acc['account_name']) ?> (<?= ucfirst($acc['account_type']) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-rupee-sign"></i> Amount</label>
                    <input type="number" step="0.01" name="amount" value="<?= htmlspecialchars($transaction['amount']) ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Description / Notes</label>
                    <textarea name="description" rows="3" placeholder="Optional description..."><?= htmlspecialchars($transaction['description'] ?? '') ?></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Transaction</button>
                    <a href="transaction_history.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>