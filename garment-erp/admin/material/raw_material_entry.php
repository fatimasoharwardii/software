<?php
$page_identifier = 'material/raw_material_entry.php';

require_once "../../config/db.php";
require_once "../../includes/functions.php";
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];

// Ensure company_id columns exist in required tables
$tables = ['raw_material_entries', 'materials', 'parties', 'accounts', 'ledger_transactions', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $conn->query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL");
    $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
    $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
}

$conn->query("ALTER TABLE raw_material_entries ADD COLUMN IF NOT EXISTS description TEXT");
$conn->query("ALTER TABLE raw_material_entries ADD COLUMN IF NOT EXISTS bill_no VARCHAR(100) DEFAULT NULL AFTER description");
$conn->query("ALTER TABLE raw_material_entries ADD COLUMN IF NOT EXISTS updated_at INT DEFAULT NULL");

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_entries'])) {
    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $entry_date  = $_POST['entry_date'];
    $bill_no     = trim($_POST['bill_no'] ?? '');
    $material_inputs = $_POST['material_input'] ?? [];
    $rates       = $_POST['rate'] ?? [];
    $qtys        = $_POST['qty'] ?? [];
    $descriptions= $_POST['description'] ?? [];

    if (empty($vendor_name)) {
        $error = "Please select/enter a vendor name.";
    } else {
        $vendor_stmt = $conn->prepare("SELECT id FROM parties WHERE party_name = ? AND company_id = ?");
        $vendor_stmt->bind_param("si", $vendor_name, $company_id);
        $vendor_stmt->execute();
        $vendor = $vendor_stmt->get_result()->fetch_assoc();
        if (!$vendor) {
            $error = "Vendor '$vendor_name' does not exist. Add it first.";
        } else {
            $vendor_id = $vendor['id'];
        }
    }

    $total_amount = 0;
    $valid_rows = [];
    if (empty($error) && !empty($material_inputs)) {
        for ($i = 0; $i < count($material_inputs); $i++) {
            $material_input = trim($material_inputs[$i]);
            if (empty($material_input)) continue;
            $rate = floatval($rates[$i]);
            $qty  = floatval($qtys[$i]);
            if ($rate <= 0 || $qty <= 0) {
                $error = "Rate and Quantity must be > 0.";
                break;
            }
            $amount = $rate * $qty;
            $total_amount += $amount;
            $description = trim($descriptions[$i] ?? '');

            if (is_numeric($material_input)) {
                $material_id = (int)$material_input;
                $mat_check = $conn->prepare("SELECT id, material_name FROM materials WHERE id = ? AND company_id = ?");
                $mat_check->bind_param("ii", $material_id, $company_id);
                $mat_check->execute();
                $mat_row = $mat_check->get_result()->fetch_assoc();
                if (!$mat_row) {
                    $error = "Selected material not found.";
                    break;
                }
                $material_name = $mat_row['material_name'];
            } else {
                $mat_insert = $conn->prepare("INSERT INTO materials (material_name, company_id) VALUES (?, ?)");
                $mat_insert->bind_param("si", $material_input, $company_id);
                $mat_insert->execute();
                $material_id   = $conn->insert_id;
                $material_name = $material_input;
            }
            $valid_rows[] = compact('material_id','material_name','rate','qty','amount','description');
        }
    }

    if (empty($error) && empty($valid_rows)) {
        $error = "At least one valid material row is required.";
    }

    if (empty($error)) {
        $conn->begin_transaction();
        try {
            // ---------- INSERT EACH ROW SEPARATELY (NO MERGE) ----------
            $insert_raw = $conn->prepare("INSERT INTO raw_material_entries 
                (vendor_id, material_id, rate, qty, amount, description, bill_no, entry_date, company_id, user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($valid_rows as $row) {
                $insert_raw->bind_param("iidddsssii",
                    $vendor_id, $row['material_id'], $row['rate'], $row['qty'], $row['amount'],
                    $row['description'], $bill_no, $entry_date, $company_id, $user_id);
                $insert_raw->execute();
            }

            // ---------- STITCHING POSTED BILLS (MERGE if vendor+bill_no match) ----------
            $check_stitch = $conn->prepare("SELECT id FROM stitching_posted_bills 
                                            WHERE emp_name = ? AND bill_no = ? AND company_id = ? LIMIT 1");
            $update_stitch = $conn->prepare("UPDATE stitching_posted_bills SET 
                                             qty = qty + ?, total_amount = total_amount + ?,
                                             claim_item = CONCAT(claim_item, ', ', ?),
                                             description = CONCAT(IFNULL(description,''), ' | ', ?),
                                             updated_at = UNIX_TIMESTAMP()
                                             WHERE id = ?");
            $insert_stitch = $conn->prepare("INSERT INTO stitching_posted_bills 
                (claim_item, qty, rate, total_amount, description, claim_date, 
                 claim_type, emp_name, head, reference_id, reference_table,
                 bill_no, status, company_id, user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Raw Material', ?, 0, 0, 'raw_material_entries',
                        ?, 'un_Posted', ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");

            $stitch_id = null;
            if (!empty($bill_no)) {
                $check_stitch->bind_param("ssi", $vendor_name, $bill_no, $company_id);
                $check_stitch->execute();
                $res = $check_stitch->get_result();
                if ($res->num_rows > 0) {
                    $stitch_id = $res->fetch_assoc()['id'];
                }
                $check_stitch->close();
            }

            foreach ($valid_rows as $row) {
                if ($stitch_id) {
                    $update_stitch->bind_param("ddssi",
                        $row['qty'], $row['amount'], $row['material_name'], $row['description'], $stitch_id);
                    $update_stitch->execute();
                } else {
                    $insert_stitch->bind_param("sdddssssii",
                        $row['material_name'], $row['qty'], $row['rate'], $row['amount'],
                        $row['description'], $entry_date, $vendor_name, $bill_no, $company_id, $user_id);
                    $insert_stitch->execute();
                    $stitch_id = $conn->insert_id;   // subsequent rows merge into this bill
                }
            }

            // Update vendor balances
            $update_party = $conn->prepare("UPDATE parties SET balance = balance + ? WHERE id = ? AND company_id = ?");
            $update_party->bind_param("dii", $total_amount, $vendor_id, $company_id);
            $update_party->execute();

            $acc_stmt = $conn->prepare("SELECT id FROM accounts WHERE account_name = ? AND company_id = ?");
            $acc_stmt->bind_param("si", $vendor_name, $company_id);
            $acc_stmt->execute();
            if ($vendor_account = $acc_stmt->get_result()->fetch_assoc()) {
                $update_acc = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND company_id = ?");
                $update_acc->bind_param("dii", $total_amount, $vendor_account['id'], $company_id);
                $update_acc->execute();
            }

            // Ledger
            $from_account = "Raw Material Purchase";
            $check_from = $conn->prepare("SELECT id FROM accounts WHERE account_name = ? AND company_id = ?");
            $check_from->bind_param("si", $from_account, $company_id);
            $check_from->execute();
            if ($check_from->get_result()->num_rows == 0) {
                $create_acc = $conn->prepare("INSERT INTO accounts (account_name, account_type, balance, company_id, user_id) VALUES (?, 'expense', 0, ?, ?)");
                $create_acc->bind_param("sii", $from_account, $company_id, $user_id);
                $create_acc->execute();
            }
            $trans_desc = "Raw material purchase – total Rs. " . number_format($total_amount, 2) . " from vendor $vendor_name";
            $trans_stmt = $conn->prepare("INSERT INTO ledger_transactions (date, from_account, to_account, amount, description, transaction_type, created_at, company_id, user_id) VALUES (?, ?, ?, ?, ?, 'purchase', NOW(), ?, ?)");
            $trans_stmt->bind_param("sssdsii", $entry_date, $from_account, $vendor_name, $total_amount, $trans_desc, $company_id, $user_id);
            $trans_stmt->execute();

            $conn->commit();
            $success = "Entries saved successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}

$vendors = $conn->query("SELECT id, party_name FROM parties WHERE company_id = $company_id ORDER BY party_name");
$materials = $conn->query("SELECT id, material_name FROM materials WHERE company_id = $company_id ORDER BY material_name");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Raw Material Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #F39C12; --primary-light: #FEF5E7; --primary-dark: #E67E22; --border: #E9ECEF; --success: #27ae60; --danger: #e74c3c; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); font-family: 'Segoe UI', system-ui, sans-serif; }
        .main-container { margin-left: 14%; padding: 28px 35px; min-height: 100vh; }
        h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 28px; display: flex; align-items: center; gap: 12px; border-bottom: 3px solid var(--primary); padding-bottom: 12px; color: #2C3E50; }
        h2 i { color: var(--primary); }
        .card { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
        .card-header { padding: 16px 20px; background: white; border-bottom: 2px solid var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; color: var(--primary-dark); }
        .card-header i { color: var(--primary); }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 1rem; }
        label { font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; display: block; color: #6c757d; }
        label i { color: var(--primary); width: 18px; }
        select, input { width: 100%; padding: 8px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.9rem; }
        select:focus, input:focus { border-color: var(--primary); outline: none; }
        .material-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .material-table th, .material-table td { border: 1px solid var(--border); padding: 10px; vertical-align: middle; }
        .material-table th { background: var(--primary-light); font-weight: 600; font-size: 0.85rem; }
        .btn-add-row { background: none; border: 2px dashed var(--primary); color: var(--primary); padding: 8px 20px; border-radius: 40px; cursor: pointer; font-weight: 600; font-size: 0.9rem; }
        .btn-add-row:hover { background: var(--primary-light); border-style: solid; }
        .btn-save { background: var(--success); color: white; border: none; padding: 10px 28px; border-radius: 40px; font-weight: 600; cursor: pointer; }
        .remove-row { color: var(--danger); cursor: pointer; text-align: center; }
        .grand-total { background: #f8f9fa; padding: 12px 20px; border-radius: 8px; font-weight: 700; font-size: 1.2rem; margin-top: 15px; }
        .grand-total span { color: var(--primary-dark); }
        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
        @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } .material-table { min-width: 700px; } .table-responsive { overflow-x: auto; } }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <h2><i class="fas fa-boxes"></i> Add Raw Material Entry</h2>
    <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-truck"></i> Vendor & Material Details</div>
        <div class="card-body">
            <form method="POST" id="materialForm">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Vendor Name *</label>
                            <input type="text" name="vendor_name" id="vendor_name" list="vendorList" class="form-control" required autocomplete="off" placeholder="Type vendor name...">
                            <datalist id="vendorList">
                                <?php while($v = $vendors->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($v['party_name']) ?>">
                                <?php endwhile; ?>
                            </datalist>
                            <small class="text-muted">Start typing to search. Vendor must exist in system.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Entry Date</label>
                            <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> Bill No.</label>
                            <input type="text" name="bill_no" class="form-control" placeholder="Enter bill number">
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="material-table" id="materialTable">
                        <thead>
                            <tr><th>Material</th><th>Description</th><th>Rate (Rs.)</th><th>Quantity</th><th>Amount (Rs.)</th><th></th></tr>
                        </thead>
                        <tbody id="materialBody"></tbody>
                    </table>
                </div>
                <div class="grand-total">
                    <i class="fas fa-calculator"></i> Grand Total: <span id="grandTotal">0.00</span> Rs.
                </div>
                <button type="button" class="btn-add-row mt-3" onclick="addMaterialRow()"><i class="fas fa-plus"></i> Add Row</button>
                <div class="text-end mt-3">
                    <button type="submit" name="save_entries" class="btn-save"><i class="fas fa-save"></i> Save Entries</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let materialOptions = [];
    <?php $materials->data_seek(0); while($m = $materials->fetch_assoc()): ?>
    materialOptions.push({id: <?= $m['id'] ?>, name: '<?= addslashes($m['material_name']) ?>'});
    <?php endwhile; ?>

    function renderMaterialCell(value, rowIndex) {
        let selectHtml = '<select class="material-select" name="material_input[]" style="width:100%;" onchange="handleMaterialChange(this, ' + rowIndex + ')">';
        selectHtml += '<option value="">-- Select Material --</option>';
        materialOptions.forEach(m => {
            let selected = (value == m.id) ? 'selected' : '';
            selectHtml += `<option value="${m.id}" ${selected}>${escapeHtml(m.name)}</option>`;
        });
        selectHtml += '<option value="__NEW__">+ Add New Material</option>';
        selectHtml += '</select>';
        return selectHtml;
    }

    function handleMaterialChange(selectEl, rowIndex) {
        if (selectEl.value === '__NEW__') {
            const td = selectEl.parentElement;
            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'material_input[]';
            input.placeholder = 'Enter new material name';
            input.className = 'form-control';
            input.style.width = '100%';
            td.innerHTML = '';
            td.appendChild(input);
            input.focus();
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m] || m);
    }

    function calculateRowAmount(inputEl) {
        const row = inputEl.closest('tr');
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const amount = rate * qty;
        row.querySelector('.amount-display').value = amount.toFixed(2);
        updateGrandTotal();
    }

    function updateGrandTotal() {
        let total = 0;
        document.querySelectorAll('.amount-display').forEach(inp => {
            total += parseFloat(inp.value) || 0;
        });
        document.getElementById('grandTotal').textContent = total.toFixed(2);
    }

    function removeRow(rowId) {
        const tbody = document.getElementById('materialBody');
        if (tbody.children.length > 1) {
            document.getElementById(rowId).remove();
            updateGrandTotal();
        } else {
            alert('At least one row must remain');
        }
    }

    let rowCounter = 0;
    function addMaterialRow(materialId = '', rate = '', qty = '', description = '') {
        const tbody = document.getElementById('materialBody');
        const rowId = 'row_' + Date.now() + '_' + rowCounter++;
        const tr = document.createElement('tr');
        tr.id = rowId;
        tr.innerHTML = `
            <td>${renderMaterialCell(materialId, rowCounter)}</td>
            <td><input type="text" name="description[]" class="description-input" value="${escapeHtml(description)}" style="width:100%;" placeholder="Optional notes"></td>
            <td><input type="number" step="0.01" name="rate[]" class="rate-input" value="${rate}" style="width:100%;" oninput="calculateRowAmount(this)"></td>
            <td><input type="number" step="0.01" name="qty[]" class="qty-input" value="${qty}" style="width:100%;" oninput="calculateRowAmount(this)"></td>
            <td><input type="text" name="amount[]" class="amount-display" readonly style="background:#e8f5e9; width:100%;" value="0.00"></td>
            <td class="remove-row" onclick="removeRow('${rowId}')"><i class="fas fa-trash"></i></td>
        `;
        tbody.appendChild(tr);
        if (materialId) calculateRowAmount(tr.querySelector('.rate-input'));
    }

    document.addEventListener('DOMContentLoaded', () => {
        addMaterialRow();
    });

    document.getElementById('materialForm').addEventListener('submit', function(e) {
        const vendorName = document.getElementById('vendor_name').value.trim();
        if (!vendorName) {
            e.preventDefault();
            alert('Please enter a vendor name');
            return false;
        }
        const options = document.getElementById('vendorList').options;
        let exists = false;
        for (let i = 0; i < options.length; i++) {
            if (options[i].value === vendorName) {
                exists = true;
                break;
            }
        }
        if (!exists) {
            e.preventDefault();
            alert('Vendor not found! Please add the vendor first in Parties section.');
            return false;
        }
        return true;
    });
</script>
</body>
</html>