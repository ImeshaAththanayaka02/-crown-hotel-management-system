<?php
// test_email.php
// Utility script to preview and test the automated booking confirmation email.

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/email_helper.php';

$action = $_GET['action'] ?? 'preview';

// Find the latest reservation in the database to use for testing
try {
    $stmt = $pdo->query("
        SELECT r.id 
        FROM reservations r 
        ORDER BY r.id DESC 
        LIMIT 1
    ");
    $reservation_id = $stmt->fetchColumn();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// If no reservation exists, guide the user to create one
if (!$reservation_id) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Crown Hotel Email Utility</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f6f8fa; color: #333; padding: 40px; text-align: center; }
            .card { max-width: 500px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 4px solid #c5a880; }
            h2 { color: #121212; margin-top: 0; }
            a { display: inline-block; background: #c5a880; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; margin-top: 15px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>No Reservations Found</h2>
            <p>We couldn't find any room reservations in the database to use for testing.</p>
            <p>Please log into the application, book a room first, and then refresh this page to test the automated email.</p>
            <a href="rooms.php">Book a Room</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

if ($action === 'preview') {
    // Generate the HTML preview using the database query to display in-browser
    $stmt = $pdo->prepare("
        SELECT 
            r.id AS reservation_id,
            r.check_in_date,
            r.check_out_date,
            r.total_price,
            r.status AS reservation_status,
            g.first_name,
            g.last_name,
            g.email AS guest_email,
            rm.room_number,
            rt.name AS room_type_name
        FROM reservations r
        JOIN guests g ON r.guest_id = g.id
        JOIN rooms rm ON r.room_id = rm.id
        JOIN room_types rt ON rm.type_id = rt.id
        WHERE r.id = ?
    ");
    $stmt->execute([$reservation_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        die("Error: Could not retrieve reservation details for Reservation ID " . $reservation_id);
    }

    $checkin_formatted = date('D, d M Y', strtotime($booking['check_in_date']));
    $checkout_formatted = date('D, d M Y', strtotime($booking['check_out_date']));
    $price_formatted = '$' . number_format($booking['total_price'], 2);
    $rsv_code = 'RSV-' . str_pad($booking['reservation_id'], 5, '0', STR_PAD_LEFT);

    ?>
    <div style="background: #2b2b2b; color: #fff; padding: 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center; border-bottom: 2px solid #c5a880; font-size: 14px; box-sizing: border-box;">
        <strong>Crown Hotel Email Utility</strong> | 
        Active Reservation: <strong><?php echo $rsv_code; ?></strong> |
        <span style="background: #c5a880; color: #121212; padding: 3px 8px; border-radius: 4px; font-weight: bold; margin: 0 10px;">In-Browser Preview</span> |
        <a href="test_email.php?action=send" style="color: #fff; text-decoration: none; margin: 0 10px; font-weight: 500;">Send Test Email (via SMTP)</a>
    </div>
    
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Confirmation Preview</title>
    </head>
    <body style="margin: 0; padding: 0; background-color: #f6f8fa; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f6f8fa; padding: 40px 0;">
            <tr>
                <td align="center">
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                        <!-- HEADER -->
                        <tr>
                            <td align="center" style="background-color: #121212; padding: 35px 30px; border-bottom: 3px solid #c5a880;">
                                <h1 style="color: #c5a880; font-size: 24px; font-weight: 300; letter-spacing: 5px; margin: 0; text-transform: uppercase;">Crown Hotel</h1>
                                <p style="color: #888888; font-size: 11px; letter-spacing: 2px; margin: 5px 0 0 0; text-transform: uppercase;">Luxury & comfort redefined</p>
                            </td>
                        </tr>
                        
                        <!-- BODY CONTENT -->
                        <tr>
                            <td style="padding: 40px 30px;">
                                <h2 style="font-size: 20px; font-weight: 600; color: #121212; margin-top: 0; margin-bottom: 15px;">Booking Confirmed!</h2>
                                <p style="font-size: 15px; color: #555555; line-height: 1.6; margin: 0 0 25px 0;">
                                    Dear <?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name'], ENT_QUOTES, 'UTF-8'); ?>,
                                </p>
                                <p style="font-size: 15px; color: #555555; line-height: 1.6; margin: 0 0 25px 0;">
                                    Thank you for choosing Crown Hotel. We are delighted to confirm your room reservation. Below are your reservation details:
                                </p>
                                
                                <!-- TABLE -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #eef2f5; border-radius: 8px; overflow: hidden;">
                                    <thead>
                                        <tr style="background-color: #f8fafc; border-bottom: 2px solid #eef2f5;">
                                            <th colspan="2" style="color: #121212; font-size: 13px; font-weight: 600; text-align: left; padding: 12px 16px; text-transform: uppercase; letter-spacing: 0.5px;">Reservation Summary</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 14px 16px; font-size: 14px; font-weight: 600; color: #555555; border-bottom: 1px solid #eef2f5; width: 40%;">Booking Reference</td>
                                            <td style="padding: 14px 16px; font-size: 14px; font-weight: bold; color: #121212; border-bottom: 1px solid #eef2f5;"><?php echo $rsv_code; ?></td>
                                        </tr>
                                        <tr style="background-color: #fafbfc;">
                                            <td style="padding: 14px 16px; font-size: 14px; font-weight: 600; color: #555555; border-bottom: 1px solid #eef2f5;">Room Number</td>
                                            <td style="padding: 14px 16px; font-size: 14px; color: #121212; border-bottom: 1px solid #eef2f5;"><?php echo htmlspecialchars($booking['room_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 14px 16px; font-size: 14px; font-weight: 600; color: #555555; border-bottom: 1px solid #eef2f5;">Room Category</td>
                                            <td style="padding: 14px 16px; font-size: 14px; color: #121212; border-bottom: 1px solid #eef2f5;"><?php echo htmlspecialchars($booking['room_type_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                        <tr style="background-color: #fafbfc;">
                                            <td style="padding: 14px 16px; font-size: 14px; font-weight: 600; color: #555555; border-bottom: 1px solid #eef2f5;">Check-in Date</td>
                                            <td style="padding: 14px 16px; font-size: 14px; color: #121212; border-bottom: 1px solid #eef2f5;"><?php echo $checkin_formatted; ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 14px 16px; font-size: 14px; font-weight: 600; color: #555555; border-bottom: 1px solid #eef2f5;">Check-out Date</td>
                                            <td style="padding: 14px 16px; font-size: 14px; color: #121212; border-bottom: 1px solid #eef2f5;"><?php echo $checkout_formatted; ?></td>
                                        </tr>
                                        <tr style="background-color: #fafbfc;">
                                            <td style="padding: 16px 16px; font-size: 14px; font-weight: 600; color: #555555;">Total Amount</td>
                                            <td style="padding: 16px 16px; font-size: 18px; font-weight: bold; color: #c5a880;"><?php echo $price_formatted; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                                
                                <!-- CTA -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                    <tr>
                                        <td align="center" style="padding: 10px 0 20px 0;">
                                            <a href="http://localhost/hottelmanagemantSystem/dashboard.php" target="_blank" style="display: inline-block; background-color: #c5a880; color: #ffffff; text-decoration: none; padding: 14px 30px; font-weight: bold; border-radius: 6px; font-size: 13px; letter-spacing: 1px; text-transform: uppercase;">View Dashboard</a>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style="font-size: 14px; color: #777777; line-height: 1.6; margin: 20px 0 0 0; text-align: center;">
                                    If you have any questions or need to modify your stay, please reply directly to this email or contact support at support@crownhotel.com.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- FOOTER -->
                        <tr>
                            <td style="background-color: #f8fafc; padding: 25px 30px; text-align: center; border-top: 1px solid #eef2f5;">
                                <p style="font-size: 12px; color: #888888; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">Crown Hotel & Spa</p>
                                <p style="font-size: 11px; color: #aaaaaa; margin: 0; line-height: 1.4;">123 Luxury Boulevard, Paradise Bay<br>&copy; <?php echo date('Y'); ?> Crown Hotel. All rights reserved.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    <?php
} elseif ($action === 'send') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Crown Hotel Email Utility - Send Test</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f6f8fa; color: #333; padding: 0; margin: 0; }
            .container { max-width: 600px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 4px solid #c5a880; }
            h2 { color: #121212; margin-top: 0; }
            .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; }
            .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            pre { background: #f8f9fa; padding: 12px; border: 1px solid #eef2f5; border-radius: 4px; overflow-x: auto; font-size: 13px; color: #c0392b; line-height: 1.5; }
            .btn { display: inline-block; background: #121212; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div style="background: #2b2b2b; color: #fff; padding: 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center; border-bottom: 2px solid #c5a880; font-size: 14px;">
            <strong>Crown Hotel Email Utility</strong> | 
            Active Reservation ID: <strong><?php echo $reservation_id; ?></strong> |
            <a href="test_email.php?action=preview" style="color: #fff; text-decoration: none; margin: 0 10px; font-weight: 500;">In-Browser Preview</a> |
            <span style="background: #c5a880; color: #121212; padding: 3px 8px; border-radius: 4px; font-weight: bold; margin: 0 10px;">Send Test Email (via SMTP)</span>
        </div>
        
        <div class="container">
            <h2>SMTP Send Test</h2>
            <p>Attempting to call <code>send_booking_confirmation(<?php echo $reservation_id; ?>)</code> to send email to guest...</p>
            
            <?php
            $result = send_booking_confirmation($reservation_id);
            
            if ($result) {
                echo "<div class='alert alert-success'><strong>Success!</strong> The email was successfully dispatched using your SMTP configuration. Please check the recipient guest's inbox.</div>";
            } else {
                echo "<div class='alert alert-danger'>";
                echo "<strong>Delivery Failed!</strong> Email transmission failed. This is expected if the SMTP credentials in <code>config/mail.php</code> are still placeholders.<br><br>";
                echo "<strong>Error Captured:</strong> The error was successfully caught and recorded in <code>logs/mail.log</code>.";
                echo "</div>";
                
                // Read and output the last line of logs/mail.log
                $log_file = __DIR__ . '/logs/mail.log';
                if (file_exists($log_file)) {
                    $logs = file($log_file);
                    $last_log = end($logs);
                    echo "<h4>Latest Entry in logs/mail.log:</h4>";
                    echo "<pre>" . htmlspecialchars($last_log) . "</pre>";
                } else {
                    echo "<p>No log file found at <code>logs/mail.log</code>. Please check folder write permissions.</p>";
                }
            }
            ?>
            <div style="margin-top: 20px; text-align: center;">
                <a href="test_email.php?action=preview" class="btn">Back to HTML Preview</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>
