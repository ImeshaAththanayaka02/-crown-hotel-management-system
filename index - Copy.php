<?php
// index.php - Public-facing landing page
ob_start();

require_once 'config/database.php';
require_once 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if logged in to display portal shortcut
$logged_in = isset($_SESSION['user_id']);

// Handle contact form submission
$contact_success = false;
$contact_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    $contact_name = trim($_POST['name'] ?? '');
    $contact_email = trim($_POST['email'] ?? '');
    $contact_subject = trim($_POST['subject'] ?? '');
    $contact_message = trim($_POST['message'] ?? '');

    // Validate inputs
    if (empty($contact_name) || empty($contact_email) || empty($contact_subject) || empty($contact_message)) {
        $contact_error = 'Please fill in all the fields.';
    } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = 'Please enter a valid email address.';
    } else {
        try {
            // Save in database
            $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, status) VALUES (?, ?, ?, ?, 'Unread')");
            $stmt->execute([$contact_name, $contact_email, $contact_subject, $contact_message]);

            // Attempt to send email
            require_once 'includes/email_helper.php';
            send_contact_inquiry_email($contact_name, $contact_email, $contact_subject, $contact_message);

            $contact_success = true;
        } catch (PDOException $e) {
            $contact_error = 'Database error: Could not save your message. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crown Luxury Hotel & Resort</title>
    <meta name="description" content="Experience world-class luxury, private butler care, and award-winning dining at the Crown Hotel & Resort.">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            display: block;
            background: var(--bg-gradient);
            color: var(--text-primary);
        }
        .landing-wrapper {
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 5%;
            height: 80px;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .navbar-brand {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-accent);
            display: flex;
            align-items: center;
            gap: 0.6rem;
            letter-spacing: 1px;
        }
        .navbar-brand i {
            color: var(--warning-color);
            filter: drop-shadow(0 0 8px rgba(251, 191, 36, 0.4));
        }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 2.2rem;
            list-style: none;
        }
        .nav-link {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-secondary);
            position: relative;
            padding: 0.5rem 0;
            transition: color 0.3s;
        }
        .nav-link:hover {
            color: var(--text-primary);
        }
        .hero {
            position: relative;
            min-height: 75vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 4rem 2rem;
            background: 
                linear-gradient(rgba(15, 23, 42, 0.55), rgba(15, 23, 42, 0.85)),
                url('assets/images/login_bg.png') center center / cover no-repeat;
            border-bottom: 1px solid var(--glass-border);
        }
        .hero h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2.2rem, 5vw, 3.8rem);
            font-weight: 600;
            margin-bottom: 1rem;
            line-height: 1.2;
            color: #fff;
            text-shadow: 0 2px 20px rgba(0,0,0,0.5);
        }
        .hero p {
            font-size: 1.1rem;
            color: var(--text-secondary);
            max-width: 600px;
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }
        .showcase-section {
            padding: 6rem 8%;
            max-width: 1300px;
            margin: 0 auto;
        }
        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }
        .section-header span {
            color: var(--warning-color);
            font-size: 0.8rem;
            letter-spacing: 4px;
            text-transform: uppercase;
            font-weight: 600;
            display: block;
            margin-bottom: 0.5rem;
        }
        .section-header h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2rem, 3.5vw, 2.8rem);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .section-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2.5rem;
        }
        .room-show-card {
            display: flex;
            flex-direction: column;
            padding: 0;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            box-shadow: var(--glass-glow);
            backdrop-filter: var(--card-blur);
            overflow: hidden;
            height: 100%;
        }
        .room-img-container {
            height: 220px;
            overflow: hidden;
        }
        .room-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }
        .room-show-card:hover .room-img-container img {
            transform: scale(1.06);
        }
        .room-details {
            padding: 1.75rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            flex: 1;
        }
        .feat-card {
            padding: 2.5rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            transition: transform 0.3s ease;
        }
        .feat-card:hover {
            transform: translateY(-8px);
        }
        .feat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.25);
            color: var(--warning-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }
    </style>
</head>
<body>

<div class="landing-wrapper">
    <!-- NAVIGATION BAR -->
    <nav class="navbar">
        <div class="navbar-brand">
            <i class="fas fa-crown"></i>
            <span>Hotel Crown</span>
        </div>
        
        <ul class="nav-links">
            <li><a href="#home" class="nav-link">Home</a></li>
            <li><a href="#about" class="nav-link">About</a></li>
            <li><a href="#showcase" class="nav-link">Rooms</a></li>
            <li><a href="#features" class="nav-link">Features</a></li>
            <li><a href="#dining" class="nav-link">Dining</a></li>
            <li><a href="#contact" class="nav-link">Contact</a></li>
        </ul>

        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <?php if ($logged_in): ?>
                <a href="home.php" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200;"><i class="fas fa-home"></i> Guest Portal</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-secondary" style="border: none; background: transparent;">Sign In</a>
                <a href="login.php?mode=register" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200;"><i class="fas fa-user-plus"></i> Register</a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <header class="hero" id="home">
        <h1>Indulge In Grandeur & Luxury</h1>
        <p>Experience world-class accommodation, award-winning fine dining, and bespoke personal services in our coastal paradise resort.</p>
        <div style="display: flex; gap: 1rem;">
            <a href="login.php" class="btn btn-primary" style="padding: 1rem 2rem; font-size: 1.05rem; background: var(--warning-color); color: #1a1200;">Book Your Stay Now</a>
            <a href="#showcase" class="btn btn-secondary" style="padding: 1rem 2rem; font-size: 1.05rem; border: 1px solid rgba(255,255,255,0.25);">Explore Suites</a>
        </div>
    </header>

    <!-- ABOUT SECTION -->
    <section class="showcase-section" id="about" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center;">
        <div style="position: relative; height: 380px;">
            <img src="assets/images/room_suite.png" alt="Resort View" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px; border: 1px solid var(--glass-border); box-shadow: var(--glass-glow);">
        </div>
        <div>
            <span class="section-label" style="color: var(--warning-color); font-size: 0.78rem; letter-spacing: 4px; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 0.5rem;">A Legacy of Grandeur</span>
            <h2 style="font-family: 'Cormorant Garamond', serif; font-size: 2.5rem; font-weight: 600; margin-bottom: 1.5rem;">Where Serenity Meets Splendor</h2>
            <p style="color: var(--text-secondary); font-size: 0.9rem; line-height: 1.7; margin-bottom: 1.5rem;">
                Set on private crystal-clear coastlines, Hotel Crown offers a haven of unparalleled five-star hospitality. Each suite and villa provides private infinity pool views, 24-hour butler services, and full custom-tailored comfort.
            </p>
            <a href="login.php" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200; font-size: 0.85rem; padding: 0.6rem 1.25rem;">Learn More</a>
        </div>
    </section>

    <!-- SHOWCASE SECTION -->
    <main class="showcase-section" id="showcase">
        <div class="section-header">
            <span>Luxury Accommodations</span>
            <h2>Our Signature Suites</h2>
            <p>Select from our hand-tailored room categories designed for maximum comfort and relaxation</p>
        </div>

        <div class="rooms-grid">
            <!-- Suite -->
            <div class="room-show-card">
                <div class="room-img-container">
                    <?php if (file_exists('assets/images/room_suite.png')): ?>
                        <img src="assets/images/room_suite.png" alt="Presidential Suite">
                    <?php else: ?>
                        <div class="image-placeholder-fallback">
                            <i class="fas fa-crown"></i>
                            <span>Presidential Suite</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="room-details">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; margin: 0;">Presidential Suite</h3>
                        <span style="color: var(--success-color); font-weight: 700;">$450.00/N</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0; line-height: 1.55; flex: 1;">Ultimate luxury suite containing master bedroom, private dining, jacuzzi, and dedicated butler service.</p>
                    <a href="login.php" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200; font-size: 0.8rem; padding: 0.5rem 1rem; width: 100%; margin-top: 0.5rem;">Book Suite</a>
                </div>
            </div>

            <!-- Deluxe -->
            <div class="room-show-card">
                <div class="room-img-container">
                    <?php if (file_exists('assets/images/room_deluxe.png')): ?>
                        <img src="assets/images/room_deluxe.png" alt="Deluxe Room">
                    <?php else: ?>
                        <div class="image-placeholder-fallback">
                            <i class="fas fa-bed"></i>
                            <span>Deluxe King Room</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="room-details">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; margin: 0;">Deluxe King Room</h3>
                        <span style="color: var(--success-color); font-weight: 700;">$280.00/N</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0; line-height: 1.55; flex: 1;">Premium rooms containing automated mini-bars, luxury marble tubs, and panoramic ocean views.</p>
                    <a href="login.php" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200; font-size: 0.8rem; padding: 0.5rem 1rem; width: 100%; margin-top: 0.5rem;">Book Room</a>
                </div>
            </div>

            <!-- Double -->
            <div class="room-show-card">
                <div class="room-img-container">
                    <?php if (file_exists('assets/images/room_double.png')): ?>
                        <img src="assets/images/room_double.png" alt="Double Room">
                    <?php else: ?>
                        <div class="image-placeholder-fallback">
                            <i class="fas fa-bed"></i>
                            <span>Double Queen Room</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="room-details">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; margin: 0;">Double Queen Room</h3>
                        <span style="color: var(--success-color); font-weight: 700;">$180.00/N</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0; line-height: 1.55; flex: 1;">Spacious family-style double room containing queen beds, workspace, smart TV, and private terrace.</p>
                    <a href="login.php" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200; font-size: 0.8rem; padding: 0.5rem 1rem; width: 100%; margin-top: 0.5rem;">Book Room</a>
                </div>
            </div>
        </div>
    </main>

    <!-- FEATURES SECTION -->
    <section class="showcase-section" id="features" style="border-top: 1px solid var(--glass-border);">
        <div class="section-header">
            <span>Exclusive Features</span>
            <h2>Royal Resort Amenities</h2>
            <p>We provide standard royal facilities to satisfy all our visitors' desires.</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 2rem;">
            <div class="glass-card feat-card">
                <div class="feat-icon"><i class="fas fa-water"></i></div>
                <h3 style="font-size: 1.1rem; font-weight: 600;">Infinity Pool</h3>
                <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Merged horizons overlooking turquoise lagoons.</p>
            </div>
            <div class="glass-card feat-card">
                <div class="feat-icon"><i class="fas fa-bell"></i></div>
                <h3 style="font-size: 1.1rem; font-weight: 600;">24/7 Butler</h3>
                <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Attentive room butler services to answer any request.</p>
            </div>
            <div class="glass-card feat-card">
                <div class="feat-icon"><i class="fas fa-spa"></i></div>
                <h3 style="font-size: 1.1rem; font-weight: 600;">Spa & Massage</h3>
                <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Custom organic massage treatments for relaxation.</p>
            </div>
            <div class="glass-card feat-card">
                <div class="feat-icon"><i class="fas fa-utensils"></i></div>
                <h3 style="font-size: 1.1rem; font-weight: 600;">Gourmet Fine Dining</h3>
                <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Culinary creations curated by globally-acclaimed chefs.</p>
            </div>
        </div>
    </section>

    <!-- DINING PREVIEW SECTION -->
    <section class="showcase-section" id="dining" style="border-top: 1px solid var(--glass-border);">
        <div style="position: relative; border-radius: 20px; overflow: hidden; min-height: 280px; display: flex; align-items: center; padding: 3rem; background: linear-gradient(to top, rgba(7,17,31,0.92) 0%, rgba(7,17,31,0.40) 60%, transparent 100%), url('assets/images/food_spread.png') center center / cover no-repeat;">
            <div style="position: relative; z-index: 10;">
                <span style="color: var(--warning-color); font-size: 0.78rem; letter-spacing: 4px; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 0.5rem;">Gourmet Restaurant</span>
                <h2 style="font-family: 'Cormorant Garamond', serif; font-size: clamp(2rem, 3.5vw, 2.8rem); font-weight: 600; color: #fff; margin-bottom: 0.5rem;">Savor Culinary Perfection</h2>
                <p style="color: rgba(255,255,255,0.7); max-width: 500px; font-size: 0.9rem; margin-bottom: 1.5rem;">From local seafood delights to international wagyu steaks, indulge in premium gastronomy.</p>
                <a href="login.php" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200; font-size: 0.85rem; padding: 0.6rem 1.25rem;">View Restaurant Menu</a>
            </div>
        </div>
    </section>

    <!-- CONTACT SECTION -->
    <section class="showcase-section" id="contact" style="border-top: 1px solid var(--glass-border);">
        <div class="section-header">
            <span>Get In Touch</span>
            <h2>Contact Our Concierge</h2>
            <p>Have questions about bookings, amenities, or special requests? Drop us a message.</p>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 4rem; max-width: 1100px; margin: 0 auto; align-items: start;">
            <!-- Contact Info Cards -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <div class="glass-card" style="display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem;">
                    <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.25); color: var(--primary-accent); display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <h4 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem;">Address</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary);">123 Luxury Boulevard, Paradise Bay</p>
                    </div>
                </div>
                <div class="glass-card" style="display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem;">
                    <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.25); color: var(--primary-accent); display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fas fa-phone-alt"></i>
                    </div>
                    <div>
                        <h4 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem;">Phone</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary);">+1-555-0100 (Concierge desk)</p>
                    </div>
                </div>
                <div class="glass-card" style="display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem;">
                    <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.25); color: var(--primary-accent); display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div>
                        <h4 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem;">Email</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary);">concierge@crownhotel.com</p>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <div class="glass-card" style="padding: 2.5rem; position: relative;">
                <?php if ($contact_success): ?>
                    <div style="background: rgba(52, 211, 153, 0.15); border: 1px solid var(--success-color); color: var(--success-color); padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: 0.9rem; display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-check-circle"></i>
                        <span>Thank you! Your message has been sent successfully. Our team will get back to you shortly.</span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($contact_error)): ?>
                    <div style="background: rgba(248, 113, 113, 0.15); border: 1px solid var(--danger-color); color: var(--danger-color); padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: 0.9rem; display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($contact_error); ?></span>
                    </div>
                <?php endif; ?>

                <form action="index.php#contact" method="POST" style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="contact_name">Full Name</label>
                            <input class="form-input" type="text" id="contact_name" name="name" required placeholder="e.g. John Doe" value="<?php echo isset($_POST['name']) && !$contact_success ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="contact_email">Email Address</label>
                            <input class="form-input" type="email" id="contact_email" name="email" required placeholder="e.g. john@example.com" value="<?php echo isset($_POST['email']) && !$contact_success ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="contact_subject">Subject</label>
                        <input class="form-input" type="text" id="contact_subject" name="subject" required placeholder="What is this regarding?" value="<?php echo isset($_POST['subject']) && !$contact_success ? htmlspecialchars($_POST['subject']) : ''; ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="contact_message">Your Message</label>
                        <textarea class="form-input" id="contact_message" name="message" rows="5" required placeholder="Write your inquiry details here..." style="resize: vertical; min-height: 120px;"><?php echo isset($_POST['message']) && !$contact_success ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                    </div>
                    <button type="submit" name="submit_contact" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200; font-weight: 700; width: 100%; justify-content: center;">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer style="margin-top: auto; padding: 4rem 5% 3rem; border-top: 1px solid var(--glass-border); background: rgba(15,23,42,0.6); font-size: 0.85rem; color: var(--text-secondary);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; max-width: 1200px; margin: 0 auto;">
            <p>&copy; <?php echo date('Y'); ?> Crown Hotel Management System (CHMS). All rights reserved.</p>
            <p style="font-size: 0.75rem; opacity: 0.85;">Developed with premium Glassmorphism & PHP 8+ PDO</p>
        </div>
    </footer>
</div>

<script src="assets/js/main.js"></script>
</body>
</html>
