<?php
// includes/auth.php – final safe version
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// We need $conn for permission reload. If not set, try to include db.php
if (!isset($conn) || $conn === null) {
    require_once __DIR__ . '/../config/db.php';
}

// $page_identifier MUST be defined before including this file
if (!isset($page_identifier)) {
    die("System error: \$page_identifier not set in " . basename($_SERVER['PHP_SELF']));
}

// Super admin bypass
if ($_SESSION['user_role'] === 'admin') {
    return;
}

// If the required permission is not in session, force reload from database
if (!isset($_SESSION['user_permissions'][$page_identifier])) {
    // Make sure functions.php is loaded for loadUserPermissions()
    if (!function_exists('loadUserPermissions')) {
        require_once __DIR__ . '/functions.php';
    }
    loadUserPermissions($_SESSION['user_id']);
}

// Final check using hasPermission (which uses the session)
if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/functions.php';
}

if (!hasPermission($page_identifier, 'view')) {
    $have = isset($_SESSION['user_permissions']) ? array_keys($_SESSION['user_permissions']) : [];
    http_response_code(403);
    echo "<div style='text-align:center; padding:50px; font-family:Inter,Segoe UI,sans-serif'>
            <h2>Access Denied</h2>
            <p>You do not have permission for <strong>$page_identifier</strong></p>
            <p style='font-size:0.8rem; color:gray'>Session permissions: " . implode(', ', $have) . "</p>
            <a href='../dashboard/dashboard.php'>← Back to Dashboard</a>
          </div>";
    exit;
}
?>