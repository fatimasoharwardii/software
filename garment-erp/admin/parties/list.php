<?php
$page_identifier = 'parties/list.php';
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

// Fetch all parties with their account balances (company filtered)
$query = "SELECT p.*, a.id as account_id, a.balance 
          FROM parties p
          LEFT JOIN accounts a ON p.party_name = a.account_name AND p.company_id = a.company_id
          WHERE p.company_id = ?
          ORDER BY p.id DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $company_id);
$stmt->execute();
$parties = $stmt->get_result();

include "../../includes/header.php";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Party List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* (CSS unchanged – same as original) */
        :root {
            --primary: #F39C12;
            --primary-hover: #FFB347;
            --dark-bg: #1E1E1E;
            --light-bg: #F9F9F9;
            --border: #E0E0E0;
            --text-dark: #2C3E50;
            --text-light: #FFFFFF;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

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
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .btn-add {
            background: var(--success);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
        }

        .btn-add:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            color: white;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h5 {
            color: var(--text-dark);
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .table th {
            background: #f8f9fa;
            color: var(--text-dark);
            font-weight: 600;
            padding: 14px 12px;
            border-bottom: 2px solid var(--primary);
            text-align: left;
            white-space: nowrap;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .type-customer { background: #d4edda; color: #155724; }
        .type-vendor { background: #cce5ff; color: #004085; }
        .type-fabric_supplier { background: #fff3cd; color: #856404; }
        .type-embroidery_vendor { background: #e2d5f0; color: #5a3f6f; }
        .type-stitching_vendor { background: #d1ecf1; color: #0c5460; }
        .type-default { background: #e9ecef; color: #495057; }

        .balance-credit {
            color: var(--success);
            font-weight: 600;
        }

        .balance-debit {
            color: var(--danger);
            font-weight: 600;
        }

        .btn {
            padding: 6px 12px;
            font-size: 0.85rem;
            font-weight: 500;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.8rem;
        }

        .action-group {
            display: flex;
            gap: 5px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7b8b;
        }

        .search-bar {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }

        .search-bar input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .search-bar input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        .search-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            cursor: pointer;
        }

        @media (max-width: 1200px) {
            .main-container { margin-left: 10%; }
        }

        @media (max-width: 900px) {
            .main-container { margin-left: 0; padding: 16px; }
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start; }
            .table { font-size: 0.85rem; }
            .table th, .table td { padding: 8px; }
            .action-group { flex-direction: column; }
        }

        @media (max-width: 768px) {
            .table-responsive { overflow-x: auto; }
            .table { min-width: 700px; }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h4><i class="fas fa-users"></i> Party List</h4>
        <a href="add.php" class="btn-add">
            <i class="fas fa-plus-circle"></i> Add New Party
        </a>
    </div>

    <!-- Search Bar -->
    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="Search by name, type or phone..." onkeyup="searchTable()">
        <button class="search-btn" onclick="searchTable()">
            <i class="fas fa-search"></i> Search
        </button>
    </div>

    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> All Parties</h5>
            <span class="badge bg-primary">Total: <?php echo $parties->num_rows; ?></span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table" id="partyTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Party Name</th>
                            <th>Type</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Current Balance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($parties->num_rows > 0) {
                            $count = 1;
                            while ($row = $parties->fetch_assoc()) { 
                                $type_class = 'type-default';
                                if ($row['party_type'] == 'customer') $type_class = 'type-customer';
                                else if ($row['party_type'] == 'vendor') $type_class = 'type-vendor';
                                else if ($row['party_type'] == 'fabric_supplier') $type_class = 'type-fabric_supplier';
                                else if ($row['party_type'] == 'embroidery_vendor') $type_class = 'type-embroidery_vendor';
                                else if ($row['party_type'] == 'stitching_vendor') $type_class = 'type-stitching_vendor';
                                
                                $balance = floatval($row['balance'] ?? 0);
                                $balance_display = number_format(abs($balance), 2);
                                $balance_class = ($balance >= 0) ? 'balance-credit' : 'balance-debit';
                                $balance_sign = ($balance >= 0) ? '(Receivable)' : '(Payable)';
                                $account_id = $row['account_id'] ?? null;
                        ?>
                        <tr>
                            <td><strong><?php echo $count++; ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($row['party_name']); ?></strong></td>
                            <td>
                                <span class="type-badge <?php echo $type_class; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $row['party_type'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if(!empty($row['phone'])): ?>
                                    <i class="fas fa-phone-alt me-1" style="color: var(--primary);"></i>
                                    <?php echo htmlspecialchars($row['phone']); ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($row['place'])): ?>
                                    <i class="fas fa-map-marker-alt me-1" style="color: var(--primary);"></i>
                                    <?php echo htmlspecialchars(substr($row['place'], 0, 30)) . (strlen($row['place'] ?? '') > 30 ? '...' : ''); ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($account_id): ?>
                                    <span class="<?php echo $balance_class; ?>">
                                        Rs. <?php echo $balance_display; ?> <?php echo $balance_sign; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">No account</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if($account_id): ?>
                                    <a href="../ledger/ledger_view.php?id=<?php echo $account_id; ?>" class="btn btn-info btn-sm" title="View Ledger">
                                        <i class="fas fa-book"></i> Ledger View
                                    </a>
                                    <?php else: ?>
                                    <button class="btn btn-info btn-sm" disabled title="No account found">
                                        <i class="fas fa-book"></i> No Ledger
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else { 
                        ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <p>No parties found</p>
                                <a href="add.php" class="btn-add">
                                    <i class="fas fa-plus-circle"></i> Add Your First Party
                                </a>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function searchTable() {
    let input = document.getElementById('searchInput');
    let filter = input.value.toUpperCase();
    let table = document.getElementById('partyTable');
    let tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let tdArray = tr[i].getElementsByTagName('td');
        let found = false;
        
        for (let j = 1; j < tdArray.length - 1; j++) {
            if (tdArray[j]) {
                let txtValue = tdArray[j].textContent || tdArray[j].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        
        if (found) {
            tr[i].style.display = '';
        } else {
            tr[i].style.display = 'none';
        }
    }
}
</script>

<?php include "../../includes/footer.php"; ?>