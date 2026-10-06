<?php
// staff.php
ob_start();

$page_title = 'Staff & Attendance Roster';
require_once 'includes/header.php';

// Enforce admin access only
require_role('Admin');

$error_msg = '';
$success_msg = '';

// ---------------------------------------------------------
// POST ACTIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: staff.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 1. ADD NEW STAFF & USER ACCOUNT
    if ($action === 'create') {
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $department = sanitize($_POST['department']);
        $phone = sanitize($_POST['phone']);
        $salary = (float)$_POST['salary'];
        $hire_date = sanitize($_POST['hire_date']);
        
        // User Credentials
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        if (empty($first_name) || empty($last_name) || empty($department) || empty($phone) || empty($salary) || empty($hire_date) || empty($username) || empty($email) || empty($password)) {
            set_flash_message('danger', 'All fields including login credentials are required.');
        } else {
            try {
                $pdo->beginTransaction();

                // Check username or email duplicate
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $chk->execute([$username, $email]);
                if ($chk->fetch()) {
                    set_flash_message('danger', 'Username or email already exists.');
                    $pdo->rollBack();
                } else {
                    // Match department to role
                    $role_id = 3; // Receptionist default
                    if ($department === 'Management') $role_id = 2; // Manager
                    if ($department === 'Housekeeping') $role_id = 4; // Housekeeper

                    // Create User login
                    $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
                    $ins_user = $pdo->prepare("INSERT INTO users (username, email, password, role_id, status) VALUES (?, ?, ?, ?, 'Active')");
                    $ins_user->execute([$username, $email, $hashed_pwd]);
                    $user_id = $pdo->lastInsertId();

                    // Create Staff record
                    $ins_staff = $pdo->prepare("INSERT INTO staff (user_id, first_name, last_name, department, phone, salary, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins_staff->execute([$user_id, $first_name, $last_name, $department, $phone, $salary, $hire_date]);

                    $pdo->commit();
                    set_flash_message('success', "Staff member {$first_name} {$last_name} registered successfully!");
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                set_flash_message('danger', 'Failed to register staff: ' . $e->getMessage());
            }
        }
        header("Location: staff.php");
        exit();
    }

    // 2. UPDATE STAFF DETAILS
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $department = sanitize($_POST['department']);
        $phone = sanitize($_POST['phone']);
        $salary = (float)$_POST['salary'];
        $hire_date = sanitize($_POST['hire_date']);

        try {
            $stmt = $pdo->prepare("UPDATE staff SET first_name = ?, last_name = ?, department = ?, phone = ?, salary = ?, hire_date = ? WHERE id = ?");
            $stmt->execute([$first_name, $last_name, $department, $phone, $salary, $hire_date, $id]);
            set_flash_message('success', 'Staff details updated.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Update failed: ' . $e->getMessage());
        }
        header("Location: staff.php");
        exit();
    }

    // 3. DELETE STAFF
    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        try {
            $pdo->beginTransaction();

            // Fetch user ID
            $stmt = $pdo->prepare("SELECT user_id FROM staff WHERE id = ?");
            $stmt->execute([$id]);
            $user_id = $stmt->fetchColumn();

            // Delete staff
            $del_staff = $pdo->prepare("DELETE FROM staff WHERE id = ?");
            $del_staff->execute([$id]);

            // Delete login account
            if ($user_id) {
                $del_user = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $del_user->execute([$user_id]);
            }

            $pdo->commit();
            set_flash_message('success', 'Staff member profile removed.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('danger', 'Failed to delete staff member.');
        }
        header("Location: staff.php");
        exit();
    }

    // 4. CLOCK-IN/OUT ATTENDANCE SIMULATION
    if ($action === 'attendance_log') {
        $staff_id = (int)$_POST['staff_id'];
        $date = sanitize($_POST['date']);
        $clock_type = sanitize($_POST['clock_type']); // 'in' or 'out'

        try {
            if ($clock_type === 'in') {
                $clock_in_time = sanitize($_POST['time']);
                
                // Determine Late status (late if clock in is after 09:00:00)
                $status = (strtotime($clock_in_time) > strtotime('09:00:00')) ? 'Late' : 'Present';

                $stmt = $pdo->prepare("
                    INSERT INTO attendance (staff_id, date, clock_in, status) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE clock_in = VALUES(clock_in), status = VALUES(status)
                ");
                $stmt->execute([$staff_id, $date, $clock_in_time, $status]);
                set_flash_message('success', 'Clock-in attendance logged.');
            } else {
                $clock_out_time = sanitize($_POST['time']);

                $stmt = $pdo->prepare("UPDATE attendance SET clock_out = ? WHERE staff_id = ? AND date = ?");
                $stmt->execute([$clock_out_time, $staff_id, $date]);
                set_flash_message('success', 'Clock-out attendance logged.');
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Attendance update failed: ' . $e->getMessage());
        }
        header("Location: staff.php#attendance");
        exit();
    }
}

// ---------------------------------------------------------
// QUERY DATA FOR LISTS
// ---------------------------------------------------------

// Fetch staff directory
$staff_list = $pdo->query("
    SELECT s.*, u.username, u.email, u.status AS user_status 
    FROM staff s 
    LEFT JOIN users u ON s.user_id = u.id 
    ORDER BY s.first_name ASC
")->fetchAll();

// Fetch today's attendance logs
$today_attendance = $pdo->query("
    SELECT a.*, s.first_name, s.last_name, s.department 
    FROM attendance a 
    JOIN staff s ON a.staff_id = s.id 
    WHERE a.date = CURDATE() 
    ORDER BY a.clock_in ASC
")->fetchAll();

$csrf_token = generate_csrf_token();
?>

<div style="display: flex; flex-direction: column; gap: 2rem;">

    <!-- TABS NAVIGATION -->
    <div style="display: flex; gap: 1rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 0.5rem; margin-bottom: 0.5rem;" class="no-print">
        <a href="#directory" class="btn btn-secondary" onclick="showTab('directory-tab')" id="btn-directory-tab">Staff Directory</a>
        <a href="#attendance" class="btn btn-secondary" onclick="showTab('attendance-tab')" id="btn-attendance-tab">Attendance Logger</a>
    </div>

    <!-- TAB 1: STAFF DIRECTORY -->
    <div id="directory-tab" class="tab-content">
        <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Staff Directory</h3>
            <button class="btn btn-primary" onclick="openAddStaffModal()"><i class="fas fa-user-plus"></i> Add Staff Member</button>
        </div>

        <div class="glass-card">
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Staff Name</th>
                            <th>Username / Email</th>
                            <th>Department</th>
                            <th>Contact Phone</th>
                            <th>Salary</th>
                            <th>Hire Date</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff_list as $s): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($s['username'] ?? 'N/A'); ?></strong><br>
                                    <span style="font-size: 0.75rem; color: var(--text-secondary);"><?php echo htmlspecialchars($s['email'] ?? 'N/A'); ?></span>
                                </td>
                                <td>
                                    <?php 
                                    $dept_badge = 'badge-info';
                                    if ($s['department'] === 'Management') $dept_badge = 'badge-success';
                                    if ($s['department'] === 'Housekeeping') $dept_badge = 'badge-warning';
                                    ?>
                                    <span class="badge <?php echo $dept_badge; ?>"><?php echo $s['department']; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($s['phone']); ?></td>
                                <td><strong><?php echo format_currency($s['salary']); ?></strong></td>
                                <td><?php echo format_date($s['hire_date']); ?></td>
                                <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                    <button class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" onclick="openEditStaffModal(<?php echo htmlspecialchars(json_encode($s)); ?>)">
                                        Edit
                                    </button>
                                    <form action="staff.php" method="POST" onsubmit="return confirm('Remove employee and disable user account logins?')" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                        <button class="btn btn-danger" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" type="submit">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: ATTENDANCE LOGGER -->
    <div id="attendance-tab" class="tab-content" style="display: none;">
        <div class="grid-2">
            <!-- Clock-in/out controller -->
            <div class="glass-card">
                <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-clock" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Attendance Punch Panel</h3>
                
                <form action="staff.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="attendance_log">
                    <input type="hidden" name="date" value="<?php echo date('Y-m-d'); ?>">

                    <div class="form-group">
                        <label class="form-label">Select Employee</label>
                        <select name="staff_id" class="form-select" required>
                            <option value="">Choose employee...</option>
                            <?php foreach ($staff_list as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?> (<?php echo htmlspecialchars($s['department']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Clock Type</label>
                            <select name="clock_type" class="form-select" required>
                                <option value="in">Clock In (Shift Start)</option>
                                <option value="out">Clock Out (Shift End)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Punch Time</label>
                            <input type="time" name="time" class="form-input" value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                        <i class="fas fa-fingerprint"></i> Log Shift Attendance
                    </button>
                </form>
            </div>

            <!-- Today's Attendance Roll -->
            <div class="glass-card">
                <h3 style="margin-bottom: 1.25rem;"><i class="fas fa-clipboard-list" style="color: var(--primary-accent); margin-right: 0.5rem;"></i> Today's Shift Logs (<?php echo date('d M Y'); ?>)</h3>
                
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Cleaner / Desk</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Shift Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($today_attendance) > 0): ?>
                                <?php foreach ($today_attendance as $att): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($att['first_name'] . ' ' . $att['last_name']); ?></strong><br><span style="font-size:0.75rem; color:var(--text-secondary);"><?php echo $att['department']; ?></span></td>
                                        <td><strong><?php echo date('h:i A', strtotime($att['clock_in'])); ?></strong></td>
                                        <td><strong><?php echo $att['clock_out'] ? date('h:i A', strtotime($att['clock_out'])) : '--:--'; ?></strong></td>
                                        <td>
                                            <?php 
                                            $att_badge = 'badge-success';
                                            if ($att['status'] === 'Late') $att_badge = 'badge-warning';
                                            if ($att['status'] === 'Absent') $att_badge = 'badge-danger';
                                            ?>
                                            <span class="badge <?php echo $att_badge; ?>"><?php echo $att['status']; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-secondary);">No shift attendance clocked today yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ==========================================
      MODALS
     ========================================== -->

<!-- ADD STAFF MODAL -->
<div class="modal" id="addStaffModal">
    <div class="modal-content" style="max-width: 650px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add Roster Employee</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('addStaffModal')">&times;</button>
        </div>
        
        <form action="staff.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="create">
            
            <h4 style="color: var(--primary-accent); border-bottom: 1px solid var(--glass-border); padding-bottom: 0.25rem; margin-bottom: 1rem;">Personal Info</h4>
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-input" required>
                </div>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label">Department</label>
                    <select name="department" class="form-select" required>
                        <option value="Reception">Reception Desk</option>
                        <option value="Housekeeping">Housekeeping Crew</option>
                        <option value="Management">Management Desk</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Monthly Salary ($)</label>
                    <input type="number" step="0.01" name="salary" class="form-input" placeholder="2500.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Hire Date</label>
                    <input type="date" name="hire_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-input" required>
            </div>

            <h4 style="color: var(--primary-accent); border-bottom: 1px solid var(--glass-border); padding-bottom: 0.25rem; margin-bottom: 1rem; margin-top: 1.5rem;">User Account Credentials</h4>
            <div class="form-group">
                <label class="form-label">Choose Username</label>
                <input type="text" name="username" class="form-input" required autocomplete="username">
            </div>
            
            <div class="form-group">
                <label class="form-label">Corporate Email Address</label>
                <input type="email" name="email" class="form-input" required autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label">Default Account Password</label>
                <input type="password" name="password" class="form-input" required autocomplete="new-password">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addStaffModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Register Employee</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT STAFF DETAILS MODAL -->
<div class="modal" id="editStaffModal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Staff Details</h3>
            <button class="btn-close" style="font-size: 1.5rem;" onclick="closeModal('editStaffModal')">&times;</button>
        </div>
        
        <form action="staff.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_staff_id">
            
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
                <label class="form-label">Department</label>
                <select name="department" id="edit_department" class="form-select" required>
                    <option value="Reception">Reception</option>
                    <option value="Housekeeping">Housekeeping</option>
                    <option value="Management">Management</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" id="edit_phone" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Salary ($)</label>
                <input type="number" step="0.01" name="salary" id="edit_salary" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Hire Date</label>
                <input type="date" name="hire_date" id="edit_hire_date" class="form-input" required>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editStaffModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Tab controller logic
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

// Ensure active tab hash reflects on page reload
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash;
    if (hash === '#attendance') {
        showTab('attendance-tab');
    } else {
        showTab('directory-tab');
    }
});

function openAddStaffModal() {
    openModal('addStaffModal');
}

function openEditStaffModal(staff) {
    document.getElementById('edit_staff_id').value = staff.id;
    document.getElementById('edit_first_name').value = staff.first_name;
    document.getElementById('edit_last_name').value = staff.last_name;
    document.getElementById('edit_department').value = staff.department;
    document.getElementById('edit_phone').value = staff.phone;
    document.getElementById('edit_salary').value = parseFloat(staff.salary).toFixed(2);
    document.getElementById('edit_hire_date').value = staff.hire_date;
    
    openModal('editStaffModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
