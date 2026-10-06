<?php
// reports.php
ob_start();

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Enforce admin/manager access
require_login();
if (!has_role(['Admin', 'Manager'])) {
    set_flash_message('danger', 'Unauthorized access.');
    header("Location: dashboard.php");
    exit();
}

// ---------------------------------------------------------
// CSV EXPORT LOGIC (Runs before HTML headers)
// ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $report_type = sanitize($_GET['type'] ?? 'revenue');
    $timeframe = sanitize($_GET['timeframe'] ?? 'monthly');

    // Date range
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');

    if ($timeframe === 'daily') {
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
    } elseif ($timeframe === 'weekly') {
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $end_date = date('Y-m-d');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chms_' . $report_type . '_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');

    if ($report_type === 'revenue') {
        fputcsv($output, ['Folio Invoice #', 'Guest Name', 'Room #', 'Checkout Date', 'Base Total ($)', 'Discount ($)', 'Tax ($)', 'Net Revenue ($)']);
        
        $stmt = $pdo->prepare("
            SELECT co.*, ci.room_id, rm.room_number, g.first_name, g.last_name, inv.invoice_number 
            FROM checkouts co 
            JOIN checkins ci ON co.checkin_id = ci.id 
            JOIN guests g ON ci.guest_id = g.id 
            JOIN rooms rm ON ci.room_id = rm.id 
            JOIN invoices inv ON inv.checkin_id = ci.id 
            WHERE DATE(co.check_out_time) BETWEEN ? AND ? 
            ORDER BY co.check_out_time DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['invoice_number'],
                $row['first_name'] . ' ' . $row['last_name'],
                $row['room_number'],
                $row['check_out_time'],
                $row['total_amount'],
                $row['discount'],
                $row['tax'],
                $row['net_amount']
            ]);
        }
    } elseif ($report_type === 'occupancy') {
        fputcsv($output, ['Room Number', 'Room Category', 'Current Status', 'Price/Night ($)']);
        
        $stmt = $pdo->query("
            SELECT r.*, rt.name AS type_name 
            FROM rooms r 
            JOIN room_types rt ON r.type_id = rt.id 
            ORDER BY r.room_number ASC
        ");
        
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['room_number'],
                $row['type_name'],
                $row['status'],
                $row['price']
            ]);
        }
    } elseif ($report_type === 'guest') {
        fputcsv($output, ['Guest Name', 'Email Address', 'Phone Number', 'Passport ID', 'Home Address']);
        
        $stmt = $pdo->query("SELECT * FROM guests ORDER BY first_name ASC");
        
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['first_name'] . ' ' . $row['last_name'],
                $row['email'],
                $row['phone'],
                $row['passport_id'],
                $row['address']
            ]);
        }
    }
    
    fclose($output);
    exit();
}

// ---------------------------------------------------------
// RENDER HTML PAGE
// ---------------------------------------------------------
$page_title = 'Financial & Auditing Reports';
require_once 'includes/header.php';

$report_type = sanitize($_GET['type'] ?? 'revenue');
$timeframe = sanitize($_GET['timeframe'] ?? 'monthly');

// Date range calculation
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');

if ($timeframe === 'daily') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} elseif ($timeframe === 'weekly') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d');
}

// Fetch lists depending on report selected
$report_title = '';
$data_list = [];
$total_revenue_sum = 0.00;
$total_discount_sum = 0.00;

try {
    if ($report_type === 'revenue') {
        $report_title = 'Revenue Ledger Audit';
        
        $stmt = $pdo->prepare("
            SELECT co.*, ci.room_id, rm.room_number, g.first_name, g.last_name, inv.invoice_number 
            FROM checkouts co 
            JOIN checkins ci ON co.checkin_id = ci.id 
            JOIN guests g ON ci.guest_id = g.id 
            JOIN rooms rm ON ci.room_id = rm.id 
            JOIN invoices inv ON inv.checkin_id = ci.id 
            WHERE DATE(co.check_out_time) BETWEEN ? AND ? 
            ORDER BY co.check_out_time DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $data_list = $stmt->fetchAll();

        foreach ($data_list as $row) {
            $total_revenue_sum += $row['net_amount'];
            $total_discount_sum += $row['discount'];
        }
    } elseif ($report_type === 'occupancy') {
        $report_title = 'Occupancy & Room Audit';
        
        $stmt = $pdo->query("
            SELECT r.*, rt.name AS type_name 
            FROM rooms r 
            JOIN room_types rt ON r.type_id = rt.id 
            ORDER BY r.room_number ASC
        ");
        $data_list = $stmt->fetchAll();
    } elseif ($report_type === 'guest') {
        $report_title = 'Registered Guests Audit';
        $data_list = $pdo->query("SELECT * FROM guests ORDER BY first_name ASC")->fetchAll();
    }
} catch (PDOException $e) {
    die("Report generation error: " . $e->getMessage());
}
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- TOP CONTROL PANEL (HIDDEN IN PRINT) -->
    <div class="glass-card no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <form action="reports.php" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; flex: 1;">
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Report Category</label>
                <select name="type" class="form-select" style="min-width: 180px;">
                    <option value="revenue" <?php echo $report_type === 'revenue' ? 'selected' : ''; ?>>Revenue Audits</option>
                    <option value="occupancy" <?php echo $report_type === 'occupancy' ? 'selected' : ''; ?>>Occupancy Audits</option>
                    <option value="guest" <?php echo $report_type === 'guest' ? 'selected' : ''; ?>>Registered Guests</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Timeframe (For Revenue)</label>
                <select name="timeframe" class="form-select" style="min-width: 150px;">
                    <option value="daily" <?php echo $timeframe === 'daily' ? 'selected' : ''; ?>>Today</option>
                    <option value="weekly" <?php echo $timeframe === 'weekly' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="monthly" <?php echo $timeframe === 'monthly' ? 'selected' : ''; ?>>Current Month</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-secondary" style="height: 43px; margin-top: auto;"><i class="fas fa-chart-line"></i> Generate</button>
        </form>

        <div style="display: flex; gap: 0.5rem; margin-top: auto;">
            <a href="reports.php?export=csv&type=<?php echo $report_type; ?>&timeframe=<?php echo $timeframe; ?>" class="btn btn-secondary" style="background: rgba(52,211,153,0.1); border-color: rgba(52,211,153,0.2);">
                <i class="fas fa-file-excel"></i> Export CSV (Excel)
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Report / PDF
            </button>
        </div>
    </div>

    <!-- REPORT LAYOUT SHEET -->
    <div class="glass-card" style="padding: 2.5rem;">
        <!-- Folio Header -->
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--glass-border); padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <div>
                <h2 style="margin: 0; color: var(--primary-accent);"><i class="fas fa-crown"></i> Crown Hotel Reports</h2>
                <span style="font-size: 0.8rem; color: var(--text-secondary);">Audit generation date: <?php echo date('d M Y, h:i A'); ?></span>
            </div>
            <div style="text-align: right;">
                <h3 style="margin: 0; font-weight: 700;"><?php echo $report_title; ?></h3>
                <span style="font-size: 0.85rem; color: var(--text-secondary);">Period: <?php echo format_date($start_date); ?> to <?php echo format_date($end_date); ?></span>
            </div>
        </div>

        <!-- STATS SECTION FOR REVENUE -->
        <?php if ($report_type === 'revenue'): ?>
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="glass-card stat-card" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                    <div class="stat-details">
                        <span class="stat-title">Transactions Checked</span>
                        <span class="stat-value"><?php echo count($data_list); ?> Check-outs</span>
                    </div>
                </div>
                <div class="glass-card stat-card" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                    <div class="stat-details">
                        <span class="stat-title">Discounts Issued</span>
                        <span class="stat-value" style="color: var(--danger-color);"><?php echo format_currency($total_discount_sum); ?></span>
                    </div>
                </div>
                <div class="glass-card stat-card" style="background: rgba(52, 211, 153, 0.05); border: 1px solid var(--success-color);">
                    <div class="stat-details">
                        <span class="stat-title" style="color: var(--success-color);">Total Net Revenue</span>
                        <span class="stat-value" style="color: var(--success-color);"><?php echo format_currency($total_revenue_sum); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- DATA TABLES -->
        <div class="table-responsive">
            <?php if ($report_type === 'revenue'): ?>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Folio Invoice #</th>
                            <th>Guest Name</th>
                            <th>Room #</th>
                            <th>Checkout Date</th>
                            <th>Base Amount</th>
                            <th>Discount</th>
                            <th>Tax (10%)</th>
                            <th style="text-align: right;">Net Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($data_list) > 0): ?>
                            <?php foreach ($data_list as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['invoice_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td>Room <?php echo htmlspecialchars($row['room_number']); ?></td>
                                    <td><?php echo format_datetime($row['check_out_time']); ?></td>
                                    <td><?php echo format_currency($row['total_amount']); ?></td>
                                    <td style="color: var(--danger-color);"><?php echo format_currency($row['discount']); ?></td>
                                    <td><?php echo format_currency($row['tax']); ?></td>
                                    <td style="text-align: right; font-weight: 600; color: var(--success-color);"><?php echo format_currency($row['net_amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-secondary);">No checkout records found in date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($report_type === 'occupancy'): ?>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Room Number</th>
                            <th>Room Category</th>
                            <th>Price / Night</th>
                            <th>Roster Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data_list as $row): ?>
                            <tr>
                                <td><strong>Room <?php echo htmlspecialchars($row['room_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['type_name']); ?></td>
                                <td><strong><?php echo format_currency($row['price']); ?></strong></td>
                                <td>
                                    <?php 
                                    $badge = 'badge-success';
                                    if ($row['status'] === 'Occupied') $badge = 'badge-warning';
                                    if ($row['status'] === 'Dirty') $badge = 'badge-danger';
                                    if ($row['status'] === 'Maintenance') $badge = 'badge-info';
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo $row['status']; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($report_type === 'guest'): ?>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Guest Profile Name</th>
                            <th>Email Address</th>
                            <th>Phone Contact</th>
                            <th>Passport ID</th>
                            <th>Home Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($data_list) > 0): ?>
                            <?php foreach ($data_list as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($row['passport_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['address'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-secondary);">No guests registered.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Print Signatures -->
        <div style="display: none; justify-content: space-between; margin-top: 5rem;" class="print-only">
            <div style="border-top: 1px solid black; width: 200px; text-align: center; padding-top: 0.5rem; font-size: 0.85rem;">
                Auditor Signature
            </div>
            <div style="border-top: 1px solid black; width: 200px; text-align: center; padding-top: 0.5rem; font-size: 0.85rem;">
                Manager Approval
            </div>
        </div>
    </div>

</div>

<!-- CSS for Print Overrides inside this page -->
<style>
@media print {
    .print-only {
        display: flex !important;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
