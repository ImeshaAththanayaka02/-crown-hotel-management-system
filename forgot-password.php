<?php
// forgot-password.php
ob_start();

require_once 'config/database.php';
require_once 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error_msg = 'CSRF validation failed.';
    } else {
        $email = trim($_POST['email']);
        $new_password = $_POST['new_password'];
        
        if (empty($email) || empty($new_password)) {
            $error_msg = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Invalid email address.';
        } else {
            try {
                // Verify email existence
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->execute([$hashed_password, $user['id']]);
                    
                    $success_msg = 'Password reset successful! You can now log in with your new password.';
                } else {
                    $error_msg = 'No account found with that email address.';
                }
            } catch (PDOException $e) {
                $error_msg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crown Hotel CHMS - Forgot Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-page">
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; width: 100%;">
        <div style="font-size: 3rem; color: var(--primary-accent); margin-bottom: 1rem;">
            <i class="fas fa-crown"></i>
        </div>
        
        <div class="glass-card auth-card">
            <?php if ($error_msg): ?>
                <div class="alert alert-danger" id="flash-alert" style="margin-bottom: 1.5rem;">
                    <span><?php echo $error_msg; ?></span>
                    <button class="btn-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php if ($success_msg): ?>
                <div class="alert alert-success" id="flash-alert" style="margin-bottom: 1.5rem;">
                    <span><?php echo $success_msg; ?></span>
                    <button class="btn-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <h2 class="auth-title">Reset Password</h2>
            <p class="auth-subtitle">Enter your email and choose a new password to recover access</p>
            
            <form action="forgot-password.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label class="form-label" for="email">Account Email</label>
                    <input class="form-input" type="email" id="email" name="email" placeholder="Enter account email" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="new_password">New Password</label>
                    <input class="form-input" type="password" id="new_password" name="new_password" placeholder="Enter new password" required autocomplete="new-password">
                </div>
                
                <button class="btn btn-primary" type="submit" style="width: 100%; margin-top: 1rem;">Reset Password</button>
            </form>
            
            <div style="margin-top: 1.5rem; text-align: center; font-size: 0.85rem;">
                <a href="login.php" style="color: var(--primary-accent); font-weight: 600;">Back to Login</a>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/main.js"></script>
</body>
</html>
