<?php
$page_identifier = 'reports/machine_report.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure machines and embroidery_entries tables have company_id column
$tables = ['machines', 'embroidery_entries'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Handle deletion (with company check)
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId > 0) {
        // Verify machine belongs to current company
        $check_stmt = $conn->prepare("SELECT id FROM machines WHERE id = ? AND company_id = ?");
        $check_stmt->bind_param("ii", $delId, $company_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            // Delete related embroidery entries (company filtered)
            $del_emb = $conn->prepare("DELETE FROM embroidery_entries WHERE machine_id = ? AND company_id = ?");
            $del_emb->bind_param("ii", $delId, $company_id);
            $del_emb->execute();

            // Delete machine itself
            $del_machine = $conn->prepare("DELETE FROM machines WHERE id = ? AND company_id = ?");
            $del_machine->bind_param("ii", $delId, $company_id);
            $del_machine->execute();
        }
    }
    // Preserve filters after deletion
    $query_params = [];
    $filter_keys = ['machine_no', 'min_head', 'max_head', 'min_rate', 'max_rate', 'min_avg', 'max_avg'];
    foreach ($filter_keys as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $query_params[] = "$key=" . urlencode($_GET[$key]);
        }
    }
    $redirect = "list.php";
    if (!empty($query_params)) $redirect .= "?" . implode('&', $query_params);
    header("Location: $redirect");
    exit;
}

// Get filter values
$machine_filter = isset($_GET['machine_no']) ? trim($_GET['machine_no']) : '';
$min_head = isset($_GET['min_head']) && $_GET['min_head'] !== '' ? (int)$_GET['min_head'] : null;
$max_head = isset($_GET['max_head']) && $_GET['max_head'] !== '' ? (int)$_GET['max_head'] : null;
$min_rate = isset($_GET['min_rate']) && $_GET['min_rate'] !== '' ? (float)$_GET['min_rate'] : null;
$max_rate = isset($_GET['max_rate']) && $_GET['max_rate'] !== '' ? (float)$_GET['max_rate'] : null;
$min_avg = isset($_GET['min_avg']) && $_GET['min_avg'] !== '' ? (int)$_GET['min_avg'] : null;
$max_avg = isset($_GET['max_avg']) && $_GET['max_avg'] !== '' ? (int)$_GET['max_avg'] : null;

// Build WHERE clause for machines (basic filters)
$where = ["company_id = ?"];
$params = [$company_id];
$types = "i";

if (!empty($machine_filter)) {
    $where[] = "machine_no LIKE ?";
    $params[] = "%$machine_filter%";
    $types .= "s";
}
if ($min_head !== null) {
    $where[] = "head >= ?";
    $params[] = $min_head;
    $types .= "i";
}
if ($max_head !== null) {
    $where[] = "head <= ?";
    $params[] = $max_head;
    $types .= "i";
}
if ($min_rate !== null) {
    $where[] = "machine_rate >= ?";
    $params[] = $min_rate;
    $types .= "d";
}
if ($max_rate !== null) {
    $where[] = "machine_rate <= ?";
    $params[] = $max_rate;
    $types .= "d";
}

$machine_sql = "SELECT id, machine_no, head, machine_rate FROM machines WHERE " . implode(" AND ", $where) . " ORDER BY id DESC";
$stmt = $conn->prepare($machine_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$machines_result = $stmt->get_result();
$machine_ids = [];
$machines_data = [];
while ($row = $machines_result->fetch_assoc()) {
    $machine_ids[] = $row['id'];
    $machines_data[$row['id']] = $row;
}

// Calculate daily averages for these machines (only if there are machine IDs)
$daily_averages = [];
if (!empty($machine_ids)) {
    $placeholders = implode(',', array_fill(0, count($machine_ids), '?'));
    $avg_sql = "SELECT 
        m.id,
        COALESCE(SUM(e.stitch_done), 0) as total_stitches,
        COUNT(DISTINCT DATE(e.entry_date)) as working_days
    FROM machines m
    LEFT JOIN embroidery_entries e ON m.id = e.machine_id AND e.company_id = m.company_id
    WHERE m.id IN ($placeholders) AND e.entry_date IS NOT NULL
    GROUP BY m.id";
    $avg_stmt = $conn->prepare($avg_sql);
    $avg_stmt->bind_param(str_repeat('i', count($machine_ids)), ...$machine_ids);
    $avg_stmt->execute();
    $avg_res = $avg_stmt->get_result();
    while ($row = $avg_res->fetch_assoc()) {
        $avg = $row['working_days'] > 0 ? round($row['total_stitches'] / $row['working_days']) : 0;
        $daily_averages[$row['id']] = [
            'total' => $row['total_stitches'],
            'days' => $row['working_days'],
            'avg' => $avg
        ];
    }
}

// Apply average filters (min_avg, max_avg)
$final_machines = [];
foreach ($machine_ids as $id) {
    $avg = $daily_averages[$id]['avg'] ?? 0;
    if (($min_avg !== null && $avg < $min_avg) || ($max_avg !== null && $avg > $max_avg)) {
        continue;
    }
    $final_machines[] = $machines_data[$id];
}

// Compute stats based on final filtered machines
$total_machines = count($final_machines);
$total_heads = 0;
$total_stitches_sum = 0;
$total_days_sum = 0;
foreach ($final_machines as $m) {
    $total_heads += $m['head'];
    $avg_data = $daily_averages[$m['id']] ?? ['total' => 0, 'days' => 0];
    $total_stitches_sum += $avg_data['total'];
    $total_days_sum += $avg_data['days'];
}
$overall_avg_stitches = ($total_days_sum > 0) ? round($total_stitches_sum / $total_days_sum) : 0;

// Helper count – if you have a helpers table, adjust accordingly. For now, set to 0.
$total_helpers = 0;
?>
<!DOCTYPE html>
<html>
<head>
<title>Machines List</title>
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

    .filter-form {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .filter-form h6 {
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 12px;
        color: var(--primary-dark);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
    }

    .filter-item {
        flex: 1;
        min-width: 150px;
    }

    .filter-item label {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 4px;
        display: block;
    }

    .filter-item input {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid var(--border);
        border-radius: 6px;
        font-size: 0.8rem;
    }

    .filter-item input:focus {
        border-color: var(--primary);
        outline: none;
    }

    .filter-buttons {
        display: flex;
        gap: 10px;
        margin-top: 8px;
    }

    .btn-filter, .btn-reset {
        padding: 6px 16px;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 6px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-filter {
        background: var(--primary);
        color: white;
        border: none;
    }

    .btn-filter:hover {
        background: var(--primary-dark);
    }

    .btn-reset {
        background: #e9ecef;
        color: var(--text-dark);
    }

    .btn-reset:hover {
        background: #dee2e6;
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
        min-width: 600px;
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
        .filter-grid {
            flex-direction: column;
        }
        .filter-item {
            min-width: auto;
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

    <!-- Filter Form -->
    <div class="filter-form">
        <h6><i class="fas fa-filter"></i> Filter Machines</h6>
        <form method="GET" action="list.php">
            <div class="filter-grid">
                <div class="filter-item">
                    <label>Machine No</label>
                    <input type="text" name="machine_no" value="<?= htmlspecialchars($machine_filter) ?>" placeholder="e.g., MC-01">
                </div>
                <div class="filter-item">
                    <label>Head (min)</label>
                    <input type="number" name="min_head" value="<?= htmlspecialchars($_GET['min_head'] ?? '') ?>" placeholder="Min heads">
                </div>
                <div class="filter-item">
                    <label>Head (max)</label>
                    <input type="number" name="max_head" value="<?= htmlspecialchars($_GET['max_head'] ?? '') ?>" placeholder="Max heads">
                </div>
                <div class="filter-item">
                    <label>Rate (min)</label>
                    <input type="number" step="0.01" name="min_rate" value="<?= htmlspecialchars($_GET['min_rate'] ?? '') ?>" placeholder="Min rate">
                </div>
                <div class="filter-item">
                    <label>Rate (max)</label>
                    <input type="number" step="0.01" name="max_rate" value="<?= htmlspecialchars($_GET['max_rate'] ?? '') ?>" placeholder="Max rate">
                </div>
                <div class="filter-item">
                    <label>Avg Stitches (min)</label>
                    <input type="number" name="min_avg" value="<?= htmlspecialchars($_GET['min_avg'] ?? '') ?>" placeholder="Min avg stitches/day">
                </div>
                <div class="filter-item">
                    <label>Avg Stitches (max)</label>
                    <input type="number" name="max_avg" value="<?= htmlspecialchars($_GET['max_avg'] ?? '') ?>" placeholder="Max avg stitches/day">
                </div>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply Filters</button>
                <a href="list.php" class="btn-reset"><i class="fas fa-times"></i> Clear Filters</a>
            </div>
        </form>
    </div>

    <!-- Stats Cards (based on filtered machines) -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-industry"></i></div>
            <div class="stat-label">Total Machines</div>
            <div class="stat-value"><?= $total_machines ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
            <div class="stat-label">Helpers</div>
            <div class="stat-value"><?= $total_helpers ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-microchip"></i></div>
            <div class="stat-label">Total Heads</div>
            <div class="stat-value"><?= number_format($total_heads) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-label">Daily Avg Stitches</div>
            <div class="stat-value"><?= number_format($overall_avg_stitches) ?> <small>stitches/day</small></div>
        </div>
    </div>

    <!-- Machine Table -->
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
                        <?php if (!empty($final_machines)): ?>
                            <?php foreach ($final_machines as $row): 
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
                                    <a href="list.php?delete=<?= $row['id'] ?><?= !empty($machine_filter) ? '&machine_no=' . urlencode($machine_filter) : '' ?><?= $min_head !== null ? '&min_head=' . $min_head : '' ?><?= $max_head !== null ? '&max_head=' . $max_head : '' ?><?= $min_rate !== null ? '&min_rate=' . $min_rate : '' ?><?= $max_rate !== null ? '&max_rate=' . $max_rate : '' ?><?= $min_avg !== null ? '&min_avg=' . $min_avg : '' ?><?= $max_avg !== null ? '&max_avg=' . $max_avg : '' ?>" class="btn-delete delete-link" onclick="return confirm('Are you sure you want to delete this machine?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="empty-state">
                                <i class="fas fa-industry"></i>
                                <p>No machines found matching the filters</p>
                                <small><a href="list.php">Clear filters</a> or <a href="add.php">Add machine</a></small>
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
document.addEventListener('click', function(e){
    if(e.target.closest('.delete-link')){
        if(!confirm('Are you sure you want to delete this machine?')){
            e.preventDefault();
        }
    }
});
</script>

</body>
</html>