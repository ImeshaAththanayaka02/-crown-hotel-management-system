<?php
// reservations.php
ob_start();

$page_title = 'Reservations & Bookings';
require_once 'includes/header.php';

// $pdo, $current_role, $current_username are defined in header.php
$user_id = get_current_user_id();

$error_msg = '';
$success_msg = '';

// Determine guest context
$guest_id = null;
if ($current_role === 'Guest') {
    $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $guest_stmt->execute([$user_id]);
    $guest_id = $guest_stmt->fetchColumn();
}

// ---------------------------------------------------------
// HANDLE FORM ACTIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: reservations.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // CHECK ROOM AVAILABILITY AJAX-like or inline
    if ($action === 'check_availability') {
        $type_id = (int)$_POST['type_id'];
        $check_in = sanitize($_POST['check_in_date']);
        $check_out = sanitize($_POST['check_out_date']);

        if (strtotime($check_in) >= strtotime($check_out) || strtotime($check_in) < strtotime(date('Y-m-d'))) {
            set_flash_message('danger', 'Invalid check-in/check-out date selection.');
        } else {
            $_SESSION['chk_type_id'] = $type_id;
            $_SESSION['chk_check_in'] = $check_in;
            $_SESSION['chk_check_out'] = $check_out;

            try {
                // Find rooms count that have NO overlaps with pending or confirmed bookings
                $count_stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM rooms r 
                    WHERE r.type_id = ? 
                      AND r.status != 'Maintenance'
                      AND r.id NOT IN (
                          SELECT room_id FROM reservations 
                          WHERE status IN ('Confirmed', 'Pending') 
                            AND NOT (check_out_date <= ? OR check_in_date >= ?)
                      )
                ");
                $count_stmt->execute([$type_id, $check_in, $check_out]);
                $avail_count = (int)$count_stmt->fetchColumn();

                if ($avail_count > 0) {
                    set_flash_message('success', "Success! {$avail_count} room(s) found available for the selected dates.");
                } else {
                    set_flash_message('danger', "Sorry! No rooms are available for the selected dates.");
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'An error occurred while checking room availability.');
            }
        }
        header("Location: reservations.php#checker");
        exit();
    }

    // CREATE RESERVATION
    if ($action === 'book') {
        $room_id = (int)$_POST['room_id'];
        $check_in = sanitize($_POST['check_in_date']);
        $check_out = sanitize($_POST['check_out_date']);
        
        // If guest, book for self. If staff, book for selected guest.
        if ($current_role === 'Guest') {
            $booking_guest_id = $guest_id;
        } else {
            $booking_guest_id = (int)$_POST['guest_id'];
        }

        if (empty($booking_guest_id) || empty($room_id) || empty($check_in) || empty($check_out)) {
            set_flash_message('danger', 'All details are required to complete reservation.');
        } else {
            try {
                // Fetch room price
                $room_stmt = $pdo->prepare("SELECT price FROM rooms WHERE id = ?");
                $room_stmt->execute([$room_id]);
                $room_price = $room_stmt->fetchColumn();

                // Calculate duration and total price
                $days = (strtotime($check_out) - strtotime($check_in)) / (60 * 60 * 24);
                if ($days <= 0) $days = 1;
                $total_price = $room_price * $days;

                // Create reservation
                $stmt = $pdo->prepare("INSERT INTO reservations (guest_id, room_id, check_in_date, check_out_date, status, total_price) VALUES (?, ?, ?, ?, 'Confirmed', ?)");
                $stmt->execute([$booking_guest_id, $room_id, $check_in, $check_out, $total_price]);
                $reservation_id = $pdo->lastInsertId();

                // Send booking confirmation email
                require_once 'includes/email_helper.php';
                send_booking_confirmation($reservation_id);

                unset($_SESSION['chk_type_id']);
                unset($_SESSION['chk_check_in']);
                unset($_SESSION['chk_check_out']);

                set_flash_message('success', 'Reservation booked successfully!');
            } catch (PDOException $e) {
                set_flash_message('danger', 'Booking failed: ' . $e->getMessage());
            }
        }
        header("Location: reservations.php");
        exit();
    }

    // UPDATE RESERVATION STATUS (Staff only: Confirm/Cancel/Complete)
    if ($action === 'update_status') {
        $res_id = (int)$_POST['id'];
        $new_status = sanitize($_POST['status']);
        
        try {
            $stmt = $pdo->prepare("SELECT guest_id, room_id, status FROM reservations WHERE id = ?");
            $stmt->execute([$res_id]);
            $res = $stmt->fetch();

            if ($res) {
                // Enforce guest cancel permissions (Guests can cancel own pending/confirmed)
                if ($current_role === 'Guest' && $res['guest_id'] != $guest_id) {
                    set_flash_message('danger', 'Access denied.');
                } elseif ($current_role === 'Guest' && $new_status !== 'Cancelled') {
                    set_flash_message('danger', 'Guests are only permitted to cancel reservations.');
                } else {
                    $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $res_id]);
                    set_flash_message('success', "Reservation status updated to: {$new_status}");
                }
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Failed to update status: ' . $e->getMessage());
        }
        header("Location: reservations.php");
        exit();
    }
}

// ---------------------------------------------------------
// QUERY DATA FOR LISTS
// ---------------------------------------------------------

// Search and Filter variables
$search_date = sanitize($_GET['search_date'] ?? '');
$filter_status = sanitize($_GET['status'] ?? '');

// Pagination config
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Build filter clauses
$where_clauses = ["1=1"];
$params = [];

if ($current_role === 'Guest') {
    $where_clauses[] = "r.guest_id = :guest_id";
    $params['guest_id'] = $guest_id;
}

if ($search_date !== '') {
    $where_clauses[] = "(r.check_in_date = :search_date OR r.check_out_date = :search_date)";
    $params['search_date'] = $search_date;
}

if ($filter_status !== '') {
    $where_clauses[] = "r.status = :status";
    $params['status'] = $filter_status;
}

$where_sql = implode(" AND ", $where_clauses);

// Count bookings
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations r WHERE $where_sql");
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch bookings
$bookings_stmt = $pdo->prepare("
    SELECT r.*, g.first_name, g.last_name, g.phone, rm.room_number, rt.name AS room_type 
    FROM reservations r 
    JOIN guests g ON r.guest_id = g.id 
    JOIN rooms rm ON r.room_id = rm.id 
    JOIN room_types rt ON rm.type_id = rt.id 
    WHERE $where_sql 
    ORDER BY r.check_in_date ASC 
    LIMIT $limit OFFSET $offset
");
$bookings_stmt->execute($params);
$bookings = $bookings_stmt->fetchAll();

// Fetch room categories for search
$categories = $pdo->query("SELECT * FROM room_types ORDER BY name ASC")->fetchAll();

// Fetch guests for staff booking form
$all_guests = [];
if ($current_role !== 'Guest') {
    $all_guests = $pdo->query("SELECT id, first_name, last_name, email FROM guests ORDER BY first_name ASC")->fetchAll();
}

// Check if availability checker is running
$available_rooms = [];
$chk_type_id = $_SESSION['chk_type_id'] ?? null;
$chk_check_in = $_SESSION['chk_check_in'] ?? null;
$chk_check_out = $_SESSION['chk_check_out'] ?? null;

if ($chk_type_id && $chk_check_in && $chk_check_out) {
    // Find rooms that have NO overlaps with pending or confirmed bookings
    $avail_stmt = $pdo->prepare("
        SELECT r.*, rt.name AS type_name 
        FROM rooms r 
        JOIN room_types rt ON r.type_id = rt.id 
        WHERE r.type_id = ? 
          AND r.status != 'Maintenance'
          AND r.id NOT IN (
              SELECT room_id FROM reservations 
              WHERE status IN ('Confirmed', 'Pending') 
                AND NOT (check_out_date <= ? OR check_in_date >= ?)
          )
        ORDER BY r.room_number ASC
    ");
    $avail_stmt->execute([$chk_type_id, $chk_check_in, $chk_check_out]);
    $available_rooms = $avail_stmt->fetchAll();
}

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 2rem;">

    <!-- 1. ROOM AVAILABILITY CHECKER -->
    <div class="glass-card" id="checker">
        <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-search-location" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Check Room Availability</h3>
        
        <form action="reservations.php" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto; gap: 1rem; align-items: flex-end;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="check_availability">
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Room Category</label>
                <select name="type_id" class="form-select" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $chk_type_id == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Check-in Date</label>
                <input type="date" name="check_in_date" class="form-input" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($chk_check_in ?? date('Y-m-d')); ?>" required>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Check-out Date</label>
                <input type="date" name="check_out_date" class="form-input" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo htmlspecialchars($chk_check_out ?? date('Y-m-d', strtotime('+1 day'))); ?>" required>
            </div>
            
            <button type="submit" class="btn btn-primary" style="height: 45px;"><i class="fas fa-search"></i> Check Rooms</button>
        </form>

        <!-- AVAILABILITY RESULTS -->
        <?php if ($chk_type_id && $chk_check_in && $chk_check_out): ?>
            <div style="margin-top: 1.5rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem;">
                <h4 style="margin-bottom: 1rem; color: var(--primary-accent);">
                    Available Rooms (<?php echo count($available_rooms); ?> found) for: 
                    <?php echo format_date($chk_check_in); ?> to <?php echo format_date($chk_check_out); ?>
                </h4>
                
                <?php if (count($available_rooms) > 0): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                        <?php foreach ($available_rooms as $room): ?>
                            <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: rgba(255,255,255,0.02);">
                                <div>
                                    <h4 style="margin: 0;">Room <?php echo htmlspecialchars($room['room_number']); ?></h4>
                                    <span style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo htmlspecialchars($room['type_name']); ?></span><br>
                                    <span style="font-size: 0.95rem; font-weight: 600; color: var(--success-color);"><?php echo format_currency($room['price']); ?>/night</span>
                                </div>
                                <button class="btn btn-primary" style="padding: 0.4rem 1rem; font-size: 0.8rem;" onclick="openBookingModal(<?php echo $room['id']; ?>, '<?php echo $room['room_number']; ?>', '<?php echo $room['price']; ?>')">
                                    <i class="fas fa-check"></i> Book Now
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: var(--danger-color); font-weight: 500;">No available rooms matching this category for the selected dates. Try other dates or categories.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2. RESERVATION RECORDS LIST -->
    <div class="glass-card">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <h3><i class="fas fa-list-ul" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Active Reservations</h3>
            
            <form action="reservations.php" method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <input type="date" name="search_date" class="form-input" style="max-width: 170px;" value="<?php echo htmlspecialchars($search_date); ?>">
                
                <select name="status" class="form-select" style="max-width: 150px;">
                    <option value="">All Statuses</option>
                    <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Confirmed" <?php echo $filter_status === 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="Cancelled" <?php echo $filter_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
                
                <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem;"><i class="fas fa-filter"></i> Filter</button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Total Cost</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($bookings) > 0): ?>
                        <?php foreach ($bookings as $b): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($b['first_name'] . ' ' . $b['last_name']); ?></strong><br>
                                    <span style="font-size: 0.75rem; color: var(--text-secondary);"><?php echo htmlspecialchars($b['phone']); ?></span>
                                </td>
                                <td>
                                    <strong>Room <?php echo htmlspecialchars($b['room_number']); ?></strong><br>
                                    <span style="font-size: 0.75rem; color: var(--text-secondary);"><?php echo htmlspecialchars($b['room_type']); ?></span>
                                </td>
                                <td><?php echo format_date($b['check_in_date']); ?></td>
                                <td><?php echo format_date($b['check_out_date']); ?></td>
                                <td><strong><?php echo format_currency($b['total_price']); ?></strong></td>
                                <td>
                                    <?php 
                                    $badge = 'badge-info';
                                    if ($b['status'] === 'Confirmed') $badge = 'badge-success';
                                    if ($b['status'] === 'Cancelled') $badge = 'badge-danger';
                                    if ($b['status'] === 'Completed') $badge = 'badge-success';
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo $b['status']; ?></span>
                                </td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem; align-items: center; min-height: 50px;">
                                    <?php if ($b['status'] === 'Confirmed' || $b['status'] === 'Pending'): ?>
                                        <form action="reservations.php" method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking?')" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                            <input type="hidden" name="status" value="Cancelled">
                                            <button type="submit" class="btn btn-danger" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($current_role !== 'Guest' && $b['status'] === 'Pending'): ?>
                                        <form action="reservations.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                            <input type="hidden" name="status" value="Confirmed">
                                            <button type="submit" class="btn btn-primary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: var(--success-color); border-color: var(--success-color); color: #fff;">
                                                Confirm
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-secondary);">No reservations found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="reservations.php?page=<?php echo $i; ?>&search_date=<?php echo urlencode($search_date); ?>&status=<?php echo $filter_status; ?>" class="btn <?php echo $page === $i ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ==========================================
      BOOKING CONFIRMATION MODAL
     ========================================== -->
<div class="modal" id="bookingModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Complete Stay Booking</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('bookingModal')">&times;</button>
        </div>
        
        <form action="reservations.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="book">
            <input type="hidden" name="room_id" id="book_room_id">
            <input type="hidden" name="check_in_date" value="<?php echo htmlspecialchars($chk_check_in ?? ''); ?>">
            <input type="hidden" name="check_out_date" value="<?php echo htmlspecialchars($chk_check_out ?? ''); ?>">
            
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <h4 style="margin: 0 0 0.5rem 0;">Booking Details:</h4>
                <p style="margin: 0.25rem 0; font-size: 0.9rem;">Room: <strong>Room <span id="lbl_room_number"></span></strong></p>
                <p style="margin: 0.25rem 0; font-size: 0.9rem;">Check-in: <strong><?php echo format_date($chk_check_in); ?></strong></p>
                <p style="margin: 0.25rem 0; font-size: 0.9rem;">Check-out: <strong><?php echo format_date($chk_check_out); ?></strong></p>
                <p style="margin: 0.25rem 0; font-size: 0.9rem;">Stay Length: <strong><span id="lbl_stay_days"></span> Nights</strong></p>
                <p style="margin: 0.5rem 0 0 0; font-size: 1rem; color: var(--success-color);">Estimated Total: <strong><span id="lbl_total_price"></span></strong></p>
            </div>
            
            <?php if ($current_role === 'Guest'): ?>
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">This reservation will be linked to your personal guest account profile.</p>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label">Assign Guest</label>
                    <select name="guest_id" class="form-select" required>
                        <option value="">Select Guest</option>
                        <?php foreach ($all_guests as $g): ?>
                            <option value="<?php echo $g['id']; ?>">
                                <?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?> (<?php echo htmlspecialchars($g['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                        Guest not in list? <a href="guests.php" style="color: var(--primary-accent); font-weight: 500;">Register new guest profile</a>.
                    </p>
                </div>
            <?php endif; ?>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('bookingModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Reservation</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBookingModal(roomId, roomNumber, price) {
    document.getElementById('book_room_id').value = roomId;
    document.getElementById('lbl_room_number').textContent = roomNumber;
    
    const checkin = new Date("<?php echo $chk_check_in ?? ''; ?>");
    const checkout = new Date("<?php echo $chk_check_out ?? ''; ?>");
    
    // Calculate nights difference
    const diffTime = Math.abs(checkout - checkin);
    let diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    if (isNaN(diffDays) || diffDays <= 0) diffDays = 1;
    
    document.getElementById('lbl_stay_days').textContent = diffDays;
    
    const totalPrice = parseFloat(price) * diffDays;
    document.getElementById('lbl_total_price').textContent = '$' + totalPrice.toFixed(2);
    
    openModal('bookingModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
