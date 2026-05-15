<?php
$page_identifier = 'fabric/purchase_view.php';
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM fabric_purchase WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();
    $query = $stmt->get_result();
    $row = $query->fetch_assoc();
    
    if (!$row) {
        header("Location: purchase_list.php");
        exit();
    }
    
    $purchased = $row['total_meter'];
    $used = $row['used_meter'] ?? 0;
    $remaining = $purchased - $used;
    $total_amount = $purchased * $row['rate'];
} else {
    header("Location: purchase_list.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>View Fabric Purchase</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FEF5E7;
        --primary-dark: #E67E22;
        --success: #27ae60;
        --success-light: #e8f5e9;
        --danger: #e74c3c;
        --warning: #f39c12;
        --border: #E9ECEF;
        --text-dark: #2C3E50;
        --text-muted: #6c757d;
        --bg-light: #F8F9FA;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
        font-family: 'Segoe UI', system-ui, sans-serif;
    }

    .main-container {
        margin-left: 14%;
        padding: 20px 24px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .card {
        background: white;
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .card-header {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .header-title {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .header-title i {
        font-size: 1.2rem;
        background: rgba(255,255,255,0.2);
        padding: 8px;
        border-radius: 10px;
    }

    .header-title h4 {
        color: white;
        margin: 0;
        font-size: 1.4rem;
        font-weight: 600;
    }

    .header-title p {
        margin: 2px 0 0;
        font-size: 1rem;
        opacity: 0.85;
    }

    .lot-badge {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
        color: white;
    }

    .card-body {
        padding: 16px;
    }

    .stats-row {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .stat-card {
        background: var(--bg-light);
        border-radius: 10px;
        padding: 8px 12px;
        text-align: center;
        border: 1px solid var(--border);
        flex: 1;
        min-width: 100px;
    }

    .stat-icon {
        font-size: 1.5rem;
        color: var(--primary);
        margin-bottom: 4px;
    }

    .stat-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.3px;
        margin-bottom: 3px;
    }

    .stat-value {
        font-size: 1.1rem;
        font-weight: 900;
        color: var(--text-dark);
    }

    .section {
        margin-bottom: 16px;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding-bottom: 4px;
        border-bottom: 2px solid var(--primary);
    }

    .section-title i {
        color: var(--primary);
        font-size: 1rem;
    }

    .info-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .info-card {
        background: var(--bg-light);
        border-radius: 10px;
        padding: 8px 12px;
        border: 1px solid var(--border);
        flex: 1;
        min-width: 150px;
    }

    .info-label {
        font-size: 0.9rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.3px;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .info-label i {
        color: var(--primary);
        font-size: 0.9rem;
    }

    .info-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-dark);
    }

    .summary-cards {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .summary-card {
        background: var(--bg-light);
        border-radius: 10px;
        padding: 10px 14px;
        border: 1px solid var(--border);
        flex: 1;
    }

    .summary-card.amount-card {
        background: linear-gradient(135deg, var(--primary-light) 0%, #fff 100%);
        border-color: var(--primary);
    }

    .summary-card.stock-card {
        background: linear-gradient(135deg, #e8f5e9 0%, #fff 100%);
        border-color: var(--success);
    }

    .summary-header {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .summary-header i {
        font-size: 0.8rem;
    }

    .summary-detail {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 5px 0;
        border-bottom: 1px dashed var(--border);
    }

    .summary-detail:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .summary-detail-label {
        font-size: 0.9rem;
        color: var(--text-muted);
    }

    .summary-detail-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-dark);
    }

    .summary-detail-value.total {
        font-size: 1.4rem;
        color: var(--primary);
        font-weight: 700;
    }

    .stock-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .stock-available {
        background: var(--success-light);
        color: var(--success);
    }

    .stock-low {
        background: #FFF3E0;
        color: var(--warning);
    }

    .stock-out {
        background: #FEF2F0;
        color: var(--danger);
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 16px;
        padding-top: 12px;
        border-top: 1px solid var(--border);
    }

    .btn-action {
        padding: 6px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }

    .btn-back {
        background: #6c757d;
        color: white;
    }

    .btn-back:hover {
        background: #5a6268;
        transform: translateY(-1px);
        color: white;
    }

    .btn-edit {
        background: var(--primary);
        color: white;
    }

    .btn-edit:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        color: white;
    }

    @media (max-width: 1200px) {
        .main-container {
            margin-left: 10%;
            padding: 16px 20px;
        }
    }

    @media (max-width: 992px) {
        .main-container {
            margin-left: 0;
            padding: 16px;
            margin-top: 60px;
        }
        .stats-row {
            flex-wrap: wrap;
        }
        .stat-card {
            min-width: calc(50% - 6px);
        }
        .info-card {
            min-width: calc(50% - 5px);
        }
        .summary-cards {
            flex-direction: column;
        }
        .action-buttons {
            justify-content: center;
        }
    }

    @media (max-width: 576px) {
        .stat-card {
            min-width: 100%;
        }
        .info-card {
            min-width: 100%;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn-action {
            justify-content: center;
        }
        .card-header {
            flex-direction: column;
            text-align: center;
        }
        .header-title {
            flex-direction: column;
            text-align: center;
        }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="card">
        <div class="card-header">
            <div class="header-title">
                <i class="fas fa-file-alt"></i>
                <div>
                    <h4>Fabric Purchase Details</h4>
                    <p>Complete purchase information</p>
                </div>
            </div>
            <div class="lot-badge">
                <i class="fas fa-layer-group"></i> Lot #<?php echo htmlspecialchars($row['lot_no']); ?>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Stats Row -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-ruler"></i></div>
                    <div class="stat-label">Total Meter</div>
                    <div class="stat-value"><?php echo number_format($purchased, 0); ?> m</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-label">Used Meter</div>
                    <div class="stat-value"><?php echo number_format($used, 0); ?> m</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
                    <div class="stat-label">Remaining</div>
                    <div class="stat-value"><?php echo number_format($remaining, 0); ?> m</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-calendar"></i></div>
                    <div class="stat-label">Purchase Date</div>
                    <div class="stat-value"><?php echo date('d-m-Y', strtotime($row['created_at'])); ?></div>
                </div>
            </div>

            <!-- Basic Information Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> Basic Info
                </div>
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-building"></i> Party Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['party_name']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-tshirt"></i> Fabric Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['fabric_name']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-palette"></i> Color</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['color'] ?: '-'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Document Numbers Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-file-alt"></i> Documents
                </div>
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-receipt"></i> Bill No</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['bill_no'] ?: '-'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-truck"></i> Challan No</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['challan_no'] ?: '-'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-cubes"></i> Bundle No</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['bundle_no'] ?: '-'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-cog"></i> Built Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($row['built_num'] ?: '-'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Amount & Stock Summary -->
            <div class="summary-cards">
                <div class="summary-card amount-card">
                    <div class="summary-header">
                        <i class="fas fa-money-bill-wave" style="color: var(--primary);"></i>
                        Amount Details
                    </div>
                    <div class="summary-detail">
                        <span class="summary-detail-label">Rate per Meter</span>
                        <span class="summary-detail-value">Rs <?php echo number_format($row['rate'], 0); ?></span>
                    </div>
                    <div class="summary-detail">
                        <span class="summary-detail-label">Total Meter</span>
                        <span class="summary-detail-value"><?php echo number_format($purchased, 0); ?> m</span>
                    </div>
                    <div class="summary-detail">
                        <span class="summary-detail-label">Adjust Rate</span>
                        <span class="summary-detail-value">Rs <?php echo number_format($row['adjust_rate'] ?? 0, 0); ?></span>
                    </div>
                    <div class="summary-detail">
                        <span class="summary-detail-label">Total Amount</span>
                        <span class="summary-detail-value total">Rs <?php echo number_format($total_amount, 0); ?></span>
                    </div>
                </div>

                <div class="summary-card stock-card">
                    <div class="summary-header">
                        <i class="fas fa-warehouse" style="color: var(--success);"></i>
                        Stock Status
                    </div>
                    <div class="summary-detail">
                        <span class="summary-detail-label">Used Meter</span>
                        <span class="summary-detail-value"><?php echo number_format($used, 0); ?> m</span>
                    </div>
                    <div class="summary-detail">
                        <span class="summary-detail-label">Available Stock</span>
                        <span class="summary-detail-value">
                            <span class="stock-badge <?php 
                                if($remaining <= 0) echo 'stock-out';
                                elseif($remaining < 100) echo 'stock-low';
                                else echo 'stock-available';
                            ?>">
                                <i class="fas <?php 
                                    if($remaining <= 0) echo 'fa-times-circle';
                                    elseif($remaining < 100) echo 'fa-exclamation-triangle';
                                    else echo 'fa-check-circle';
                                ?>"></i>
                                <?php echo number_format($remaining, 0); ?> m
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="purchase_list.php" class="btn-action btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="purchase_edit.php?id=<?php echo $row['id']; ?>" class="btn-action btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>