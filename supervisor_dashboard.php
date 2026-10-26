<?php
session_start();
include 'config/db_connect.php';
$required_role = 'supervisor';
include 'auth_check.php';

$confirm_message = ""; $confirm_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_delivery'])) {
    $delivery_id = (int)$_POST['delivery_id'];
    $driver_vol  = (float)$_POST['driver_vol'];
    $station_vol = (float)$_POST['station_vol'];
    $disc_text   = trim($_POST['discrepancy_reason'] ?? '');
    $pin_entered = preg_replace('/\D/', '', trim($_POST['supervisor_pin'] ?? ''));

    $checks_ok = !empty($_POST['check_dip']) && !empty($_POST['check_seal'])
              && !empty($_POST['check_waybill']) && !empty($_POST['check_quality']);

    if (!$checks_ok) {
        $confirm_message = "Please tick all verification checkboxes before confirming.";
        $confirm_type    = "error";
    } elseif (strlen($pin_entered) !== 4) {
        $confirm_message = "Please enter your 4-digit PIN to authorise.";
        $confirm_type    = "error";
    } else {
        $pc = $conn->prepare("SELECT verification_pin FROM users WHERE id=?");
        $pc->bind_param("i", $_SESSION['user_id']); $pc->execute();
        $pr = $pc->get_result()->fetch_assoc(); $pc->close();

        if (empty($pr['verification_pin'])) {
            $confirm_message = "You have not set a verification PIN. Set one in your Profile first.";
            $confirm_type    = "error";
        } elseif (!password_verify($pin_entered, $pr['verification_pin'])) {
            $confirm_message = "Incorrect PIN. Please try again.";
            $confirm_type    = "error";
        } else {
            $fetch = $conn->prepare("SELECT u.id as driver_id, u.name as driver_name FROM deliveries d JOIN vehicles v ON d.truck_id=v.id LEFT JOIN users u ON v.driver_id=u.id WHERE d.id=?");
            $fetch->bind_param("i", $delivery_id); $fetch->execute();
            $row = $fetch->get_result()->fetch_assoc(); $fetch->close();

            if ($row) {
                $variance  = abs($driver_vol - $station_vol);
                $stamp     = "Verified by: ".$_SESSION['name']." | PIN-authorised | ".date('d M Y, H:i:s')." | Station dip: ".number_format($station_vol)."L";

                if ($variance > 100) {
                    $new_status = 'flagged';
                    $reason_db  = "Variance of ".number_format($variance,0)."L detected. ".$disc_text;
                    if (!empty($row['driver_id'])) {
                        $sr = "Suspended pending investigation: Delivery #$delivery_id — ".number_format($variance,0)."L variance (driver: ".number_format($driver_vol)."L, station: ".number_format($station_vol)."L).";
                        $sus = $conn->prepare("UPDATE users SET status='suspended', status_reason=? WHERE id=?");
                        $sus->bind_param("si",$sr,$row['driver_id']); $sus->execute(); $sus->close();
                    }
                    $confirm_message = "⚠ Discrepancy of ".number_format($variance)."L. Delivery flagged and driver ".htmlspecialchars($row['driver_name']??"")." suspended.";
                    $confirm_type = "error";
                } else {
                    $new_status = 'delivered';
                    $reason_db  = $disc_text;
                    $confirm_message = " Delivery #$delivery_id verified and confirmed at ".date('H:i, d M Y').".";
                    $confirm_type = "success";
                }

                $upd = $conn->prepare("UPDATE deliveries SET delivery_status=?,driver_reported_vol=?,station_reported_vol=?,discrepancy_reason=?,supervisor_signature=? WHERE id=?");
                $upd->bind_param("siissi",$new_status,$driver_vol,$station_vol,$reason_db,$stamp,$delivery_id);
                $upd->execute(); $upd->close();
            }
        }
    }
}

// STATION INFO
$station_name = 'Unassigned'; $station_location = '';
$sup_station_id = $_SESSION['station_id'] ?? 0;
if ($sup_station_id) {
    $st = $conn->prepare("SELECT name,location FROM stations WHERE id=?");
    $st->bind_param("i",$sup_station_id); $st->execute();
    $std = $st->get_result()->fetch_assoc(); $st->close();
    if ($std) { $station_name=$std['name']; $station_location=$std['location']; }
}

// FETCH LOGISTICS MANAGER (user with role 'admin' or 'manager')
$manager_id = 0;
$mgr = $conn->prepare("SELECT id FROM users WHERE role IN ('admin', 'manager') AND status = 'active' ORDER BY id ASC LIMIT 1");
if ($mgr) {
    $mgr->execute();
    $mgr_row = $mgr->get_result()->fetch_assoc();
    $mgr->close();
    if ($mgr_row) {
        $manager_id = (int)$mgr_row['id'];
    }
}

// STATS
$stats = [];
foreach (['pending','in_transit','awaiting_verification','delivered'] as $sk) {
    $ex = $sk=='delivered' ? "AND DATE(created_at)=CURDATE()" : "";
    $q = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE delivery_status=? AND station_id=? $ex");
    $q->bind_param("si",$sk,$sup_station_id); $q->execute();
    $stats[$sk]=$q->get_result()->fetch_assoc()['c']; $q->close();
}

// HAS PIN?
$pc2 = $conn->prepare("SELECT verification_pin FROM users WHERE id=?");
$pc2->bind_param("i",$_SESSION['user_id']); $pc2->execute();
$has_pin = !empty($pc2->get_result()->fetch_assoc()['verification_pin']); $pc2->close();

// DELIVERIES
$stmt = $conn->prepare("SELECT d.*,v.plate_number,u.name as driver_name,u.phone as driver_phone FROM deliveries d JOIN vehicles v ON d.truck_id=v.id LEFT JOIN users u ON v.driver_id=u.id WHERE d.delivery_status IN ('pending','in_transit','awaiting_verification') AND d.station_id=? ORDER BY FIELD(d.delivery_status,'awaiting_verification','in_transit','pending'),d.created_at DESC");
$stmt->bind_param("i",$sup_station_id); $stmt->execute();
$all_deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// UNREAD MESSAGES
$um=$conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i",$_SESSION['user_id']); $um->execute();
$unread_count=$um->get_result()->fetch_assoc()['c']; $um->close();

$products=['PMS'=>'PMS (Petrol)','AGO'=>'AGO (Diesel)','DPK'=>'DPK (Kerosene)','ATK'=>'ATK (Aviation)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Supervisor Dashboard - PetroTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#4f0470;--primary-dark:#3a0552;--secondary:#0f172a;--muted:#64748b;--border:#e2e8f0;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--info:#3b82f6;--radius:12px;--radius-lg:20px;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#f3e8ff 50%,#f8fafc 100%);color:#1e293b;min-height:100vh;}
nav{background:white;border-bottom:1px solid var(--border);padding:0 5%;height:64px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100;}
.nav-logo{font-size:1.1rem;font-weight:800;color:var(--secondary);text-decoration:none;display:flex;align-items:center;gap:8px;}
.nav-right{display:flex;align-items:center;gap:12px;}
.nav-right a{text-decoration:none;background:#f1f5f9;color:var(--secondary);padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:.2s;}
.nav-right a:hover{background:#faf5ff;color:var(--primary);}
.nav-right a.logout{background:#fef2f2;color:#dc2626;}
.nav-right a.logout:hover{background:#fee2e2;}
.nbadge{background:#dc2626;color:white;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;}
.container{max-width:1100px;margin:0 auto;padding:32px 20px;}
.page-title{font-size:22px;font-weight:800;color:var(--secondary);margin-bottom:4px;}
.page-sub{font-size:14px;color:var(--muted);margin-bottom:28px;}
.alert{padding:14px 18px;border-radius:var(--radius);margin-bottom:22px;font-size:14px;font-weight:500;border-left:4px solid;display:flex;align-items:flex-start;gap:10px;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#22c55e;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:var(--danger);}
.no-pin-banner{background:#fffbeb;border:1px solid #fde68a;border-left:4px solid var(--warning);border-radius:var(--radius);padding:14px 18px;margin-bottom:22px;font-size:13px;color:#92400e;display:flex;align-items:center;gap:10px;}
.no-pin-banner a{color:var(--primary);font-weight:700;text-decoration:none;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;}
.stat-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px 24px;box-shadow:var(--shadow);transition:.2s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.08);}
.stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;}
.stat-number{font-size:32px;font-weight:800;color:var(--secondary);line-height:1;}
.stat-number.highlight{color:var(--primary);}
.section-title{font-size:15px;font-weight:700;color:var(--secondary);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.delivery-card{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:26px;margin-bottom:16px;box-shadow:var(--shadow);transition:.2s;}
.delivery-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.08);}
.delivery-card.needs-action{border-left:5px solid var(--primary);background:linear-gradient(135deg,white 0%,#fdf9ff 100%);}
.delivery-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.delivery-id-label{font-size:11px;color:var(--muted);margin-bottom:3px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.delivery-title{font-size:18px;font-weight:800;color:var(--secondary);}
.status{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.status-pending{background:#fff7ed;color:#9a3412;}
.status-in_transit{background:#eff6ff;color:#1d4ed8;}
.status-awaiting{background:#fef9c3;color:#854d0e;border:1px solid #fde047;}
.delivery-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:20px;}
.detail-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:3px;}
.detail-value{font-size:14px;font-weight:600;color:var(--secondary);}
.verify-box{background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius);padding:22px;}
.vb-title{font-size:14px;font-weight:700;color:var(--secondary);margin-bottom:4px;display:flex;align-items:center;gap:8px;}
.vb-sub{font-size:12px;color:var(--muted);margin-bottom:20px;}
.vol-compare{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;}
.vol-box{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:14px;}
.vol-box label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px;}
.vol-box input{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-size:15px;font-weight:700;color:var(--secondary);outline:none;transition:.2s;}
.vol-box input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,4,112,.1);}
.vol-box.driver-box{border-color:#bfdbfe;} .vol-box.driver-box label{color:#1d4ed8;} .vol-box.driver-box input{color:#1d4ed8;background:#f0f9ff;}
.vol-box.station-box{border-color:#bbf7d0;} .vol-box.station-box label{color:#065f46;}
.variance-preview{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;justify-content:space-between;min-height:44px;}
.var-label{color:var(--muted);font-weight:500;} .var-value{font-size:16px;font-weight:800;}
.reason-area{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius);font-family:'Inter',sans-serif;font-size:13px;resize:vertical;min-height:70px;outline:none;margin-bottom:16px;transition:.2s;}
.reason-area:focus{border-color:var(--primary);}
.checklist{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:16px;}
.checklist-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px;}
.check-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f8fafc;cursor:pointer;}
.check-item:last-child{border-bottom:none;padding-bottom:0;}
.check-item input[type=checkbox]{width:17px;height:17px;accent-color:var(--primary);cursor:pointer;flex-shrink:0;}
.check-item label{font-size:13px;font-weight:500;color:var(--secondary);cursor:pointer;flex:1;line-height:1.4;}
.check-item input:checked+label{color:var(--success);}
.timestamp-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#065f46;font-weight:500;}
.pin-section{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:16px;}
.pin-section > label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:10px;}
.pin-input{width:100%;padding:12px;border:2px solid var(--border);border-radius:var(--radius);font-size:24px;font-weight:800;letter-spacing:10px;text-align:center;outline:none;font-family:'Inter',sans-serif;color:var(--secondary);transition:.2s;background:#f8fafc;margin-bottom:8px;}
.pin-input:focus{border-color:var(--primary);background:white;box-shadow:0 0 0 3px rgba(79,4,112,.1);}
.pin-hint{font-size:11px;color:var(--muted);}
.pin-hint a{color:var(--primary);font-weight:600;text-decoration:none;}
.btn-verify{width:100%;padding:14px;background:var(--primary);color:white;border:none;border-radius:var(--radius);font-size:14px;font-weight:700;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-verify:hover:not(:disabled){background:var(--primary-dark);transform:translateY(-1px);}
.btn-verify:disabled{opacity:.45;cursor:not-allowed;}
.tolerance-note{font-size:11px;color:var(--muted);margin-top:10px;text-align:center;}
.empty-state{text-align:center;padding:60px 24px;background:white;border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow);}
.empty-state h3{font-size:17px;font-weight:600;color:var(--secondary);margin-bottom:8px;}
.empty-state p{font-size:14px;color:var(--muted);}
@media(max-width:768px){.vol-compare{grid-template-columns:1fr;}.delivery-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:500px){.delivery-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<nav>
    <a href="supervisor_dashboard.php" class="nav-logo">🛢️ PetroTrack</a>
    <div class="nav-right">
        <div style="text-align:right;margin-right:4px;">
            <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
            <div style="font-size:11px;color:var(--muted);">Station Supervisor</div>
        </div>
        <a href="profile.php?view_id=<?php echo $manager_id > 0 ? $manager_id : $_SESSION['user_id']; ?>"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="messages.php"><i class="fas fa-envelope"></i> Messages <?php if($unread_count>0) echo '<span class="nbadge">'.$unread_count.'</span>'; ?></a>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>
<div class="container">

<?php if($confirm_message): ?>
<div class="alert alert-<?php echo $confirm_type; ?>">
    <i class="fas fa-<?php echo $confirm_type=='success'?'check-circle':'exclamation-triangle'; ?>" style="margin-top:2px;flex-shrink:0;"></i>
    <span><?php echo $confirm_message; ?></span>
</div>
<?php endif; ?>

<?php if(!$has_pin): ?>
<div class="no-pin-banner">
    <i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i>
    <span>You have not set a verification PIN yet — you cannot confirm deliveries without one. <a href="profile.php">Set your PIN in Profile →</a></span>
</div>
<?php endif; ?>

<div class="page-title">⛽ <?php echo htmlspecialchars($station_name); ?></div>
<div class="page-sub"><?php echo htmlspecialchars($station_location); ?> — Verify and confirm incoming fuel deliveries</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-number"><?php echo $stats['pending']; ?></div></div>
    <div class="stat-card"><div class="stat-label">In Transit</div><div class="stat-number"><?php echo $stats['in_transit']; ?></div></div>
    <div class="stat-card"><div class="stat-label">Awaiting Your Verification</div><div class="stat-number highlight"><?php echo $stats['awaiting_verification']; ?></div></div>
    <div class="stat-card"><div class="stat-label">Confirmed Today</div><div class="stat-number"><?php echo $stats['delivered']; ?></div></div>
</div>

<div class="section-title"><i  style="color:var(--primary);"></i> Incoming Deliveries</div>

<?php if(!empty($all_deliveries)): ?>
<?php foreach($all_deliveries as $d):
    $needs_action = ($d['delivery_status']==='awaiting_verification');
    $did = $d['id'];
    $sc=['pending'=>'status-pending','in_transit'=>'status-in_transit','awaiting_verification'=>'status-awaiting'][$d['delivery_status']]??'';
    $sl=['pending'=>'Pending','in_transit'=>'In Transit','awaiting_verification'=>'🔔 Awaiting Your Verification'][$d['delivery_status']]??ucfirst($d['delivery_status']);
?>
<div class="delivery-card <?php echo $needs_action?'needs-action':''; ?>">
    <div class="delivery-top">
        <div>
            <div class="delivery-id-label">Order ID</div>
            <div class="delivery-title">#<?php echo $d['id']; ?></div>
        </div>
        <span class="status <?php echo $sc; ?>"><?php echo $sl; ?></span>
    </div>
    <div class="delivery-grid">
        <div><div class="detail-label">Product</div><div class="detail-value"><?php echo $products[$d['product_type']]??$d['product_type']; ?></div></div>
        <div><div class="detail-label">Volume Loaded</div><div class="detail-value"><?php echo number_format($d['volume_loaded']); ?> L</div></div>
        <?php if($d['driver_reported_vol']): ?>
        <div><div class="detail-label">Driver Reported</div><div class="detail-value" style="color:var(--info);"><?php echo number_format($d['driver_reported_vol']); ?> L</div></div>
        <?php endif; ?>
        <div><div class="detail-label">Driver</div><div class="detail-value"><?php echo htmlspecialchars($d['driver_name']??'Unassigned'); ?></div></div>
        <div><div class="detail-label">Truck</div><div class="detail-value"><?php echo htmlspecialchars($d['plate_number']); ?></div></div>
        <?php if($d['driver_phone']): ?>
        <div><div class="detail-label">Contact</div><div class="detail-value"><?php echo htmlspecialchars($d['driver_phone']); ?></div></div>
        <?php endif; ?>
    </div>

    <?php if($needs_action): ?>
    <div class="verify-box">
        <div class="vb-title"><i class="fas fa-clipboard-check" style="color:var(--primary);"></i> Verify Fuel Receipt</div>
        <div class="vb-sub">Enter your dip reading, complete all checks, then enter your PIN to authorise.</div>
        <form method="POST">
            <input type="hidden" name="delivery_id" value="<?php echo $d['id']; ?>">

            <div class="vol-compare">
                <div class="vol-box driver-box">
                    <label><i class="fas fa-truck"></i> Driver Reported (L)</label>
                    <input type="number" name="driver_vol" value="<?php echo $d['driver_reported_vol']??$d['volume_loaded']; ?>" readonly style="cursor:not-allowed;opacity:.8;">
                </div>
                <div class="vol-box station-box">
                    <label><i class="fas fa-gas-pump"></i> Your Station Dip (L)</label>
                    <input type="number" name="station_vol" id="sv-<?php echo $did; ?>" placeholder="Enter actual received..." required
                           oninput="updateVariance('<?php echo $did;?>',<?php echo $d['driver_reported_vol']??$d['volume_loaded'];?>)">
                </div>
            </div>

            <div class="variance-preview" id="vp-<?php echo $did; ?>">
                <span class="var-label">Variance</span>
                <span class="var-value" style="color:var(--muted);">— Enter station dip to calculate</span>
            </div>

            <textarea name="discrepancy_reason" class="reason-area" placeholder="Notes / reason for variance (optional — e.g. foam residue, calibration, evaporation)"></textarea>

            <div class="checklist">
                <div class="checklist-title"><i class="fas fa-tasks"></i> &nbsp;Pre-confirmation Checklist — all required</div>
                <div class="check-item">
                    <input type="checkbox" name="check_dip" id="cd-<?php echo $did;?>" onchange="updateBtn('<?php echo $did;?>')">
                    <label for="cd-<?php echo $did;?>">Dip stick reading confirmed and recorded</label>
                </div>
                <div class="check-item">
                    <input type="checkbox" name="check_seal" id="cs-<?php echo $did;?>" onchange="updateBtn('<?php echo $did;?>')">
                    <label for="cs-<?php echo $did;?>">Tanker seals verified as intact on arrival</label>
                </div>
                <div class="check-item">
                    <input type="checkbox" name="check_waybill" id="cw-<?php echo $did;?>" onchange="updateBtn('<?php echo $did;?>')">
                    <label for="cw-<?php echo $did;?>">Waybill / delivery note sighted and matches order</label>
                </div>
                <div class="check-item">
                    <input type="checkbox" name="check_quality" id="cq-<?php echo $did;?>" onchange="updateBtn('<?php echo $did;?>')">
                    <label for="cq-<?php echo $did;?>">Fuel quality visually acceptable (no contamination)</label>
                </div>
            </div>

            <div class="timestamp-box">
                <i class="fas fa-clock"></i>
                <span>Confirmation timestamp: <strong id="ts-<?php echo $did;?>">—</strong></span>
            </div>

            <div class="pin-section">
                <label><i class="fas fa-lock"></i> &nbsp;Supervisor PIN — 4-digit PIN to authorise</label>
                <input type="text" inputmode="numeric" name="supervisor_pin" id="pin-<?php echo $did;?>"
                       class="pin-input" maxlength="4" placeholder="••••" autocomplete="off"
                       oninput="this.value=this.value.replace(/\D/g,'');updateBtn('<?php echo $did;?>')"
                       style="-webkit-text-security:disc;">
                <div class="pin-hint">
                    <?php if(!$has_pin): ?>
                        <span style="color:var(--danger);">⚠ No PIN set. <a href="profile.php">Set your PIN first.</a></span>
                    <?php else: ?>
                        Enter the 4-digit PIN you set in your <a href="profile.php">Profile</a>.
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" name="verify_delivery" value="1"
                    class="btn-verify" id="btn-<?php echo $did;?>" disabled>
                <i class="fas fa-check-circle"></i> Confirm &amp; Authorise Delivery
            </button>
            <div class="tolerance-note">Tolerance ±100 L. Variances above this flag the delivery and suspend the driver pending investigation.</div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-state">
    <div style="font-size:3rem;margin-bottom:12px;"></div>
    <h3>No incoming deliveries</h3>
    <p>All deliveries are completed or none are scheduled for your station.</p>
</div>
<?php endif; ?>
</div>

<script>
(function(){
    function tick(){
        var now=new Date(), pad=function(n){return String(n).padStart(2,'0')};
        var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var s=pad(now.getDate())+' '+months[now.getMonth()]+' '+now.getFullYear()+', '+pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
        document.querySelectorAll('[id^="ts-"]').forEach(function(el){el.textContent=s;});
    }
    tick(); setInterval(tick,1000);
})();

function updateVariance(did,driverVol){
    var inp=document.getElementById('sv-'+did);
    var pv=document.getElementById('vp-'+did);
    var val=parseFloat(inp.value);
    if(isNaN(val)||inp.value===''){
        pv.innerHTML='<span class="var-label">Variance</span><span class="var-value" style="color:var(--muted);">— Enter station dip to calculate</span>';
        updateBtn(did); return;
    }
    var v=Math.abs(driverVol-val), ok=v<=100;
    var c=ok?'var(--success)':'var(--danger)';
    var n=ok?'✓ Within tolerance':'⚠ Exceeds tolerance — will be flagged';
    pv.innerHTML='<span class="var-label">Variance</span><span class="var-value" style="color:'+c+';">'+fmt(v)+' L &nbsp;<small style="font-size:12px;font-weight:500;">'+n+'</small></span>';
    updateBtn(did);
}

function updateBtn(did){
    var btn=document.getElementById('btn-'+did);
    if(!btn) return;
    var allChecked=['cd-','cs-','cw-','cq-'].every(function(p){var el=document.getElementById(p+did);return el&&el.checked;});
    var pinOk=document.getElementById('pin-'+did).value.length===4;
    var dipOk=document.getElementById('sv-'+did).value!=='';
    btn.disabled=!(allChecked&&pinOk&&dipOk);
}

function fmt(n){return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,',');}
</script>
</body>
</html>