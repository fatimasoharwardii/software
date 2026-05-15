<?php
$page_identifier = 'stitching/edit_material.php';
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
$tables = ['stitching_bill_items', 'jobs', 'stitching_posted_bills'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// ----- Get entry ID -----
$entry_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$entry_id) {
    die("Invalid entry ID.");
}

// ----- Fetch the material entry with company check -----
$fetch_stmt = $conn->prepare("SELECT * FROM stitching_bill_items WHERE id = ? AND tab_type = 'material' AND company_id = ?");
$fetch_stmt->bind_param("ii", $entry_id, $company_id);
$fetch_stmt->execute();
$entry = $fetch_stmt->get_result()->fetch_assoc();
$fetch_stmt->close();

if (!$entry) {
    die("Material entry not found or does not belong to your company.");
}
$job_no = $entry['job_no'];
$current_name = $entry['name'];

// Verify job belongs to the same company (optional but recommended)
$job_check = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
$job_check->bind_param("si", $job_no, $company_id);
$job_check->execute();
if ($job_check->get_result()->num_rows == 0) {
    die("Job does not belong to your company.");
}
$job_check->close();

// ----- Handle form submission (update) -----
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_material'])) {
    $new_name = trim($_POST['name'] ?? '');
    $new_qty = floatval($_POST['qty'] ?? 0);
    $new_rate = floatval($_POST['rate'] ?? 0);
    $new_amount = $new_qty * $new_rate;
    $new_bill_no = trim($_POST['bill_no'] ?? '');

    if (empty($new_name) || $new_qty <= 0) {
        $error = "Item name and quantity are required.";
    } else {
        $conn->begin_transaction();
        try {
            // Update the item (with company check)
            $update_stmt = $conn->prepare("UPDATE stitching_bill_items 
                SET name = ?, qty = ?, rate = ?, amount = ?, bill_no = ?
                WHERE id = ? AND tab_type = 'material' AND company_id = ?");
            $update_stmt->bind_param("sdddsii", $new_name, $new_qty, $new_rate, $new_amount, $new_bill_no, $entry_id, $company_id);
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update item: " . $update_stmt->error);
            }
            $update_stmt->close();

            // Get job details for posted bill update
            $job_stmt = $conn->prepare("SELECT serial_no, design_name, fabric_name, size, brand_name FROM jobs WHERE job_no = ? AND company_id = ?");
            $job_stmt->bind_param("si", $job_no, $company_id);
            $job_stmt->execute();
            $job = $job_stmt->get_result()->fetch_assoc();
            $job_stmt->close();

            // Calculate total for this vendor (item name) + job_no + tab_type='material'
            $vendor = $new_name;
            $total_stmt = $conn->prepare("SELECT SUM(amount) AS total, SUM(qty) AS total_qty 
                                         FROM stitching_bill_items 
                                         WHERE job_no = ? AND name = ? AND tab_type = 'material' AND company_id = ?");
            $total_stmt->bind_param("ssi", $job_no, $vendor, $company_id);
            $total_stmt->execute();
            $total_r = $total_stmt->get_result()->fetch_assoc();
            $total_stmt->close();

            $total_amount = $total_r['total'] ?? 0;
            $total_qty = $total_r['total_qty'] ?? 0;
            $avg_rate = ($total_qty > 0) ? ($total_amount / $total_qty) : 0;

            $claim_type = 'Material Bill';
            $claim_item = 'Material Charges';

            // Check if a posted bill already exists for this vendor and claim_type (with company check)
            $check_bill = $conn->prepare("SELECT id FROM stitching_posted_bills 
                                          WHERE job_no = ? AND emp_name = ? AND claim_type = ? AND company_id = ?");
            $check_bill->bind_param("sssi", $job_no, $vendor, $claim_type, $company_id);
            $check_bill->execute();
            $bill_exists = $check_bill->get_result()->num_rows > 0;
            $check_bill->close();

            if ($bill_exists) {
                // Update existing posted bill
                $update_bill = $conn->prepare("UPDATE stitching_posted_bills SET 
                    total_amount = ?, qty = ?, rate = ?,
                    description = ?, design_name = ?, fabric_name = ?, size = ?, brand_name = ?,
                    status = 'pending', post_date = CURDATE(), bill_no = ?
                    WHERE job_no = ? AND emp_name = ? AND claim_type = ? AND company_id = ?");
                $desc = "Material bill for job $job_no - Item: $vendor";
                $update_bill->bind_param("ddssssssssssi", 
                    $total_amount, $total_qty, $avg_rate, $desc,
                    $job['design_name'] ?? '', $job['fabric_name'] ?? '', $job['size'] ?? '', $job['brand_name'] ?? '',
                    $new_bill_no, $job_no, $vendor, $claim_type, $company_id);
                if (!$update_bill->execute()) {
                    throw new Exception("Failed to update posted bill: " . $update_bill->error);
                }
                $update_bill->close();
            } else {
                // Insert new posted bill
                $insert_bill = $conn->prepare("INSERT INTO stitching_posted_bills 
                    (job_no, serial_no, emp_name, claim_item, qty, rate, total_amount, description, 
                     claim_type, claim_date, design_name, fabric_name, size, brand_name, status, 
                     manual_total, auto_total, difference_total, post_date, bill_no, company_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'pending', ?, 0, ?, CURDATE(), ?, ?)");
                $desc = "Material bill for job $job_no - Item: $vendor";
                $manual_total = $total_amount;
                $difference_total = $total_amount; // auto_total = 0
                $insert_bill->bind_param("ssssddsssssssdddssi", 
                    $job_no, $job['serial_no'] ?? '', $vendor, $claim_item, 
                    $total_qty, $avg_rate, $total_amount, $desc,
                    $claim_type,
                    $job['design_name'] ?? '', $job['fabric_name'] ?? '', $job['size'] ?? '', $job['brand_name'] ?? '',
                    $manual_total, $difference_total,
                    $new_bill_no, $company_id);
                if (!$insert_bill->execute()) {
                    throw new Exception("Failed to create posted bill: " . $insert_bill->error);
                }
                $insert_bill->close();
            }

            $conn->commit();
            $message = "Material entry updated successfully.";

            // Refresh entry data for display
            $refresh = $conn->prepare("SELECT * FROM stitching_bill_items WHERE id = ? AND tab_type = 'material' AND company_id = ?");
            $refresh->bind_param("ii", $entry_id, $company_id);
            $refresh->execute();
            $entry = $refresh->get_result()->fetch_assoc();
            $refresh->close();
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Material Entry</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f5f7fa;
            font-family: 'Segoe UI', sans-serif;
        }
        .main-container {
            margin-left: 14%;
            padding: 20px 24px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        .edit-container {
            max-width: 700px;
            margin: 30px auto;
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .form-label {
            font-weight: 600;
        }
        .btn-primary {
            background: #F39C12;
            border: none;
        }
        .btn-primary:hover {
            background: #E67E22;
        }
        .btn-danger {
            background: #e74c3c;
            border: none;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        @media (max-width: 992px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
                margin-top: 60px;
            }
            .edit-container {
                margin: 20px auto;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="edit-container">
        <h3 class="mb-4"><i class="fas fa-edit text-warning"></i> Edit Material Entry</h3>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="save_material" value="1">

            <div class="mb-3">
                <label class="form-label">Job No</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($entry['job_no']) ?>" disabled>
            </div>

            <div class="mb-3">
                <label class="form-label">Item Name</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($entry['name']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Quantity</label>
                <input type="number" name="qty" class="form-control" step="0.01" value="<?= htmlspecialchars($entry['qty']) ?>" required oninput="calcAmount()">
            </div>

            <div class="mb-3">
                <label class="form-label">Rate (Rs.)</label>
                <input type="number" name="rate" class="form-control" step="0.01" value="<?= htmlspecialchars($entry['rate']) ?>" required oninput="calcAmount()">
            </div>

            <div class="mb-3">
                <label class="form-label">Amount (auto‑calculated)</label>
                <input type="text" name="amount_display" class="form-control" id="amount_display" readonly style="background:#e8f5e9;" value="<?= number_format($entry['amount'], 2) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Bill No (optional)</label>
                <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($entry['bill_no'] ?? '') ?>">
            </div>

            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <a href="entry.php?left_job_no=<?= urlencode($job_no) ?>&tab=material" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    <a href="delete_material_entry.php?id=<?= $entry_id ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this material entry? This will also update the posted bill.')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function calcAmount() {
    let qty = parseFloat(document.querySelector('[name="qty"]').value) || 0;
    let rate = parseFloat(document.querySelector('[name="rate"]').value) || 0;
    document.getElementById('amount_display').value = (qty * rate).toFixed(2);
}
// initial calculation (already done by PHP value, but recalc in case)
calcAmount();
</script>
</body>
</html>