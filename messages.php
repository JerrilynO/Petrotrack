<?php
session_start();
include 'config/db_connect.php';
include 'auth_check.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];
$initials = strtoupper(substr($user_name, 0, 1));

$message_alert = "";
$msgType = "";

// 1. HANDLE SEND MESSAGE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_message'])) {
    $receiver_id = $_POST['receiver_id'];
    $subject = trim($_POST['subject']);
    $body = trim($_POST['message']);

    if (empty($body)) {
        $message_alert = "Message cannot be empty.";
        $msgType = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $user_id, $receiver_id, $subject, $body);
        if ($stmt->execute()) {
            $message_alert = "Message sent successfully!";
            $msgType = "success";
        } else {
            $message_alert = "Error sending message.";
            $msgType = "error";
        }
        $stmt->close();
    }
}

// 2. HANDLE MARK AS READ
if (isset($_GET['read'])) {
    $msg_id = $_GET['read'];
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
    $stmt->bind_param("ii", $msg_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: messages.php?view=" . $msg_id);
    exit();
}

// 3. HANDLE DELETE MESSAGE
if (isset($_GET['delete'])) {
    $msg_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)");
    $stmt->bind_param("iii", $msg_id, $user_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: messages.php");
    exit();
}

// 4. FETCH INBOX
$inbox_sql = "SELECT m.*, u.name as sender_name, u.role as sender_role 
              FROM messages m 
              JOIN users u ON m.sender_id = u.id 
              WHERE m.receiver_id = ? 
              ORDER BY m.created_at DESC";
$inbox_stmt = $conn->prepare($inbox_sql);
$inbox_stmt->bind_param("i", $user_id);
$inbox_stmt->execute();
$inbox = $inbox_stmt->get_result();

// 5. FETCH SENT
$sent_sql = "SELECT m.*, u.name as receiver_name, u.role as receiver_role 
             FROM messages m 
             JOIN users u ON m.receiver_id = u.id 
             WHERE m.sender_id = ? 
             ORDER BY m.created_at DESC";
$sent_stmt = $conn->prepare($sent_sql);
$sent_stmt->bind_param("i", $user_id);
$sent_stmt->execute();
$sent = $sent_stmt->get_result();

// 6. FETCH UNREAD COUNT
$unread_stmt = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_count = $unread_stmt->get_result()->fetch_assoc()['c'];

// 7. FETCH RECIPIENTS BASED ON ROLE
if ($user_role == 'admin') {
    $recipients_sql = "SELECT id, name, role FROM users WHERE id != ? ORDER BY role, name";
    $rec_stmt = $conn->prepare($recipients_sql);
    $rec_stmt->bind_param("i", $user_id);
} else {
    $recipients_sql = "SELECT id, name, role FROM users WHERE role = 'admin' ORDER BY name";
    $rec_stmt = $conn->prepare($recipients_sql);
}
$rec_stmt->execute();
$recipients = $rec_stmt->get_result();

// 8. FETCH SINGLE MESSAGE IF VIEWING
$view_message = null;
if (isset($_GET['view'])) {
    $view_id = $_GET['view'];
    $view_stmt = $conn->prepare("SELECT m.*, 
        s.name as sender_name, s.role as sender_role,
        r.name as receiver_name, r.role as receiver_role
        FROM messages m
        JOIN users s ON m.sender_id = s.id
        JOIN users r ON m.receiver_id = r.id
        WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)");
    $view_stmt->bind_param("iii", $view_id, $user_id, $user_id);
    $view_stmt->execute();
    $view_message = $view_stmt->get_result()->fetch_assoc();

    if ($view_message && $view_message['receiver_id'] == $user_id && !$view_message['is_read']) {
        $mark_stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
        $mark_stmt->bind_param("i", $view_id);
        $mark_stmt->execute();
    }
}

$active_tab = $_GET['tab'] ?? 'inbox';

// Helper function
if (!function_exists('getRoleLabel')) {
    function getRoleLabel($role) {
        switch($role) {
            case 'admin': return 'Admin';
            case 'driver': return 'Driver';
            case 'supervisor': return 'Supervisor';
            default: return ucfirst($role);
        }
    }
}

// Sidebar dashboard link based on role
$dashboard_link = 'admin_dashboard.php';
if ($user_role == 'driver') $dashboard_link = 'driver_dashboard.php';
if ($user_role == 'supervisor') $dashboard_link = 'supervisor_dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - PetroTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #0986B8;
            --primary-dark: #3b0357;
            --primary-light: #7c2d9e;
            --primary-bright: #6b21a5;
            --secondary: #0f172a;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --success: #10b981;
            --warning: #f59e0b;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --radius: 12px;
            --radius-lg: 20px;
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f3e8ff 50%, #f8fafc 100%);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(135deg, #ffffff 0%, #faf5ff 100%);
            height: 100vh;
            position: fixed;
            left: 0; top: 0;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid #e2e8f0; }
        .brand-logo {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--secondary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .brand-logo span { color: var(--primary); }

        .nav-links { list-style: none; padding: 20px 0; flex: 1; }
        .nav-links li a {
            display: flex; align-items: center; padding: 12px 25px;
            color: var(--text-muted); text-decoration: none; font-weight: 500;
            transition: all 0.3s; gap: 12px; font-size: 0.95rem;
            justify-content: space-between;
        }
        .nav-links li a:hover, .nav-links li a.active {
            color: var(--primary);
            background: #faf5ff;
            border-right: 4px solid var(--primary);
        }
        .nav-left { display: flex; align-items: center; gap: 12px; }
        .badge {
            background: var(--danger); color: white; font-size: 0.65rem; font-weight: 700;
            padding: 2px 7px; border-radius: 10px; min-width: 20px; text-align: center;
        }

        .user-profile { padding: 20px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px; flex-shrink: 0;
        }
        .btn-logout {
            display: flex; align-items: center; justify-content: center;
            width: calc(100% - 40px); margin: 0 20px 20px;
            background: var(--secondary); color: white; border: none; padding: 12px;
            border-radius: 50px; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: all 0.2s;
        }
        .btn-logout:hover { background: var(--primary); transform: translateY(-2px); }

        /* --- MAIN CONTENT --- */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 30px;
            width: calc(100% - var(--sidebar-width));
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h2 { font-size: 28px; font-weight: 700; color: var(--secondary); }

        /* --- BUTTONS --- */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white; border: none; padding: 10px 20px; border-radius: var(--radius);
            cursor: pointer; font-weight: 600; display: inline-flex; align-items: center;
            gap: 8px; text-decoration: none; font-size: 0.85rem;
            transition: all 0.3s; box-shadow: 0 4px 6px -1px rgba(79, 4, 112, 0.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 15px -5px rgba(79, 4, 112, 0.4); }

        /* --- MESSAGES LAYOUT --- */
        .messages-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }

        /* --- LEFT PANEL --- */
        .left-panel {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(79, 4, 112, 0.1);
            overflow: hidden;
        }

        .tabs { display: flex; border-bottom: 1px solid #e2e8f0; }
        .tab-btn {
            flex: 1; padding: 14px; text-align: center; font-size: 0.8rem; font-weight: 600;
            cursor: pointer; border: none; background: none; color: var(--text-muted);
            transition: 0.2s;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            background: #faf5ff;
        }

        .message-list { max-height: 600px; overflow-y: auto; }

        .message-item {
            padding: 15px 20px; border-bottom: 1px solid #e2e8f0; cursor: pointer;
            transition: background 0.2s; display: block; text-decoration: none; color: inherit;
        }
        .message-item:hover { background: #faf5ff; }
        .message-item.unread { background: #f5f0ff; border-left: 3px solid var(--primary); }
        .message-item.active-msg { background: #eef0ff; }

        .msg-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .msg-from { font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .msg-time { font-size: 0.65rem; color: var(--text-muted); }
        .msg-subject { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .msg-preview { font-size: 0.7rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .unread-dot { width: 8px; height: 8px; background: var(--primary); border-radius: 50%; display: inline-block; margin-right: 5px; }

        .empty-msg { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 0.8rem; }

        /* --- RIGHT PANEL --- */
        .right-panel { display: flex; flex-direction: column; gap: 20px; }

        /* Compose */
        .compose-card {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(79, 4, 112, 0.1);
            padding: 25px;
        }
        .compose-card h3 {
            font-size: 1rem; font-weight: 700; margin-bottom: 18px;
            padding-bottom: 10px; border-bottom: 1px solid #e2e8f0;
            color: var(--secondary);
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block; font-size: 0.7rem; font-weight: 600;
            color: var(--text-muted); margin-bottom: 5px; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0;
            border-radius: var(--radius); font-size: 0.85rem; font-family: 'Inter', sans-serif;
            transition: 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 4, 112, 0.1);
        }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .btn-send {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white; border: none; padding: 12px 24px; border-radius: var(--radius);
            font-size: 0.85rem; font-weight: 600; cursor: pointer; width: 100%;
            transition: all 0.3s;
        }
        .btn-send:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79, 4, 112, 0.3); }

        /* View Message */
        .view-card {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(79, 4, 112, 0.1);
            padding: 25px;
        }
        .view-header { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .view-subject { font-size: 1.2rem; font-weight: 700; color: var(--secondary); margin-bottom: 10px; }
        .view-meta { font-size: 0.75rem; color: var(--text-muted); display: flex; gap: 20px; flex-wrap: wrap; }
        .view-meta span strong { color: var(--primary); }
        .view-body {
            font-size: 0.85rem; line-height: 1.8; color: var(--text-main);
            margin-bottom: 20px; white-space: pre-wrap;
        }
        .view-actions { display: flex; gap: 10px; }
        .btn-reply {
            background: #f0e7ff; color: var(--primary); border: none; padding: 9px 18px;
            border-radius: var(--radius); font-weight: 600; cursor: pointer; font-size: 0.75rem;
            transition: 0.2s;
        }
        .btn-reply:hover { background: var(--primary); color: white; }
        .btn-del-msg {
            background: #fee2e2; color: var(--danger); border: none; padding: 9px 18px;
            border-radius: var(--radius); font-weight: 600; cursor: pointer; font-size: 0.75rem;
            transition: 0.2s;
        }
        .btn-del-msg:hover { background: var(--danger); color: white; }

        /* Role badge */
        .role-tag { font-size: 0.6rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
        .role-admin { background: #e0e7ff; color: #3730a3; }
        .role-driver { background: #d1fae5; color: #065f46; }
        .role-supervisor { background: #fed7aa; color: #92400e; }

        /* Alert */
        .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-size: 0.85rem; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid var(--success); }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid var(--danger); }

        @media (max-width: 900px) {
            .messages-layout { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 1000; }
            .main-content { margin-left: 0; width: 100%; padding: 20px; }
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="<?php echo $dashboard_link; ?>" class="brand-logo">
                <span>🛢️</span> PetroTrack
            </a>
        </div>
        <ul class="nav-links">
            <?php if ($user_role == 'admin'): ?>
                <li><a href="admin_dashboard.php"><span class="nav-left"></i> Dashboard</span></a></li>
                <li><a href="vehicle_management.php"><span class="nav-left"></i> Vehicle Management</span></a></li>
                <li><a href="delivery_management.php"><span class="nav-left"></i> Delivery Orders</span></a></li>
                <li><a href="user_management.php"><span class="nav-left"></i> User Management</span></a></li>
                <li><a href="station_management.php"><span class="nav-left"></i> Station Management</span></a></li>
                <li><a href="tracking_map.php"><span class="nav-left"></i> Simulated Tracking</span></a></li>
                <li>
                    <a href="messages.php" class="active">
                        <span class="nav-left"></i> Messages</span>
                        <?php if ($unread_count > 0): ?>
                            <span class="badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="reports.php"><span class="nav-left"></i> Reports</span></a></li>
                <li><a href="profile.php"></i> My Profile</a></li>
            <?php elseif ($user_role == 'driver'): ?>
                <li><a href="driver_dashboard.php"><span class="nav-left"> My Deliveries</span></a></li>
                <li><a href="messages.php" class="active"><span class="nav-left"> Messages</span>
                    <?php if ($unread_count > 0): ?><span class="badge"><?php echo $unread_count; ?></span><?php endif; ?>
                </a></li>
                <li><a href="profile.php"><span class="nav-left"> My Profile</span></a></li>
            <?php elseif ($user_role == 'supervisor'): ?>
                <li><a href="supervisor_dashboard.php"><span class="nav-left"> Station Dashboard</span></a></li>
                <li><a href="messages.php" class="active"><span class="nav-left"> Messages</span>
                    <?php if ($unread_count > 0): ?><span class="badge"><?php echo $unread_count; ?></span><?php endif; ?>
                </a></li>
                <li><a href="profile.php"><span class="nav-left"> My Profile</span></a></li>
            <?php endif; ?>
            
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo $initials; ?></div>
            <div>
                <div style="font-weight: 700; font-size: 14px;"><?php echo htmlspecialchars($user_name); ?></div>
                <div style="font-size: 11px; color: var(--text-muted);"><?php echo getRoleLabel($user_role); ?></div>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">

        <div class="page-header">
            <h2><i ></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="font-size:0.85rem; background:var(--danger); color:white; padding:3px 10px; border-radius:10px; margin-left:8px;"><?php echo $unread_count; ?> unread</span>
                <?php endif; ?>
            </h2>
        </div>

        <?php if ($message_alert): ?>
            <div class="alert <?php echo $msgType == 'success' ? 'alert-success' : 'alert-error'; ?>">
                <i class="fas fa-<?php echo $msgType == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message_alert; ?>
            </div>
        <?php endif; ?>

        <div class="messages-layout">

            <!-- LEFT: Message List -->
            <div class="left-panel">
                <div class="tabs">
                    <button class="tab-btn <?php echo $active_tab == 'inbox' ? 'active' : ''; ?>" onclick="switchTab('inbox')">
                        <i class="fas fa-inbox"></i> Inbox <?php if ($unread_count > 0): ?><span class="badge"><?php echo $unread_count; ?></span><?php endif; ?>
                    </button>
                    <button class="tab-btn <?php echo $active_tab == 'sent' ? 'active' : ''; ?>" onclick="switchTab('sent')">
                        <i class="fas fa-paper-plane"></i> Sent
                    </button>
                </div>

                <!-- INBOX -->
                <div id="inbox-tab" class="message-list" style="<?php echo $active_tab == 'sent' ? 'display:none;' : ''; ?>">
                    <?php if ($inbox->num_rows > 0): ?>
                        <?php while ($msg = $inbox->fetch_assoc()): ?>
                            <a href="messages.php?view=<?php echo $msg['id']; ?>&tab=inbox" 
                               class="message-item <?php echo !$msg['is_read'] ? 'unread' : ''; ?> <?php echo (isset($_GET['view']) && $_GET['view'] == $msg['id']) ? 'active-msg' : ''; ?>">
                                <div class="msg-header">
                                    <span class="msg-from">
                                        <?php if (!$msg['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
                                        <?php echo htmlspecialchars($msg['sender_name']); ?>
                                        <span class="role-tag role-<?php echo $msg['sender_role']; ?>"><?php echo getRoleLabel($msg['sender_role']); ?></span>
                                    </span>
                                    <span class="msg-time"><?php echo date('d M, H:i', strtotime($msg['created_at'])); ?></span>
                                </div>
                                <div class="msg-subject"><?php echo htmlspecialchars($msg['subject'] ?: '(No subject)'); ?></div>
                                <div class="msg-preview"><?php echo htmlspecialchars(substr($msg['message'], 0, 60)) . '...'; ?></div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-msg"><i class="fas fa-inbox fa-2x" style="margin-bottom: 10px; display: block; opacity: 0.5;"></i> No messages in your inbox</div>
                    <?php endif; ?>
                </div>

                <!-- SENT -->
                <div id="sent-tab" class="message-list" style="<?php echo $active_tab == 'inbox' ? 'display:none;' : ''; ?>">
                    <?php if ($sent->num_rows > 0): ?>
                        <?php while ($msg = $sent->fetch_assoc()): ?>
                            <a href="messages.php?view=<?php echo $msg['id']; ?>&tab=sent"
                               class="message-item <?php echo (isset($_GET['view']) && $_GET['view'] == $msg['id']) ? 'active-msg' : ''; ?>">
                                <div class="msg-header">
                                    <span class="msg-from">
                                        To: <?php echo htmlspecialchars($msg['receiver_name']); ?>
                                        <span class="role-tag role-<?php echo $msg['receiver_role']; ?>"><?php echo getRoleLabel($msg['receiver_role']); ?></span>
                                    </span>
                                    <span class="msg-time"><?php echo date('d M, H:i', strtotime($msg['created_at'])); ?></span>
                                </div>
                                <div class="msg-subject"><?php echo htmlspecialchars($msg['subject'] ?: '(No subject)'); ?></div>
                                <div class="msg-preview"><?php echo htmlspecialchars(substr($msg['message'], 0, 60)) . '...'; ?></div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-msg"><i class="fas fa-paper-plane fa-2x" style="margin-bottom: 10px; display: block; opacity: 0.5;"></i> No sent messages</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: View + Compose -->
            <div class="right-panel">

                <!-- View Message -->
                <?php if ($view_message): ?>
                <div class="view-card">
                    <div class="view-header">
                        <div class="view-subject"><?php echo htmlspecialchars($view_message['subject'] ?: '(No subject)'); ?></div>
                        <div class="view-meta">
                            <span><strong>From:</strong> <?php echo htmlspecialchars($view_message['sender_name']); ?> 
                                <span class="role-tag role-<?php echo $view_message['sender_role']; ?>"><?php echo getRoleLabel($view_message['sender_role']); ?></span>
                            </span>
                            <span><strong>To:</strong> <?php echo htmlspecialchars($view_message['receiver_name']); ?>
                                <span class="role-tag role-<?php echo $view_message['receiver_role']; ?>"><?php echo getRoleLabel($view_message['receiver_role']); ?></span>
                            </span>
                            <span><strong>Date:</strong> <?php echo date('d M Y, H:i', strtotime($view_message['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="view-body"><?php echo nl2br(htmlspecialchars($view_message['message'])); ?></div>
                    <div class="view-actions">
                        <?php if ($view_message['receiver_id'] == $user_id): ?>
                            <button class="btn-reply" onclick="prefillReply('<?php echo htmlspecialchars($view_message['sender_name'], ENT_QUOTES); ?>', <?php echo $view_message['sender_id']; ?>, '<?php echo htmlspecialchars($view_message['subject'], ENT_QUOTES); ?>')">
                                <i class="fas fa-reply"></i> Reply
                            </button>
                        <?php endif; ?>
                        <a href="messages.php?delete=<?php echo $view_message['id']; ?>" class="btn-del-msg" onclick="return confirm('Delete this message?')">
                            <i class="fas fa-trash-alt"></i> Delete
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Compose -->
                <div class="compose-card" id="composeCard">
                    <h3><i class="fas fa-edit" style="color: var(--primary);"></i> Compose Message</h3>
                    <form method="POST" action="messages.php<?php echo isset($_GET['tab']) ? '?tab='.$_GET['tab'] : ''; ?>">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> To</label>
                            <select name="receiver_id" id="receiverSelect" required>
                                <option value="">-- Select Recipient --</option>
                                <?php 
                                $recipients->data_seek(0);
                                while ($r = $recipients->fetch_assoc()): ?>
                                    <option value="<?php echo $r['id']; ?>">
                                        <?php echo htmlspecialchars($r['name']); ?> (<?php echo getRoleLabel($r['role']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Subject</label>
                            <input type="text" name="subject" id="subjectInput" placeholder="e.g. Delivery Update">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-comment"></i> Message</label>
                            <textarea name="message" placeholder="Type your message here..." required></textarea>
                        </div>
                        <button type="submit" name="send_message" class="btn-send">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                </div>

            </div>
        </div>

    </main>

    <script>
        function switchTab(tab) {
            document.getElementById('inbox-tab').style.display = tab === 'inbox' ? 'block' : 'none';
            document.getElementById('sent-tab').style.display = tab === 'sent' ? 'block' : 'none';
            document.querySelectorAll('.tab-btn').forEach((btn, i) => {
                btn.classList.toggle('active', (i === 0 && tab === 'inbox') || (i === 1 && tab === 'sent'));
            });
        }

        function prefillReply(senderName, senderId, subject) {
            document.getElementById('receiverSelect').value = senderId;
            document.getElementById('subjectInput').value = subject.startsWith('Re: ') ? subject : 'Re: ' + subject;
            document.getElementById('composeCard').scrollIntoView({ behavior: 'smooth' });
        }
    </script>

</body>
</html>