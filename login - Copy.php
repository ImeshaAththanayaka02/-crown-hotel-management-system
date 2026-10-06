<?php
// login.php
ob_start();

require_once 'config/database.php';
require_once 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: home.php");
    exit();
}

$error_msg   = '';
$success_msg = '';
$mode = isset($_GET['mode']) && $_GET['mode'] === 'register' ? 'register' : 'login';

// ─── Handle form submission ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error_msg = 'Security token mismatch. Please refresh and try again.';
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        if ($mode === 'login') {
            if (empty($username) || empty($password)) {
                $error_msg = 'Please enter your username/email and password.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT u.*, r.name AS role_name
                        FROM users u
                        JOIN roles r ON u.role_id = r.id
                        WHERE u.username = :username OR u.email = :email_input
                    ");
                    $stmt->execute([
                        'username'    => $username,
                        'email_input' => $username,
                    ]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password'])) {
                        if ($user['status'] === 'Inactive') {
                            $error_msg = 'Your account has been deactivated. Please contact the front desk.';
                        } else {
                            $_SESSION['user_id']   = $user['id'];
                            $_SESSION['username']  = $user['username'];
                            $_SESSION['role_name'] = $user['role_name'];
                            $_SESSION['role_id']   = $user['role_id'];

                            set_flash_message('success', "Welcome back, {$user['username']}!");
                            header("Location: home.php");
                            exit();
                        }
                    } else {
                        $error_msg = 'Invalid credentials. Please try again.';
                    }
                } catch (PDOException $e) {
                    $error_msg = 'A database error occurred. Please try again shortly.';
                }
            }
        } else {
            // Registration
            $email      = trim($_POST['email']);
            $first_name = trim($_POST['first_name']);
            $last_name  = trim($_POST['last_name']);
            $phone      = trim($_POST['phone']);

            if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($phone)) {
                $error_msg = 'All fields are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_msg = 'Please enter a valid email address.';
            } else {
                try {
                    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                    $chk->execute([$username, $email]);
                    if ($chk->fetch()) {
                        $error_msg = 'Username or email is already registered.';
                    } else {
                        $pdo->beginTransaction();
                        $hash = password_hash($password, PASSWORD_DEFAULT);

                        $pdo->prepare("INSERT INTO users (username, email, password, role_id, status) VALUES (?, ?, ?, 5, 'Active')")
                            ->execute([$username, $email, $hash]);
                        $uid = $pdo->lastInsertId();

                        $pdo->prepare("INSERT INTO guests (user_id, first_name, last_name, email, phone) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$uid, $first_name, $last_name, $email, $phone]);

                        $pdo->commit();
                        $success_msg = 'Account created successfully! Please log in.';
                        $mode = 'login';
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_msg = 'Registration failed. Please try again.';
                }
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
    <title>Crown Hotel CHMS – <?php echo $mode === 'register' ? 'Create Account' : 'Sign In'; ?></title>
    <meta name="description" content="Sign in to the Crown Hotel Management System to manage bookings, guests, and hotel operations.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ─── Reset ──────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:       #07111f;
            --navy-mid:   #0d1f3c;
            --gold:       #D4AF37;
            --gold-light: #f0d060;
            --white:      #f8fafc;
            --muted:      rgba(255,255,255,0.55);
            --border:     rgba(255,255,255,0.10);
            --input-bg:   rgba(255,255,255,0.06);
            --danger:     #f87171;
            --success:    #34d399;
            --radius:     14px;
            --transition: 0.3s ease;
        }

        /* ─── Demo Credentials Hint Card ─────────────── */
        .cred-hint {
            background: rgba(212,175,55,0.07);
            border: 1px solid rgba(212,175,55,0.25);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            margin-bottom: 1.25rem;
            animation: fadeUp 0.6s ease both;
            animation-delay: 0.1s;
        }
        .cred-hint-title {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--gold);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .cred-grid { display: flex; flex-direction: column; gap: 0.35rem; }
        .cred-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.4rem 0.6rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background var(--transition);
        }
        .cred-row:hover { background: rgba(255,255,255,0.06); }
        .cred-badge {
            font-size: 0.65rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 50px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            min-width: 80px;
            text-align: center;
        }
        .cred-badge.admin    { background: rgba(239,68,68,0.2);   color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .cred-badge.manager  { background: rgba(139,92,246,0.2);  color: #c4b5fd; border: 1px solid rgba(139,92,246,0.3); }
        .cred-badge.reception{ background: rgba(56,189,248,0.2);  color: #7dd3fc; border: 1px solid rgba(56,189,248,0.3); }
        .cred-badge.guest    { background: rgba(52,211,153,0.2);  color: #6ee7b7; border: 1px solid rgba(52,211,153,0.3); }
        .cred-user { font-size: 0.8rem; font-weight: 600; color: var(--white); flex: 1; }
        .cred-pass { font-size: 0.75rem; color: var(--muted); font-family: monospace; }
        .cred-tap  { font-size: 0.68rem; color: var(--muted); text-align: right; margin-top: 0.4rem; }

        /* ─── Password show/hide toggle ──────────────── */
        .pwd-toggle {
            position: absolute;
            right: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 0.85rem;
            padding: 0;
            pointer-events: all;
            transition: color var(--transition);
        }
        .pwd-toggle:hover { color: var(--gold); }

        html, body {
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background: var(--navy);
            color: var(--white);
        }

        /* ─── Split-screen wrapper ───────────────────── */
        .split-screen {
            display: flex;
            min-height: 100vh;
        }

        /* ─── LEFT PANEL – Hotel image ───────────────── */
        .panel-image {
            flex: 0 0 58%;
            position: relative;
            overflow: hidden;
            background:
                url('assets/images/login_bg.png') center center / cover no-repeat;
        }

        /* Gradient overlay for depth and legibility */
        .panel-image::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(
                    to right,
                    rgba(7,17,31,0.15) 0%,
                    rgba(7,17,31,0.55) 100%
                ),
                linear-gradient(
                    to top,
                    rgba(7,17,31,0.75) 0%,
                    transparent 50%
                );
        }

        /* Hotel tagline overlay */
        .panel-image-content {
            position: absolute;
            bottom: 2.75rem;
            left: 3rem;
            right: 2rem;
            animation: fadeUp 0.9s ease both;
            animation-delay: 0.3s;
        }

        .panel-image-content .tagline-label {
            display: inline-block;
            font-size: 0.7rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 0.65rem;
        }

        .panel-image-content h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2rem, 3.5vw, 3rem);
            font-weight: 600;
            line-height: 1.2;
            color: #fff;
            text-shadow: 0 2px 20px rgba(0,0,0,0.6);
            margin-bottom: 0.5rem;
        }

        .panel-image-content p {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.7);
            max-width: 360px;
        }

        /* ─── RIGHT PANEL – Login card ───────────────── */
        .panel-form {
            flex: 0 0 42%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2.5rem;
            background:
                radial-gradient(
                    ellipse at 70% 30%,
                    #112150 0%,
                    #0a1838 40%,
                    #07111f 100%
                );
            position: relative;
        }

        /* Subtle background texture dots */
        .panel-form::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 28px 28px;
            pointer-events: none;
        }

        .form-card {
            width: 100%;
            max-width: 400px;
            position: relative;
            z-index: 1;
        }

        /* ─── Crown brand mark ──────────────────────── */
        .brand-mark {
            text-align: center;
            margin-bottom: 2rem;
            animation: fadeDown 0.7s ease both;
        }

        .crown-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(212,175,55,0.12);
            border: 1px solid rgba(212,175,55,0.35);
            font-size: 1.75rem;
            color: var(--gold);
            margin-bottom: 1rem;
            box-shadow: 0 0 28px rgba(212,175,55,0.25);
            animation: pulseGold 2.5s ease-in-out infinite alternate;
        }

        @keyframes pulseGold {
            from { box-shadow: 0 0 14px rgba(212,175,55,0.20); }
            to   { box-shadow: 0 0 36px rgba(212,175,55,0.55); }
        }

        .brand-mark h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--white);
            letter-spacing: 0.5px;
        }

        .brand-mark p {
            font-size: 0.78rem;
            color: var(--muted);
            margin-top: 0.3rem;
            letter-spacing: 0.3px;
        }

        /* ─── Alert messages ────────────────────────── */
        .alert {
            padding: 0.85rem 1.1rem;
            border-radius: 10px;
            font-size: 0.82rem;
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            margin-bottom: 1.4rem;
            animation: fadeUp 0.4s ease both;
        }
        .alert-danger  { background: rgba(248,113,113,0.12); border: 1px solid rgba(248,113,113,0.30); color: #fca5a5; }
        .alert-success { background: rgba(52,211,153,0.12);  border: 1px solid rgba(52,211,153,0.30);  color: #6ee7b7; }
        .alert i { margin-top: 1px; flex-shrink: 0; }

        /* ─── Mode tabs ─────────────────────────────── */
        .mode-tabs {
            display: flex;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 4px;
            margin-bottom: 1.75rem;
            animation: fadeUp 0.7s ease both;
            animation-delay: 0.1s;
        }
        .mode-tab {
            flex: 1;
            padding: 0.55rem;
            border-radius: 7px;
            border: none;
            background: transparent;
            color: var(--muted);
            font-family: 'Poppins', sans-serif;
            font-size: 0.83rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
            text-align: center;
            display: block;
        }
        .mode-tab.active {
            background: rgba(212,175,55,0.18);
            color: var(--gold);
            box-shadow: 0 0 12px rgba(212,175,55,0.15);
        }
        .mode-tab:hover:not(.active) { color: var(--white); }

        /* ─── Form elements ─────────────────────────── */
        .field-group {
            margin-bottom: 1.1rem;
            animation: fadeUp 0.6s ease both;
        }
        .field-group:nth-child(1) { animation-delay: 0.15s; }
        .field-group:nth-child(2) { animation-delay: 0.22s; }
        .field-group:nth-child(3) { animation-delay: 0.29s; }
        .field-group:nth-child(4) { animation-delay: 0.36s; }
        .field-group:nth-child(5) { animation-delay: 0.43s; }
        .field-group:nth-child(6) { animation-delay: 0.50s; }

        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 500;
            color: rgba(255,255,255,0.65);
            margin-bottom: 0.4rem;
            letter-spacing: 0.3px;
        }

        .field-wrap {
            position: relative;
        }

        .field-wrap i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem;
            color: var(--muted);
            pointer-events: none;
            transition: color var(--transition);
        }

        .field-input {
            width: 100%;
            padding: 0.78rem 2.8rem 0.78rem 2.6rem;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--white);
            font-family: 'Poppins', sans-serif;
            font-size: 0.875rem;
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
        }
        .field-input::placeholder { color: rgba(255,255,255,0.28); }
        .field-input:focus {
            border-color: rgba(212,175,55,0.55);
            background: rgba(255,255,255,0.09);
            box-shadow:
                0 0 0 3px rgba(212,175,55,0.10),
                0 0 18px rgba(212,175,55,0.12);
        }
        .field-input:focus + i,
        .field-wrap:focus-within i { color: var(--gold); }

        /* Two-column row for register form */
        .fields-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        /* ─── Submit button ─────────────────────────── */
        .btn-submit {
            width: 100%;
            padding: 0.9rem;
            margin-top: 1.6rem;
            border: none;
            border-radius: var(--radius);
            background: linear-gradient(135deg, #c9a227 0%, #f0d060 50%, #c9a227 100%);
            background-size: 200% auto;
            color: #1a1200;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: background-position 0.4s ease, transform 0.2s ease, box-shadow 0.3s ease;
            box-shadow: 0 4px 20px rgba(212,175,55,0.30);
            animation: fadeUp 0.6s ease both;
            animation-delay: 0.55s;
        }
        .btn-submit:hover {
            background-position: right center;
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(212,175,55,0.45);
        }
        .btn-submit:active { transform: translateY(0); }

        /* ─── Footer links ──────────────────────────── */
        .form-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.78rem;
            color: var(--muted);
            animation: fadeUp 0.6s ease both;
            animation-delay: 0.62s;
        }
        .form-footer a {
            color: var(--gold);
            font-weight: 500;
            text-decoration: none;
            transition: color var(--transition);
        }
        .form-footer a:hover { color: var(--gold-light); text-decoration: underline; }

        /* Divider line */
        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 1rem 0 0.75rem;
        }

        /* ─── Keyframe Animations ────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── Responsive – stack on mobile ──────────── */
        @media (max-width: 768px) {
            .panel-image { display: none; }
            .panel-form  { flex: 1; padding: 2rem 1.5rem; }
        }
    </style>
</head>
<body>

<div class="split-screen">

    <!-- ══════════════════════════════════════
         LEFT PANEL – Luxury hotel image
         ══════════════════════════════════════ -->
    <div class="panel-image">
        <div class="panel-image-content">
            <span class="tagline-label">Luxury Redefined</span>
            <h1>Where Every Moment<br>Becomes a Memory</h1>
            <p>Five-star excellence, world-class comfort, and bespoke service await you.</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         RIGHT PANEL – Login / Register form
         ══════════════════════════════════════ -->
    <div class="panel-form">
        <div class="form-card">

            <!-- Brand mark -->
            <div class="brand-mark">
                <div class="crown-icon"><i class="fas fa-crown"></i></div>
                <h2>Crown Hotel</h2>
                <p>Management System</p>
            </div>

            <!-- Alert messages -->
            <?php if ($error_msg): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>



            <!-- ─── LOGIN FORM ─── -->
            <?php if ($mode === 'login'): ?>
                <form action="login.php?mode=login" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <!-- Quick Credentials Hint Card -->
                    <div class="cred-hint">
                        <div class="cred-hint-title"><i class="fas fa-info-circle"></i> Demo Credentials</div>
                        <div class="cred-grid">
                            <div class="cred-row" onclick="fillCreds('admin','admin123')">
                                <span class="cred-badge admin">Admin</span>
                                <span class="cred-user">admin</span>
                                <span class="cred-pass">admin123</span>
                            </div>
                            <div class="cred-row" onclick="fillCreds('manager','admin123')">
                                <span class="cred-badge manager">Manager</span>
                                <span class="cred-user">manager</span>
                                <span class="cred-pass">admin123</span>
                            </div>
                            <div class="cred-row" onclick="fillCreds('reception','admin123')">
                                <span class="cred-badge reception">Receptionist</span>
                                <span class="cred-user">reception</span>
                                <span class="cred-pass">admin123</span>
                            </div>
                            <div class="cred-row" onclick="fillCreds('guest1','admin123')">
                                <span class="cred-badge guest">Guest</span>
                                <span class="cred-user">guest1</span>
                                <span class="cred-pass">admin123</span>
                            </div>
                        </div>
                        <p class="cred-tap"><i class="fas fa-hand-pointer"></i> Click a row to auto-fill</p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="username">Username or Email</label>
                        <div class="field-wrap">
                            <input class="field-input" type="text" id="username" name="username"
                                   placeholder="e.g. admin, manager, guest1"
                                   required autocomplete="username">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password">Password</label>
                        <div class="field-wrap">
                            <input class="field-input" type="password" id="password" name="password"
                                   placeholder="Enter your password (e.g. admin123)"
                                   required autocomplete="current-password">
                            <i class="fas fa-lock"></i>
                            <button type="button" class="pwd-toggle" id="pwd-toggle" onclick="togglePassword()" title="Show/Hide password">
                                <i class="fas fa-eye" id="pwd-icon"></i>
                            </button>
                        </div>
                    </div>

                    <button class="btn-submit" type="submit">
                        <i class="fas fa-arrow-right-to-bracket" style="margin-right:0.5rem;"></i>
                        Login
                    </button>
                </form>

                <script>
                function fillCreds(user, pass) {
                    document.getElementById('username').value = user;
                    document.getElementById('password').value = pass;
                    // Show a brief filled indicator
                    const rows = document.querySelectorAll('.cred-row');
                    rows.forEach(r => r.style.background = '');
                    event.currentTarget.style.background = 'rgba(212,175,55,0.15)';
                    setTimeout(() => { event.currentTarget.style.background = ''; }, 1200);
                }
                function togglePassword() {
                    const input = document.getElementById('password');
                    const icon  = document.getElementById('pwd-icon');
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                }
                </script>

                <div class="form-footer">
                    <hr class="divider">
                    <a href="forgot-password.php"><i class="fas fa-key" style="margin-right:0.3rem;"></i>Forgot your password?</a>
                </div>

            <!-- ─── REGISTER FORM ─── -->
            <?php else: ?>
                <form action="login.php?mode=register" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="fields-row">
                        <div class="field-group">
                            <label class="field-label" for="first_name">First Name</label>
                            <div class="field-wrap">
                                <input class="field-input" type="text" id="first_name" name="first_name"
                                       placeholder="John" required>
                                <i class="fas fa-id-card"></i>
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="last_name">Last Name</label>
                            <div class="field-wrap">
                                <input class="field-input" type="text" id="last_name" name="last_name"
                                       placeholder="Doe" required>
                                <i class="fas fa-id-card"></i>
                            </div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="username">Username</label>
                        <div class="field-wrap">
                            <input class="field-input" type="text" id="username" name="username"
                                   placeholder="Choose a username" required autocomplete="username">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="email">Email Address</label>
                        <div class="field-wrap">
                            <input class="field-input" type="email" id="email" name="email"
                                   placeholder="john@example.com" required autocomplete="email">
                            <i class="fas fa-envelope"></i>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="phone">Phone Number</label>
                        <div class="field-wrap">
                            <input class="field-input" type="text" id="phone" name="phone"
                                   placeholder="+1-555-0199" required>
                            <i class="fas fa-phone"></i>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password">Password</label>
                        <div class="field-wrap">
                            <input class="field-input" type="password" id="password" name="password"
                                   placeholder="Create a strong password"
                                   required autocomplete="new-password">
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>

                    <button class="btn-submit" type="submit">
                        <i class="fas fa-user-plus" style="margin-right:0.5rem;"></i>
                        Create Guest Account
                    </button>
                </form>
            <?php endif; ?>

        </div><!-- /.form-card -->
    </div><!-- /.panel-form -->

</div><!-- /.split-screen -->

</body>
</html>
