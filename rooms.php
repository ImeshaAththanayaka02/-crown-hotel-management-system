<?php
// rooms.php
ob_start();

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Enforce login
require_login();

// ---- Availability Check Logic ----
if (isset($_GET['type_id']) && isset($_GET['check_in_date']) && isset($_GET['check_out_date'])) {
    $filter_cat = $_GET['type_id'];
    $check_in   = $_GET['check_in_date'];
    $check_out  = $_GET['check_out_date'];

    if (!empty($check_in) && !empty($check_out)) {
        $query = "SELECT COUNT(*) FROM rooms r 
                  WHERE (:cat1 = '' OR r.type_id = :cat2)
                  AND r.id NOT IN (
                      SELECT room_id FROM reservations 
                      WHERE status IN ('Confirmed', 'Pending')
                      AND NOT (check_out_date <= :check_in OR check_in_date >= :check_out)
                  )";

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'cat1'      => $filter_cat,
            'cat2'      => $filter_cat,
            'check_in'  => $check_in,
            'check_out' => $check_out
        ]);
        
        $available_count = $stmt->fetchColumn();

        if ($available_count > 0) {
            echo "<script>alert('Rooms are available! You can proceed with your booking.');</script>";
        } else {
            echo "<script>alert('Sorry, no rooms are available for the selected dates.');</script>";
        }
    }
}

$current_role = get_current_role();
$user_id = get_current_user_id();

// Support preview toggle for staff
$view = $_GET['view'] ?? '';
$is_guest_view = ($current_role === 'Guest' || $view === 'guest');

// Guest View Routing
if ($is_guest_view) {
    // Process guest bookings
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            set_flash_message('danger', 'Security token mismatch. Please try again.');
            header("Location: rooms.php");
            exit();
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'book_room') {
            $room_id = (int)$_POST['room_id'];
            $check_in = sanitize($_POST['check_in_date']);
            $check_out = sanitize($_POST['check_out_date']);

            // Get guest ID
            $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
            $guest_stmt->execute([$user_id]);
            $guest_id = $guest_stmt->fetchColumn();

            if (empty($guest_id) || empty($room_id) || empty($check_in) || empty($check_out)) {
                set_flash_message('danger', 'Invalid booking details. Please select correct dates.');
            } else {
                try {
                    // Check if room is available
                    $avail_chk = $pdo->prepare("
                        SELECT id, price FROM rooms 
                        WHERE id = ? AND status != 'Maintenance'
                          AND id NOT IN (
                              SELECT room_id FROM reservations 
                              WHERE status IN ('Confirmed', 'Pending') 
                                AND NOT (check_out_date <= ? OR check_in_date >= ?)
                          )
                    ");
                    $avail_chk->execute([$room_id, $check_in, $check_out]);
                    $room = $avail_chk->fetch();

                    if (!$room) {
                        set_flash_message('danger', 'This room is no longer available for the selected dates.');
                    } else {
                        // Calculate price
                        $days = (strtotime($check_out) - strtotime($check_in)) / (86400);
                        if ($days <= 0) $days = 1;
                        $total_price = $room['price'] * $days;

                        // Create reservation
                        $ins = $pdo->prepare("
                            INSERT INTO reservations (guest_id, room_id, check_in_date, check_out_date, status, total_price) 
                            VALUES (?, ?, ?, ?, 'Confirmed', ?)
                        ");
                        $ins->execute([$guest_id, $room_id, $check_in, $check_out, $total_price]);
                        $reservation_id = $pdo->lastInsertId();

                        // Send booking confirmation email
                        require_once 'includes/email_helper.php';
                        send_booking_confirmation($reservation_id);

                        set_flash_message('success', 'Your reservation was confirmed successfully! Welcome to Crown Hotel.');
                        header("Location: dashboard.php");
                        exit();
                    }
                } catch (PDOException $e) {
                    set_flash_message('danger', 'Booking failed: ' . $e->getMessage());
                }
            }
            header("Location: rooms.php");
            exit();
        }
    }

    // Load available rooms list & filters
    $filter_category = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int)$_GET['type_id'] : '';
    $chk_in = sanitize($_GET['check_in_date'] ?? '');
    $chk_out = sanitize($_GET['check_out_date'] ?? '');

    $where_clauses = ["r.status != 'Maintenance'"];
    $query_params = [];

    if ($filter_category !== '') {
        $where_clauses[] = "r.type_id = :type_id";
        $query_params['type_id'] = $filter_category;
    }

    $has_dates = ($chk_in !== '' && $chk_out !== '') ? 1 : 0;
    $query_params['has_dates1'] = $has_dates;
    $query_params['has_dates2'] = $has_dates;
    $query_params['check_in'] = $chk_in;
    $query_params['check_out'] = $chk_out;

    $where_sql = implode(" AND ", $where_clauses);
    $rooms_stmt = $pdo->prepare("
        SELECT r.*, rt.name AS type_name, rt.description AS type_desc, rt.max_occupancy,
               (CASE 
                    WHEN :has_dates1 = 1 AND r.id IN (
                        SELECT room_id FROM reservations 
                        WHERE status IN ('Confirmed', 'Pending') 
                          AND NOT (check_out_date <= :check_in OR check_in_date >= :check_out)
                    ) THEN 0
                    WHEN :has_dates2 = 0 AND r.status != 'Available' THEN 0
                    ELSE 1
               END) AS is_available
        FROM rooms r 
        JOIN room_types rt ON r.type_id = rt.id 
        WHERE $where_sql
        ORDER BY r.price ASC
    ");
    $rooms_stmt->execute($query_params);
    $rooms_all = $rooms_stmt->fetchAll();

    // Group rooms by type_id in PHP: select the first available room of each type if one exists,
    // otherwise select any room of that type.
    $rooms_list = [];
    foreach ($rooms_all as $room) {
        $type_id = $room['type_id'];
        if (!isset($rooms_list[$type_id])) {
            $rooms_list[$type_id] = $room;
        } else {
            // If the current chosen representative is unavailable, but this room is available,
            // swap it so we display/book the available room.
            if ($rooms_list[$type_id]['is_available'] == 0 && $room['is_available'] == 1) {
                $rooms_list[$type_id] = $room;
            }
        }
    }
    // Re-index array keys to be 0-based
    $rooms_list = array_values($rooms_list);

    $categories = $pdo->query("SELECT * FROM room_types ORDER BY name ASC")->fetchAll();
    $csrf_token = generate_csrf_token();
    
    $page_title = 'Explore Rooms - Hotel Crown';
    require_once 'includes/guest_header.php';
    ?>
    <style>
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2.5rem;
            margin-top: 2rem;
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
        .room-card-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            color: #db2777;
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
            background: #db2777;
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
            background: #be185d;
            color: #ffffff;
        }
    </style>
    <?php
    if ($current_role !== 'Guest') {
        echo '<div style="background: #fbbf24; color: #1a1200; padding: 0.75rem 5%; text-align: center; font-size: 0.85rem; font-weight: 600; position: sticky; top: 80px; z-index: 999; display: flex; align-items: center; justify-content: center; gap: 1rem; border-bottom: 1px solid rgba(0,0,0,0.15);">
            <span><i class="fas fa-info-circle"></i> You are viewing the Customer-Facing Room catalog as Admin/Staff.</span>
            <a href="rooms.php" class="btn btn-secondary" style="background: rgba(0,0,0,0.1); border: 1px solid rgba(0,0,0,0.15); padding: 0.35rem 0.85rem; font-size: 0.75rem; color: #1a1200; border-radius: 6px;">Switch to Admin View</a>
        </div>';
    }
    ?>
    <div style="max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem;">
        <!-- Banner Title -->
        <div style="text-align: center; margin-bottom: 3rem;">
            <span style="color: var(--text-primary); font-size: 0.85rem; letter-spacing: 4px; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 0.5rem;">Rooms</span>
            <h1 style="font-family: 'Cormorant Garamond', serif; font-size: 3.2rem; font-weight: 600; color: #db2777; margin: 0;">Hand Picked Rooms</h1>
        </div>

        <!-- Search / Filter Card -->
        <div class="glass-card" style="margin-bottom: 3rem; padding: 2rem;">
            <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: 1.25rem;">
                <i class="fas fa-search" style="color: var(--warning-color);"></i> Find Available Rooms
            </h3>
            <form action="rooms.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) auto; gap: 1.5rem; align-items: flex-end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Category</label>
                    <select name="type_id" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category === $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Check-in Date</label>
                    <input type="date" name="check_in_date" class="form-input" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($chk_in); ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Check-out Date</label>
                    <input type="date" name="check_out_date" class="form-input" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo htmlspecialchars($chk_out); ?>">
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <button type="submit" class="btn btn-primary" style="height: 46px; background: var(--warning-color); color: #1a1200;"><i class="fas fa-search"></i> Check Availability</button>
                    <?php if ($filter_category || $chk_in || $chk_out): ?>
                        <a href="rooms.php" class="btn btn-secondary" style="height: 46px; display: flex; align-items: center;">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Rooms Grid -->
        <div class="rooms-grid">
            <?php if (count($rooms_list) > 0): ?>
                <?php foreach ($rooms_list as $room): ?>
                    <?php 
                    // --- Category-based image map ---
                    $category_image_map = [
                        'Single Room'  => 'assets/images/room_single.png',
                        'Double Room'  => 'assets/images/room_double.png',
                        'Deluxe Room'  => 'assets/images/room_deluxe.png',
                        'Suite Room'   => 'assets/images/room_suite.png',
                    ];
                    $room_img = '';
                    $has_img  = false;

                    if ($room['image_path'] && file_exists('uploads/' . $room['image_path'])) {
                        // Uploaded image takes priority
                        $room_img = 'uploads/' . $room['image_path'];
                        $has_img  = true;
                    } elseif (isset($category_image_map[$room['type_name']])
                              && file_exists($category_image_map[$room['type_name']])) {
                        // Match by category name
                        $room_img = $category_image_map[$room['type_name']];
                        $has_img  = true;
                    } elseif (file_exists('assets/images/login_bg.png')) {
                        // Generic fallback
                        $room_img = 'assets/images/login_bg.png';
                        $has_img  = true;
                    }
                    ?>
                    <div class="room-show-card">
                        <div class="room-img-container">
                            <?php if ($has_img): ?>
                                <img src="<?php echo htmlspecialchars($room_img); ?>" alt="<?php echo htmlspecialchars($room['type_name']); ?>">
                            <?php else: ?>
                                <div class="image-placeholder-fallback">
                                    <i class="fas fa-bed"></i>
                                    <span><?php echo htmlspecialchars($room['type_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <span style="position: absolute; top: 1rem; right: 1rem; background: rgba(15, 23, 42, 0.85); border: 1px solid var(--glass-border); padding: 0.4rem 0.8rem; border-radius: 50px; font-size: 0.8rem; font-weight: 600; color: var(--warning-color); z-index: 10;">
                                Room <?php echo htmlspecialchars($room['room_number']); ?>
                            </span>
                        </div>

                        <div class="room-details">
                            <div class="room-card-header">
                                <h3 class="room-card-title"><?php echo htmlspecialchars($room['type_name']); ?></h3>
                                <span class="room-card-price">$<?php echo number_format($room['price'], 0); ?>/night</span>
                            </div>
                            <p class="room-card-desc">
                                <?php echo htmlspecialchars($room['type_desc'] ?? 'Premium accommodation with upscale bedding, air conditioning, and full personal butler amenities.'); ?>
                            </p>
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.78rem; border-top: 1px solid rgba(0,0,0,0.06); padding-top: 0.5rem; color: #777777; margin-bottom: 0.25rem;">
                                <span><i class="fas fa-users" style="margin-right: 0.3rem; color: #db2777;"></i> Occupancy: <?php echo $room['max_occupancy']; ?> Guests</span>
                                <?php if ($room['is_available']): ?>
                                    <span style="display: flex; align-items: center; gap: 0.3rem;"><i class="fas fa-circle" style="font-size: 0.5rem; color: var(--success-color);"></i> Available</span>
                                <?php else: ?>
                                    <span style="display: flex; align-items: center; gap: 0.3rem;"><i class="fas fa-circle" style="font-size: 0.5rem; color: var(--danger-color);"></i> Fully Booked</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($room['is_available']): ?>
                                <button class="room-card-btn" onclick="openBookingModal(<?php echo $room['id']; ?>, '<?php echo $room['room_number']; ?>', '<?php echo $room['price']; ?>')">
                                    <i class="fas fa-calendar-check" style="margin-right: 0.3rem;"></i> Book Now
                                </button>
                            <?php else: ?>
                                <button class="room-card-btn" style="background: #9ca3af; cursor: not-allowed;" disabled>
                                    <i class="fas fa-ban" style="margin-right: 0.3rem;"></i> Fully Booked
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="glass-card" style="grid-column: 1 / -1; text-align: center; padding: 4rem;">
                    <i class="fas fa-hotel" style="font-size: 3rem; color: var(--text-secondary); opacity: 0.3; margin-bottom: 1rem;"></i>
                    <p style="font-weight: 500; font-size: 1.1rem;">No available rooms match your criteria.</p>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Try choosing different stay dates or category filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Guest Booking Modal -->
    <div class="modal" id="gBookingModal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3>Book Luxury Room</h3>
                <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('gBookingModal')">&times;</button>
            </div>
            
            <form action="rooms.php" method="POST" id="bookingForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="book_room">
                <input type="hidden" name="room_id" id="book_room_id">

                <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <h4 style="margin: 0 0 0.75rem 0; font-family: 'Cormorant Garamond', serif; font-size: 1.25rem; color: var(--warning-color);">Selected Accommodation</h4>
                    <p style="margin: 0.25rem 0; font-size: 0.9rem;">Room Number: <strong>Room <span id="lbl_room_number"></span></strong></p>
                    <p style="margin: 0.25rem 0; font-size: 0.9rem;">Rate per Night: <strong>$<span id="lbl_room_rate"></span></strong></p>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Check-in Date</label>
                        <input type="date" name="check_in_date" id="modal_check_in" class="form-input" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($chk_in !== '' ? $chk_in : date('Y-m-d')); ?>" onchange="recalculateCost()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Check-out Date</label>
                        <input type="date" name="check_out_date" id="modal_check_out" class="form-input" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo htmlspecialchars($chk_out !== '' ? $chk_out : date('Y-m-d', strtotime('+1 day'))); ?>" onchange="recalculateCost()" required>
                    </div>
                </div>

                <div style="background: rgba(56,189,248,0.06); border: 1px solid rgba(56,189,248,0.2); padding: 1rem; border-radius: 8px; margin-top: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="font-size: 0.8rem; color: var(--text-secondary);">Stay Duration:</span>
                        <strong style="display: block; font-size: 1rem;"><span id="lbl_stay_nights">1</span> Nights</strong>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 0.8rem; color: var(--text-secondary);">Total Price:</span>
                        <strong style="display: block; font-size: 1.3rem; color: var(--success-color);">$<span id="lbl_total_price">0.00</span></strong>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('gBookingModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200;"><i class="fas fa-check"></i> Confirm Reservation</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentRoomRate = 0;

        function openBookingModal(roomId, roomNumber, price) {
            document.getElementById('book_room_id').value = roomId;
            document.getElementById('lbl_room_number').textContent = roomNumber;
            document.getElementById('lbl_room_rate').textContent = parseFloat(price).toFixed(2);
            currentRoomRate = parseFloat(price);

            // Set search dates to modal if available
            const searchCheckin = "<?php echo $chk_in; ?>";
            const searchCheckout = "<?php echo $chk_out; ?>";

            if (searchCheckin) document.getElementById('modal_check_in').value = searchCheckin;
            if (searchCheckout) document.getElementById('modal_check_out').value = searchCheckout;

            recalculateCost();
            openModal('gBookingModal');
        }

        function recalculateCost() {
            const checkinVal = document.getElementById('modal_check_in').value;
            const checkoutVal = document.getElementById('modal_check_out').value;
            
            if (!checkinVal || !checkoutVal) return;

            const checkin = new Date(checkinVal);
            const checkout = new Date(checkoutVal);
            
            let diffDays = Math.ceil((checkout - checkin) / (1000 * 60 * 60 * 24));
            if (isNaN(diffDays) || diffDays <= 0) diffDays = 1;

            document.getElementById('lbl_stay_nights').textContent = diffDays;
            
            const total = diffDays * currentRoomRate;
            document.getElementById('lbl_total_price').textContent = total.toFixed(2);
        }
    </script>
    <?php
    require_once 'includes/guest_footer.php';
    exit();
}

$page_title = 'Room Management';
require_once 'includes/header.php';

// Enforce role-based access control
require_role(['Admin', 'Manager']);

$error_msg = '';
$success_msg = '';

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: rooms.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // ADD ROOM
    if ($action === 'create') {
        $room_number = sanitize($_POST['room_number']);
        $type_id = (int)$_POST['type_id'];
        $price = (float)$_POST['price'];
        $status = sanitize($_POST['status']);
        $image_filename = null;

        // Image upload handling
        if (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === UPLOAD_ERR_OK) {
            $upload = upload_image($_FILES['room_image'], 'uploads/');
            if ($upload['status']) {
                $image_filename = $upload['filename'];
            } else {
                set_flash_message('danger', 'Image upload failed: ' . $upload['message']);
                header("Location: rooms.php");
                exit();
            }
        }

        if (empty($room_number) || empty($type_id) || empty($price) || empty($status)) {
            set_flash_message('danger', 'All fields are required.');
        } else {
            try {
                // Check duplicate
                $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_number = ?");
                $stmt->execute([$room_number]);
                if ($stmt->fetch()) {
                    set_flash_message('danger', "Room #{$room_number} already exists.");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO rooms (room_number, type_id, price, status, image_path) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$room_number, $type_id, $price, $status, $image_filename]);
                    
                    // If marked Dirty, seed an initial housekeeping log
                    if ($status === 'Dirty') {
                        $room_id = $pdo->lastInsertId();
                        $hk_stmt = $pdo->prepare("INSERT INTO housekeeping (room_id, remarks) VALUES (?, 'Initial setup dirty status')");
                        $hk_stmt->execute([$room_id]);
                    }
                    
                    set_flash_message('success', "Room #{$room_number} added successfully!");
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'Error adding room: ' . $e->getMessage());
            }
        }
        header("Location: rooms.php");
        exit();
    }

    // UPDATE ROOM
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $room_number = sanitize($_POST['room_number']);
        $type_id = (int)$_POST['type_id'];
        $price = (float)$_POST['price'];
        $status = sanitize($_POST['status']);
        
        try {
            // Fetch current room
            $stmt = $pdo->prepare("SELECT image_path FROM rooms WHERE id = ?");
            $stmt->execute([$id]);
            $current_room = $stmt->fetch();
            $image_filename = $current_room['image_path'] ?? null;

            // Handle new image upload if specified
            if (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === UPLOAD_ERR_OK) {
                $upload = upload_image($_FILES['room_image'], 'uploads/');
                if ($upload['status']) {
                    $image_filename = $upload['filename'];
                }
            }

            $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, type_id = ?, price = ?, status = ?, image_path = ? WHERE id = ?");
            $stmt->execute([$room_number, $type_id, $price, $status, $image_filename, $id]);
            
            // Sync with housekeeping if status is updated to Dirty
            if ($status === 'Dirty') {
                $hk_check = $pdo->prepare("SELECT id FROM housekeeping WHERE room_id = ? AND status != 'Clean'");
                $hk_check->execute([$id]);
                if (!$hk_check->fetch()) {
                    $hk_insert = $pdo->prepare("INSERT INTO housekeeping (room_id, status, remarks) VALUES (?, 'Dirty', 'Status updated to Dirty by Management')");
                    $hk_insert->execute([$id]);
                }
            }
            
            set_flash_message('success', "Room #{$room_number} updated successfully.");
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error updating room: ' . $e->getMessage());
        }
        header("Location: rooms.php");
        exit();
    }

    // DELETE ROOM
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            // Check status first
            $stmt = $pdo->prepare("SELECT room_number, status FROM rooms WHERE id = ?");
            $stmt->execute([$id]);
            $room = $stmt->fetch();

            if ($room) {
                if ($room['status'] === 'Occupied') {
                    set_flash_message('danger', "Room #{$room['room_number']} is currently occupied and cannot be deleted.");
                } else {
                    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
                    $stmt->execute([$id]);
                    set_flash_message('success', "Room #{$room['room_number']} deleted successfully.");
                }
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Cannot delete room because it has associated reservations or check-in history.');
        }
        header("Location: rooms.php");
        exit();
    }
}

// Search and Filter parameters
$search = sanitize($_GET['search'] ?? '');
$filter_type = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int)$_GET['type_id'] : '';
$filter_status = sanitize($_GET['status'] ?? '');

// Pagination Config
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Build query
$where_clauses = ["1=1"];
$params = [];

if ($search !== '') {
    $where_clauses[] = "r.room_number LIKE :search";
    $params['search'] = "%$search%";
}
if ($filter_type !== '') {
    $where_clauses[] = "r.type_id = :type_id";
    $params['type_id'] = $filter_type;
}
if ($filter_status !== '') {
    $where_clauses[] = "r.status = :status";
    $params['status'] = $filter_status;
}

$where_sql = implode(" AND ", $where_clauses);

// Count Total Rooms for pagination
$count_query = "SELECT COUNT(*) FROM rooms r WHERE $where_sql";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_rows = $stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Rooms
$query = "
    SELECT r.*, rt.name AS type_name, rt.base_price 
    FROM rooms r 
    JOIN room_types rt ON r.type_id = rt.id 
    WHERE $where_sql 
    ORDER BY r.room_number ASC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rooms = $stmt->fetchAll();

// Fetch Categories for form dropdowns
$categories = $pdo->query("SELECT * FROM room_types ORDER BY name ASC")->fetchAll();
$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- TOP CONTROL PANEL -->
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <!-- Filters Form -->
        <form action="rooms.php" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; flex: 1;">
            <input type="text" name="search" placeholder="Search Room Number" class="form-input" style="max-width: 200px;" value="<?php echo htmlspecialchars($search); ?>">
            
            <select name="type_id" class="form-select" style="max-width: 200px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filter_type === $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="status" class="form-select" style="max-width: 200px;">
                <option value="">All Statuses</option>
                <option value="Available" <?php echo $filter_status === 'Available' ? 'selected' : ''; ?>>Available</option>
                <option value="Occupied" <?php echo $filter_status === 'Occupied' ? 'selected' : ''; ?>>Occupied</option>
                <option value="Dirty" <?php echo $filter_status === 'Dirty' ? 'selected' : ''; ?>>Dirty</option>
                <option value="Maintenance" <?php echo $filter_status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
            </select>
            
            <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search || $filter_type || $filter_status): ?>
                <a href="rooms.php" class="btn btn-secondary" style="background: rgba(255,0,0,0.1); border-color: rgba(255,0,0,0.2);">Clear</a>
            <?php endif; ?>
        </form>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <a href="rooms.php?view=guest" class="btn btn-secondary" style="height: 44px; display: inline-flex; align-items: center;"><i class="fas fa-eye"></i> View as Guest</a>
            <button class="btn btn-primary" style="height: 44px;" onclick="openAddRoomModal()"><i class="fas fa-plus"></i> Add New Room</button>
        </div>
    </div>

    <!-- ROOMS LISTING -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="custom-table" id="rooms-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Room Number</th>
                        <th>Category</th>
                        <th>Price/Night</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rooms) > 0): ?>
                        <?php foreach ($rooms as $room): ?>
                            <tr>
                                <td>
                                    <?php 
                                    $adm_img_map = [
                                        'Single Room' => 'assets/images/room_single.png',
                                        'Double Room' => 'assets/images/room_double.png',
                                        'Deluxe Room' => 'assets/images/room_deluxe.png',
                                        'Suite Room'  => 'assets/images/room_suite.png',
                                    ];
                                    if ($room['image_path']) {
                                        $display_img = 'uploads/' . htmlspecialchars($room['image_path']);
                                    } elseif (isset($adm_img_map[$room['type_name']])
                                              && file_exists($adm_img_map[$room['type_name']])) {
                                        $display_img = $adm_img_map[$room['type_name']];
                                    } else {
                                        $display_img = 'assets/images/login_bg.png';
                                    }
                                    ?>
                                    <img src="<?php echo $display_img; ?>" alt="Room Image" style="width: 60px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid var(--glass-border);">
                                </td>
                                <td><strong>Room <?php echo htmlspecialchars($room['room_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($room['type_name']); ?></td>
                                <td><strong><?php echo format_currency($room['price']); ?></strong></td>
                                <td>
                                    <?php 
                                    $badge_class = 'badge-success';
                                    if ($room['status'] === 'Occupied') $badge_class = 'badge-warning';
                                    if ($room['status'] === 'Dirty') $badge_class = 'badge-danger';
                                    if ($room['status'] === 'Maintenance') $badge_class = 'badge-info';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $room['status']; ?></span>
                                </td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem; align-items: center; height: 60px;">
                                    <button class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" onclick="openEditRoomModal(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form action="rooms.php" method="POST" onsubmit="return confirm('Are you sure you want to delete Room <?php echo $room['room_number']; ?>?')" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $room['id']; ?>">
                                        <button class="btn btn-danger" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" type="submit">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-secondary);">No rooms found matching filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION CONTROLS -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="rooms.php?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type_id=<?php echo $filter_type; ?>&status=<?php echo $filter_status; ?>" class="btn <?php echo $page === $i ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ==========================================
      MODALS SECTION
     ========================================== -->

<!-- ADD ROOM MODAL -->
<div class="modal" id="addRoomModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add New Room</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('addRoomModal')">&times;</button>
        </div>
        
        <form action="rooms.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="create">
            
            <div class="form-group">
                <label class="form-label">Room Number</label>
                <input type="text" name="room_number" placeholder="e.g. 105" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Room Category</label>
                <select name="type_id" class="form-select" id="add_type_id" onchange="autoPopulatePrice('add')" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" data-price="<?php echo $cat['base_price']; ?>">
                            <?php echo htmlspecialchars($cat['name']); ?> (Base: <?php echo format_currency($cat['base_price']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Pricing Per Night ($)</label>
                <input type="number" step="0.01" name="price" id="add_price" placeholder="120.00" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" required>
                    <option value="Available">Available</option>
                    <option value="Dirty">Dirty</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Room Image</label>
                <input type="file" name="room_image" class="form-input" style="padding: 0.5rem;">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addRoomModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Room</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ROOM MODAL -->
<div class="modal" id="editRoomModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Room Details</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('editRoomModal')">&times;</button>
        </div>
        
        <form action="rooms.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="form-group">
                <label class="form-label">Room Number</label>
                <input type="text" name="room_number" id="edit_room_number" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Room Category</label>
                <select name="type_id" id="edit_type_id" class="form-select" onchange="autoPopulatePrice('edit')" required>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" data-price="<?php echo $cat['base_price']; ?>">
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Pricing Per Night ($)</label>
                <input type="number" step="0.01" name="price" id="edit_price" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" id="edit_status" class="form-select" required>
                    <option value="Available">Available</option>
                    <option value="Occupied">Occupied</option>
                    <option value="Dirty">Dirty</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Replace Room Image (Optional)</label>
                <input type="file" name="room_image" class="form-input" style="padding: 0.5rem;">
                <p id="edit_image_note" style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;"></p>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editRoomModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * Automatically set room price based on selected category type
 */
function autoPopulatePrice(mode) {
    const selectId = mode === 'add' ? 'add_type_id' : 'edit_type_id';
    const priceInputId = mode === 'add' ? 'add_price' : 'edit_price';
    
    const select = document.getElementById(selectId);
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption && selectedOption.dataset.price) {
        document.getElementById(priceInputId).value = parseFloat(selectedOption.dataset.price).toFixed(2);
    }
}

function openAddRoomModal() {
    openModal('addRoomModal');
}

function openEditRoomModal(room) {
    document.getElementById('edit_id').value = room.id;
    document.getElementById('edit_room_number').value = room.room_number;
    document.getElementById('edit_type_id').value = room.type_id;
    document.getElementById('edit_price').value = parseFloat(room.price).toFixed(2);
    document.getElementById('edit_status').value = room.status;
    
    const imageNote = document.getElementById('edit_image_note');
    if (room.image_path) {
        imageNote.textContent = 'Current file: ' + room.image_path;
    } else {
        imageNote.textContent = 'No current image set.';
    }
    
    openModal('editRoomModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
