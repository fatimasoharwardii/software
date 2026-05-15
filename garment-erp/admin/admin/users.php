<?php
session_start();
require_once "../../config/db.php";
require_once "../../includes/functions.php";

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../login.php");
    exit;
}
$company_id = $_SESSION['company_id'];

// ---------- ADD USER ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $errors = [];
    if (empty($name)) $errors[] = "Name is required";
    if (empty($username)) $errors[] = "Username is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($password)) $errors[] = "Password is required";
    if (empty($errors)) {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Username or email already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, username, email, password, role, is_active, company_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssii", $name, $username, $email, $hashed, $role, $is_active, $company_id);
            if ($stmt->execute()) {
                $success = "User added successfully!";
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// ---------- RESET PASSWORD ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    $check = $conn->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
    $check->bind_param("ii", $user_id, $company_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND company_id = ?");
        $update->bind_param("sii", $hashed, $user_id, $company_id);
        if ($update->execute()) {
            $success = "Password reset successfully!";
        } else {
            $error = "Error resetting password.";
        }
    } else {
        $error = "User not found.";
    }
}

// ---------- DELETE USER ----------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if ($id != $_SESSION['user_id']) {
        $conn->query("DELETE FROM users WHERE id = $id AND company_id = " . intval($company_id));
        $conn->query("DELETE FROM user_permissions WHERE user_id = $id");
        $success = "User deleted.";
    } else {
        $error = "You cannot delete your own account.";
    }
    header("Location: users.php?msg=" . urlencode($success ?? $error));
    exit;
}

// ---------- UPDATE PERMISSIONS ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_permissions'])) {
    $user_id = intval($_POST['user_id']);
    $conn->query("DELETE FROM user_permissions WHERE user_id = $user_id");
    $pages = $_POST['pages'] ?? [];
    $inserted = 0;
    foreach ($pages as $page_id => $perms) {
        $can_view = isset($perms['view']) ? 1 : 0;
        $can_edit = isset($perms['edit']) ? 1 : 0;
        if ($can_view) {
            $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, page_id, can_view, can_edit) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiii", $user_id, $page_id, $can_view, $can_edit);
            $stmt->execute();
            $inserted++;
        }
    }
    if ($user_id == $_SESSION['user_id']) {
        loadUserPermissions($user_id);
    }
    $success = "Permissions updated ($inserted entries).";
    header("Location: users.php?msg=" . urlencode($success));
    exit;
}

// ---------- FETCH DATA ----------
$users = $conn->query("SELECT id, name, username, email, role, is_active FROM users WHERE company_id = " . intval($company_id) . " ORDER BY id");
$pageResult = $conn->query("SELECT id, page_url, page_name, module, is_menu_item FROM pages ORDER BY module, page_name");
$groupedMenuPages = [];
$groupedAllPages = [];
while ($row = $pageResult->fetch_assoc()) {
    $groupedAllPages[$row['module']][] = $row;
    if ($row['is_menu_item']) {
        $groupedMenuPages[$row['module']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>User Management · Garment ERP</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #f97316;
            --primary-light: #fff7ed;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
            --radius: 1.25rem;
        }
        body {
            background: var(--bg);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text-dark);
            margin: 0;
            padding: 0;
        }
        /* 14% left margin for desktop */
        .main-container {
            margin-left: 14%;
            padding: 28px 2rem;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        @media (max-width: 992px) {
            .main-container {
                margin-left: 0;
                padding: 16px;
            }
        }
        h2.page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        h2.page-title i {
            color: var(--primary);
        }
        .card {
            border: none;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            background: #ffffff;
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            padding: 1rem 1.25rem;
            border-radius: var(--radius) var(--radius) 0 0 !important;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-control, .form-select {
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            font-size: 0.875rem;
            padding: 0.6rem 1rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
        }
        .btn {
            border-radius: 2.5rem;
            font-weight: 600;
            padding: 0.5rem 1.25rem;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: var(--primary);
            border: none;
        }
        .btn-primary:hover {
            background: #ea580c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249,115,22,0.35);
        }
        .btn-outline-primary {
            border: 1px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }
        .btn-outline-primary:hover {
            background: var(--primary);
            color: #fff;
        }
        .btn-warning {
            background: #fbbf24;
            border: none;
            color: #1e293b;
        }
        .btn-warning:hover {
            background: #f59e0b;
        }
        .btn-danger {
            background: #ef4444;
            border: none;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
        .table {
            background: #ffffff;
            border-radius: 1rem;
            overflow: hidden;
        }
        .table thead th {
            background: #f1f5f9;
            font-weight: 600;
            border-bottom: 2px solid var(--border);
            color: var(--text-dark);
            font-size: 0.85rem;
            padding: 0.75rem 1rem;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.9rem;
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .badge-active {
            background: #dcfce7;
            color: #166534;
            padding: 0.3em 0.8em;
            border-radius: 2rem;
            font-weight: 500;
        }
        .badge-inactive {
            background: #f1f5f9;
            color: #475569;
            padding: 0.3em 0.8em;
            border-radius: 2rem;
            font-weight: 500;
        }
        /* Modal styling */
        .modal-content {
            border: none;
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        .modal-header {
            background: var(--primary-light);
            border-bottom: 1px solid #ffedd5;
            border-radius: 1.5rem 1.5rem 0 0;
            padding: 1rem 1.5rem;
        }
        .modal-title {
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        /* 14% left offset for modal dialog on large screens */
        @media (min-width: 992px) {
            .modal-dialog {
                margin-left: 14%;
                max-width: calc(100% - 14%);
            }
        }
        .accordion-item {
            border: 1px solid var(--border);
            border-radius: 1rem !important;
            margin-bottom: 0.75rem;
            overflow: hidden;
        }
        .accordion-button {
            padding: 0.9rem 1.25rem;
            font-weight: 600;
            color: #334155;
            background: #ffffff;
            box-shadow: none;
            display: flex;
            align-items: center;
        }
        .accordion-button:not(.collapsed) {
            background: #fff7ed;
            color: #c2410c;
        }
        .accordion-button:focus {
            box-shadow: none;
            border-color: #fdba74;
        }
        .folder-actions {
            display: flex;
            gap: 1.5rem;
            margin-left: auto;
        }
        .folder-actions label {
            font-weight: 600;
            color: #c2410c;
            font-size: 0.8rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin: 0;
        }
        .page-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .page-row:last-child {
            border-bottom: none;
        }
        .page-name {
            font-size: 0.9rem;
            color: #1e293b;
        }
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        .action-btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<?php include "../../includes/navbar.php"; ?>
<div class="main-container">
    <h2 class="page-title"><i class="fas fa-users-cog"></i> User Management</h2>

    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <!-- Add User Card -->
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-user-plus"></i> Add New User</div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-3">
                    <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                </div>
                <div class="col-md-3">
                    <input type="text" name="username" class="form-control" placeholder="Username" required>
                </div>
                <div class="col-md-3">
                    <input type="email" name="email" class="form-control" placeholder="Email" required>
                </div>
                <div class="col-md-2">
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                </div>
                <div class="col-md-2">
                    <select name="role" class="form-select">
                        <option value="user">User</option>
                        <option value="admin">Super Admin</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-center">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="isActive" checked>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" name="add_user" class="btn btn-primary w-100"><i class="fas fa-save"></i> Add User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Users -->
    <div class="card">
        <div class="card-header"><i class="fas fa-users"></i> Existing Users</div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php while($user = $users->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($user['name']) ?></strong></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><span class="text-capitalize"><?= $user['role'] ?></span></td>
                        <td>
                            <?= $user['is_active'] 
                                ? '<span class="badge-active">Active</span>' 
                                : '<span class="badge-inactive">Inactive</span>' ?>
                        </td>
                        <td>
                            <div class="action-btn-group">
                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#permModal<?= $user['id'] ?>">
                                    <i class="fas fa-key"></i> Permissions
                                </button>
                                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#passModal<?= $user['id'] ?>">
                                    <i class="fas fa-lock"></i> New Pass
                                </button>
                                <?php if($user['id'] != $_SESSION['user_id']): ?>
                                <a href="?delete=<?= $user['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user?')">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <!-- Reset Password Modal -->
                    <div class="modal fade" id="passModal<?= $user['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-sm">
                            <div class="modal-content">
                                <form method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-lock"></i> Reset Password – <?= htmlspecialchars($user['name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <label class="form-label">New Password</label>
                                        <input type="password" name="new_password" class="form-control" required minlength="4">
                                    </div>
                                    <div class="modal-footer">
                                        <button type="submit" name="reset_password" class="btn btn-primary">Set Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Permissions Modal -->
                    <div class="modal fade" id="permModal<?= $user['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-xl">
                            <div class="modal-content">
                                <form method="POST" id="permForm<?= $user['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Permissions: <?= htmlspecialchars($user['name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <?php
                                        $current_perms = [];
                                        $perm_res = $conn->query("SELECT page_id, can_view, can_edit FROM user_permissions WHERE user_id = {$user['id']}");
                                        while($p = $perm_res->fetch_assoc()) $current_perms[$p['page_id']] = $p;
                                        ?>
                                        <div class="accordion" id="accordion<?= $user['id'] ?>">
                                            <?php
                                            $idx = 0;
                                            foreach($groupedMenuPages as $module => $menuPages):
                                                $safeId = 'acc' . $user['id'] . '_' . $idx++;
                                                $allModulePages = $groupedAllPages[$module] ?? [];
                                                $hiddenPages = array_filter($allModulePages, fn($p) => !$p['is_menu_item']);
                                            ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header">
                                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $safeId ?>">
                                                        <i class="fas fa-folder-open me-2 text-warning"></i> <?= htmlspecialchars($module) ?>
                                                        <span class="folder-actions">
                                                            <label><input type="checkbox" class="view-all-folder" data-mod="<?= $safeId ?>"> View All</label>
                                                            <label><input type="checkbox" class="edit-all-folder" data-mod="<?= $safeId ?>"> Edit All</label>
                                                        </span>
                                                    </button>
                                                </h2>
                                                <div id="<?= $safeId ?>" class="accordion-collapse collapse show" data-bs-parent="#accordion<?= $user['id'] ?>">
                                                    <div class="accordion-body p-0">
                                                        <!-- Hidden dependent checkboxes -->
                                                        <?php foreach($hiddenPages as $hp):
                                                            $v = isset($current_perms[$hp['id']]) && $current_perms[$hp['id']]['can_view'];
                                                            $e = isset($current_perms[$hp['id']]) && $current_perms[$hp['id']]['can_edit'];
                                                        ?>
                                                        <input type="checkbox" class="hidden-dep" data-mod="<?= $safeId ?>" data-type="view" name="pages[<?= $hp['id'] ?>][view]" value="1" <?= $v ? 'checked' : '' ?> style="display:none">
                                                        <input type="checkbox" class="hidden-dep" data-mod="<?= $safeId ?>" data-type="edit" name="pages[<?= $hp['id'] ?>][edit]" value="1" <?= $e ? 'checked' : '' ?> style="display:none">
                                                        <?php endforeach; ?>
                                                        
                                                        <!-- Visible menu entries -->
                                                        <?php foreach($menuPages as $page):
                                                            $v = isset($current_perms[$page['id']]) && $current_perms[$page['id']]['can_view'];
                                                            $e = isset($current_perms[$page['id']]) && $current_perms[$page['id']]['can_edit'];
                                                        ?>
                                                        <div class="page-row">
                                                            <div class="page-name">
                                                                <i class="fas fa-file-alt me-2 text-muted"></i> <?= htmlspecialchars($page['page_name']) ?> 
                                                                <small class="text-muted">(<?= $page['page_url'] ?>)</small>
                                                            </div>
                                                            <div class="page-perms d-flex gap-4">
                                                                <div class="form-check">
                                                                    <input type="checkbox" class="form-check-input page-view" name="pages[<?= $page['id'] ?>][view]" value="1" <?= $v ? 'checked' : '' ?> data-mod="<?= $safeId ?>">
                                                                    <label>View</label>
                                                                </div>
                                                                <div class="form-check">
                                                                    <input type="checkbox" class="form-check-input page-edit" name="pages[<?= $page['id'] ?>][edit]" value="1" <?= $e ? 'checked' : '' ?> <?= !$v ? 'disabled' : '' ?>>
                                                                    <label>Edit</label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="submit" name="update_permissions" class="btn btn-primary"><i class="fas fa-save"></i> Save Permissions</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update folder‑level checkboxes when modal shown
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('show.bs.modal', function() {
            const items = this.querySelectorAll('.accordion-item');
            items.forEach(item => updateFolderChecks(item));
        });
    });

    document.addEventListener('change', function(e) {
        // Folder "View All"
        if (e.target.classList.contains('view-all-folder')) {
            const cont = e.target.closest('.accordion-item');
            setAllViews(cont, e.target.checked);
            updateFolderChecks(cont);
        }
        // Folder "Edit All"
        if (e.target.classList.contains('edit-all-folder')) {
            const cont = e.target.closest('.accordion-item');
            setAllEdits(cont, e.target.checked);
            updateFolderChecks(cont);
        }
        // Individual view
        if (e.target.classList.contains('page-view')) {
            const row = e.target.closest('.page-row');
            const edit = row.querySelector('.page-edit');
            if (edit) {
                edit.disabled = !e.target.checked;
                if (!e.target.checked) edit.checked = false;
            }
            updateFolderChecks(e.target.closest('.accordion-item'));
        }
        // Individual edit → cascade hidden dependents
        if (e.target.classList.contains('page-edit')) {
            const cont = e.target.closest('.accordion-item');
            if (e.target.checked) {
                setHiddenDeps(cont, true);
            }
            updateFolderChecks(cont);
        }
    });

    function getMod(container) {
        return container.querySelector('[data-mod]')?.getAttribute('data-mod');
    }

    function setAllViews(cont, checked) {
        cont.querySelectorAll('.page-view').forEach(cb => { cb.checked = checked; cb.dispatchEvent(new Event('change', {bubbles: true})); });
        cont.querySelectorAll('.hidden-dep[data-type="view"]').forEach(cb => cb.checked = checked);
    }

    function setAllEdits(cont, checked) {
        cont.querySelectorAll('.page-edit:not(:disabled)').forEach(cb => { cb.checked = checked; cb.dispatchEvent(new Event('change', {bubbles: true})); });
        cont.querySelectorAll('.hidden-dep[data-type="edit"]').forEach(cb => cb.checked = checked);
        if (checked) {
            cont.querySelectorAll('.hidden-dep[data-type="view"]').forEach(cb => cb.checked = true);
        }
    }

    function setHiddenDeps(cont, checked) {
        cont.querySelectorAll('.hidden-dep[data-type="view"]').forEach(cb => cb.checked = checked);
        cont.querySelectorAll('.hidden-dep[data-type="edit"]').forEach(cb => cb.checked = checked);
    }

    function updateFolderChecks(cont) {
        const viewAllBtn = cont.querySelector('.view-all-folder');
        const editAllBtn = cont.querySelector('.edit-all-folder');
        const views = cont.querySelectorAll('.page-view');
        const edits = cont.querySelectorAll('.page-edit:not(:disabled)');
        const hiddenViews = cont.querySelectorAll('.hidden-dep[data-type="view"]');
        const hiddenEdits = cont.querySelectorAll('.hidden-dep[data-type="edit"]');

        const allViewChecked = [...views, ...hiddenViews].every(cb => cb.checked);
        const allEditChecked = [...edits, ...hiddenEdits].every(cb => cb.checked);

        if (viewAllBtn) viewAllBtn.checked = allViewChecked;
        if (editAllBtn) editAllBtn.checked = allEditChecked;
    }
});
</script>
</body>
</html>