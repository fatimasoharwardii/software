<?php
$page_identifier = 'parties/add.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id']; // add user_id for foreign key

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

$message = '';

if (isset($_POST['save'])) {
    $party_name   = trim($_POST['party_name'] ?? '');
    $party_type   = trim($_POST['party_type'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $opening_balance = floatval($_POST['opening_balance'] ?? 0);

    if (empty($party_name) || empty($party_type)) {
        $message = '<div class="alert alert-danger">Party Name and Type are required.</div>';
    } else {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Insert into parties table (include user_id)
            $stmt = $conn->prepare("INSERT INTO parties (party_name, party_type, phone, place, balance, company_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssdii", $party_name, $party_type, $phone, $address, $opening_balance, $company_id, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert party: " . $stmt->error);
            }
            $party_id = $stmt->insert_id;
            $stmt->close();

            // Insert into accounts table (include user_id)
            $acc_stmt = $conn->prepare("INSERT INTO accounts (account_name, account_type, balance, company_id, user_id) VALUES (?, ?, ?, ?, ?)");
            $acc_stmt->bind_param("ssdii", $party_name, $party_type, $opening_balance, $company_id, $user_id);
            if (!$acc_stmt->execute()) {
                throw new Exception("Failed to create account: " . $acc_stmt->error);
            }
            $acc_stmt->close();

            $conn->commit();
            // Clear output buffer and redirect
            ob_end_clean();
            header("Location: list.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

include "../../includes/header.php";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Vendor/Party</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS unchanged – same as original */
        :root {
            --primary: #F39C12;
            --primary-hover: #FFB347;
            --primary-light: #FEF5E7;
            --dark-bg: #1E1E1E;
            --light-bg: #F9F9F9;
            --border: #E0E0E0;
            --text-dark: #2C3E50;
            --text-light: #FFFFFF;
            --success: #28a745;
            --danger: #dc3545;
            --info: #17a2b8;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--light-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-dark);
            min-height: 100vh;
        }

        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-header h4 {
            color: var(--primary);
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h4 i {
            color: var(--primary);
        }

        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .card-header {
            background: white;
            padding: 18px 24px;
            border-bottom: 2px solid var(--primary);
        }

        .card-header h4 {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h4 i {
            color: var(--primary);
        }

        .card-body {
            padding: 30px;
        }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .form-label i {
            color: var(--primary);
            margin-right: 6px;
            width: 18px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%232C3E50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 40px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .btn {
            padding: 12px 28px;
            font-size: 1rem;
            font-weight: 500;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-secondary {
            background: #e9ecef;
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #dee2e6;
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            font-size: 0.95rem;
        }

        .alert-success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }

        .alert-warning {
            background: #fff3cd;
            border-color: #ffeeba;
            color: #856404;
        }

        .alert-danger {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }

        .alert-info {
            background: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
            border-left: 4px solid var(--primary);
        }

        .alert-info i {
            color: var(--primary);
            margin-right: 8px;
        }

        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 5px;
        }

        .type-customer {
            background: #d4edda;
            color: #155724;
        }

        .type-vendor {
            background: #cce5ff;
            color: #004085;
        }

        .input-group {
            display: flex;
            align-items: center;
            border: 2px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        .input-group:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        .input-group-text {
            background: var(--bg-light);
            padding: 12px 16px;
            font-weight: 600;
            color: var(--text-dark);
            border-right: 2px solid var(--border);
            background-color: #f8f9fa;
        }

        .input-group .form-control {
            border: none;
            border-radius: 0;
        }

        .input-group .form-control:focus {
            box-shadow: none;
        }

        @media (max-width: 1200px) {
            .main-container {
                margin-left: 10%;
            }
        }

        @media (max-width: 900px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
            }
            .btn {
                width: 100%;
                justify-content: center;
                margin-bottom: 10px;
            }
            .mt-4 {
                display: flex;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h4><i class="fas fa-user-plus"></i> Add New Party</h4>
    </div>

    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-building"></i> Party Details</h4>
        </div>
        <div class="card-body">
            <?php echo $message; ?>

            <form method="POST" id="partyForm">
                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-user"></i> Party / Vendor Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="party_name" class="form-control" 
                           placeholder="Enter party name" required>
                </div>

                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-tag"></i> Party Type <span class="text-danger">*</span>
                    </label>
                    <select name="party_type" class="form-control" required>
                        <option value="">-- Select Type --</option>
                        <option value="customer">Customer</option>
                        <option value="vendor">Vendor</option>
                        <option value="employee">Employee</option>
                    </select>
                    <div class="mt-2">
                        <span class="type-badge type-customer">Customer</span>
                        <span class="type-badge type-vendor ms-2">Vendor</span>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-coins"></i> Opening Balance <span class="text-muted">(optional)</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">Rs.</span>
                        <input type="number" name="opening_balance" class="form-control" 
                               placeholder="00" step="1" value="">
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> 
                        Positive = Receivable (Customer owes you) | Negative = Payable (You owe to Vendor)
                    </small>
                </div>

                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-phone"></i> Phone Number
                    </label>
                    <input type="text" name="phone" class="form-control" 
                           placeholder="Enter contact number">
                </div>

                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-map-marker-alt"></i> Address
                    </label>
                    <textarea name="address" class="form-control" rows="3" 
                              placeholder="Enter complete address"></textarea>
                </div>

                <div class="mt-4 d-flex gap-3">
                    <button type="submit" name="save" class="btn btn-success">
                        <i class="fas fa-save"></i> Save & Create Account
                    </button>
                    <a href="list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>

                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> When you add a party here, an account will automatically be created in the Ledger system with the opening balance you entered.
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('partyForm').addEventListener('submit', function(e) {
    const balance = parseFloat(document.querySelector('[name="opening_balance"]').value) || 0;
    const partyType = document.querySelector('[name="party_type"]').value;
    
    if (balance < 0) {
        if (!confirm('You are entering a NEGATIVE opening balance (Payable). Are you sure?')) {
            e.preventDefault();
        }
    }
    
    if (balance > 0 && partyType === 'vendor') {
        if (!confirm('Vendor with POSITIVE balance? This means vendor owes you money. Are you sure?')) {
            e.preventDefault();
        }
    }
    
    if (balance < 0 && partyType === 'customer') {
        if (!confirm('Customer with NEGATIVE balance? This means customer owes you money (should be positive). Are you sure?')) {
            e.preventDefault();
        }
    }
});
</script>
</body>
</html>
<?php include "../../includes/footer.php"; ?>