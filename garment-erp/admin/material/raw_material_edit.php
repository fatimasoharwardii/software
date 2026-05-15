<?php
$page_identifier = 'material/raw_material_edit.php';

require_once "../../config/db.php";
require_once "../../includes/functions.php";
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: raw_material_list.php");
    exit;
}

// Fetch current entry
$stmt = $conn->prepare("SELECT * FROM raw_material_entries WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
if (!$entry) {
    header("Location: raw_material_list.php");
    exit;
}

// Fetch vendors & materials
$vendors_stmt = $conn->prepare("SELECT id, party_name FROM parties WHERE company_id = ? ORDER BY party_name");
$vendors_stmt->bind_param("i", $company_id);
$vendors_stmt->execute();
$vendors = $vendors_stmt->get_result();

$materials_stmt = $conn->prepare("SELECT id, material_name FROM materials WHERE company_id = ? ORDER BY material_name");
$materials_stmt->bind_param("i", $company_id);
$materials_stmt->execute();
$materials = $materials_stmt->get_result();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $entry_date = $_POST['entry_date'];
    $vendor_id = (int)$_POST['vendor_id'];
    $material_input = trim($_POST['material_input']);
    $rate = (float)$_POST['rate'];
    $qty = (float)$_POST['qty'];
    $description = trim($_POST['description']);
    $bill_no = trim($_POST['bill_no'] ?? '');
    $amount = $rate * $qty;

    // Get new vendor name
    $vendor_stmt = $conn->prepare("SELECT party_name FROM parties WHERE id = ? AND company_id = ?");
    $vendor_stmt->bind_param("ii", $vendor_id, $company_id);
    $vendor_stmt->execute();
    $vendor = $vendor_stmt->get_result()->fetch_assoc();
    if (!$vendor) {
        $error = "Vendor not found.";
        goto show_form;
    }
    $vendor_name = $vendor['party_name'];

    // Material determination
    if (is_numeric($material_input)) {
        $material_id = (int)$material_input;
        $mat_check = $conn->prepare("SELECT id, material_name FROM materials WHERE id = ? AND company_id = ?");
        $mat_check->bind_param("ii", $material_id, $company_id);
        $mat_check->execute();
        if ($mat_check->get_result()->num_rows == 0) {
            $error = "Material not found.";
            goto show_form;
        }
        $mat_name_stmt = $conn->prepare("SELECT material_name FROM materials WHERE id = ? AND company_id = ?");
        $mat_name_stmt->bind_param("ii", $material_id, $company_id);
        $mat_name_stmt->execute();
        $material_name = $mat_name_stmt->get_result()->fetch_assoc()['material_name'];
    } else {
        $mat_insert = $conn->prepare("INSERT INTO materials (material_name, company_id) VALUES (?, ?)");
        $mat_insert->bind_param("si", $material_input, $company_id);
        $mat_insert->execute();
        $material_id = $conn->insert_id;
        $material_name = $material_input;
    }

    $conn->begin_transaction();
    try {
        $old = $entry;
        $old_amount = $old['amount'];
        $old_vendor_id = $old['vendor_id'];
        $old_bill_no = $old['bill_no'] ?? '';

        // Old vendor name
        $old_vn_stmt = $conn->prepare("SELECT party_name FROM parties WHERE id = ? AND company_id = ?");
        $old_vn_stmt->bind_param("ii", $old_vendor_id, $company_id);
        $old_vn_stmt->execute();
        $old_vendor_name = $old_vn_stmt->get_result()->fetch_assoc()['party_name'];

        // Reverse old balances from old vendor
        $rev_party = $conn->prepare("UPDATE parties SET balance = balance - ? WHERE id = ? AND company_id = ?");
        $rev_party->bind_param("dii", $old_amount, $old_vendor_id, $company_id);
        $rev_party->execute();

        $rev_acc = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE account_name = ? AND company_id = ?");
        $rev_acc->bind_param("dsi", $old_amount, $old_vendor_name, $company_id);
        $rev_acc->execute();

        // Apply new balances to new vendor
        $add_party = $conn->prepare("UPDATE parties SET balance = balance + ? WHERE id = ? AND company_id = ?");
        $add_party->bind_param("dii", $amount, $vendor_id, $company_id);
        $add_party->execute();

        $add_acc = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE account_name = ? AND company_id = ?");
        $add_acc->bind_param("dsi", $amount, $vendor_name, $company_id);
        $add_acc->execute();

        // Update raw material entry
        $upd = $conn->prepare("UPDATE raw_material_entries SET entry_date=?, vendor_id=?, material_id=?, rate=?, qty=?, amount=?, description=?, bill_no=? WHERE id=? AND company_id=?");
        $upd->bind_param("siidddssii", $entry_date, $vendor_id, $material_id, $rate, $qty, $amount, $description, $bill_no, $id, $company_id);
        $upd->execute();

        // ---------- Adjust stitching posted bills ----------
        // 1. Subtract old entry's values from old stitching bill
        $find_old_stitch = $conn->prepare("SELECT id FROM stitching_posted_bills 
                                           WHERE emp_name = ? AND bill_no = ? AND company_id = ? LIMIT 1");
        $find_old_stitch->bind_param("ssi", $old_vendor_name, $old_bill_no, $company_id);
        $find_old_stitch->execute();
        if ($old_stitch = $find_old_stitch->get_result()->fetch_assoc()) {
            $old_stitch_id = $old_stitch['id'];
            // Subtract old qty & amount (never go below zero)
            $sub = $conn->prepare("UPDATE stitching_posted_bills SET 
                                   qty = GREATEST(qty - ?, 0),
                                   total_amount = GREATEST(total_amount - ?, 0),
                                   updated_at = UNIX_TIMESTAMP()
                                   WHERE id = ? AND company_id = ?");
            $sub->bind_param("ddii", $old['qty'], $old_amount, $old_stitch_id, $company_id);
            $sub->execute();
        }

        // 2. Add new entry's values to new stitching bill (merge)
        $find_new_stitch = $conn->prepare("SELECT id FROM stitching_posted_bills 
                                           WHERE emp_name = ? AND bill_no = ? AND company_id = ? LIMIT 1");
        $find_new_stitch->bind_param("ssi", $vendor_name, $bill_no, $company_id);
        $find_new_stitch->execute();
        if ($new_stitch = $find_new_stitch->get_result()->fetch_assoc()) {
            $new_stitch_id = $new_stitch['id'];
            $add_stitch = $conn->prepare("UPDATE stitching_posted_bills SET 
                                          qty = qty + ?, total_amount = total_amount + ?,
                                          claim_item = CONCAT(claim_item, ', ', ?),
                                          description = CONCAT(IFNULL(description,''), ' | ', ?),
                                          updated_at = UNIX_TIMESTAMP()
                                          WHERE id = ? AND company_id = ?");
            $add_stitch->bind_param("ddssii", $qty, $amount, $material_name, $description, $new_stitch_id, $company_id);
            $add_stitch->execute();
        } else {
            // Create new stitching bill (edge case)
            $ins_stitch = $conn->prepare("INSERT INTO stitching_posted_bills 
                (claim_item, qty, rate, total_amount, description, claim_date, 
                 claim_type, emp_name, head, reference_id, reference_table,
                 bill_no, status, company_id, user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Raw Material', ?, 0, ?, 'raw_material_entries',
                        ?, 'un_Posted', ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");
            $ins_stitch->bind_param("sdddsssisii",
                $material_name, $qty, $rate, $amount, $description,
                $entry_date, $vendor_name, $id, $bill_no, $company_id, $user_id);
            $ins_stitch->execute();
        }

        // Ledger adjustment (remove old, add new)
        $old_desc = "Raw material purchase – total Rs. " . number_format($old_amount, 2) . "%";
        $del_ledger = $conn->prepare("DELETE FROM ledger_transactions WHERE description LIKE ? AND company_id = ? AND amount = ?");
        $del_ledger->bind_param("sid", $old_desc, $company_id, $old_amount);
        $del_ledger->execute();

        $from_account = "Raw Material Purchase";
        $check_from = $conn->prepare("SELECT id FROM accounts WHERE account_name = ? AND company_id = ?");
        $check_from->bind_param("si", $from_account, $company_id);
        $check_from->execute();
        if ($check_from->get_result()->num_rows == 0) {
            $create_acc = $conn->prepare("INSERT INTO accounts (account_name, account_type, balance, company_id, user_id) VALUES (?, 'expense', 0, ?, ?)");
            $create_acc->bind_param("sii", $from_account, $company_id, $user_id);
            $create_acc->execute();
        }
        $trans_desc = "Raw material purchase – total Rs. " . number_format($amount, 2) . " from vendor $vendor_name";
        $trans_stmt = $conn->prepare("INSERT INTO ledger_transactions (date, from_account, to_account, amount, description, transaction_type, created_at, company_id, user_id) VALUES (?, ?, ?, ?, ?, 'purchase', NOW(), ?, ?)");
        $trans_stmt->bind_param("sssdsii", $entry_date, $from_account, $vendor_name, $amount, $trans_desc, $company_id, $user_id);
        $trans_stmt->execute();

        $conn->commit();
        $success = "Entry updated successfully!";

        // Reload entry
        $stmt = $conn->prepare("SELECT * FROM raw_material_entries WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $id, $company_id);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error updating: " . $e->getMessage();
    }
}

show_form:
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Raw Material Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #F39C12; --primary-light: #FEF5E7; --primary-dark: #E67E22; --border: #E9ECEF; --success: #27ae60; --danger: #e74c3c; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); font-family: 'Segoe UI', sans-serif; }
        .main-container { margin-left: 14%; padding: 28px 35px; min-height: 100vh; }
        h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 28px; display: flex; align-items: center; gap: 12px; border-bottom: 3px solid var(--primary); padding-bottom: 12px; color: #2C3E50; }
        h2 i { color: var(--primary); }
        .card { background: white; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .card-header { padding: 16px 20px; background: white; border-bottom: 2px solid var(--primary); color: var(--primary-dark); font-weight: 700; }
        .card-body { padding: 24px; }
        .form-group { margin-bottom: 1.2rem; }
        label { font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; display: block; color: #6c757d; }
        label i { color: var(--primary); width: 18px; }
        input, select, textarea { width: 100%; padding: 8px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.9rem; }
        input:focus, select:focus, textarea:focus { border-color: var(--primary); outline: none; }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; border: none; cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #e9ecef; color: #2C3E50; }
        .action-buttons { margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap; }
        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
        @media (max-width: 992px) { .main-container { margin-left: 0; padding: 16px; margin-top: 60px; } }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <h2><i class="fas fa-edit"></i> Edit Raw Material Entry</h2>
    <?php if($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-pen"></i> Entry Details</div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Entry Date</label>
                            <input type="date" name="entry_date" value="<?= htmlspecialchars($entry['entry_date']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Vendor</label>
                            <select name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php while($v = $vendors->fetch_assoc()): ?>
                                <option value="<?= $v['id'] ?>" <?= $v['id'] == $entry['vendor_id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['party_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> Bill No.</label>
                            <input type="text" name="bill_no" value="<?= htmlspecialchars($entry['bill_no'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-box"></i> Material</label>
                            <input type="text" name="material_input" list="materialList" value="<?= htmlspecialchars($entry['material_id']) ?>" required>
                            <datalist id="materialList">
                                <?php $materials->data_seek(0); while($m = $materials->fetch_assoc()): ?>
                                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['material_name']) ?></option>
                                <?php endwhile; ?>
                            </datalist>
                            <small class="text-muted">Type existing ID or new material name</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Rate (Rs.)</label>
                            <input type="number" step="0.01" name="rate" value="<?= htmlspecialchars($entry['rate']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label><i class="fas fa-cubes"></i> Quantity</label>
                            <input type="number" step="0.01" name="qty" value="<?= htmlspecialchars($entry['qty']) ?>" required>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Description</label>
                    <textarea name="description" rows="3"><?= htmlspecialchars($entry['description'] ?? '') ?></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" name="update" class="btn btn-primary"><i class="fas fa-save"></i> Update Entry</button>
                    <a href="raw_material_list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>