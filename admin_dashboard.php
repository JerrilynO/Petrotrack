<?php
session_start();
include 'config/db_connect.php';
$required_role = 'admin';
include 'auth_check.php';

$admin_id   = $_SESSION['user_id'];
$admin_name = 'Admin';

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

// Fetch admin's full details
$s = $conn->prepare("SELECT name, email FROM users WHERE id=?");
$s->bind_param("i", $admin_id); $s->execute();
$admin = $s->get_result()->fetch_assoc(); $s->close();
$admin_name = $admin['name'] ?? 'Admin';
$initials   = strtoupper(substr($admin_name, 0, 1));

$hour = date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');

$message = ""; $msgType = "";

// ── HANDLE QUICK ADD TRUCK ────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['quick_add_truck'])) {
    $plate    = trim($_POST['plate_number']);
    $capacity = (int)$_POST['capacity'];
    $ins = $conn->prepare("INSERT INTO vehicles (plate_number, capacity, status, managed_by) VALUES (?, ?, 'active', ?)");
    $ins->bind_param("sii", $plate, $capacity, $admin_id);
    if ($ins->execute()) {
        $message = "Truck added successfully!"; $msgType = "success";
        echo "<script>window.location.href='admin_dashboard.php';</script>";
    } else { $message = "Error: ".$ins->error; $msgType = "error"; }
    $ins->close();
}

// ── HANDLE QUICK CREATE ORDER ─────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['quick_create_order'])) {
    $truck_id   = (int)$_POST['truck_id'];
    $source     = $_POST['source'];
    $station_id = (int)$_POST['station_id'];
    $product    = $_POST['product_type'];
    $volume     = (int)$_POST['volume'];
    $date       = date('Y-m-d');
    $eta        = date('H:i', strtotime('+4 hours'));

    // Verify this truck belongs to this admin
    $chk = $conn->prepare("SELECT id FROM vehicles WHERE id=? AND managed_by=?");
    $chk->bind_param("ii", $truck_id, $admin_id); $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $message = "You can only create orders for your own trucks."; $msgType = "error";
    } else {
        $sn = $conn->prepare("SELECT name FROM stations WHERE id=?");
        $sn->bind_param("i", $station_id); $sn->execute();
        $destination = $sn->get_result()->fetch_assoc()['name'] ?? 'Unknown'; $sn->close();

        $ins = $conn->prepare("INSERT INTO deliveries (truck_id, source, destination, station_id, product_type, volume_loaded, delivery_status, delivery_date, estimated_arrival, created_by) VALUES (?,?,?,?,?,?,'pending',?,?,?)");
        $ins->bind_param("isssisssi", $truck_id, $source, $destination, $station_id, $product, $volume, $date, $eta, $admin_id);
        if ($ins->execute()) {
            $message = "Delivery order created!"; $msgType = "success";
            echo "<script>window.location.href='admin_dashboard.php';</script>";
        } else { $message = "Error: ".$ins->error; $msgType = "error"; }
        $ins->close();
    }
    $chk->close();
}

// ── STATS — scoped to this admin only ────────────────────────────────────────
$s = $conn->prepare("SELECT COUNT(*) as c FROM vehicles WHERE managed_by=?");
$s->bind_param("i",$admin_id); $s->execute();
$my_trucks = $s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE created_by=? AND delivery_status='in_transit'");
$s->bind_param("i",$admin_id); $s->execute();
$my_in_transit = $s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE created_by=? AND delivery_status='flagged'");
$s->bind_param("i",$admin_id); $s->execute();
$my_flagged = $s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE created_by=? AND delivery_status='awaiting_verification'");
$s->bind_param("i",$admin_id); $s->execute();
$my_awaiting = $s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE created_by=? AND delivery_status='delivered' AND DATE(delivery_date)=CURDATE()");
$s->bind_param("i",$admin_id); $s->execute();
$my_delivered_today = $s->get_result()->fetch_assoc()['c']; $s->close();

// ── MY ACTIVE DELIVERIES ──────────────────────────────────────────────────────
$active_q = $conn->prepare(
    "SELECT d.id, d.source, d.destination, d.product_type, d.volume_loaded,
            d.driver_reported_vol, d.delivery_status, d.delivery_date,
            v.plate_number, u.name as driver_name
     FROM deliveries d
     JOIN vehicles v ON d.truck_id = v.id
     LEFT JOIN users u ON v.driver_id = u.id
     WHERE d.created_by = ? AND d.delivery_status NOT IN ('delivered','cancelled')
     ORDER BY d.id DESC LIMIT 10"
);
$active_q->bind_param("i", $admin_id); $active_q->execute();
$active_deliveries = $active_q->get_result()->fetch_all(MYSQLI_ASSOC); $active_q->close();

// ── MY TRUCKS ─────────────────────────────────────────────────────────────────
$trucks_q = $conn->prepare(
    "SELECT v.id, v.plate_number, v.capacity, v.status, u.name as driver_name
     FROM vehicles v LEFT JOIN users u ON v.driver_id = u.id
     WHERE v.managed_by = ?
     ORDER BY v.created_at DESC"
);
$trucks_q->bind_param("i", $admin_id); $trucks_q->execute();
$my_truck_list = $trucks_q->get_result()->fetch_all(MYSQLI_ASSOC); $trucks_q->close();

// ── MY DRIVERS (drivers assigned to my trucks) ────────────────────────────────
$drivers_q = $conn->prepare(
    "SELECT DISTINCT u.id, u.name, u.phone, u.status, v.plate_number
     FROM users u
     JOIN vehicles v ON v.driver_id = u.id
     WHERE v.managed_by = ? AND u.role = 'driver'
     ORDER BY u.name"
);
$drivers_q->bind_param("i", $admin_id); $drivers_q->execute();
$my_drivers = $drivers_q->get_result()->fetch_all(MYSQLI_ASSOC); $drivers_q->close();

// ── UNREAD MESSAGES ───────────────────────────────────────────────────────────
$um = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i",$admin_id); $um->execute();
$uc = $um->get_result()->fetch_assoc()['c']; $um->close();

$products = ['PMS'=>'PMS (Petrol)','AGO'=>'AGO (Diesel)','DPK'=>'DPK (Kerosene)','ATK'=>'ATK (Aviation)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dashboard - PetroTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#0986B8;--primary-dark:#3b0357;--primary-light:#7c2d9e;--secondary:#0f172a;--muted:#64748b;--border:#e2e8f0;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--info:#3b82f6;--radius:12px;--radius-lg:20px;--shadow:0 4px 6px -1px rgb(0 0 0/.1),0 2px 4px -2px rgb(0 0 0/.1);--shadow-lg:0 20px 25px -5px rgb(0 0 0/.1),0 8px 10px -6px rgb(0 0 0/.1);--sidebar-width:260px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#f3e8ff 50%,#f8fafc 100%);color:#1e293b;display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-width);background:linear-gradient(135deg,#fff 0%,#faf5ff 100%);height:100vh;position:fixed;left:0;top:0;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-header{padding:25px 20px;border-bottom:1px solid var(--border);}
.brand-logo{font-size:1.25rem;font-weight:800;color:var(--secondary);text-decoration:none;display:flex;align-items:center;gap:8px;}
.brand-logo span{color:var(--primary);}
.nav-links{list-style:none;padding:20px 0;flex:1;}
.nav-links li a{display:flex;align-items:center;padding:12px 25px;color:var(--muted);text-decoration:none;font-weight:500;transition:.3s;gap:12px;font-size:.95rem;}
.nav-links li a.active,.nav-links li a:hover{color:var(--primary);background:#faf5ff;border-right:4px solid var(--primary);}
.user-profile{padding:20px;border-top:1px solid var(--border);}
.profile-info{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;}
.btn-logout{width:100%;background:var(--secondary);color:white;border:none;padding:10px;border-radius:50px;font-size:.85rem;font-weight:600;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;}
.btn-logout:hover{background:var(--primary);}
.main-content{margin-left:var(--sidebar-width);flex:1;padding:30px;width:calc(100% - var(--sidebar-width));}

/* WELCOME HERO */
.welcome-hero{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-light) 100%);border-radius:var(--radius-lg);padding:28px 32px;color:white;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:var(--shadow-lg);}
.welcome-hero::before{content:'';position:absolute;width:250px;height:250px;background:rgba(255,255,255,.07);border-radius:50%;top:-80px;right:-60px;}
.welcome-hero::after{content:'';position:absolute;width:150px;height:150px;background:rgba(255,255,255,.05);border-radius:50%;bottom:-40px;left:200px;}
.welcome-hero h2{font-size:1.6rem;font-weight:800;margin-bottom:4px;position:relative;}
.welcome-hero p{font-size:.875rem;opacity:.85;position:relative;}
.welcome-hero .manager-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);border-radius:50px;padding:4px 14px;font-size:.75rem;font-weight:700;margin-top:12px;position:relative;}

/* ALERT */
.alert{padding:13px 18px;border-radius:var(--radius);margin-bottom:22px;font-size:.875rem;font-weight:500;border-left:4px solid;display:flex;align-items:center;gap:10px;}
.alert-success{background:#ecfdf5;color:#065f46;border-color:var(--success);}
.alert-error{background:#fef2f2;color:#991b1b;border-color:var(--danger);}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:white;padding:22px;border-radius:var(--radius-lg);box-shadow:var(--shadow);border-top:4px solid transparent;transition:.2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
.stat-card.purple{border-color:var(--primary);}
.stat-card.blue{border-color:var(--info);}
.stat-card.green{border-color:var(--success);}
.stat-card.red{border-color:var(--danger);}
.stat-card.orange{border-color:var(--warning);}
.stat-title{font-size:.75rem;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;}
.stat-value{font-size:2rem;font-weight:800;color:var(--secondary);line-height:1;}
.stat-sub{font-size:.7rem;color:var(--muted);margin-top:5px;}

/* SECTION */
.section-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;margin-top:24px;}
.section-head h3{font-size:1rem;font-weight:700;color:var(--secondary);display:flex;align-items:center;gap:8px;}
.section-head a{font-size:.8rem;color:var(--primary);text-decoration:none;font-weight:600;}
.section-head a:hover{text-decoration:underline;}

/* CARDS */
.card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid rgba(79,4,112,.06);overflow:hidden;margin-bottom:20px;}
.card-body{padding:22px;}

/* QUICK ACTION FORMS */
.quick-form{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.fg{flex:1;min-width:150px;}
.fg label{display:block;margin-bottom:6px;font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.fg input,.fg select{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius);font-size:.875rem;font-family:'Inter',sans-serif;transition:.2s;outline:none;}
.fg input:focus,.fg select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,4,112,.1);}
.btn-submit{background:var(--primary);color:white;border:none;padding:10px 22px;border-radius:var(--radius);font-size:.875rem;font-weight:600;cursor:pointer;transition:.2s;height:42px;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;box-shadow:0 4px 6px -1px rgba(79,4,112,.3);}
.btn-submit:hover{background:var(--primary-dark);transform:translateY(-1px);}
.info-text{margin-top:12px;font-size:.75rem;color:var(--muted);}
.info-text a{color:var(--primary);text-decoration:none;font-weight:600;}

/* DELIVERY TABLE */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:700px;}
th{padding:11px 16px;text-align:left;background:#f8fafc;color:var(--primary);font-weight:600;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
td{padding:13px 16px;border-bottom:1px solid #f1f5f9;font-size:.83rem;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#faf5ff;}
.pill{padding:3px 10px;border-radius:50px;font-size:.65rem;font-weight:700;text-transform:uppercase;}
.pill-pending{background:#fed7aa;color:#92400e;}
.pill-transit{background:#dbeafe;color:#1e40af;}
.pill-awaiting{background:#fef3c7;color:#92400e;}
.pill-flagged{background:#fee2e2;color:#991b1b;}
.pill-delivered{background:#d1fae5;color:#065f46;}

/* TRUCK & DRIVER GRID */
.fleet-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;}
.fleet-card{background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius);padding:16px;transition:.2s;}
.fleet-card:hover{border-color:var(--primary);background:#faf5ff;}
.fleet-card .fc-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:10px;}
.fleet-card h4{font-size:.9rem;font-weight:700;color:var(--secondary);margin-bottom:3px;}
.fleet-card p{font-size:.75rem;color:var(--muted);}
.status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;}
.dot-active{background:var(--success);}
.dot-inactive{background:var(--muted);}
.dot-suspended{background:var(--danger);}
.dot-maintenance{background:var(--warning);}

/* EMPTY */
.empty{text-align:center;padding:32px;color:var(--muted);font-size:.875rem;}
.empty i{display:block;font-size:1.8rem;opacity:.25;margin-bottom:8px;}

@media(max-width:768px){.sidebar{transform:translateX(-100%);}.main-content{margin-left:0;width:100%;padding:20px;}.quick-form{flex-direction:column;}.btn-submit{width:100%;justify-content:center;}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header">
        <a href="admin_dashboard.php" class="brand-logo"><span>🛢️</span> PetroTrack</a>
    </div>
    <ul class="nav-links">
        <li><a href="admin_dashboard.php" class="active"></i> Dashboard</a></li>
        <li><a href="vehicle_management.php"></i> Vehicle Management</a></li>
        <li><a href="delivery_management.php"></i> Delivery Orders</a></li>
        <li><a href="user_management.php"></i> User Management</a></li>
        <li><a href="station_management.php"></i> Station Management</a></li>
        <li><a href="tracking_map.php"></i> Simulated Tracking</a></li>
        <li><a href="messages.php"></i> Messages
            <?php if($uc>0) echo '<span style="background:#ef4444;color:white;font-size:10px;padding:2px 7px;border-radius:10px;margin-left:8px;">'.$uc.'</span>'; ?>
        </a></li>
        <li><a href="reports.php"></i> Reports</a></li>
        <li><a href="profile.php"></i> My Profile</a></li>
    </ul>
    <div class="user-profile">
        <div class="profile-info">
            <div class="avatar"><?php echo $initials; ?></div>
            <div>
                <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($admin_name); ?></div>
                <div style="font-size:11px;color:var(--muted);">Logistics Manager</div>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</aside>
<main class="main-content">

    <?php if($message): ?>
    <div class="alert alert-<?php echo $msgType; ?>">
        <i class="fas fa-<?php echo $msgType=='success'?'check-circle':'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <!-- WELCOME HERO -->
    <div class="welcome-hero">
        <h2><?php echo $greeting; ?>, <?php echo htmlspecialchars($admin_name); ?>!</h2>
        <p>Here is your fleet and delivery overview for today, <?php echo date('l, d F Y'); ?>.</p>
        <div class="manager-badge">
            <i class="fas fa-id-badge"></i>
            Logistics Manager &mdash; <?php echo htmlspecialchars($admin_name); ?>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card purple">
            <div class="stat-title"><i></i> My Fleet</div>
            <div class="stat-value"><?php echo $my_trucks; ?></div>
            <div class="stat-sub">Trucks under your management</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-title"><i ></i> In Transit</div>
            <div class="stat-value"><?php echo $my_in_transit; ?></div>
            <div class="stat-sub">Currently on the road</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-title"><i ></i> Awaiting Verification</div>
            <div class="stat-value"><?php echo $my_awaiting; ?></div>
            <div class="stat-sub">Supervisor confirming</div>
        </div>
        <div class="stat-card green">
            <div class="stat-title"><i></i> Delivered Today</div>
            <div class="stat-value"><?php echo $my_delivered_today; ?></div>
            <div class="stat-sub">Confirmed <?php echo date('d M Y'); ?></div>
        </div>
        <div class="stat-card red">
            <div class="stat-title"><i ></i> Flagged</div>
            <div class="stat-value"><?php echo $my_flagged; ?></div>
            <div class="stat-sub">Investigation required</div>
        </div>
    </div>

    <!-- QUICK ADD TRUCK -->
    <div class="card">
        <div class="card-body">
            <h3 style="font-size:1rem;font-weight:700;color:var(--secondary);margin-bottom:18px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-plus-circle" style="color:var(--primary);"></i> Quick Add Truck
            </h3>
            <form method="POST" class="quick-form">
                <div class="fg">
                    <label>Plate Number</label>
                    <input type="text" name="plate_number" placeholder="e.g. ABC-123-XJ" required>
                </div>
                <div class="fg">
                    <label>Capacity (Litres)</label>
                    <input type="number" name="capacity" placeholder="33000" required>
                </div>
                <button type="submit" name="quick_add_truck" value="1" class="btn-submit">
                    <i class="fas fa-plus"></i> Add Truck
                </button>
            </form>
            <p class="info-text"><i class="fas fa-info-circle"></i> Or go to <a href="vehicle_management.php">Vehicle Management</a> to assign drivers and more.</p>
        </div>
    </div>

    <!-- QUICK CREATE ORDER -->
    <div class="card">
        <div class="card-body">
            <h3 style="font-size:1rem;font-weight:700;color:var(--secondary);margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-clipboard-list" style="color:var(--primary);"></i> Quick Create Delivery Order
            </h3>
            <p style="font-size:.78rem;color:var(--muted);margin-bottom:18px;">Order will be attributed to you — <strong><?php echo htmlspecialchars($admin_name); ?></strong> — as the managing logistics officer.</p>
            <form method="POST" class="quick-form">
                <div class="fg">
                    <label>Source Depot</label>
                    <select name="source" required>
                        <option value="Apapa Depot, Lagos">Apapa Depot, Lagos</option>
                        <option value="Mosimi Depot, Ogun">Mosimi Depot, Ogun</option>
                        <option value="Warri Depot, Delta">Warri Depot, Delta</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Destination Station</label>
                    <select name="station_id" required>
                        <option value="">— Select Station —</option>
                        <?php $sr=$conn->query("SELECT id,name,location FROM stations ORDER BY name");
                        while($ss=$sr->fetch_assoc()): ?>
                        <option value="<?php echo $ss['id']; ?>"><?php echo htmlspecialchars($ss['name'].' ('.$ss['location'].')'); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>My Truck</label>
                    <select name="truck_id" required>
                        <option value="">— Select Truck —</option>
                        <?php foreach($my_truck_list as $t): ?>
                        <option value="<?php echo $t['id']; ?>">
                            <?php echo htmlspecialchars($t['plate_number']); ?>
                            <?php echo $t['driver_name'] ? ' ('.$t['driver_name'].')' : ' (no driver)'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Product Type</label>
                    <select name="product_type" required>
                        <option value="PMS">PMS (Petrol)</option>
                        <option value="AGO">AGO (Diesel)</option>
                        <option value="DPK">DPK (Kerosene)</option>
                        <option value="ATK">ATK (Aviation)</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Volume (Litres)</label>
                    <input type="number" name="volume" placeholder="33000" required>
                </div>
                <button type="submit" name="quick_create_order" value="1" class="btn-submit">
                    <i class="fas fa-check-circle"></i> Create Order
                </button>
            </form>
            <p class="info-text"><i class="fas fa-info-circle"></i> For full delivery history go to <a href="delivery_management.php">Delivery Orders</a>.</p>
        </div>
    </div>

    <!-- ACTIVE DELIVERIES -->
    <div class="section-head">
        <h3><i style="color:var(--primary);"></i> My Active Deliveries</h3>
        <a href="delivery_management.php">View all →</a>
    </div>
    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order</th><th>Driver / Truck</th><th>Route</th>
                        <th>Product</th><th>Volume</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(!empty($active_deliveries)): foreach($active_deliveries as $d):
                    $pillClass = ['pending'=>'pill-pending','in_transit'=>'pill-transit','awaiting_verification'=>'pill-awaiting','flagged'=>'pill-flagged'][$d['delivery_status']] ?? 'pill-pending';
                    $pillLabel = ['pending'=>'Pending','in_transit'=>'In Transit','awaiting_verification'=>'Awaiting Verification','flagged'=>'Flagged'][$d['delivery_status']] ?? ucfirst($d['delivery_status']);
                ?>
                <tr>
                    <td><strong>#<?php echo $d['id']; ?></strong><br><small style="color:var(--muted);"><?php echo $d['delivery_date']??'—'; ?></small></td>
                    <td><?php echo htmlspecialchars($d['driver_name']??'Unassigned'); ?><br><small style="color:var(--muted);"><?php echo htmlspecialchars($d['plate_number']); ?></small></td>
                    <td style="font-size:.78rem;"><?php echo htmlspecialchars($d['source']??'—'); ?><br>→ <?php echo htmlspecialchars($d['destination']??'—'); ?></td>
                    <td><?php echo $products[$d['product_type']]??$d['product_type']; ?></td>
                    <td><?php echo number_format($d['volume_loaded']); ?> L</td>
                    <td><span class="pill <?php echo $pillClass; ?>"><?php echo $pillLabel; ?></span></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6"><div class="empty"><i class="fas fa-inbox"></i>No active deliveries. Create an order above.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MY FLEET -->
    <div class="section-head">
        <h3><i style="color:var(--primary);"></i> My Fleet (<?php echo count($my_truck_list); ?> trucks)</h3>
        <a href="vehicle_management.php">Manage →</a>
    </div>
    <?php if(!empty($my_truck_list)): ?>
    <div class="fleet-grid" style="margin-bottom:20px;">
        <?php foreach($my_truck_list as $t):
            $dotClass = ['active'=>'dot-active','inactive'=>'dot-inactive','maintenance'=>'dot-maintenance'][$t['status']] ?? 'dot-inactive';
        ?>
        <div class="fleet-card">
            <div style="background:#f3e8ff;"></div>
            <h4><?php echo htmlspecialchars($t['plate_number']); ?></h4>
            <p><span class="status-dot <?php echo $dotClass; ?>"></span><?php echo ucfirst($t['status']); ?> &bull; <?php echo number_format($t['capacity']); ?> L</p>
            <p style="margin-top:4px;color:#475569;"><?php echo $t['driver_name'] ? ''.$t['driver_name'] : 'No driver assigned'; ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body"><div class="empty"><i class="fas fa-truck"></i>No trucks added yet. Use the form above to add your first truck.</div></div></div>
    <?php endif; ?>

    <!-- MY DRIVERS -->
    <div class="section-head">
        <h3><i style="color:var(--primary);"></i> My Drivers (<?php echo count($my_drivers); ?>)</h3>
        <a href="user_management.php">Manage →</a>
    </div>
    <?php if(!empty($my_drivers)): ?>
    <div class="fleet-grid" style="margin-bottom:20px;">
        <?php foreach($my_drivers as $dr):
            $dotClass = ['active'=>'dot-active','inactive'=>'dot-inactive','suspended'=>'dot-suspended'][$dr['status']] ?? 'dot-inactive';
        ?>
        <div class="fleet-card">
            <div class="fc-icon" style="background:#e0f2fe;">👤</div>
            <h4><?php echo htmlspecialchars($dr['name']); ?></h4>
            <p><span class="status-dot <?php echo $dotClass; ?>"></span><?php echo ucfirst($dr['status']); ?></p>
            <p style="margin-top:4px;color:#475569;"><?php echo htmlspecialchars($dr['phone']??'—'); ?></p>
            <p style="margin-top:2px;font-size:.72rem;color:var(--muted);">Truck: <?php echo htmlspecialchars($dr['plate_number']); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body"><div class="empty"><i class="fas fa-users"></i>No drivers assigned to your trucks yet. Assign drivers in <a href="vehicle_management.php" style="color:var(--primary);">Vehicle Management</a>.</div></div></div>
    <?php endif; ?>

</main>
</body>
</html>