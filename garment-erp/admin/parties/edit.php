<?php
$page_identifier = 'parties/edit.php';
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
$tables = ['parties', 'accounts'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: list.php");
    exit;
}

// Fetch party with company check
$stmt = $conn->prepare("SELECT * FROM parties WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$party = $stmt->get_result()->fetch_assoc();

if (!$party) {
    header("Location: list.php");
    exit;
}

// Get account balance (company filtered)
$acc_stmt = $conn->prepare("SELECT balance FROM accounts WHERE account_name = ? AND company_id = ?");
$acc_stmt->bind_param("si", $party['party_name'], $company_id);
$acc_stmt->execute();
$account = $acc_stmt->get_result()->fetch_assoc();
$current_balance = $account['balance'] ?? 0;

$error = '';

if (isset($_POST['update'])) {
    $party_name = trim($_POST['party_name'] ?? '');
    $party_type = $_POST['party_type'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $opening_balance = floatval($_POST['opening_balance'] ?? 0);

    if (empty($party_name) || empty($party_type)) {
        $error = "Party name and type are required.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Update party in parties table
            $update_party = $conn->prepare("UPDATE parties SET party_name = ?, party_type = ?, phone = ?, place = ? WHERE id = ? AND company_id = ?");
            $update_party->bind_param("ssssii", $party_name, $party_type, $phone, $address, $id, $company_id);
            if (!$update_party->execute()) {
                throw new Exception("Failed to update party: " . $update_party->error);
            }

            // Update account in accounts table (using old party name to locate)
            $old_name = $party['party_name'];
            $update_acc = $conn->prepare("UPDATE accounts SET account_name = ?, account_type = ?, balance = ? WHERE account_name = ? AND company_id = ?");
            $update_acc->bind_param("ssdsi", $party_name, $party_type, $opening_balance, $old_name, $company_id);
            if (!$update_acc->execute()) {
                throw new Exception("Failed to update account: " . $update_acc->error);
            }

            $conn->commit();
            // Refresh party data for display (optional)
            $party['party_name'] = $party_name;
            $party['party_type'] = $party_type;
            $party['phone'] = $phone;
            $party['place'] = $address;
            $current_balance = $opening_balance;
            $success_msg = "Party updated successfully!";
           ?>  <SCRIPT>
                setTimeout(function() {
                    window.location.href = "list.php";
                },);</SCRipt> <?php
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

include "../../includes/header.php";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Party</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* (CSS unchanged – same as original) */
        :root {
            --primary: #F39C12;
            --primary-light: #FEF5E7;
            --primary-dark: #B26000;
            --text-dark: #2C3E50;
            --border: #E5E7E9;
            --bg-light: #F8F9F9;
            --success: #28a745;
            --danger: #dc3545;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: var(--text-dark);
        }

        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
        }

        .page-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h2 {
            font-size: 1.6rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-dark);
        }

        .page-header h2 i {
            color: var(--primary);
        }

        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header {
            background: white;
            padding: 14px 20px;
            border-bottom: 2px solid var(--primary);
        }

        .card-header h4 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-dark);
        }

        .card-header h4 i {
            color: var(--primary);
        }

        .card-body {
            padding: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-label i {
            color: var(--primary);
            width: 16px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        .input-group {
            display: flex;
            align-items: center;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        .input-group:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        .input-group-text {
            background: var(--bg-light);
            padding: 8px 12px;
            font-weight: 600;
            border-right: 1.5px solid var(--border);
        }

        .input-group .form-control {
            border: none;
        }

        .btn {
            padding: 8px 20px;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #e9ecef;
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #dee2e6;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: -8px;
        }

        .col-md-4, .col-md-6, .col-md-12 {
            padding: 8px;
        }

        .col-md-4 { width: 33.333%; }
        .col-md-6 { width: 50%; }
        .col-md-12 { width: 100%; }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background: #f8d7da;
            border-left: 4px solid var(--danger);
            color: #721c24;
        }

        .alert-success {
            background: #d4edda;
            border-left: 4px solid var(--success);
            color: #155724;
        }

        .alert-info {
            background: #d1ecf1;
            border-left: 4px solid var(--primary);
            color: #0c5460;
        }

        .balance-summary {
            background: var(--bg-light);
            border-radius: 8px;
            padding: 12px;
        }

        .balance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px dashed var(--border);
        }

        .balance-item:last-child {
            border-bottom: none;
        }

        .balance-value {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .balance-positive { color: var(--success); }
        .balance-negative { color: var(--danger); }

        .type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .type-customer { background: #d4edda; color: #155724; }
        .type-vendor { background: #cce5ff; color: #004085; }
        .type-fabric_supplier { background: #fff3cd; color: #856404; }
        .type-embroidery_vendor { background: #e2d5f0; color: #5a3f6f; }
        .type-stitching_vendor { background: #d1ecf1; color: #0c5460; }

        .text-muted {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 4px;
            display: block;
        }

        .mt-3 { margin-top: 12px; }
        .mt-4 { margin-top: 20px; }
        .mb-0 { margin-bottom: 0; }
        .me-2 { margin-right: 8px; }
        .ms-auto { margin-left: auto; }
        .d-flex { display: flex; }
        .gap-2 { gap: 8px; }

        @media (max-width: 992px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
            }
            .col-md-4, .col-md-6 {
                width: 100%;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<div class="main-container">
    <div class="page-header">
        <h2>
            <i class="fas fa-edit"></i>
            Edit Party: <?= htmlspecialchars($party['party_name']) ?>
        </h2>
        <div class="d-flex gap-2">
            <a href="add.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Add New
            </a>
            <a href="list.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <?php if(isset($error) && $error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if(isset($success_msg)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?>
        </div>
    <?php endif; ?>

    <!-- Current Balance Summary -->
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-chart-line"></i> Current Balance Summary</h4>
        </div>
        <div class="card-body">
            <div class="balance-summary">
                <div class="balance-item">
                    <span><i class="fas fa-wallet"></i> Current Ledger Balance:</span>
                    <span class="balance-value <?= $current_balance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                        Rs. <?= number_format($current_balance, 2) ?>
                    </span>
                </div>
                <div class="balance-item">
                    <span><i class="fas fa-info-circle"></i> Meaning:</span>
                    <span class="text-muted" style="margin:0;">
                        <?php if($current_balance > 0): ?>
                            <?= $party['party_type'] == 'customer' ? 'Customer owes you money (Receivable)' : 'You owe to vendor (Payable)' ?>
                        <?php elseif($current_balance < 0): ?>
                            <?= $party['party_type'] == 'customer' ? 'Customer overpaid (Credit balance)' : 'Vendor owes you money (Receivable)' ?>
                        <?php else: ?>
                            Zero balance (No pending amount)
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Form Card -->
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-building"></i> Edit Party Details</h4>
        </div>
        <div class="card-body">
            <form method="POST" onsubmit="return validateForm()">
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-user"></i> Party/Vendor Name *</label>
                        <input type="text" name="party_name" value="<?= htmlspecialchars($party['party_name']) ?>" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-tag"></i> Party Type *</label>
                        <select name="party_type" class="form-select" required id="partyType">
                            <option value="customer" <?= $party['party_type']=="customer"?"selected":"" ?>>Customer</option>
                            <option value="vendor" <?= $party['party_type']=="vendor"?"selected":"" ?>>Vendor</option>
                            <option value="fabric_supplier" <?= $party['party_type']=="fabric_supplier"?"selected":"" ?>>Fabric Supplier</option>
                            <option value="embroidery_vendor" <?= $party['party_type']=="embroidery_vendor"?"selected":"" ?>>Embroidery Vendor</option>
                            <option value="stitching_vendor" <?= $party['party_type']=="stitching_vendor"?"selected":"" ?>>Stitching Vendor</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-coins"></i> Opening Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">Rs.</span>
                            <input type="number" name="opening_balance" class="form-control" 
                                   value="<?= $current_balance ?>" step="0.01" id="openingBalance">
                        </div>
                        <small class="text-muted"><i class="fas fa-info-circle"></i> Positive = Receivable | Negative = Payable</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($party['phone']) ?>" class="form-control">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-map-marker-alt"></i> Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($party['place']) ?></textarea>
                    </div>
                </div>

                <div class="mt-3">
                    <span class="type-badge type-<?= $party['party_type'] ?>">
                        <i class="fas fa-check-circle"></i> Current Type: <?= ucwords(str_replace('_', ' ', $party['party_type'])) ?>
                    </span>
                    <?php if($current_balance != 0): ?>
                        <span class="ms-2 badge bg-warning text-dark">
                            <i class="fas fa-exclamation-triangle"></i> Changing balance will affect ledger
                        </span>
                    <?php endif; ?>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" name="update" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Party
                    </button>
                    <a href="delete.php?id=<?= $id ?>" class="btn btn-danger" 
                       onclick="return confirm('Are you sure you want to delete this party? This will also affect ledger accounts.')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                    <a href="list.php" class="btn btn-secondary ms-auto">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Card -->
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-info-circle"></i> Important Information</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle"></i>
                <strong>Note:</strong> Changes will be reflected in both Parties and Ledger Account sections.
                Account name will be updated automatically in the ledger system.
            </div>
        </div>
    </div>
</div>

<script>
function validateForm() {
    const partyType = document.getElementById('partyType').value;
    const balance = parseFloat(document.getElementById('openingBalance').value) || 0;
    
    if (partyType === 'customer' && balance < 0) {
        return confirm('Customer with NEGATIVE balance? This means customer owes you money (should be positive). Are you sure?');
    }
    
    if (partyType === 'vendor' && balance > 0) {
        return confirm('Vendor with POSITIVE balance? This means vendor owes you money (should be negative). Are you sure?');
    }
    
    return true;
}

document.getElementById('partyType').addEventListener('change', function() {
    const partyType = this.value;
    let hint = '';
    if (partyType === 'customer') {
        hint = 'Customer: Positive = Receivable, Negative = Unusual (Customer owes you)';
    } else if (partyType === 'vendor') {
        hint = 'Vendor: Negative = Payable (You owe to vendor), Positive = Unusual';
    } else {
        hint = 'Positive = Asset, Negative = Liability';
    }
    
    const smallText = document.querySelector('.col-md-6 .text-muted');
    if (smallText) {
        smallText.innerHTML = '<i class="fas fa-info-circle"></i> ' + hint;
    }
});
</script>

</body>
</html>
<?php include "../../includes/footer.php"; ?>