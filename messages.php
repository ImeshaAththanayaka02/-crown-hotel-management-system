<?php
// messages.php
ob_start();

$page_title = 'Guest Messages';
require_once 'includes/header.php';

// Enforce role access (Admin, Manager)
require_role(['Admin', 'Manager']);

$csrf_token = generate_csrf_token();

// Handle POST actions (Delete, Mark Read, Mark All Read)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash_message('danger', 'CSRF validation failed.');
        header("Location: messages.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    
    // 1. DELETE MESSAGE
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('success', 'Message deleted successfully.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error deleting message: ' . $e->getMessage());
        }
        header("Location: messages.php");
        exit();
    }

    // 2. MARK AS READ
    if ($action === 'mark_read') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("UPDATE contact_messages SET status = 'Read' WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('success', 'Message marked as read.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error updating message: ' . $e->getMessage());
        }
        header("Location: messages.php");
        exit();
    }

    // 3. MARK ALL AS READ
    if ($action === 'mark_all_read') {
        try {
            $pdo->query("UPDATE contact_messages SET status = 'Read' WHERE status = 'Unread'");
            set_flash_message('success', 'All messages marked as read.');
        } catch (PDOException $e) {
            set_flash_message('danger', 'Error updating messages: ' . $e->getMessage());
        }
        header("Location: messages.php");
        exit();
    }
}

// Handle GET view message popup & mark as read
$view_message = null;
if (isset($_GET['view_id'])) {
    $view_id = (int)$_GET['view_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE id = ?");
        $stmt->execute([$view_id]);
        $view_message = $stmt->fetch();
        if ($view_message) {
            if ($view_message['status'] === 'Unread') {
                $upd = $pdo->prepare("UPDATE contact_messages SET status = 'Read' WHERE id = ?");
                $upd->execute([$view_id]);
                // update local state for the modal/list in this execution
                $view_message['status'] = 'Read';
            }
        }
    } catch (PDOException $e) {
        // Silent error
    }
}

// Handle Filters
$filter = sanitize($_GET['filter'] ?? 'all');
$where_clause = "";
if ($filter === 'unread') {
    $where_clause = "WHERE status = 'Unread'";
} elseif ($filter === 'read') {
    $where_clause = "WHERE status = 'Read'";
}

// Fetch Messages
try {
    $messages_stmt = $pdo->query("SELECT * FROM contact_messages {$where_clause} ORDER BY created_at DESC");
    $messages = $messages_stmt->fetchAll();
    
    // Count totals
    $total_unread = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'Unread'")->fetchColumn();
    $total_all = $pdo->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- TOP CONTROL PANEL -->
    <div class="glass-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3>Guest Inquiry Dashboard</h3>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0;">Read and manage messages received through the public contact form</p>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <?php if ($total_unread > 0): ?>
                <span class="badge badge-danger" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $total_unread; ?> Unread Message<?php echo $total_unread > 1 ? 's' : ''; ?>
                </span>
                
                <form action="messages.php" method="POST" style="margin: 0;" onsubmit="return confirm('Mark all messages as read?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 1rem;">
                        <i class="fas fa-check-double"></i> Mark All Read
                    </button>
                </form>
            <?php else: ?>
                <span class="badge badge-success" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                    <i class="fas fa-check"></i> All Caught Up
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- FILTER TAB NAV -->
    <div style="display: flex; gap: 0.5rem;">
        <a href="messages.php?filter=all" class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 0.8rem; padding: 0.4rem 1.2rem;">
            All (<?php echo $total_all; ?>)
        </a>
        <a href="messages.php?filter=unread" class="btn <?php echo $filter === 'unread' ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 0.8rem; padding: 0.4rem 1.2rem;">
            Unread (<?php echo $total_unread; ?>)
        </a>
        <a href="messages.php?filter=read" class="btn <?php echo $filter === 'read' ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 0.8rem; padding: 0.4rem 1.2rem;">
            Read (<?php echo $total_all - $total_unread; ?>)
        </a>
    </div>

    <!-- MESSAGES TABLE LIST -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th style="width: 20%;">Sender Name</th>
                        <th style="width: 25%;">Email</th>
                        <th style="width: 25%;">Subject</th>
                        <th style="width: 15%;">Received Date</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 5%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($messages) > 0): ?>
                        <?php foreach ($messages as $msg): ?>
                            <tr style="<?php echo $msg['status'] === 'Unread' ? 'font-weight: 600; background-color: rgba(255,255,255,0.015);' : ''; ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <?php if ($msg['status'] === 'Unread'): ?>
                                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: var(--danger-color);" title="Unread"></span>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($msg['name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <a href="mailto:<?php echo htmlspecialchars($msg['email']); ?>" style="color: var(--primary-accent); transition: opacity 0.2s;" title="Email guest">
                                        <i class="far fa-envelope"></i> <?php echo htmlspecialchars($msg['email']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span style="display: block; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo htmlspecialchars($msg['subject']); ?>
                                    </span>
                                </td>
                                <td><?php echo format_datetime($msg['created_at']); ?></td>
                                <td>
                                    <?php if ($msg['status'] === 'Unread'): ?>
                                        <span class="badge badge-danger">Unread</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Read</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; align-items: center;">
                                        <!-- View Detail Button -->
                                        <a href="messages.php?filter=<?php echo $filter; ?>&view_id=<?php echo $msg['id']; ?>" class="btn btn-primary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" title="View inquiry details">
                                            <i class="fas fa-eye"></i> View
                                        </a>

                                        <!-- Mark Read Button (Only for Unread) -->
                                        <?php if ($msg['status'] === 'Unread'): ?>
                                            <form action="messages.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="id" value="<?php echo $msg['id']; ?>">
                                                <button type="submit" class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; border-color: var(--success-color); color: var(--success-color); background: rgba(52, 211, 153, 0.05);" title="Mark as read">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Delete Button -->
                                        <form action="messages.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this message?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $msg['id']; ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; border-color: var(--danger-color); color: var(--danger-color); background: rgba(248, 113, 113, 0.05);" title="Delete message">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 3rem 0;">
                                <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 0.75rem; display: block; opacity: 0.5;"></i>
                                No inquiries found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- VIEW MESSAGE DETAIL MODAL -->
<div class="modal" id="messageModal">
    <div class="modal-content" style="max-width: 600px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--glass-border); padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <h3 id="modalSubject" style="margin: 0; font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; color: var(--warning-color);">Message Details</h3>
            <span style="font-size: 1.5rem; cursor: pointer; color: var(--text-secondary); transition: color 0.2s;" onclick="closeMessageModal()">&times;</span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; font-size: 0.85rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 1rem;">
                <div>
                    <strong style="color: var(--text-secondary); display: block; margin-bottom: 0.2rem;">From</strong>
                    <span id="modalSenderName" style="font-weight: 600;">Sender Name</span>
                </div>
                <div>
                    <strong style="color: var(--text-secondary); display: block; margin-bottom: 0.2rem;">Email</strong>
                    <a id="modalSenderEmail" href="#" style="color: var(--primary-accent); font-weight: 500;">sender@email.com</a>
                </div>
            </div>

            <div style="font-size: 0.85rem; border-bottom: 1px solid var(--glass-border); padding-bottom: 1rem;">
                <strong style="color: var(--text-secondary); display: block; margin-bottom: 0.2rem;">Date Received</strong>
                <span id="modalDate">13 July 2026, 12:00 PM</span>
            </div>

            <div>
                <strong style="color: var(--text-secondary); display: block; margin-bottom: 0.5rem; font-size: 0.85rem;">Message Content</strong>
                <div id="modalMessageText" style="background: var(--input-bg); border: 1px solid var(--glass-border); border-radius: 8px; padding: 1.25rem; font-size: 0.9rem; line-height: 1.6; white-space: pre-wrap; max-height: 250px; overflow-y: auto;">
                    Message content goes here...
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeMessageModal()">Close</button>
                <a id="modalReplyBtn" href="#" class="btn btn-primary" style="background: var(--warning-color); color: #1a1200;">
                    <i class="fas fa-reply"></i> Reply via Email
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Open Message Detail Modal
function openMessageModal(id, name, email, subject, message, date) {
    document.getElementById('modalSubject').textContent = subject;
    document.getElementById('modalSenderName').textContent = name;
    
    const emailLink = document.getElementById('modalSenderEmail');
    emailLink.textContent = email;
    emailLink.href = 'mailto:' + encodeURIComponent(email);
    
    document.getElementById('modalDate').textContent = date;
    document.getElementById('modalMessageText').textContent = message;
    
    const replyBtn = document.getElementById('modalReplyBtn');
    replyBtn.href = 'mailto:' + encodeURIComponent(email) + '?subject=' + encodeURIComponent('Re: ' + subject);
    
    document.getElementById('messageModal').style.display = 'flex';
}

// Close Modal
function closeMessageModal() {
    document.getElementById('messageModal').style.display = 'none';
    
    // If we opened this popup via query parameters, clear the view_id parameter so page refresh doesn't trigger modal again
    const url = new URL(window.location.href);
    if (url.searchParams.has('view_id')) {
        url.searchParams.delete('view_id');
        window.history.replaceState({}, '', url.toString());
    }
}

// Close modal if clicking outside content box
window.addEventListener('click', function(event) {
    const modal = document.getElementById('messageModal');
    if (event.target === modal) {
        closeMessageModal();
    }
});
</script>

<?php
// Trigger opening the modal from PHP if view_id query parameter is set
if ($view_message) {
    $formatted_date = format_datetime($view_message['created_at']);
    echo "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        openMessageModal(
            " . (int)$view_message['id'] . ",
            " . json_encode($view_message['name']) . ",
            " . json_encode($view_message['email']) . ",
            " . json_encode($view_message['subject']) . ",
            " . json_encode($view_message['message']) . ",
            " . json_encode($formatted_date) . "
        );
    });
    </script>";
}

require_once 'includes/footer.php';
?>
