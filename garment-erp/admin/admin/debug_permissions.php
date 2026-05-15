<?php
session_start();
require_once "includes/db.php";
require_once "includes/functions.php";

if ($_SESSION['user_role'] !== 'admin') die("Only admin can debug");

echo "<pre>";
echo "User ID: " . $_SESSION['user_id'] . "\n";
echo "Role: " . $_SESSION['user_role'] . "\n";
echo "Company ID: " . $_SESSION['company_id'] . "\n";
echo "Permissions in session:\n";
print_r($_SESSION['user_permissions'] ?? 'No permissions loaded');
echo "\n\nCheck database user_permissions:\n";
$uid = $_SESSION['user_id'];
$res = $conn->query("SELECT p.page_url, up.can_view FROM user_permissions up JOIN pages p ON up.page_id = p.id WHERE up.user_id = $uid");
while($row = $res->fetch_assoc()) {
    echo $row['page_url'] . " => view=" . $row['can_view'] . "\n";
}
echo "</pre>";
?>