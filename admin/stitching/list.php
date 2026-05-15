<?php
$page_identifier = 'stitching/list.php';
require_once "../../config/db.php";
require_once "../../includes/functions.php";
session_start();

// --- Company filter (multi-tenant) ---
$company_id = $_SESSION['company_id'] ?? 0;
$company_id = intval($company_id);

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Search and filter
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$tab_type_filter = isset($_GET['tab_type']) ? mysqli_real_escape_string($conn, $_GET['tab_type']) : '';
$from_date = isset($_GET['from_date']) ? mysqli_real_escape_string($conn, $_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? mysqli_real_escape_string($conn, $_GET['to_date']) : '';

// Part 1: stitching_bill_items (company filter via jobs)
$stitching_part = "
    SELECT 
        sbi.id,
        sbi.created_at,
        sbi.job_no,
        sbi.tab_type,
        sbi.name AS vendor_name,
        sbi.qty,
        sbi.rate,
        (COALESCE(sbi.amount,0) + COALESCE(sbi.sub_total,0)) AS amount,
        sbi.part_name,
        sbi.lot_no,
        sbi.department,
        sbi.color,
        sbi.kurti_qty,
        sbi.shalwar_qty,
        sbi.dupatta_qty,
        sbi.stitch,
        sbi.round_qty,
        sbi.head,
        j.design_name,
        NULL AS emb_issue,
        NULL AS back_issue,
        NULL AS extra_issue,
        NULL AS color_number,
        NULL AS total_meter_with_color
    FROM stitching_bill_items sbi
    INNER JOIN jobs j ON sbi.job_no = j.job_no AND j.company_id = $company_id
";

// Part 2: fabric_issue table (company filter via jobs)
$fabric_part = "
    SELECT 
        fi.id,
        fi.created_at,
        fi.job_no,
        'fabric_issue' AS tab_type,
        fi.fabric_name AS vendor_name,
        (fi.emb_issue + fi.back_issue + fi.extra_issue) AS qty,
        fi.adjust_rate AS rate,
        fi.amount,
        NULL AS part_name,
        fi.lot_no,
        NULL AS department,
        NULL AS color,
        NULL AS kurti_qty,
        NULL AS shalwar_qty,
        NULL AS dupatta_qty,
        NULL AS stitch,
        NULL AS round_qty,
        NULL AS head,
        j.design_name,
        fi.emb_issue,
        fi.back_issue,
        fi.extra_issue,
        fi.color_number,
        fi.total_meter_with_color
    FROM fabric_issue fi
    INNER JOIN jobs j ON fi.job_no = j.job_no AND j.company_id = $company_id
";

$union_sql = "($stitching_part) UNION ALL ($fabric_part)";

// Build WHERE conditions for search/filter
$where_conditions = [];
if (!empty($search)) {
    $where_conditions[] = "(job_no LIKE '%$search%' OR vendor_name LIKE '%$search%' OR part_name LIKE '%$search%' OR lot_no LIKE '%$search%')";
}
if (!empty($tab_type_filter)) {
    $where_conditions[] = "tab_type = '$tab_type_filter'";
}
if (!empty($from_date)) {
    $where_conditions[] = "DATE(created_at) >= '$from_date'";
}
if (!empty($to_date)) {
    $where_conditions[] = "DATE(created_at) <= '$to_date'";
}
// Exclude Production Billing & Claim Billing
$where_conditions[] = "tab_type NOT IN ('production_billing', 'claim_billing')";
$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $where_conditions);
}

$final_query = "
    SELECT * FROM ($union_sql) AS combined
    $where_clause
    ORDER BY created_at DESC
    LIMIT $offset, $limit
";

$count_sql = "
    SELECT COUNT(*) as total FROM ($union_sql) AS combined
    $where_clause
";

$result = $conn->query($final_query);
$count_res = $conn->query($count_sql);
$total_rows = $count_res->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Get distinct tab types for filter dropdown
$type_sql = "
    SELECT DISTINCT tab_type FROM (
        SELECT tab_type FROM stitching_bill_items
        UNION
        SELECT 'fabric_issue' AS tab_type
    ) AS types
    WHERE tab_type NOT IN ('production_billing', 'claim_billing')
    ORDER BY tab_type
";
$tab_types_result = $conn->query($type_sql);
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Stitching & Fabric Entries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #F39C12;
            --primary-light: #FEF5E7;
            --primary-dark: #B26000;
            --border: #E5E7E9;
            --bg-light: #F8F9F9;
            --text-dark: #2C3E50;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background-color: #f5f5f5; color: var(--text-dark); font-size: 0.75rem; }
        .main-container { margin-left: 14%; padding: 20px 28px; min-height: 100vh; }
        .page-header { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 14px 20px; margin-bottom: 20px; border-left: 5px solid var(--primary); }
        .page-header h1 { font-size: 1.4rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 10px; }
        .page-header h1 i { color: var(--primary); }
        .page-header p { margin: 3px 0 0 0; color: #6b7b8b; font-size: 0.7rem; }
        .filter-bar { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 10px 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .filter-bar form { flex: 1; display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 3px; }
        .filter-group label { font-size: 0.65rem; font-weight: 600; color: #666; }
        .filter-bar input, .filter-bar select { padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.7rem; }
        .filter-bar input { min-width: 140px; }
        .filter-bar button { padding: 5px 14px; background: var(--primary); color: white; border: none; border-radius: 6px; font-size: 0.7rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .filter-bar button:hover { background: var(--primary-dark); }
        .btn-reset { padding: 5px 14px; background: #95a5a6; color: white; text-decoration: none; border-radius: 6px; font-size: 0.7rem; display: inline-flex; align-items: center; gap: 5px; }
        .btn-reset:hover { background: #7f8c8d; color: white; }
        .btn-add { padding: 5px 14px; background: var(--success); color: white; text-decoration: none; border-radius: 6px; font-size: 0.7rem; display: inline-flex; align-items: center; gap: 5px; }
        .btn-add:hover { background: #219a52; color: white; }
        .table-container { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 12px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.7rem; }
        table thead th { background: var(--bg-light); padding: 8px 6px; font-weight: 600; border-bottom: 2px solid var(--primary); white-space: nowrap; }
        table tbody td { padding: 6px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        table tbody tr:hover { background: var(--bg-light); }
        .type-badge { padding: 2px 8px; border-radius: 30px; font-size: 0.6rem; font-weight: 600; display: inline-block; white-space: nowrap; }
        .type-stitching-depart { background: #28a745; color: white; }
        .type-embroidery-billing { background: #17a2b8; color: white; }
        .type-fabric-issue { background: #ffc107; color: #333; }
        .type-master { background: #dc3545; color: white; }
        .type-thekdar { background: #28a745; color: white; }
        .type-packing { background: #ffc107; color: #333; }
        .type-pressman { background: #17a2b8; color: white; }
        .type-handwork { background: #6f42c1; color: white; }
        .type-material { background: #fd7e14; color: white; }
        .type-croping { background: #20c997; color: white; }
        .type-checking { background: #e83e8c; color: white; }
        .type-overlook { background: #343a40; color: white; }
        .type-other { background: #6c757d; color: white; }
        .action-buttons { display: flex; gap: 3px; flex-wrap: wrap; justify-content: center; }
        .btn-action { padding: 3px 8px; border-radius: 4px; font-size: 0.6rem; display: inline-flex; align-items: center; gap: 3px; text-decoration: none; transition: 0.2s; }
        .btn-edit { background: var(--warning); color: #333; }
        .btn-edit:hover { background: #e0a800; color: #333; transform: translateY(-1px); }
        .btn-delete { background: var(--danger); color: white; }
        .btn-delete:hover { background: #c0392b; transform: translateY(-1px); }
        .amount { font-weight: 600; color: var(--success); }
        .text-end { text-align: right; }
        .pagination { margin-top: 20px; display: flex; gap: 4px; justify-content: center; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 4px 10px; border: 1px solid var(--border); border-radius: 4px; color: var(--text-dark); text-decoration: none; font-size: 0.7rem; }
        .pagination a:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .active { background: var(--primary); color: white; border-color: var(--primary); }
        .summary-badge { margin-top: 16px; text-align: right; }
        .summary-badge .badge { background: var(--primary); color: white; padding: 4px 10px; border-radius: 30px; font-size: 0.65rem; }
        @media (max-width: 1200px) { .main-container { margin-left: 10%; } }
        @media (max-width: 900px) { .main-container { margin-left: 0; padding: 12px; } .filter-bar form { flex-direction: column; width: 100%; } .filter-bar input, .filter-bar select { width: 100%; } .action-buttons { flex-direction: column; } .btn-action { width: 100%; justify-content: center; } }
    </style>
</head>
<body>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/navbar.php'; ?>

<div class="main-container">
    <div class="page-header">
        <h1><i class="fas fa-tshirt"></i> All Stitching & Fabric Entries</h1>
        <p>Stitching | Embroidery Billing | Fabric Issue | Handwork | Master | Thekdar | Croping | Checking | Overlook | Pressman | Packing | Material | Other</p>
    </div>

    <!-- Filter Bar with Date Range -->
    <div class="filter-bar">
        <form method="GET">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" placeholder="Job No, Vendor, Lot, Part..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Entry Type</label>
                <select name="tab_type">
                    <option value="">All Types</option>
                    <?php 
                    $tab_types_result->data_seek(0);
                    while($type_row = $tab_types_result->fetch_assoc()): 
                        $type_val = $type_row['tab_type'];
                        $type_display = ucwords(str_replace('_', ' ', $type_val));
                    ?>
                    <option value="<?= $type_val ?>" <?= $tab_type_filter == $type_val ? 'selected' : '' ?>><?= $type_display ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> From Date</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> To Date</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <button type="submit"><i class="fas fa-filter"></i> Filter</button>
        </form>
        <a href="list.php" class="btn-reset"><i class="fas fa-redo-alt"></i> Reset</a>
        <a href="entry.php" class="btn-add"><i class="fas fa-plus"></i> New Entry</a>
    </div>

    <!-- Entries Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Job No</th>
                    <th>Type</th>
                    <th>Vendor/Name</th>
                    <th>Details</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Rate</th>
                    <th class="text-end">Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows > 0): 
                    $count = $offset + 1;
                    while($row = $result->fetch_assoc()): 
                        $type_class = 'type-' . str_replace('_', '-', $row['tab_type']);
                        $total_amount = $row['amount'] ?? 0;
                        
                        // Determine edit/delete URLs
                        $edit_url = '';
                        $delete_url = '';
                        switch($row['tab_type']) {
                            case 'stitching_depart':
                                $edit_url = "edit_billing.php?id=" . $row['id'];
                                $delete_url = "delete_stitching_entry.php?id=" . $row['id'];
                                break;
                            case 'embroidery_billing':
                                $edit_url = "edit_emb_bill.php?job_no=" . urlencode($row['job_no']);
                                $delete_url = "emb_billing_delete.php?id=" . $row['id'];
                                break;
                            case 'fabric_issue':
                                $edit_url = "edit_fabric_issue.php?id=" . $row['id'];
                                $delete_url = "delete_issue.php?id=" . $row['id'];
                                break;
                            case 'material':
                                $edit_url = "edit_material.php?id=" . $row['id'];
                                $delete_url = "delete_material_entry.php?id=" . $row['id'];
                                break;
                            default:
                                $edit_url = "edit_stitching_billing.php?id=" . $row['id'];
                                $delete_url = "delete_stitching_entry.php?id=" . $row['id'];
                                break;
                        }
                        
                        // Build details string
                        $details = [];
                        if(!empty($row['part_name'])) $details[] = "Part: " . htmlspecialchars($row['part_name']);
                        if(!empty($row['lot_no'])) $details[] = "Lot: " . htmlspecialchars($row['lot_no']);
                        if(!empty($row['department'])) $details[] = "Dept: " . htmlspecialchars($row['department']);
                        if(!empty($row['color'])) $details[] = "Color: " . htmlspecialchars($row['color']);
                        if($row['kurti_qty'] > 0) $details[] = "Kurti: " . $row['kurti_qty'];
                        if($row['shalwar_qty'] > 0) $details[] = "Shalwar: " . $row['shalwar_qty'];
                        if($row['dupatta_qty'] > 0) $details[] = "Dupatta: " . $row['dupatta_qty'];
                        if($row['stitch'] > 0) $details[] = "Stitches: " . number_format($row['stitch']);
                        if($row['round_qty'] > 0) $details[] = "Rounds: " . $row['round_qty'];
                        if($row['head'] > 0) $details[] = "Head: " . $row['head'];
                        if($row['emb_issue'] > 0) $details[] = "Emb: " . $row['emb_issue'] . "m";
                        if($row['back_issue'] > 0) $details[] = "Back: " . $row['back_issue'] . "m";
                        if($row['extra_issue'] > 0) $details[] = "Extra: " . $row['extra_issue'] . "m";
                        if($row['color_number'] > 0) $details[] = "Colors: " . $row['color_number'];
                        if($row['total_meter_with_color'] > 0) $details[] = "Total Mtr: " . number_format($row['total_meter_with_color'],2);
                        $details_str = implode(' | ', $details);
                ?>
                <tr>
                    <td><strong><?= $count++ ?></strong></td>
                    <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($row['job_no']) ?></strong>
                        <?php if($row['design_name']): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($row['design_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="type-badge <?= $type_class ?>"><?= ucwords(str_replace('_', ' ', $row['tab_type'])) ?></span></td>
                    <td><?= htmlspecialchars($row['vendor_name'] ?? 'N/A') ?></td>
                    <td><small><?= $details_str ?: '-' ?></small></td>
                    <td class="text-end"><?= number_format($row['qty'] ?? 0) ?></td>
                    <td class="text-end">Rs <?= number_format($row['rate'] ?? 0, 2) ?></td>
                    <td class="text-end amount">Rs <?= number_format($total_amount, 2) ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="<?= $edit_url ?>" class="btn-action btn-edit" title="Edit"><i class="fas fa-edit"></i> Edit</a>
                            <a href="<?= $delete_url ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete this entry?')"><i class="fas fa-trash"></i> Delete</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="10" class="text-center py-4">No entries found. <a href="entry.php">Add first entry</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if($total_pages > 1): ?>
    <div class="pagination">
        <?php if($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&tab_type=<?= urlencode($tab_type_filter) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php 
        $start = max(1, $page-2);
        $end = min($total_pages, $page+2);
        for($i=$start; $i<=$end; $i++): ?>
            <?php if($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&tab_type=<?= urlencode($tab_type_filter) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&tab_type=<?= urlencode($tab_type_filter) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="summary-badge">
        <span class="badge"><i class="fas fa-calculator"></i> Total: <?= $total_rows ?> entries | Page <?= $page ?> of <?= $total_pages ?></span>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>