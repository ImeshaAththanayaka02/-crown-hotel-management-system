<?php
// housekeeping.php
ob_start();

$page_title = 'Housekeeping Management';
require_once 'includes/header.php';

// Enforce role access (Admin, Manager, Receptionist, Housekeeping)
require_role(['Admin', 'Manager', 'Receptionist', 'Housekeeping']);

$error_msg = '';
$success_msg = '';

// Handle POST operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: housekeeping.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 1. ASSIGN HOUSEKEEPER
    if ($action === 'assign') {
        $hk_id = (int)$_POST['hk_id'];
        $staff_id = (int)$_POST['staff_id'];
        $remarks = sanitize($_POST['remarks']);

        try {
            $stmt = $pdo->prepare("UPDATE housekeeping SET staff_id = ?, status = 'Cleaning', remarks = ? WHERE id = ?");
            $stmt->execute([$staff_id, $remarks, $hk_id]);
            set_flash_message('success', 'Housekeeper assigned successfully.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Assignment failed: ' . $e->getMessage());
        }
        header("Location: housekeeping.php");
        exit();
    }

    // 2. UPDATE CLEANING STATUS
    if ($action === 'update_status') {
        $hk_id = (int)$_POST['id'];
        $status = sanitize($_POST['status']);
        $remarks = sanitize($_POST['remarks']);

        try {
            $pdo->beginTransaction();

            // Fetch room ID from housekeeping task
            $hk_stmt = $pdo->prepare("SELECT room_id FROM housekeeping WHERE id = ?");
            $hk_stmt->execute([$hk_id]);
            $room_id = $hk_stmt->fetchColumn();

            if ($room_id) {
                // Update housekeeping task
                $completed_at = ($status === 'Clean') ? date('Y-m-d H:i:s') : NULL;
                $stmt = $pdo->prepare("UPDATE housekeeping SET status = ?, remarks = ?, completed_at = ? WHERE id = ?");
                $stmt->execute([$status, $remarks, $completed_at, $hk_id]);

                // Sync with rooms status
                if ($status === 'Clean') {
                    // Only mark room 'Available' if it's NOT currently 'Occupied'
                    $room_status_stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                    $room_status_stmt->execute([$room_id]);
                    $curr_room_status = $room_status_stmt->fetchColumn();

                    if ($curr_room_status !== 'Occupied') {
                        $up_room = $pdo->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?");
                        $up_room->execute([$room_id]);
                    }
                } else {
                    // If marked Dirty or Cleaning, set rooms table status to Dirty (unless occupied/maintenance)
                    $room_status_stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                    $room_status_stmt->execute([$room_id]);
                    $curr_room_status = $room_status_stmt->fetchColumn();

                    if ($curr_room_status === 'Available') {
                        $up_room = $pdo->prepare("UPDATE rooms SET status = 'Dirty' WHERE id = ?");
                        $up_room->execute([$room_id]);
                    }
                }
            }

            $pdo->commit();
            set_flash_message('success', "Housekeeping task updated to {$status}.");
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('danger', 'Update failed: ' . $e->getMessage());
        }
        header("Location: housekeeping.php");
        exit();
    }

    // 3. FILE MAINTENANCE REQUEST (Blocks room in rooms table)
    if ($action === 'maintenance') {
        $room_id = (int)$_POST['room_id'];
        $remarks = sanitize($_POST['remarks']);

        try {
            $pdo->beginTransaction();

            // Check if room is occupied
            $room_status_stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
            $room_status_stmt->execute([$room_id]);
            $curr_room_status = $room_status_stmt->fetchColumn();

            if ($curr_room_status === 'Occupied') {
                set_flash_message('danger', 'Cannot put occupied rooms under maintenance.');
                $pdo->rollBack();
            } else {
                // Update room to Maintenance
                $up_room = $pdo->prepare("UPDATE rooms SET status = 'Maintenance' WHERE id = ?");
                $up_room->execute([$room_id]);

                // Log task under housekeeping
                $stmt = $pdo->prepare("INSERT INTO housekeeping (room_id, remarks, status) VALUES (?, ?, 'Dirty')");
                $stmt->execute([$room_id, "MAINTENANCE: " . $remarks]);

                $pdo->commit();
                set_flash_message('success', 'Room placed under maintenance mode.');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('danger', 'Maintenance file failed: ' . $e->getMessage());
        }
        header("Location: housekeeping.php");
        exit();
    }
}

// ---------------------------------------------------------
// QUERY DATA FOR LISTS
// ---------------------------------------------------------

// Fetch housekeeping tasks
$tasks = $pdo->query("
    SELECT h.*, r.room_number, r.status AS room_current_status, s.first_name, s.last_name 
    FROM housekeeping h 
    JOIN rooms r ON h.room_id = r.id 
    LEFT JOIN staff s ON h.staff_id = s.id 
    ORDER BY h.status DESC, h.assigned_at DESC
")->fetchAll();

// Fetch housekeeping staff list for dropdowns (department = Housekeeping)
$hk_staff = $pdo->query("
    SELECT id, first_name, last_name 
    FROM staff 
    WHERE department = 'Housekeeping' 
    ORDER BY first_name ASC
")->fetchAll();

// Fetch all rooms for maintenance request options
$all_rooms = $pdo->query("SELECT id, room_number, status FROM rooms ORDER BY room_number ASC")->fetchAll();

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- CONTROL BOARD -->
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3>Housekeeping Board</h3>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0;">Track, assign, and clear cleaning and maintenance operations</p>
        </div>
        
        <?php if ($current_role !== 'Housekeeping'): ?>
            <button class="btn btn-danger" onclick="openMaintenanceModal()"><i class="fas fa-tools"></i> Log Maintenance</button>
        <?php endif; ?>
    </div>

    <!-- TASKS DIRECTORY -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Room Number</th>
                        <th>Room Status</th>
                        <th>Assigned Cleaner</th>
                        <th>Cleaning Task Status</th>
                        <th>Assigned At</th>
                        <th>Remarks</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($tasks) > 0): ?>
                        <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td><strong>Room <?php echo htmlspecialchars($t['room_number']); ?></strong></td>
                                <td>
                                    <?php 
                                    $r_badge = 'badge-success';
                                    if ($t['room_current_status'] === 'Occupied') $r_badge = 'badge-warning';
                                    if ($t['room_current_status'] === 'Dirty') $r_badge = 'badge-danger';
                                    if ($t['room_current_status'] === 'Maintenance') $r_badge = 'badge-info';
                                    ?>
                                    <span class="badge <?php echo $r_badge; ?>"><?php echo $t['room_current_status']; ?></span>
                                </td>
                                <td>
                                    <?php echo $t['first_name'] ? htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) : '<span style="color:var(--danger-color); font-weight:600;">Unassigned</span>'; ?>
                                </td>
                                <td>
                                    <?php 
                                    $badge = 'badge-danger';
                                    $pulse_dot = '<span class="status-pulse-dot" style="background: var(--danger-color); display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 6px; box-shadow: 0 0 8px var(--danger-color); animation: pulse-status 1.5s infinite alternate;"></span>';
                                    if ($t['status'] === 'Clean') {
                                        $badge = 'badge-success';
                                        $pulse_dot = '';
                                    } elseif ($t['status'] === 'Cleaning') {
                                        $badge = 'badge-warning';
                                        $pulse_dot = '<span class="status-pulse-dot" style="background: var(--warning-color); display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 6px; box-shadow: 0 0 8px var(--warning-color); animation: pulse-status 1.5s infinite alternate;"></span>';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge; ?>" style="display: inline-flex; align-items: center;">
                                        <?php echo $pulse_dot; ?>
                                        <?php echo $t['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo format_datetime($t['assigned_at']); ?></td>
                                <td><?php echo htmlspecialchars($t['remarks'] ?? 'None'); ?></td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                    <?php if (!$t['staff_id'] && $current_role !== 'Housekeeping'): ?>
                                        <button class="btn btn-primary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" onclick="openAssignModal(<?php echo $t['id']; ?>)">
                                            <i class="fas fa-user-plus"></i> Assign
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($t['status'] !== 'Clean'): ?>
                                        <button class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: rgba(52,211,153,0.1); color: var(--success-color);" onclick="openUpdateModal(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                            <i class="fas fa-clipboard-check"></i> Complete
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-secondary);">No housekeeping tasks scheduled.</td>
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

<!-- ASSIGN STAFF MODAL -->
<div class="modal" id="assignModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Assign Housekeeping Cleaner</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('assignModal')">&times;</button>
        </div>
        
        <form action="housekeeping.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="hk_id" id="assign_hk_id">
            
            <div class="form-group">
                <label class="form-label">Select Cleaner</label>
                <select name="staff_id" class="form-select" required>
                    <option value="">Select housekeeping staff...</option>
                    <?php foreach ($hk_staff as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Task Instructions / Remarks</label>
                <input type="text" name="remarks" class="form-input" placeholder="e.g. Wash bathroom, change towels">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Roster</button>
            </div>
        </form>
    </div>
</div>

<!-- UPDATE TASK STATUS MODAL -->
<div class="modal" id="updateTaskModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Update Clean Task status</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('updateTaskModal')">&times;</button>
        </div>
        
        <form action="housekeeping.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" id="update_hk_id">
            
            <div class="form-group">
                <label class="form-label">Update Status</label>
                <select name="status" id="update_status" class="form-select" required>
                    <option value="Dirty">Dirty</option>
                    <option value="Cleaning">Cleaning In Progress</option>
                    <option value="Clean">Clean (Release Room)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Remarks / Notes</label>
                <input type="text" name="remarks" id="update_remarks" class="form-input">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('updateTaskModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Updates</button>
            </div>
        </form>
    </div>
</div>

<!-- LOG MAINTENANCE MODAL -->
<div class="modal" id="maintenanceModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Log Room Maintenance</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('maintenanceModal')">&times;</button>
        </div>
        
        <form action="housekeeping.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="maintenance">
            
            <div class="form-group">
                <label class="form-label">Room Number</label>
                <select name="room_id" class="form-select" required>
                    <option value="">Select Room...</option>
                    <?php foreach ($all_rooms as $rm): ?>
                        <option value="<?php echo $rm['id']; ?>" <?php echo $rm['status'] === 'Occupied' ? 'disabled' : ''; ?>>
                            Room <?php echo htmlspecialchars($rm['room_number']); ?> (Currently: <?php echo $rm['status']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Issue / Maintenance Details</label>
                <textarea name="remarks" class="form-textarea" rows="3" placeholder="e.g. AC leaking water, bathroom pipe broken" required></textarea>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('maintenanceModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Lock for Maintenance</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAssignModal(hkId) {
    document.getElementById('assign_hk_id').value = hkId;
    openModal('assignModal');
}

function openUpdateModal(task) {
    document.getElementById('update_hk_id').value = task.id;
    document.getElementById('update_status').value = task.status;
    document.getElementById('update_remarks').value = task.remarks || '';
    
    openModal('updateTaskModal');
}

function openMaintenanceModal() {
    openModal('maintenanceModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
