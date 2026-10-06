<?php
// dashboard.php
ob_start();

$page_title = 'Dashboard Portal';
require_once 'includes/header.php';

// $pdo, $current_role, $current_username are initialized in header.php
$user_id = get_current_user_id();

try {
    if (in_array($current_role, ['Admin', 'Manager', 'Receptionist'])) {
        // =========================================================
        // ADMIN / MANAGER / RECEPTIONIST DASHBOARD DATA
        // =========================================================
        
        // 1. Core Stat Aggregates
        $total_rooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
        $available_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Available'")->fetchColumn();
        $occupied_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Occupied'")->fetchColumn();
        $today_checkins = $pdo->query("SELECT COUNT(*) FROM reservations WHERE DATE(check_in_date) = CURDATE() AND status != 'Cancelled'")->fetchColumn();
        $today_checkouts = $pdo->query("SELECT COUNT(*) FROM reservations WHERE DATE(check_out_date) = CURDATE() AND status != 'Cancelled'")->fetchColumn();
        
        // Monthly Revenue
        $monthly_revenue = $pdo->query("
            SELECT COALESCE(SUM(net_amount), 0) 
            FROM checkouts 
            WHERE MONTH(check_out_time) = MONTH(CURDATE()) AND YEAR(check_out_time) = YEAR(CURDATE())
        ")->fetchColumn();

        // 2. Recent Bookings List
        $recent_bookings_stmt = $pdo->query("
            SELECT r.*, g.first_name, g.last_name, rm.room_number 
            FROM reservations r 
            JOIN guests g ON r.guest_id = g.id 
            JOIN rooms rm ON r.room_id = rm.id 
            ORDER BY r.created_at DESC 
            LIMIT 5
        ");
        $recent_bookings = $recent_bookings_stmt->fetchAll();

        // 3. Chart Data: Monthly Revenue (Last 6 Months)
        $revenue_chart_stmt = $pdo->query("
            SELECT DATE_FORMAT(check_out_time, '%b %Y') AS month_name, SUM(net_amount) AS revenue 
            FROM checkouts 
            WHERE check_out_time >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY YEAR(check_out_time), MONTH(check_out_time)
            ORDER BY YEAR(check_out_time), MONTH(check_out_time)
        ");
        $revenue_data = $revenue_chart_stmt->fetchAll();

        // Fallback chart data if empty
        if (empty($revenue_data)) {
            $revenue_data = [
                ['month_name' => date('b Y'), 'revenue' => 0]
            ];
        }

        // 4. Chart Data: Occupancy Rate by Room Type
        $occupancy_chart_stmt = $pdo->query("
            SELECT rt.name AS type_name, COUNT(r.id) AS total_rooms, 
                   SUM(CASE WHEN r.status = 'Occupied' THEN 1 ELSE 0 END) AS occupied_rooms
            FROM room_types rt
            LEFT JOIN rooms r ON rt.id = r.type_id
            GROUP BY rt.id
        ");
        $occupancy_data = $occupancy_chart_stmt->fetchAll();

    } elseif ($current_role === 'Housekeeping') {
        // =========================================================
        // HOUSEKEEPING DASHBOARD DATA
        // =========================================================
        
        // Get housekeeping staff ID
        $staff_stmt = $pdo->prepare("SELECT id FROM staff WHERE user_id = ?");
        $staff_stmt->execute([$user_id]);
        $staff_id = $staff_stmt->fetchColumn();

        // Stat Aggregates
        $dirty_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Dirty'")->fetchColumn();
        $maintenance_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Maintenance'")->fetchColumn();
        
        $my_pending_tasks = 0;
        if ($staff_id) {
            $my_pending_tasks = $pdo->prepare("SELECT COUNT(*) FROM housekeeping WHERE staff_id = ? AND status != 'Clean'");
            $my_pending_tasks->execute([$staff_id]);
            $my_pending_tasks = $my_pending_tasks->fetchColumn();
        }

        // Recent Housekeeping Tasks Assigned
        $tasks_stmt = $pdo->prepare("
            SELECT h.*, r.room_number, s.first_name, s.last_name 
            FROM housekeeping h 
            JOIN rooms r ON h.room_id = r.id 
            LEFT JOIN staff s ON h.staff_id = s.id 
            ORDER BY h.assigned_at DESC 
            LIMIT 5
        ");
        $tasks_stmt->execute();
        $recent_tasks = $tasks_stmt->fetchAll();

    } elseif ($current_role === 'Guest') {
        // =========================================================
        // GUEST PORTAL DASHBOARD DATA
        // =========================================================
        
        // Get guest record ID
        $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
        $guest_stmt->execute([$user_id]);
        $guest_id = $guest_stmt->fetchColumn();

        $my_bookings_count = 0;
        $active_stays_count = 0;
        $total_spent = 0.00;
        $my_bookings = [];

        if ($guest_id) {
            // Count total bookings
            $my_bookings_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE guest_id = ?");
            $my_bookings_count_stmt->execute([$guest_id]);
            $my_bookings_count = $my_bookings_count_stmt->fetchColumn();

            // Active stays
            $active_stays_stmt = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE guest_id = ? AND status = 'Active'");
            $active_stays_stmt->execute([$guest_id]);
            $active_stays_count = $active_stays_stmt->fetchColumn();

            // Total spent
            $total_spent_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(co.net_amount), 0) 
                FROM checkouts co 
                JOIN checkins ci ON co.checkin_id = ci.id 
                WHERE ci.guest_id = ?
            ");
            $total_spent_stmt->execute([$guest_id]);
            $total_spent = $total_spent_stmt->fetchColumn();

            // Bookings List
            $bookings_stmt = $pdo->prepare("
                SELECT r.*, rm.room_number 
                FROM reservations r 
                JOIN rooms rm ON r.room_id = rm.id 
                WHERE r.guest_id = ? 
                ORDER BY r.check_in_date DESC 
                LIMIT 5
            ");
            $bookings_stmt->execute([$guest_id]);
            $my_bookings = $bookings_stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    die("Error fetching dashboard statistics: " . $e->getMessage());
}
?>

<div class="dashboard-wrapper" style="display: flex; flex-direction: column; gap: 2rem;">

    <!-- CONDITIONAL RENDER BY ROLE -->
    <?php if (in_array($current_role, ['Admin', 'Manager', 'Receptionist'])): ?>
        
        <!-- STATS GRID -->
        <div class="stats-grid">
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Total Rooms</span>
                    <span class="stat-value"><?php echo $total_rooms; ?></span>
                </div>
                <div class="stat-icon"><i class="fas fa-hotel"></i></div>
            </div>
            
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Available</span>
                    <span class="stat-value"><?php echo $available_rooms; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--success-color);"><i class="fas fa-check-circle"></i></div>
            </div>
            
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Occupied</span>
                    <span class="stat-value"><?php echo $occupied_rooms; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--warning-color);"><i class="fas fa-door-closed"></i></div>
            </div>

            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Check-ins Today</span>
                    <span class="stat-value"><?php echo $today_checkins; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--info-color);"><i class="fas fa-sign-in-alt"></i></div>
            </div>

            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Check-outs Today</span>
                    <span class="stat-value"><?php echo $today_checkouts; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--danger-color);"><i class="fas fa-sign-out-alt"></i></div>
            </div>

            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Revenue (Month)</span>
                    <span class="stat-value"><?php echo format_currency($monthly_revenue); ?></span>
                </div>
                <div class="stat-icon" style="color: var(--success-color);"><i class="fas fa-hand-holding-usd"></i></div>
            </div>
        </div>

        <!-- CHARTS SECTION -->
        <div class="charts-grid">
            <div class="glass-card">
                <h3 style="margin-bottom: 1rem;"><i class="fas fa-chart-area" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Monthly Revenue Trend</h3>
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
            
            <div class="glass-card">
                <h3 style="margin-bottom: 1rem;"><i class="fas fa-chart-pie" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Occupancy Rate</h3>
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="occupancyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- RECENT BOOKINGS TABLE -->
        <div class="glass-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h3><i class="fas fa-history" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Recent Reservations</h3>
                <a href="reservations.php" class="btn btn-secondary" style="padding: 0.4rem 1rem; font-size: 0.8rem;">View All</a>
            </div>
            
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th>Room #</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Total Price</th>
                            <th>Status</th>
                            <th>Booked Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_bookings) > 0): ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></strong></td>
                                    <td>Room <?php echo htmlspecialchars($booking['room_number']); ?></td>
                                    <td><?php echo format_date($booking['check_in_date']); ?></td>
                                    <td><?php echo format_date($booking['check_out_date']); ?></td>
                                    <td><?php echo format_currency($booking['total_price']); ?></td>
                                    <td>
                                        <?php 
                                        $badge_class = 'badge-info';
                                        if ($booking['status'] === 'Confirmed') $badge_class = 'badge-success';
                                        if ($booking['status'] === 'Cancelled') $badge_class = 'badge-danger';
                                        if ($booking['status'] === 'Completed') $badge_class = 'badge-success';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $booking['status']; ?></span>
                                    </td>
                                    <td><?php echo format_datetime($booking['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-secondary);">No recent bookings found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($current_role === 'Housekeeping'): ?>
        
        <!-- HOUSEKEEPING STATS GRID -->
        <div class="stats-grid">
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Dirty Rooms</span>
                    <span class="stat-value"><?php echo $dirty_rooms; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--danger-color);"><i class="fas fa-spray-can"></i></div>
            </div>
            
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Maintenance Rooms</span>
                    <span class="stat-value"><?php echo $maintenance_rooms; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--warning-color);"><i class="fas fa-tools"></i></div>
            </div>
            
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">My Pending Tasks</span>
                    <span class="stat-value"><?php echo $my_pending_tasks; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--primary-accent);"><i class="fas fa-tasks"></i></div>
            </div>
        </div>

        <!-- RECENT HOUSEKEEPING TASKS -->
        <div class="glass-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h3><i class="fas fa-broom" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Active Cleaning Assignments</h3>
                <a href="housekeeping.php" class="btn btn-secondary" style="padding: 0.4rem 1rem; font-size: 0.8rem;">Manage Housekeeping</a>
            </div>
            
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Room #</th>
                            <th>Assigned Staff</th>
                            <th>Clean Status</th>
                            <th>Assigned At</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_tasks) > 0): ?>
                            <?php foreach ($recent_tasks as $task): ?>
                                <tr>
                                    <td><strong>Room <?php echo htmlspecialchars($task['room_number']); ?></strong></td>
                                    <td><?php echo $task['first_name'] ? htmlspecialchars($task['first_name'] . ' ' . $task['last_name']) : '<span style="color:var(--text-secondary)">Unassigned</span>'; ?></td>
                                    <td>
                                        <?php 
                                        $badge_class = 'badge-danger';
                                        if ($task['status'] === 'Clean') $badge_class = 'badge-success';
                                        if ($task['status'] === 'Cleaning') $badge_class = 'badge-warning';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $task['status']; ?></span>
                                    </td>
                                    <td><?php echo format_datetime($task['assigned_at']); ?></td>
                                    <td><?php echo htmlspecialchars($task['remarks'] ?? 'No remarks'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-secondary);">No assignments listed.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($current_role === 'Guest'): ?>
        
        <!-- GUEST STATS GRID -->
        <div class="stats-grid">
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">My Bookings</span>
                    <span class="stat-value"><?php echo $my_bookings_count; ?></span>
                </div>
                <div class="stat-icon"><i class="fas fa-bookmark"></i></div>
            </div>
            
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Active Stays</span>
                    <span class="stat-value"><?php echo $active_stays_count; ?></span>
                </div>
                <div class="stat-icon" style="color: var(--success-color);"><i class="fas fa-bed"></i></div>
            </div>
            
            <div class="glass-card stat-card">
                <div class="stat-details">
                    <span class="stat-title">Total Spent</span>
                    <span class="stat-value"><?php echo format_currency($total_spent); ?></span>
                </div>
                <div class="stat-icon" style="color: var(--success-color);"><i class="fas fa-credit-card"></i></div>
            </div>
        </div>

        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
            <a href="rooms.php" class="btn btn-primary" style="flex: 1; padding: 1.25rem; font-size: 1rem; border-radius: 12px; background: var(--warning-color); color: #1a1200;">
                <i class="fas fa-calendar-plus" style="margin-right: 0.5rem;"></i> Book A New Room Stay
            </a>
            <a href="menu.php" class="btn btn-secondary" style="flex: 1; padding: 1.25rem; font-size: 1rem; border-radius: 12px;">
                <i class="fas fa-hamburger" style="margin-right: 0.5rem;"></i> Order Room Service F&B
            </a>
        </div>

        <!-- MY BOOKINGS -->
        <div class="glass-card">
            <h3><i class="fas fa-suitcase-rolling" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> My Stay History</h3>
            <div class="table-responsive" style="margin-top: 1rem;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Room #</th>
                            <th>Check-in Date</th>
                            <th>Check-out Date</th>
                            <th>Total Cost</th>
                            <th>Booking Status</th>
                            <th>Reserved Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($my_bookings) > 0): ?>
                            <?php foreach ($my_bookings as $booking): ?>
                                <tr>
                                    <td><strong>Room <?php echo htmlspecialchars($booking['room_number']); ?></strong></td>
                                    <td><?php echo format_date($booking['check_in_date']); ?></td>
                                    <td><?php echo format_date($booking['check_out_date']); ?></td>
                                    <td><?php echo format_currency($booking['total_price']); ?></td>
                                    <td>
                                        <?php 
                                        $badge_class = 'badge-info';
                                        if ($booking['status'] === 'Confirmed') $badge_class = 'badge-success';
                                        if ($booking['status'] === 'Cancelled') $badge_class = 'badge-danger';
                                        if ($booking['status'] === 'Completed') $badge_class = 'badge-success';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $booking['status']; ?></span>
                                    </td>
                                    <td><?php echo format_datetime($booking['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-secondary);">You haven't made any bookings yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- CHART SCRIPT LOGIC (ONLY FOR ADMIN/MANAGER/RECEPTIONIST) -->
<?php if (in_array($current_role, ['Admin', 'Manager', 'Receptionist'])): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Revenue Line Chart
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    
    // PHP variables injection
    const months = <?php echo json_encode(array_column($revenue_data, 'month_name')); ?>;
    const revenues = <?php echo json_encode(array_column($revenue_data, 'revenue')); ?>;
    
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#94a3b8' : '#475569';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(15, 23, 42, 0.05)';

    const revenueChart = new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Revenue ($)',
                data: revenues,
                borderColor: '#38bdf8',
                backgroundColor: 'rgba(56, 189, 248, 0.15)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#38bdf8',
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: textColor,
                        font: { family: 'Poppins' }
                    }
                },
                y: {
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: textColor,
                        font: { family: 'Poppins' }
                    }
                }
            }
        }
    });

    // 2. Room Occupancy Bar Chart
    const occupancyCtx = document.getElementById('occupancyChart').getContext('2d');
    
    const types = <?php echo json_encode(array_column($occupancy_data, 'type_name')); ?>;
    const totalRooms = <?php echo json_encode(array_column($occupancy_data, 'total_rooms')); ?>;
    const occupiedRooms = <?php echo json_encode(array_column($occupancy_data, 'occupied_rooms')); ?>;

    const occupancyChart = new Chart(occupancyCtx, {
        type: 'bar',
        data: {
            labels: types,
            datasets: [
                {
                    label: 'Occupied',
                    data: occupiedRooms,
                    backgroundColor: '#60a5fa',
                    borderRadius: 5
                },
                {
                    label: 'Total',
                    data: totalRooms,
                    backgroundColor: 'rgba(255, 255, 255, 0.1)',
                    borderRadius: 5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    stacked: false,
                    grid: { display: false },
                    ticks: {
                        color: textColor,
                        font: { family: 'Poppins' }
                    }
                },
                y: {
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: textColor,
                        font: { family: 'Poppins' },
                        stepSize: 1
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: textColor,
                        font: { family: 'Poppins', size: 11 }
                    }
                }
            }
        }
    });

    // React to theme toggling in localstorage
    document.getElementById('theme-toggle').addEventListener('click', () => {
        setTimeout(() => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const updatedTextColor = isDark ? '#94a3b8' : '#475569';
            const updatedGridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(15, 23, 42, 0.05)';
            
            // Update line chart colors
            revenueChart.options.scales.x.ticks.color = updatedTextColor;
            revenueChart.options.scales.x.grid.color = updatedGridColor;
            revenueChart.options.scales.y.ticks.color = updatedTextColor;
            revenueChart.options.scales.y.grid.color = updatedGridColor;
            revenueChart.update();

            // Update bar chart colors
            occupancyChart.options.scales.x.ticks.color = updatedTextColor;
            occupancyChart.options.scales.y.ticks.color = updatedTextColor;
            occupancyChart.options.scales.y.grid.color = updatedGridColor;
            occupancyChart.options.plugins.legend.labels.color = updatedTextColor;
            occupancyChart.update();
        }, 100);
    });
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
