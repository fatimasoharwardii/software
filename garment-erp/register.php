<?php
session_start();
require_once "config/db.php";
require_once "includes/functions.php";

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $company_name = trim($_POST['company_name']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    
    if (empty($name) || empty($username) || empty($email) || empty($company_name) || empty($password)) {
        $error = "All fields are required";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        // Check if username or email already exists (across all companies)
        $checkUser = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $checkUser->bind_param("ss", $username, $email);
        $checkUser->execute();
        if ($checkUser->get_result()->num_rows > 0) {
            $error = "Username or email already exists. Please choose a different one.";
        } else {
            // Create company
            $stmt_comp = $conn->prepare("INSERT INTO companies (company_name) VALUES (?)");
            $stmt_comp->bind_param("s", $company_name);
            if (!$stmt_comp->execute()) {
                $error = "Failed to create company: " . $conn->error;
            } else {
                $company_id = $conn->insert_id;
                
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $role = 'admin'; // first user of this company becomes admin
                $is_active = 1;
                $stmt = $conn->prepare("INSERT INTO users (name, username, email, password, role, is_active, company_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssii", $name, $username, $email, $hashed, $role, $is_active, $company_id);
                if ($stmt->execute()) {
                    $success = "Company and admin account created successfully! You can now login.";
                } else {
                    $error = "Database error: " . $conn->error;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Garment ERP</title>
    <!-- Bootstrap 5 + Icons + Google Font (Inter) -->
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

        .register-card {
            background: #ffffff;
            border: 1.5px solid #e1e5ec;
            border-radius: 28px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
            max-width: 560px;
            width: 100%;
            transition: box-shadow 0.25s ease, transform 0.2s ease;
        }

        .register-card:hover {
            box-shadow: 0 20px 35px rgba(0, 0, 0, 0.12);
            transform: translateY(-3px);
        }

        .register-card-body {
            padding: 2.4rem 2rem 2rem 2rem;
        }

        .register-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            margin-bottom: 1.8rem;
            color: #1f2a3e;
            font-weight: 800;
            font-size: 1.9rem;
            letter-spacing: -0.3px;
        }

        .register-title i {
            font-size: 2.1rem;
            color: #ff9800;
            background: #fff2e0;
            padding: 8px;
            border-radius: 60px;
        }

        .input-icon-group {
            position: relative;
            margin-bottom: 1.3rem;
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
            font-size: 0.95rem;
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

        .btn-register {
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

        .btn-register:hover {
            background: linear-gradient(95deg, #ffb84d, #ff9800);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 152, 0, 0.3);
        }

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

        .register-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.6rem 0 1.2rem;
        }
        .register-divider::before,
        .register-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e9edf2;
        }
        .register-divider span {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #8e9aaf;
            padding: 0 12px;
        }

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

        .alert-success-custom {
            border-radius: 20px;
            background: #e9f9ef;
            border-left: 4px solid #2ecc71;
            color: #1e7b48;
            font-weight: 600;
            padding: 0.9rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 560px) {
            .register-card-body {
                padding: 1.8rem 1.3rem;
            }
            .register-title {
                font-size: 1.6rem;
            }
            .register-title i {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>

<div class="register-card">
    <div class="register-card-body">
        <div class="register-title">
            <i class="bi bi-building-add"></i>
            <span>Register Company</span>
        </div>

        <?php if($error): ?>
        <div class="alert-custom">
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.1rem;"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if($success): ?>
        <div class="alert-success-custom">
            <i class="bi bi-check-circle-fill"></i>
            <?= htmlspecialchars($success) ?> <a href="login.php" style="font-weight:700; color:#1e7b48;">Login here</a>
        </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <!-- Company Name -->
            <div class="input-icon-group">
                <i class="bi bi-shop"></i>
                <input type="text" name="company_name" class="form-control" placeholder="Company name" required>
            </div>
            <!-- Full Name -->
            <div class="input-icon-group">
                <i class="bi bi-person-badge"></i>
                <input type="text" name="name" class="form-control" placeholder="Your full name" required>
            </div>
            <!-- Username -->
            <div class="input-icon-group">
                <i class="bi bi-person-circle"></i>
                <input type="text" name="username" class="form-control" placeholder="Username" required>
            </div>
            <!-- Email -->
            <div class="input-icon-group">
                <i class="bi bi-envelope-at"></i>
                <input type="email" name="email" class="form-control" placeholder="Email address" required>
            </div>
            <!-- Password -->
            <div class="input-icon-group">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <!-- Confirm Password -->
            <div class="input-icon-group">
                <i class="bi bi-shield-lock"></i>
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm password" required>
            </div>
            
            <button type="submit" class="btn-register">
                <i class="bi bi-check2-circle"></i> Register Company
            </button>
        </form>

        <div class="register-divider">
            <span>Already have an account?</span>
        </div>
        <a href="login.php" class="btn-secondary-outline">
            <i class="bi bi-box-arrow-in-right"></i> Sign in to your dashboard
        </a>
    </div>
</div>

</body>
</html>