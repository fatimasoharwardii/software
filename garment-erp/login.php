<?php
session_start();
require_once "config/db.php";
require_once "includes/functions.php";

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login = trim($_POST['login']);
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT id, name, username, email, password, role, is_active, company_id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        if ($user['is_active'] != 1) {
            $error = "Account is inactive. Contact admin.";
        } elseif (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['company_id'] = $user['company_id'];
            
            // Load page permissions for this user
            loadUserPermissions($user['id']);
            
            // Redirect to dashboard
            header("Location: admin/dashboard/dashboard.php");
            exit;
        } else {
            $error = "Invalid password";
        }
    } else {
        $error = "User not found";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Garment ERP</title>
    <!-- Bootstrap 5 CSS + Icons + Google Fonts (Inter) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f1f3f5 0%, #f9fafb 100%);
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        /* Modern card container */
        .login-card {
            background: #ffffff;
            border: 1.5px solid #e1e5ec;
            border-radius: 28px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
            max-width: 460px;
            width: 100%;
            transition: box-shadow 0.25s ease, transform 0.2s ease;
        }

        .login-card:hover {
            box-shadow: 0 20px 35px rgba(0, 0, 0, 0.12);
            transform: translateY(-3px);
        }

        .login-card-body {
            padding: 2.4rem 2rem 2rem 2rem;
        }

        /* Title area with icon */
        .login-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            margin-bottom: 2rem;
            color: #1f2a3e;
            font-weight: 800;
            font-size: 1.95rem;
            letter-spacing: -0.3px;
        }

        .login-title i {
            font-size: 2.2rem;
            color: #ff9800;
            background: #fff2e0;
            padding: 8px;
            border-radius: 60px;
        }

        /* Icon inside inputs (modern, minimal) */
        .input-icon-group {
            position: relative;
            margin-bottom: 1.4rem;
        }

        .input-icon-group .bi {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
            color: #a0aaba;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-icon-group .form-control {
            padding: 0.85rem 1rem 0.85rem 2.7rem;
            font-size: 0.98rem;
            border: 1.5px solid #e2e6ed;
            border-radius: 16px;
            background-color: #fefefe;
            font-weight: 500;
            transition: all 0.2s;
        }

        .input-icon-group .form-control:focus {
            border-color: #ff9800;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.2);
            outline: none;
        }

        .form-control::placeholder {
            color: #b1b9c9;
            font-weight: 500;
        }

        /* Primary button with gradient */
        .btn-login {
            background: linear-gradient(95deg, #ff9800, #ffb756);
            border: none;
            border-radius: 40px;
            font-weight: 700;
            font-size: 1rem;
            padding: 0.85rem 1.2rem;
            color: white;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            transition: all 0.25s ease;
            margin-top: 0.5rem;
            box-shadow: 0 2px 6px rgba(255, 152, 0, 0.2);
        }

        .btn-login:hover {
            background: linear-gradient(95deg, #ffb84d, #ff9800);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 152, 0, 0.3);
        }

        /* Divider elegant line */
        .login-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.6rem 0 1.2rem;
        }
        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e9edf2;
        }
        .login-divider span {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #8e9aaf;
            padding: 0 12px;
        }

        /* Secondary link (create account) */
        .btn-secondary-outline {
            background: transparent;
            border: 1.5px solid #e0e5ec;
            border-radius: 40px;
            padding: 0.75rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            transition: all 0.2s;
            text-decoration: none;
            width: 100%;
        }

        .btn-secondary-outline:hover {
            border-color: #ff9800;
            background-color: #fff8ef;
            color: #e67e22;
            transform: translateY(-1px);
        }

        /* Alert styling (minimal but elegant) */
        .alert-custom {
            border-radius: 20px;
            background: #fff4ed;
            border-left: 4px solid #e67e22;
            color: #bc6c25;
            font-weight: 600;
            padding: 0.9rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Responsive adjustments */
        @media (max-width: 500px) {
            .login-card-body {
                padding: 1.8rem 1.3rem;
            }
            .login-title {
                font-size: 1.6rem;
            }
            .login-title i {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-card-body">
        <!-- Title area (like the admin example, but for Garment ERP) -->
        <div class="login-title">
            <i class="bi bi-scissors"></i> 
            <span>Garment ERP</span>
        </div>

        <?php if($error): ?>
        <div class="alert-custom">
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.1rem;"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <!-- Username / Email field with icon -->
            <div class="input-icon-group">
                <i class="bi bi-person-circle"></i>
                <input type="text" name="login" class="form-control" placeholder="Username or Email" required autofocus>
            </div>
            <!-- Password field with icon -->
            <div class="input-icon-group">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <!-- Login Button -->
            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>
        </form>

        <!-- Divider + register link (keeping original functionality) -->
        <div class="login-divider">
            <span>New here?</span>
        </div>
        <a href="register.php" class="btn-secondary-outline">
            <i class="bi bi-building-add"></i> Create New Company Account
        </a>
    </div>
</div>

</body>
</html>