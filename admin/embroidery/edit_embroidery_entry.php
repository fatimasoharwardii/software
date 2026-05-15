<?php
$page_identifier = 'embroidery/edit_embroidery_entry.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$can_edit = hasAccess($current_page, true);
$company_id = (int)$_SESSION['company_id'];

// Ensure required tables have company_id column
$tables = ['embroidery_entries', 'jobs', 'accounts'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: list.php");
    exit;
}

// Fetch embroidery entry details (with company isolation)
$stmt = $conn->prepare("SELECT * FROM embroidery_entries WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();

if (!$entry) {
    header("Location: list.php");
    exit;
}

// Fetch all jobs for suggestions (only current company)
$jobs_stmt = $conn->prepare("SELECT job_no FROM jobs WHERE company_id = ? ORDER BY job_no");
$jobs_stmt->bind_param("i", $company_id);
$jobs_stmt->execute();
$jobs_list = $jobs_stmt->get_result();

// Fetch operators from accounts table (only current company)
$operators_stmt = $conn->prepare("SELECT account_name, account_type, balance FROM accounts 
                                  WHERE account_type IN ('employee', 'vendor', 'customer') AND company_id = ? 
                                  ORDER BY account_name ASC");
$operators_stmt->bind_param("i", $company_id);
$operators_stmt->execute();
$operators = $operators_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch helpers
$helpers_stmt = $conn->prepare("SELECT account_name, account_type, balance FROM accounts 
                                WHERE account_type IN ('employee', 'vendor', 'customer') AND company_id = ? 
                                ORDER BY account_name ASC");
$helpers_stmt->bind_param("i", $company_id);
$helpers_stmt->execute();
$helpers = $helpers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle update
if (isset($_POST['update'])) {
    $entry_date = $_POST['entry_date'];
    $machine_id_raw = !empty($_POST['machine_id']) ? (int)$_POST['machine_id'] : null;
    $machine_no = $_POST['machine_no'];
    $shift = $_POST['shift'];
    $job_no = $_POST['job_no'];
    $design_no = $_POST['design_no'];
    $vendor_name = $_POST['vendor_name'];
    $part = $_POST['part'];
    
    $per_round = floatval($_POST['per_round']);
    $rounds = floatval($_POST['rounds']);
    $stitch_done = floatval($_POST['stitch_done']);
    
    // Auto‑calculate if stitch_done = 0 but per_round and rounds exist
    if ($stitch_done == 0 && $per_round > 0 && $rounds > 0) {
        $stitch_done = $per_round * $rounds;
    }
    
    $op_rate = floatval($_POST['op_rate']);
    $operator_name = $_POST['operator_name'];
    $helper_name = $_POST['helper_name'];
    
    // Verify that the job_no belongs to the current company
    $job_check = $conn->prepare("SELECT id FROM jobs WHERE job_no = ? AND company_id = ?");
    $job_check->bind_param("si", $job_no, $company_id);
    $job_check->execute();
    if ($job_check->get_result()->num_rows == 0) {
        $error = "Invalid job number – does not belong to your company.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Update embroidery_entries with company check
            if ($machine_id_raw === null) {
                $update_stmt = $conn->prepare("UPDATE embroidery_entries SET 
                    entry_date = ?, machine_id = NULL, machine_no = ?, shift = ?, job_no = ?, design_no = ?,
                    vendor_name = ?, part = ?, stitch_done = ?, per_round = ?, rounds = ?, op_rate = ?,
                    operator_name = ?, helper_name = ?
                    WHERE id = ? AND company_id = ?");
                $update_stmt->bind_param("sssssssddddsi", 
                    $entry_date, $machine_no, $shift, $job_no, $design_no,
                    $vendor_name, $part, $stitch_done, $per_round, $rounds, $op_rate,
                    $operator_name, $helper_name, $id, $company_id);
            } else {
                $update_stmt = $conn->prepare("UPDATE embroidery_entries SET 
                    entry_date = ?, machine_id = ?, machine_no = ?, shift = ?, job_no = ?, design_no = ?,
                    vendor_name = ?, part = ?, stitch_done = ?, per_round = ?, rounds = ?, op_rate = ?,
                    operator_name = ?, helper_name = ?
                    WHERE id = ? AND company_id = ?");
                // ✅ Fixed: type string now exactly matches 16 placeholders
                $update_stmt->bind_param("sissssssddddssii", 
                    $entry_date, $machine_id_raw, $machine_no, $shift, $job_no, $design_no,
                    $vendor_name, $part, $stitch_done, $per_round, $rounds, $op_rate,
                    $operator_name, $helper_name, $id, $company_id);
            }
            
            if (!$update_stmt->execute()) {
                throw new Exception("Error updating entry: " . $update_stmt->error);
            }
            
            $conn->commit();
            $success = "Entry updated successfully!";
            
            // Refresh entry data
            $refresh_stmt = $conn->prepare("SELECT * FROM embroidery_entries WHERE id = ? AND company_id = ?");
            $refresh_stmt->bind_param("ii", $id, $company_id);
            $refresh_stmt->execute();
            $entry = $refresh_stmt->get_result()->fetch_assoc();
            
            echo "<script>setTimeout(() => { window.location.href = 'list.php'; }, 1500);</script>";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Embroidery Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #F39C12;
            --primary-hover: #FFB347;
            --dark-bg: #1E1E1E;
            --light-bg: #F9F9F9;
            --border: #E0E0E0;
            --text-dark: #2C3E50;
            --text-light: #FFFFFF;
            --success: #28a745;
            --danger: #dc3545;
            --info: #17a2b8;
            --warning: #ffc107;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--light-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-dark);
        }

        /* Main container with 14% left margin */
        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h2 i {
            color: var(--primary);
        }

        /* Card */
        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .card-header {
            background: white;
            padding: 16px 20px;
            border-bottom: 2px solid var(--primary);
        }

        .card-header h4 {
            color: var(--primary);
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-body {
            padding: 24px;
        }

        /* Form Elements */
        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .form-label i {
            color: var(--primary);
            margin-right: 6px;
            width: 18px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        .form-control[readonly] {
            background: var(--bg-light);
            border-color: var(--border);
        }

        /* Row layout */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: -8px;
        }

        .col-md-3, .col-md-4, .col-md-6, .col-md-12 {
            padding: 8px;
        }

        .col-md-3 { width: 25%; }
        .col-md-4 { width: 33.333%; }
        .col-md-6 { width: 50%; }
        .col-md-12 { width: 100%; }

        /* Buttons */
        .btn {
            padding: 10px 24px;
            font-size: 0.95rem;
            font-weight: 500;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #e9ecef;
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #dee2e6;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        /* Alerts */
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        /* Calculation info */
        .calc-info {
            font-size: 0.7rem;
            color: var(--primary);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .calc-info i {
            font-size: 0.65rem;
        }

        /* Datalist */
        datalist {
            display: none;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .main-container {
                margin-left: 10%;
            }
        }

        @media (max-width: 900px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
            }
            
            .col-md-3, .col-md-4, .col-md-6 {
                width: 100%;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2>
            <i class="fas fa-edit"></i>
            Edit Embroidery Entry #<?= $id ?>
        </h2>
        <a href="list.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    </div>

    <!-- Success/Error Messages -->
    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Edit Form Card -->
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-pen"></i> Entry Details</h4>
        </div>
        <div class="card-body">
            <form method="POST" id="editForm">
                <!-- Basic Info Row 1 -->
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-calendar"></i> Entry Date</label>
                        <input type="date" name="entry_date" class="form-control" 
                               value="<?= date('Y-m-d', strtotime($entry['entry_date'])) ?>" required>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-industry"></i> Machine No</label>
                        <input type="text" name="machine_no" class="form-control" 
                               value="<?= htmlspecialchars($entry['machine_no']) ?>" readonly>
                        <input type="hidden" name="machine_id" value="<?= $entry['machine_id'] ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-clock"></i> Shift</label>
                        <select name="shift" class="form-select" required>
                            <option value="day" <?= $entry['shift'] == 'day' ? 'selected' : '' ?>>Day</option>
                            <option value="night" <?= $entry['shift'] == 'night' ? 'selected' : '' ?>>Night</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-hashtag"></i> Job No</label>
                        <input type="text" name="job_no" class="form-control" list="jobList" 
                               value="<?= htmlspecialchars($entry['job_no']) ?>" required>
                        <datalist id="jobList">
                            <?php while($job = $jobs_list->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($job['job_no']) ?>">
                            <?php endwhile; ?>
                        </datalist>
                    </div>
                </div>

                <!-- Design and Vendor Row -->
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-palette"></i> Design No</label>
                        <input type="text" name="design_no" class="form-control" 
                               value="<?= htmlspecialchars($entry['design_no']) ?>" readonly>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-user-tie"></i> Vendor</label>
                        <input type="text" name="vendor_name" class="form-control" 
                               value="<?= htmlspecialchars($entry['vendor_name']) ?>" readonly>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-cog"></i> Part</label>
                        <input type="text" name="part" class="form-control" 
                               value="<?= htmlspecialchars($entry['part']) ?>" placeholder="Part name">
                    </div>
                </div>

                <!-- Stitch Details Row -->
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-tshirt"></i> Stitch Done</label>
                        <input type="number" step="1" name="stitch_done" id="stitch_done" class="form-control" 
                               value="<?= $entry['stitch_done'] ?>">
                        <div class="calc-info" id="stitchCalcInfo" style="display: none;">
                            <i class="fas fa-calculator"></i> Auto-calculated from Per Round × Rounds
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-sync"></i> Per Round</label>
                        <input type="number" step="1" name="per_round" id="per_round" class="form-control" 
                               value="<?= $entry['per_round'] ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-undo"></i> Rounds</label>
                        <input type="number" step="0.01" name="rounds" id="rounds" class="form-control" 
                               value="<?= $entry['rounds'] ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-rupee-sign"></i> OP Rate</label>
                        <input type="number" step="0.05" name="op_rate" class="form-control" 
                               value="<?= $entry['op_rate'] ?>">
                    </div>
                </div>

                <!-- Operator and Helper Row -->
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-user-cog"></i> Operator</label>
                        <input type="text" name="operator_name" class="form-control" list="operatorList" 
                               value="<?= htmlspecialchars($entry['operator_name']) ?>">
                        <datalist id="operatorList">
                            <?php foreach($operators as $op): ?>
                                <option value="<?= htmlspecialchars($op['account_name']) ?>" 
                                        label="<?= $op['account_type'] ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-user-friends"></i> Helper</label>
                        <input type="text" name="helper_name" class="form-control" list="helperList" 
                               value="<?= htmlspecialchars($entry['helper_name']) ?>">
                        <datalist id="helperList">
                            <?php foreach($helpers as $hl): ?>
                                <option value="<?= htmlspecialchars($hl['account_name']) ?>" 
                                        label="<?= $hl['account_type'] ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <!-- Form Buttons -->
                <div class="row mt-4">
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" name="update" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Entry
                        </button>
                        <a href="list.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <a href="delete_embroidery_entry.php?id=<?= $id ?>" class="btn btn-danger ms-auto" 
                           onclick="return confirm('Are you sure you want to delete this entry?')">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Entry Info Card -->
    <div class="card mt-4">
        <div class="card-header">
            <h4><i class="fas fa-info-circle"></i> Entry Information</h4>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <small class="text-muted">Created At</small>
                    <div><strong><?= date('d-m-Y H:i', strtotime($entry['created_at'])) ?></strong></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Entry ID</small>
                    <div><strong>#<?= $id ?></strong></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Total Stitches</small>
                    <div><strong><?= number_format($entry['stitch_done']) ?></strong></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Total Rounds</small>
                    <div><strong><?= number_format($entry['rounds']) ?></strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-calculate Stitch Done from Per Round and Rounds
const perRoundInput = document.getElementById('per_round');
const roundsInput = document.getElementById('rounds');
const stitchDoneInput = document.getElementById('stitch_done');
const stitchCalcInfo = document.getElementById('stitchCalcInfo');

function updateStitchFromPerRoundAndRounds() {
    const perRound = parseFloat(perRoundInput.value) || 0;
    const rounds = parseFloat(roundsInput.value) || 0;
    
    if (perRound > 0 && rounds > 0) {
        const calculatedStitch = perRound * rounds;
        stitchDoneInput.value = calculatedStitch.toFixed(2);
        stitchCalcInfo.style.display = 'flex';
    } else {
        stitchCalcInfo.style.display = 'none';
        if (stitchDoneInput.value === '0' || stitchDoneInput.value === '') {
            stitchDoneInput.value = '0';
        }
    }
}

function updateRoundsFromStitchAndPerRound() {
    const perRound = parseFloat(perRoundInput.value) || 0;
    const stitch = parseFloat(stitchDoneInput.value) || 0;
    
    if (perRound > 0 && stitch > 0) {
        const calculatedRounds = stitch / perRound;
        roundsInput.value = calculatedRounds.toFixed(2);
    }
}

function updatePerRoundFromStitchAndRounds() {
    const rounds = parseFloat(roundsInput.value) || 0;
    const stitch = parseFloat(stitchDoneInput.value) || 0;
    
    if (rounds > 0 && stitch > 0) {
        const calculatedPerRound = stitch / rounds;
        perRoundInput.value = calculatedPerRound.toFixed(2);
    }
}

if (perRoundInput && roundsInput && stitchDoneInput) {
    perRoundInput.addEventListener('input', updateStitchFromPerRoundAndRounds);
    roundsInput.addEventListener('input', updateStitchFromPerRoundAndRounds);
    
    stitchDoneInput.addEventListener('input', function() {
        const perRound = parseFloat(perRoundInput.value) || 0;
        const rounds = parseFloat(roundsInput.value) || 0;
        
        if (perRound > 0 && rounds > 0) {
            const calculatedStitch = perRound * rounds;
            if (Math.abs(calculatedStitch - parseFloat(stitchDoneInput.value)) > 0.01) {
                updateRoundsFromStitchAndPerRound();
                stitchCalcInfo.style.display = 'none';
            }
        } else if (perRound > 0 && stitchDoneInput.value > 0) {
            updateRoundsFromStitchAndPerRound();
        } else if (rounds > 0 && stitchDoneInput.value > 0) {
            updatePerRoundFromStitchAndRounds();
        }
    });
}

document.getElementById('editForm').addEventListener('submit', function(e) {
    const stitchValue = parseFloat(stitchDoneInput.value) || 0;
    const perRoundValue = parseFloat(perRoundInput.value) || 0;
    const roundsValue = parseFloat(roundsInput.value) || 0;
    
    if (stitchValue === 0 && perRoundValue === 0 && roundsValue === 0) {
        e.preventDefault();
        alert('Please enter either Stitch Done, or both Per Round and Rounds values.');
        return false;
    }
    
    if (stitchValue === 0 && perRoundValue > 0 && roundsValue > 0) {
        stitchDoneInput.value = (perRoundValue * roundsValue).toFixed(2);
    }
    
    return true;
});
</script>

</body>
</html>