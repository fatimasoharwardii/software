<?php
$page_identifier = 'jobs/view.php';
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Ensure jobs table has company_id column
$check = $conn->query("SHOW COLUMNS FROM jobs LIKE 'company_id'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE jobs ADD COLUMN company_id INT DEFAULT NULL");
    $conn->query("UPDATE jobs SET company_id = 1 WHERE company_id IS NULL");
    $conn->query("ALTER TABLE jobs MODIFY company_id INT NOT NULL");
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header("Location: list.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $id, $company_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    header("Location: list.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Job Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* (CSS unchanged – same as original) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #F5F7FA;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #2C3E50;
        }

        .main-container {
            margin-left: 14%;
            padding: 24px 32px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .page-header {
            margin-bottom: 28px;
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(135deg, #F39C12 0%, #E67E22 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h2 i {
            background: none;
            -webkit-text-fill-color: #F39C12;
            color: #F39C12;
        }

        .job-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #E9ECEF;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card-header-custom {
            background: white;
            padding: 20px 28px;
            border-bottom: 3px solid #F39C12;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header-custom h4 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 700;
            color: #2C3E50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header-custom h4 i {
            color: #F39C12;
        }

        .job-status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .job-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            padding: 28px;
        }

        .info-section {
            background: #F8F9FA;
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s;
        }

        .info-section:hover {
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #F39C12;
        }

        .info-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-title i {
            color: #F39C12;
            font-size: 0.9rem;
        }

        .info-content {
            font-size: 1rem;
            font-weight: 500;
            color: #2C3E50;
            word-break: break-word;
        }

        .info-content strong {
            color: #F39C12;
            font-weight: 700;
        }

        .image-section {
            padding: 0 28px 28px 28px;
        }

        .image-container {
            background: #F8F9FA;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 1px solid #E9ECEF;
        }

        .image-container img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .no-image {
            padding: 60px;
            text-align: center;
            color: #6c757d;
        }

        .no-image i {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .btn-back {
            background: #F39C12;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-back:hover {
            background: #E67E22;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
        }

        .btn-edit {
            background: #2C3E50;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-edit:hover {
            background: #1a252f;
            color: white;
            transform: translateY(-2px);
        }

        .status-Embroidery { background: #9b59b6; color: white; }
        .status-Stitching { background: #e74c3c; color: white; }
        .status-CMT { background: #34495e; color: white; }
        .status-Backup { background: #3498db; color: white; }
        .status-Ready { background: #2ecc71; color: white; }
        .status-Incomplete { background: #f39c12; color: white; }
        .status-Checking { background: #1abc9c; color: white; }
        .status-default { background: #95a5a6; color: white; }

        .action-buttons {
            padding: 20px 28px 28px 28px;
            border-top: 1px solid #E9ECEF;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        @media (max-width: 1200px) {
            .main-container {
                margin-left: 10%;
            }
        }

        @media (max-width: 992px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
                margin-top: 15px;
            }
            .job-info-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                padding: 20px;
            }
            .card-header-custom {
                flex-direction: column;
                align-items: flex-start;
                padding: 16px 20px;
            }
            .card-header-custom h4 {
                font-size: 1.2rem;
            }
            .info-section {
                padding: 16px;
            }
            .image-section {
                padding: 0 20px 20px 20px;
            }
            .image-container img {
                max-height: 300px;
            }
            .no-image {
                padding: 40px;
            }
            .btn-back, .btn-edit {
                padding: 8px 20px;
                font-size: 0.85rem;
            }
            .page-header h2 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 12px;
                margin-top: 10px;
            }
            .job-info-grid {
                padding: 16px;
                gap: 12px;
            }
            .card-header-custom {
                padding: 12px 16px;
            }
            .info-section {
                padding: 14px;
            }
            .info-title {
                font-size: 0.7rem;
                margin-bottom: 8px;
            }
            .info-content {
                font-size: 0.9rem;
            }
            .image-section {
                padding: 0 16px 16px 16px;
            }
            .image-container {
                padding: 16px;
            }
            .image-container img {
                max-height: 250px;
            }
            .no-image {
                padding: 30px;
            }
            .no-image i {
                font-size: 2.5rem;
            }
            .btn-back, .btn-edit {
                padding: 8px 16px;
                font-size: 0.8rem;
            }
            .action-buttons {
                padding: 16px !important;
            }
        }

        @media (max-width: 576px) {
            .main-container {
                padding: 10px;
                margin-top: 8px;
            }
            .page-header h2 {
                font-size: 1.3rem;
            }
            .card-header-custom h4 {
                font-size: 1rem;
            }
            .job-status-badge {
                font-size: 0.75rem;
                padding: 4px 12px;
            }
            .info-content {
                font-size: 0.85rem;
            }
            .btn-back, .btn-edit {
                width: 100%;
                justify-content: center;
            }
            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .job-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>

<div class="main-container">
    <div class="page-header">
        <h2>
            <i class="fas fa-eye"></i>
            Job Details
        </h2>
    </div>

    <div class="job-card">
        <div class="card-header-custom">
            <h4>
                <i class="fas fa-briefcase"></i>
                Job #<?php echo htmlspecialchars($row['job_no']); ?>
            </h4>
            <?php
            $status_class = 'status-default';
            switch($row['status']) {
                case 'Embroidery': $status_class = 'status-Embroidery'; break;
                case 'Stitching': $status_class = 'status-Stitching'; break;
                case 'CMT': $status_class = 'status-CMT'; break;
                case 'Backup': $status_class = 'status-Backup'; break;
                case 'Ready': $status_class = 'status-Ready'; break;
                case 'Incomplete': $status_class = 'status-Incomplete'; break;
                case 'Checking': $status_class = 'status-Checking'; break;
            }
            ?>
            <span class="job-status-badge <?php echo $status_class; ?>">
                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['status']); ?>
            </span>
        </div>

        <div class="job-info-grid">
            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-palette"></i>
                    DESIGN INFORMATION
                </div>
                <div class="info-content">
                    <strong>Design Name:</strong> <?php echo htmlspecialchars($row['design_name'] ?? 'N/A'); ?>
                </div>
            </div>

            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-trademark"></i>
                    BRAND INFORMATION
                </div>
                <div class="info-content">
                    <strong>Brand Name:</strong> <?php echo htmlspecialchars($row['brand_name'] ?? 'N/A'); ?>
                </div>
            </div>

            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-ruler"></i>
                    SIZE & QUANTITY
                </div>
                <div class="info-content">
                    <strong>Size:</strong> <?php echo htmlspecialchars($row['size'] ?? 'N/A'); ?><br>
                    <strong>Quantity:</strong> <?php echo number_format($row['quantity']); ?> pcs
                </div>
            </div>

            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-tshirt"></i>
                    FABRIC INFORMATION
                </div>
                <div class="info-content">
                    <strong>Fabric:</strong> <?php echo htmlspecialchars($row['fabric_name'] ?? 'N/A'); ?>
                </div>
            </div>

            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-palette"></i>
                    EMBROIDERY DETAILS
                </div>
                <div class="info-content">
                    <strong>Rate:</strong> Rs. <?php echo number_format($row['embroidery_rate'] ?? 0, 2); ?><br>
                    <strong>Vendor:</strong> <?php echo htmlspecialchars($row['embroidery_vendor_name'] ?? 'N/A'); ?>
                </div>
            </div>

            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-calendar-alt"></i>
                    DATES
                </div>
                <div class="info-content">
                    <strong>Start Date:</strong> <?php echo htmlspecialchars($row['start_date'] ?? $row['job_date'] ?? 'N/A'); ?><br>
                    <strong>Delivery Date:</strong> <?php echo htmlspecialchars($row['delivery_date'] ?? 'N/A'); ?>
                </div>
            </div>

            <?php if(!empty($row['description'])): ?>
            <div class="info-section">
                <div class="info-title">
                    <i class="fas fa-align-left"></i>
                    DESCRIPTION
                </div>
                <div class="info-content">
                    <?php echo nl2br(htmlspecialchars($row['description'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="image-section">
            <div class="image-container">
                <div class="info-title mb-3">
                    <i class="fas fa-image"></i>
                    DESIGN IMAGE
                </div>
                <?php 
                $image_found = false;
                $image_path = "";
                if(!empty($row['image'])) {
                    $possible_paths = [
                        "../../assets/uploads/" . $row['image'],
                        "../assets/uploads/" . $row['image'],
                        "assets/uploads/" . $row['image']
                    ];
                    foreach($possible_paths as $path) {
                        if(file_exists($path)) {
                            $image_path = $path;
                            $image_found = true;
                            break;
                        }
                    }
                }
                if(!$image_found && !empty($row['design_image'])) {
                    $possible_paths = [
                        "../../assets/uploads/" . $row['design_image'],
                        "../assets/uploads/" . $row['design_image'],
                        "assets/uploads/" . $row['design_image']
                    ];
                    foreach($possible_paths as $path) {
                        if(file_exists($path)) {
                            $image_path = $path;
                            $image_found = true;
                            break;
                        }
                    }
                }
                ?>
                <?php if($image_found): ?>
                    <img src="<?php echo $image_path; ?>" 
                         alt="<?php echo htmlspecialchars($row['design_name']); ?>">
                <?php else: ?>
                    <div class="no-image">
                        <i class="fas fa-image"></i>
                        <p>No image available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="action-buttons">
            <a href="list.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="../dashboard/dashboard.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn-edit ms-2">
                <i class="fas fa-edit"></i> Edit Job
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>