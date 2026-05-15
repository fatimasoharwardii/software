<?php
/**
 * functions.php - Core functions for Garment ERP
 * Includes sanitization, formatting, costing calculations, and permission management.
 */

// ==================== BASIC UTILITIES ====================

function sanitize_input($data) {
    return htmlspecialchars(trim($data));
}

function format_date($date) {
    return date('d-m-Y', strtotime($date));
}

function format_currency($amount) {
    return number_format($amount, 2);
}

function redirect($location) {
    header('Location: ' . $location);
    exit;
}

function show_error($message) {
    echo '<div class="alert alert-error">' . htmlspecialchars($message) . '</div>';
}

function show_success($message) {
    echo '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
}

// ==================== COSTING FUNCTIONS (Stitching Billing) ====================

/**
 * Calculate per-piece costing from stitching bill entries by type
 */
function calculatePerPieceCosting($conn, $job_no) {
    $result = [];
    $jobQ = $conn->query("SELECT quantity FROM jobs WHERE job_no='$job_no' LIMIT 1");
    if(!$jobQ || $jobQ->num_rows === 0) return $result;
    $jobData = $jobQ->fetch_assoc();
    $jobQty = intval($jobData['quantity']) ?: 1;
    
    $query = $conn->query("
        SELECT tab_type, name, SUM(amount) as total_amount, COUNT(*) as entries_count, AVG(rate) as avg_rate
        FROM stitching_bill_items
        WHERE job_no='$job_no'
        GROUP BY tab_type, name
        ORDER BY tab_type, name
    ");
    if($query && $query->num_rows > 0) {
        while($row = $query->fetch_assoc()) {
            $perPiece = ($jobQty > 0) ? round(($row['total_amount'] / $jobQty), 2) : 0;
            $result[] = [
                'type' => $row['tab_type'],
                'name' => $row['name'],
                'total_amount' => $row['total_amount'],
                'per_piece' => $perPiece,
                'entries' => $row['entries_count'],
                'avg_rate' => $row['avg_rate']
            ];
        }
    }
    return $result;
}

/**
 * Get per-piece costing summary by type only (consolidated)
 */
function getPerPieceSummaryByType($conn, $job_no) {
    $result = [];
    $jobQ = $conn->query("SELECT quantity FROM jobs WHERE job_no='$job_no' LIMIT 1");
    if(!$jobQ || $jobQ->num_rows === 0) return $result;
    $jobData = $jobQ->fetch_assoc();
    $jobQty = intval($jobData['quantity']) ?: 1;
    
    $query = $conn->query("
        SELECT tab_type, SUM(amount) as total_amount, COUNT(DISTINCT name) as vendors_count, COUNT(*) as entries_count
        FROM stitching_bill_items
        WHERE job_no='$job_no'
        GROUP BY tab_type
        ORDER BY tab_type
    ");
    if($query && $query->num_rows > 0) {
        while($row = $query->fetch_assoc()) {
            $perPiece = ($jobQty > 0) ? round(($row['total_amount'] / $jobQty), 2) : 0;
            $result[$row['tab_type']] = [
                'total_amount' => $row['total_amount'],
                'per_piece' => $perPiece,
                'entries' => $row['entries_count'],
                'vendors' => $row['vendors_count']
            ];
        }
    }
    return $result;
}

/**
 * Search stitching bill entries by type
 */
function searchStitchingByType($conn, $job_no, $searchType) {
    $result = [];
    $safe_type = mysqli_real_escape_string($conn, $searchType);
    $safe_job = mysqli_real_escape_string($conn, $job_no);
    $query = $conn->query("
        SELECT * FROM stitching_bill_items
        WHERE job_no='$safe_job' AND tab_type='$safe_type'
        ORDER BY name, created_at DESC
    ");
    if($query && $query->num_rows > 0) {
        while($row = $query->fetch_assoc()) $result[] = $row;
    }
    return $result;
}

/**
 * Get all available stitching types from bill entries
 */
function getAvailableStitchingTypes($conn, $job_no) {
    $result = [];
    $safe_job = mysqli_real_escape_string($conn, $job_no);
    $query = $conn->query("SELECT DISTINCT tab_type FROM stitching_bill_items WHERE job_no='$safe_job' ORDER BY tab_type");
    if($query && $query->num_rows > 0) {
        while($row = $query->fetch_assoc()) $result[] = $row['tab_type'];
    }
    return $result;
}

// ==================== PERMISSION FUNCTIONS (used by auth.php) ====================

/**
 * Check if current user has permission for a given page URL.
 * Super admin (role = 'admin') always returns true.
 * 
 * @param string $page_url Relative URL like 'dashboard/dashboard.php' or 'reports/fabric_report.php'
 * @param string $type 'view' or 'edit'
 * @return bool
 */
function hasPermission($page_url, $type = 'view') {
    if (!isset($_SESSION['user_id'])) return false;
    // Admin (super admin) sees everything
    if ($_SESSION['user_role'] === 'admin') return true;
    
    $permissions = $_SESSION['user_permissions'] ?? [];
    if (!isset($permissions[$page_url])) return false;
    
    if ($type === 'edit') {
        return !empty($permissions[$page_url]['can_edit']);
    }
    return !empty($permissions[$page_url]['can_view']);
}

/**
 * Legacy alias for hasPermission (used in older code)
 * @param string $page_url
 * @param bool $editRequired
 * @return bool
 */
function hasAccess($page_url, $editRequired = false) {
    return hasPermission($page_url, $editRequired ? 'edit' : 'view');
}

/**
 * Load user page permissions into session.
 * Must be called after login.
 * 
 * @param int $user_id
 * @return void
 */
function loadUserPermissions($user_id) {
    global $conn;
    if (!$conn) {
        // Fallback: try to include database connection again
        require_once __DIR__ . '/../config/db.php';
        global $conn;
    }
    $permissions = [];
    $sql = "SELECT p.page_url, up.can_view, up.can_edit 
            FROM user_permissions up 
            JOIN pages p ON up.page_id = p.id 
            WHERE up.user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("loadUserPermissions prepare failed: " . $conn->error);
        return;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $permissions[$row['page_url']] = [
            'can_view' => (bool)$row['can_view'],
            'can_edit' => (bool)$row['can_edit']
        ];
    }
    $_SESSION['user_permissions'] = $permissions;
}

// ==================== COMPANY ISOLATION ====================

/**
 * Returns a SQL condition to filter by current company.
 * 
 * @param string $table_alias Optional alias (e.g., 'j.')
 * @return string SQL condition (with AND prefix)
 */
function getCompanyFilter($table_alias = '') {
    if (!isset($_SESSION['company_id'])) return '1=0';
    $alias = $table_alias ? $table_alias . '.' : '';
    return " AND {$alias}company_id = " . (int)$_SESSION['company_id'];
}

/**
 * Get current user's company ID
 * @return int|null
 */
function getCurrentCompanyId() {
    return $_SESSION['company_id'] ?? null;
}

/**
 * Check if given company ID matches current user's company
 * @param int $company_id
 * @return bool
 */
function isSameCompany($company_id) {
    return isset($_SESSION['company_id']) && $_SESSION['company_id'] == $company_id;
}

// ==================== ADDITIONAL HELPER (optional) ====================
/**
 * Get the current page URL relative to project root.
 * Useful for permission checks when auto-detection is needed.
 * 
 * @param string $base_path Your project base path, e.g., '/garment_erp/'
 * @return string
 */
function getCurrentPageUrl($base_path = '/garment_erp/') {
    $uri = $_SERVER['REQUEST_URI'];
    $page = str_replace($base_path, '', $uri);
    $page = strtok($page, '?');
    return $page;
}
?>