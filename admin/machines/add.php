<?php
// Explicit page identifier for permission system
$page_identifier = 'machines/add.php';

// Include database and functions FIRST
require_once "../../config/db.php";
require_once "../../includes/functions.php";

// Include authentication (handles login & permission)
require_once "../../includes/auth.php";

$company_id = (int)$_SESSION['company_id'];
$user_id    = (int)$_SESSION['user_id'];   // needed for foreign key

// Ensure machines table has company_id and bonus columns (your existing ALTERs)
$conn->query("ALTER TABLE machines ADD COLUMN IF NOT EXISTS company_id INT DEFAULT NULL");
$conn->query("UPDATE machines SET company_id = 1 WHERE company_id IS NULL");
$conn->query("ALTER TABLE machines MODIFY company_id INT NOT NULL");

$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `machine_rate` DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_stitch_1` INT DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_amount_1` DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_stitch_2` INT DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_amount_2` DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_stitch_3` INT DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_amount_3` DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_stitch_4` INT DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_amount_4` DECIMAL(10,2) DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_stitch_5` INT DEFAULT 0");
$conn->query("ALTER TABLE `machines` ADD COLUMN IF NOT EXISTS `bonus_amount_5` DECIMAL(10,2) DEFAULT 0");

// Get recent machines (only current company)
$recent_stmt = $conn->prepare("SELECT * FROM machines WHERE company_id = ? ORDER BY id DESC LIMIT 5");
$recent_stmt->bind_param("i", $company_id);
$recent_stmt->execute();
$recent_machines = $recent_stmt->get_result();

$error_message = "";

if (isset($_POST['save'])) {
    $machine_no    = trim($_POST['machine_no'] ?? '');
    $machine_rate  = (float) ($_POST['machine_rate'] ?? 0);
    $head          = (int) ($_POST['head'] ?? 0);

    // Check duplicate machine number within company
    $dup_stmt = $conn->prepare("SELECT id FROM machines WHERE machine_no = ? AND company_id = ?");
    $dup_stmt->bind_param("si", $machine_no, $company_id);
    $dup_stmt->execute();
    if ($dup_stmt->get_result()->num_rows > 0) {
        $error_message = "Machine number '$machine_no' already exists for your company.";
    } else {
        // Bonus tiers
        $b1_stitch = (int) ($_POST['bonus_stitch_1'] ?? 0);
        $b1_amount = (float) ($_POST['bonus_amount_1'] ?? 0);
        $b2_stitch = (int) ($_POST['bonus_stitch_2'] ?? 0);
        $b2_amount = (float) ($_POST['bonus_amount_2'] ?? 0);
        $b3_stitch = (int) ($_POST['bonus_stitch_3'] ?? 0);
        $b3_amount = (float) ($_POST['bonus_amount_3'] ?? 0);
        $b4_stitch = (int) ($_POST['bonus_stitch_4'] ?? 0);
        $b4_amount = (float) ($_POST['bonus_amount_4'] ?? 0);
        $b5_stitch = (int) ($_POST['bonus_stitch_5'] ?? 0);
        $b5_amount = (float) ($_POST['bonus_amount_5'] ?? 0);

        // Escape all values for safety (no bind_param complexity)
        $machine_no_esc = mysqli_real_escape_string($conn, $machine_no);
        
        $sql = "INSERT INTO machines
          (machine_no, machine_rate, head, 
           bonus_stitch_1, bonus_amount_1, bonus_stitch_2, bonus_amount_2, 
           bonus_stitch_3, bonus_amount_3, bonus_stitch_4, bonus_amount_4, 
           bonus_stitch_5, bonus_amount_5, created_at, user_id, company_id)
          VALUES
          ('$machine_no_esc', $machine_rate, $head,
           $b1_stitch, $b1_amount, $b2_stitch, $b2_amount,
           $b3_stitch, $b3_amount, $b4_stitch, $b4_amount,
           $b5_stitch, $b5_amount, NOW(), $user_id, $company_id)";

        if ($conn->query($sql)) {
            header("Location: list.php");
            exit;
        } else {
            $error_message = "Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Add Machine</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* (keep your existing styles – unchanged from previous version) */
    :root { --primary: #F39C12; --primary-light: #FEF5E7; --primary-dark: #E67E22; --border: #E9ECEF; --text-dark: #2C3E50; --text-muted: #6c757d; --bg-light: #F8F9FA; --danger: #e74c3c; --success: #27ae60; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%); font-family: 'Segoe UI', system-ui, sans-serif; }
    .main-container { margin-left: 14%; padding: 20px 24px; min-height: 100vh; transition: margin-left 0.3s ease; }
    .card { background: white; border: none; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden; }
    .card-header { background: white; padding: 14px 20px; border-bottom: 2px solid var(--primary); }
    .card-header h4 { color: var(--primary-dark); font-size: 1.2rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
    .card-header h4 i { color: var(--primary); }
    .card-body { padding: 20px; }
    .two-column-layout { display: flex; gap: 24px; flex-wrap: wrap; }
    .left-column { flex: 1.5; min-width: 280px; }
    .right-column { flex: 1; min-width: 280px; }
    .form-group { margin-bottom: 16px; }
    .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
    .form-row .form-group { flex: 1; margin-bottom: 0; min-width: 120px; }
    label { display: block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 6px; }
    label i { color: var(--primary); width: 14px; margin-right: 3px; }
    .form-control { width: 100%; padding: 10px 12px; font-size: 0.95rem; border: 1px solid var(--border); border-radius: 8px; transition: all 0.2s; background: white; }
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 2px rgba(243,156,18,0.1); }
    .bonus-list { background: var(--bg-light); border-radius: 12px; padding: 12px; margin-bottom: 16px; }
    .bonus-list-title { font-size: 0.8rem; font-weight: 700; color: var(--primary-dark); margin-bottom: 12px; display: flex; align-items: center; gap: 6px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
    .bonus-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--border); }
    .bonus-item:last-child { border-bottom: none; }
    .bonus-level { font-size: 0.75rem; font-weight: 600; min-width: 70px; }
    .bonus-details { display: flex; align-items: center; gap: 8px; }
    .bonus-details input { padding: 6px 10px; font-size: 0.85rem; border: 1px solid var(--border); border-radius: 6px; background: white; width: 85px; }
    .bonus-details input:focus { border-color: var(--primary); outline: none; }
    .test-section { background: var(--bg-light); border-radius: 10px; padding: 14px; margin-top: 16px; }
    .test-section h5 { font-size: 0.8rem; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; color: var(--primary-dark); }
    .bonus-preview { background: var(--primary-light); border-radius: 8px; padding: 8px 14px; display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; }
    .recent-machines { margin-top: 20px; }
    .recent-title { font-size: 0.75rem; font-weight: 700; color: var(--primary-dark); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .machine-list { max-height: 280px; overflow-y: auto; }
    .machine-item { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
    .machine-item:hover { border-color: var(--primary); }
    .machine-name { font-weight: 600; font-size: 0.85rem; color: var(--primary-dark); }
    .machine-details { font-size: 0.7rem; color: var(--text-muted); }
    .btn { padding: 8px 20px; font-size: 0.85rem; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .btn-secondary { background: #e9ecef; color: var(--text-dark); border: 1px solid var(--border); }
    .action-buttons { margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
    .alert-danger { background: #fef5e7; color: #856404; border-left: 4px solid var(--danger); padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem; }
    @media (max-width: 992px) {
        .main-container { margin-left: 0; padding: 16px; margin-top: 60px; }
        .two-column-layout { flex-direction: column; gap: 16px; }
        .form-row { flex-direction: column; gap: 10px; }
        .btn, .btn-primary, .btn-secondary { width: 100%; justify-content: center; }
        .bonus-item { flex-direction: column; align-items: flex-start; gap: 8px; }
        .bonus-details input { width: 100%; }
    }
</style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="card">
        <div class="card-header">
            <h4><i class="fas fa-plus-circle"></i> Add New Machine</h4>
        </div>
        <div class="card-body">
            <?php if ($error_message): ?>
                <div class="alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" id="machineForm">
                <div class="two-column-layout">
                    <div class="left-column">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Machine No</label>
                                <input type="text" name="machine_no" class="form-control" required placeholder="M-001" maxlength="15" value="Machine">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-microchip"></i> Head</label>
                                <input type="number" name="head" class="form-control" placeholder="1" max="99">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-rupee-sign"></i> Operator Rate</label>
                                <input type="number" step="0.05" name="machine_rate" class="form-control" placeholder="0.00">
                            </div>
                        </div>

                        <div class="test-section">
                            <h5><i class="fas fa-calculator"></i> Test Bonus Calculator</h5>
                            <div class="form-row">
                                <div class="form-group" style="min-width: 120px;">
                                    <label>Today's Stitches</label>
                                    <input type="number" id="test_stitches" class="form-control" placeholder="250" step="10000">
                                </div>
                                <div class="form-group" style="min-width: 100px;">
                                    <label>&nbsp;</label>
                                    <button type="button" id="calcBonusBtn" class="btn btn-primary" style="padding: 8px 16px;">Calculate</button>
                                </div>
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div id="bonusResult" class="bonus-preview" style="display: none;">
                                        <i class="fas fa-rupee-sign"></i> Bonus: Rs. <span id="bonusAmount">0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="recent-machines">
                            <div class="recent-title"><i class="fas fa-history"></i> Recently Added Machines</div>
                            <div class="machine-list">
                                <?php if ($recent_machines && $recent_machines->num_rows > 0): ?>
                                    <?php while($machine = $recent_machines->fetch_assoc()): ?>
                                        <div class="machine-item">
                                            <div>
                                                <div class="machine-name"><?= htmlspecialchars($machine['machine_no']) ?></div>
                                                <div class="machine-details">Rate: Rs <?= number_format($machine['machine_rate'], 2) ?> | Head: <?= $machine['head'] ?></div>
                                            </div>
                                            <div class="machine-details"><?= date('d/m/Y', strtotime($machine['created_at'])) ?></div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="machine-item"><div class="machine-name">No machines added yet</div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="right-column">
                        <div class="bonus-list">
                            <div class="bonus-list-title"><i class="fas fa-gift"></i> Bonus Tiers <small class="text-muted">(Daily stitches based)</small></div>
                            <?php for($i=1;$i<=5;$i++): ?>
                            <div class="bonus-item">
                                <div class="bonus-level">Tier <?= $i ?></div>
                                <div class="bonus-details">
                                    <input type="number" name="bonus_stitch_<?= $i ?>" placeholder="Stitches" value="0" step="10000">
                                    <span>→</span>
                                    <input type="number" step="10" name="bonus_amount_<?= $i ?>" placeholder="Rs." value="0.00">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" name="save" class="btn btn-primary"><i class="fas fa-save"></i> Save Machine</button>
                    <a href="list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('calcBonusBtn').addEventListener('click', function() {
    const stitches = parseInt(document.getElementById('test_stitches').value || '0', 10);
    function getInt(name) { return parseInt(document.querySelector(`[name="${name}"]`).value || '0', 10); }
    function getFloat(name) { return parseFloat(document.querySelector(`[name="${name}"]`).value || '0'); }
    const tiers = [
        { min: getInt('bonus_stitch_1'), amt: getFloat('bonus_amount_1') },
        { min: getInt('bonus_stitch_2'), amt: getFloat('bonus_amount_2') },
        { min: getInt('bonus_stitch_3'), amt: getFloat('bonus_amount_3') },
        { min: getInt('bonus_stitch_4'), amt: getFloat('bonus_amount_4') },
        { min: getInt('bonus_stitch_5'), amt: getFloat('bonus_amount_5') }
    ];
    let applicable = { min: 0, amt: 0 };
    tiers.forEach(t => { if (!isNaN(t.min) && stitches >= t.min && t.amt > 0 && t.min >= applicable.min) applicable = t; });
    document.getElementById('bonusAmount').textContent = applicable.amt.toFixed(2);
    document.getElementById('bonusResult').style.display = 'flex';
});
</script>
</body>
</html>