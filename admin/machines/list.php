<?php
$page_identifier = 'machines/list.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure machines table has company_id column
$check = $conn->query("SHOW COLUMNS FROM machines LIKE 'company_id'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE machines ADD COLUMN company_id INT DEFAULT NULL");
    $conn->query("UPDATE machines SET company_id = 1 WHERE company_id IS NULL");
    $conn->query("ALTER TABLE machines MODIFY company_id INT NOT NULL");
}

// Handle deletion
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId > 0) {
        // First verify machine belongs to current company
        $check_stmt = $conn->prepare("SELECT id FROM machines WHERE id = ? AND company_id = ?");
        $check_stmt->bind_param("ii", $delId, $company_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            // Delete related embroidery entries (with company check)
            $del_emb = $conn->prepare("DELETE FROM embroidery_entries WHERE machine_id = ? AND company_id = ?");
            $del_emb->bind_param("ii", $delId, $company_id);
            $del_emb->execute();
            
            // Delete machine
            $del_machine = $conn->prepare("DELETE FROM machines WHERE id = ? AND company_id = ?");
            $del_machine->bind_param("ii", $delId, $company_id);
            $del_machine->execute();
        }
    }
    header("Location: list.php");
    exit;
}

// Calculate daily average stitches per machine (company filtered)
$daily_avg_stmt = $conn->prepare("SELECT 
    m.id,
    m.machine_no,
    COALESCE(SUM(e.stitch_done), 0) as total_stitches,
    COUNT(DISTINCT DATE(e.entry_date)) as working_days
FROM machines m
LEFT JOIN embroidery_entries e ON m.id = e.machine_id AND e.company_id = m.company_id
WHERE m.company_id = ? AND e.entry_date IS NOT NULL
GROUP BY m.id
HAVING working_days > 0");
$daily_avg_stmt->bind_param("i", $company_id);
$daily_avg_stmt->execute();
$daily_avg_result = $daily_avg_stmt->get_result();

$daily_averages = [];
while ($row = $daily_avg_result->fetch_assoc()) {
    $avg = $row['working_days'] > 0 ? round($row['total_stitches'] / $row['working_days']) : 0;
    $daily_averages[$row['id']] = [
        'total' => $row['total_stitches'],
        'days' => $row['working_days'],
        'avg' => $avg
    ];
}

// Calculate overall daily average (company filtered)
$overall_avg_stmt = $conn->prepare("SELECT 
    SUM(stitch_done) as total_stitches,
    COUNT(DISTINCT DATE(entry_date)) as working_days
FROM embroidery_entries WHERE company_id = ?");
$overall_avg_stmt->bind_param("i", $company_id);
$overall_avg_stmt->execute();
$overall_data = $overall_avg_stmt->get_result()->fetch_assoc();
$overall_avg = ($overall_data['working_days'] > 0) ? round($overall_data['total_stitches'] / $overall_data['working_days']) : 0;

// Count total machines (company filtered)
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM machines WHERE company_id = ?");
$count_stmt->bind_param("i", $company_id);
$count_stmt->execute();
$total_machines = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Count unique helpers (company filtered)
$helper_stmt = $conn->prepare("SELECT COUNT(DISTINCT day_helper_name) + COUNT(DISTINCT night_helper_name) as total FROM machines WHERE (day_helper_name IS NOT NULL AND day_helper_name != '') OR (night_helper_name IS NOT NULL AND night_helper_name != '') AND company_id = ?");
$helper_stmt->bind_param("i", $company_id);
$helper_stmt->execute();
$total_helpers = $helper_stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Sum of heads (company filtered)
$head_stmt = $conn->prepare("SELECT SUM(head) as total FROM machines WHERE company_id = ?");
$head_stmt->bind_param("i", $company_id);
$head_stmt->execute();
$total_heads = $head_stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Fetch all machines for table (company filtered)
$machines_stmt = $conn->prepare("SELECT * FROM machines WHERE company_id = ? ORDER BY id DESC");
$machines_stmt->bind_param("i", $company_id);
$machines_stmt->execute();
$result = $machines_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Machines List</title>
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
        padding: 20px 24px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .page-header {
        margin-bottom: 24px;
    }

    .page-header h4 {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h4 i {
        color: var(--primary);
        font-size: 1.4rem;
    }

    .stats-row {
        display: flex;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 12px 16px;
        border: 1px solid var(--border);
        flex: 1;
        min-width: 120px;
        transition: all 0.2s;
    }

    .stat-card:hover {
        border-color: var(--primary);
        transform: translateY(-2px);
    }

    .stat-icon {
        font-size: 1.2rem;
        color: var(--primary);
        margin-bottom: 6px;
    }

    .stat-label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .stat-value small {
        font-size: 0.7rem;
        font-weight: normal;
        color: var(--text-muted);
    }

    .card {
        background: white;
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .card-header {
        background: white;
        padding: 14px 20px;
        border-bottom: 2px solid var(--primary);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .card-header h5 {
        color: var(--primary-dark);
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .card-header h5 i {
        color: var(--primary);
    }

    .btn-add {
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 8px;
        padding: 6px 16px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-add:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        color: white;
    }

    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
        min-width: 800px;
    }

    .table th {
        background: var(--bg-light);
        padding: 10px 12px;
        border-bottom: 2px solid var(--primary);
        font-weight: 600;
        white-space: nowrap;
    }

    .table td {
        padding: 8px 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .table tbody tr:hover {
        background: var(--primary-light);
    }

    .machine-no {
        font-weight: 700;
        color: var(--primary-dark);
    }

    .rate-value {
        font-weight: 600;
        color: var(--primary);
    }

    .avg-value {
        font-weight: 600;
        color: var(--info);
    }

    .btn-edit, .btn-delete {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        text-decoration: none;
        transition: all 0.2s;
        margin: 0 2px;
    }

    .btn-edit {
        background: var(--primary);
        color: white;
    }

    .btn-edit:hover {
        background: var(--primary-dark);
    }

    .btn-delete {
        background: var(--danger);
        color: white;
    }

    .btn-delete:hover {
        background: #c82333;
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 12px;
        opacity: 0.5;
    }

    .empty-state p {
        font-size: 0.85rem;
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
            flex-direction: column;
            gap: 12px;
        }
        .stat-card {
            min-width: auto;
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
    <div class="page-header">
        <h4><i class="fas fa-cogs"></i> Machine Management</h4>
    </div>

    <!-- Stats Cards -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-industry"></i></div>
            <div class="stat-label">Total Machines</div>
            <div class="stat-value"><?= number_format($total_machines) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
            <div class="stat-label">Helpers</div>
            <div class="stat-value"><?= number_format($total_helpers) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-microchip"></i></div>
            <div class="stat-label">Total Heads</div>
            <div class="stat-value"><?= number_format($total_heads) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-label">Daily Avg Stitches</div>
            <div class="stat-value"><?= number_format($overall_avg) ?> <small>stitches/day</small></div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> All Machines</h5>
            <a href="add.php" class="btn-add"><i class="fas fa-plus"></i> Add Machine</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Machine No</th>
                            <th>Head</th>
                            <th>Machine Rate</th>
                            <th>Daily Avg Stitches</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $avg_data = $daily_averages[$row['id']] ?? ['avg' => 0, 'total' => 0, 'days' => 0];
                            ?>
                            <tr>
                                <td><span class="machine-no"><?= htmlspecialchars($row['machine_no']) ?></span></td>
                                <td><?= htmlspecialchars($row['head'] ?? '-') ?></td>
                                <td><span class="rate-value">Rs <?= number_format($row['machine_rate'] ?? 0, 2) ?></span></td>
                                <td>
                                    <?php if ($avg_data['avg'] > 0): ?>
                                        <span class="avg-value"><?= number_format($avg_data['avg']) ?></span>
                                        <small class="text-muted">/day</small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="edit.php?id=<?= $row['id'] ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="list.php?delete=<?= $row['id'] ?>" class="btn-delete delete-link" onclick="return confirm('Are you sure you want to delete this machine?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="empty-state">
                                    <i class="fas fa-industry"></i>
                                    <p>No machines found</p>
                                    <small>Click "Add Machine" to create your first machine</small>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// No additional JavaScript needed (delete confirmation handled inline)
</script>

</body>
</html>