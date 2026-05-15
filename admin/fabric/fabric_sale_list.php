<?php
$page_identifier = 'fabric/fabric_sale_list.php';
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
$tables = ['fabric_sale', 'fabric_purchase', 'accounts', 'stitching_posted_bills', 'ledger_transactions'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Handle Delete with prepared statement and company isolation
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $sale_id = (int)$_GET['delete'];
    
    // Get sale details with company check
    $sale_stmt = $conn->prepare("SELECT * FROM fabric_sale WHERE id = ? AND company_id = ?");
    $sale_stmt->bind_param("ii", $sale_id, $company_id);
    $sale_stmt->execute();
    $sale = $sale_stmt->get_result()->fetch_assoc();
    
    if ($sale) {
        mysqli_begin_transaction($conn);
        try {
            // Update fabric_purchase: reverse sold_meter
            $update_purchase = $conn->prepare("UPDATE fabric_purchase SET 
                sold_meter = COALESCE(sold_meter, 0) - ?,
                remaining_meter_sold = total_meter - COALESCE(used_meter, 0) - (COALESCE(sold_meter, 0) - ?)
                WHERE lot_no = ? AND company_id = ?");
            $qty = floatval($sale['quantity']);
            $update_purchase->bind_param("ddsi", $qty, $qty, $sale['lot_no'], $company_id);
            if (!$update_purchase->execute()) {
                throw new Exception("Error updating stock: " . $update_purchase->error);
            }
            
            // Reverse account balance
            $update_account = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE account_name = ? AND company_id = ?");
            $total_amount = floatval($sale['total_amount']);
            $update_account->bind_param("dsi", $total_amount, $sale['party_name'], $company_id);
            if (!$update_account->execute()) {
                throw new Exception("Error updating account balance: " . $update_account->error);
            }
            
            // Delete related ledger transaction
            $delete_ledger = $conn->prepare("DELETE FROM ledger_transactions 
                WHERE description LIKE ? AND from_account = ? AND amount = ? AND company_id = ?");
            $like_desc = "%" . $sale['lot_no'] . "%";
            $delete_ledger->bind_param("ssds", $like_desc, $sale['party_name'], $total_amount, $company_id);
            $delete_ledger->execute();
            
            // Delete from stitching_posted_bills
            $delete_bill = $conn->prepare("DELETE FROM stitching_posted_bills 
                WHERE job_no = ? AND emp_name = ? AND total_amount = ? AND company_id = ?");
            $job_no = "SALE-" . $sale['lot_no'];
            $delete_bill->bind_param("ssds", $job_no, $sale['party_name'], $total_amount, $company_id);
            $delete_bill->execute();
            
            // Delete the sale record
            $delete_sale = $conn->prepare("DELETE FROM fabric_sale WHERE id = ? AND company_id = ?");
            $delete_sale->bind_param("ii", $sale_id, $company_id);
            if (!$delete_sale->execute()) {
                throw new Exception("Error deleting sale: " . $delete_sale->error);
            }
            
            mysqli_commit($conn);
            $success_msg = "Sale record deleted successfully!";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    } else {
        $error = "Sale record not found!";
    }
}

// Get filter values (safe – will be used in prepared statements)
$lot_filter = isset($_GET['lot_no']) ? trim($_GET['lot_no']) : '';
$party_filter = isset($_GET['party']) ? trim($_GET['party']) : '';
$fabric_filter = isset($_GET['fabric']) ? trim($_GET['fabric']) : '';

// Build WHERE clause with company isolation and prepared statements for filters
$where_clauses = ["fs.company_id = ?"];
$params = [$company_id];
$types = "i";

if (!empty($lot_filter)) {
    $where_clauses[] = "fs.lot_no LIKE ?";
    $params[] = "%$lot_filter%";
    $types .= "s";
}
if (!empty($party_filter)) {
    $where_clauses[] = "fs.party_name LIKE ?";
    $params[] = "%$party_filter%";
    $types .= "s";
}
if (!empty($fabric_filter)) {
    $where_clauses[] = "(fs.fabric_name LIKE ? OR fp.fabric_name LIKE ?)";
    $params[] = "%$fabric_filter%";
    $params[] = "%$fabric_filter%";
    $types .= "ss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Main sales query with joins
$sales_sql = "SELECT fs.*, 
    fp.fabric_name as purchase_fabric,
    fp.color as purchase_color,
    fp.total_meter as purchase_total,
    fp.used_meter,
    fp.sold_meter,
    fp.bill_no as purchase_bill_no
    FROM fabric_sale fs 
    LEFT JOIN fabric_purchase fp ON fs.lot_no = fp.lot_no AND fp.company_id = fs.company_id
    $where_sql
    ORDER BY fs.id DESC";
$sales_stmt = $conn->prepare($sales_sql);
$sales_stmt->bind_param($types, ...$params);
$sales_stmt->execute();
$sales_result = $sales_stmt->get_result();

// Totals query (same filters)
$total_sql = "SELECT SUM(fs.quantity) as total_qty, SUM(fs.total_amount) as total_amount 
              FROM fabric_sale fs 
              LEFT JOIN fabric_purchase fp ON fs.lot_no = fp.lot_no AND fp.company_id = fs.company_id
              $where_sql";
$total_stmt = $conn->prepare($total_sql);
$total_stmt->bind_param($types, ...$params);
$total_stmt->execute();
$totals = $total_stmt->get_result()->fetch_assoc();
if (!$totals) {
    $totals = ['total_qty' => 0, 'total_amount' => 0];
}

// Distinct values for filter datalists (with company isolation)
$lots_stmt = $conn->prepare("SELECT DISTINCT lot_no FROM fabric_sale WHERE company_id = ? ORDER BY lot_no DESC");
$lots_stmt->bind_param("i", $company_id);
$lots_stmt->execute();
$lots_query = $lots_stmt->get_result();

$parties_stmt = $conn->prepare("SELECT DISTINCT party_name FROM fabric_sale WHERE company_id = ? ORDER BY party_name");
$parties_stmt->bind_param("i", $company_id);
$parties_stmt->execute();
$parties_query = $parties_stmt->get_result();

$fabrics_stmt = $conn->prepare("SELECT DISTINCT fabric_name FROM fabric_sale WHERE fabric_name IS NOT NULL AND fabric_name != '' AND company_id = ?
    UNION 
    SELECT DISTINCT fabric_name FROM fabric_purchase WHERE fabric_name IS NOT NULL AND fabric_name != '' AND company_id = ?
    ORDER BY fabric_name");
$fabrics_stmt->bind_param("ii", $company_id, $company_id);
$fabrics_stmt->execute();
$fabrics_query = $fabrics_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Fabric Sale List</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #B26000;
        --border: #E5E7E9;
        --bg-light: #F8F9FA;
        --text-dark: #2C3E50;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
        --info: #3498db;
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
        line-height: 1.5;
    }

    .main-container {
        margin-left: 14%;
        padding: 20px 24px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .page-header {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 24px;
        border-left: 5px solid var(--primary);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }

    .page-header h2 {
        font-size: 1.5rem;
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

    .btn-add {
        background: var(--success);
        color: white;
        border: none;
        border-radius: 8px;
        padding: 8px 18px;
        font-size: 0.9rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-add:hover {
        background: #219a52;
        transform: translateY(-1px);
        color: white;
    }

    .filter-section {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        border: 1px solid var(--border);
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }

    .filter-title {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .filter-title i {
        color: var(--primary);
    }

    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: flex-end;
    }

    .filter-group {
        flex: 1;
        min-width: 160px;
    }

    .filter-group label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #666;
        margin-bottom: 5px;
    }

    .filter-group label i {
        color: var(--primary);
        margin-right: 4px;
    }

    .filter-group input, .filter-group select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.85rem;
        transition: all 0.2s;
    }

    .filter-group input:focus, .filter-group select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .filter-buttons {
        display: flex;
        gap: 10px;
    }

    .btn-filter, .btn-reset {
        border: none;
        border-radius: 8px;
        padding: 8px 20px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-filter {
        background: var(--primary);
        color: white;
    }

    .btn-filter:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .btn-reset {
        background: #e9ecef;
        color: var(--text-dark);
    }

    .btn-reset:hover {
        background: #dee2e6;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stats-card {
        background: white;
        border-radius: 12px;
        padding: 16px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        border-left: 4px solid var(--primary);
    }

    .stats-card h6 {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #666;
        margin-bottom: 5px;
        letter-spacing: 0.5px;
    }

    .stats-card .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary);
        word-break: break-word;
    }

    .content-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .card-header {
        background: white;
        padding: 14px 20px;
        border-bottom: 2px solid var(--primary);
    }

    .card-header h5 {
        font-size: 1rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text-dark);
    }

    .card-header h5 i {
        color: var(--primary);
    }

    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
        min-width: 1000px;
    }

    th {
        background: #f8f9fa;
        padding: 10px 8px;
        text-align: center;
        border-bottom: 2px solid var(--primary);
        font-weight: 600;
        white-space: nowrap;
    }

    td {
        padding: 8px;
        border-bottom: 1px solid var(--border);
        text-align: center;
        vertical-align: middle;
    }

    tr:hover {
        background: var(--primary-light);
    }

    .amount {
        font-weight: 700;
        color: var(--success);
    }

    .badge-count {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        background: var(--primary-light);
        color: var(--primary-dark);
    }

    .empty-state {
        text-align: center;
        padding: 50px;
        color: #999;
    }

    .error-message {
        background: #f8d7da;
        border-left: 4px solid var(--danger);
        padding: 12px 16px;
        margin-bottom: 20px;
        border-radius: 8px;
        color: #721c24;
        font-size: 0.9rem;
    }

    .success-message {
        background: #d4edda;
        border-left: 4px solid var(--success);
        padding: 12px 16px;
        margin-bottom: 20px;
        border-radius: 8px;
        color: #155724;
        font-size: 0.9rem;
    }

    .action-buttons {
        display: flex;
        gap: 6px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn-edit, .btn-delete {
        border: none;
        border-radius: 6px;
        padding: 4px 10px;
        font-size: 0.7rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-edit {
        background: var(--info);
        color: white;
    }

    .btn-edit:hover {
        background: #2980b9;
        color: white;
        transform: translateY(-1px);
    }

    .btn-delete {
        background: var(--danger);
        color: white;
    }

    .btn-delete:hover {
        background: #c0392b;
        transform: translateY(-1px);
    }

    /* Responsive Overrides */
    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
            padding: 16px 20px;
        }
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 12px 16px;
            margin-top: 56px;
        }
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .filter-row {
            flex-direction: column;
        }
        .filter-group {
            width: 100%;
        }
        .filter-buttons {
            width: 100%;
            flex-direction: column;
            gap: 8px;
        }
        .btn-filter, .btn-reset {
            justify-content: center;
            width: 100%;
        }
        .action-buttons {
            flex-direction: column;
            gap: 5px;
        }
        .btn-edit, .btn-delete {
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .main-container {
            padding: 10px 12px;
        }
        .page-header h2 {
            font-size: 1.2rem;
        }
        .stat-number {
            font-size: 1.2rem;
        }
        table {
            font-size: 0.7rem;
            min-width: 800px;
        }
        th, td {
            padding: 6px 4px;
        }
    }

    /* Touch-friendly */
    input, select, button, a {
        touch-action: manipulation;
    }
</style>
</head>
<body>
<?php 
$navbar_path = "../../includes/navbar.php";
if(file_exists($navbar_path)) {
    include $navbar_path;
}
?>

<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-shopping-cart"></i> Fabric Sale List</h2>
        <a href="fabric_sale.php" class="btn-add"><i class="fas fa-plus"></i> New Sale</a>
    </div>

    <?php if(isset($error)): ?>
        <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if(isset($success_msg)): ?>
        <div class="success-message"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-title"><i class="fas fa-filter"></i> Filter Sales</div>
        <form method="GET" action="fabric_sale_list.php">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-barcode"></i> Lot No</label>
                    <input type="text" name="lot_no" list="lotList" placeholder="Search by Lot No..." value="<?= htmlspecialchars($lot_filter) ?>" autocomplete="off">
                    <datalist id="lotList">
                        <?php while($lot = $lots_query->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($lot['lot_no']) ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-user"></i> Party/Customer</label>
                    <input type="text" name="party" list="partyList" placeholder="Search by Customer..." value="<?= htmlspecialchars($party_filter) ?>" autocomplete="off">
                    <datalist id="partyList">
                        <?php while($party = $parties_query->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($party['party_name']) ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-tshirt"></i> Fabric</label>
                    <input type="text" name="fabric" list="fabricList" placeholder="Search by Fabric..." value="<?= htmlspecialchars($fabric_filter) ?>" autocomplete="off">
                    <datalist id="fabricList">
                        <?php while($fabric = $fabrics_query->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($fabric['fabric_name']) ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply</button>
                    <a href="fabric_sale_list.php" class="btn-reset"><i class="fas fa-times"></i> Clear</a>
                </div>
            </div>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stats-card">
            <h6><i class="fas fa-ruler"></i> Total Quantity Sold</h6>
            <div class="stat-number"><?= number_format($totals['total_qty'] ?? 0, 2) ?> m</div>
        </div>
        <div class="stats-card">
            <h6><i class="fas fa-rupee-sign"></i> Total Amount</h6>
            <div class="stat-number">Rs. <?= number_format(round($totals['total_amount'] ?? 0), 0) ?></div>
        </div>
        <div class="stats-card">
            <h6><i class="fas fa-chart-line"></i> Total Records</h6>
            <div class="stat-number"><?= $sales_result->num_rows ?></div>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> All Sales Records</h5>
        </div>
        <div class="card-body p-0">
            <?php if($sales_result && $sales_result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Bill No</th>
                                <th>Customer</th>
                                <th>Lot No</th>
                                <th>Fabric</th>
                                <th>Colors</th>
                                <th>Quantity (m)</th>
                                <th>Total Quantity</th>
                                <th>Rate</th>
                                <th>Total Amount</th> 
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $sales_result->fetch_assoc()): 
                                $color_count = max(1, intval($row['color_count'] ?? 1));
                                $total_quantity = floatval($row['quantity']);
                            ?>
                            <tr>
                                <td><strong>#<?= $row['id'] ?></strong></td>
                                <td><?= date('d-m-Y', strtotime($row['sale_date'])) ?></td>
                                <td><?= htmlspecialchars($row['bill_no'] ?? '-') ?></td>
                                <td><strong><?= htmlspecialchars($row['party_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['lot_no']) ?></td>
                                <td><?= htmlspecialchars($row['fabric_name'] ?: $row['purchase_fabric']) ?></td>
                                <td><span class="badge-count"><?= $color_count ?></span></td>
                                <td><?= number_format($total_quantity, 2) ?> m</td>
                                <td class="quantity"><strong><?= number_format($total_quantity * $color_count, 2) ?> m</strong></td>
                                <td>Rs. <?= number_format(round($row['rate']), 0) ?> /m</td>
                                <td class="amount">Rs. <?= number_format(round($row['total_amount']), 0) ?></td>
                                <td class="action-buttons">
                                    <a href="fabric_sale_edit.php?id=<?= $row['id'] ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button onclick="deleteSale(<?= $row['id'] ?>, '<?= htmlspecialchars($row['lot_no']) ?>', '<?= htmlspecialchars($row['party_name']) ?>')" class="btn-delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x mb-3"></i>
                    <p>No sales records found</p>
                    <a href="fabric_sale.php" class="btn-add" style="display: inline-block; margin-top: 15px;">Create First Sale</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function deleteSale(id, lotNo, partyName) {
    Swal.fire({
        title: 'Are you sure?',
        text: `Delete sale #${id} for lot ${lotNo} to ${partyName}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `fabric_sale_list.php?delete=${id}`;
        }
    });
}
</script>

</body>
</html>