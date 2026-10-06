<?php
// billing.php
ob_start();

$page_title = 'Billing';
require_once 'includes/header.php';

// $pdo, $current_role are initialized in header.php
$user_id = get_current_user_id();

$error_msg = '';
$success_msg = '';

// Get Guest ID if role is Guest
$guest_id = null;
if ($current_role === 'Guest') {
    $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $guest_stmt->execute([$user_id]);
    $guest_id = $guest_stmt->fetchColumn();
}

// ---------------------------------------------------------
// POST ACTIONS (Staff Only)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Admin', 'Manager', 'Receptionist']);

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: billing.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // RECORD PAYMENT
    if ($action === 'pay') {
        $invoice_id = (int)$_POST['invoice_id'];
        $amount = (float)$_POST['amount'];
        $payment_method = sanitize($_POST['payment_method']);
        $transaction_id = sanitize($_POST['transaction_id']);

        if ($amount <= 0 || empty($payment_method)) {
            set_flash_message('danger', 'Invalid payment amount or method.');
        } else {
            try {
                // Fetch invoice
                $inv_stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                $inv_stmt->execute([$invoice_id]);
                $invoice = $inv_stmt->fetch();

                if (!$invoice) {
                    set_flash_message('danger', 'Invoice not found.');
                } else {
                    $outstanding = $invoice['total_amount'] - $invoice['paid_amount'];
                    if ($amount > $outstanding) {
                        set_flash_message('danger', 'Payment amount cannot exceed outstanding balance.');
                    } else {
                        $pdo->beginTransaction();

                        // Add payment record
                        $pay_stmt = $pdo->prepare("INSERT INTO payments (invoice_id, payment_method, amount, transaction_id) VALUES (?, ?, ?, ?)");
                        $pay_stmt->execute([$invoice_id, $payment_method, $amount, $transaction_id ?: NULL]);

                        // Update invoice
                        $new_paid = $invoice['paid_amount'] + $amount;
                        $status = 'Partially Paid';
                        if (abs($new_paid - $invoice['total_amount']) < 0.01) {
                            $status = 'Paid';
                        }
                        
                        $up_inv = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?");
                        $up_inv->execute([$new_paid, $status, $invoice_id]);

                        $pdo->commit();
                        set_flash_message('success', "Payment of " . format_currency($amount) . " logged successfully!");
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                set_flash_message('danger', 'Failed to record payment: ' . $e->getMessage());
            }
        }
        header("Location: billing.php");
        exit();
    }
}

// ---------------------------------------------------------
// QUERY DATA FOR LISTS
// ---------------------------------------------------------

// Search and Filter variables
$search_inv = sanitize($_GET['search_inv'] ?? '');
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
    $where_clauses[] = "ci.guest_id = :guest_id";
    $params['guest_id'] = $guest_id;
}

if ($search_inv !== '') {
    $where_clauses[] = "(i.invoice_number LIKE :search OR g.first_name LIKE :search OR g.last_name LIKE :search)";
    $params['search'] = "%$search_inv%";
}

if ($filter_status !== '') {
    $where_clauses[] = "i.status = :status";
    $params['status'] = $filter_status;
}

$where_sql = implode(" AND ", $where_clauses);

// Count
$count_stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM invoices i 
    JOIN checkins ci ON i.checkin_id = ci.id 
    JOIN guests g ON ci.guest_id = g.id
    WHERE $where_sql
");
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Invoices
$invoices_stmt = $pdo->prepare("
    SELECT i.*, ci.room_id, rm.room_number, g.first_name, g.last_name, g.phone, g.email, g.address, g.passport_id,
           (i.total_amount - i.paid_amount) AS outstanding_balance 
    FROM invoices i 
    JOIN checkins ci ON i.checkin_id = ci.id 
    JOIN guests g ON ci.guest_id = g.id 
    JOIN rooms rm ON ci.room_id = rm.id 
    WHERE $where_sql 
    ORDER BY i.created_at DESC 
    LIMIT $limit OFFSET $offset
");
$invoices_stmt->execute($params);
$invoices = $invoices_stmt->fetchAll();

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- TOP CONTROL PANEL -->
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <form action="billing.php" method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; flex: 1;">
            <input type="text" name="search_inv" placeholder="Invoice # or guest name" class="form-input" style="max-width: 250px;" value="<?php echo htmlspecialchars($search_inv); ?>">
            
            <select name="status" class="form-select" style="max-width: 150px;">
                <option value="">All Statuses</option>
                <option value="Unpaid" <?php echo $filter_status === 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                <option value="Partially Paid" <?php echo $filter_status === 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="Paid" <?php echo $filter_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
            </select>
            
            <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem;"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

    <!-- INVOICES LEDGER -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Invoice Number</th>
                        <th>Guest Name</th>
                        <th>Room #</th>
                        <th>Created At</th>
                        <th>Total Cost</th>
                        <th>Paid</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($invoices) > 0): ?>
                        <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']); ?></td>
                                <td>Room <?php echo htmlspecialchars($inv['room_number']); ?></td>
                                <td><?php echo format_date($inv['created_at']); ?></td>
                                <td><strong><?php echo format_currency($inv['total_amount']); ?></strong></td>
                                <td><span style="color: var(--success-color);"><?php echo format_currency($inv['paid_amount']); ?></span></td>
                                <td>
                                    <span style="<?php echo $inv['outstanding_balance'] > 0 ? 'color: var(--danger-color); font-weight: 600;' : 'color: var(--text-secondary);'; ?>">
                                        <?php echo format_currency($inv['outstanding_balance']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $badge = 'badge-danger';
                                    if ($inv['status'] === 'Paid') $badge = 'badge-success';
                                    if ($inv['status'] === 'Partially Paid') $badge = 'badge-warning';
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo $inv['status']; ?></span>
                                </td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                    <button class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" onclick="viewInvoiceDetails(<?php echo htmlspecialchars(json_encode($inv)); ?>)">
                                        <i class="fas fa-eye"></i> View Invoice
                                    </button>
                                    
                                    <?php if ($current_role !== 'Guest' && $inv['outstanding_balance'] > 0): ?>
                                        <button class="btn btn-primary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: var(--success-color); border-color: var(--success-color);" onclick="openPaymentModal(<?php echo htmlspecialchars(json_encode($inv)); ?>)">
                                            <i class="fas fa-hand-holding-usd"></i> Collect Payment
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--text-secondary);">No invoices found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="billing.php?page=<?php echo $i; ?>&search_inv=<?php echo urlencode($search_inv); ?>&status=<?php echo $filter_status; ?>" class="btn <?php echo $page === $i ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ==========================================
      MODALS
     ========================================== -->

<!-- COLLECT PAYMENT MODAL -->
<div class="modal" id="paymentModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Record Invoice Payment</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        
        <form action="billing.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="invoice_id" id="pay_invoice_id">
            
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <p style="margin: 0.25rem 0;">Invoice Number: <strong id="lbl_pay_inv_num"></strong></p>
                <p style="margin: 0.25rem 0;">Outstanding Balance: <strong style="color: var(--danger-color);" id="lbl_pay_outstanding"></strong></p>
            </div>
            
            <div class="form-group">
                <label class="form-label">Payment Amount ($)</label>
                <input type="number" step="0.01" name="amount" id="pay_amount" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-select" required>
                    <option value="Cash">Cash</option>
                    <option value="Card">Credit/Debit Card</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Transaction Reference ID (Optional)</label>
                <input type="text" name="transaction_id" class="form-input" placeholder="e.g. TXN-1299">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Log Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW INVOICE DETAILED MODAL (PRINT FRIENDLY) -->
<div class="modal" id="invoiceDetailModal">
    <div class="modal-content" style="max-width: 750px;">
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.5rem;">
            <h3>Detailed Folio Sheet</h3>
            <div style="display: flex; gap: 0.5rem;">
                <button class="btn btn-primary" onclick="window.print()" style="padding: 0.4rem 1rem; font-size: 0.8rem; background: var(--info-color); border-color: var(--info-color);">
                    <i class="fas fa-print"></i> Print Folio / PDF
                </button>
                <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('invoiceDetailModal')">&times;</button>
            </div>
        </div>
        
        <!-- PRINT WRAPPER -->
        <div id="invoice_print_area" style="color: var(--text-primary); padding: 1rem;">
            <!-- Brand Section -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
                <div>
                    <h2 style="margin: 0; color: var(--primary-accent);"><i class="fas fa-crown"></i> Crown Luxury Hotel</h2>
                    <p style="margin: 0.25rem 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">
                        100 Luxury Blvd, Beachfront Sector<br>
                        Phone: +1 (555) 0199 | billing@crownhotel.com
                    </p>
                </div>
                <div style="text-align: right;">
                    <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700;">INVOICE FOLIO</h3>
                    <h4 style="margin: 0.25rem 0 0 0; color: var(--primary-accent);" id="lbl_inv_number"></h4>
                    <p style="margin: 0.25rem 0 0 0; font-size: 0.8rem; color: var(--text-secondary);" id="lbl_inv_date"></p>
                </div>
            </div>
            
            <!-- Guest & Bill Details -->
            <div class="grid-2" style="margin-bottom: 2rem; background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); padding: 1rem; border-radius: 8px;">
                <div>
                    <h5 style="margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.75rem; text-transform: uppercase;">BILL TO:</h5>
                    <strong id="lbl_inv_guest_name" style="font-size: 1.05rem;"></strong><br>
                    <span id="lbl_inv_guest_contact" style="font-size: 0.85rem; color: var(--text-secondary);"></span><br>
                    <span id="lbl_inv_guest_address" style="font-size: 0.85rem; color: var(--text-secondary);"></span>
                </div>
                <div style="text-align: right;">
                    <h5 style="margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.75rem; text-transform: uppercase;">STAY DETAILS:</h5>
                    <span>Room: <strong id="lbl_inv_room"></strong></span><br>
                    <span>ID Proof: <span id="lbl_inv_passport"></span></span><br>
                    <span>Payment Status: <span class="badge" id="lbl_inv_status"></span></span>
                </div>
            </div>

            <!-- Folio Breakdown -->
            <table class="custom-table" style="margin-bottom: 2rem; width: 100%;">
                <thead>
                    <tr style="background: rgba(255,255,255,0.03);">
                        <th>Service / Description</th>
                        <th style="text-align: right;">Total Net Charge</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Room Stay Accommodation Charges</strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-secondary);">Room charges calculated based on duration of stay.</span>
                        </td>
                        <td style="text-align: right; font-weight: 600;" id="lbl_inv_accomm"></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>F&B / In-Room Dining Services</strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-secondary);">Delivered room service dining orders.</span>
                        </td>
                        <td style="text-align: right; font-weight: 600;" id="lbl_inv_fb"></td>
                    </tr>
                    <tr style="border-top: 2px solid var(--glass-border);">
                        <td style="text-align: right; font-weight: 600;">Subtotal:</td>
                        <td style="text-align: right; font-weight: 600;" id="lbl_inv_subtotal"></td>
                    </tr>
                    <tr>
                        <td style="text-align: right; color: var(--text-secondary);">Tax & Services (10%):</td>
                        <td style="text-align: right;" id="lbl_inv_tax"></td>
                    </tr>
                    <?php if ($current_role !== 'Guest'): ?>
                    <tr>
                        <td style="text-align: right; color: var(--danger-color);">Discount Applied:</td>
                        <td style="text-align: right; color: var(--danger-color);" id="lbl_inv_discount"></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: rgba(52, 211, 153, 0.05); font-size: 1.1rem;">
                        <td style="text-align: right; font-weight: 700;">Grand Net Total:</td>
                        <td style="text-align: right; font-weight: 700; color: var(--success-color);" id="lbl_inv_grand"></td>
                    </tr>
                    <tr style="font-size: 0.95rem;">
                        <td style="text-align: right; font-weight: 600;">Total Paid:</td>
                        <td style="text-align: right; color: var(--success-color);" id="lbl_inv_paid"></td>
                    </tr>
                    <tr style="font-size: 1rem; border-top: 1px solid var(--glass-border);">
                        <td style="text-align: right; font-weight: 700;">Remaining Balance:</td>
                        <td style="text-align: right; font-weight: 700; color: var(--danger-color);" id="lbl_inv_balance"></td>
                    </tr>
                </tbody>
            </table>

            <div style="text-align: center; margin-top: 3rem; border-top: 1px dashed var(--glass-border); padding-top: 1.5rem; font-size: 0.85rem; color: var(--text-secondary);">
                <p>Thank you for choosing Crown Luxury Hotel. We hope your stay was delightful.</p>
            </div>
        </div>
        
        <div class="no-print" style="display: flex; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--glass-border); padding-top: 1rem;">
            <button class="btn btn-secondary" onclick="closeModal('invoiceDetailModal')">Close</button>
        </div>
    </div>
</div>

<script>
function openPaymentModal(inv) {
    document.getElementById('pay_invoice_id').value = inv.id;
    document.getElementById('lbl_pay_inv_num').textContent = inv.invoice_number;
    document.getElementById('lbl_pay_outstanding').textContent = '$' + parseFloat(inv.outstanding_balance).toFixed(2);
    document.getElementById('pay_amount').value = parseFloat(inv.outstanding_balance).toFixed(2);
    document.getElementById('pay_amount').max = parseFloat(inv.outstanding_balance).toFixed(2);
    
    openModal('paymentModal');
}

function viewInvoiceDetails(inv) {
    document.getElementById('lbl_inv_number').textContent = inv.invoice_number;
    document.getElementById('lbl_inv_date').textContent = new Date(inv.created_at).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'});
    document.getElementById('lbl_inv_guest_name').textContent = inv.first_name + ' ' + inv.last_name;
    document.getElementById('lbl_inv_guest_contact').textContent = 'Phone: ' + inv.phone + ' | Email: ' + inv.email;
    document.getElementById('lbl_inv_guest_address').textContent = inv.address || 'Address: N/A';
    document.getElementById('lbl_inv_room').textContent = 'Room ' + inv.room_number;
    document.getElementById('lbl_inv_passport').textContent = inv.passport_id || 'N/A';
    
    // Status Badge
    const statusLbl = document.getElementById('lbl_inv_status');
    statusLbl.textContent = inv.status;
    statusLbl.className = 'badge ';
    if (inv.status === 'Paid') statusLbl.className += 'badge-success';
    else if (inv.status === 'Partially Paid') statusLbl.className += 'badge-warning';
    else statusLbl.className += 'badge-danger';
    
    // Calculations
    const total = parseFloat(inv.total_amount);
    const paid = parseFloat(inv.paid_amount);
    
    // Fetch individual F&B costs to display detailed folio split
    document.getElementById('lbl_inv_fb').textContent = 'Calculating...';
    document.getElementById('lbl_inv_accomm').textContent = 'Calculating...';
    
    fetch('api/orders.php?checkin_id=' + inv.checkin_id)
        .then(response => response.json())
        .then(data => {
            const fbSpent = parseFloat(data.total_spent) || 0;
            const subtotal = total / 1.1; // Reverse 10% tax for subtotal presentation
            const tax = subtotal * 0.1;
            const accomm = subtotal - fbSpent;
            
            document.getElementById('lbl_inv_fb').textContent = '$' + fbSpent.toFixed(2);
            document.getElementById('lbl_inv_accomm').textContent = '$' + accomm.toFixed(2);
            document.getElementById('lbl_inv_subtotal').textContent = '$' + subtotal.toFixed(2);
            document.getElementById('lbl_inv_tax').textContent = '$' + tax.toFixed(2);
        })
        .catch(err => {
            // Fallback display if API fails
            document.getElementById('lbl_inv_fb').textContent = '$0.00';
            document.getElementById('lbl_inv_accomm').textContent = '$' + total.toFixed(2);
            document.getElementById('lbl_inv_subtotal').textContent = '$' + total.toFixed(2);
            document.getElementById('lbl_inv_tax').textContent = '$0.00';
        });

    document.getElementById('lbl_inv_grand').textContent = '$' + total.toFixed(2);
    document.getElementById('lbl_inv_paid').textContent = '$' + paid.toFixed(2);
    document.getElementById('lbl_inv_balance').textContent = '$' + (total - paid).toFixed(2);
    
    <?php if ($current_role !== 'Guest'): ?>
    // Retrieve discount if logged in checkout records
    document.getElementById('lbl_inv_discount').textContent = '$0.00'; // Default
    <?php endif; ?>

    openModal('invoiceDetailModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
