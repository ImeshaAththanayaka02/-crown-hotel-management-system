<?php
// home.php — Main customer landing page after login

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Enforce login
require_login();

$current_role = get_current_role();
$current_username = get_current_username();
$user_id = get_current_user_id();

// Fetch time of day greeting
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// Fetch some room types for display
try {
    $room_types = $pdo->query("SELECT * FROM room_types ORDER BY base_price ASC LIMIT 4")->fetchAll();
} catch (PDOException $e) {
    $room_types = [];
}

// Fetch a preview of featured food menu items
try {
    $featured_food = $pdo->query("SELECT * FROM food_menu WHERE is_available = 1 LIMIT 3")->fetchAll();
} catch (PDOException $e) {
    $featured_food = [];
}

$page_title = 'Home - Hotel Crown';
require_once 'includes/guest_header.php';
?>

<!-- Scoped CSS for Home Page Landing Sections -->
<style>
    /* Hero Section */
    .hero-banner {
        position: relative;
        min-height: 80vh;
        background: 
            linear-gradient(to top, rgba(7, 17, 31, 0.95) 0%, rgba(7, 17, 31, 0.35) 60%, transparent 100%),
            url('assets/images/header.jpg') center center / cover no-repeat;
        display: flex;
        align-items: center;
        padding: 0 8%;
        margin-bottom: 4rem;
        animation: heroScaleIn 1.2s cubic-bezier(0.25, 1, 0.5, 1) both;
    }

    @keyframes heroScaleIn {
        from { transform: scale(1.05); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    .hero-content {
        max-width: 700px;
        position: relative;
        z-index: 10;
        animation: fadeSlideUp 0.9s ease both;
        animation-delay: 0.2s;
    }

    @keyframes fadeSlideUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .hero-greeting {
        color: var(--warning-color);
        font-size: 0.85rem;
        letter-spacing: 3px;
        text-transform: uppercase;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 1rem;
    }

    .hero-title {
        font-family: 'Cormorant Garamond', serif;
        font-size: clamp(2.2rem, 5vw, 4rem);
        line-height: 1.15;
        font-weight: 600;
        color: #fff;
        text-shadow: 0 4px 30px rgba(0,0,0,0.6);
        margin-bottom: 1.5rem;
    }

    .hero-subtitle {
        font-size: 1.05rem;
        color: rgba(255, 255, 255, 0.75);
        line-height: 1.6;
        margin-bottom: 2.5rem;
        max-width: 550px;
    }

    .hero-buttons {
        display: flex;
        gap: 1.25rem;
        flex-wrap: wrap;
    }

    /* About Section */

    
    .about-sec {
        padding: 6rem 8%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4rem;
        align-items: center;
        max-width: 1300px;
        margin: 0 auto;
    }

    .about-image-stack {
        position: relative;
        height: 480px;
    }

    .about-img-main {
        width: 60%;
        height: 60%;
        object-fit: cover;
        border-radius: 12px;
        border: 1px solid var(--glass-border);
        box-shadow: var(--glass-glow);
    }

    .about-img-sub {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 55%;
        height: 55%;
        object-fit: cover;
        border-radius: 12px;
        border: 1px solid var(--glass-border);
        box-shadow: 0 10px 40px rgba(0,0,0,0.4);
    }

    .section-label {
        color: var(--warning-color);
        font-size: 0.78rem;
        letter-spacing: 4px;
        text-transform: uppercase;
        font-weight: 600;
        display: block;
        margin-bottom: 0.5rem;
    }

    .section-title {
        font-family: 'Cormorant Garamond', serif;
        font-size: clamp(2rem, 3.5vw, 2.8rem);
        font-weight: 600;
        margin-bottom: 1.5rem;
        line-height: 1.25;
    }

    .about-text {
        font-size: 0.92rem;
        color: var(--text-secondary);
        line-height: 1.7;
        margin-bottom: 1.5rem;
    }

    /* Feature Cards */
    .features-sec {
        padding: 5rem 8%;
        max-width: 1300px;
        margin: 0 auto;
    }

    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 2rem;
        margin-top: 3rem;
    }

    .glass-card.feat-card {
    padding: 2.5rem 2rem !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 1.25rem !important;
    background: rgba(255, 255, 255, 0.12) !important; /* තව ටිකක් සුදු පාට වැඩි කළා කැපිලා පේන්න */
    backdrop-filter: blur(15px) !important;
    -webkit-backdrop-filter: blur(15px) !important;
    border: 1px solid rgba(255, 255, 255, 0.25) !important; /* බෝඩර් එක තව ටිකක් ඝනකම් කළා */
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4) !important;
    transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s ease !important;
}

.glass-card.feat-card:hover {
    transform: translateY(-8px) !important;
    background: rgba(255, 255, 255, 0.2) !important;
    border-color: rgba(212, 175, 55, 0.8) !important; /* Gold බෝඩර් එක තදින් පේන්න */
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5) !important;
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

    /* Rooms Preview Carousel */
    .rooms-sec {
        padding: 5rem 8%;
        max-width: 1300px;
        margin: 0 auto;
    }

    .rooms-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 2rem;
        margin-top: 3rem;
    }

    .room-show-card {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        height: 420px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s ease;
    }
    .room-show-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
    }
    .room-img-container {
        width: 100%;
        height: 100%;
        overflow: hidden;
    }
    .room-img-container img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }
    .room-show-card:hover .room-img-container img {
        transform: scale(1.06);
    }
    .room-details {
        position: absolute;
        bottom: 20px;
        left: 20px;
        right: 20px;
        background: #ffffff;
        border-radius: 12px;
        padding: 1.25rem;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
        z-index: 10;
        color: #333333;
    }
    .room-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .room-card-title {
        font-family: 'Poppins', sans-serif;
        font-size: 1.15rem;
        font-weight: 600;
        color: #d49f30;
        margin: 0;
    }
    .room-card-price {
        font-family: 'Poppins', sans-serif;
        font-size: 1.15rem;
        font-weight: 700;
        color: #1a1a1a;
    }
    .room-card-desc {
        font-size: 0.85rem;
        color: #666666;
        line-height: 1.5;
        margin: 0;
    }
    .room-card-btn {
        background: #d49f30;
        color: #ffffff;
        border: none;
        padding: 0.6rem 1rem;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s;
        text-align: center;
        width: 100%;
        margin-top: 0.25rem;
        display: inline-block;
    }
    .room-card-btn:hover {
        background: #b58322;
        color: #ffffff;
    }

    /* Dining Preview Section */
    .dining-preview-sec {
        padding: 5rem 8%;
        max-width: 1300px;
        margin: 0 auto;
    }

    .dining-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 2rem;
        margin-top: 3rem;
    }

    @media (max-width: 768px) {
        .about-sec {
            grid-template-columns: 1fr;
            gap: 2.5rem;
        }
        .about-image-stack {
            height: 350px;
        }
    }
</style>

<!-- 1. HERO BANNER SECTION -->
<section class="hero-banner" id="home">
    <div class="hero-content">
        <span class="hero-greeting">
            <i class="fas fa-crown"></i> <?php echo $greeting; ?>, <?php echo htmlspecialchars($current_username); ?>
        </span>
        <h1 class="hero-title">Experience a World of Luxury & Refinement</h1>
        <p class="hero-subtitle">Indulge in absolute oceanfront grandeur, bespoke five-star butler services, and award-winning gastronomy at the Maldives' crown jewel resort.</p>
        
        <div class="hero-buttons">
            <a href="rooms.php" class="btn btn-primary" style="padding: 1rem 2rem; background: var(--warning-color); color: #1a1200; font-size: 1rem; border-radius: 50px;">
                <i class="fas fa-bed" style="margin-right: 0.5rem;"></i> Reserve Accommodation
            </a>
            <a href="menu.php" class="btn btn-secondary" style="padding: 1rem 2rem; font-size: 1rem; border-radius: 50px; border: 1px solid rgba(255,255,255,0.25);">
                <i class="fas fa-utensils" style="margin-right: 0.5rem;"></i> Order Suite Dining
            </a>
            <?php if ($current_role !== 'Guest'): ?>
                <a href="dashboard.php" class="btn btn-secondary" style="padding: 1rem 2rem; font-size: 1rem; border-radius: 50px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.3);">
                    <i class="fas fa-chart-line" style="margin-right: 0.5rem; color: var(--primary-accent);"></i> Staff Console
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 2. ABOUT US SECTION --> 
<section class="about-sec" id="about">
    <div class="about-image-stack">
        <img src="assets/images/about-1.jpg" alt="Resort View" class="about-img-main">
        <img src="assets/images/room_deluxe.png" alt="Suite Lounge" class="about-img-sub">
    </div>
    
    <div>
        <span class="section-label">A Legacy of Grandeur</span>
        <h2 class="section-title">Where Seaside Serenity Meets Elegant Splendor</h2>
        <p class="about-text">
            ​For generations, Hotel Crown has stood as a beacon of unmatched mountain hospitality. Set against the beautiful landscapes of Welimada, our private sanctuary blends five-star modern design with absolute privacy.
        </p>
        <p class="about-text">
           Whether you choose our luxurious villas or our finest suites, you will be treated to dedicated 24/7 personal butlers, bespoke spa therapy wellness options, and gourmet dining that celebrates premium culinary craft.
        <div style="display: flex; gap: 2.5rem; margin-top: 2rem;">
            <div>
                <span style="font-family: 'Cormorant Garamond', serif; font-size: 2.5rem; font-weight: 700; color: var(--warning-color); display: block;">12</span>
                <span style="font-size: 0.75rem; text-transform: uppercase; color:  letter-spacing: 1.5px;">Premium Suites</span>
            </div>
            <div>
                <span style="font-family: 'Cormorant Garamond', serif; font-size: 2.5rem; font-weight: 700; color: var(--warning-color); display: block;">5★</span>
                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary)#FFD770; letter-spacing: 1.5px;">Luxury Rating</span>
            </div>
            <div>
                <span style="font-family: 'Cormorant Garamond', serif; font-size: 2.5rem; font-weight: 700; color: var(--warning-color); display: block;">100%</span>
                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary; letter-spacing: 1.5px;">Guest Satisfaction</span>
            </div>
        </div>
    </div>
</section>

<!-- 3. FEATURE HIGHLIGHTS -->
<section class="features-sec" id="features">
    <div style="text-align: center; margin-bottom: 4rem;">
        <span class="section-label">Royal Amenities</span>
        <h2 class="section-title">Designed for Ultimate Comfort</h2>
        <p style="color: var(--text-secondary); max-width: 600px; margin: 0 auto; font-size: 0.9rem;">Enjoy full access to our collection of signature amenities curated to make your vacation unforgettable.</p>
    </div>

    <div class="features-grid">
        <div class="glass-card feat-card">
            <div class="feat-icon"><i class="fas fa-water"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 600;">Infinity mountain Pool</h3>
            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Our temperature-controlled infinity pool merges directly with the gorgeous misty mountain horizon."</p>
        </div>
        
        <div class="glass-card feat-card">
            <div class="feat-icon"><i class="fas fa-bell"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 600;">Bespoke Butler</h3>
            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">A dedicated personal butler is at your service 24/7, attending to all requests, bookings, and packing.</p>
        </div>

        <div class="glass-card feat-card">
            <div class="feat-icon"><i class="fas fa-spa"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 600;">Luxury Wellness</h3>
            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Experience absolute relaxation with hot stone therapy, organic steam rooms, and yoga amidst the cool mountain breeze.</p>
        </div>

        <div class="glass-card feat-card">
            <div class="feat-icon"><i class="fas fa-utensils"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 600;">Award Cuisine</h3>
            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Savor the culinary creations of globally-trained chefs featuring both exotic local and western cuisines.</p>
        </div>
        <!-- Card 5: Scenic Mountain Views -->
        <div class="glass-card feat-card">
            <div class="feat-icon"><i class="fas fa-mountain"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 600;">Scenic Mountain Views</h3>
            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Wake up to breathtaking, misty mountain views and lush green landscapes right from your private balcony.</p>
        </div>

        <!-- Card 6: Guided Local Tours -->
        <div class="glass-card feat-card">
            <div class="feat-icon"><i class="fas fa-map-location-dot"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 600;">Guided Local Tours</h3>
            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">Explore the hidden beauty of Welimada with our specially curated guided tours to waterfalls, tea estates, and viewpoints.</p>
        </div>
    </div>
</section>

<!-- 4. ACCOMMODATION SUITES SHOWCASE -->
<section class="rooms-sec" id="rooms">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1.5rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 1.5rem; margin-bottom: 2rem;">
        <div>
            <span class="section-label">Luxury Stays</span>
            <h2 class="section-title" style="margin-bottom: 0;">Our Signature Rooms & Suites</h2>
        </div>
        <a href="rooms.php" class="btn btn-secondary" style="background: rgba(255,255,255,0.05); font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 50px;">
            View Room Catalog <i class="fas fa-arrow-right" style="font-size: 0.75rem; margin-left: 0.4rem;"></i>
        </a>
    </div>

    <div class="rooms-preview-grid">
        <?php if (!empty($room_types)): ?>
            <?php foreach ($room_types as $type): ?>
                <?php 
                // Set catalog preview image
                // 🌟 කාමරේ නම අනුව පින්තූර වෙන වෙනම ලෝඩ් කරනවා
                if ($type['name'] === 'Single Room') {
                    $type_img = 'assets/images/room_single.png';
                } elseif ($type['name'] === 'Double Room') {
                    $type_img = 'assets/images/room_double.png'; // 👈 ඔයා දාපු double room පින්තූරේ නම මෙතනට දෙන්න
                } elseif ($type['name'] === 'Deluxe Room') {
            $type_img = 'assets/images/room_deluxe.png';
        } elseif ($type['name'] === 'Suite Room') {
            $type_img = 'assets/images/room_suite.png';
        } else {
            $type_img = 'assets/images/room_single.png'; // Default එකක් විදිහට
        }
                ?>
                <div class="room-show-card">
                    <div class="room-img-container">
                        <img src="<?php echo htmlspecialchars($type_img); ?>" alt="<?php echo htmlspecialchars($type['name']); ?>">
                    </div>
                    <div class="room-details">
                        <div class="room-card-header">
                            <h3 class="room-card-title"><?php echo htmlspecialchars($type['name']); ?></h3>
                            <span class="room-card-price">$<?php echo number_format($type['base_price'], 0); ?>/night</span>
                        </div>
                        <p class="room-card-desc">
                            <?php echo htmlspecialchars($type['description'] ?? 'Indulge in spacious luxury layouts, private jacuzzi, and premier service.'); ?>
                        </p>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.78rem; border-top: 1px solid rgba(0,0,0,0.06); padding-top: 0.5rem; color: #777777; margin-bottom: 0.25rem;">
                            <span>Max Occupancy: <?php echo $type['max_occupancy']; ?> Guests</span>
                        </div>
                        
                        <a href="rooms.php?type_id=<?php echo $type['id']; ?>" class="room-card-btn">
                            <i class="fas fa-calendar-check" style="margin-right: 0.3rem;"></i> Book Stay
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- 5. FOOD SERVICE DINING PREVIEW -->
<section class="dining-preview-sec" id="menu">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1.5rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 1.5rem; margin-bottom: 2rem;">
        <div>
            <span class="section-label">Culinary Pleasures</span>
            <h2 class="section-title" style="margin-bottom: 0;">Featured Culinary Selections</h2>
        </div>
        <a href="menu.php" class="btn btn-secondary" style="background: rgba(255,255,255,0.05); font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 50px;">
            Full Dining Menu <i class="fas fa-arrow-right" style="font-size: 0.75rem; margin-left: 0.4rem;"></i>
        </a>
    </div>

    <div class="dining-preview-grid">
        <?php if (!empty($featured_food)): ?>
            <?php foreach ($featured_food as $item): ?>
                <div class="glass-card" style="display: flex; flex-direction: column; justify-content: space-between; gap: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;">
                        <div>
                            <span style="font-size: 0.7rem; text-transform: uppercase; color: var(--warning-color); font-weight: 600; letter-spacing: 1px; display: block; margin-bottom: 0.25rem;"><?php echo $item['category']; ?></span>
                            <h3 style="font-size: 1.1rem; font-weight: 600; margin: 0 0 0.3rem 0;"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                            <p style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.5; margin: 0;"><?php echo htmlspecialchars($item['description']); ?></p>
                        </div>
                        <span style="font-size: 1.2rem; font-weight: 700; color: var(--success-color); white-space: nowrap;">$<?php echo number_format($item['price'], 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: flex-end; border-top: 1px solid var(--glass-border); padding-top: 0.75rem;">
                        <a href="menu.php" class="btn btn-primary" style="padding: 0.35rem 0.9rem; font-size: 0.75rem; background: var(--warning-color); color: #1a1200; font-weight: 600;">
                            Order Service
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php
require_once 'includes/guest_footer.php';
?>
