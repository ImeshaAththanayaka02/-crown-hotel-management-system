<?php
// menu.php
ob_start();

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Enforce login
require_login();

$current_role = get_current_role();
$user_id = get_current_user_id();

// Fetch Guest Info & Active Check-in
$guest_id = null;
$active_checkin_id = null;
$active_room_number = '';

if ($current_role === 'Guest') {
    $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $guest_stmt->execute([$user_id]);
    $guest_id = $guest_stmt->fetchColumn();

    if ($guest_id) {
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
} else {
    // For staff, retrieve all occupied rooms to deliver service
    $occupied_rooms = $pdo->query("
        SELECT ci.id, rm.room_number, g.first_name, g.last_name 
        FROM checkins ci 
        JOIN rooms rm ON ci.room_id = rm.id 
        JOIN guests g ON ci.guest_id = g.id 
        WHERE ci.status = 'Active' 
        ORDER BY rm.room_number ASC
    ")->fetchAll();
}

// Handle Order Placement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'Security validation failed.');
        header("Location: menu.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'order_food') {
        $item_id = (int)$_POST['item_id'];
        $quantity = (int)$_POST['quantity'];
        
        $order_checkin_id = null;
        if ($current_role === 'Guest') {
            $order_checkin_id = $active_checkin_id;
        } else {
            $order_checkin_id = (int)$_POST['checkin_id'];
        }

        if (empty($order_checkin_id) || empty($item_id) || $quantity <= 0) {
            set_flash_message('danger', 'Ordering failed. Ensure room is occupied and quantity is valid.');
        } else {
            try {
                // Fetch menu item pricing
                $item_stmt = $pdo->prepare("SELECT price, item_name FROM food_menu WHERE id = ? AND is_available = 1");
                $item_stmt->execute([$item_id]);
                $item = $item_stmt->fetch();

                if (!$item) {
                    set_flash_message('danger', 'Item is currently unavailable.');
                } else {
                    $total = $item['price'] * $quantity;
                    $ins = $pdo->prepare("
                        INSERT INTO food_orders (checkin_id, item_id, quantity, status, total_price) 
                        VALUES (?, ?, ?, 'Pending', ?)
                    ");
                    $ins->execute([$order_checkin_id, $item_id, $quantity, $total]);

                    set_flash_message('success', "Order placed successfully: {$quantity}x {$item['item_name']}!");
                    if ($current_role === 'Guest') {
                        header("Location: restaurant.php"); // Redirect to guest's food log
                    } else {
                        header("Location: restaurant.php#queue"); // Redirect staff to orders queue
                    }
                    exit();
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'Order failed: ' . $e->getMessage());
            }
        }
        header("Location: menu.php");
        exit();
    }
}

// Fetch F&B directory
$menu_items = $pdo->query("SELECT * FROM food_menu ORDER BY category ASC, item_name ASC")->fetchAll();
$csrf_token = generate_csrf_token();

$page_title = 'Dining Menu - Hotel Crown';
require_once 'includes/guest_header.php';
?>

<div style="max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem;">
    <!-- Hero Banner -->
    <div style="position: relative; border-radius: 20px; overflow: hidden; min-height: 280px; display: flex; align-items: flex-end; padding: 3rem; margin-bottom: 3rem; background: linear-gradient(to top, rgba(7,17,31,0.92) 0%, rgba(7,17,31,0.40) 60%, transparent 100%), url('assets/images/food_spread.png') center center / cover no-repeat;">
        <div style="position: relative; z-index: 1;">
            <span style="color: var(--warning-color); font-size: 0.8rem; letter-spacing: 4px; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 0.5rem;">Fine Dining & Room Service</span>
            <h1 style="font-family: 'Cormorant Garamond', serif; font-size: clamp(2rem, 4vw, 3.2rem); font-weight: 600; color: #fff; text-shadow: 0 2px 20px rgba(0,0,0,0.5); line-height: 1.2;">The Crown Culinary Experience</h1>
            <p style="color: rgba(255,255,255,0.7); max-width: 500px; font-size: 0.9rem; margin-top: 0.5rem;">Indulge in award-winning gourmet dishes prepared by our world-class chefs, delivered straight to your suite.</p>
        </div>
    </div>

    <!-- Active Status Banner for Guests -->
    <?php if ($current_role === 'Guest'): ?>
        <?php if ($active_checkin_id): ?>
            <div style="background: rgba(52, 211, 153, 0.08); border: 1px solid rgba(52, 211, 153, 0.25); padding: 1.25rem; border-radius: 12px; margin-bottom: 3rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas fa-check-circle" style="color: var(--success-color); font-size: 1.5rem;"></i>
                    <div>
                        <span style="font-weight: 600; display: block; font-size: 0.95rem;">Active Stay: Room <?php echo $active_room_number; ?></span>
                        <span style="font-size: 0.8rem; color: var(--text-secondary);">You can order room service directly to your suite.</span>
                    </div>
                </div>
                <a href="#menu-list" class="btn btn-primary" style="background: var(--success-color); border-color: var(--success-color); color: #fff; font-size: 0.85rem; padding: 0.5rem 1.25rem;">View Dishes</a>
            </div>
        <?php else: ?>
            <div style="background: rgba(251, 191, 36, 0.08); border: 1px solid rgba(251, 191, 36, 0.25); padding: 1.25rem; border-radius: 12px; margin-bottom: 3rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-info-circle" style="color: var(--warning-color); font-size: 1.5rem;"></i>
                <div>
                    <span style="font-weight: 600; display: block; font-size: 0.95rem;">Room Service Restricted</span>
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">Room service ordering is only available for currently checked-in guests. Browse the menu below, or <a href="rooms.php" style="color: var(--warning-color); font-weight: 500;">book a stay</a> to order.</span>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Display by category -->
    <div id="menu-list">
        <?php 
        $categories =  ['Main' => 'Main Courses', 'Beverage' => 'Beverages', 'Dessert' => 'Desserts'];
        foreach ($categories as $cat_code => $cat_name):
            $cat_items = array_filter($menu_items, function($item) use ($cat_code) {
                return $item['category'] === $cat_code;
            });
            if (empty($cat_items)) continue;

            // ── Per-item keyword → image map ──────────────────────────
            // Keys are lowercase keywords; first match wins.
            // Falls back to category image, then generic food_spread.
            $item_keyword_map = [
                'truffle fries'   => 'assets/images/menu_truffle_fries.png',
                'truffle'         => 'assets/images/menu_truffle_fries.png',
                'fries'           => 'assets/images/menu_truffle_fries.png',
                'rice'            => 'assets/images/Mix-Rice.jpg.',
                'nasi'            => 'assets/images/Nasi-Goreng.jpg',
                'pizza'           => 'assets/images/pizza.jpg',

'milkshake'     => 'assets/images/milkshakes.jpg',
    'chocolate'     => 'assets/images/Hot-Chocolate.jpg',
    'hot'           => 'assets/images/Hot-Chocolate.jpg',
    
            
                'wagyu'           => 'assets/images/menu_wagyu_burger.png',
                'burger'          => 'assets/images/menu_wagyu_burger.png',
                'cappuccino'      => 'assets/images/menu_cappuccino.png',
                'coffee'          => 'assets/images/menu_cappuccino.png',
                'latte'           => 'assets/images/menu_cappuccino.png',
                'espresso'        => 'assets/images/menu_cappuccino.png',
                'orange juice'    => 'assets/images/menu_orange_juice.png',
                'juice'           => 'assets/images/menu_orange_juice.png',
                'watalappan'    => 'assets/images/watalappan.jpeg',
                'ice cream'     => 'assets/images/ice_cream.jpg',
                'fruit salad'   => 'assets/images/fruit _salad.jpg',
                'cheesecake'    => 'assets/images/cheese_cake.jpg',
            ];

            // Category fallback images
            $cat_image_map = [
    'Appetizer' => 'assets/images/food_appetizer.png',
    'Main'      => 'assets/images/food_main.png',
    'Beverage'  => 'assets/images/food_beverage.png',
    'Beverages' => 'assets/images/food_beverage.png', // මේක අනිවාර්යයෙන්ම දාන්න!
    'Bever'     => 'assets/images/food_beverage.png', // Warning එක නිසා කැපිලා තිබ්බොත් ඒත් අහුවෙන්න මේකත් දාන්න!
    'Dessert'   => 'assets/images/food_dessert.png'
];
            
        ?>
        <div style="margin-bottom: 4.5rem;">
            <h2 style="font-family: 'Cormorant Garamond', serif; font-size: 2.2rem; font-weight: 600; margin-bottom: 1.75rem; display: flex; align-items: center; gap: 0.75rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.6rem;">
                <span style="color: var(--warning-color); font-family: sans-serif; font-size: 1.1rem; border: 1px solid var(--warning-color); border-radius: 50%; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                    <?php 
                    if ($cat_code === 'Appetizer') echo '<i class="fas fa-seedling" style="font-size:0.8rem"></i>';
                    elseif ($cat_code === 'Main') echo '<i class="fas fa-drumstick-bite" style="font-size:0.8rem"></i>';
                    elseif ($cat_code === 'Beverage') echo '<i class="fas fa-glass-cheers" style="font-size:0.8rem"></i>';
                    elseif ($cat_code === 'Dessert') echo '<i class="fas fa-cookie-bite" style="font-size:0.8rem"></i>';
                    ?>
                </span>
                <?php echo $cat_name; ?>
            </h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 2rem;">
                <?php foreach ($cat_items as $item): ?>
                    <?php
                    // Resolve per-item image by keyword scanning
                    $item_name_lower = strtolower($item['item_name']);
                    $resolved_img = null;
                    foreach ($item_keyword_map as $keyword => $img_path) {
                        if (str_contains($item_name_lower, $keyword)) {
                            $resolved_img = $img_path;
                            break;
                        }
                    }
                    // Category fallback → generic fallback
                    if (!$resolved_img) {
                        $resolved_img = $cat_image_map[$cat_code] ?? 'assets/images/food_spread.png';
                    }
                    ?>
                    <div class="menu-item-card<?php echo !$item['is_available'] ? ' menu-item-unavailable' : ''; ?>">

                        <!-- Image header with price badge overlay -->
                        <div class="menu-item-img-wrap">
                            <img src="<?php echo htmlspecialchars($resolved_img); ?>"
                                 alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                 class="menu-item-img"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <!-- CSS fallback if image file is missing -->
                            <div class="menu-item-img-fallback" style="display:none;">
                                <i class="fas <?php
                                    if ($cat_code === 'Appetizer')    echo 'fa-seedling';
                                    elseif ($cat_code === 'Main')     echo 'fa-drumstick-bite';
                                    elseif ($cat_code === 'Beverage') echo 'fa-glass-cheers';
                                    else                               echo 'fa-cookie-bite';
                                ?>" style="font-size:2.5rem; color:var(--warning-color); opacity:0.6;"></i>
                            </div>
                            <!-- Price badge floating top-right -->
                            <span class="menu-price-badge">$<?php echo number_format($item['price'], 2); ?></span>
                            <!-- Out of stock darkening ribbon -->
                            <?php if (!$item['is_available']): ?>
                                <div class="menu-oos-ribbon">Unavailable</div>
                            <?php endif; ?>
                        </div>

                        <!-- Card body: name, description, footer -->
                        <div class="menu-item-body">
                            <div>
                                <h3 class="menu-item-title"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                <p class="menu-item-desc"><?php echo htmlspecialchars($item['description'] ?? 'Made with fresh hand-sourced local ingredients.'); ?></p>
                            </div>

                            <div class="menu-item-footer">
                                <?php if ($item['is_available']): ?>
                                    <span class="menu-avail-dot menu-avail-yes">
                                        <i class="fas fa-circle" style="font-size:0.42rem;"></i> Available
                                    </span>
                                <?php else: ?>
                                    <span class="menu-avail-dot menu-avail-no">
                                        <i class="fas fa-circle" style="font-size:0.42rem;"></i> Out of stock
                                    </span>
                                <?php endif; ?>

                                <?php if ($item['is_available']): ?>
                                    <?php if ($current_role === 'Guest'): ?>
                                        <?php if ($active_checkin_id): ?>
                                            <button class="btn menu-order-btn"
                                                onclick="openOrderModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>', <?php echo $item['price']; ?>)">
                                                <i class="fas fa-plus"></i> Order Now
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary" style="padding:0.4rem 1rem;font-size:0.8rem;opacity:0.45;cursor:not-allowed;" disabled title="Requires active check-in">
                                                Order Now
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="btn menu-order-btn"
                                            onclick="openOrderModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>', <?php echo $item['price']; ?>)">
                                            <i class="fas fa-plus"></i> Staff Order
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Order Modal -->
<div class="modal" id="orderModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Place Room Service Order</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('orderModal')">&times;</button>
        </div>
        
        <form action="menu.php" method="POST" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="order_food">
            <input type="hidden" name="item_id" id="order_item_id">

            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <h4 style="margin: 0 0 0.5rem 0; font-family: 'Cormorant Garamond', serif; font-size: 1.25rem; color: var(--warning-color);">Food Item Details</h4>
                <p style="margin: 0.25rem 0; font-size: 0.95rem;">Item: <strong><span id="lbl_item_name"></span></strong></p>
                <p style="margin: 0.25rem 0; font-size: 0.95rem;">Price: <strong>$<span id="lbl_item_price"></span></strong></p>
            </div>

            <?php if ($current_role === 'Guest'): ?>
                <div style="background: rgba(255,255,255,0.03); padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--glass-border); margin-bottom: 1.25rem; font-size: 0.85rem;">
                    Delivering directly to: <strong>Room <?php echo $active_room_number; ?></strong>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label">Deliver To Room</label>
                    <select name="checkin_id" class="form-select" required>
                        <option value="">Select occupied suite...</option>
                        <?php foreach ($occupied_rooms as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                Room <?php echo htmlspecialchars($r['room_number']); ?> (<?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Quantity</label>
                <input type="number" name="quantity" id="order_quantity" class="form-input" min="1" value="1" onchange="recalculateOrderCost()" oninput="recalculateOrderCost()" required>
            </div>

            <div style="background: rgba(56,189,248,0.06); border: 1px solid rgba(56,189,248,0.2); padding: 1rem; border-radius: 8px; margin-top: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.85rem; color: var(--text-secondary);">Estimated Total:</span>
                <strong style="font-size: 1.3rem; color: var(--success-color);">$<span id="lbl_order_total">0.00</span></strong>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('orderModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200;"><i class="fas fa-hamburger"></i> Place Order</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentItemPrice = 0;

    function openOrderModal(itemId, itemName, price) {
        document.getElementById('order_item_id').value = itemId;
        document.getElementById('lbl_item_name').textContent = itemName;
        document.getElementById('lbl_item_price').textContent = parseFloat(price).toFixed(2);
        document.getElementById('order_quantity').value = 1;
        currentItemPrice = parseFloat(price);
        
        recalculateOrderCost();
        openModal('orderModal');
    }

    function recalculateOrderCost() {
        const qty = parseInt(document.getElementById('order_quantity').value) || 1;
        const total = qty * currentItemPrice;
        document.getElementById('lbl_order_total').textContent = total.toFixed(2);
    }
</script>

<?php
require_once 'includes/guest_footer.php';
?>
