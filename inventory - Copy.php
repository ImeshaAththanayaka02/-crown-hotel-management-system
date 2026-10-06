<?php
// inventory.php
ob_start();

$page_title = 'Inventory Logistics';
require_once 'includes/header.php';

// Enforce role access (Admin, Manager, Housekeeping)
require_role(['Admin', 'Manager', 'Housekeeping']);

$error_msg = '';
$success_msg = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: inventory.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 1. ADD ITEM
    if ($action === 'create') {
        $item_name = sanitize($_POST['item_name']);
        $quantity = (int)$_POST['quantity'];
        $unit = sanitize($_POST['unit']);
        $min_threshold = (int)$_POST['min_threshold'];

        if (empty($item_name) || $quantity < 0 || empty($unit) || $min_threshold < 0) {
            set_flash_message('danger', 'All fields are required and must be valid.');
        } else {
            try {
                // Check duplicate
                $chk = $pdo->prepare("SELECT id FROM inventory WHERE item_name = ?");
                $chk->execute([$item_name]);
                if ($chk->fetch()) {
                    set_flash_message('danger', "Item '{$item_name}' already exists in inventory.");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO inventory (item_name, quantity, unit, min_threshold) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$item_name, $quantity, $unit, $min_threshold]);
                    set_flash_message('success', "Item {$item_name} added to inventory.");
                }
            } catch (PDOException $e) {
                set_flash_message('danger', 'Error adding item: ' . $e->getMessage());
            }
        }
        header("Location: inventory.php");
        exit();
    }

    // 2. UPDATE ITEM
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $item_name = sanitize($_POST['item_name']);
        $quantity = (int)$_POST['quantity'];
        $unit = sanitize($_POST['unit']);
        $min_threshold = (int)$_POST['min_threshold'];

        try {
            $stmt = $pdo->prepare("UPDATE inventory SET item_name = ?, quantity = ?, unit = ?, min_threshold = ? WHERE id = ?");
            $stmt->execute([$item_name, $quantity, $unit, $min_threshold, $id]);
            set_flash_message('success', "Item {$item_name} updated successfully.");
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error updating item: ' . $e->getMessage());
        }
        header("Location: inventory.php");
        exit();
    }

    // 3. DELETE ITEM
    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('success', 'Item removed from inventory.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error removing item.');
        }
        header("Location: inventory.php");
        exit();
    }
}

// Fetch all inventory items
$items = $pdo->query("SELECT *, (quantity <= min_threshold) AS is_low FROM inventory ORDER BY item_name ASC")->fetchAll();

// Count low stock items for dashboard alerts
$low_stock_count = 0;
foreach ($items as $it) {
    if ($it['is_low']) $low_stock_count++;
}

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- TOP CONTROL PANEL -->
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3>Inventory Stock Ledger</h3>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0;">Monitor and track linens, amenities, toiletries, and cleaning supplies</p>
        </div>

        <div style="display: flex; gap: 1rem;">
            <?php if ($low_stock_count > 0): ?>
                <div class="badge badge-danger" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; padding: 0.5rem 1rem; animation: pulse 1.5s infinite;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $low_stock_count; ?> Low Stock Alerts
                </div>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="openAddItemModal()"><i class="fas fa-plus"></i> Add Supply Item</button>
        </div>
    </div>

    <!-- SUPPLIES DIRECTORY -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Supply Item Name</th>
                        <th>Available Stock</th>
                        <th>Unit</th>
                        <th>Safety Threshold</th>
                        <th>Alert Status</th>
                        <th>Last Modified</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                <td>
                                    <span style="<?php echo $item['is_low'] ? 'color: var(--danger-color); font-weight: 700;' : ''; ?>">
                                        <?php echo $item['quantity']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td><?php echo $item['min_threshold']; ?></td>
                                <td>
                                    <?php if ($item['is_low']): ?>
                                        <span class="badge badge-danger"><i class="fas fa-arrow-down"></i> Low Stock Alert</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Stock Level OK</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo format_datetime($item['updated_at']); ?></td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                    <button class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" onclick="openEditItemModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                        Restock / Edit
                                    </button>
                                    <form action="inventory.php" method="POST" onsubmit="return confirm('Permanently delete item from stock records?')" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button class="btn btn-danger" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" type="submit">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-secondary);">No items registered in stock ledger.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ==========================================
      MODALS
     ========================================== -->

<!-- ADD ITEM MODAL -->
<div class="modal" id="addItemModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add Supply Item</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('addItemModal')">&times;</button>
        </div>
        
        <form action="inventory.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="create">
            
            <div class="form-group">
                <label class="form-label">Supply Item Name</label>
                <input type="text" name="item_name" placeholder="e.g. Bath Towels (Small)" class="form-input" required>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label">Initial Quantity</label>
                    <input type="number" name="quantity" placeholder="e.g. 50" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <select name="unit" class="form-select" required>
                        <option value="Pieces">Pieces</option>
                        <option value="Bottles">Bottles</option>
                        <option value="Packets">Packets</option>
                        <option value="Kits">Kits</option>
                        <option value="Liters">Liters</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Min Threshold</label>
                    <input type="number" name="min_threshold" placeholder="e.g. 15" class="form-input" required>
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addItemModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ITEM MODAL -->
<div class="modal" id="editItemModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Restock & Edit Item</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('editItemModal')">&times;</button>
        </div>
        
        <form action="inventory.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_item_id">
            
            <div class="form-group">
                <label class="form-label">Supply Item Name</label>
                <input type="text" name="item_name" id="edit_item_name" class="form-input" required>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" id="edit_quantity" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <select name="unit" id="edit_unit" class="form-select" required>
                        <option value="Pieces">Pieces</option>
                        <option value="Bottles">Bottles</option>
                        <option value="Packets">Packets</option>
                        <option value="Kits">Kits</option>
                        <option value="Liters">Liters</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Min Threshold</label>
                    <input type="number" name="min_threshold" id="edit_min_threshold" class="form-input" required>
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editItemModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddItemModal() {
    openModal('addItemModal');
}

function openEditItemModal(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_quantity').value = item.quantity;
    document.getElementById('edit_unit').value = item.unit;
    document.getElementById('edit_min_threshold').value = item.min_threshold;
    
    openModal('editItemModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
