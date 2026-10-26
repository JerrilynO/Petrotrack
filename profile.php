<?php
session_start();
include 'config/db_connect.php';
include 'auth_check.php';

if (!function_exists('getRoleLabel')) {
    function getRoleLabel($role) {
        switch($role) {
            case 'admin':      return 'Logistics Manager';
            case 'driver':     return 'Tanker Driver';
            case 'supervisor': return 'Station Supervisor';
            default:           return 'Staff';
        }
    }
}

 $user_id   = $_SESSION['user_id'];
 $user_role = $_SESSION['role'];
 $message   = "";
 $msgType   = "";

// ── FETCH FULL USER DATA ──────────────────────────────────────────────────────
 $stmt = $conn->prepare("SELECT u.*, s.name as station_name, s.location as station_location
                        FROM users u
                        LEFT JOIN stations s ON u.station_id = s.id
                        WHERE u.id = ?");
 $stmt->bind_param("i", $user_id);
 $stmt->execute();
 $user = $stmt->get_result()->fetch_assoc();
 $stmt->close();

 $initials    = strtoupper(substr($user['name'], 0, 1));
 $joined_date = date('d F Y', strtotime($user['created_at']));

// ── HANDLE PROFILE UPDATE ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name  = trim($_POST['name']  ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');

    if ($new_name && $new_email) {
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?");
        $stmt->bind_param("sssi", $new_name, $new_email, $new_phone, $user_id);
        if ($stmt->execute()) {
            $_SESSION['name'] = $new_name;
            $user['name']  = $new_name;
            $user['email'] = $new_email;
            $user['phone'] = $new_phone;
            $initials      = strtoupper(substr($new_name, 0, 1));
            $message = "Profile updated successfully.";
            $msgType = "success";
        } else {
            $message = "Error updating profile.";
            $msgType = "error";
        }
        $stmt->close();
    } else {
        $message = "Name and email are required.";
        $msgType = "error";
    }
}

// ── HANDLE PASSWORD CHANGE ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_password'] ?? '';
    $new_pw   = $_POST['new_password']     ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pw_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!password_verify($current, $pw_row['password'])) {
        $message = "Current password is incorrect.";
        $msgType = "error";
    } elseif (strlen($new_pw) < 6) {
        $message = "New password must be at least 6 characters.";
        $msgType = "error";
    } elseif ($new_pw !== $confirm) {
        $message = "New passwords do not match.";
        $msgType = "error";
    } else {
        $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $hashed, $user_id);
        if ($stmt->execute()) {
            $message = "Password changed successfully.";
            $msgType = "success";
        } else {
            $message = "Error changing password.";
            $msgType = "error";
        }
        $stmt->close();
    }
}

// ── HANDLE PIN SET / CHANGE (supervisor only) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_pin']) && $user_role === 'supervisor') {
    $new_pin     = $_POST['new_pin']     ?? '';
    $confirm_pin = $_POST['confirm_pin'] ?? '';
    $pw_verify   = $_POST['pin_password'] ?? '';

    // Re-fetch password for verification
    $pstmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $pstmt->bind_param("i", $user_id); $pstmt->execute();
    $prow = $pstmt->get_result()->fetch_assoc(); $pstmt->close();

    if (!password_verify($pw_verify, $prow['password'])) {
        $message = "Your account password is incorrect."; $msgType = "error";
    } elseif (!preg_match('/^\d{4}$/', $new_pin)) {
        $message = "PIN must be exactly 4 digits."; $msgType = "error";
    } elseif ($new_pin !== $confirm_pin) {
        $message = "PINs do not match."; $msgType = "error";
    } else {
        $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET verification_pin=? WHERE id=?");
        $upd->bind_param("si", $hashed_pin, $user_id);
        if ($upd->execute()) { $message = "Verification PIN updated successfully."; $msgType = "success"; }
        else { $message = "Error saving PIN."; $msgType = "error"; }
        $upd->close();
    }
}

// ── HANDLE ACCOUNT DEACTIVATION ───────────────────────────────────────────────
// ── HANDLE SECURITY QUESTION SET / CHANGE ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_security_question'])) {
    $question   = trim($_POST['security_question'] ?? '');
    $answer     = trim($_POST['security_answer']   ?? '');
    $pw_confirm = $_POST['sq_password'] ?? '';

    // Re-fetch password
    $pstmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $pstmt->bind_param("i", $user_id); $pstmt->execute();
    $prow = $pstmt->get_result()->fetch_assoc(); $pstmt->close();

    if (!password_verify($pw_confirm, $prow['password'])) {
        $message = "Your account password is incorrect."; $msgType = "error";
    } elseif (empty($question)) {
        $message = "Please select a security question."; $msgType = "error";
    } elseif (strlen($answer) < 2) {
        $message = "Please enter a valid answer."; $msgType = "error";
    } else {
        $hashed_answer = password_hash(strtolower($answer), PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET security_question=?, security_answer=? WHERE id=?");
        $upd->bind_param("ssi", $question, $hashed_answer, $user_id);
        if ($upd->execute()) {
            $message = "Security question saved successfully."; $msgType = "success";
        } else {
            $message = "Error saving security question."; $msgType = "error";
        }
        $upd->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_account'])) {
    // Set status to inactive
    $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        // Log the user out completely
        session_unset();
        session_destroy();
        
        // Redirect to login page (or a specific goodbye page)
        header("Location: login.php");
        exit();
    } else {
        $message = "Error deactivating account. Please contact support.";
        $msgType = "error";
    }
    $stmt->close();
}

// ── ROLE-SPECIFIC STATS ───────────────────────────────────────────────────────
 $stats = [];

if ($user_role === 'admin') {
    $stats['total_deliveries'] = $conn->query("SELECT COUNT(*) as c FROM deliveries")->fetch_assoc()['c'];
    $stats['total_trucks']     = $conn->query("SELECT COUNT(*) as c FROM vehicles")->fetch_assoc()['c'];
    $stats['total_drivers']    = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='driver'")->fetch_assoc()['c'];
    $stats['total_stations']   = $conn->query("SELECT COUNT(*) as c FROM stations")->fetch_assoc()['c'];

} elseif ($user_role === 'driver') {
    $truck_stmt = $conn->prepare("SELECT plate_number, capacity FROM vehicles WHERE driver_id = ?");
    $truck_stmt->bind_param("i", $user_id);
    $truck_stmt->execute();
    $stats['truck'] = $truck_stmt->get_result()->fetch_assoc();
    $truck_stmt->close();

    $del_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN d.delivery_status = 'delivered' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN d.delivery_status = 'flagged'   THEN 1 ELSE 0 END) as flagged
        FROM deliveries d
        JOIN vehicles v ON d.truck_id = v.id
        WHERE v.driver_id = ?
    ");
    $del_stmt->bind_param("i", $user_id);
    $del_stmt->execute();
    $stats['deliveries'] = $del_stmt->get_result()->fetch_assoc();
    $del_stmt->close();

} elseif ($user_role === 'supervisor') {
    $sup_station = $_SESSION['station_id'] ?? 0;
    $s_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN delivery_status IN ('pending','in_transit') THEN 1 ELSE 0 END) as active
        FROM deliveries WHERE station_id = ?
    ");
    $s_stmt->bind_param("i", $sup_station);
    $s_stmt->execute();
    $stats['deliveries'] = $s_stmt->get_result()->fetch_assoc();
    $s_stmt->close();
}

// ── CHECK PIN STATUS (supervisor only) ───────────────────────────────────────
$has_pin = false;
if ($user_role === 'supervisor') {
    $pchk = $conn->prepare("SELECT verification_pin FROM users WHERE id=?");
    $pchk->bind_param("i", $user_id); $pchk->execute();
    $prow2 = $pchk->get_result()->fetch_assoc(); $pchk->close();
    $has_pin = !empty($prow2['verification_pin']);
}

// ── CHECK SECURITY QUESTION STATUS ───────────────────────────────────────────
$has_security_q = false;
$current_security_q = '';
$sq_chk = $conn->prepare("SELECT security_question FROM users WHERE id=?");
$sq_chk->bind_param("i", $user_id); $sq_chk->execute();
$sq_row = $sq_chk->get_result()->fetch_assoc(); $sq_chk->close();
if (!empty($sq_row['security_question'])) {
    $has_security_q    = true;
    $current_security_q = $sq_row['security_question'];
}

// ── DASHBOARD LINK ────────────────────────────────────────────────────────────
 $dashboard_link = match($user_role) {
    'admin'      => 'admin_dashboard.php',
    'driver'     => 'driver_dashboard.php',
    'supervisor' => 'supervisor_dashboard.php',
    default      => 'index.php'
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — PetroTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary: #0986B8;
    --primary-light: #7c2d9e;
    --secondary: #0f172a;
    --bg: #f8fafc;
    --card: #ffffff;
    --text: #1e293b;
    --muted: #64748b;
    --border: #e2e8f0;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --shadow: 0 4px 6px -1px rgb(0 0 0/0.1),0 2px 4px -2px rgb(0 0 0/0.1);
    --shadow-lg: 0 20px 25px -5px rgb(0 0 0/0.1),0 8px 10px -6px rgb(0 0 0/0.1);
    --radius: 12px;
    --radius-lg: 20px;
    --sidebar: 260px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#f3e8ff 50%,#f8fafc 100%);color:var(--text);display:flex;min-height:100vh;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar);background:linear-gradient(135deg,#fff 0%,#faf5ff 100%);height:100vh;position:fixed;left:0;top:0;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-header{padding:25px 20px;border-bottom:1px solid var(--border);}
.brand-logo{font-size:1.2rem;font-weight:800;color:var(--secondary);text-decoration:none;display:flex;align-items:center;gap:8px;}
.brand-logo span{color:var(--primary);}
.nav-links{list-style:none;padding:20px 0;flex:1;}
.nav-links li a{display:flex;align-items:center;padding:12px 25px;color:var(--muted);text-decoration:none;font-weight:500;transition:all 0.3s;gap:12px;font-size:0.9rem;}
.nav-links li a:hover,.nav-links li a.active{color:var(--primary);background:#faf5ff;border-right:4px solid var(--primary);}
.user-profile{padding:20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.avatar-sm{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;flex-shrink:0;}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:calc(100% - 40px);margin:0 20px 20px;background:var(--secondary);color:#fff;border:none;padding:12px;border-radius:50px;font-size:0.85rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.2s;}
.btn-logout:hover{background:var(--primary);transform:translateY(-2px);}

/* ── MAIN ── */
.main-content{margin-left:var(--sidebar);flex:1;padding:30px;width:calc(100% - var(--sidebar));overflow-y:auto;}

/* ── PROFILE HERO ── */
.profile-hero{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-light) 100%);border-radius:var(--radius-lg);padding:40px;color:#fff;display:flex;align-items:center;gap:30px;margin-bottom:30px;position:relative;overflow:hidden;}
.profile-hero::before{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,0.07);top:-100px;right:-100px;}
.profile-hero::after{content:'';position:absolute;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,0.05);bottom:-60px;left:-60px;}
.hero-avatar{width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,0.2);border:3px solid rgba(255,255,255,0.4);display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:800;color:#fff;flex-shrink:0;position:relative;z-index:1;}
.hero-info{position:relative;z-index:1;}
.hero-info h1{font-size:26px;font-weight:800;margin-bottom:6px;letter-spacing:-0.5px;}
.hero-info p{font-size:14px;opacity:0.85;margin-bottom:12px;}
.hero-badges{display:flex;gap:10px;flex-wrap:wrap;}
.badge{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);}
.badge.active{background:rgba(16,185,129,0.25);border-color:rgba(16,185,129,0.4);}
.badge.suspended{background:rgba(239,68,68,0.25);border-color:rgba(239,68,68,0.4);}
.badge.inactive{background:rgba(148,163,184,0.25);border-color:rgba(148,163,184,0.4);}

/* ── STATS ROW ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:30px;}
.stat-card{background:var(--card);border-radius:var(--radius-lg);padding:22px;box-shadow:var(--shadow);border-top:4px solid transparent;transition:0.2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
.stat-card.purple{border-color:var(--primary);}
.stat-card.green{border-color:var(--success);}
.stat-card.red{border-color:var(--danger);}
.stat-card.blue{border-color:#3b82f6;}
.stat-card.orange{border-color:var(--warning);}
.stat-title{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;}
.stat-value{font-size:28px;font-weight:800;color:var(--secondary);}

/* ── CONTENT GRID ── */
.content-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
.content-grid.single{grid-template-columns:1fr;}

/* ── CARDS ── */
.card{background:var(--card);border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid rgba(79,4,112,0.08);overflow:hidden;}
.card-header{padding:20px 25px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.card-header h3{font-size:16px;font-weight:700;color:var(--secondary);}
.card-header i{color:var(--primary);}
.card-body{padding:25px;}

/* ── FORM ── */
.fg{margin-bottom:18px;}
.fg label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:0.3px;}
.fg input{width:100%;padding:12px 15px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:#f8fafc;font-family:'Inter',sans-serif;transition:all 0.2s;}
.fg input:focus{outline:none;border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px rgba(79,4,112,0.08);}
.fg input:disabled{background:#f1f5f9;color:var(--muted);cursor:not-allowed;}
.fg input::placeholder{color:#94a3b8;}
.btn-save{width:100%;padding:13px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.2s;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-save:hover{opacity:0.9;transform:translateY(-1px);box-shadow:0 4px 14px rgba(79,4,112,0.3);}
.btn-danger{background:var(--danger);}
.btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3);}

/* ── INFO LIST ── */
.info-list{display:flex;flex-direction:column;gap:0;}
.info-item{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid var(--border);}
.info-item:last-child{border-bottom:none;}
.info-label{font-size:13px;color:var(--muted);font-weight:500;display:flex;align-items:center;gap:8px;}
.info-label i{width:16px;color:var(--primary);}
.info-value{font-size:14px;font-weight:600;color:var(--text);text-align:right;}

/* ── TRUCK CARD ── */
.truck-info{background:linear-gradient(135deg,#f8fafc,#faf5ff);border-radius:12px;padding:20px;text-align:center;border:1px solid rgba(79,4,112,0.1);}
.truck-icon{font-size:40px;margin-bottom:12px;}
.truck-plate{font-size:22px;font-weight:800;color:var(--secondary);letter-spacing:1px;margin-bottom:4px;}
.truck-cap{font-size:13px;color:var(--muted);}

/* ── STATION CARD ── */
.station-info{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:12px;padding:20px;border:1px solid rgba(16,185,129,0.2);}
.station-name{font-size:20px;font-weight:800;color:#065f46;margin-bottom:4px;}
.station-loc{font-size:13px;color:#047857;display:flex;align-items:center;gap:6px;}

/* ── ALERT ── */
.alert{padding:14px 18px;border-radius:10px;margin-bottom:24px;font-size:14px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

@media(max-width:900px){
    .sidebar{transform:translateX(-100%);}
    .main-content{margin-left:0;width:100%;padding:20px;}
    .content-grid{grid-template-columns:1fr;}
    .profile-hero{flex-direction:column;text-align:center;}
    .stats-row{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="<?php echo $dashboard_link; ?>" class="brand-logo">
            <span>🛢️</span> PetroTrack
        </a>
    </div>
    <ul class="nav-links">
        <?php if ($user_role === 'admin'): ?>
            <li><a href="admin_dashboard.php"></i> Dashboard</a></li>
            <li><a href="vehicle_management.php"></i> Vehicle Management</a></li>
            <li><a href="delivery_management.php"></i> Delivery Orders</a></li>
            <li><a href="user_management.php"></i> User Management</a></li>
            <li><a href="station_management.php"></i> Station Management</a></li>
            <li><a href="tracking_map.php"></i> Simulated Tracking</a></li>
            <li><a href="messages.php"></i> Messages</a></li>
            <li><a href="reports.php"></i> Reports</a></li>
        <?php elseif ($user_role === 'driver'): ?>
            <li><a href="driver_dashboard.php"></i> My Deliveries</a></li>
            <li><a href="messages.php"></i> Messages</a></li>
        <?php elseif ($user_role === 'supervisor'): ?>
            <li><a href="supervisor_dashboard.php"></i> Station Dashboard</a></li>
            <li><a href="messages.php"></i> Messages</a></li>
        <?php endif; ?>
        <li><a href="profile.php" class="active"></i> My Profile</a></li>
    </ul>
    <div class="user-profile">
        <div class="avatar-sm"><?php echo $initials; ?></div>
        <div>
            <div style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($user['name']); ?></div>
            <div style="font-size:11px;color:var(--muted);"><?php echo getRoleLabel($user_role); ?></div>
        </div>
    </div>
    <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<!-- ── MAIN ── -->
<main class="main-content">

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $msgType; ?>">
            <?php echo $msgType === 'success' ? '' : ''; ?>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- HERO -->
    <div class="profile-hero">
        <div class="hero-avatar"><?php echo $initials; ?></div>
        <div class="hero-info">
            <h1><?php echo htmlspecialchars($user['name']); ?></h1>
            <p><?php echo htmlspecialchars($user['email']); ?> &nbsp;·&nbsp; Member since <?php echo $joined_date; ?></p>
            <div class="hero-badges">
                <span class="badge"><?php echo getRoleLabel($user_role); ?></span>
                <span class="badge <?php echo $user['status']; ?>">
                    <?php echo ucfirst($user['status']); ?>
                </span>
                <?php if ($user_role === 'supervisor' && $user['station_name']): ?>
                    <span class="badge">⛽ <?php echo htmlspecialchars($user['station_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ROLE-SPECIFIC STATS -->
    <?php if ($user_role === 'admin'): ?>
    <div class="stats-row">
        <div class="stat-card purple">
            <div class="stat-title">Total Deliveries</div>
            <div class="stat-value"><?php echo $stats['total_deliveries']; ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-title">Fleet Size</div>
            <div class="stat-value"><?php echo $stats['total_trucks']; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-title">Active Drivers</div>
            <div class="stat-value"><?php echo $stats['total_drivers']; ?></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-title">Stations</div>
            <div class="stat-value"><?php echo $stats['total_stations']; ?></div>
        </div>
    </div>

    <?php elseif ($user_role === 'driver'): ?>
    <div class="stats-row">
        <div class="stat-card purple">
            <div class="stat-title">Total Trips</div>
            <div class="stat-value"><?php echo $stats['deliveries']['total'] ?? 0; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-title">Completed</div>
            <div class="stat-value"><?php echo $stats['deliveries']['completed'] ?? 0; ?></div>
        </div>
        <div class="stat-card red">
            <div class="stat-title">Flagged</div>
            <div class="stat-value"><?php echo $stats['deliveries']['flagged'] ?? 0; ?></div>
        </div>
    </div>

    <?php elseif ($user_role === 'supervisor'): ?>
    <div class="stats-row">
        <div class="stat-card purple">
            <div class="stat-title">Total Deliveries</div>
            <div class="stat-value"><?php echo $stats['deliveries']['total'] ?? 0; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-title">Completed</div>
            <div class="stat-value"><?php echo $stats['deliveries']['completed'] ?? 0; ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-title">Active / Pending</div>
            <div class="stat-value"><?php echo $stats['deliveries']['active'] ?? 0; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- CONTENT GRID -->
    <div class="content-grid">

        <!-- LEFT: EDIT PROFILE -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user-edit"></i>
                <h3>Edit Profile</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="profile.php">
                    <div class="fg">
                        <label>Full Name</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    <div class="fg">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="fg">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+234 80X XXX XXXX">
                    </div>
                    <div class="fg">
                        <label>Role</label>
                        <input type="text" value="<?php echo getRoleLabel($user_role); ?>" disabled>
                    </div>
                    <div class="fg">
                        <label>Member Since</label>
                        <input type="text" value="<?php echo $joined_date; ?>" disabled>
                    </div>
                    <button type="submit" name="update_profile" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- RIGHT: ROLE INFO + PASSWORD + DEACTIVATION -->
        <div style="display:flex;flex-direction:column;gap:24px;">

            <!-- ROLE-SPECIFIC INFO CARD -->
            <?php if ($user_role === 'driver'): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-truck"></i>
                    <h3>Assigned Truck</h3>
                </div>
                <div class="card-body">
                    <?php if ($stats['truck']): ?>
                        <div class="truck-info">
                            <div class="truck-icon"></div>
                            <div class="truck-plate"><?php echo htmlspecialchars($stats['truck']['plate_number']); ?></div>
                            <div class="truck-cap">Capacity: <?php echo number_format($stats['truck']['capacity']); ?> Litres</div>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center;padding:20px;color:var(--muted);">
                            <i class="fas fa-truck" style="font-size:30px;opacity:0.3;display:block;margin-bottom:10px;"></i>
                            No truck assigned yet
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($user_role === 'supervisor'): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-gas-pump"></i>
                    <h3>My Station</h3>
                </div>
                <div class="card-body">
                    <?php if ($user['station_name']): ?>
                        <div class="station-info">
                            <div class="station-name">⛽ <?php echo htmlspecialchars($user['station_name']); ?></div>
                            <div class="station-loc">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($user['station_location']); ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center;padding:20px;color:var(--muted);">
                            <i class="fas fa-gas-pump" style="font-size:30px;opacity:0.3;display:block;margin-bottom:10px;"></i>
                            No station assigned yet
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($user_role === 'admin'): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-shield-alt"></i>
                    <h3>Account Info</h3>
                </div>
                <div class="card-body">
                    <div class="info-list">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user-tag"></i> Role</span>
                            <span class="info-value"><?php echo getRoleLabel($user_role); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-circle"></i> Status</span>
                            <span class="info-value" style="color:<?php echo $user['status']==='active'?'#10b981':'#ef4444'; ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar"></i> Joined</span>
                            <span class="info-value"><?php echo $joined_date; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-truck"></i> Trucks Managed</span>
                            <span class="info-value"><?php echo $stats['total_trucks']; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-gas-pump"></i> Stations</span>
                            <span class="info-value"><?php echo $stats['total_stations']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- CHANGE PASSWORD -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-lock"></i>
                    <h3>Change Password</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="profile.php">
                        <div class="fg">
                            <label>Current Password</label>
                            <input type="password" name="current_password" placeholder="Enter current password" required>
                        </div>
                        <div class="fg">
                            <label>New Password</label>
                            <input type="password" name="new_password" placeholder="At least 6 characters" required>
                        </div>
                        <div class="fg">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" placeholder="Repeat new password" required>
                        </div>
                        <button type="submit" name="change_password" class="btn-save" style="background:linear-gradient(135deg,#0f172a,#1e293b);">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- VERIFICATION PIN (supervisor only) -->
            <?php if ($user_role === 'supervisor'): ?>
            <div class="card" style="border:1px solid rgba(79,4,112,0.2);">
                <div class="card-header">
                    <i class="fas fa-lock" style="color:var(--primary);"></i>
                    <h3>Verification PIN</h3>
                </div>
                <div class="card-body">
                    <?php if ($has_pin): ?>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#065f46;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-check-circle"></i> PIN is set. You can update it below.
                    </div>
                    <?php else: ?>
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#92400e;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-exclamation-triangle"></i> No PIN set yet. You need a PIN to confirm deliveries.
                    </div>
                    <?php endif; ?>
                    <p style="font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.6;">
                        Your 4-digit PIN is used to authorise delivery verifications on the supervisor dashboard. Keep it private — it acts as your digital sign-off.
                    </p>
                    <form method="POST" action="profile.php">
                        <div class="fg">
                            <label>Account Password (to confirm identity)</label>
                            <input type="password" name="pin_password" placeholder="Enter your login password" required>
                        </div>
                        <div class="fg">
                            <label>New 4-digit PIN</label>
                            <input type="password" name="new_pin" id="newPin"
                                   maxlength="4" pattern="\d{4}" placeholder="e.g. 1234"
                                   oninput="this.value=this.value.replace(/\D/g,'')"
                                   autocomplete="new-password" required
                                   style="letter-spacing:8px;font-size:20px;font-weight:800;text-align:center;">
                        </div>
                        <div class="fg">
                            <label>Confirm New PIN</label>
                            <input type="password" name="confirm_pin" id="confirmPin"
                                   maxlength="4" pattern="\d{4}" placeholder="Repeat PIN"
                                   oninput="this.value=this.value.replace(/\D/g,'');checkPinMatch()"
                                   autocomplete="new-password" required
                                   style="letter-spacing:8px;font-size:20px;font-weight:800;text-align:center;">
                            <div id="pin-match-msg" style="font-size:12px;margin-top:6px;"></div>
                        </div>
                        <button type="submit" name="set_pin" class="btn-save" style="background:linear-gradient(135deg,var(--primary),#7c2d9e);">
                            <i class="fas fa-key"></i> <?php echo $has_pin ? 'Update PIN' : 'Set PIN'; ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!--  SECURITY QUESTION -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-shield-alt"></i>
                    <h3>Security Question</h3>
                </div>
                <div class="card-body">
                    <?php if ($has_security_q): ?>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#065f46;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-check-circle"></i>
                        <span>Security question is set: <strong><?php echo htmlspecialchars($current_security_q); ?></strong></span>
                    </div>
                    <?php else: ?>
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#92400e;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>No security question set. You need one to use the <strong>Forgot Password</strong> feature.</span>
                    </div>
                    <?php endif; ?>
                    <p style="font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.6;">
                        Your security question is used to verify your identity if you ever forget your password. Choose something only you would know. The answer is case-insensitive.
                    </p>
                    <form method="POST" action="profile.php">
                        <div class="fg">
                            <label>Account Password (to confirm identity)</label>
                            <input type="password" name="sq_password" placeholder="Enter your login password" required>
                        </div>
                        <div class="fg">
                            <label>Security Question</label>
                            <select name="security_question" required style="width:100%;padding:12px 15px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:#f8fafc;font-family:'Inter',sans-serif;transition:all 0.2s;outline:none;">
                                <option value="" disabled <?php echo !$has_security_q ? 'selected' : ''; ?>>— Choose a question —</option>
                                <?php
                                $questions = [
                                    "What was the name of your first pet?",
                                    "What is your mother's maiden name?",
                                    "What city were you born in?",
                                    "What was the name of your primary school?",
                                    "What is the name of the street you grew up on?",
                                    "What was your childhood nickname?",
                                    "What is your oldest sibling's middle name?",
                                    "What was the make of your first car?",
                                ];
                                foreach ($questions as $q):
                                    $sel = ($current_security_q === $q) ? 'selected' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($q); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($q); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fg">
                            <label>Your Answer</label>
                            <input type="text" name="security_answer" placeholder="Type your answer (case-insensitive)" required autocomplete="off">
                        </div>
                        <button type="submit" name="set_security_question" class="btn-save" style="background:linear-gradient(135deg,#0369a1,#0284c7);">
                            <i class="fas fa-shield-alt"></i> <?php echo $has_security_q ? 'Update Security Question' : 'Set Security Question'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!--  DEACTIVATE ACCOUNT -->
            <div class="card" style="border:0.5px solid var(--danger);">
                <div class="card-header">
                    <i  style="color:var(--danger)"></i>
                    <h3 style="color:var(--danger)">ACCOUNT STATUS</h3>
                </div>
                <div class="card-body">
                    <p style="margin-top: -10px; margin-bottom:10px;color:var(--muted);font-size:14px;">
                        Deactivating your account will sign you out immediately. You will not be able to log back in until an administrator reactivates your account.
                    </p>
                    <form method="POST" action="profile.php" onsubmit="return confirm('Are you absolutely sure you want to deactivate your account? This action cannot be undone.');">
                        <button type="submit" name="deactivate_account" class="btn-save btn-danger">
                            <i ></i> Deactivate My Account
                        </button>
                    </form>
                </div>
            </div>

        </div><!-- end right col -->
    </div><!-- end content-grid -->

</main>
<script>
function checkPinMatch() {
    var p1 = document.getElementById('newPin');
    var p2 = document.getElementById('confirmPin');
    var msg = document.getElementById('pin-match-msg');
    if (!p1 || !p2 || !msg) return;
    if (p2.value.length === 0) { msg.textContent = ''; return; }
    if (p1.value === p2.value) {
        msg.textContent = '✓ PINs match';
        msg.style.color = '#10b981';
    } else {
        msg.textContent = '✗ PINs do not match';
        msg.style.color = '#ef4444';
    }
}
</script>
</body>
</html>