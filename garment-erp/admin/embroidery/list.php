<?php
$page_identifier = 'embroidery/list.php';
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
$tables = ['embroidery_entries', 'jobs', 'accounts', 'machines'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Embroidery Entries</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #F39C12;
        --primary-light: #FFF3E0;
        --primary-dark: #E67E22;
        --text-dark: #2C3E50;
        --text-muted: #7F8C8D;
        --border: #ECF0F1;
        --bg-light: #FAFAFA;
        --success: #27ae60;
        --danger: #e74c3c;
        --white: #ffffff;
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
        --shadow: 0 4px 6px rgba(0,0,0,0.05);
        --shadow-lg: 0 10px 25px rgba(0,0,0,0.08);
        --transition: all 0.2s ease;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #F1F4F6;
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        color: var(--text-dark);
    }

    .main-container {
        margin-left: 14%;
        padding: 24px 32px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    /* Page Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        color: white;
        padding: 20px 25px;
        margin-bottom: 24px;
        border-radius: 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        box-shadow: var(--shadow-lg);
    }

    .page-header h4 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: white;
    }

    .page-header h4 i {
        font-size: 1.5rem;
    }

    .btn-add {
        background: white;
        color: var(--primary-dark);
        border: none;
        border-radius: 8px;
        padding: 10px 22px;
        font-size: 0.9rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: var(--transition);
    }

    .btn-add:hover {
        background: #f0f0f0;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        color: var(--primary-dark);
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .stats-card {
        background: white;
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
        transition: var(--transition);
        display: flex;
        align-items: flex-start;
        gap: 15px;
    }

    .stats-card:hover {
        box-shadow: var(--shadow);
        transform: translateY(-2px);
        border-color: var(--primary-light);
    }

    .stats-icon {
        width: 45px;
        height: 45px;
        border-radius: 10px;
        background: var(--primary-light);
        color: var(--primary-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        flex-shrink: 0;
    }

    .stats-info h6 {
        font-size: 0.7rem;
        text-transform: uppercase;
        margin-bottom: 4px;
        color: var(--text-muted);
        font-weight: 600;
    }

    .stats-info .stat-number {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-dark);
        line-height: 1.2;
    }

    .stats-info .stat-label {
        font-size: 0.65rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Filter Section */
    .filter-section {
        background: white;
        border-radius: 12px;
        padding: 20px 22px;
        margin-bottom: 20px;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
    }

    .filter-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: flex-end;
    }

    .filter-item {
        flex: 1;
        min-width: 150px;
    }

    .filter-item label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 4px;
        color: var(--text-muted);
        letter-spacing: 0.3px;
    }

    .filter-item label i {
        color: var(--primary);
        width: 14px;
    }

    .filter-item input, .filter-item select {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.85rem;
        background: var(--bg-light);
        transition: var(--transition);
    }

    .filter-item input:focus, .filter-item select:focus {
        border-color: var(--primary);
        background: white;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.15);
        outline: none;
    }

    .filter-buttons {
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }

    .btn {
        padding: 9px 18px;
        font-size: 0.8rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: var(--transition);
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #ECF0F1;
        color: var(--text-dark);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: #D5DBDB;
    }

    /* Table Card */
    .content-card {
        background: white;
        border-radius: 12px;
        box-shadow: var(--shadow);
        margin-bottom: 24px;
        overflow: hidden;
    }

    .card-header {
        background: white;
        padding: 14px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .card-header h5 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
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
        min-width: 1200px;
        background: white;
        table-layout: auto;
    }

    table thead th {
        background: var(--bg-light);
        color: var(--text-dark);
        font-weight: 700;
        padding: 14px 12px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--primary);
        white-space: nowrap;
        text-align: left;
        position: sticky;
        top: 0;
        z-index: 2;
    }

    table tbody td {
        padding: 12px 12px;
        border-bottom: 1px solid #F1F1F1;
        vertical-align: middle;
        color: #444;
    }

    table tbody tr:hover {
        background: #FFF9F2;
    }

    .machine-no {
        font-weight: 700;
        color: var(--primary-dark);
    }

    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.7rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .badge-day {
        background: #FFF3E0;
        color: var(--primary-dark);
        border: 1px solid var(--primary);
    }

    .badge-night {
        background: #EDE7F6;
        color: #6A1B9A;
        border: 1px solid #AB47BC;
    }

    .stitch-count {
        font-weight: 600;
        color: var(--text-dark);
    }

    .rate-value {
        font-weight: 600;
        color: var(--primary-dark);
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 5px;
        justify-content: flex-start;
    }

    .btn-action {
        padding: 5px 10px;
        border-radius: 6px;
        color: white;
        text-decoration: none;
        font-size: 0.7rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: var(--transition);
    }

    .btn-action:hover {
        transform: translateY(-1px);
        color: white;
        filter: brightness(0.9);
    }

    .btn-edit { 
        background: var(--primary);
        color: white;
    }
    .btn-delete { 
        background: var(--danger);
        color: white;
    }

    .empty-state {
        text-align: center;
        padding: 50px;
        color: #999;
    }

    .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 10px;
        opacity: 0.5;
    }

    /* Pagination */
    .pagination {
        margin-top: 20px;
        display: flex;
        gap: 5px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .pagination a, .pagination span {
        padding: 6px 12px;
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text-dark);
        text-decoration: none;
        font-size: 0.75rem;
        transition: var(--transition);
        background: white;
    }

    .pagination a:hover, .pagination .active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .text-muted {
        color: var(--text-muted);
        font-size: 0.65rem;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; padding: 20px; }
    }

    @media (max-width: 992px) {
        .main-container { margin-left: 0; padding: 15px; margin-top: 60px; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .filter-grid { flex-direction: column; }
        .filter-item { width: 100%; }
        .stats-grid { grid-template-columns: 1fr; }
    }

    /* Scrollbar for table */
    .table-responsive::-webkit-scrollbar {
        height: 6px;
    }
    .table-responsive::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    .table-responsive::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
    }
</style>

<?php
// Prepare datalists with company isolation
$datalist_machines = [];
$stmt = $conn->prepare("SELECT DISTINCT machine_no FROM embroidery_entries WHERE machine_no IS NOT NULL AND machine_no != '' AND company_id = ? ORDER BY machine_no");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $datalist_machines[] = htmlspecialchars($row['machine_no']);
}

$datalist_jobs = [];
$stmt = $conn->prepare("SELECT DISTINCT job_no FROM embroidery_entries WHERE job_no IS NOT NULL AND job_no != '' AND company_id = ? ORDER BY job_no");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $datalist_jobs[] = htmlspecialchars($row['job_no']);
}

$datalist_operators = [];
$stmt = $conn->prepare("SELECT DISTINCT operator_name FROM embroidery_entries WHERE operator_name IS NOT NULL AND operator_name != '' AND company_id = ? ORDER BY operator_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $datalist_operators[] = htmlspecialchars($row['operator_name']);
}
?>

<datalist id="machine-list">
    <?php foreach($datalist_machines as $m): ?>
        <option value="<?= $m ?>">
    <?php endforeach; ?>
</datalist>

<datalist id="job-list">
    <?php foreach($datalist_jobs as $j): ?>
        <option value="<?= $j ?>">
    <?php endforeach; ?>
</datalist>

<datalist id="operator-list">
    <?php foreach($datalist_operators as $op): ?>
        <option value="<?= $op ?>">
    <?php endforeach; ?>
</datalist>

</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h4><i class="fas fa-layer-group"></i> Embroidery Entries</h4>
        <a href="entry.php" class="btn-add"><i class="fas fa-plus-circle"></i> Add New Entry</a>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-title"><i class="fas fa-filter"></i> Filter & Search</div>
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-item">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <input type="date" name="date_from" value="<?= isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : '' ?>">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <input type="date" name="date_to" value="<?= isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : '' ?>">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-industry"></i> Machine No</label>
                    <input type="text" name="machine_no" value="<?= isset($_GET['machine_no']) ? htmlspecialchars($_GET['machine_no']) : '' ?>" 
                           placeholder="All Machines" list="machine-list" autocomplete="off">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-hashtag"></i> Job No</label>
                    <input type="text" name="job_no" value="<?= isset($_GET['job_no']) ? htmlspecialchars($_GET['job_no']) : '' ?>" 
                           placeholder="All Jobs" list="job-list" autocomplete="off">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-user"></i> Operator</label>
                    <input type="text" name="operator_name" value="<?= isset($_GET['operator_name']) ? htmlspecialchars($_GET['operator_name']) : '' ?>" 
                           placeholder="All Operators" list="operator-list" autocomplete="off">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-clock"></i> Shift</label>
                    <select name="shift">
                        <option value="">All Shifts</option>
                        <option value="day" <?= (isset($_GET['shift']) && $_GET['shift'] == 'day') ? 'selected' : '' ?>>Day</option>
                        <option value="night" <?= (isset($_GET['shift']) && $_GET['shift'] == 'night') ? 'selected' : '' ?>>Night</option>
                    </select>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                    <a href="list.php" class="btn btn-secondary"><i class="fas fa-redo-alt"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Stats Cards (with company isolation) -->
    <?php
    $stmt = $conn->prepare("SELECT COUNT(*) as t FROM embroidery_entries WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $total_entries = $stmt->get_result()->fetch_assoc()['t'] ?? 0;

    $stmt = $conn->prepare("SELECT COUNT(DISTINCT machine_no) as t FROM embroidery_entries WHERE company_id = ? AND machine_no IS NOT NULL AND machine_no != ''");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $active_machines = $stmt->get_result()->fetch_assoc()['t'] ?? 0;

    $stmt = $conn->prepare("SELECT SUM(stitch_done) as t FROM embroidery_entries WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $total_stitches = $stmt->get_result()->fetch_assoc()['t'] ?? 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as t FROM embroidery_entries WHERE company_id = ? AND DATE(entry_date) = CURDATE()");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $today_entries = $stmt->get_result()->fetch_assoc()['t'] ?? 0;
    ?>

    <div class="stats-grid">
        <div class="stats-card">
            <div class="stats-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stats-info">
                <h6>Total Entries</h6>
                <div class="stat-number"><?= $total_entries ?></div>
                <div class="stat-label">All time entries</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon"><i class="fas fa-cogs"></i></div>
            <div class="stats-info">
                <h6>Active Machines</h6>
                <div class="stat-number"><?= $active_machines ?></div>
                <div class="stat-label">Machines with entries</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon"><i class="fas fa-tshirt"></i></div>
            <div class="stats-info">
                <h6>Total Stitches</h6>
                <div class="stat-number"><?= number_format($total_stitches, 0) ?></div>
                <div class="stat-label">Stitches completed</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="stats-info">
                <h6>Today's Entries</h6>
                <div class="stat-number"><?= $today_entries ?></div>
                <div class="stat-label">Added today</div>
            </div>
        </div>
    </div>

    <!-- Main Table -->
    <div class="content-card">
        <div class="card-header">
            <h5><i class="fas fa-table"></i> Entry Records</h5>
            <span class="text-muted"><?= $total_entries ?> total entries</span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Machine</th>
                        <th>Shift</th>
                        <th>Date</th>
                        <th>Job No</th>
                        <th>Design</th>
                        <th>Operator</th>
                        <th>Part</th>
                        <th>Stitches</th>
                        <th>Rounds</th>
                        <th>Rate</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Build WHERE clause dynamically with company isolation
                $where_clauses = ["company_id = ?"];
                $params = [$company_id];
                $types = "i";

                if (!empty($_GET['date_from'])) {
                    $where_clauses[] = "DATE(entry_date) >= ?";
                    $params[] = $_GET['date_from'];
                    $types .= "s";
                }
                if (!empty($_GET['date_to'])) {
                    $where_clauses[] = "DATE(entry_date) <= ?";
                    $params[] = $_GET['date_to'];
                    $types .= "s";
                }
                if (!empty($_GET['machine_no'])) {
                    $where_clauses[] = "machine_no LIKE ?";
                    $params[] = "%" . $_GET['machine_no'] . "%";
                    $types .= "s";
                }
                if (!empty($_GET['job_no'])) {
                    $where_clauses[] = "job_no LIKE ?";
                    $params[] = "%" . $_GET['job_no'] . "%";
                    $types .= "s";
                }
                if (!empty($_GET['operator_name'])) {
                    $where_clauses[] = "operator_name LIKE ?";
                    $params[] = "%" . $_GET['operator_name'] . "%";
                    $types .= "s";
                }
                if (!empty($_GET['shift'])) {
                    $where_clauses[] = "shift = ?";
                    $params[] = $_GET['shift'];
                    $types .= "s";
                }

                $where_sql = "WHERE " . implode(" AND ", $where_clauses);

                // Pagination
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limit = 50;
                $offset = ($page - 1) * $limit;

                // Total count query
                $count_sql = "SELECT COUNT(*) as total FROM embroidery_entries $where_sql";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->bind_param($types, ...$params);
                $count_stmt->execute();
                $total_row = $count_stmt->get_result()->fetch_assoc();
                $total_pages = ceil($total_row['total'] / $limit);

                // Data query
                $sql = "SELECT * FROM embroidery_entries $where_sql ORDER BY id DESC LIMIT ? OFFSET ?";
                $stmt = $conn->prepare($sql);
                $all_params = array_merge($params, [$limit, $offset]);
                $all_types = $types . "ii";
                $stmt->bind_param($all_types, ...$all_params);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $count = $offset + 1;
                    while ($row = $result->fetch_assoc()) {
                ?>
                    <tr>
                        <td><strong><?= $count++ ?></strong></td>
                        <td><span class="machine-no"><?= htmlspecialchars($row['machine_no']) ?></span></td>
                        <td>
                            <span class="badge <?= $row['shift'] === 'day' ? 'badge-day' : 'badge-night' ?>">
                                <i class="fas fa-<?= $row['shift'] === 'day' ? 'sun' : 'moon' ?>"></i>
                                <?= ucfirst($row['shift']) ?>
                            </span>
                        </td>
                        <td><?= date('d/m/Y', strtotime($row['entry_date'])) ?></td>
                        <td><strong><?= htmlspecialchars($row['job_no']) ?></strong></td>
                        <td><?= htmlspecialchars($row['design_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['operator_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['part'] ?? '-') ?></td>
                        <td class="stitch-count"><?= number_format($row['stitch_done'], 0) ?></td>
                        <td><?= number_format((float)($row['rounds'] ?? 1), 2, '.', '') ?></td>
                        <td class="rate-value">Rs <?= number_format($row['op_rate'] ?? 0, 2) ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="edit_embroidery_entry.php?id=<?= $row['id'] ?>" class="btn-action btn-edit"><i class="fas fa-edit"></i></a>
                                <a href="delete_embroidery_entry.php?id=<?= $row['id'] ?>" class="btn-action btn-delete" onclick="return confirm('Delete this entry?')"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php
                    }
                } else {
                ?>
                    <tr>
                        <td colspan="12" class="empty-state">
                            <i class="fas fa-tshirt"></i>
                            <p>No embroidery entries found</p>
                            <small>Try adjusting your filters or add a new entry</small>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php
        $query_params = array_diff_key($_GET, ['page' => 0]);
        $query_string = http_build_query($query_params);
        $base = "?" . ($query_string ? $query_string . "&" : "");
        ?>
        <?php if ($page > 1): ?><a href="<?= $base ?>page=<?= $page-1 ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
            <?php if ($i == $page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="<?= $base ?>page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?><a href="<?= $base ?>page=<?= $page+1 ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Summary -->
    <?php if (($result->num_rows ?? 0) > 0): ?>
    <div style="margin-top: 20px; text-align: right; padding: 12px 20px;">
        <span class="badge" style="background: var(--primary); color: white; padding: 8px 14px;">
            <i class="fas fa-list"></i> Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_row['total']) ?> of <?= $total_row['total'] ?> entries
        </span>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>