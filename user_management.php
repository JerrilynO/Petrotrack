<?php
session_start();
include 'config/db_connect.php';

 $required_role = 'admin';
include 'auth_check.php';

// FETCH ADMIN NAME
 $admin_name = 'Admin User';
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($data = $res->fetch_assoc()) $admin_name = $data['name'];
}

if (!function_exists('getRoleLabel')) {
    function getRoleLabel($role) {
        return match($role) {
            'admin'      => 'Admin',
            'driver'     => 'Driver',
            'supervisor' => 'Supervisor',
            default      => ucfirst($role)
        };
    }
}

 $message = "";
 $msgType = "";

// ── 1. HANDLE STATUS CHANGE ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'], $_GET['id'])) {
    $target_id = (int) $_GET['id'];
    $action    = $_GET['action'];

    if ($target_id === (int) $_SESSION['user_id']) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'You cannot change your own status.'];
    } else {
        $new_status = match($action) {
            'activate'   => 'active',
            'suspend'    => 'suspended',
            'deactivate' => 'inactive',
            default      => null
        };

        if ($new_status) {
            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $target_id);
            $stmt->execute();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User status updated to: ' . ucfirst($new_status)];
        }
    }
    header("Location: user_management.php"); exit();
}

// ── 2. HANDLE DELETE ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $target_id = (int) $_GET['delete'];
    if ($target_id === (int) $_SESSION['user_id']) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'You cannot delete your own account.'];
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User deleted successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Error deleting user. They may have linked records.'];
        }
    }
    header("Location: user_management.php"); exit();
}

// ── 3. HANDLE EDIT USER (SIMPLIFIED - NO PASSWORD RESET) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user_id'])) {
    $edit_id    = (int) $_POST['edit_user_id'];
    $edit_name  = trim($_POST['edit_name']  ?? '');
    $edit_email = trim($_POST['edit_email'] ?? '');
    $edit_phone = trim($_POST['edit_phone'] ?? '');
    $edit_role  = $_POST['edit_role']   ?? '';
    $edit_station = (int) ($_POST['edit_station_id'] ?? 0);

    // Password Reset logic REMOVED. Admin cannot change passwords via this screen.

    if ($edit_name && $edit_email && in_array($edit_role, ['admin','driver','supervisor'])) {
        // Update everything EXCEPT password
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, station_id=? WHERE id=?");
        $stmt->bind_param("ssssi", $edit_name, $edit_email, $edit_phone, $edit_role, $edit_station, $edit_id);
        
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User updated successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Error updating user.'];
        }
        $stmt->close();
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please fill in all required fields.'];
    }
    header("Location: user_management.php"); exit();
}

// ── 4. HANDLE ADD NEW USER (Keep Password for creation) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $new_name     = trim($_POST['new_name']     ?? '');
    $new_email    = trim($_POST['new_email']    ?? '');
    $new_phone    = trim($_POST['new_phone']    ?? '');
    $new_role     = $_POST['new_role']    ?? '';
    $new_station  = (int) ($_POST['new_station_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if ($new_name && $new_email && $new_password && in_array($new_role, ['admin','driver','supervisor'])) {
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param("s", $new_email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A user with that email already exists.'];
        } else {
            $hashed  = password_hash($new_password, PASSWORD_DEFAULT);
            $status  = 'active';
            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, role, station_id, password, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssiis", $new_name, $new_email, $new_phone, $new_role, $new_station, $hashed, $status);
            if ($stmt->execute()) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "User '$new_name' created successfully."];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Error creating user.'];
            }
        }
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please fill in all required fields.'];
    }
    header("Location: user_management.php"); exit();
}

// ── FLASH MESSAGES ───────────────────────────────────────────────────────────
if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $msgType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// ── 5. FETCH FILTERS & USERS ─────────────────────────────────────────────────
 $filter_role   = $_GET['role']   ?? '';
 $filter_status = $_GET['status'] ?? '';
 $search        = trim($_GET['search'] ?? '');

 $sql = "SELECT u.*, s.name as station_name 
        FROM users u 
        LEFT JOIN stations s ON u.station_id = s.id";

 $conditions = [];
if ($filter_role)   $conditions[] = "u.role = '" . $conn->real_escape_string($filter_role) . "'";
if ($filter_status) $conditions[] = "u.status = '" . $conn->real_escape_string($filter_status) . "'";
if ($search)        $conditions[] = "(u.name LIKE '%" . $conn->real_escape_string($search) . "%' OR u.email LIKE '%" . $conn->real_escape_string($search) . "%')";
if (!empty($conditions)) $sql .= " WHERE " . implode(" AND ", $conditions);
 $sql .= " ORDER BY u.created_at DESC";

 $result = $conn->query($sql);

// ── COUNTS ───────────────────────────────────────────────────────────────────
 $total_users    = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
 $active_users   = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'active'")->fetch_assoc()['c'];
 $suspended_users= $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'suspended'")->fetch_assoc()['c'];
 $inactive_users = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'inactive'")->fetch_assoc()['c'];
 $driver_count   = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'driver'")->fetch_assoc()['c'];
 $supervisor_count = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'supervisor'")->fetch_assoc()['c'];

// ── STATIONS (for dropdowns) ──────────────────────────────────────────────────
 $stations_result = $conn->query("SELECT id, name FROM stations ORDER BY name");
 $stations = [];
while ($s = $stations_result->fetch_assoc()) $stations[] = $s;

// ── FETCH ALL USERS FOR JS (edit modal prefill) ───────────────────────────────
 $all_users_result = $conn->query("SELECT id, name, email, phone, role, station_id, status FROM users");
 $all_users_js = [];
while ($u = $all_users_result->fetch_assoc()) $all_users_js[$u['id']] = $u;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - PetroTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0986B8;
            --primary-dark: #7c3aed;
            --primary-light: #a78bfa;
            --primary-bright: #a855f7;
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
            background: linear-gradient(135deg, #f8fafc 0%, #f5f3ff 50%, #f8fafc 100%);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
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

        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid #f0f0f0; }
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
        }
        .nav-links li a.active, .nav-links li a:hover {
            color: var(--primary);
            background: #faf5ff;
            border-right: 4px solid var(--primary);
        }

        .user-profile { padding: 20px; border-top: 1px solid #f0f0f0; display: flex; align-items: center; gap: 10px; }
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
        .btn-logout:hover { background: var(--primary); }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; width: calc(100% - var(--sidebar-width)); }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { font-size: 28px; font-weight: 700; color: var(--secondary); }

        /* ── BUTTONS ── */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-bright) 100%);
            color: white; border: none; padding: 12px 24px; border-radius: var(--radius);
            cursor: pointer; font-weight: 600; font-size: 0.9rem;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);
            transition: all 0.3s; text-decoration: none;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 15px -5px rgba(139, 92, 246, 0.4); }

        /* ── STAT CARDS ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            padding: 20px; border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md); border-top: 4px solid transparent;
            text-align: center; cursor: pointer; transition: all 0.3s;
            text-decoration: none;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
        .stat-card.purple { border-color: var(--primary); }
        .stat-card.green  { border-color: var(--success); }
        .stat-card.red    { border-color: var(--danger); }
        .stat-card.gray   { border-color: #94a3b8; }
        .stat-card.teal   { border-color: #14b8a6; }
        .stat-card.orange { border-color: var(--warning); }
        .stat-title { font-size: 0.7rem; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 1.8rem; font-weight: 800; color: var(--secondary); }

        /* ── TOOLBAR ── */
        .toolbar {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            padding: 15px 20px; border-radius: var(--radius);
            box-shadow: var(--shadow-md); margin-bottom: 20px;
            border: 1px solid rgba(139, 92, 246, 0.1);
            display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
        }
        .search-box { position: relative; flex: 1; min-width: 200px; }
        .search-box input {
            width: 100%; padding: 10px 14px 10px 38px; border-radius: var(--radius);
            border: 1px solid #e2e8f0; font-size: 0.85rem; font-family: 'Inter', sans-serif;
            outline: none; transition: 0.2s;
        }
        .search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); }
        .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
        .divider { width: 1px; height: 24px; background: #e2e8f0; }
        .filter-label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-btn {
            padding: 6px 14px; border-radius: 50px; font-size: 0.8rem; font-weight: 500;
            text-decoration: none; color: var(--text-muted); background: #f8fafc;
            border: 1px solid #e2e8f0; transition: all 0.2s; white-space: nowrap;
        }
        .filter-btn:hover, .filter-btn.active {
            background: var(--primary); color: white; border-color: var(--primary);
        }

        /* ── TABLE ── */
        .table-container {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(139, 92, 246, 0.1);
            overflow-x: auto;
        }
        .table-header { padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; }
        .table-header strong { color: var(--secondary); font-size: 0.9rem; }
        .table-header span { font-size: 0.75rem; color: var(--text-muted); }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; }
        th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--primary); font-weight: 600; font-size: 0.7rem;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #faf5ff; }

        /* ── BADGES ── */
        .role-badge { padding: 4px 10px; border-radius: 50px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .role-driver { background: #d1fae5; color: #065f46; }
        .role-supervisor { background: #fed7aa; color: #92400e; }
        .role-admin { background: #e0e7ff; color: #3730a3; }

        .status-badge {
            padding: 4px 10px; border-radius: 50px; font-size: 0.7rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f1f5f9; color: #475569; }
        .status-suspended { background: #fee2e2; color: #991b1b; }

        .user-cell { display: flex; align-items: center; gap: 10px; }
        .user-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;
        }
        .user-cell strong { display: block; font-size: 0.85rem; color: var(--secondary); }
        .user-cell small { font-size: 0.7rem; color: var(--text-muted); }

        /* ── DROPDOWN ACTIONS ── */
        .actions-cell { position: relative; }
        .action-menu-btn {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 6px 12px; cursor: pointer; font-size: 0.75rem; color: var(--text-muted);
            display: inline-flex; align-items: center; gap: 6px; transition: 0.2s;
        }
        .action-menu-btn:hover { background: #f1f5f9; color: var(--primary); }
        .action-dropdown {
            display: none; position: absolute; right: 0; top: calc(100% + 4px);
            background: white; border: 1px solid #e2e8f0; border-radius: var(--radius);
            box-shadow: var(--shadow-lg); z-index: 500; min-width: 160px; overflow: hidden;
        }
        .action-dropdown.open { display: block; }
        .action-dropdown a, .action-dropdown button {
            display: flex; align-items: center; gap: 10px; padding: 10px 16px;
            width: 100%; text-align: left; font-size: 0.8rem; font-weight: 500;
            color: var(--text-main); text-decoration: none; background: none;
            border: none; cursor: pointer; transition: background 0.15s;
        }
        .action-dropdown a:hover, .action-dropdown button:hover { background: #faf5ff; }
        .action-dropdown .sep { border: none; border-top: 1px solid #e2e8f0; margin: 4px 0; }
        .action-dropdown .danger { color: var(--danger); }
        .action-dropdown .danger:hover { background: #fee2e2; }

        /* ── FLASH MESSAGES ── */
        .flash {
            padding: 12px 18px; border-radius: var(--radius); margin-bottom: 20px;
            font-size: 0.85rem; display: flex; align-items: center; gap: 10px;
        }
        .flash.success { background: #ecfdf5; color: #065f46; border-left: 4px solid var(--success); }
        .flash.error   { background: #fef2f2; color: #991b1b; border-left: 4px solid var(--danger); }

        /* ── MODAL ── */
        .modal-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(3px);
            z-index: 200; align-items: center; justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            border-radius: var(--radius-lg); padding: 32px; width: 100%; max-width: 500px;
            max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg);
            border: 1px solid rgba(139, 92, 246, 0.2); animation: slideUp 0.25s ease;
        }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; color: var(--secondary); }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); transition: 0.2s; }
        .modal-close:hover { color: var(--danger); }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.7rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: var(--radius);
            font-size: 0.85rem; font-family: 'Inter', sans-serif; outline: none; transition: 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: var(--primary); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-submit {
            width: 100%; padding: 12px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-bright) 100%);
            color: white; border: none; border-radius: var(--radius); font-size: 0.9rem;
            font-weight: 700; cursor: pointer; margin-top: 8px; transition: all 0.3s;
        }
        .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }

        .empty-state { text-align: center; padding: 50px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; display: block; opacity: 0.5; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 1000; }
            .main-content { margin-left: 0; width: 100%; padding: 20px; }
            .page-header { flex-direction: column; text-align: center; }
            .btn-primary { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="admin_dashboard.php" class="brand-logo"><span>🛢️</span> PetroTrack</a>
    </div>
    <ul class="nav-links">
        <li><a href="admin_dashboard.php"></i> Dashboard</a></li>
        <li><a href="vehicle_management.php"></i> Vehicle Management</a></li>
        <li><a href="delivery_management.php"></i> Delivery Orders</a></li>
        <li><a href="user_management.php" class="active"></i> User Management</a></li>
        <li><a href="station_management.php"></i> Station Management</a></li>
        <li><a href="tracking_map.php"></i> Simulated Tracking</a></li>
        <li><a href="messages.php"></i> Messages
            <?php
            $unread = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id = ? AND is_read = 0");
            $unread->bind_param("i", $_SESSION['user_id']);
            $unread->execute();
            $uc = $unread->get_result()->fetch_assoc()['c'];
            if ($uc > 0) echo '<span style="background:#ef4444;color:white;font-size:10px;padding:2px 7px;border-radius:10px;margin-left:8px;">' . $uc . '</span>';
            ?>
        </a></li>
        <li><a href="reports.php"></i> Reports</a></li>
        <li><a href="profile.php"></i> My Profile</a></li>
    </ul>
    <div class="user-profile">
        <div class="avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
        <div>
            <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($admin_name); ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?php echo getRoleLabel($_SESSION['role'] ?? ''); ?></div>
        </div>
    </div>
    <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<!-- ══ MAIN ══ -->
<main class="main-content">

    <div class="page-header">
        <h2><i  style="color: var(--primary);"></i> User Management</h2>
        <button class="btn-primary" onclick="openAddModal()">
            <i class="fas fa-user-plus"></i> Add New User
        </button>
    </div>

    <?php if ($message): ?>
        <div class="flash <?php echo $msgType; ?>">
            <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="stats-grid">
        <a href="user_management.php" class="stat-card purple">
            <div class="stat-title">Total Users</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
        </a>
        <a href="user_management.php?status=active" class="stat-card green">
            <div class="stat-title">Active</div>
            <div class="stat-value"><?php echo $active_users; ?></div>
        </a>
        <a href="user_management.php?status=suspended" class="stat-card red">
            <div class="stat-title">Suspended</div>
            <div class="stat-value"><?php echo $suspended_users; ?></div>
        </a>
        <a href="user_management.php?status=inactive" class="stat-card gray">
            <div class="stat-title">Inactive</div>
            <div class="stat-value"><?php echo $inactive_users; ?></div>
        </a>
        <a href="user_management.php?role=driver" class="stat-card teal">
            <div class="stat-title">Drivers</div>
            <div class="stat-value"><?php echo $driver_count; ?></div>
        </a>
        <a href="user_management.php?role=supervisor" class="stat-card orange">
            <div class="stat-title">Supervisors</div>
            <div class="stat-value"><?php echo $supervisor_count; ?></div>
        </a>
    </div>

    <!-- Toolbar: Search + Filters -->
    <div class="toolbar">
        <form method="GET" action="user_management.php" style="display:contents;">
            <?php if ($filter_role)   echo "<input type='hidden' name='role'   value='" . htmlspecialchars($filter_role) . "'>"; ?>
            <?php if ($filter_status) echo "<input type='hidden' name='status' value='" . htmlspecialchars($filter_status) . "'>"; ?>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search by name or email…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <button type="submit" class="btn-primary" style="padding:8px 20px;font-size:0.8rem;">Search</button>
            <?php if ($search): ?>
                <a href="user_management.php<?php echo $filter_role ? '?role='.$filter_role : ''; ?>" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">✕ Clear</a>
            <?php endif; ?>
        </form>

        <div class="divider"></div>
        <span class="filter-label">Role:</span>
        <a href="user_management.php<?php echo ($filter_status ? '?status='.$filter_status : '') . ($search ? ($filter_status ? '&' : '?').'search='.$search : ''); ?>" class="filter-btn <?php echo !$filter_role ? 'active' : ''; ?>">All</a>
        <a href="user_management.php?role=driver<?php echo $filter_status ? '&status='.$filter_status : ''; ?>" class="filter-btn <?php echo $filter_role=='driver' ? 'active' : ''; ?>">Driver</a>
        <a href="user_management.php?role=supervisor<?php echo $filter_status ? '&status='.$filter_status : ''; ?>" class="filter-btn <?php echo $filter_role=='supervisor' ? 'active' : ''; ?>">Supervisor</a>

        <div class="divider"></div>
        <span class="filter-label">Status:</span>
        <a href="user_management.php<?php echo $filter_role ? '?role='.$filter_role : ''; ?>" class="filter-btn <?php echo !$filter_status ? 'active' : ''; ?>">All</a>
        <a href="user_management.php?status=active<?php echo $filter_role ? '&role='.$filter_role : ''; ?>" class="filter-btn <?php echo $filter_status=='active' ? 'active' : ''; ?>">Active</a>
        <a href="user_management.php?status=suspended<?php echo $filter_role ? '&role='.$filter_role : ''; ?>" class="filter-btn <?php echo $filter_status=='suspended' ? 'active' : ''; ?>">Suspended</a>
        <a href="user_management.php?status=inactive<?php echo $filter_role ? '&role='.$filter_role : ''; ?>" class="filter-btn <?php echo $filter_status=='inactive' ? 'active' : ''; ?>">Inactive</a>
    </div>

    <!-- Users Table -->
    <div class="table-container">
        <div class="table-header">
            <strong> </i> Users</strong>
            <span><?php echo $result->num_rows; ?> result<?php echo $result->num_rows != 1 ? 's' : ''; ?></span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Station</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar"><?php echo strtoupper(substr($row['name'], 0, 1)); ?></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                        <small><?php echo htmlspecialchars($row['email']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $row['role']; ?>">
                                    <?php echo getRoleLabel($row['role']); ?>
                                </span>
                            </td>
                            <td><?php echo $row['station_name'] ? htmlspecialchars($row['station_name']) : '<span style="color:#ccc;">—</span>'; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $row['status']; ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted);font-size:0.75rem;"><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                            <td style="text-align:right;" class="actions-cell">
                                <?php if ($row['id'] != $_SESSION['user_id']): ?>
                                    <button class="action-menu-btn" onclick="toggleMenu(this)">
                                        Actions <i class="fas fa-chevron-down" style="font-size:0.7rem;"></i>
                                    </button>
                                    <div class="action-dropdown">
                                        <a href="#" onclick="openEditModal(<?php echo $row['id']; ?>); return false;">
                                            <i class="fas fa-pencil-alt" style="color:var(--primary);"></i> Edit User
                                        </a>
                                        <hr class="sep">
                                        <?php if ($row['status'] !== 'active'): ?>
                                            <a href="user_management.php?action=activate&id=<?php echo $row['id']; ?>">
                                                <i class="fas fa-check-circle" style="color:var(--success);"></i> Activate
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($row['status'] !== 'suspended'): ?>
                                            <a href="user_management.php?action=suspend&id=<?php echo $row['id']; ?>"
                                               onclick="return confirm('Suspend <?php echo addslashes($row['name']); ?>?')">
                                                <i class="fas fa-ban" style="color:var(--warning);"></i> Suspend
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($row['status'] !== 'inactive'): ?>
                                            <a href="user_management.php?action=deactivate&id=<?php echo $row['id']; ?>"
                                               onclick="return confirm('Deactivate <?php echo addslashes($row['name']); ?>?')">
                                                <i class="fas fa-moon" style="color:#94a3b8;"></i> Deactivate
                                            </a>
                                        <?php endif; ?>
                                        <hr class="sep">
                                        <a href="user_management.php?delete=<?php echo $row['id']; ?>" class="danger"
                                           onclick="return confirm('Permanently delete <?php echo addslashes($row['name']); ?>? This cannot be undone.')">
                                            <i class="fas fa-trash-alt"></i> Delete User
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);font-size:0.7rem;font-style:italic;">You</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fas fa-users-slash"></i>
                                No users found matching your filters.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

<!-- ══ EDIT USER MODAL (No Password Tab) ══ -->
<div class="modal-backdrop" id="editModalBackdrop">
    <div class="modal">
        <div class="modal-title">
            <i class="fas fa-user-edit" style="color: var(--primary);"></i> Edit User
            <button class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        <form method="POST" action="user_management.php">
            <input type="hidden" name="edit_user_id" id="edit_user_id">
            
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="edit_name" id="edit_name" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="edit_email" id="edit_email" required>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="edit_phone" id="edit_phone">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Role *</label>
                    <select name="edit_role" id="edit_role" required>
                        <option value="admin">Admin</option>
                        <option value="driver">Driver</option>
                        <option value="supervisor">Supervisor</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Station</label>
                    <select name="edit_station_id" id="edit_station_id">
                        <option value="0">— None —</option>
                        <?php foreach ($stations as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>

<!-- ══ ADD USER MODAL ══ -->
<div class="modal-backdrop" id="addModalBackdrop">
    <div class="modal">
        <div class="modal-title">
            <i class="fas fa-user-plus" style="color: var(--primary);"></i> Add New User
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <form method="POST" action="user_management.php">
            <input type="hidden" name="add_user" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="new_name" required>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="new_phone">
                </div>
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="new_email" required>
            </div>
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="new_password" required placeholder="Minimum 8 characters">
            </div>
            <div class="form-group">
                <label>Role *</label>
                <select name="new_role" id="new_role" required onchange="toggleNewStation(this)">
                    <option value="">Select role…</option>
                    <option value="admin">Admin</option>
                    <option value="driver">Driver</option>
                    <option value="supervisor">Supervisor</option>
                </select>
            </div>
            <div class="form-group" id="new_station_group" style="display:none;">
                <label>Station</label>
                <select name="new_station_id">
                    <option value="0">— None —</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-user-plus"></i> Create User</button>
        </form>
    </div>
</div>

<script>
const allUsers = <?php echo json_encode($all_users_js); ?>;

function toggleMenu(btn) {
    const dropdown = btn.nextElementSibling;
    const isOpen = dropdown.classList.contains('open');
    document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));
    if (!isOpen) dropdown.classList.add('open');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.actions-cell')) {
        document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});

function openEditModal(userId) {
    const u = allUsers[userId];
    if (!u) return;
    document.getElementById('edit_user_id').value = u.id;
    document.getElementById('edit_name').value = u.name;
    document.getElementById('edit_email').value = u.email;
    document.getElementById('edit_phone').value = u.phone || '';
    document.getElementById('edit_role').value = u.role;
    document.getElementById('edit_station_id').value = u.station_id || 0;
    // Password field removed, so we don't set it here
    document.getElementById('editModalBackdrop').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editModalBackdrop').classList.remove('open');
}

function openAddModal() {
    document.getElementById('new_role').value = '';
    document.getElementById('new_station_group').style.display = 'none';
    document.getElementById('addModalBackdrop').classList.add('open');
}

function closeAddModal() {
    document.getElementById('addModalBackdrop').classList.remove('open');
}

function toggleNewStation(select) {
    const stationGroup = document.getElementById('new_station_group');
    stationGroup.style.display = select.value === 'supervisor' ? 'block' : 'none';
}

document.getElementById('editModalBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
document.getElementById('addModalBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.opacity = '0', 4000);
</script>
</body>
</html>