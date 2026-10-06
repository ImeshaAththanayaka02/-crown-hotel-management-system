<?php
// guests.php
ob_start();

$page_title = 'Guest Registry';
require_once 'includes/header.php';

// Enforce role access (Admin, Manager, Receptionist)
require_role(['Admin', 'Manager', 'Receptionist']);

$error_msg = '';
$success_msg = '';

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: guests.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // ADD GUEST
    if ($action === 'create') {
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        $passport_id = sanitize($_POST['passport_id']);

        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
            set_flash_message('danger', 'First name, last name, email, and phone are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash_message('danger', 'Please enter a valid email address.');
        } else {
            try {
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT id FROM guests WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    set_flash_message('danger', "Guest email '{$email}' is already registered.");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO guests (first_name, last_name, email, phone, address, passport_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$first_name, $last_name, $email, $phone, $address, $passport_id]);
                    set_flash_message('success', "Guest {$first_name} {$last_name} registered successfully.");
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'Error adding guest: ' . $e->getMessage());
            }
        }
        header("Location: guests.php");
        exit();
    }

    // UPDATE GUEST
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        $passport_id = sanitize($_POST['passport_id']);

        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
            set_flash_message('danger', 'Required fields missing.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash_message('danger', 'Invalid email address.');
        } else {
            try {
                // Check if email is used by another guest
                $stmt = $pdo->prepare("SELECT id FROM guests WHERE email = ? AND id != ?");
                $stmt->execute([$email, $id]);
                if ($stmt->fetch()) {
                    set_flash_message('danger', "Email '{$email}' is already in use by another guest.");
                } else {
                    $stmt = $pdo->prepare("UPDATE guests SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, passport_id = ? WHERE id = ?");
                    $stmt->execute([$first_name, $last_name, $email, $phone, $address, $passport_id, $id]);
                    set_flash_message('success', "Guest profiles updated successfully.");
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'Error updating profile: ' . $e->getMessage());
            }
        }
        header("Location: guests.php");
        exit();
    }

    // DELETE GUEST
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            // Check if guest has active checkins
            $stmt = $pdo->prepare("SELECT id FROM checkins WHERE guest_id = ? AND status = 'Active'");
            $stmt->execute([$id]);
            if ($stmt->fetch()) {
                set_flash_message('danger', 'Cannot delete guest with active hotel stays.');
            } else {
                $stmt = $pdo->prepare("DELETE FROM guests WHERE id = ?");
                $stmt->execute([$id]);
                set_flash_message('success', 'Guest record removed successfully.');
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error deleting guest record (dependent stays exist).');
        }
        header("Location: guests.php");
        exit();
    }
}

// Search parameter
$search = sanitize($_GET['search'] ?? '');

// Pagination config
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Build query
$where_sql = "1=1";
$params = [];
if ($search !== '') {
    $where_sql = "(first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR phone LIKE :search OR passport_id LIKE :search)";
    $params['search'] = "%$search%";
}

// Count total
$count_query = "SELECT COUNT(*) FROM guests WHERE $where_sql";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_rows = $stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Guests
$query = "
    SELECT * FROM guests 
    WHERE $where_sql 
    ORDER BY first_name ASC, last_name ASC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$guests = $stmt->fetchAll();

// Get stay history if requested via AJAX
if (isset($_GET['ajax_history_id'])) {
    $guest_id = (int)$_GET['ajax_history_id'];
    
    // Fetch reservations
    $res_stmt = $pdo->prepare("
        SELECT r.*, rm.room_number 
        FROM reservations r 
        JOIN rooms rm ON r.room_id = rm.id 
        WHERE r.guest_id = ? 
        ORDER BY r.check_in_date DESC
    ");
    $res_stmt->execute([$guest_id]);
    $history = $res_stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode($history);
    exit();
}

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- TOP CONTROL PANEL -->
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <form action="guests.php" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; flex: 1;">
            <input type="text" name="search" placeholder="Search by name, email, passport" class="form-input" style="max-width: 300px;" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Search</button>
            <?php if ($search): ?>
                <a href="guests.php" class="btn btn-secondary" style="background: rgba(255,0,0,0.1); border-color: rgba(255,0,0,0.2);">Clear</a>
            <?php endif; ?>
        </form>

        <button class="btn btn-primary" onclick="openAddGuestModal()"><i class="fas fa-user-plus"></i> Add New Guest</button>
    </div>

    <!-- GUESTS LISTING -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Guest Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Passport ID</th>
                        <th>Address</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($guests) > 0): ?>
                        <?php foreach ($guests as $guest): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($guest['first_name'] . ' ' . $guest['last_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($guest['email']); ?></td>
                                <td><?php echo htmlspecialchars($guest['phone']); ?></td>
                                <td><?php echo htmlspecialchars($guest['passport_id'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($guest['address'] ?? 'N/A'); ?></td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem; align-items: center;">
                                    <button class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" onclick="viewGuestHistory(<?php echo $guest['id']; ?>, '<?php echo htmlspecialchars($guest['first_name'] . ' ' . $guest['last_name']); ?>')">
                                        <i class="fas fa-history"></i> History
                                    </button>
                                    <button class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; background: rgba(56, 189, 248, 0.1);" onclick="openEditGuestModal(<?php echo htmlspecialchars(json_encode($guest)); ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form action="guests.php" method="POST" onsubmit="return confirm('Are you sure you want to delete guest registry for <?php echo htmlspecialchars($guest['first_name'] . ' ' . $guest['last_name']); ?>?')" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $guest['id']; ?>">
                                        <button class="btn btn-danger" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" type="submit">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-secondary);">No guests found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="guests.php?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="btn <?php echo $page === $i ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
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

<!-- ADD GUEST MODAL -->
<div class="modal" id="addGuestModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add New Guest</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('addGuestModal')">&times;</button>
        </div>
        
        <form action="guests.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="create">
            
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-input" placeholder="e.g. John" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-input" placeholder="e.g. Doe" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-input" placeholder="john@example.com" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-input" placeholder="+1-555-0150" required>
            </div>

            <div class="form-group">
                <label class="form-label">Passport ID / ID Number</label>
                <input type="text" name="passport_id" class="form-input" placeholder="A12345678">
            </div>
            
            <div class="form-group">
                <label class="form-label">Home Address</label>
                <textarea name="address" class="form-textarea" rows="3" placeholder="123 Luxury Dr, Miami, FL"></textarea>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addGuestModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Guest</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT GUEST MODAL -->
<div class="modal" id="editGuestModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Guest Profile</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('editGuestModal')">&times;</button>
        </div>
        
        <form action="guests.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" id="edit_first_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-input" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" id="edit_email" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" id="edit_phone" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Passport ID / ID Number</label>
                <input type="text" name="passport_id" id="edit_passport_id" class="form-input">
            </div>
            
            <div class="form-group">
                <label class="form-label">Home Address</label>
                <textarea name="address" id="edit_address" class="form-textarea" rows="3"></textarea>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editGuestModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- STAY HISTORY MODAL -->
<div class="modal" id="historyModal">
    <div class="modal-content" style="max-width: 800px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Stay History: <span id="history_guest_name" style="color: var(--primary-accent);"></span></h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('historyModal')">&times;</button>
        </div>
        
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Check-in Date</th>
                        <th>Check-out Date</th>
                        <th>Total Cost</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="history_table_body">
                    <!-- Loaded dynamically via JS -->
                </tbody>
            </table>
        </div>
        
        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
            <button class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button>
        </div>
    </div>
</div>

<script>
function openAddGuestModal() {
    openModal('addGuestModal');
}

function openEditGuestModal(guest) {
    document.getElementById('edit_id').value = guest.id;
    document.getElementById('edit_first_name').value = guest.first_name;
    document.getElementById('edit_last_name').value = guest.last_name;
    document.getElementById('edit_email').value = guest.email;
    document.getElementById('edit_phone').value = guest.phone;
    document.getElementById('edit_passport_id').value = guest.passport_id || '';
    document.getElementById('edit_address').value = guest.address || '';
    
    openModal('editGuestModal');
}

function viewGuestHistory(guestId, guestName) {
    document.getElementById('history_guest_name').textContent = guestName;
    const tbody = document.getElementById('history_table_body');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading history records...</td></tr>';
    
    openModal('historyModal');
    
    // Fetch via AJAX
    fetch('guests.php?ajax_history_id=' + guestId)
        .then(response => response.json())
        .then(data => {
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-secondary);">No historical stays found for this guest.</td></tr>';
                return;
            }
            
            data.forEach(item => {
                const tr = document.createElement('tr');
                
                // Format total price
                const formattedPrice = '$' + parseFloat(item.total_price).toFixed(2);
                
                // Format check-in/out dates
                const checkIn = new Date(item.check_in_date).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});
                const checkOut = new Date(item.check_out_date).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});
                
                // Badges
                let badgeClass = 'badge-info';
                if (item.status === 'Confirmed' || item.status === 'Completed') badgeClass = 'badge-success';
                if (item.status === 'Cancelled') badgeClass = 'badge-danger';
                
                tr.innerHTML = `
                    <td><strong>Room ${escapeHtml(item.room_number)}</strong></td>
                    <td>${checkIn}</td>
                    <td>${checkOut}</td>
                    <td><strong>${formattedPrice}</strong></td>
                    <td><span class="badge ${badgeClass}">${item.status}</span></td>
                `;
                tbody.appendChild(tr);
            });
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--danger-color);">Error loading history details.</td></tr>';
        });
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<?php require_once 'includes/footer.php'; ?>
