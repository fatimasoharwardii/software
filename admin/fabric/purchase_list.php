<?php
$page_identifier = 'fabric/purchase_list.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure required columns and company_id exist
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS company_id INT DEFAULT NULL");
$conn->query("UPDATE fabric_purchase SET company_id = 1 WHERE company_id IS NULL");
$conn->query("ALTER TABLE fabric_purchase MODIFY company_id INT NOT NULL");

$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS used_meter DECIMAL(12,2) DEFAULT 0");
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS sold_meter DECIMAL(12,2) DEFAULT 0");
$conn->query("ALTER TABLE fabric_purchase ADD COLUMN IF NOT EXISTS remaining_meter DECIMAL(12,2) DEFAULT 0");

// Update remaining meter calculation for current company
$update_remaining = $conn->prepare("UPDATE fabric_purchase 
    SET remaining_meter = total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)
    WHERE company_id = ?");
$update_remaining->bind_param("i", $company_id);
$update_remaining->execute();

// Main query – fetch all purchases for current company
$query = "SELECT 
    id,
    lot_no, 
    party_name, 
    fabric_name, 
    color, 
    total_meter, 
    rate, 
    adjust_rate,
    COALESCE(used_meter, 0) as issued_meter,
    COALESCE(sold_meter, 0) as sold_meter,
    created_at,
    (total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)) as remaining_calc 
FROM fabric_purchase 
WHERE company_id = ?
ORDER BY id DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

// Aggregated totals (company specific)
$total_purchased_stmt = $conn->prepare("SELECT SUM(total_meter) as total FROM fabric_purchase WHERE company_id = ?");
$total_purchased_stmt->bind_param("i", $company_id);
$total_purchased_stmt->execute();
$total_purchased_data = $total_purchased_stmt->get_result()->fetch_assoc();
$total_purchased = $total_purchased_data['total'] ?? 0;

$total_issued_stmt = $conn->prepare("SELECT SUM(used_meter) as total FROM fabric_purchase WHERE company_id = ?");
$total_issued_stmt->bind_param("i", $company_id);
$total_issued_stmt->execute();
$total_issued_data = $total_issued_stmt->get_result()->fetch_assoc();
$total_issued = $total_issued_data['total'] ?? 0;

$total_sold_stmt = $conn->prepare("SELECT SUM(sold_meter) as total FROM fabric_purchase WHERE company_id = ?");
$total_sold_stmt->bind_param("i", $company_id);
$total_sold_stmt->execute();
$total_sold_data = $total_sold_stmt->get_result()->fetch_assoc();
$total_sold = $total_sold_data['total'] ?? 0;

$total_remaining_stmt = $conn->prepare("SELECT SUM(total_meter - COALESCE(used_meter, 0) - COALESCE(sold_meter, 0)) as total_remaining FROM fabric_purchase WHERE company_id = ?");
$total_remaining_stmt->bind_param("i", $company_id);
$total_remaining_stmt->execute();
$total_remaining_data = $total_remaining_stmt->get_result()->fetch_assoc();
$total_remaining = $total_remaining_data['total_remaining'] ?? 0;

$total_lots_stmt = $conn->prepare("SELECT COUNT(DISTINCT lot_no) as total FROM fabric_purchase WHERE company_id = ?");
$total_lots_stmt->bind_param("i", $company_id);
$total_lots_stmt->execute();
$total_lots = $total_lots_stmt->get_result()->fetch_assoc()['total'] ?? 0;

$total_parties_stmt = $conn->prepare("SELECT COUNT(DISTINCT party_name) as total FROM fabric_purchase WHERE company_id = ?");
$total_parties_stmt->bind_param("i", $company_id);
$total_parties_stmt->execute();
$total_parties = $total_parties_stmt->get_result()->fetch_assoc()['total'] ?? 0;

$total_records = $result->num_rows;
?>
<!DOCTYPE html>
<html>
<head>
<title>Fabric Purchase List</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    /* (CSS unchanged – same as original for responsiveness) */
    :root {
        --primary: #F39C12;
        --primary-dark: #E67E22;
        --primary-light: #FEF5E7;
        --success: #27ae60;
        --success-light: #e8f5e9;
        --danger: #e74c3c;
        --warning: #f39c12;
        --info: #3498db;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-light: #6c757d;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #F0F2F5;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .main-container {
        margin-left: 14%;
        padding: 24px 32px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .stock-card {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        border-radius: 16px;
        padding: 16px 24px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .stock-label {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .stock-label i {
        font-size: 1.5rem;
        color: rgba(255,255,255,0.9);
    }

    .stock-label span {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 600;
        color: rgba(255,255,255,0.85);
    }

    .stock-value {
        font-size: 1.8rem;
        font-weight: 800;
        color: white;
    }

    .stock-unit {
        font-size: 0.8rem;
        margin-left: 5px;
    }

    .stats-row {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: white;
        border-radius: 14px;
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        border: 1px solid var(--border);
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border-color: var(--primary);
    }

    .stat-icon {
        width: 42px;
        height: 42px;
        background: var(--primary-light);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .stat-icon i {
        font-size: 1.2rem;
        color: var(--primary);
    }

    .stat-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-dark);
        line-height: 1.2;
    }

    .stat-label {
        font-size: 0.65rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    .main-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--border);
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        background: white;
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

    .btn-add {
        background: var(--primary);
        color: white;
        padding: 8px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 0.8rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }

    .btn-add:hover {
        background: var(--primary-dark);
        color: white;
        transform: translateY(-1px);
    }

    .table-wrapper {
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .table th {
        background: #F8F9FA;
        color: var(--text-dark);
        padding: 12px 14px;
        font-weight: 600;
        text-align: left;
        border-bottom: 2px solid var(--primary);
        white-space: nowrap;
    }

    .table td {
        padding: 10px 14px;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .table tbody tr {
        transition: all 0.2s;
    }

    .table tbody tr:hover {
        background: var(--primary-light);
    }

    .lot-number {
        font-weight: 700;
        color: var(--primary);
        background: var(--primary-light);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        display: inline-block;
    }

    .remaining-badge {
        background: var(--success-light);
        color: var(--success);
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        display: inline-block;
    }

    .remaining-low {
        background: #FFF3E0;
        color: var(--warning);
    }

    .remaining-zero {
        background: #FEF2F0;
        color: var(--danger);
    }

    .issued-badge {
        background: #E3F2FD;
        color: var(--info);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        display: inline-block;
    }

    .sold-badge {
        background: #FCE4EC;
        color: #e91e63;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        display: inline-block;
    }

    .btn-sm {
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 0.7rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin: 0 2px;
        transition: all 0.2s;
    }

    .btn-view {
        background: var(--info);
        color: white;
    }

    .btn-view:hover {
        background: #217dbb;
        color: white;
    }

    .btn-edit {
        background: var(--primary);
        color: white;
    }

    .btn-edit:hover {
        background: var(--primary-dark);
        color: white;
    }

    .btn-delete {
        background: #FEF2F0;
        color: var(--danger);
    }

    .btn-delete:hover {
        background: var(--danger);
        color: white;
    }

    .summary-footer {
        padding: 12px 20px;
        background: #F8F9FA;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        font-size: 0.75rem;
    }

    .summary-info {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .summary-item {
        display: flex;
        align-items: baseline;
        gap: 6px;
    }

    .summary-label {
        color: var(--text-light);
    }

    .summary-value {
        font-weight: 700;
        color: var(--text-dark);
    }

    .summary-value.highlight {
        color: var(--success);
    }

    .empty-state {
        text-align: center;
        padding: 50px;
        color: var(--text-light);
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 15px;
        opacity: 0.5;
    }

    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
            padding: 20px;
        }
        .stats-row {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 16px;
            margin-top: 60px;
        }
    }

    @media (max-width: 768px) {
        .stats-row {
            grid-template-columns: repeat(2, 1fr);
        }
        .stock-card {
            flex-direction: column;
            text-align: center;
        }
        .summary-footer {
            flex-direction: column;
            text-align: center;
        }
        .summary-info {
            justify-content: center;
        }
        .card-header {
            flex-direction: column;
            text-align: center;
        }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <!-- Stock Card -->
    <div class="stock-card">
        <div class="stock-label">
            <i class="fas fa-warehouse"></i>
            <span>AVAILABLE STOCK</span>
        </div>
        <div class="stock-value">
            <?php echo number_format($total_remaining, 2); ?>
            <span class="stock-unit">Meters</span>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="stat-value"><?php echo $total_records; ?></div>
                <div class="stat-label">Purchases</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-tags"></i></div>
            <div>
                <div class="stat-value"><?php echo $total_lots; ?></div>
                <div class="stat-label">Lots</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-building"></i></div>
            <div>
                <div class="stat-value"><?php echo $total_parties; ?></div>
                <div class="stat-label">Suppliers</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div>
                <div class="stat-value"><?php echo number_format($total_purchased, 0); ?>m</div>
                <div class="stat-label">Total Purchased</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-calculator"></i></div>
            <div>
                <div class="stat-value"><?php echo number_format($total_remaining, 0); ?>m</div>
                <div class="stat-label">Available</div>
            </div>
        </div>
    </div>

    <!-- Main Table -->
    <div class="main-card">
        <div class="card-header">
            <h4><i class="fas fa-list-ul"></i> Fabric Purchase Records</h4>
            <a href="purchase_add.php" class="btn-add"><i class="fas fa-plus-circle"></i> Add Purchase</a>
        </div>

        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Lot No</th>
                        <th>Party</th>
                        <th>Fabric</th>
                        <th>Color</th>
                        <th>Purchased</th>
                        <th>Issued</th>
                        <th>Sold</th>
                        <th>Remaining</th>
                        <th>Rate</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($total_records > 0): ?>
                        <?php while($row = $result->fetch_assoc()): 
                            $purchased = floatval($row['total_meter']);
                            $issued = floatval($row['issued_meter']);
                            $sold = floatval($row['sold_meter']);
                            $remaining = $purchased - $issued - $sold;
                            
                            $remaining_class = 'remaining-badge';
                            if($remaining <= 0) $remaining_class .= ' remaining-zero';
                            elseif($remaining < 100) $remaining_class .= ' remaining-low';
                        ?>
                        <tr>
                            <td><span class="lot-number"><?php echo htmlspecialchars($row['lot_no']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['party_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['fabric_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['color'] ?? '-'); ?></td>
                            <td><strong><?php echo number_format($purchased, 0); ?></strong> m</td>
                            <td><span class="issued-badge"><?php echo number_format($issued, 0); ?> m</span></td>
                            <td><span class="sold-badge"><?php echo number_format($sold, 0); ?> m</span></td>
                            <td><span class="<?php echo $remaining_class; ?>"><?php echo number_format($remaining, 0); ?> m</span></td>
                            <td>Rs <?php echo number_format($row['rate'], 0); ?></td>
                            <td><?php echo date('d-m-Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <a href="purchase_view.php?id=<?php echo $row['id']; ?>" class="btn-sm btn-view" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="purchase_edit.php?id=<?php echo $row['id']; ?>" class="btn-sm btn-edit" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="purchase_delete.php?id=<?php echo $row['id']; ?>" class="btn-sm btn-delete" title="Delete" onclick="return confirm('Delete this record?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <p>No records found</p>
                                <a href="purchase_add.php" class="btn-add" style="display: inline-flex;">Add First Purchase</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_records > 0): ?>
        <div class="summary-footer">
            <div class="summary-info">
                <div class="summary-item"><span class="summary-label">Records:</span><span class="summary-value"><?php echo $total_records; ?></span></div>
                <div class="summary-item"><span class="summary-label">Purchased:</span><span class="summary-value"><?php echo number_format($total_purchased, 0); ?>m</span></div>
                <div class="summary-item"><span class="summary-label">Issued:</span><span class="summary-value"><?php echo number_format($total_issued, 0); ?>m</span></div>
                <div class="summary-item"><span class="summary-label">Sold:</span><span class="summary-value"><?php echo number_format($total_sold, 0); ?>m</span></div>
                <div class="summary-item"><span class="summary-label">Available:</span><span class="summary-value highlight"><?php echo number_format($total_remaining, 0); ?>m</span></div>
            </div>
            <div class="summary-item"><i class="fas fa-clock"></i><span class="summary-label"><?php echo date('d-m-Y H:i'); ?></span></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>