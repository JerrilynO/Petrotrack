<?php
session_start();
include 'config/db_connect.php';
$required_role = 'driver';
include 'auth_check.php';

if (!function_exists('getRoleLabel')) {
    function getRoleLabel($role) {
        switch($role) {
            case 'admin': return 'Administrator';
            case 'driver': return 'Driver';
            case 'supervisor': return 'Supervisor';
            default: return 'Staff';
        }
    }
}

// CHECK DRIVER STATUS
$status_check = $conn->prepare("SELECT status, status_reason FROM users WHERE id = ?");
$status_check->bind_param("i", $_SESSION['user_id']);
$status_check->execute();
$status_data = $status_check->get_result()->fetch_assoc();
$status_check->close();
$is_suspended = ($status_data && $status_data['status'] === 'suspended');

$message = ""; $msgType = "";

// FETCH ALL ASSIGNED DELIVERIES (Removes strict LIMIT 1 to stack pending tasks)
$stmt = $conn->prepare("SELECT d.*, v.plate_number, v.capacity
    FROM deliveries d JOIN vehicles v ON d.truck_id = v.id
    WHERE v.driver_id = ?
    AND d.delivery_status IN ('pending','in_transit','awaiting_verification','flagged')
    ORDER BY FIELD(d.delivery_status, 'in_transit', 'flagged', 'awaiting_verification', 'pending'), d.id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$all_deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Separate the currently active running trip from queued pending trips
$active_trip = null;
$pending_trips = [];

foreach ($all_deliveries as $deliv) {
    if ($deliv['delivery_status'] === 'pending') {
        $pending_trips[] = $deliv;
    } else {
        // If multiple active statuses exist by anomaly, prioritize the first found
        if (!$active_trip) { $active_trip = $deliv; }
    }
}

// HANDLE START TRIP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['start_trip']) && !$is_suspended) {
    $target_id = (int)$_POST['trip_id'];
    // Ensure driver doesn't already have an active trip ongoing
    if ($active_trip) {
        $message = "You already have an active trip in transit or awaiting verification!";
        $msgType = "error";
    } else {
        $upd = $conn->prepare("UPDATE deliveries SET delivery_status='in_transit' WHERE id=? AND delivery_status='pending'");
        $upd->bind_param("i", $target_id);
        if ($upd->execute()) { $message = "Trip started! Drive safely."; $msgType = "success"; }
        $upd->close();
        echo "<script>setTimeout(()=>location.reload(),1000)</script>";
    }
}

// HANDLE UPDATE LOCATION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['move_truck']) && !$is_suspended) {
    $ts = $conn->prepare("SELECT id, latitude, longitude FROM vehicles WHERE driver_id=?");
    $ts->bind_param("i", $_SESSION['user_id']); $ts->execute();
    $td = $ts->get_result()->fetch_assoc(); $ts->close();
    if ($td) {
        $lat = ($td['latitude'] ?? null) == null ? 6.4698 : $td['latitude'] + (rand(0,10)-5)/1000;
        $lng = ($td['longitude'] ?? null) == null ? 3.3855 : $td['longitude'] + (rand(0,10)-5)/1000;
        $upd = $conn->prepare("UPDATE vehicles SET latitude=?,longitude=? WHERE id=?");
        $upd->bind_param("ddi",$lat,$lng,$td['id']); $upd->execute(); $upd->close();
        $message = "Location updated."; $msgType = "success";
    }
}

// HANDLE REPORT DELAY
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['report_delay']) && !$is_suspended) {
    if ($active_trip) {
        $reason = trim($_POST['delay_reason'] ?? '');
        if ($reason) {
            $note = $conn->prepare("INSERT INTO delivery_notes (delivery_id, note_type, note_text, reported_by) VALUES (?, 'delay', ?, ?)");
            $note->bind_param("isi", $active_trip['id'], $reason, $_SESSION['user_id']);
            $note->execute(); $note->close();
            $message = "Delay reported and logged."; $msgType = "info";
        }
    }
}

// HANDLE SUBMIT DELIVERY
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_delivery']) && !$is_suspended) {
    if ($active_trip && $active_trip['delivery_status'] == 'in_transit') {
        $driver_vol = (int)$_POST['driver_vol'];
        if ($driver_vol > 0) {
            $upd = $conn->prepare("UPDATE deliveries SET delivery_status='awaiting_verification', driver_reported_vol=?, volume_delivered=? WHERE id=?");
            $upd->bind_param("iii", $driver_vol, $driver_vol, $active_trip['id']);
            if ($upd->execute()) { $message = "Delivery submitted! Waiting for station supervisor to verify."; $msgType = "success"; }
            $upd->close();
            echo "<script>setTimeout(()=>location.reload(),1500)</script>";
        } else { $message = "Enter a valid volume."; $msgType = "error"; }
    }
}

// UNREAD MESSAGES
$um = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i",$_SESSION['user_id']); $um->execute();
$unread_count = $um->get_result()->fetch_assoc()['c']; $um->close();

// ASSIGNED TRUCK
$tc = $conn->prepare("SELECT plate_number, capacity FROM vehicles WHERE driver_id=?");
$tc->bind_param("i",$_SESSION['user_id']); $tc->execute();
$assigned_truck = $tc->get_result()->fetch_assoc(); $tc->close();

// STATS
$uid = $_SESSION['user_id'];
$stat_pending = $conn->prepare("SELECT COUNT(*) as c FROM deliveries d JOIN vehicles v ON d.truck_id=v.id WHERE v.driver_id=? AND d.delivery_status='pending'");
$stat_pending->bind_param("i",$uid); $stat_pending->execute();
$n_pending = $stat_pending->get_result()->fetch_assoc()['c']; $stat_pending->close();

$stat_transit = $conn->prepare("SELECT COUNT(*) as c FROM deliveries d JOIN vehicles v ON d.truck_id=v.id WHERE v.driver_id=? AND d.delivery_status='in_transit'");
$stat_transit->bind_param("i",$uid); $stat_transit->execute();
$n_transit = $stat_transit->get_result()->fetch_assoc()['c']; $stat_transit->close();

$stat_done = $conn->prepare("SELECT COUNT(*) as c FROM deliveries d JOIN vehicles v ON d.truck_id=v.id WHERE v.driver_id=? AND d.delivery_status='delivered' AND DATE(d.delivery_date)=CURDATE()");
$stat_done->bind_param("i",$uid); $stat_done->execute();
$n_done = $stat_done->get_result()->fetch_assoc()['c']; $stat_done->close();

$stat_total = $conn->prepare("SELECT COUNT(*) as c FROM deliveries d JOIN vehicles v ON d.truck_id=v.id WHERE v.driver_id=? AND d.delivery_status='delivered'");
$stat_total->bind_param("i",$uid); $stat_total->execute();
$n_total = $stat_total->get_result()->fetch_assoc()['c']; $stat_total->close();

// RECENT HISTORY
$hist = $conn->prepare("SELECT d.id, d.destination, d.source, d.product_type, d.volume_loaded, d.driver_reported_vol, d.station_reported_vol, d.delivery_status, d.delivery_date
    FROM deliveries d JOIN vehicles v ON d.truck_id=v.id
    WHERE v.driver_id=? AND d.delivery_status IN ('delivered','cancelled','flagged')
    ORDER BY d.id DESC LIMIT 5");
$hist->bind_param("i",$_SESSION['user_id']); $hist->execute();
$history = $hist->get_result()->fetch_all(MYSQLI_ASSOC); $hist->close();

$greeting = date('G')<12 ? 'Morning' : (date('G')<18 ? 'Afternoon' : 'Evening');
$products = ['PMS'=>'PMS (Petrol)','AGO'=>'AGO (Diesel)','DPK'=>'DPK (Kerosene)','ATK'=>'ATK (Aviation)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Driver Dashboard - PetroTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── MATCHING SUPERVISOR DESIGN EXACTLY ── */
:root{--primary:#4f0470;--primary-dark:#3a0552;--secondary:#0f172a;--bg:#f8fafc;--muted:#64748b;--border:#e2e8f0;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--info:#3b82f6;--radius:12px;--radius-lg:20px;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#f3e8ff 50%,#f8fafc 100%);color:#1e293b;min-height:100vh;}

/* NAV — identical to supervisor */
nav{background:white;border-bottom:1px solid var(--border);padding:0 5%;height:64px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100;}
.nav-logo{font-size:1.1rem;font-weight:800;color:var(--secondary);text-decoration:none;display:flex;align-items:center;gap:8px;}
.nav-right{display:flex;align-items:center;gap:12px;}
.nav-right a{text-decoration:none;background:#f1f5f9;color:var(--secondary);padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:.2s;}
.nav-right a:hover{background:#faf5ff;color:var(--primary);}
.nav-right a.logout{background:#fef2f2;color:#dc2626;}
.nav-right a.logout:hover{background:#fee2e2;}
.badge{background:#dc2626;color:white;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;}

/* CONTAINER — matches supervisor 1100px */
.container{max-width:1100px;margin:0 auto;padding:32px 20px;}
.page-title{font-size:22px;font-weight:800;color:var(--secondary);margin-bottom:4px;}
.page-sub{font-size:14px;color:var(--muted);margin-bottom:28px;}

/* ALERTS */
.alert{padding:14px 18px;border-radius:var(--radius);margin-bottom:22px;font-size:14px;font-weight:500;border-left:4px solid;display:flex;align-items:flex-start;gap:10px;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#22c55e;}
.alert-info{background:#eff6ff;color:#1e40af;border-color:var(--info);}
.alert-error{background:#fef2f2;color:#991b1b;border-color:var(--danger);}

/* INVESTIGATION BANNER */
.investigation-banner{background:linear-gradient(135deg,#7f1d1d,#b91c1c);color:white;border-radius:var(--radius-lg);padding:28px 32px;margin-bottom:24px;box-shadow:0 8px 25px rgba(239,68,68,.3);position:relative;overflow:hidden;}
.investigation-banner::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);}
.investigation-banner::after{content:'';position:absolute;bottom:-40px;left:120px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.04);}
.investigation-banner h3{font-size:1.2rem;font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:10px;position:relative;}
.investigation-banner p{font-size:.875rem;opacity:.9;line-height:1.7;position:relative;}
.investigation-banner .reason-box{background:rgba(0,0,0,.25);border-radius:8px;padding:12px 16px;margin-top:14px;font-size:.83rem;position:relative;}

/* STATS — identical to supervisor */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;}
.stat-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px 24px;box-shadow:var(--shadow);transition:.2s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.08);}
.stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;}
.stat-number{font-size:32px;font-weight:800;color:var(--secondary);line-height:1;}
.stat-number.highlight{color:var(--primary);}

/* SECTION TITLE — identical to supervisor */
.section-title{font-size:15px;font-weight:700;color:var(--secondary);margin-bottom:16px;display:flex;align-items:center;gap:8px;}

/* DELIVERY CARD — identical to supervisor */
.delivery-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:26px;margin-bottom:16px;box-shadow:var(--shadow);transition:.2s;}
.delivery-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.08);}
.delivery-card.active-trip{border-left:5px solid var(--primary);background:linear-gradient(135deg,white 0%,#fdf9ff 100%);}
.delivery-card.in-transit{border-left:5px solid var(--info);}
.delivery-card.awaiting{border-left:5px solid var(--warning);}
.delivery-card.flagged-card{border-left:5px solid var(--danger);background:linear-gradient(135deg,white 0%,#fff5f5 100%);}
.delivery-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.delivery-id-label{font-size:11px;color:var(--muted);margin-bottom:3px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.delivery-title{font-size:18px;font-weight:800;color:var(--secondary);}
.status{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.status-pending{background:#fff7ed;color:#9a3412;}
.status-in_transit{background:#eff6ff;color:#1d4ed8;}
.status-awaiting{background:#fef9c3;color:#854d0e;border:1px solid #fde047;}
.status-flagged{background:#fee2e2;color:#991b1b;}

/* DELIVERY GRID — identical to supervisor */
.delivery-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:20px;}
.detail-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:3px;}
.detail-value{font-size:14px;font-weight:600;color:var(--secondary);}

/* ACTION BOX — styled like supervisor's verify-box */
.action-box{background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius);padding:22px;}
.action-box .ab-title{font-size:14px;font-weight:700;color:var(--secondary);margin-bottom:4px;display:flex;align-items:center;gap:8px;}
.action-box .ab-sub{font-size:12px;color:var(--muted);margin-bottom:18px;}
.btn-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;}
.btn-row.single{grid-template-columns:1fr;}
.btn{width:100%;padding:12px 16px;border:none;border-radius:var(--radius);font-size:13px;font-weight:700;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn:hover{filter:brightness(.93);transform:translateY(-1px);}
.btn-primary{background:var(--primary);color:white;}
.btn-dark{background:var(--secondary);color:white;}
.btn-warning{background:var(--warning);color:white;}
.btn-success{background:var(--success);color:white;}
.btn-danger-soft{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* SUBMIT VOLUME INPUT */
.vol-input-wrap{display:flex;align-items:center;gap:12px;background:white;border:1px solid #bbf7d0;border-radius:var(--radius);padding:14px;margin-bottom:14px;}
.vol-input-wrap label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);white-space:nowrap;}
.vol-input-wrap input{flex:1;border:none;outline:none;font-size:16px;font-weight:700;color:var(--secondary);background:transparent;}

/* AWAITING PANEL */
.awaiting-panel{background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius);padding:28px;text-align:center;}
.spinner{width:40px;height:40px;border:4px solid #fde68a;border-top-color:var(--warning);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 14px;}
@keyframes spin{to{transform:rotate(360deg);}}
.awaiting-panel h4{font-size:15px;font-weight:700;color:#92400e;margin-bottom:6px;}
.awaiting-panel p{font-size:13px;color:#b45309;line-height:1.7;}

/* EMPTY STATE — identical to supervisor */
.empty-state{text-align:center;padding:40px 24px;background:white;border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow);margin-bottom:24px;}
.empty-state h3{font-size:17px;font-weight:600;color:var(--secondary);margin-bottom:8px;}
.empty-state p{font-size:14px;color:var(--muted);}

/* HISTORY CARD */
.history-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:26px;margin-bottom:16px;box-shadow:var(--shadow);}
.hist-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f8fafc;flex-wrap:wrap;gap:8px;}
.hist-row:last-child{border-bottom:none;}
.hist-id{font-weight:700;color:var(--secondary);font-size:.875rem;}
.hist-sub{color:var(--muted);font-size:.78rem;margin-top:2px;}

/* MODAL */
.modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:center;justify-content:center;}
.modal-box{background:white;border-radius:var(--radius-lg);padding:28px;width:90%;max-width:420px;box-shadow:0 20px 40px rgba(0,0,0,.15);animation:slideDown .3s ease;}
@keyframes slideDown{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-box h3{font-size:1.05rem;font-weight:700;margin-bottom:16px;color:var(--secondary);display:flex;align-items:center;gap:8px;}
.modal-box textarea{width:100%;min-height:100px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius);font-family:'Inter',sans-serif;font-size:13px;resize:vertical;outline:none;}
.modal-box textarea:focus{border-color:var(--primary);}
.modal-btns{display:flex;gap:10px;margin-top:14px;}
.modal-btns button{flex:1;padding:10px;border:none;border-radius:var(--radius);font-weight:700;font-size:13px;cursor:pointer;}
.mbtn-cancel{background:#f1f5f9;color:var(--secondary);}
.mbtn-submit{background:var(--warning);color:white;}

@media(max-width:768px){.delivery-grid{grid-template-columns:1fr 1fr;}.btn-row{grid-template-columns:1fr;}}
@media(max-width:500px){.delivery-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<nav>
    <a href="driver_dashboard.php" class="nav-logo">🛢️ PetroTrack</a>
    <div class="nav-right">
        <div style="text-align:right;margin-right:4px;">
            <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
            <div style="font-size:11px;color:var(--muted);">Driver</div>
        </div>
        <a href="profile.php"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="messages.php"><i class="fas fa-envelope"></i> Messages <?php if($unread_count>0) echo '<span class="badge">'.$unread_count.'</span>'; ?></a>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>

<div class="container">

<?php if($message): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType=='success'?'check-circle':($msgType=='info'?'info-circle':'exclamation-triangle'); ?>" style="margin-top:2px;flex-shrink:0;"></i>
    <span><?php echo $message; ?></span>
</div>
<?php endif; ?>

<?php if($is_suspended): ?>
<div class="investigation-banner">
    <h3><i class="fas fa-shield-alt"></i> Investigation In Progress</h3>
    <p>Your account has been suspended following a delivery discrepancy. You cannot start new trips until an administrator reviews and reinstates you. Please contact your logistics manager.</p>
    <?php if(!empty($status_data['status_reason'])): ?>
    <div class="reason-box"><strong>Reason:</strong> <?php echo htmlspecialchars($status_data['status_reason']); ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="page-title"> Good <?php echo $greeting; ?>, <?php echo htmlspecialchars($_SESSION['name']); ?></div>
<div class="page-sub">
    <?php if($assigned_truck): ?>
        Assigned Truck: <strong><?php echo htmlspecialchars($assigned_truck['plate_number']); ?></strong> &mdash; Capacity: <?php echo number_format($assigned_truck['capacity']); ?> L
    <?php else: ?>
        No truck assigned yet. Contact your logistics manager.
    <?php endif; ?>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Pending</div>
        <div class="stat-number"><?php echo $n_pending; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">In Transit</div>
        <div class="stat-number"><?php echo $n_transit; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Delivered Today</div>
        <div class="stat-number highlight"><?php echo $n_done; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Completed</div>
        <div class="stat-number"><?php echo $n_total; ?></div>
    </div>
</div>

<div class="section-title"><i style="color:var(--primary);"></i> Current Active Status</div>

<?php if($active_trip):
    $s = $active_trip['delivery_status'];
    $cardClass = ['in_transit'=>'in-transit','awaiting_verification'=>'awaiting','flagged'=>'flagged-card'][$s] ?? 'active-trip';
    $statusClass = ['in_transit'=>'status-in_transit','awaiting_verification'=>'status-awaiting','flagged'=>'status-flagged'][$s] ?? 'status-pending';
    $statusLabel = ['in_transit'=>'In Transit','awaiting_verification'=>'Awaiting Verification','flagged'=>'Flagged'][$s] ?? ucfirst($s);
?>
<div class="delivery-card <?php echo $cardClass; ?>">
    <div class="delivery-top">
        <div>
            <div class="delivery-id-label">Order ID</div>
            <div class="delivery-title">#<?php echo $active_trip['id']; ?></div>
        </div>
        <span class="status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
    </div>

    <div class="delivery-grid">
        <div>
            <div class="detail-label">Product</div>
            <div class="detail-value"><?php echo $products[$active_trip['product_type']] ?? $active_trip['product_type']; ?></div>
        </div>
        <div>
            <div class="detail-label">Volume Loaded</div>
            <div class="detail-value"><?php echo number_format($active_trip['volume_loaded']); ?> L</div>
        </div>
        <div>
            <div class="detail-label">From</div>
            <div class="detail-value"><?php echo htmlspecialchars($active_trip['source'] ?? '—'); ?></div>
        </div>
        <div>
            <div class="detail-label">Destination</div>
            <div class="detail-value"><?php echo htmlspecialchars($active_trip['destination']); ?></div>
        </div>
        <div>
            <div class="detail-label">Truck</div>
            <div class="detail-value"><?php echo htmlspecialchars($active_trip['plate_number']); ?></div>
        </div>
        <?php if($active_trip['estimated_arrival']): ?>
        <div>
            <div class="detail-label">Est. Arrival</div>
            <div class="detail-value"><?php echo $active_trip['estimated_arrival']; ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if($s == 'in_transit' && !$is_suspended): ?>
    <div class="action-box">
        <div class="ab-title"><i class="fas fa-route" style="color:var(--info);"></i> Trip Underway</div>
        <div class="ab-sub">Update your location or report a delay. When you arrive and have discharged, enter the volume below and submit for verification.</div>

        <div class="btn-row" style="margin-bottom:18px;">
            <form method="POST" style="display:contents;">
                <button type="submit" name="move_truck" value="1" class="btn btn-dark">
                    <i class="fas fa-map-marker-alt"></i> Update Location
                </button>
            </form>
            <button class="btn btn-warning" onclick="document.getElementById('delayModal').style.display='flex'">
                <i class="fas fa-exclamation-triangle"></i> Report Delay
            </button>
        </div>

        <form method="POST" onsubmit="return confirm('Submit this delivery for supervisor verification?')">
            <div class="vol-input-wrap">
                <label>Volume Delivered (L)</label>
                <input type="number" name="driver_vol"
                       value="<?php echo $active_trip['volume_loaded']; ?>"
                       min="1" max="<?php echo $active_trip['volume_loaded']; ?>" required>
            </div>
            <div class="btn-row single">
                <button type="submit" name="submit_delivery" value="1" class="btn btn-success">
                    Submit to Supervisor for Verification
                </button>
            </div>
        </form>
    </div>

    <?php elseif($s == 'awaiting_verification'): ?>
    <div class="awaiting-panel">
        <div class="spinner"></div>
        <h4>Awaiting Station Verification</h4>
        <p>You reported <strong><?php echo number_format($active_trip['driver_reported_vol'] ?? $active_trip['volume_loaded']); ?> L</strong> delivered.<br>
        The station supervisor is measuring and verifying the received fuel.<br>
        Please remain on-site until confirmation is complete.</p>
    </div>

    <?php elseif($s == 'flagged'): ?>
    <div class="action-box" style="background:#fef2f2;border-color:#fecaca;">
        <div class="ab-title" style="color:#991b1b;"><i class="fas fa-flag"></i> Delivery Flagged</div>
        <div class="ab-sub" style="color:#b91c1c;">A volume discrepancy was detected during supervisor verification. This delivery has been escalated to the administrator. You will be contacted shortly.</div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="empty-state" style="padding: 30px 24px;">
    <h3>No Active Delivery Run</h3>
    <p>You are currently not moving any product. Start a trip below when ready.</p>
</div>
<?php endif; ?>


<div class="section-title" style="margin-top:32px;"><i style="color:var(--warning);"></i> Pending Queue (<?php echo count($pending_trips); ?>)</div>

<?php if(!empty($pending_trips)): ?>
    <?php foreach($pending_trips as $pt): ?>
        <div class="delivery-card active-trip" style="margin-bottom:20px;">
            <div class="delivery-top">
                <div>
                    <div class="delivery-id-label">Order ID</div>
                    <div class="delivery-title">#<?php echo $pt['id']; ?></div>
                </div>
                <span class="status status-pending">Pending</span>
            </div>

            <div class="delivery-grid">
                <div>
                    <div class="detail-label">Product</div>
                    <div class="detail-value"><?php echo $products[$pt['product_type']] ?? $pt['product_type']; ?></div>
                </div>
                <div>
                    <div class="detail-label">Volume Loaded</div>
                    <div class="detail-value"><?php echo number_format($pt['volume_loaded']); ?> L</div>
                </div>
                <div>
                    <div class="detail-label">From</div>
                    <div class="detail-value"><?php echo htmlspecialchars($pt['source'] ?? '—'); ?></div>
                </div>
                <div>
                    <div class="detail-label">Destination</div>
                    <div class="detail-value"><?php echo htmlspecialchars($pt['destination']); ?></div>
                </div>
                <div>
                    <div class="detail-label">Truck</div>
                    <div class="detail-value"><?php echo htmlspecialchars($pt['plate_number']); ?></div>
                </div>
            </div>

            <?php if(!$is_suspended): ?>
            <div class="action-box" style="padding:16px;">
                <form method="POST">
                    <input type="hidden" name="trip_id" value="<?php echo $pt['id']; ?>">
                    <button type="submit" name="start_trip" value="1" class="btn btn-primary" <?php if($active_trip) echo 'disabled style="opacity:0.5; cursor:not-allowed;" title="Complete your active trip first"'; ?>>
                        <i class="fas fa-play"></i> <?php echo $active_trip ? 'Locked (Complete Active Run First)' : 'Start This Trip'; ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
<div class="empty-state">
    <h3>No Pending Deliveries Queue</h3>
    <p>Your workspace queue is clear. No alternative pending shipments are scheduled for this truck asset.</p>
</div>
<?php endif; ?>


<?php if(!empty($history)): ?>
<div class="section-title" style="margin-top:32px;"><i class="fas fa-history" style="color:var(--primary);"></i> Recent Deliveries</div>
<div class="history-card">
    <?php foreach($history as $h):
        $hbg = ['delivered'=>['#d1fae5','#065f46'],'cancelled'=>['#f1f5f9','#475569'],'flagged'=>['#fee2e2','#991b1b']][$h['delivery_status']] ?? ['#f1f5f9','#475569'];
    ?>
    <div class="hist-row">
        <div>
            <div class="hist-id">#<?php echo $h['id']; ?> &mdash; <?php echo htmlspecialchars($h['destination']); ?></div>
            <div class="hist-sub"><?php echo $products[$h['product_type']]??$h['product_type']; ?> &bull; Loaded: <?php echo number_format($h['volume_loaded']); ?> L</div>
            <?php if($h['driver_reported_vol'] && $h['station_reported_vol']): ?>
            <div class="hist-sub">
                Driver: <?php echo number_format($h['driver_reported_vol']); ?> L &bull;
                Station: <?php echo number_format($h['station_reported_vol']); ?> L
                <?php $var = abs($h['driver_reported_vol'] - $h['station_reported_vol']); if($var > 0): ?>
                &bull; <span style="color:<?php echo $var>100?'#991b1b':'#065f46'; ?>;">Variance: <?php echo number_format($var); ?> L</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <span style="background:<?php echo $hbg[0]; ?>;color:<?php echo $hbg[1]; ?>;padding:4px 12px;border-radius:50px;font-size:11px;font-weight:700;text-transform:uppercase;white-space:nowrap;">
            <?php echo ucfirst($h['delivery_status']); ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>

<div id="delayModal" class="modal">
    <div class="modal-box">
        <h3><i class="fas fa-clock" style="color:var(--warning);"></i> Report a Delay</h3>
        <form method="POST">
            <textarea name="delay_reason" placeholder="Describe the reason (e.g. traffic, road block, vehicle breakdown)..." required></textarea>
            <div class="modal-btns">
                <button type="button" class="mbtn-cancel" onclick="document.getElementById('delayModal').style.display='none'">Cancel</button>
                <button type="submit" name="report_delay" value="1" class="mbtn-submit">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<script>
window.onclick = function(e){ if(e.target.classList.contains('modal')) e.target.style.display='none'; }
</script>
</body>
</html>