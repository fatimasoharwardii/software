<?php
$page_identifier = 'embroidery/entry.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$user_id = (int)$_SESSION['user_id']; // ✅ added for foreign key

// Ensure required tables have company_id column
$tables = ['embroidery_entries', 'machines', 'jobs', 'accounts'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'company_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `company_id` INT DEFAULT NULL");
        $conn->query("UPDATE `$table` SET `company_id` = 1 WHERE `company_id` IS NULL");
        $conn->query("ALTER TABLE `$table` MODIFY `company_id` INT NOT NULL");
    }
}

// Function to calculate bonus based on machine tiers (unchanged)
function calculateMachineBonus($stitches, $machine_id, $conn, $company_id) {
    $stmt = $conn->prepare("SELECT bonus_stitch_1, bonus_amount_1, bonus_stitch_2, bonus_amount_2,
                                   bonus_stitch_3, bonus_amount_3, bonus_stitch_4, bonus_amount_4,
                                   bonus_stitch_5, bonus_amount_5
                            FROM machines WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $machine_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $machine = $result->fetch_assoc();
    if(!$machine) return 0;

    $tiers = [
        ['min' => $machine['bonus_stitch_5'], 'amount' => $machine['bonus_amount_5']],
        ['min' => $machine['bonus_stitch_4'], 'amount' => $machine['bonus_amount_4']],
        ['min' => $machine['bonus_stitch_3'], 'amount' => $machine['bonus_amount_3']],
        ['min' => $machine['bonus_stitch_2'], 'amount' => $machine['bonus_amount_2']],
        ['min' => $machine['bonus_stitch_1'], 'amount' => $machine['bonus_amount_1']]
    ];
    foreach($tiers as $tier) {
        if($stitches >= $tier['min'] && $tier['amount'] > 0) {
            return $tier['amount'];
        }
    }
    return 0;
}

// Make sure necessary columns exist
$conn->query("ALTER TABLE embroidery_entries ADD COLUMN IF NOT EXISTS machine_bonus DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE embroidery_entries ADD COLUMN IF NOT EXISTS helper_absent TINYINT(1) DEFAULT 0");
$conn->query("ALTER TABLE embroidery_entries MODIFY COLUMN rounds DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE embroidery_entries MODIFY COLUMN stitch_done DECIMAL(12,2) DEFAULT 0");
$conn->query("ALTER TABLE machines ADD COLUMN IF NOT EXISTS machine_rate DECIMAL(10,2) DEFAULT 0");

ini_set('max_input_vars', 10000);

// Fetch all jobs data for current company
$stmt = $conn->prepare("SELECT job_no, design_name, size, quantity, embroidery_vendor_name FROM jobs WHERE company_id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$jobs_result = $stmt->get_result();
$jobs_json = [];
while($job = $jobs_result->fetch_assoc()){
    $jobs_json[$job['job_no']] = $job;
}

// Fetch operators from accounts
$stmt = $conn->prepare("SELECT account_name FROM accounts WHERE account_type IN ('employee', 'vendor', 'customer') AND company_id = ? ORDER BY account_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$ops_res = $stmt->get_result();
$operators = [];
while($op = $ops_res->fetch_assoc()){ $operators[] = $op; }

// Fetch helpers
$stmt = $conn->prepare("SELECT account_name FROM accounts WHERE account_type IN ('employee', 'vendor', 'customer') AND company_id = ? ORDER BY account_name");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$hlp_res = $stmt->get_result();
$helpers = [];
while($hl = $hlp_res->fetch_assoc()){ $helpers[] = $hl; }

$part_options = [
    'ASTEEN', 'ASTEEN BUNCH', 'ASTEEN CHAK PATI', 'ASTEEN DAMAN BUN', 'ASTEEN DAMAN LECE',
    'ASTEEN GALA', 'ASTEEN GALA ANRKH', 'ASTEEN GALA LACE', 'ASTEEN trousers', 'BODY ASTEEN PATTA',
    'BORDER', 'BUNCH', 'CHAK PATTI', 'CHAK TRZ GALA', 'COAT A', 'COAT B', 'DAMAN', 'DAMAN ASTEEN',
    'DAMAN ASTEEN CHA', 'DAMAN ASTEEN GAL', 'DAMAN BORDER', 'DUPATTA', 'FRONT', 'GALA ASTEEN',
    'GALA ASTEEN BUNCH', 'GALA ASTEEN FRONT', 'GALA CHAK TRUSER', 'KALI', 'KALI trousers', 'LACE',
    'NACK ARM HOLE', 'PANEL', 'PILAZO', 'trousers'
];

// Modified insert function with user_id
function insertEmbroideryEntry($conn, $data, $company_id, $user_id) {
    $sql = "INSERT INTO embroidery_entries (
        entry_date, machine_id, machine_no, shift, job_no, design_no,
        vendor_name, part, stitch_done, per_round, rounds, op_rate,
        operator_name, helper_name, machine_bonus, helper_absent, company_id, user_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sissssssddddsssdii",
        $data['entry_date'], $data['machine_id'], $data['machine_no'], $data['shift'],
        $data['job_no'], $data['design_no'], $data['vendor_name'], $data['part'],
        $data['stitch_done'], $data['per_round'], $data['rounds'], $data['op_rate'],
        $data['operator_name'], $data['helper_name'], $data['machine_bonus'], $data['helper_absent'],
        $company_id, $user_id
    );
    return $stmt->execute();
}

// Handle save all entries
$message = '';
if(isset($_POST['save_all_entries'])){
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $validation_errors = [];

    if(isset($_POST['entries']) && !empty($_POST['entries'])) {
        foreach($_POST['entries'] as $entry_key => $entry){
            if(empty($entry['entry_date'])) continue;

            $entry_date = $entry['entry_date'];
            $machine_id = isset($entry['machine_id']) && !empty($entry['machine_id']) ? (int)$entry['machine_id'] : null;
            $machine_no = $entry['machine_no'] ?? '';
            $shift = $entry['shift'] ?? '';
            $operator_name = $entry['operator_name_select'] ?? '';
            $helper_name = $entry['helper_name_select'] ?? '';
            $helper_absent = isset($entry['helper_absent']) ? 1 : 0;

            // Validate required fields
            if(empty($operator_name)) {
                $validation_errors[] = "Operator name is required for Machine $machine_no - $shift shift";
                continue;
            }
            if(empty($helper_name) && $helper_absent == 0) {
                $validation_errors[] = "Helper name is required for Machine $machine_no - $shift shift (or check Helper Absent)";
                continue;
            }

            // Calculate total stitches for this shift
            $total_stitches_shift = 0;
            $has_valid_row = false;
            $valid_rows = [];

            if(isset($entry['rows']) && is_array($entry['rows'])) {
                foreach($entry['rows'] as $row){
                    $has_data = !empty($row['job_no']) || !empty($row['part']) ||
                                floatval($row['stitch_done'] ?? 0) > 0 ||
                                floatval($row['per_round'] ?? 0) > 0 ||
                                floatval($row['rounds'] ?? 0) > 0;

                    if($has_data) {
                        if(empty($row['job_no'])) {
                            $validation_errors[] = "Job number is required for a data row in Machine $machine_no - $shift shift";
                            continue;
                        }
                        if(empty($row['part'])) {
                            $validation_errors[] = "Part is required for Job {$row['job_no']} in Machine $machine_no - $shift shift";
                            continue;
                        }
                        if(empty($row['stitch_done']) && empty($row['per_round']) && empty($row['rounds'])) {
                            $validation_errors[] = "Stitch, Per Round, or Rounds must be filled for Job {$row['job_no']}";
                            continue;
                        }
                        $has_valid_row = true;
                        $valid_rows[] = $row;
                        $stitch_done = isset($row['stitch_done']) ? floatval($row['stitch_done']) : 0;
                        $total_stitches_shift += $stitch_done;
                    }
                }
            }

            if(!$has_valid_row) {
                $validation_errors[] = "At least one complete row must be entered for Machine $machine_no - $shift shift";
                continue;
            }

            // Calculate bonus
            $shift_bonus = 0;
            if($machine_id !== null) {
                $shift_bonus = calculateMachineBonus($total_stitches_shift, $machine_id, $conn, $company_id);
            }

            // Save each valid row
            foreach($valid_rows as $row){
                $job_no = $row['job_no'];
                $design_no = $row['design_no'] ?? '';
                $vendor_name = $row['vendor_name'] ?? '';
                $part = $row['part'] ?? '';
                $stitch_done = isset($row['stitch_done']) ? floatval($row['stitch_done']) : 0;
                $per_round = isset($row['per_round']) ? floatval($row['per_round']) : 0;
                $rounds = isset($row['rounds']) ? floatval($row['rounds']) : 0;
                $op_rate = isset($row['op_rate']) ? floatval($row['op_rate']) : 0;

                $insert_data = [
                    'entry_date' => $entry_date,
                    'machine_id' => $machine_id,
                    'machine_no' => $machine_no,
                    'shift' => $shift,
                    'job_no' => $job_no,
                    'design_no' => $design_no,
                    'vendor_name' => $vendor_name,
                    'part' => $part,
                    'stitch_done' => $stitch_done,
                    'per_round' => $per_round,
                    'rounds' => $rounds,
                    'op_rate' => $op_rate,
                    'operator_name' => $operator_name,
                    'helper_name' => $helper_name,
                    'machine_bonus' => $shift_bonus,
                    'helper_absent' => $helper_absent
                ];
                if(insertEmbroideryEntry($conn, $insert_data, $company_id, $user_id)){ // ✅ added $user_id
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = $conn->error;
                }
            }
        }
    }

    if($success_count > 0){
        $message = "Saved $success_count entries successfully.";
        if($error_count > 0) $message .= " $error_count failed.";
        if(!empty($validation_errors)) {
            $message .= "<br><strong>Validation Errors:</strong><br>" . implode("<br>", $validation_errors);
        }
    } else {
        $message = "No entries were saved. Please fill in the data correctly.";
        if(!empty($validation_errors)) {
            $message = "<strong>Validation Errors:</strong><br>" . implode("<br>", $validation_errors);
        } elseif(!empty($errors)) {
            $message .= " Errors: " . implode(", ", $errors);
        }
    }
}

// Handle single shift save
if(isset($_POST['save_shift'])){
    $entry_key = $_POST['save_shift'];
    if(isset($_POST['entries'][$entry_key])){
        $entry = $_POST['entries'][$entry_key];
        if(!empty($entry['entry_date'])){
            $entry_date = $entry['entry_date'];
            $machine_id = isset($entry['machine_id']) && !empty($entry['machine_id']) ? (int)$entry['machine_id'] : null;
            $machine_no = $entry['machine_no'] ?? '';
            $shift = $entry['shift'] ?? '';
            $operator_name = $entry['operator_name_select'] ?? '';
            $helper_name = $entry['helper_name_select'] ?? '';
            $helper_absent = isset($entry['helper_absent']) ? 1 : 0;

            $validation_errors = [];

            if(empty($operator_name)) {
                $validation_errors[] = "Operator name is required for this shift";
            }
            if(empty($helper_name) && $helper_absent == 0) {
                $validation_errors[] = "Helper name is required for this shift (or check Helper Absent)";
            }

            $total_stitches_shift = 0;
            $has_valid_row = false;
            $valid_rows = [];

            if(isset($entry['rows']) && is_array($entry['rows'])) {
                foreach($entry['rows'] as $row){
                    $has_data = !empty($row['job_no']) || !empty($row['part']) ||
                                floatval($row['stitch_done'] ?? 0) > 0 ||
                                floatval($row['per_round'] ?? 0) > 0 ||
                                floatval($row['rounds'] ?? 0) > 0;

                    if($has_data) {
                        if(empty($row['job_no'])) {
                            $validation_errors[] = "Job number is required for a data row";
                            continue;
                        }
                        if(empty($row['part'])) {
                            $validation_errors[] = "Part is required for Job {$row['job_no']}";
                            continue;
                        }
                        if(empty($row['stitch_done']) && empty($row['per_round']) && empty($row['rounds'])) {
                            $validation_errors[] = "Stitch, Per Round, or Rounds must be filled for Job {$row['job_no']}";
                            continue;
                        }
                        $has_valid_row = true;
                        $valid_rows[] = $row;
                        $stitch_done = isset($row['stitch_done']) ? floatval($row['stitch_done']) : 0;
                        $total_stitches_shift += $stitch_done;
                    }
                }
            }

            if(!$has_valid_row) {
                $validation_errors[] = "At least one complete row must be entered for this shift";
            }

            if(!empty($validation_errors)) {
                $message = "Validation Errors:<br>" . implode("<br>", $validation_errors);
            } else {
                $shift_bonus = 0;
                if($machine_id !== null) {
                    $shift_bonus = calculateMachineBonus($total_stitches_shift, $machine_id, $conn, $company_id);
                }

                $success_count = 0;
                foreach($valid_rows as $row){
                    $job_no = $row['job_no'];
                    $design_no = $row['design_no'] ?? '';
                    $vendor_name = $row['vendor_name'] ?? '';
                    $part = $row['part'] ?? '';
                    $stitch_done = isset($row['stitch_done']) ? floatval($row['stitch_done']) : 0;
                    $per_round = isset($row['per_round']) ? floatval($row['per_round']) : 0;
                    $rounds = isset($row['rounds']) ? floatval($row['rounds']) : 0;
                    $op_rate = isset($row['op_rate']) ? floatval($row['op_rate']) : 0;

                    $insert_data = [
                        'entry_date' => $entry_date,
                        'machine_id' => $machine_id,
                        'machine_no' => $machine_no,
                        'shift' => $shift,
                        'job_no' => $job_no,
                        'design_no' => $design_no,
                        'vendor_name' => $vendor_name,
                        'part' => $part,
                        'stitch_done' => $stitch_done,
                        'per_round' => $per_round,
                        'rounds' => $rounds,
                        'op_rate' => $op_rate,
                        'operator_name' => $operator_name,
                        'helper_name' => $helper_name,
                        'machine_bonus' => $shift_bonus,
                        'helper_absent' => $helper_absent
                    ];
                    if(insertEmbroideryEntry($conn, $insert_data, $company_id, $user_id)){ // ✅ added $user_id
                        $success_count++;
                    }
                }
                $message = "Saved $success_count entries for this shift.";
            }
        }
    }
}

// Fetch machines for current company
$stmt = $conn->prepare("SELECT * FROM machines WHERE company_id = ? ORDER BY id");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$machines_res = $stmt->get_result();
$machineRows = [];
while($m = $machines_res->fetch_assoc()){ $machineRows[] = $m; }

$previous_date = date('Y-m-d', strtotime('-1 day'));

// Prepare machine bonus tiers data for JavaScript
$tiers_data = [];
foreach($machineRows as $m) {
    $tiers_data[$m['id']] = [
        't1_min' => (int)$m['bonus_stitch_1'],
        't1_amt' => (float)$m['bonus_amount_1'],
        't2_min' => (int)$m['bonus_stitch_2'],
        't2_amt' => (float)$m['bonus_amount_2'],
        't3_min' => (int)$m['bonus_stitch_3'],
        't3_amt' => (float)$m['bonus_amount_3'],
        't4_min' => (int)$m['bonus_stitch_4'],
        't4_amt' => (float)$m['bonus_amount_4'],
        't5_min' => (int)$m['bonus_stitch_5'],
        't5_amt' => (float)$m['bonus_amount_5']
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Embroidery Entry</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<datalist id="operatorList"><?php foreach($operators as $op): ?><option value="<?= htmlspecialchars($op['account_name']); ?>"><?php endforeach; ?></datalist>
<datalist id="helperList"><?php foreach($helpers as $hl): ?><option value="<?= htmlspecialchars($hl['account_name']); ?>"><?php endforeach; ?></datalist>
<datalist id="jobList"><?php
$job_list_stmt = $conn->prepare("SELECT job_no FROM jobs WHERE company_id = ?");
$job_list_stmt->bind_param("i", $company_id);
$job_list_stmt->execute();
$job_list_res = $job_list_stmt->get_result();
while($j = $job_list_res->fetch_assoc()){ ?><option value="<?= htmlspecialchars($j['job_no']) ?>"><?php } ?>
</datalist>
<datalist id="partList"><?php foreach($part_options as $part_option): ?><option value="<?= htmlspecialchars($part_option) ?>"><?php endforeach; ?></datalist>
<style>
    :root { 
        --primary: #F39C12; 
        --primary-hover: #FFB347; 
        --day-bg: #FFF9F0; 
        --night-bg: #F5F7FA; 
        --text-dark: #2C3E50; 
        --success: #28a745;
        --danger: #dc3545;
        --info: #17a2b8;
        --warning: #ffc107;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #F5F5F5; font-family: 'Segoe UI', system-ui, sans-serif; }
    
    .main-container { 
        margin-left: 14%; 
        padding: 20px 24px; 
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }
    
    .page-header { 
        background: white; 
        border-radius: 12px; 
        padding: 16px 20px; 
        margin-bottom: 20px; 
        border-left: 4px solid var(--primary);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .page-header h2 { 
        color: var(--primary); 
        font-weight: 600; 
        margin: 0; 
        display: flex; 
        align-items: center; 
        gap: 10px;
        font-size: 1.3rem;
    }
    
    .machine-card { 
        background: white; 
        border-radius: 12px; 
        margin-bottom: 20px; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
        overflow: hidden; 
    }
    .card-header { 
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%); 
        padding: 12px 16px; 
        color: white; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        flex-wrap: wrap;
        gap: 10px;
    }
    .card-header span i { margin-right: 6px; }
    
    .shift-section { 
        padding: 16px; 
        border-bottom: 1px solid #eee; 
    }
    .shift-section-day { background: var(--day-bg); }
    .shift-section-night { background: var(--night-bg); }
    .shift-title { 
        font-size: 1rem; 
        font-weight: 600; 
        margin-bottom: 12px; 
        padding-bottom: 6px; 
        border-bottom: 2px solid var(--primary); 
        display: inline-block;
    }
    
    .fields-row { 
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px; 
        margin-bottom: 16px; 
    }
    .field-item { 
        display: flex;
        flex-direction: column;
    }
    .field-item label { 
        font-size: 0.7rem; 
        font-weight: 600; 
        text-transform: uppercase; 
        color: #6b7b8b; 
        margin-bottom: 4px;
        letter-spacing: 0.5px;
    }
    .field-item label i { margin-right: 4px; }
    .field-item input, .field-item select { 
        width: 100%; 
        padding: 8px 10px; 
        border: 1px solid #ddd; 
        border-radius: 6px;
        font-size: 0.85rem;
    }
    .field-item input:focus {
        border-color: var(--primary);
        outline: none;
    }
    
    .helper-absent-checkbox {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 8px;
    }
    .helper-absent-checkbox input {
        width: auto;
        margin: 0;
        transform: scale(1.1);
        cursor: pointer;
    }
    .helper-absent-checkbox label {
        font-size: 0.75rem;
        font-weight: normal;
        text-transform: none;
        color: #856404;
        margin: 0;
        cursor: pointer;
    }
    .helper-absent-checkbox label i {
        color: var(--warning);
    }
    
    .required-star::after {
        content: '*';
        color: var(--danger);
        margin-left: 4px;
    }
    
    .validation-warning {
        font-size: 0.7rem;
        color: var(--danger);
        margin-top: 4px;
        display: none;
    }
    
    .total-stitches-box, .total-bonus-box {
        background: linear-gradient(135deg, var(--success) 0%, #218838 100%);
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 42px;
        font-weight: 600;
    }
    .total-bonus-box { background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); }
    
    .table-responsive { 
        overflow-x: auto; 
        margin-top: 12px;
        -webkit-overflow-scrolling: touch;
    }
    .table { 
        width: 100%; 
        border-collapse: collapse; 
        font-size: 0.8rem; 
        background: white;
        min-width: 800px;
    }
    .table thead th { 
        background: #f8f9fa; 
        padding: 8px 6px; 
        border-bottom: 2px solid var(--primary); 
        white-space: nowrap;
        font-weight: 600;
    }
    .table td { 
        padding: 6px; 
        border: 1px solid #eee; 
        vertical-align: middle;
    }
    .table input { 
        width: 100%; 
        padding: 6px 8px; 
        border: 1px solid #ddd; 
        border-radius: 4px;
        font-size: 0.8rem;
    }
    .table input[type="number"] {
        -moz-appearance: textfield;
    }
    .table input:focus {
        border-color: var(--primary);
        outline: none;
    }
    
    .btn-primary, .btn-success-custom { 
        background: var(--primary); 
        color: white; 
        border: none; 
        padding: 8px 16px; 
        border-radius: 6px; 
        display: inline-flex; 
        align-items: center; 
        gap: 6px; 
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 500;
        transition: all 0.2s;
    }
    .btn-primary:hover, .btn-success-custom:hover { 
        background: var(--primary-hover); 
        transform: translateY(-1px);
    }
    .btn-lg { padding: 10px 24px; font-size: 1rem; }
    .save-shift { background: var(--info); }
    .save-shift:hover { background: #138496; }
    
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
    .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid var(--danger); }
    .alert-success { background: #d4edda; color: #155724; border-left: 4px solid var(--success); }
    .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid var(--warning); }
    
    .date-selector { 
        padding: 6px 10px; 
        border: none; 
        border-radius: 6px;
        background: rgba(255,255,255,0.2);
        color: white;
        font-size: 0.85rem;
    }
    .date-selector:focus { outline: none; }
    
    .helper-field:disabled {
        background: #e9ecef;
        cursor: not-allowed;
    }
    
    @media (max-width: 1200px) {
        .main-container { margin-left: 10%; padding: 16px 20px; }
    }
    
    @media (max-width: 992px) {
        .main-container { margin-left: 0; padding: 12px; margin-top: 15px; }
        .fields-row { grid-template-columns: repeat(2, 1fr); }
        .card-header { flex-direction: column; align-items: flex-start; }
    }
    
    @media (max-width: 768px) {
        .main-container { padding: 10px; }
        .fields-row { grid-template-columns: 1fr; gap: 10px; }
        .table { font-size: 0.7rem; min-width: 700px; }
        .btn-primary, .btn-success-custom { padding: 6px 12px; font-size: 0.75rem; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <div class="page-header">
        <h2><i class="fas fa-thread"></i> Add Embroidery Entries</h2>
    </div>
    
    <?php if(!empty($message)): ?>
        <div class="alert alert-<?= strpos($message, 'Error') !== false || strpos($message, 'required') !== false ? 'danger' : (strpos($message, 'Validation') !== false ? 'warning' : 'success') ?> alert-dismissible fade show">
            <i class="fas <?= strpos($message, 'Error') !== false || strpos($message, 'required') !== false ? 'fa-exclamation-circle' : (strpos($message, 'Validation') !== false ? 'fa-exclamation-triangle' : 'fa-check-circle') ?>"></i>
            <?= $message ?>
            <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <form method="POST" id="mainForm">
        <input type="hidden" name="save_all_entries" value="1">
        
        <?php foreach($machineRows as $idx => $machine): ?>
            <div class="machine-card">
                <div class="card-header">
                    <span><i class="fas fa-industry"></i> <strong>Machine <?= htmlspecialchars($machine['machine_no']) ?></strong>
                        <span style="font-size:0.75rem; margin-left:8px;"><i class="fas fa-rupee-sign"></i> Rate: <?= $machine['machine_rate'] ?? 0 ?>/1000</span>
                    </span>
                    <input type="date" class="date-selector" data-machine-idx="<?= $idx ?>" value="<?= $previous_date ?>">
                </div>
                
                <?php 
                $shifts = [
                    'day' => ['label' => 'Day Shift', 'icon' => 'sun'], 
                    'night' => ['label' => 'Night Shift', 'icon' => 'moon']
                ];
                foreach($shifts as $shiftKey => $shiftInfo): 
                    $entryKey = $idx . '_' . $shiftKey; 
                ?>
                <div class="shift-section shift-section-<?= $shiftKey ?>">
                    <div class="shift-title">
                        <i class="fas fa-<?= $shiftInfo['icon'] ?>"></i> <?= $shiftInfo['label'] ?>
                    </div>
                    
                    <input type="hidden" name="entries[<?= $entryKey ?>][machine_id]" value="<?= $machine['id'] ?>">
                    <input type="hidden" name="entries[<?= $entryKey ?>][machine_no]" value="<?= htmlspecialchars($machine['machine_no']) ?>">
                    <input type="hidden" name="entries[<?= $entryKey ?>][shift]" value="<?= $shiftKey ?>">
                    <input type="hidden" name="entries[<?= $entryKey ?>][entry_date]" class="entry-date-<?= $idx ?>" value="<?= $previous_date ?>">
                    
                    <div class="fields-row">
                        <div class="field-item">
                            <label class="required-star"><i class="fas fa-user-cog"></i> Operator</label>
                            <input type="text" name="entries[<?= $entryKey ?>][operator_name_select]" list="operatorList" placeholder="Type operator name" class="operator-field" autocomplete="off" required>
                            <div class="validation-warning operator-warning">Operator name is required</div>
                        </div>
                        <div class="field-item">
                            <label class="required-star"><i class="fas fa-user-friends"></i> Helper</label>
                            <input type="text" name="entries[<?= $entryKey ?>][helper_name_select]" list="helperList" placeholder="Type helper name" class="helper-field" autocomplete="off">
                            <div class="validation-warning helper-warning">Helper name is required (or check Helper Absent)</div>
                            <div class="helper-absent-checkbox">
                                <input type="checkbox" name="entries[<?= $entryKey ?>][helper_absent]" id="helper_absent_<?= $entryKey ?>" class="helper-absent-check">
                                <label for="helper_absent_<?= $entryKey ?>"><i class="fas fa-user-slash"></i> Helper Absent</label>
                            </div>
                        </div>
                        <div class="field-item">
                            <label><i class="fas fa-calculator"></i> Total Stitches</label>
                            <div class="total-stitches-box"><i class="fas fa-stitch"></i><span class="total-stitch-val">0</span></div>
                        </div>
                        <div class="field-item">
                            <label><i class="fas fa-gift"></i> Shift Bonus</label>
                            <div class="total-bonus-box"><i class="fas fa-rupee-sign"></i><span class="total-bonus-val">0</span></div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Job No</th>
                                    <th>Design</th>
                                    <th>Vendor</th>
                                    <th>Part</th>
                                    <th>Per Round</th>
                                    <th>Rounds</th>
                                    <th>Stitch</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($r = 0; $r < 8; $r++): ?>
                                    <tr data-row="<?= $r ?>">
                                        <td><input type="text" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][job_no]" class="job-input" list="jobList" placeholder="Job No" autocomplete="off"></td>
                                        <td><input type="text" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][design_no]" class="design-input" readonly></td>
                                        <td><input type="text" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][vendor_name]" class="vendor-input" readonly></td>
                                        <td><input type="text" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][part]" class="part-input" list="partList" placeholder="Part" autocomplete="off"></td>
                                        <td><input type="number" step="any" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][per_round]" class="per-round-input" placeholder="0" step="0.01" value="0"></td>
                                        <td><input type="number" step="any" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][rounds]" class="rounds-input" placeholder="0" step="0.01" value="0"></td>
                                        <td><input type="number" step="any" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][stitch_done]" class="stitch-input" placeholder="0" step="0.01" value="0"></td>
                                        <td><input type="number" step="any" name="entries[<?= $entryKey ?>][rows][<?= $r ?>][op_rate]" class="op-rate-input" placeholder="Rate" value="<?= $machine['machine_rate'] ?? '' ?>" step="0.01"></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-end mt-2">
                        <button type="button" class="btn-primary save-shift" data-entry-key="<?= $entryKey ?>">
                            <i class="fas fa-save"></i> Save This Shift
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        
        <div class="text-center mt-4 mb-4">
            <button type="submit" class="btn-primary btn-lg">
                <i class="fas fa-save"></i> Save All Entries
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const jobsData = <?php echo json_encode($jobs_json); ?>;
const machineBonusTiers = <?php echo json_encode($tiers_data); ?>;

function calculateBonus(stitches, machineId) {
    const tiers = machineBonusTiers[machineId];
    if(!tiers) return 0;
    const tierList = [
        {min: parseFloat(tiers.t5_min), amt: parseFloat(tiers.t5_amt)},
        {min: parseFloat(tiers.t4_min), amt: parseFloat(tiers.t4_amt)},
        {min: parseFloat(tiers.t3_min), amt: parseFloat(tiers.t3_amt)},
        {min: parseFloat(tiers.t2_min), amt: parseFloat(tiers.t2_amt)},
        {min: parseFloat(tiers.t1_min), amt: parseFloat(tiers.t1_amt)}
    ];
    for(let t of tierList) {
        if(stitches >= t.min && t.amt > 0) return t.amt;
    }
    return 0;
}

function loadJobDetails(input) {
    const jobNo = input.value;
    const row = input.closest('tr');
    const designInput = row.querySelector('.design-input');
    const vendorInput = row.querySelector('.vendor-input');
    
    if(jobNo && jobsData[jobNo]){
        if(designInput) designInput.value = jobsData[jobNo].design_name || '';
        if(vendorInput) vendorInput.value = jobsData[jobNo].embroidery_vendor_name || '';
    } else {
        if(designInput) designInput.value = '';
        if(vendorInput) vendorInput.value = '';
    }
}

function validateRow(row) {
    const jobInput = row.querySelector('.job-input');
    const partInput = row.querySelector('.part-input');
    const stitchInput = row.querySelector('.stitch-input');
    const perRoundInput = row.querySelector('.per-round-input');
    const roundsInput = row.querySelector('.rounds-input');
    
    const hasData = jobInput.value.trim() !== '' || 
                    partInput.value.trim() !== '' || 
                    (parseFloat(stitchInput.value) || 0) > 0 ||
                    (parseFloat(perRoundInput.value) || 0) > 0 ||
                    (parseFloat(roundsInput.value) || 0) > 0;
    
    if(!hasData) return true;
    
    let isValid = true;
    
    if(jobInput.value.trim() === '') {
        jobInput.style.borderColor = '#dc3545';
        isValid = false;
    } else {
        jobInput.style.borderColor = '#ddd';
    }
    
    if(partInput.value.trim() === '') {
        partInput.style.borderColor = '#dc3545';
        isValid = false;
    } else {
        partInput.style.borderColor = '#ddd';
    }
    
    const stitchVal = parseFloat(stitchInput.value) || 0;
    const perRoundVal = parseFloat(perRoundInput.value) || 0;
    const roundsVal = parseFloat(roundsInput.value) || 0;
    
    if(stitchVal === 0 && perRoundVal === 0 && roundsVal === 0) {
        stitchInput.style.borderColor = '#dc3545';
        perRoundInput.style.borderColor = '#dc3545';
        roundsInput.style.borderColor = '#dc3545';
        isValid = false;
    } else {
        stitchInput.style.borderColor = '#ddd';
        perRoundInput.style.borderColor = '#ddd';
        roundsInput.style.borderColor = '#ddd';
    }
    
    return isValid;
}

function validateShift(shiftSection) {
    let isValid = true;
    const operatorInput = shiftSection.querySelector('.operator-field');
    const helperInput = shiftSection.querySelector('.helper-field');
    const helperAbsentCheck = shiftSection.querySelector('.helper-absent-check');
    const rows = shiftSection.querySelectorAll('tbody tr');
    
    if(operatorInput && operatorInput.value.trim() === '') {
        operatorInput.style.borderColor = '#dc3545';
        const warning = operatorInput.parentElement.querySelector('.operator-warning');
        if(warning) warning.style.display = 'block';
        isValid = false;
    } else if(operatorInput) {
        operatorInput.style.borderColor = '#ddd';
        const warning = operatorInput.parentElement.querySelector('.operator-warning');
        if(warning) warning.style.display = 'none';
    }
    
    const isHelperAbsent = helperAbsentCheck ? helperAbsentCheck.checked : false;
    
    if(!isHelperAbsent && helperInput && helperInput.value.trim() === '') {
        helperInput.style.borderColor = '#dc3545';
        const warning = helperInput.parentElement.querySelector('.helper-warning');
        if(warning) warning.style.display = 'block';
        isValid = false;
    } else if(helperInput) {
        helperInput.style.borderColor = '#ddd';
        const warning = helperInput.parentElement.querySelector('.helper-warning');
        if(warning) warning.style.display = 'none';
    }
    
    let hasValidRow = false;
    rows.forEach(row => {
        if(validateRow(row)) {
            if(row.querySelector('.job-input').value.trim() !== '') {
                hasValidRow = true;
            }
        } else {
            isValid = false;
        }
    });
    
    if(!hasValidRow) {
        isValid = false;
    }
    
    return isValid;
}

function updateShiftTotals(shiftSection) {
    let totalStitch = 0;
    const machineId = shiftSection.querySelector('input[name*="[machine_id]"]')?.value;
    
    shiftSection.querySelectorAll('.stitch-input').forEach(input => {
        let stitch = parseFloat(input.value) || 0;
        totalStitch += stitch;
    });
    let shiftBonus = calculateBonus(totalStitch, machineId);
    
    let totalStitchSpan = shiftSection.querySelector('.total-stitch-val');
    let totalBonusSpan = shiftSection.querySelector('.total-bonus-val');
    if(totalStitchSpan) totalStitchSpan.textContent = totalStitch.toFixed(2);
    if(totalBonusSpan) totalBonusSpan.textContent = shiftBonus.toFixed(2);
}

function updateStitchFromPerRoundAndRounds(row) {
    const perRound = parseFloat(row.querySelector('.per-round-input')?.value) || 0;
    const rounds = parseFloat(row.querySelector('.rounds-input')?.value) || 0;
    const stitchInput = row.querySelector('.stitch-input');
    
    if(perRound > 0 && rounds > 0 && stitchInput) {
        let calculatedStitch = perRound * rounds;
        stitchInput.value = calculatedStitch.toFixed(2);
    }
}

function updateRoundsFromStitchAndPerRound(row) {
    const perRound = parseFloat(row.querySelector('.per-round-input')?.value) || 0;
    const stitch = parseFloat(row.querySelector('.stitch-input')?.value) || 0;
    const roundsInput = row.querySelector('.rounds-input');
    
    if(perRound > 0 && stitch > 0 && roundsInput) {
        let calculatedRounds = stitch / perRound;
        roundsInput.value = calculatedRounds.toFixed(2);
    }
}

function setupHelperAbsentListener(shiftSection) {
    const helperAbsentCheck = shiftSection.querySelector('.helper-absent-check');
    const helperInput = shiftSection.querySelector('.helper-field');
    const helperWarning = shiftSection.querySelector('.helper-warning');
    const helperLabel = helperInput ? helperInput.parentElement.querySelector('label') : null;
    
    if(helperAbsentCheck && helperInput) {
        helperAbsentCheck.addEventListener('change', function() {
            if(this.checked) {
                helperInput.disabled = true;
                helperInput.value = '';
                helperInput.style.borderColor = '#ddd';
                if(helperWarning) helperWarning.style.display = 'none';
                if(helperLabel) helperLabel.classList.remove('required-star');
            } else {
                helperInput.disabled = false;
                if(helperLabel) helperLabel.classList.add('required-star');
            }
            validateShift(shiftSection);
        });
    }
}

document.addEventListener('input', function(e) {
    const row = e.target.closest('tr');
    if(!row) return;
    
    const target = e.target;
    
    if(target.classList.contains('per-round-input')) {
        updateStitchFromPerRoundAndRounds(row);
    }
    else if(target.classList.contains('rounds-input')) {
        updateStitchFromPerRoundAndRounds(row);
    }
    else if(target.classList.contains('stitch-input')) {
        updateRoundsFromStitchAndPerRound(row);
    }
    
    validateRow(row);
    
    const shiftSection = row.closest('.shift-section');
    if(shiftSection) {
        updateShiftTotals(shiftSection);
        validateShift(shiftSection);
    }
});

document.addEventListener('change', function(e) {
    if(e.target.classList.contains('job-input')) {
        loadJobDetails(e.target);
    }
    const row = e.target.closest('tr');
    if(row) validateRow(row);
    const shiftSection = e.target.closest('.shift-section');
    if(shiftSection) updateShiftTotals(shiftSection);
});

document.querySelectorAll('.date-selector').forEach(dateInput => {
    dateInput.addEventListener('change', function() {
        const machineIdx = this.dataset.machineIdx;
        document.querySelectorAll('.entry-date-' + machineIdx).forEach(hiddenDate => {
            hiddenDate.value = this.value;
        });
    });
});

document.addEventListener('click', function(e) {
    if(e.target.closest('.save-shift')) {
        const btn = e.target.closest('.save-shift');
        const entryKey = btn.dataset.entryKey;
        const shiftSection = btn.closest('.shift-section');
        
        if(!validateShift(shiftSection)) {
            alert('Please fill all required fields:\n- Operator name is required\n- Helper name is required (or check Helper Absent)\n- Job number and part are required for each data row\n- At least one stitch value (Stitch, Per Round, or Rounds) must be filled');
            return;
        }
        
        const tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = '';
        tempForm.style.display = 'none';
        
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'save_shift';
        hiddenInput.value = entryKey;
        tempForm.appendChild(hiddenInput);
        
        shiftSection.querySelectorAll('input').forEach(el => {
            if(el.name && el.name !== '') {
                const clone = document.createElement('input');
                clone.type = 'hidden';
                clone.name = el.name;
                clone.value = el.value;
                tempForm.appendChild(clone);
            }
        });
        
        document.body.appendChild(tempForm);
        tempForm.submit();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.shift-section').forEach(section => {
        updateShiftTotals(section);
        validateShift(section);
        setupHelperAbsentListener(section);
    });
});
</script>
</body>
</html>