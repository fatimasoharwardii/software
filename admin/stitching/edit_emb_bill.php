<?php
$page_identifier = 'stitching/edit_emb_bill.php';
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
$tables = ['stitching_posted_bills', 'stitching_bill_items', 'jobs', 'accounts', 'parties'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Accept either id (bill_id) or job_no
$bill_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$job_no_param = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';

// If job_no provided, fetch the bill id
if (!empty($job_no_param) && $bill_id == 0) {
    $bill_stmt = $conn->prepare("SELECT id FROM stitching_posted_bills WHERE job_no = ? AND claim_type = 'Embroidery Bill' AND company_id = ? ORDER BY id DESC LIMIT 1");
    $bill_stmt->bind_param("si", $job_no_param, $company_id);
    $bill_stmt->execute();
    $bill_row = $bill_stmt->get_result()->fetch_assoc();
    if ($bill_row) {
        $bill_id = $bill_row['id'];
    }
    $bill_stmt->close();
}

if ($bill_id == 0) {
    header("Location: list.php");
    exit;
}

// Fetch bill details
$bill_stmt = $conn->prepare("SELECT * FROM stitching_posted_bills WHERE id = ? AND company_id = ?");
$bill_stmt->bind_param("ii", $bill_id, $company_id);
$bill_stmt->execute();
$bill = $bill_stmt->get_result()->fetch_assoc();
$bill_stmt->close();

if (!$bill) {
    header("Location: list.php");
    exit;
}

// Can't edit posted bills
if ($bill['status'] == 'posted') {
    echo "<script>
            alert('Posted bills cannot be edited! Please un-post the bill first.');
            window.location.href = 'list.php?id=$bill_id';
          </script>";
    exit;
}

// Fetch bill items
$items_stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE job_no = ? AND tab_type = 'embroidery_billing' AND company_id = ? ORDER BY id");
$items_stmt->bind_param("si", $bill['job_no'], $company_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}
$items_stmt->close();

// Handle update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $billing_date = $_POST['billing_date'];
    $part_names = $_POST['part_name'] ?? [];
    $stitches = $_POST['stitch_round'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $rounds = $_POST['round'] ?? [];
    $heads = $_POST['head'] ?? [];
    $sub_totals = $_POST['sub_total'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    
    $total_amount = array_sum($sub_totals);
    
    $conn->begin_transaction();
    try {
        // Update existing items
        $update_item_stmt = $conn->prepare("UPDATE stitching_bill_items SET 
            part_name = ?, stitch = ?, rate = ?, round_qty = ?, head = ?, sub_total = ?, amount = ?
            WHERE id = ? AND company_id = ?");
        
        foreach ($item_ids as $index => $item_id) {
            if (empty($part_names[$index])) continue;
            $part = $part_names[$index];
            $stitch = floatval($stitches[$index] ?? 0);
            $rate = floatval($rates[$index] ?? 0);
            $round = floatval($rounds[$index] ?? 1);
            $head = floatval($heads[$index] ?? 1);
            $sub_total = floatval($sub_totals[$index] ?? 0);
            
            // Create a variable for the integer cast (required for bind_param reference)
            $item_id_int = (int)$item_id;
            
            $update_item_stmt->bind_param(
                "sddddddii",
                $part, $stitch, $rate, $round, $head, $sub_total, $sub_total,
                $item_id_int, $company_id
            );
            if (!$update_item_stmt->execute()) {
                throw new Exception("Failed to update item: " . $update_item_stmt->error);
            }
        }
        $update_item_stmt->close();
        
        // Insert new items
        $insert_item_stmt = $conn->prepare("INSERT INTO stitching_bill_items 
            (job_no, tab_type, name, part_name, stitch, round_qty, qty, rate, amount, sub_total, head, created_at, company_id) 
            VALUES (?, 'embroidery_billing', ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW(), ?)");
        
        $new_part_names = $_POST['new_part_name'] ?? [];
        $new_stitches = $_POST['new_stitch_round'] ?? [];
        $new_rates = $_POST['new_rate'] ?? [];
        $new_rounds = $_POST['new_round'] ?? [];
        $new_heads = $_POST['new_head'] ?? [];
        $new_sub_totals = $_POST['new_sub_total'] ?? [];
        
        foreach ($new_part_names as $index => $part) {
            if (empty($part)) continue;
            $stitch = floatval($new_stitches[$index] ?? 0);
            $rate = floatval($new_rates[$index] ?? 0);
            $round = floatval($new_rounds[$index] ?? 1);
            $head = floatval($new_heads[$index] ?? 1);
            $sub_total = floatval($new_sub_totals[$index] ?? 0);
            
            $insert_item_stmt->bind_param(
                "sssddddddi",
                $bill['job_no'], $bill['emp_name'], $part, $stitch, $round, $rate, $sub_total, $sub_total, $head, $company_id
            );
            if (!$insert_item_stmt->execute()) {
                throw new Exception("Failed to insert new item: " . $insert_item_stmt->error);
            }
        }
        $insert_item_stmt->close();
        
        // Update bill totals in stitching_posted_bills (STITCHING POSTED BILL BHI EDIT HOGA)
        $auto_total = 0;
        $manual_total = $total_amount;
        $difference_total = $auto_total - $manual_total;
        
        $update_bill_stmt = $conn->prepare("UPDATE stitching_posted_bills SET 
            claim_date = ?, total_amount = ?, manual_total = ?, auto_total = ?, difference_total = ?
            WHERE id = ? AND company_id = ?");
        $update_bill_stmt->bind_param(
            "sddddii",
            $billing_date, $total_amount, $manual_total, $auto_total, $difference_total,
            $bill_id, $company_id
        );
        if (!$update_bill_stmt->execute()) {
            throw new Exception("Failed to update bill: " . $update_bill_stmt->error);
        }
        $update_bill_stmt->close();
        
        $conn->commit();
        echo "<script>
                alert('Bill updated successfully!');
                window.location.href = 'list.php?id=$bill_id';
              </script>";
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error: " . $e->getMessage();
    }
}

function formatCurrency($value) {
    return 'Rs. ' . number_format($value ?? 0, 2);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Embroidery Bill #<?= $bill_id ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #F39C12; --primary-light: #FEF5E7; --primary-dark: #B26000; --text-dark: #2C3E50; --border: #E5E7E9; --bg-light: #F8F9F9; --success: #27ae60; --danger: #e74c3c; --info: #17a2b8; --warning: #ffc107; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background-color: #f5f5f5; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: var(--text-dark); }
        .main-container { margin-left: 14%; padding: 20px 24px; min-height: 100vh; transition: margin-left 0.3s ease; }
        h1 { font-size: 1.6rem; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; color: var(--text-dark); }
        h1 i { color: var(--primary); }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
        .card { background: white; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
        .card-header { background: white; padding: 14px 20px; border-bottom: 2px solid var(--primary); font-size: 1rem; font-weight: 600; color: var(--text-dark); display: flex; align-items: center; gap: 8px; }
        .card-header i { color: var(--primary); }
        .card-body { padding: 20px; }
        .info-box { background: var(--bg-light); border: 1px solid var(--border); border-radius: 10px; padding: 15px; margin-bottom: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; }
        .info-item { display: flex; flex-direction: column; }
        .info-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
        .info-value { font-size: 0.95rem; font-weight: 600; color: var(--text-dark); margin-top: 4px; }
        .form-label { font-weight: 600; color: var(--text-dark); margin-bottom: 5px; font-size: 0.85rem; }
        .form-control { border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; font-size: 0.9rem; width: 100%; transition: all 0.2s; }
        .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(243,156,18,0.1); }
        .form-control[readonly] { background: var(--bg-light); }
        .btn { padding: 8px 18px; font-size: 0.85rem; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #219a52; transform: translateY(-1px); }
        .btn-secondary { background: #e9ecef; color: var(--text-dark); }
        .btn-secondary:hover { background: #dee2e6; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-info { background: var(--info); color: white; }
        .btn-sm { padding: 4px 10px; font-size: 0.75rem; }
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 700px; }
        .table th { background: var(--bg-light); padding: 10px 8px; border-bottom: 2px solid var(--primary); font-weight: 600; white-space: nowrap; }
        .table td { padding: 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table input { width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; }
        .table-info { background: var(--primary-light) !important; }
        .grand-total { font-size: 1rem; font-weight: 700; color: var(--success); }
        .action-buttons { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .add-row-btn { background: var(--primary); color: white; border: none; border-radius: 8px; padding: 8px 16px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; margin: 12px 0; transition: all 0.2s; }
        .add-row-btn:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .remove-row { background: var(--danger); color: white; border: none; border-radius: 6px; padding: 5px 10px; font-size: 0.75rem; cursor: pointer; transition: all 0.2s; }
        .remove-row:hover { background: #c82333; transform: scale(1.05); }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .badge-warning { background: var(--warning); color: #333; }
        @media (max-width: 1200px) { .main-container { margin-left: 10%; padding: 16px 20px; } }
        @media (max-width: 992px) { .main-container { margin-left: 0; padding: 14px 16px; margin-top: 15px; } h1 { font-size: 1.4rem; } .info-box { grid-template-columns: repeat(2, 1fr); gap: 12px; } }
        @media (max-width: 768px) { .main-container { padding: 12px; margin-top: 60px; } h1 { font-size: 1.2rem; } .card-header { padding: 12px 16px; } .card-body { padding: 16px; } .info-box { grid-template-columns: 1fr; gap: 10px; } .info-value { font-size: 0.9rem; } .table { font-size: 0.75rem; min-width: 600px; } .table th, .table td { padding: 6px; } .table input { padding: 4px 6px; font-size: 0.75rem; } .btn { padding: 6px 14px; font-size: 0.75rem; } .action-buttons { flex-direction: column; } .action-buttons .btn { width: 100%; justify-content: center; } .add-row-btn { width: 100%; justify-content: center; } }
        @media (max-width: 576px) { .main-container { padding: 8px; margin-top: 70px; } h1 { font-size: 1rem; } .info-label { font-size: 0.65rem; } .info-value { font-size: 0.85rem; } .table { min-width: 550px; font-size: 0.7rem; } .table th, .table td { padding: 4px; } }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <h1>
        <i class="fas fa-edit"></i>
        Edit Embroidery Bill #<?= str_pad($bill_id, 5, '0', STR_PAD_LEFT) ?>
    </h1>

    <?php if (isset($error_msg)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($bill['status'] == 'un_posted'): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Note:</strong> This bill is in <strong>Un-Posted</strong> status and can be edited.
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-info-circle"></i>
            Bill Information
        </div>
        <div class="card-body">
            <div class="info-box">
                <div class="info-item">
                    <span class="info-label">Job No</span>
                    <span class="info-value"><?= htmlspecialchars($bill['job_no']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Vendor</span>
                    <span class="info-value"><?= htmlspecialchars($bill['emp_name'] ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Design</span>
                    <span class="info-value"><?= htmlspecialchars($bill['design_name'] ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="badge badge-warning">Un-Posted</span>
                    </span>
                </div>
            </div>

            <form method="POST" id="editForm">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Bill Date</label>
                        <input type="date" name="billing_date" class="form-control" value="<?= $bill['claim_date'] ?>" required>
                    </div>
                </div>

                <h5 class="mb-3">Bill Items</h5>
                
                <div class="table-responsive">
                    <table class="table table-bordered" id="itemsTable">
                        <thead>
                            <tr>
                                <th>Part</th>
                                <th>Stitches</th>
                                <th>Rate/1000</th>
                                <th>Rounds</th>
                                <th>Head</th>
                                <th>Sub Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <?php 
                            $total = 0;
                            foreach ($items as $item): 
                                $total += $item['sub_total'];
                            ?>
                            <tr class="item-row">
                                <td>
                                    <input type="hidden" name="item_id[]" value="<?= $item['id'] ?>">
                                    <input type="text" name="part_name[]" class="form-control" value="<?= htmlspecialchars($item['part_name']) ?>" required>
                                </td>
                                <td>
                                    <input type="number" name="stitch_round[]" class="form-control stitch-round" value="<?= $item['stitch'] ?>" step="1" min="0" oninput="calculateRow(this)">
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="rate[]" class="form-control rate" value="<?= $item['rate'] ?>" min="0" oninput="calculateRow(this)">
                                </td>
                                <td>
                                    <input type="number" name="round[]" class="form-control round" value="<?= $item['round_qty'] ?>" step="any" min="1" oninput="calculateRow(this)">
                                </td>
                                <td>
                                    <input type="number" name="head[]" class="form-control head" value="<?= $item['head'] ?>" step="1" min="1" oninput="calculateRow(this)">
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="sub_total[]" class="form-control sub-total" value="<?= $item['sub_total'] ?>" readonly>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="remove-row" onclick="removeRow(this)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tbody id="newItemsBody"></tbody>
                        <tfoot>
                            <tr class="table-info">
                                <td colspan="6" class="text-end fw-bold">Grand Total:</td>
                                <td class="fw-bold grand-total" id="grandTotal"><?= formatCurrency($total) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <button type="button" class="add-row-btn" onclick="addNewRow()">
                    <i class="fas fa-plus"></i> Add New Row
                </button>

                <div class="action-buttons">
                    <button type="submit" name="update" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Bill
                    </button>
                    <a href="list.php?id=<?= $bill_id ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <a href="list.php" class="btn btn-info">
                        <i class="fas fa-list"></i> Back to List
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function calculateRow(element) {
    const row = element.closest('tr');
    const stitches = parseFloat(row.querySelector('.stitch-round').value) || 0;
    const rate = parseFloat(row.querySelector('.rate').value) || 0;
    const rounds = parseFloat(row.querySelector('.round').value) || 1;
    const head = parseFloat(row.querySelector('.head').value) || 1;
    
    const subTotal = (stitches / 1000) * rate * head * rounds;
    row.querySelector('.sub-total').value = subTotal.toFixed(2);
    
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let total = 0;
    document.querySelectorAll('.sub-total').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.getElementById('grandTotal').textContent = 'Rs. ' + total.toFixed(2);
}

function addNewRow() {
    const tbody = document.getElementById('newItemsBody');
    const newRow = document.createElement('tr');
    newRow.className = 'item-row';
    newRow.innerHTML = `
        <td>
            <input type="text" name="new_part_name[]" class="form-control" placeholder="Part name" required>
        </td>
        <td>
            <input type="number" name="new_stitch_round[]" class="form-control stitch-round" value="0" step="1" min="0" oninput="calculateRow(this)">
        </td>
        <td>
            <input type="number" step="0.01" name="new_rate[]" class="form-control rate" value="0.00" min="0" oninput="calculateRow(this)">
        </td>
        <td>
            <input type="number" name="new_round[]" class="form-control round" value="1" step="1" min="1" oninput="calculateRow(this)">
        </td>
        <td>
            <input type="number" name="new_head[]" class="form-control head" value="1" step="1" min="1" oninput="calculateRow(this)">
        </td>
        <td>
            <input type="number" step="0.01" name="new_sub_total[]" class="form-control sub-total" readonly value="0.00">
        </td>
        <td class="text-center">
            <button type="button" class="remove-row" onclick="removeRow(this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(newRow);
}

function removeRow(button) {
    const row = button.closest('tr');
    const itemsBody = document.getElementById('itemsBody');
    
    if (row.parentNode.id === 'itemsBody' && itemsBody.children.length === 1) {
        alert('At least one original row must remain. You can clear values instead.');
        return;
    }
    
    row.remove();
    calculateGrandTotal();
}

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('stitch-round') || 
        e.target.classList.contains('rate') || 
        e.target.classList.contains('round') || 
        e.target.classList.contains('head')) {
        calculateRow(e.target);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    calculateGrandTotal();
});
</script>
</body>
</html>