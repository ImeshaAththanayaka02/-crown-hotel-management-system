<?php
// restaurant.php
ob_start();

$page_title = 'Dining & Restaurant Portal';
require_once 'includes/header.php';

// $pdo, $current_role, $user_id are defined in header.php
$error_msg = '';
$success_msg = '';
$user_id = get_current_user_id();

// Get Guest ID if role is Guest
$guest_id = null;
$active_checkin_id = null;
$active_room_number = '';

if ($current_role === 'Guest') {
    $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    
    $guest_stmt->execute([$user_id]);
    $guest_id = $guest_stmt->fetchColumn();

    if ($guest_id) {
        // Find active checkin for this guest
        $ci_stmt = $pdo->prepare("
            SELECT ci.id, rm.room_number 
            FROM checkins ci 
            JOIN rooms rm ON ci.room_id = rm.id 
            WHERE ci.guest_id = ? AND ci.status = 'Active'
        ");
        $ci_stmt->execute([$guest_id]);
        $active_stay = $ci_stmt->fetch();
        if ($active_stay) {
            $active_checkin_id = $active_stay['id'];
            $active_room_number = $active_stay['room_number'];
        }
    }
}

// ---------------------------------------------------------
// POST ACTIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: restaurant.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 1. ADD FOOD MENU ITEM (Staff Only)
    if ($action === 'create_item') {
        require_role(['Admin', 'Manager']);
        $item_name = sanitize($_POST['item_name']);
        $description = sanitize($_POST['description']);
        $price = (float)$_POST['price'];
        $category = sanitize($_POST['category']);
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        $image_filename = null;

        // Image upload handling
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            $upload = upload_image($_FILES['item_image'], 'uploads/');
            if ($upload['status']) {
                $image_filename = $upload['filename'];
            } else {
                set_flash_message('danger', 'Image upload failed: ' . $upload['message']);
                header("Location: restaurant.php#menu");
                exit();
            }
        }

        if (empty($item_name) || $price <= 0 || empty($category)) {
            set_flash_message('danger', 'All fields are required.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO food_menu (item_name, description, price, category, is_available, image_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$item_name, $description, $price, $category, $is_available, $image_filename]);
                set_flash_message('success', "Menu item '{$item_name}' added.");
            } catch (PDOException $e) {
                set_flash_message('danger', 'Error adding item: ' . $e->getMessage());
            }
        }
        header("Location: restaurant.php#menu");
        exit();
    }

    // 2. UPDATE MENU ITEM (Staff Only)
    if ($action === 'update_item') {
        require_role(['Admin', 'Manager']);
        $id = (int)$_POST['id'];
        $item_name = sanitize($_POST['item_name']);
        $description = sanitize($_POST['description']);
        $price = (float)$_POST['price'];
        $category = sanitize($_POST['category']);
        $is_available = isset($_POST['is_available']) ? 1 : 0;

        try {
            // Check if a new image was uploaded
            if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
                $upload = upload_image($_FILES['item_image'], 'uploads/');
                if ($upload['status']) {
                    // Fetch old image to delete it optionally
                    $old_img_stmt = $pdo->prepare("SELECT image_path FROM food_menu WHERE id = ?");
                    $old_img_stmt->execute([$id]);
                    $old_img = $old_img_stmt->fetchColumn();
                    if ($old_img && file_exists('uploads/' . $old_img)) {
                        @unlink('uploads/' . $old_img);
                    }

                    $stmt = $pdo->prepare("UPDATE food_menu SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, image_path = ? WHERE id = ?");
                    $stmt->execute([$item_name, $description, $price, $category, $is_available, $upload['filename'], $id]);
                } else {
                    set_flash_message('danger', 'Image upload failed: ' . $upload['message']);
                    header("Location: restaurant.php#menu");
                    exit();
                }
            } else {
                $stmt = $pdo->prepare("UPDATE food_menu SET item_name = ?, description = ?, price = ?, category = ?, is_available = ? WHERE id = ?");
                $stmt->execute([$item_name, $description, $price, $category, $is_available, $id]);
            }
            set_flash_message('success', 'Menu item updated.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error updating item: ' . $e->getMessage());
        }
        header("Location: restaurant.php#menu");
        exit();
    }

    // 3. DELETE MENU ITEM (Staff Only)
    if ($action === 'delete_item') {
        require_role(['Admin', 'Manager']);
        $id = (int)$_POST['id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM food_menu WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('success', 'Menu item deleted.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Cannot delete item (already ordered in booking history).');
        }
        header("Location: restaurant.php#menu");
        exit();
    }

    // 4. PLACE ROOM SERVICE ORDER
    if ($action === 'place_order') {
        $item_id  = (int)$_POST['item_id'];
        $quantity = (int)$_POST['quantity'];
        $room_id  = (int)$_POST['room_id'];

        if (empty($room_id) || empty($item_id) || $quantity <= 0) {
            set_flash_message('danger', 'Please select a room and a menu item.');
        } else {
            try {
                // Look up active check-in for the selected room
                $ci_lookup = $pdo->prepare("SELECT id FROM checkins WHERE room_id = ? AND status = 'Active' LIMIT 1");
                $ci_lookup->execute([$room_id]);
                $order_checkin_id = $ci_lookup->fetchColumn();

                if (!$order_checkin_id) {
                    set_flash_message('danger', 'The selected room has no active check-in. Please check-in the guest first.');
                } else {
                    // Fetch price
                    $price_stmt = $pdo->prepare("SELECT price, item_name FROM food_menu WHERE id = ?");
                    $price_stmt->execute([$item_id]);
                    $item = $price_stmt->fetch();

                    if ($item) {
                        $total = $item['price'] * $quantity;
                        $stmt  = $pdo->prepare("INSERT INTO food_orders (checkin_id, item_id, quantity, status, total_price) VALUES (?, ?, ?, 'Pending', ?)");
                        $stmt->execute([$order_checkin_id, $item_id, $quantity, $total]);
                        $order_id = $pdo->lastInsertId();

                        // Send email notification to owner
                        require_once 'includes/email_helper.php';
                        send_order_notification_email($order_id);

                        set_flash_message('success', "Order placed: {$quantity}x {$item['item_name']}!");
                    }
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'Order placement failed: ' . $e->getMessage());
            }
        }
        header("Location: restaurant.php");
        exit();
    }

    // 5. UPDATE ORDER STATUS (Staff Only)
    if ($action === 'update_order_status') {
        require_role(['Admin', 'Manager', 'Receptionist']);
        $order_id = (int)$_POST['id'];
        $new_status = sanitize($_POST['status']);

        try {
            $stmt = $pdo->prepare("UPDATE food_orders SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $order_id]);
            set_flash_message('success', "Order status updated to: {$new_status}");
        } catch (PDOException $e) {
            set_flash_message('danger', 'Failed to update order status.');
        }
        header("Location: restaurant.php#queue");
        exit();
    }
}

// ---------------------------------------------------------
// QUERY DATA FOR LISTS
// ---------------------------------------------------------

// Fetch menu items
$menu_items = $pdo->query("SELECT * FROM food_menu ORDER BY category ASC, item_name ASC")->fetchAll();

// Fetch ALL registered rooms for order form dropdown
$all_rooms = $pdo->query("
    SELECT r.id AS room_id, r.room_number, ci.id AS checkin_id, g.first_name, g.last_name
    FROM rooms r
    LEFT JOIN checkins ci ON ci.room_id = r.id AND ci.status = 'Active'
    LEFT JOIN guests g ON ci.guest_id = g.id
    WHERE r.status != 'Maintenance'
    ORDER BY r.room_number ASC
")->fetchAll();

// Fetch room service order list
if ($current_role === 'Guest') {
    // Guests only see orders for their active stay
    $orders = [];
    if ($active_checkin_id) {
        $orders_stmt = $pdo->prepare("
            SELECT fo.*, fm.item_name, fm.price 
            FROM food_orders fo 
            JOIN food_menu fm ON fo.item_id = fm.id 
            WHERE fo.checkin_id = ? 
            ORDER BY fo.order_time DESC
        ");
        $orders_stmt->execute([$active_checkin_id]);
        $orders = $orders_stmt->fetchAll();
    }
} else {
    // Staff see all active orders
    $orders = $pdo->query("
        SELECT fo.*, fm.item_name, fm.price, rm.room_number, g.first_name, g.last_name 
        FROM food_orders fo 
        JOIN food_menu fm ON fo.item_id = fm.id 
        JOIN checkins ci ON fo.checkin_id = ci.id 
        JOIN rooms rm ON ci.room_id = rm.id 
        JOIN guests g ON ci.guest_id = g.id 
        ORDER BY fo.status ASC, fo.order_time DESC
    ")->fetchAll();
}

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 2rem;">

    <!-- TOP TAB SWITCHER -->
    <div style="display: flex; gap: 1rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.5rem;" class="no-print">
        <a href="#orders" class="btn btn-secondary" onclick="showTab('orders-tab')" id="btn-orders-tab">Room Service Orders</a>
        <a href="#menu" class="btn btn-secondary" onclick="showTab('menu-tab')" id="btn-menu-tab">F&B Menu Directory</a>
    </div>

    <!-- TAB 1: ROOM SERVICE ORDERS -->
    <div id="orders-tab" class="tab-content">
        <div class="grid-3" style="grid-template-columns: 1fr 2fr; gap: 1.5rem;">
            
            <!-- Order Form -->
            <div class="glass-card" style="height: fit-content;">
                <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-pizza-slice" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Order Food</h3>
                
                <?php if (empty($all_rooms)): ?>
                    <p style="color: var(--danger-color); font-weight: 500;">No rooms registered in the system.</p>
                <?php else: ?>
                    <form action="restaurant.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="place_order">

                        <div class="form-group">
                            <label class="form-label">Select Room to Deliver To</label>
                            <select name="room_id" id="room_select" class="form-select" required onchange="updateRoomStatus(this)">
                                <option value="">-- Select Room --</option>
                                <?php foreach ($all_rooms as $r): ?>
                                    <option value="<?php echo $r['room_id']; ?>"
                                        data-has-checkin="<?php echo $r['checkin_id'] ? '1' : '0'; ?>"
                                        data-guest="<?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?>">
                                        Room <?php echo htmlspecialchars($r['room_number']); ?>
                                        <?php if ($r['checkin_id']): ?>
                                            — <?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?> ✓
                                        <?php else: ?>
                                            — (No active check-in)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="room-status-msg" style="margin-top: 0.4rem; font-size: 0.8rem;"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Select Menu Item</label>
                            <select name="item_id" class="form-select" required>
                                <option value="">Select food item...</option>
                                <?php foreach ($menu_items as $item): ?>
                                    <?php if ($item['is_available']): ?>
                                        <option value="<?php echo $item['id']; ?>">
                                            [<?php echo $item['category']; ?>] <?php echo htmlspecialchars($item['item_name']); ?> - $<?php echo $item['price']; ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="item-preview-container" style="margin-bottom: 1.25rem; display: none; background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 0.75rem; border-radius: 8px; align-items: center; gap: 0.75rem;">
                            <!-- Preview populated via Javascript -->
                        </div>

                        <div class="form-group">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-input" min="1" value="1" required>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                            <i class="fas fa-shipping-fast"></i> Place Room Order
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Orders Queue List -->
            <div class="glass-card" id="queue">
                <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-list-alt" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Order Log History</h3>
                
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <?php if ($current_role !== 'Guest'): ?>
                                    <th>Room</th>
                                <?php endif; ?>
                                <th>Item Ordered</th>
                                <th>Qty</th>
                                <th>Cost</th>
                                <th>Status</th>
                                <?php if ($current_role !== 'Guest'): ?>
                                    <th style="text-align: right;">Progress Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($orders) > 0): ?>
                                <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <?php if ($current_role !== 'Guest'): ?>
                                            <td><strong>Room <?php echo htmlspecialchars($o['room_number']); ?></strong></td>
                                        <?php endif; ?>
                                        <td>
                                            <strong><?php echo htmlspecialchars($o['item_name']); ?></strong><br>
                                            <span style="font-size:0.75rem; color:var(--text-secondary);"><?php echo format_datetime($o['order_time']); ?></span>
                                        </td>
                                        <td><?php echo $o['quantity']; ?></td>
                                        <td><strong><?php echo format_currency($o['total_price']); ?></strong></td>
                                        <td>
                                            <?php 
                                            $o_badge = 'badge-danger'; // Pending
                                            if ($o['status'] === 'Preparing') $o_badge = 'badge-warning';
                                            if ($o['status'] === 'Delivered') $o_badge = 'badge-success';
                                            ?>
                                            <span class="badge <?php echo $o_badge; ?>"><?php echo $o['status']; ?></span>
                                        </td>
                                        
                                        <?php if ($current_role !== 'Guest'): ?>
                                            <td style="text-align: right; display:flex; justify-content:flex-end; gap:0.25rem;">
                                                <?php if ($o['status'] === 'Pending'): ?>
                                                    <form action="restaurant.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="action" value="update_order_status">
                                                        <input type="hidden" name="id" value="<?php echo $o['id']; ?>">
                                                        <input type="hidden" name="status" value="Preparing">
                                                        <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">Prepare</button>
                                                    </form>
                                                <?php elseif ($o['status'] === 'Preparing'): ?>
                                                    <form action="restaurant.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="action" value="update_order_status">
                                                        <input type="hidden" name="id" value="<?php echo $o['id']; ?>">
                                                        <input type="hidden" name="status" value="Delivered">
                                                        <button type="submit" class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.7rem; background: var(--success-color); border-color:var(--success-color);">Deliver</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="font-size:0.75rem; color:var(--text-secondary);">Delivered</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $current_role === 'Guest' ? 4 : 6; ?>" style="text-align: center; color: var(--text-secondary);">No food orders placed yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: MENU DIRECTORY -->
    <div id="menu-tab" class="tab-content" style="display: none;">
        <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>F&B Dining Menu</h3>
            <?php if (in_array($current_role, ['Admin', 'Manager'])): ?>
                <button class="btn btn-primary" onclick="openAddMenuModal()"><i class="fas fa-plus"></i> Add Menu Item</button>
            <?php endif; ?>
        </div>

        <div class="glass-card">
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Food Item Name</th>
                            <th>Description</th>
                            <th>Pricing</th>
                            <th>Category</th>
                            <th>Kitchen Status</th>
                            <?php if (in_array($current_role, ['Admin', 'Manager'])): ?>
                                <th style="text-align: right;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menu_items as $item): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <?php if ($item['image_path'] && file_exists('uploads/' . $item['image_path'])): ?>
                                            <img src="uploads/<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border);">
                                        <?php else: ?>
                                            <div style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.05); border-radius: 8px; border: 1px solid var(--glass-border); color: var(--text-secondary);">
                                                <i class="fas fa-utensils"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($item['description'] ?? 'No description provided.'); ?></td>
                                <td><strong><?php echo format_currency($item['price']); ?></strong></td>
                                <td><span class="badge badge-info"><?php echo $item['category']; ?></span></td>
                                <td>
                                    <?php if ($item['is_available']): ?>
                                        <span class="badge badge-success">Available</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Out of Stock</span>
                                    <?php endif; ?>
                                </td>
                                <?php if (in_array($current_role, ['Admin', 'Manager'])): ?>
                                    <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem; align-items: center; height: 50px;">
                                        <button class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" onclick="openEditMenuModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                            Edit
                                        </button>
                                        <form action="restaurant.php" method="POST" onsubmit="return confirm('Delete item from menu registry?')" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                            <button class="btn btn-danger" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" type="submit">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- ==========================================
      MODALS
     ========================================== -->

<!-- ADD MENU ITEM MODAL -->
<div class="modal" id="addMenuModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add Food Menu Item</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('addMenuModal')">&times;</button>
        </div>
        
        <form action="restaurant.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="create_item">
            
            <div class="form-group">
                <label class="form-label">Food Item Name</label>
                <input type="text" name="item_name" placeholder="e.g. Grilled Ribeye Steak" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <input type="text" name="description" placeholder="e.g. Served with truffle mash and black pepper jus" class="form-input">
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select" required>
                        <option value="Appetizer">Appetizer</option>
                        <option value="Main">Main Course</option>
                        <option value="Beverage">Beverage</option>
                        <option value="Dessert">Dessert</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Price ($)</label>
                    <input type="number" step="0.01" name="price" placeholder="24.00" class="form-input" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Food Item Image</label>
                <input type="file" name="item_image" class="form-input" accept="image/*">
            </div>

            <div class="form-group" style="flex-direction: row; gap: 0.5rem; align-items: center; margin-top: 0.5rem;">
                <input type="checkbox" name="is_available" id="is_available_chk" checked style="width: 18px; height: 18px; cursor: pointer;">
                <label for="is_available_chk" style="cursor: pointer;">Item Available In Kitchen</label>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addMenuModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MENU ITEM MODAL -->
<div class="modal" id="editMenuModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Menu Item Details</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('editMenuModal')">&times;</button>
        </div>
        
        <form action="restaurant.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="id" id="edit_food_id">
            
            <div class="form-group">
                <label class="form-label">Food Item Name</label>
                <input type="text" name="item_name" id="edit_food_name" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <input type="text" name="description" id="edit_food_desc" class="form-input">
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" id="edit_food_cat" class="form-select" required>
                        <option value="Appetizer">Appetizer</option>
                        <option value="Main">Main</option>
                        <option value="Beverage">Beverage</option>
                        <option value="Dessert">Dessert</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Price ($)</label>
                    <input type="number" step="0.01" name="price" id="edit_food_price" class="form-input" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Food Item Image (Leave empty to keep current)</label>
                <input type="file" name="item_image" class="form-input" accept="image/*">
            </div>

            <div class="form-group" style="flex-direction: row; gap: 0.5rem; align-items: center; margin-top: 0.5rem;">
                <input type="checkbox" name="is_available" id="edit_food_avail" style="width: 18px; height: 18px; cursor: pointer;">
                <label for="edit_food_avail" style="cursor: pointer;">Item Available In Kitchen</label>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editMenuModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Tabs configuration
function showTab(tabId) {
    const tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(t => t.style.display = 'none');
    
    document.getElementById(tabId).style.display = 'block';

    const buttons = document.querySelectorAll('[id^="btn-"]');
    buttons.forEach(b => {
        b.className = 'btn btn-secondary';
    });

    document.getElementById('btn-' + tabId).className = 'btn btn-primary';
}

document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash;
    if (hash === '#menu') {
        showTab('menu-tab');
    } else {
        showTab('orders-tab');
    }
});

function openAddMenuModal() {
    openModal('addMenuModal');
}

function openEditMenuModal(item) {
    document.getElementById('edit_food_id').value = item.id;
    document.getElementById('edit_food_name').value = item.item_name;
    document.getElementById('edit_food_desc').value = item.description || '';
    document.getElementById('edit_food_cat').value = item.category;
    document.getElementById('edit_food_price').value = parseFloat(item.price).toFixed(2);
    document.getElementById('edit_food_avail').checked = (parseInt(item.is_available) === 1);
    
    openModal('editMenuModal');
}

const menuItems = <?php echo json_encode($menu_items); ?>;
document.addEventListener('DOMContentLoaded', () => {
    const itemSelect = document.querySelector('select[name="item_id"]');
    if (itemSelect) {
        itemSelect.addEventListener('change', function() {
            const itemId = parseInt(this.value);
            const container = document.getElementById('item-preview-container');
            if (!itemId) {
                container.style.display = 'none';
                container.innerHTML = '';
                return;
            }
            const item = menuItems.find(i => parseInt(i.id) === itemId);
            if (item) {
                let imgHtml = '';
                if (item.image_path) {
                    imgHtml = `<img src="uploads/${item.image_path}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border);">`;
                } else {
                    imgHtml = `<div style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.05); border-radius: 8px; border: 1px solid var(--glass-border); color: var(--text-secondary);"><i class="fas fa-utensils"></i></div>`;
                }
                container.innerHTML = `
                    ${imgHtml}
                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                        <strong style="font-size: 0.9rem;">${item.item_name}</strong>
                        <span style="font-size: 0.75rem; color: var(--text-secondary);">${item.description || 'No description provided.'}</span>
                    </div>
                `;
                container.style.display = 'flex';
            } else {
                container.style.display = 'none';
            }
        });
    }
});

function updateRoomStatus(sel) {
    const opt = sel.options[sel.selectedIndex];
    const msg = document.getElementById('room-status-msg');
    if (!opt || !opt.value) { msg.innerHTML = ''; return; }
    const hasCheckin = opt.getAttribute('data-has-checkin');
    if (hasCheckin === '1') {
        const guest = opt.getAttribute('data-guest');
        msg.innerHTML = `<span style="color: var(--success-color);"><i class="fas fa-check-circle"></i> Active check-in: ${guest}</span>`;
    } else {
        msg.innerHTML = `<span style="color: var(--warning-color);"><i class="fas fa-exclamation-triangle"></i> This room has no active check-in. Order cannot be placed.</span>`;
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
