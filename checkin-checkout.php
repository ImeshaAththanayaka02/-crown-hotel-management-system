<?php
// checkin-checkout.php
ob_start();

$page_title = 'Front Desk Operations';
require_once 'includes/header.php';

// Enforce role access (Admin, Manager, Receptionist)
require_role(['Admin', 'Manager', 'Receptionist']);

$error_msg = '';
$success_msg = '';

// ---------------------------------------------------------
// POST ACTIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: checkin-checkout.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 1. PROCESS CHECK-IN (From Reservation)
    if ($action === 'checkin_reservation') {
        $reservation_id = (int)$_POST['reservation_id'];

        try {
            // Fetch reservation details
            $res_stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'Confirmed'");
            $res_stmt->execute([$reservation_id]);
            $res = $res_stmt->fetch();

            if (!$res) {
                set_flash_message('danger', 'Invalid or unconfirmed reservation selection.');
            } else {
                $pdo->beginTransaction();

                // Create check-in
                $stmt = $pdo->prepare("INSERT INTO checkins (reservation_id, guest_id, room_id, status) VALUES (?, ?, ?, 'Active')");
                $stmt->execute([$reservation_id, $res['guest_id'], $res['room_id']]);
                $checkin_id = $pdo->lastInsertId();

                // Update reservation status to Completed (since they checked in)
                $up_res = $pdo->prepare("UPDATE reservations SET status = 'Completed' WHERE id = ?");
                $up_res->execute([$reservation_id]);

                // Update room status to Occupied
                $up_room = $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
                $up_room->execute([$res['room_id']]);

                // Create initial invoice record
                $invoice_number = 'INV-' . date('Y') . '-' . str_pad($checkin_id, 4, '0', STR_PAD_LEFT);
                $inv_stmt = $pdo->prepare("INSERT INTO invoices (checkin_id, invoice_number, total_amount, paid_amount, status) VALUES (?, ?, ?, 0.00, 'Unpaid')");
                $inv_stmt->execute([$checkin_id, $invoice_number, $res['total_price']]);

                $pdo->commit();
                set_flash_message('success', "Check-in completed successfully for Room #{$res['room_id']}.");
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('danger', 'Check-in failed: ' . $e->getMessage());
        }
        header("Location: checkin-checkout.php");
        exit();
    }

    // 2. PROCESS DIRECT WALK-IN CHECK-IN
    if ($action === 'checkin_walkin') {
        $guest_id = (int)$_POST['guest_id'];
        $room_id = (int)$_POST['room_id'];
        $check_out_date = sanitize($_POST['check_out_date']);

        if (empty($guest_id) || empty($room_id) || empty($check_out_date)) {
            set_flash_message('danger', 'Please select guest, room, and check-out date.');
        } else {
            try {
                $pdo->beginTransaction();

                // Fetch room price
                $room_stmt = $pdo->prepare("SELECT price, status FROM rooms WHERE id = ?");
                $room_stmt->execute([$room_id]);
                $room = $room_stmt->fetch();

                if ($room['status'] !== 'Available') {
                    set_flash_message('danger', 'Selected room is no longer available.');
                    $pdo->rollBack();
                } else {
                    $days = (strtotime($check_out_date) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);
                    if ($days <= 0) $days = 1;
                    $total_price = $room['price'] * $days;

                    // Create dummy completed reservation for accounting
                    $res_stmt = $pdo->prepare("INSERT INTO reservations (guest_id, room_id, check_in_date, check_out_date, status, total_price) VALUES (?, ?, CURDATE(), ?, 'Completed', ?)");
                    $res_stmt->execute([$guest_id, $room_id, $check_out_date, $total_price]);
                    $res_id = $pdo->lastInsertId();

                    // Create check-in
                    $stmt = $pdo->prepare("INSERT INTO checkins (reservation_id, guest_id, room_id, status) VALUES (?, ?, ?, 'Active')");
                    $stmt->execute([$res_id, $guest_id, $room_id]);
                    $checkin_id = $pdo->lastInsertId();

                    // Update room to Occupied
                    $up_room = $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
                    $up_room->execute([$room_id]);

                    // Create invoice
                    $invoice_number = 'INV-' . date('Y') . '-' . str_pad($checkin_id, 4, '0', STR_PAD_LEFT);
                    $inv_stmt = $pdo->prepare("INSERT INTO invoices (checkin_id, invoice_number, total_amount, paid_amount, status) VALUES (?, ?, ?, 0.00, 'Unpaid')");
                    $inv_stmt->execute([$checkin_id, $invoice_number, $total_price]);

                    $pdo->commit();
                    set_flash_message('success', "Walk-in check-in registered successfully!");
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                set_flash_message('danger', 'Walk-in check-in failed: ' . $e->getMessage());
            }
        }
        header("Location: checkin-checkout.php");
        exit();
    }

    // 3. PROCESS CHECK-OUT & BILL CONSOLIDATION
    if ($action === 'checkout') {
        $checkin_id = (int)$_POST['checkin_id'];
        $discount = (float)($_POST['discount'] ?? 0.00);
        $payment_method = sanitize($_POST['payment_method'] ?? 'Cash');

        try {
            // Fetch check-in detail
            $ci_stmt = $pdo->prepare("
                SELECT ci.*, rm.price AS room_price, rm.id AS room_id, r.check_in_date, r.check_out_date 
                FROM checkins ci 
                JOIN rooms rm ON ci.room_id = rm.id 
                JOIN reservations r ON ci.reservation_id = r.id 
                WHERE ci.id = ? AND ci.status = 'Active'
            ");
            $ci_stmt->execute([$checkin_id]);
            $ci = $ci_stmt->fetch();

            if (!$ci) {
                set_flash_message('danger', 'Check-in record not found or already completed.');
            } else {
                $pdo->beginTransaction();

                // Calculate room nights base cost
                $check_in_time = strtotime($ci['check_in_date']);
                $today = strtotime(date('Y-m-d'));
                $nights = ($today - $check_in_time) / (60 * 60 * 24);
                if ($nights <= 0) $nights = 1; // Minimum 1 night billing
                $room_total = $ci['room_price'] * $nights;

                // Fetch food orders cost
                $food_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_price), 0) FROM food_orders WHERE checkin_id = ? AND status = 'Delivered'");
                $food_stmt->execute([$checkin_id]);
                $food_total = $food_stmt->fetchColumn();

                $subtotal = $room_total + $food_total;
                $tax = $subtotal * 0.10; // 10% tax rate
                $net_total = $subtotal + $tax - $discount;

                // Create check-out record
                $co_stmt = $pdo->prepare("INSERT INTO checkouts (checkin_id, total_amount, discount, tax, net_amount) VALUES (?, ?, ?, ?, ?)");
                $co_stmt->execute([$checkin_id, $subtotal, $discount, $tax, $net_total]);

                // Update check-in record
                $up_ci = $pdo->prepare("UPDATE checkins SET status = 'Completed', actual_check_out_time = CURRENT_TIMESTAMP WHERE id = ?");
                $up_ci->execute([$checkin_id]);

                // Update room to Dirty status
                $up_room = $pdo->prepare("UPDATE rooms SET status = 'Dirty' WHERE id = ?");
                $up_room->execute([$ci['room_id']]);

                // Create housekeeping entry automatically for cleaning crew
                $hk_stmt = $pdo->prepare("INSERT INTO housekeeping (room_id, remarks) VALUES (?, 'Guest checked out. Clean room and restock toiletries.')");
                $hk_stmt->execute([$ci['room_id']]);

                // Update Invoice
                $inv_stmt = $pdo->prepare("SELECT id FROM invoices WHERE checkin_id = ?");
                $inv_stmt->execute([$checkin_id]);
                $invoice_id = $inv_stmt->fetchColumn();

                if ($invoice_id) {
                    $up_inv = $pdo->prepare("UPDATE invoices SET total_amount = ?, paid_amount = ?, status = 'Paid' WHERE id = ?");
                    $up_inv->execute([$net_total, $net_total, $invoice_id]);

                    // Add payment transaction log
                    $pay_stmt = $pdo->prepare("INSERT INTO payments (invoice_id, payment_method, amount, transaction_id) VALUES (?, ?, ?, ?)");
                    $txn_id = 'TXN-' . strtoupper(bin2hex(random_bytes(5)));
                    $pay_stmt->execute([$invoice_id, $payment_method, $net_total, $txn_id]);
                }

                $pdo->commit();
                set_flash_message('success', "Check-out completed for Room #{$ci['room_id']}. Invoice paid via {$payment_method}.");
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('danger', 'Check-out process failed: ' . $e->getMessage());
        }
        header("Location: checkin-checkout.php");
        exit();
    }
}

// ---------------------------------------------------------
// FETCH RENDER DATA
// ---------------------------------------------------------

// Fetch active checkins
$active_checkins = $pdo->query("
    SELECT ci.*, g.first_name, g.last_name, g.phone, rm.room_number, rt.name AS room_type, r.check_in_date, r.check_out_date, rm.price 
    FROM checkins ci
    JOIN guests g ON ci.guest_id = g.id
    JOIN rooms rm ON ci.room_id = rm.id
    JOIN room_types rt ON rm.type_id = rt.id
    JOIN reservations r ON ci.reservation_id = r.id
    WHERE ci.status = 'Active'
    ORDER BY ci.check_in_time DESC
")->fetchAll();

// Fetch confirmed reservations for check-in dropdown
$confirmed_reservations = $pdo->query("
    SELECT r.*, g.first_name, g.last_name, rm.room_number, rt.name AS room_type 
    FROM reservations r
    JOIN guests g ON r.guest_id = g.id
    JOIN rooms rm ON r.room_id = rm.id
    JOIN room_types rt ON rm.type_id = rt.id
    WHERE r.status = 'Confirmed'
      AND r.check_in_date <= CURDATE()
      AND r.id NOT IN (SELECT reservation_id FROM checkins WHERE reservation_id IS NOT NULL)
    ORDER BY r.check_in_date ASC
")->fetchAll();

// Fetch guests and available rooms for walk-in form
$guests = $pdo->query("SELECT id, first_name, last_name, email FROM guests ORDER BY first_name ASC")->fetchAll();
$available_rooms = $pdo->query("
    SELECT r.*, rt.name AS type_name 
    FROM rooms r
    JOIN room_types rt ON r.type_id = rt.id
    WHERE r.status = 'Available'
    ORDER BY r.room_number ASC
")->fetchAll();

// ---------------------------------------------------------
// RECEPTIONIST TIMELINE CALENDAR LOGIC
// ---------------------------------------------------------
$timeline_dates = [];
for ($i = -4; $i <= 4; $i++) {
    $timeline_dates[] = date('Y-m-d', strtotime("$i days"));
}
$start_timeline = $timeline_dates[0];
$end_timeline = end($timeline_dates);

// Fetch all rooms for timeline Y-axis
$all_rooms_timeline = $pdo->query("
    SELECT r.id, r.room_number, r.status, rt.name AS type_name 
    FROM rooms r
    JOIN room_types rt ON r.type_id = rt.id
    ORDER BY r.room_number ASC
")->fetchAll();

// Fetch bookings/checkins overlapping timeline date window
$timeline_bookings_stmt = $pdo->prepare("
    SELECT r.id, r.room_id, r.check_in_date, r.check_out_date, r.status, g.first_name, g.last_name
    FROM reservations r
    JOIN guests g ON r.guest_id = g.id
    WHERE r.status IN ('Confirmed', 'Pending', 'Completed')
      AND NOT (r.check_out_date <= ? OR r.check_in_date >= ?)
");
$timeline_bookings_stmt->execute([$start_timeline, $end_timeline]);
$timeline_bookings = $timeline_bookings_stmt->fetchAll();

// Group bookings by room_id
$room_bookings = [];
foreach ($timeline_bookings as $b) {
    $room_bookings[$b['room_id']][] = $b;
}

// Helper function to calculate CSS Grid Column positions
function get_timeline_grid_span($check_in, $check_out, $timeline_dates) {
    $start_date_time = strtotime($check_in);
    $end_date_time = strtotime($check_out);
    
    $timeline_start_time = strtotime($timeline_dates[0]);
    $timeline_end_time = strtotime(end($timeline_dates));
    
    if ($end_date_time <= $timeline_start_time || $start_date_time >= $timeline_end_time) {
        return null;
    }
    
    $start_idx = 0;
    if ($start_date_time > $timeline_start_time) {
        $idx = array_search($check_in, $timeline_dates);
        if ($idx !== false) $start_idx = $idx;
    }
    
    $end_idx = count($timeline_dates);
    if ($end_date_time < $timeline_end_time) {
        $idx = array_search($check_out, $timeline_dates);
        if ($idx !== false) $end_idx = $idx;
    }
    
    if ($start_idx >= $end_idx) {
        $end_idx = $start_idx + 1;
    }
    
    return [
        'start' => $start_idx + 1,
        'end' => $end_idx + 1
    ];
}

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 2rem;">

    <!-- VISUAL OCCUPANCY TIMELINE CALENDAR -->
    <div class="glass-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3><i class="fas fa-calendar-alt" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Visual Front Desk Booking Timeline</h3>
            <span style="font-size: 0.8rem; padding: 0.25rem 0.75rem; border-radius: 50px; background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.2); color: var(--primary-accent);">9-Day Rolling Window</span>
        </div>
        
        <div style="overflow-x: auto;">
            <div style="min-width: 900px;">
                <!-- Timeline Headers Grid -->
                <div style="display: grid; grid-template-columns: 120px repeat(9, 1fr); font-size: 0.8rem; font-weight: 600; text-align: center; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.75rem; color: var(--text-secondary);">
                    <div style="text-align: left; padding-left: 0.5rem;">Room Details</div>
                    <?php foreach ($timeline_dates as $date): ?>
                        <?php 
                        $is_today = ($date === date('Y-m-d'));
                        $day_label = date('D', strtotime($date));
                        $date_label = date('d M', strtotime($date));
                        ?>
                        <div style="<?php echo $is_today ? 'color: var(--warning-color); font-weight: 700;' : ''; ?>">
                            <?php echo $day_label; ?><br>
                            <span style="font-size: 0.75rem; opacity: 0.85;"><?php echo $date_label; ?></span>
                            <?php if ($is_today): ?>
                                <br><span style="font-size: 0.65rem; background: rgba(251,191,36,0.15); padding: 1px 4px; border-radius: 3px;">TODAY</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Timeline Room Rows -->
                <div style="display: flex; flex-direction: column;">
                    <?php foreach ($all_rooms_timeline as $room): ?>
                        <div style="display: grid; grid-template-columns: 120px repeat(9, 1fr); align-items: center; border-bottom: 1px solid var(--glass-border); min-height: 52px; position: relative;">
                            <!-- Room Column -->
                            <div style="padding: 0.25rem 0.5rem; text-align: left;">
                                <strong style="font-size: 0.9rem;">Room <?php echo htmlspecialchars($room['room_number']); ?></strong><br>
                                <span style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo htmlspecialchars($room['type_name']); ?></span>
                            </div>
                            
                            <!-- Timeline Grid Slots Wrapper -->
                            <div style="grid-column: 2 / 11; display: grid; grid-template-columns: repeat(9, 1fr); position: relative; height: 100%; align-items: center; gap: 4px;">
                                <!-- Background gridlines -->
                                <?php for ($i = 0; $i < 9; $i++): ?>
                                    <div style="grid-column: <?php echo $i+1; ?>; border-right: 1px dashed rgba(255, 255, 255, 0.05); height: 100%; pointer-events: none;"></div>
                                <?php endfor; ?>
                                
                                <!-- Booking bars -->
                                <?php 
                                $bookings_in_room = $room_bookings[$room['id']] ?? [];
                                foreach ($bookings_in_room as $b) {
                                    $span = get_timeline_grid_span($b['check_in_date'], $b['check_out_date'], $timeline_dates);
                                    if ($span) {
                                        $badge_color = 'rgba(56, 189, 248, 0.2)';
                                        $text_color = 'var(--primary-accent)';
                                        $border_color = 'rgba(56, 189, 248, 0.4)';
                                        
                                        if ($b['status'] === 'Completed') {
                                            $badge_color = 'rgba(52, 211, 153, 0.15)';
                                            $text_color = 'var(--success-color)';
                                            $border_color = 'rgba(52, 211, 153, 0.3)';
                                        } elseif ($b['status'] === 'Pending') {
                                            $badge_color = 'rgba(251, 191, 36, 0.15)';
                                            $text_color = 'var(--warning-color)';
                                            $border_color = 'rgba(251, 191, 36, 0.3)';
                                        }
                                        
                                        $tooltip = "Guest: " . htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) . " | Dates: " . format_date($b['check_in_date']) . " to " . format_date($b['check_out_date']) . " (" . $b['status'] . ")";
                                        
                                        echo '
                                        <div style="
                                            grid-column: ' . $span['start'] . ' / ' . $span['end'] . ';
                                            background: ' . $badge_color . ';
                                            color: ' . $text_color . ';
                                            border: 1px solid ' . $border_color . ';
                                            border-radius: 6px;
                                            padding: 4px 8px;
                                            font-size: 0.72rem;
                                            font-weight: 500;
                                            text-align: center;
                                            white-space: nowrap;
                                            overflow: hidden;
                                            text-overflow: ellipsis;
                                            z-index: 5;
                                            cursor: help;
                                            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                                        " title="' . $tooltip . '">
                                            ' . htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) . '
                                        </div>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- CHECK-IN OPERATIONS CONTAINER -->
    <div class="grid-2">
        
        <!-- 1. CHECK-IN FROM RESERVATION -->
        <div class="glass-card">
            <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-clipboard-check" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Check-In (Reservation)</h3>
            
            <form action="checkin-checkout.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="checkin_reservation">
                
                <div class="form-group">
                    <label class="form-label">Select Confirmed Reservation</label>
                    <select name="reservation_id" class="form-select" required>
                        <option value="">Choose reservation...</option>
                        <?php foreach ($confirmed_reservations as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?> - Room <?php echo htmlspecialchars($r['room_number']); ?> (<?php echo htmlspecialchars($r['room_type']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;" <?php echo count($confirmed_reservations) === 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-key"></i> Check-in Guest
                </button>
                <?php if (count($confirmed_reservations) === 0): ?>
                    <p style="font-size: 0.75rem; color: var(--text-secondary); text-align: center; margin-top: 0.5rem;">No bookings scheduled for check-in today.</p>
                <?php endif; ?>
            </form>
        </div>

        <!-- 2. DIRECT WALK-IN CHECK-IN -->
        <div class="glass-card">
            <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-walking" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Direct Walk-in Check-in</h3>
            
            <form action="checkin-checkout.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="checkin_walkin">
                
                <div class="form-group">
                    <label class="form-label">Select Guest Profile</label>
                    <select name="guest_id" class="form-select" required>
                        <option value="">Select guest...</option>
                        <?php foreach ($guests as $g): ?>
                            <option value="<?php echo $g['id']; ?>">
                                <?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?> (<?php echo htmlspecialchars($g['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Available Rooms</label>
                        <select name="room_id" class="form-select" required>
                            <option value="">Select Room...</option>
                            <?php foreach ($available_rooms as $rm): ?>
                                <option value="<?php echo $rm['id']; ?>">
                                    Room <?php echo htmlspecialchars($rm['room_number']); ?> (<?php echo htmlspecialchars($rm['type_name']); ?> - $<?php echo $rm['price']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Check-out Date</label>
                        <input type="date" name="check_out_date" class="form-input" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;" <?php echo count($available_rooms) === 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-sign-in-alt"></i> Fast Direct Check-in
                </button>
            </form>
        </div>
    </div>

    <!-- 3. ACTIVE OCCUPANCY DIRECTORY (FOR CHECK-OUTS) -->
    <div class="glass-card">
        <h3><i class="fas fa-door-open" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Active Occupied Rooms</h3>
        <div class="table-responsive" style="margin-top: 1rem;">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th>Checked-in At</th>
                        <th>Scheduled Departure</th>
                        <th>Room Price</th>
                        <th style="text-align: right;">Checkout Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($active_checkins) > 0): ?>
                        <?php foreach ($active_checkins as $ci): ?>
                            <tr>
                                <td><strong>Room <?php echo htmlspecialchars($ci['room_number']); ?></strong><br><span style="font-size:0.75rem; color:var(--text-secondary);"><?php echo htmlspecialchars($ci['room_type']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($ci['first_name'] . ' ' . $ci['last_name']); ?></strong><br><span style="font-size:0.75rem; color:var(--text-secondary);"><?php echo htmlspecialchars($ci['phone']); ?></span></td>
                                <td><?php echo format_datetime($ci['check_in_time']); ?></td>
                                <td><?php echo format_date($ci['check_out_date']); ?></td>
                                <td><strong><?php echo format_currency($ci['price']); ?></strong></td>
                                <td style="text-align: right;">
                                    <button class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;" onclick="loadCheckoutDetails(<?php echo htmlspecialchars(json_encode($ci)); ?>)">
                                        <i class="fas fa-sign-out-alt"></i> Check Out
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-secondary);">No rooms currently occupied.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ==========================================
      CHECK-OUT BILL CONSOLIDATION MODAL
     ========================================== -->
<div class="modal" id="checkoutModal">
    <div class="modal-content" style="max-width: 650px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Consolidate Checkout Invoice</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('checkoutModal')">&times;</button>
        </div>
        
        <form action="checkin-checkout.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" name="checkin_id" id="co_checkin_id">
            
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <h4 style="margin: 0 0 0.75rem 0; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.5rem; color: var(--primary-accent);">
                    Billing Summary - Room <span id="lbl_co_room"></span>
                </h4>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                    <span>Guest Name:</span>
                    <strong id="lbl_co_guest"></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                    <span>Check-in Date:</span>
                    <span id="lbl_co_checkin"></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                    <span>Nights Logged:</span>
                    <strong id="lbl_co_nights"></strong>
                </div>
                
                <div style="border-top: 1px dashed var(--glass-border); margin: 0.75rem 0; padding-top: 0.75rem;"></div>

                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.95rem;">
                    <span>Room Accommodation Total:</span>
                    <strong id="lbl_co_room_total"></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.95rem;">
                    <span>F&B / Room Service Orders:</span>
                    <strong id="lbl_co_food_total"></strong>
                </div>
                
                <div style="border-top: 1px dashed var(--glass-border); margin: 0.75rem 0; padding-top: 0.75rem;"></div>

                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.95rem;">
                    <span>Subtotal:</span>
                    <strong id="lbl_co_subtotal"></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.95rem;">
                    <span>Taxes & Service (10%):</span>
                    <strong id="lbl_co_tax"></strong>
                </div>
            </div>

            <!-- Discount & Payments Inputs -->
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Apply Discount ($)</label>
                    <input type="number" step="0.01" name="discount" id="co_discount" value="0.00" class="form-input" oninput="recalculateNetTotal()">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="Cash">Cash Payment</option>
                        <option value="Card">Credit/Debit Card</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding: 1rem; background: rgba(52, 211, 153, 0.1); border-radius: 8px;">
                <span style="font-size: 1.1rem; font-weight: 600;">Net Amount Due:</span>
                <span style="font-size: 1.5rem; font-weight: 700; color: var(--success-color);" id="lbl_co_net"></span>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('checkoutModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-cash-register"></i> Complete Checkout</button>
            </div>
        </form>
    </div>
</div>

<script>
let checkinBaseRoomPrice = 0;
let checkinNights = 0;
let checkinFoodOrders = 0;

function loadCheckoutDetails(ci) {
    document.getElementById('co_checkin_id').value = ci.id;
    document.getElementById('lbl_co_room').textContent = ci.room_number;
    document.getElementById('lbl_co_guest').textContent = ci.first_name + ' ' + ci.last_name;
    
    // Parse Dates to compute nights
    const checkinDate = new Date(ci.check_in_date);
    const today = new Date();
    // Zero out times for date-only comparison
    checkinDate.setHours(0,0,0,0);
    today.setHours(0,0,0,0);
    
    const diffTime = Math.abs(today - checkinDate);
    let nights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    if (nights <= 0) nights = 1;
    
    checkinNights = nights;
    checkinBaseRoomPrice = parseFloat(ci.price);
    
    document.getElementById('lbl_co_checkin').textContent = checkinDate.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});
    document.getElementById('lbl_co_nights').textContent = nights;
    
    const roomTotal = checkinBaseRoomPrice * nights;
    document.getElementById('lbl_co_room_total').textContent = '$' + roomTotal.toFixed(2);
    
    // Load food orders total via AJAX endpoint from api/orders.php
    document.getElementById('lbl_co_food_total').textContent = 'Loading...';
    
    fetch('api/orders.php?checkin_id=' + ci.id)
        .then(response => response.json())
        .then(data => {
            checkinFoodOrders = parseFloat(data.total_spent) || 0;
            document.getElementById('lbl_co_food_total').textContent = '$' + checkinFoodOrders.toFixed(2);
            
            // Recalculate totals
            recalculateNetTotal();
        })
        .catch(err => {
            checkinFoodOrders = 0;
            document.getElementById('lbl_co_food_total').textContent = '$0.00';
            recalculateNetTotal();
        });

    openModal('checkoutModal');
}

function recalculateNetTotal() {
    const roomTotal = checkinBaseRoomPrice * checkinNights;
    const subtotal = roomTotal + checkinFoodOrders;
    const tax = subtotal * 0.10;
    
    const discountInput = document.getElementById('co_discount');
    let discount = parseFloat(discountInput.value) || 0;
    if (discount < 0) discount = 0;
    
    const netTotal = Math.max(0, subtotal + tax - discount);
    
    document.getElementById('lbl_co_subtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('lbl_co_tax').textContent = '$' + tax.toFixed(2);
    document.getElementById('lbl_co_net').textContent = '$' + netTotal.toFixed(2);
}
</script>

<?php require_once 'includes/footer.php'; ?>
