<?php
$page_identifier = 'stitching/edit_fabric_issue.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: _list.php");
    exit;
}

// Fetch issue details
$issue_query = mysqli_query($conn, "SELECT fi.*, j.design_name, j.size, j.quantity 
                                    FROM fabric_issue fi
                                    LEFT JOIN jobs j ON fi.job_no = j.job_no
                                    WHERE fi.id = $id");
$issue = mysqli_fetch_assoc($issue_query);

if (!$issue) {
    header("Location:list.php");
    exit;
}

// Handle update
if (isset($_POST['update'])) {
    $job_no = trim($_POST['job_no'] ?? '');
    $lot = trim($_POST['lot_no'] ?? '');
    $fabric = trim($_POST['fabric_name'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $emb = floatval($_POST['emb_issue'] ?? 0);
    $back = floatval($_POST['back_issue'] ?? 0);
    $extra = floatval($_POST['extra_issue'] ?? 0);
    $rate = floatval($_POST['adjust_rate'] ?? 0);
    $color_number = floatval($_POST['color_number'] ?? 1);

    $total_issue = $emb + $back + $extra;
    $total_meter_with_color = $total_issue * $color_number;

    if ($job_no === "" || $lot === "") {
        $error = "Job No and Lot No are required.";
    } elseif ($total_issue <= 0) {
        $error = "Issue quantity must be greater than 0.";
    } else {
        $job_no_safe = mysqli_real_escape_string($conn, $job_no);
        $lot_safe = mysqli_real_escape_string($conn, $lot);

        // Update fabric_issue record
        $update_issue = "UPDATE fabric_issue
                        SET job_no = '$job_no_safe',
                            lot_no = '$lot_safe',
                            fabric_name = '" . mysqli_real_escape_string($conn, $fabric) . "',
                            color = '" . mysqli_real_escape_string($conn, $color) . "',
                            emb_issue = $emb,
                            back_issue = $back,
                            extra_issue = $extra,
                            adjust_rate = $rate,
                            color_number = $color_number,
                            total_meter_with_color = $total_meter_with_color
                        WHERE id = $id";

        if (mysqli_query($conn, $update_issue)) {
            $success = "Fabric issue updated successfully!";
            echo "<script>
                setTimeout(() => {
                    window.location.href = 'list.php';
                }, );
            </script>";
            
            // Refresh issue data
            $issue_query = mysqli_query($conn, "SELECT fi.*, j.design_name, j.size, j.quantity 
                                                FROM fabric_issue fi
                                                LEFT JOIN jobs j ON fi.job_no = j.job_no
                                                WHERE fi.id = $id");
            $issue = mysqli_fetch_assoc($issue_query);
        } else {
            $error = "Update failed: " . mysqli_error($conn);
        }
    }
}

// Fetch jobs for dropdown
$jobs_data = mysqli_query($conn, "SELECT job_no, design_name FROM jobs ORDER BY job_no ASC");
$jobs_list = [];
while ($row = mysqli_fetch_assoc($jobs_data)) {
    $jobs_list[] = $row;
}

// Fetch lots for dropdown
$lots_data = mysqli_query($conn, "SELECT lot_no, fabric_name, color, adjust_rate FROM fabric_purchase ORDER BY id DESC");
$lots_list = [];
while ($row = mysqli_fetch_assoc($lots_data)) {
    $lots_list[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Fabric Issue #<?php echo $id; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Simple Minimal Theme */
    :root {
        --primary: #F39C12;
        --border: #e0e0e0;
        --bg-light: #f8f9fa;
        --text-dark: #2c3e50;
    }

    body {
        background-color: #f5f5f5;
        font-family: 'Segoe UI', system-ui, sans-serif;
        color: var(--text-dark);
        margin: 0;
        padding: 0;
    }

    .container {
        max-width: 1000px;
        margin-left: 14%;
        padding: 20px;
    }

    h4 {
        color: var(--primary);
        font-size: 1.8rem;
        font-weight: 600;
        margin-bottom: 25px;
        border-bottom: 2px solid var(--primary);
        padding-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 25px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 5px;
    }

    label i {
        color: var(--primary);
        width: 18px;
        margin-right: 4px;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid var(--border);
        border-radius: 6px;
        font-size: 0.95rem;
        transition: all 0.2s;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(243,156,18,0.1);
    }

    .form-control[readonly] {
        background: var(--bg-light);
        border-color: var(--border);
    }

    .row {
        display: flex;
        flex-wrap: wrap;
        margin: -8px;
    }

    .col-md-3, .col-md-4, .col-md-6 {
        padding: 8px;
    }

    .col-md-3 { width: 25%; }
    .col-md-4 { width: 33.333%; }
    .col-md-6 { width: 50%; }

    .btn {
        padding: 12px 28px;
        font-size: 1rem;
        font-weight: 600;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: #e08e0b;
    }

    .btn-secondary {
        background: #e9ecef;
        color: var(--text-dark);
    }

    .btn-secondary:hover {
        background: #dee2e6;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .alert-danger {
        background: #ffebee;
        border: 1px solid #ffcdd2;
        color: #c62828;
    }

    .alert-success {
        background: #e8f5e9;
        border: 1px solid #c8e6c9;
        color: #2e7d32;
    }

    .info-box {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
        display: flex;
        gap: 25px;
        flex-wrap: wrap;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 0.8rem;
        color: #666;
    }

    .info-value {
        font-size: 1.2rem;
        font-weight: 600;
        color: var(--primary);
    }

    .total-box {
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 15px;
        margin: 15px 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 600;
    }

    .total-label {
        color: #666;
    }

    .total-value {
        color: var(--primary);
        font-size: 1.3rem;
    }

    @media (max-width: 768px) {
        .container { margin-left: 0; }
        .col-md-3, .col-md-4, .col-md-6 { width: 100%; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="container">
    <h4>
        <i class="fas fa-edit"></i>
        Edit Fabric Issue #<?php echo $id; ?>
    </h4>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <!-- Job Info -->
        <div class="info-box">
            <div class="info-item">
                <span class="info-label">Design</span>
                <span class="info-value"><?php echo htmlspecialchars($issue['design_name'] ?? '-'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Size</span>
                <span class="info-value"><?php echo htmlspecialchars($issue['size'] ?? '-'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Qty</span>
                <span class="info-value"><?php echo htmlspecialchars($issue['quantity'] ?? '-'); ?></span>
            </div>
        </div>

        <form method="POST">
            <!-- Job & Lot Selection -->
            <div class="row">
                <div class="col-md-6">
                    <label><i class="fas fa-hashtag"></i> Job No</label>
                    <select name="job_no" class="form-select" required>
                        <option value="">Select Job</option>
                        <?php foreach ($jobs_list as $job): ?>
                            <option value="<?php echo $job['job_no']; ?>" <?php echo ($job['job_no'] == $issue['job_no']) ? 'selected' : ''; ?>>
                                <?php echo $job['job_no']; ?> - <?php echo htmlspecialchars($job['design_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label><i class="fas fa-layer-group"></i> Lot No</label>
                    <select name="lot_no" class="form-select" required>
                        <option value="">Select Lot</option>
                        <?php foreach ($lots_list as $lot): ?>
                            <option value="<?php echo $lot['lot_no']; ?>" <?php echo ($lot['lot_no'] == $issue['lot_no']) ? 'selected' : ''; ?>>
                                <?php echo $lot['lot_no']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Fabric Details -->
            <div class="row mt-3">
                <div class="col-md-4">
                    <label><i class="fas fa-tshirt"></i> Fabric</label>
                    <input type="text" name="fabric_name" class="form-control" value="<?php echo htmlspecialchars($issue['fabric_name']); ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label><i class="fas fa-palette"></i> Color</label>
                    <input type="text" name="color" class="form-control" value="<?php echo htmlspecialchars($issue['color']); ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label><i class="fas fa-tag"></i> Rate (Rs.)</label>
                    <input type="number" step="0.25" name="adjust_rate" class="form-control" value="<?php echo $issue['adjust_rate']; ?>" readonly>
                </div>
            </div>

            <!-- Issue Quantities -->
            <div class="row mt-3">
                <div class="col-md-3">
                    <label><i class="fas fa-print"></i> Emb</label>
                    <input type="number" step="0.25" name="emb_issue" class="form-control" value="<?php echo $issue['emb_issue']; ?>" required>
                </div>

                <div class="col-md-3">
                    <label><i class="fas fa-print"></i> Back</label>
                    <input type="number" step="0.25" name="back_issue" class="form-control" value="<?php echo $issue['back_issue']; ?>" required>
                </div>

                <div class="col-md-3">
                    <label><i class="fas fa-plus"></i> Extra</label>
                    <input type="number" step="0.25" name="extra_issue" class="form-control" value="<?php echo $issue['extra_issue']; ?>" required>
                </div>

                <div class="col-md-3">
                    <label><i class="fas fa-hashtag"></i> Color #</label>
                    <input type="number" step="0.25" name="color_number" class="form-control" value="<?php echo $issue['color_number'] ?? 1; ?>" required>
                </div>
            </div>

            <!-- Total -->
            <?php 
            $total = ($issue['emb_issue'] + $issue['back_issue'] + $issue['extra_issue']) * ($issue['color_number'] ?? 1);
            $amount = $total * $issue['adjust_rate'];
            ?>
            <div class="total-box">
                <span class="total-label"><i class="fas fa-calculator"></i> Total Meter:</span>
                <span class="total-value"><?php echo number_format($total, 2); ?> m</span>
            </div>

            <div class="total-box">
                <span class="total-label"><i class="fas fa-rupee-sign"></i> Total Amount:</span>
                <span class="total-value">Rs. <?php echo number_format($amount, 2); ?></span>
            </div>

            <!-- Buttons -->
            <div class="mt-4 d-flex gap-2">
                <button type="submit" name="update" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update
                </button>
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Calculate total on input change
document.querySelectorAll('input[name="emb_issue"], input[name="back_issue"], input[name="extra_issue"], input[name="color_number"]').forEach(input => {
    input.addEventListener('input', function() {
        const emb = parseFloat(document.querySelector('input[name="emb_issue"]').value) || 0;
        const back = parseFloat(document.querySelector('input[name="back_issue"]').value) || 0;
        const extra = parseFloat(document.querySelector('input[name="extra_issue"]').value) || 0;
        const colorNum = parseFloat(document.querySelector('input[name="color_number"]').value) || 1;
        const rate = parseFloat(document.querySelector('input[name="adjust_rate"]').value) || 0;
        
        const total = (emb + back + extra) * colorNum;
        const amount = total * rate;
        
        document.querySelector('.total-value').textContent = total.toFixed(2) + ' m';
        document.querySelectorAll('.total-value')[1].textContent = 'Rs. ' + amount.toFixed(2);
    });
});
</script>

</body>
</html>