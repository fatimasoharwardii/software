<?php
$page_identifier = 'material/raw_material_list.php';

require_once "../../config/db.php";
require_once "../../includes/functions.php";
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];

// ============ FILTER PROCESSING ============
$vendor_ids   = isset($_GET['vendor_ids'])   ? array_filter($_GET['vendor_ids'], 'is_numeric') : [];
$bill_nos     = isset($_GET['bill_nos'])     ? array_map('trim', $_GET['bill_nos']) : [];
$material_ids = isset($_GET['material_ids']) ? array_filter($_GET['material_ids'], 'is_numeric') : [];
$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';

// Base WHERE
$where_parts = ["r.company_id = $company_id"];

// Vendor filter
if (!empty($vendor_ids)) {
    $ids = implode(',', array_map('intval', $vendor_ids));
    $where_parts[] = "r.vendor_id IN ($ids)";
}

// Bill no filter
if (!empty($bill_nos)) {
    $bill_conds = [];
    foreach ($bill_nos as $bn) {
        $bn_esc = mysqli_real_escape_string($conn, $bn);
        if ($bn === '') {
            $bill_conds[] = "r.bill_no = '' OR r.bill_no IS NULL";
        } else {
            $bill_conds[] = "r.bill_no = '$bn_esc'";
        }
    }
    $where_parts[] = "(" . implode(' OR ', $bill_conds) . ")";
}

// Material filter
if (!empty($material_ids)) {
    $ids = implode(',', array_map('intval', $material_ids));
    $where_parts[] = "r.material_id IN ($ids)";
}

// Date range filter
if (!empty($start_date)) {
    $start = mysqli_real_escape_string($conn, $start_date);
    $where_parts[] = "r.entry_date >= '$start'";
}
if (!empty($end_date)) {
    $end = mysqli_real_escape_string($conn, $end_date);
    $where_parts[] = "r.entry_date <= '$end'";
}

$where = implode(' AND ', $where_parts);

// ============ PAGINATION & GROUPING ============
$group_by_material = (count($material_ids) > 1);

$items_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

if ($group_by_material) {
    $total_rows = null;
    $total_pages = 1;
} else {
    $cnt = $conn->query("SELECT COUNT(*) AS c FROM raw_material_entries r
        LEFT JOIN parties p ON r.vendor_id = p.id
        LEFT JOIN materials m ON r.material_id = m.id
        WHERE $where")->fetch_assoc()['c'];
    $total_pages = ceil($cnt / $items_per_page);
}

// Main query
$sql = "SELECT r.id, r.entry_date, r.bill_no,
               p.party_name AS vendor_name,
               m.material_name,
               r.rate, r.qty, r.amount, r.description
        FROM raw_material_entries r
        LEFT JOIN parties p ON r.vendor_id = p.id
        LEFT JOIN materials m ON r.material_id = m.id
        WHERE $where
        ORDER BY r.entry_date DESC, r.id DESC";

if (!$group_by_material) {
    $sql .= " LIMIT $offset, $items_per_page";
}

$res = $conn->query($sql);

if ($group_by_material && $res) {
    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $mat = $row['material_name'] ?? 'Unknown';
        $grouped[$mat][] = $row;
    }
} else {
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// Fetch filter options
$vendor_opts   = $conn->query("SELECT id, party_name FROM parties WHERE company_id = $company_id ORDER BY party_name");
$bill_opts     = $conn->query("SELECT DISTINCT bill_no FROM raw_material_entries WHERE company_id = $company_id AND bill_no IS NOT NULL AND bill_no != '' ORDER BY bill_no");
$material_opts = $conn->query("SELECT id, material_name FROM materials WHERE company_id = $company_id ORDER BY material_name");

// Messages
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg   = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Raw Material List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #F39C12;
            --primary-light: #FEF5E7;
            --primary-dark: #E67E22;
            --border: #E9ECEF;
            --text-dark: #2C3E50;
            --success: #27ae60;
            --danger: #e74c3c;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); font-family: 'Segoe UI', system-ui, sans-serif; }
        .main-container { margin-left: 14%; padding: 28px 35px; min-height: 100vh; }
        h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 28px; display: flex; align-items: center; gap: 12px; border-bottom: 3px solid var(--primary); padding-bottom: 12px; }
        h2 i { color: var(--primary); }

        /* Card with overflow visible for filter card */
        .card { background: white; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 2px solid var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; background: white; border-radius: 20px 20px 0 0; }
        .card-body { padding: 20px 24px; }

        /* Filter row */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 160px; position: relative; }
        .filter-group label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #6c757d; display: block; margin-bottom: 4px; }
        .filter-group label i { color: var(--primary); width: 14px; }

        /* Multi-select */
        .multi-select-btn {
            width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 40px;
            font-size: 0.85rem; background: white; text-align: left; cursor: pointer;
            display: flex; justify-content: space-between; align-items: center;
        }
        .multi-select-btn:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 2px rgba(243,156,18,0.1); }
        .multi-select-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; background: white;
            border: 1px solid var(--border); border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15); max-height: 220px; overflow-y: auto;
            z-index: 20; display: none; padding: 8px 0; margin-top: 4px;
        }
        .multi-select-dropdown.show { display: block; }
        .multi-select-dropdown label {
            display: flex; align-items: center; gap: 8px; padding: 8px 14px;
            font-weight: 400; font-size: 0.85rem; cursor: pointer; margin: 0;
        }
        .multi-select-dropdown label:hover { background: var(--primary-light); }
        .multi-select-dropdown input[type="checkbox"] { accent-color: var(--primary); }

        .btn { border-radius: 40px; padding: 8px 24px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; border: none; cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-outline { background: white; border: 1px solid var(--border); color: var(--text-dark); }
        .btn-outline:hover { background: var(--primary-light); border-color: var(--primary); }
        .btn-add { background: var(--success); color: white; text-decoration: none; }
        .btn-add:hover { background: #219a52; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; border-bottom: 1px solid var(--border); }
        th { background: var(--primary-light); font-weight: 700; color: var(--text-dark); font-size: 0.85rem; }
        td { font-size: 0.85rem; vertical-align: middle; }
        .btn-sm { padding: 5px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; margin: 0 2px; }
        .btn-edit { background: var(--primary); color: white; }
        .btn-edit:hover { background: var(--primary-dark); }
        .btn-delete { background: var(--danger); color: white; }
        .btn-delete:hover { background: #c0392b; }

        .alert { padding: 12px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-left: 4px solid; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }

        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; }
        .page-link { padding: 6px 12px; border: 1px solid var(--border); border-radius: 30px; text-decoration: none; color: var(--primary-dark); font-size: 0.8rem; }
        .page-link.active { background: var(--primary); color: white; }
        .page-link:hover:not(.active) { background: var(--primary-light); }

        .grand-total-row td { background: #f8f9fa; font-weight: 700; }

        @media (max-width: 992px) {
            .main-container { margin-left: 0; padding: 16px; margin-top: 60px; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2><i class="fas fa-boxes"></i> Raw Material Entries</h2>
        <a href="raw_material_entry.php" class="btn btn-add"><i class="fas fa-plus-circle"></i> New Entry</a>
    </div>

    <?php if($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if($error_msg): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- FILTER CARD -->
    <div class="card">
        <div class="card-header"><i class="fas fa-filter"></i> Advanced Filters</div>
        <div class="card-body" style="overflow:visible;">
            <form method="GET" id="filterForm">
                <div class="filter-bar">
                    <!-- Date Range -->
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Start Date</label>
                        <input type="date" name="start_date" class="multi-select-btn" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> End Date</label>
                        <input type="date" name="end_date" class="multi-select-btn" value="<?= htmlspecialchars($end_date) ?>">
                    </div>

                    <!-- Vendor Multi-Select -->
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> Vendor</label>
                        <div class="multi-select" id="vendorMulti">
                            <div class="multi-select-btn" onclick="toggleDropdown('vendorMulti')">Select Vendors <i class="fas fa-caret-down"></i></div>
                            <div class="multi-select-dropdown">
                                <?php while($v = $vendor_opts->fetch_assoc()): ?>
                                <label><input type="checkbox" name="vendor_ids[]" value="<?= $v['id'] ?>" <?= in_array($v['id'], $vendor_ids) ? 'checked' : '' ?>> <?= htmlspecialchars($v['party_name']) ?></label>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Bill No Multi-Select -->
                    <div class="filter-group">
                        <label><i class="fas fa-file-invoice"></i> Bill No</label>
                        <div class="multi-select" id="billMulti">
                            <div class="multi-select-btn" onclick="toggleDropdown('billMulti')">Select Bills <i class="fas fa-caret-down"></i></div>
                            <div class="multi-select-dropdown">
                                <?php while($b = $bill_opts->fetch_assoc()): ?>
                                <label><input type="checkbox" name="bill_nos[]" value="<?= htmlspecialchars($b['bill_no']) ?>" <?= in_array($b['bill_no'], $bill_nos) ? 'checked' : '' ?>> <?= htmlspecialchars($b['bill_no']) ?></label>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Material Multi-Select -->
                    <div class="filter-group">
                        <label><i class="fas fa-box"></i> Material</label>
                        <div class="multi-select" id="materialMulti">
                            <div class="multi-select-btn" onclick="toggleDropdown('materialMulti')">Select Materials <i class="fas fa-caret-down"></i></div>
                            <div class="multi-select-dropdown">
                                <?php while($m = $material_opts->fetch_assoc()): ?>
                                <label><input type="checkbox" name="material_ids[]" value="<?= $m['id'] ?>" <?= in_array($m['id'], $material_ids) ? 'checked' : '' ?>> <?= htmlspecialchars($m['material_name']) ?></label>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Buttons -->
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="raw_material_list.php" class="btn btn-outline" style="margin-left:8px;"><i class="fas fa-times"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- RESULTS -->
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> Material Entries List</div>
        <div class="card-body">

            <?php if ($group_by_material && !empty($grouped)): ?>
                <?php foreach ($grouped as $material => $entries): ?>
                <h5 class="mb-3"><i class="fas fa-box"></i> <?= htmlspecialchars($material) ?></h5>
                <div class="table-responsive mb-4">
                    <table>
                        <thead><tr><th>ID</th><th>Date</th><th>Vendor</th><th>Bill No</th><th>Rate</th><th>Qty</th><th>Amount</th><th>Description</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php $gt = 0; foreach ($entries as $row): $gt += $row['amount']; ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= date('d-m-Y', strtotime($row['entry_date'])) ?></td>
                                <td><?= htmlspecialchars($row['vendor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['bill_no'] ?? '') ?></td>
                                <td><?= number_format($row['rate'],2) ?></td>
                                <td><?= number_format($row['qty'],2) ?></td>
                                <td><?= number_format($row['amount'],2) ?></td>
                                <td><?= htmlspecialchars($row['description'] ?? '') ?></td>
                                <td>
                                    <a href="raw_material_edit.php?id=<?= $row['id'] ?>" class="btn-sm btn-edit"><i class="fas fa-edit"></i></a>
                                    <a href="raw_material_delete.php?id=<?= $row['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="grand-total-row"><td colspan="6"><strong>Grand Total for <?= htmlspecialchars($material) ?></strong></td><td><strong><?= number_format($gt,2) ?></strong></td><td colspan="2"></td></tr>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php elseif (!empty($rows)): ?>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>ID</th><th>Date</th><th>Vendor</th><th>Material</th><th>Bill No</th><th>Rate</th><th>Qty</th><th>Amount</th><th>Description</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= date('d-m-Y', strtotime($row['entry_date'])) ?></td>
                                <td><?= htmlspecialchars($row['vendor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['material_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['bill_no'] ?? '') ?></td>
                                <td><?= number_format($row['rate'],2) ?></td>
                                <td><?= number_format($row['qty'],2) ?></td>
                                <td><?= number_format($row['amount'],2) ?></td>
                                <td><?= htmlspecialchars($row['description'] ?? '') ?></td>
                                <td>
                                    <a href="raw_material_edit.php?id=<?= $row['id'] ?>" class="btn-sm btn-edit"><i class="fas fa-edit"></i></a>
                                    <a href="raw_material_delete.php?id=<?= $row['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>" class="page-link <?= $i==$page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align:center; padding:40px;">No entries found.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleDropdown(id) {
        const dd = document.getElementById(id).querySelector('.multi-select-dropdown');
        document.querySelectorAll('.multi-select-dropdown').forEach(d => { if(d!==dd) d.classList.remove('show'); });
        dd.classList.toggle('show');
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.multi-select')) {
            document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
        }
    });
</script>
</body>
</html>