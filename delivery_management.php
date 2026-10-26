<?php
session_start();
include 'config/db_connect.php';
$required_role = 'admin';
include 'auth_check.php';

if (!function_exists('getRoleLabel')) {
    function getRoleLabel($role) {
        return match($role) { 'admin'=>'Admin','driver'=>'Driver','supervisor'=>'Supervisor',default=>ucfirst($role) };
    }
}

$admin_name = 'Admin';
if (isset($_SESSION['user_id'])) {
    $s = $conn->prepare("SELECT name FROM users WHERE id=?");
    $s->bind_param("i",$_SESSION['user_id']); $s->execute();
    if($r=$s->get_result()->fetch_assoc()) $admin_name=$r['name'];
    $s->close();
}

$message = ""; $msgType = "";
$report_data = null;

// HANDLE ACTIONS
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];

    if ($action == 'cancel') {
        $s = $conn->prepare("UPDATE deliveries SET delivery_status='cancelled' WHERE id=?");
        $s->bind_param("i",$id); $s->execute(); $s->close();
        $message = "Delivery cancelled."; $msgType = "success";
    }

    if ($action == 'reinstate') {
        // Find driver
        $find = $conn->prepare("SELECT u.id FROM users u JOIN vehicles v ON v.driver_id=u.id JOIN deliveries d ON d.truck_id=v.id WHERE d.id=?");
        $find->bind_param("i",$id); $find->execute();
        $driver = $find->get_result()->fetch_assoc(); $find->close();
        if ($driver) {
            $r1 = $conn->prepare("UPDATE users SET status='active', status_reason=NULL WHERE id=?");
            $r1->bind_param("i",$driver['id']); $r1->execute(); $r1->close();
            $r2 = $conn->prepare("UPDATE deliveries SET delivery_status='delivered' WHERE id=?");
            $r2->bind_param("i",$id); $r2->execute(); $r2->close();
            $message = "Driver reinstated and delivery resolved."; $msgType = "success";
        }
    }

    if ($action == 'view_report') {
        $s = $conn->prepare("SELECT d.*, v.plate_number, u.name as driver_name, mgr.name as manager_name
            FROM deliveries d JOIN vehicles v ON d.truck_id=v.id
            LEFT JOIN users u ON v.driver_id=u.id
            LEFT JOIN users mgr ON d.created_by=mgr.id
            WHERE d.id=?");
        $s->bind_param("i",$id); $s->execute();
        $report_data = $s->get_result()->fetch_assoc(); $s->close();
    }

    if ($action != 'view_report') {
        echo "<script>window.location.href='delivery_management.php';</script>"; exit();
    }
}

// CREATE DELIVERY
if ($_SERVER["REQUEST_METHOD"]=="POST" && isset($_POST['create_delivery'])) {
    $truck_id   = (int)$_POST['truck_id'];
    $source     = $_POST['source'];
    $station_id = (int)$_POST['station_id'];
    $product    = $_POST['product_type'];
    $volume     = (int)$_POST['volume'];
    $date       = date('Y-m-d');
    $eta        = date('H:i', strtotime('+4 hours'));

    $s = $conn->prepare("SELECT name FROM stations WHERE id=?");
    $s->bind_param("i",$station_id); $s->execute();
    $stn = $s->get_result()->fetch_assoc(); $s->close();
    $destination = $stn['name'] ?? 'Unknown';

    $ins = $conn->prepare("INSERT INTO deliveries (truck_id,source,destination,station_id,product_type,volume_loaded,delivery_status,delivery_date,estimated_arrival,created_by) VALUES (?,?,?,?,?,?,'pending',?,?,?)");
    if ($ins) {
        $ins->bind_param("ississssi", $truck_id,$source,$destination,$station_id,$product,$volume,$date,$eta,$_SESSION['user_id']);
        if ($ins->execute()) { $message="New delivery order created!"; $msgType="success"; }
        else { $message="Error: ".$ins->error; $msgType="error"; }
        $ins->close();
    }
}

// FETCH ALL DELIVERIES
$result = $conn->query("SELECT d.*, v.plate_number, u.name as driver_name,
    mgr.name as manager_name
    FROM deliveries d JOIN vehicles v ON d.truck_id=v.id
    LEFT JOIN users u ON v.driver_id=u.id
    LEFT JOIN users mgr ON d.created_by=mgr.id
    WHERE d.created_by=".$_SESSION['user_id']."
    ORDER BY d.id DESC");

// UNREAD MESSAGES
$um = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i",$_SESSION['user_id']); $um->execute();
$uc = $um->get_result()->fetch_assoc()['c']; $um->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Delivery Orders - PetroTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#0986B8;--primary-dark:#3b0357;--primary-light:#7c2d9e;--secondary:#0f172a;--muted:#64748b;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--info:#3b82f6;--radius:12px;--radius-lg:20px;--shadow:0 4px 6px -1px rgb(0 0 0/.1),0 2px 4px -2px rgb(0 0 0/.1);--shadow-lg:0 20px 25px -5px rgb(0 0 0/.1),0 8px 10px -6px rgb(0 0 0/.1);--sidebar-width:260px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#f3e8ff 50%,#f8fafc 100%);color:#1e293b;display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-width);background:linear-gradient(135deg,#fff 0%,#faf5ff 100%);height:100vh;position:fixed;left:0;top:0;border-right:1px solid #e2e8f0;display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-header{padding:25px 20px;border-bottom:1px solid #e2e8f0;}
.brand-logo{font-size:1.25rem;font-weight:800;color:var(--secondary);text-decoration:none;display:flex;align-items:center;gap:8px;}
.nav-links{list-style:none;padding:20px 0;flex:1;}
.nav-links li a{display:flex;align-items:center;padding:12px 25px;color:var(--muted);text-decoration:none;font-weight:500;transition:.3s;gap:12px;font-size:.95rem;}
.nav-links li a.active,.nav-links li a:hover{color:var(--primary);background:#faf5ff;border-right:4px solid var(--primary);}
.user-profile{padding:20px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;}
.avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;}
.btn-logout{display:flex;align-items:center;justify-content:center;width:calc(100% - 40px);margin:0 20px 20px;background:var(--secondary);color:white;border:none;padding:12px;border-radius:50px;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;transition:.2s;}
.btn-logout:hover{background:var(--primary);}
.main-content{margin-left:var(--sidebar-width);flex:1;padding:30px;width:calc(100% - var(--sidebar-width));}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;flex-wrap:wrap;gap:15px;}
.page-header h2{font-size:28px;font-weight:700;color:var(--secondary);}
.search-box{display:flex;align-items:center;background:white;padding:8px 15px;border-radius:var(--radius);border:1px solid #e2e8f0;width:300px;transition:.2s;}
.search-box:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,4,112,.1);}
.search-box input{border:none;outline:none;margin-left:10px;width:100%;font-size:.85rem;font-family:'Inter',sans-serif;}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-light));color:white;border:none;padding:12px 24px;border-radius:var(--radius);cursor:pointer;font-weight:600;font-size:.9rem;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 6px -1px rgba(79,4,112,.3);transition:.3s;text-decoration:none;}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 15px -5px rgba(79,4,112,.4);}
.table-container{background:linear-gradient(135deg,#fff 0%,#fef9ff 100%);border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid rgba(79,4,112,.1);overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:900px;}
th,td{padding:15px 20px;text-align:left;border-bottom:1px solid #e2e8f0;font-size:.85rem;}
th{background:linear-gradient(135deg,#f8fafc,#f1f5f9);color:var(--primary);font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#faf5ff;}
.status-badge{padding:4px 10px;border-radius:50px;font-size:.68rem;font-weight:700;display:inline-block;text-transform:uppercase;letter-spacing:.4px;}
.st-pending{background:#fed7aa;color:#92400e;}
.st-in_transit{background:#dbeafe;color:#1e40af;}
.st-awaiting{background:#fef3c7;color:#92400e;border:1px solid #fde047;}
.st-delivered{background:#d1fae5;color:#065f46;}
.st-cancelled{background:#f1f5f9;color:#475569;}
.st-flagged{background:#fee2e2;color:#991b1b;font-weight:800;}
.action-link{color:var(--muted);text-decoration:none;font-size:.75rem;padding:4px 8px;border-radius:6px;transition:.2s;display:inline-flex;align-items:center;gap:4px;}
.action-link.danger{color:var(--danger);} .action-link.danger:hover{background:#fee2e2;}
.action-link.success{color:var(--success);} .action-link.success:hover{background:#d1fae5;}
.action-link.info{color:var(--info);} .action-link.info:hover{background:#dbeafe;}
.row-flagged td{background:#fef2f2;}
.row-flagged:hover td{background:#fee2e2;}
.alert{padding:12px 18px;border-radius:var(--radius);margin-bottom:20px;font-size:.85rem;display:flex;align-items:center;gap:10px;border-left:4px solid;}
.alert-success{background:#ecfdf5;color:#065f46;border-color:var(--success);}
.alert-error{background:#fef2f2;color:#991b1b;border-color:var(--danger);}
.modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:center;justify-content:center;}
.modal-content{background:linear-gradient(135deg,#fff,#fef9ff);padding:30px;border-radius:var(--radius-lg);width:560px;max-width:90%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid rgba(79,4,112,.2);animation:slideDown .3s ease;}
@keyframes slideDown{from{transform:translateY(-30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1px solid #e2e8f0;padding-bottom:15px;}
.modal-header h3{font-size:1.2rem;font-weight:700;color:var(--secondary);}
.close-modal{font-size:26px;cursor:pointer;color:var(--muted);} .close-modal:hover{color:var(--danger);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
.form-group{margin-bottom:2px;}
.form-group.full{grid-column:span 2;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid #e2e8f0;border-radius:var(--radius);font-size:.875rem;font-family:'Inter',sans-serif;transition:.2s;}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,4,112,.1);}
/* POD REPORT MODAL */
.report-row{display:flex;justify-content:space-between;margin-bottom:12px;font-size:.9rem;padding-bottom:12px;border-bottom:1px solid #f1f5f9;}
.report-row:last-child{border-bottom:none;}
.report-label{color:var(--muted);font-weight:500;}
.report-value{font-weight:700;color:var(--secondary);}
.variance-box{padding:12px 16px;border-radius:var(--radius);margin:14px 0;font-size:.875rem;}
.var-ok{background:#f0fdf4;border-left:4px solid var(--success);color:#065f46;}
.var-bad{background:#fef2f2;border-left:4px solid var(--danger);color:#991b1b;}
.verified-box{background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid var(--success);border-radius:var(--radius);padding:12px 16px;font-size:13px;color:#065f46;display:flex;align-items:center;gap:8px;}
@media(max-width:768px){.sidebar{transform:translateX(-100%);}.main-content{margin-left:0;width:100%;padding:20px;}.form-grid{grid-template-columns:1fr;}.form-group.full{grid-column:span 1;}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="admin_dashboard.php" class="brand-logo"><span>🛢️</span> PetroTrack</a>
    </div>
    <ul class="nav-links">
        <li><a href="admin_dashboard.php"></i> Dashboard</a></li>
        <li><a href="vehicle_management.php"></i> Vehicle Management</a></li>
        <li><a href="delivery_management.php" class="active"></i> Delivery Orders</a></li>
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
        <div class="avatar"><?php echo strtoupper(substr($admin_name,0,1)); ?></div>
        <div>
            <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($admin_name); ?></div>
            <div style="font-size:11px;color:var(--muted);"><?php echo getRoleLabel($_SESSION['role']??''); ?></div>
        </div>
    </div>
    <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<!-- MAIN -->
<main class="main-content">

    <div class="page-header">
        <h2><i  style="color:var(--primary);"></i> Delivery Orders</h2>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div class="search-box">
                <i class="fas fa-search" style="color:var(--muted);"></i>
                <input type="text" id="searchInput" placeholder="Search plate, driver, destination..." onkeyup="filterTable()">
            </div>
            <button class="btn-primary" onclick="document.getElementById('addModal').style.display='flex'">
                <i class="fas fa-plus"></i> New Order
            </button>
        </div>
    </div>

    <?php if($message): ?>
    <div class="alert alert-<?php echo $msgType; ?>">
        <i class="fas fa-<?php echo $msgType=='success'?'check-circle':'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <div class="table-container">
        <table id="deliveryTable">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Truck & Driver</th>
                    <th>Route</th>
                    <th>Product</th>
                    <th>Volumes</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()):
                    $s = $row['delivery_status'];
                    $rowClass = $s=='flagged' ? 'row-flagged' : '';
                    $products = ['PMS'=>'PMS (Petrol)','AGO'=>'AGO (Diesel)','DPK'=>'DPK (Kerosene)','ATK'=>'ATK (Aviation)'];
                    $badgeClass = ['pending'=>'st-pending','in_transit'=>'st-in_transit','awaiting_verification'=>'st-awaiting','delivered'=>'st-delivered','cancelled'=>'st-cancelled','flagged'=>'st-flagged'][$s] ?? 'st-pending';
                    $badgeLabel = ['pending'=>'Pending','in_transit'=>'In Transit','awaiting_verification'=>'Awaiting Verification','delivered'=>'Delivered','cancelled'=>'Cancelled','flagged'=>'Flagged'][$s] ?? ucfirst($s);
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td><strong>#<?php echo $row['id']; ?></strong><br><small style="color:var(--muted);"><?php echo $row['delivery_date'] ?? '—'; ?></small></td>
                    <td>
                        <strong><?php echo htmlspecialchars($row['plate_number']); ?></strong><br>
                        <small style="color:var(--muted);"><?php echo htmlspecialchars($row['driver_name']??'Unassigned'); ?></small>
                    </td>
                    <td>
                        <small style="color:var(--muted);">From:</small> <?php echo htmlspecialchars($row['source']??'—'); ?><br>
                        <small style="color:var(--muted);">To:</small> <?php echo htmlspecialchars($row['destination']??'—'); ?>
                    </td>
                    <td><?php echo $products[$row['product_type']]??$row['product_type']; ?></td>
                    <td>
                        <span title="Loaded"><?php echo number_format($row['volume_loaded']); ?> L</span>
                        <?php if($row['driver_reported_vol']): ?>
                        <br><small style="color:var(--info);" title="Driver reported">Driver: <?php echo number_format($row['driver_reported_vol']); ?> L</small>
                        <?php endif; ?>
                        <?php if($row['station_reported_vol']): ?>
                        <br><small style="color:var(--success);" title="Station confirmed">Station: <?php echo number_format($row['station_reported_vol']); ?> L</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                        <?php if($s=='flagged'): ?>
                        <div style="font-size:10px;color:#991b1b;margin-top:4px;">⚠ Driver suspended</div>
                        <?php endif; ?>
                        <?php if($s=='awaiting_verification'): ?>
                        <div style="font-size:10px;color:#92400e;margin-top:4px;">Supervisor verifying...</div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if(in_array($s,['delivered','flagged'])): ?>
                            <a href="?action=view_report&id=<?php echo $row['id']; ?>" class="action-link info">
                                <i class="fas fa-file-invoice"></i> View POD
                            </a>
                        <?php endif; ?>
                        <?php if($s=='flagged'): ?>
                            <br><a href="?action=reinstate&id=<?php echo $row['id']; ?>" class="action-link success" onclick="return confirm('Reinstate driver and mark delivery as resolved?')">
                                <i class="fas fa-check-circle"></i> Reinstate
                            </a>
                        <?php elseif(!in_array($s,['delivered','cancelled','flagged','awaiting_verification'])): ?>
                            <a href="?action=cancel&id=<?php echo $row['id']; ?>" class="action-link danger" onclick="return confirm('Cancel this delivery order?')">
                                <i class="fas fa-times-circle"></i> Cancel
                            </a>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:.7rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:40px;color:var(--muted);">
                        <i class="fas fa-inbox" style="font-size:2rem;display:block;opacity:.3;margin-bottom:10px;"></i>
                        No deliveries yet. Click "New Order" to create one.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- CREATE ORDER MODAL -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle" style="color:var(--primary);"></i> Create New Delivery</h3>
            <span class="close-modal" onclick="document.getElementById('addModal').style.display='none'">&times;</span>
        </div>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label>Source Depot</label>
                    <select name="source" required>
                        <option value="Apapa Depot, Lagos">Apapa Depot, Lagos</option>
                        <option value="Mosimi Depot, Ogun">Mosimi Depot, Ogun</option>
                        <option value="Warri Depot, Delta">Warri Depot, Delta</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Destination Station</label>
                    <select name="station_id" required>
                        <option value="">Select Station</option>
                        <?php $sr=$conn->query("SELECT id,name,location FROM stations ORDER BY name");
                        while($ss=$sr->fetch_assoc()): ?>
                        <option value="<?php echo $ss['id']; ?>"><?php echo htmlspecialchars($ss['name'].' — '.$ss['location']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assign Truck</label>
                    <select name="truck_id" required>
                        <option value="">Select Truck</option>
                        <?php $tr=$conn->query("SELECT v.id, v.plate_number, u.name as driver FROM vehicles v LEFT JOIN users u ON v.driver_id=u.id WHERE v.status='active'");
                        while($tt=$tr->fetch_assoc()): ?>
                        <option value="<?php echo $tt['id']; ?>"><?php echo htmlspecialchars($tt['plate_number'].($tt['driver']?' ('.$tt['driver'].')':' (no driver)')); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Product Type</label>
                    <select name="product_type" required>
                        <option value="PMS">PMS (Petrol)</option>
                        <option value="AGO">AGO (Diesel)</option>
                        <option value="DPK">DPK (Kerosene)</option>
                        <option value="ATK">ATK (Aviation)</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>Volume (Litres)</label>
                    <input type="number" name="volume" placeholder="e.g. 33000" min="1" required>
                </div>
            </div>
            <button type="submit" name="create_delivery" class="btn-primary" style="width:100%;justify-content:center;margin-top:10px;">
                <i class="fas fa-check-circle"></i> Create Order
            </button>
        </form>
    </div>
</div>

<!-- POD / REPORT MODAL -->
<div id="reportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-file-contract" style="color:var(--primary);"></i> Delivery Report #<?php echo $report_data['id']??''; ?></h3>
            <span class="close-modal" onclick="document.getElementById('reportModal').style.display='none';window.location.href='delivery_management.php';">&times;</span>
        </div>
        <?php if($report_data): 
            $dv = $report_data['driver_reported_vol'] ?? 0;
            $sv = $report_data['station_reported_vol'] ?? 0;
            $variance = abs($dv - $sv);
            $varOk = $variance <= 100;
        ?>
        <div class="report-row"><span class="report-label">Truck / Driver</span><span class="report-value"><?php echo htmlspecialchars($report_data['plate_number'].' / '.($report_data['driver_name']??'N/A')); ?></span></div>
        <div class="report-row"><span class="report-label">Logistics Manager</span><span class="report-value" style="color:var(--primary);"><?php echo htmlspecialchars($report_data['manager_name']??$admin_name); ?></span></div>
        <div class="report-row"><span class="report-label">Product</span><span class="report-value"><?php echo htmlspecialchars($report_data['product_type']); ?></span></div>
        <div class="report-row"><span class="report-label">Volume Loaded at Depot</span><span class="report-value"><?php echo number_format($report_data['volume_loaded']); ?> L</span></div>
        <div class="report-row"><span class="report-label">Driver Reported Delivered</span><span class="report-value" style="color:var(--info);"><?php echo number_format($dv); ?> L</span></div>
        <div class="report-row"><span class="report-label">Station Dip Reading</span><span class="report-value" style="color:var(--success);"><?php echo number_format($sv); ?> L</span></div>
        <div class="variance-box <?php echo $varOk?'var-ok':'var-bad'; ?>">
            <strong>Variance: <?php echo number_format($variance); ?> L</strong><br>
            <span style="font-size:.8em;"><?php echo $variance==0?'Perfect match':($varOk?'Within acceptable tolerance (±100L)':'Exceeds tolerance — delivery flagged'); ?></span>
        </div>
        <?php if($report_data['discrepancy_reason']): ?>
        <div class="report-row"><span class="report-label" style="color:var(--danger);">Reason</span><span class="report-value"><?php echo htmlspecialchars($report_data['discrepancy_reason']); ?></span></div>
        <?php endif; ?>
        <?php
        // Parse verifier name from the stamp stored in supervisor_signature
        // Stamp format: "Verified by: NAME | PIN-authorised | DATE | Station dip: XL"
        $verifier_name = null;
        $verifier_time = null;
        if (!empty($report_data['supervisor_signature'])) {
            $stamp = $report_data['supervisor_signature'];
            if (strpos($stamp, 'Verified by:') !== false && strpos($stamp, 'data:image') === false) {
                // It's a text stamp — parse it
                if (preg_match('/Verified by:\s*(.+?)\s*\|/', $stamp, $m)) $verifier_name = trim($m[1]);
                if (preg_match('/\|\s*PIN-authorised\s*\|\s*(.+?)\s*\|/', $stamp, $m)) $verifier_time = trim($m[1]);
            }
        }
        ?>
        <?php if($verifier_name): ?>
        <div class="report-row">
            <span class="report-label">Verified By</span>
            <span class="report-value" style="color:var(--success);">
                <i class="fas fa-user-check" style="margin-right:5px;"></i><?php echo htmlspecialchars($verifier_name); ?>
            </span>
        </div>
        <?php if($verifier_time): ?>
        <div class="report-row">
            <span class="report-label">Verified At</span>
            <span class="report-value"><?php echo htmlspecialchars($verifier_time); ?></span>
        </div>
        <?php endif; ?>
        <div class="verified-box" style="margin-top:4px;margin-bottom:4px;">
            <i class="fas fa-check-circle"></i>
            PIN-authorised verification 
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end;">
            <button onclick="document.getElementById('reportModal').style.display='none';window.location.href='delivery_management.php';" style="padding:9px 20px;background:#f1f5f9;border:none;border-radius:var(--radius);cursor:pointer;font-weight:600;">Close</button>
            <button onclick="window.print()" class="btn-primary" style="padding:9px 20px;"><i class="fas fa-print"></i> Print</button>
        </div>
        <?php else: ?>
        <p style="text-align:center;color:var(--muted);padding:20px 0;">No verification data available for this delivery.</p>
        <?php endif; ?>
    </div>
</div>

<script>
window.onload = function() {
    const p = new URLSearchParams(window.location.search);
    if (p.get('action') === 'view_report') document.getElementById('reportModal').style.display='flex';
}
window.onclick = function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display='none';
}
function filterTable() {
    var f = document.getElementById('searchInput').value.toUpperCase();
    var rows = document.querySelectorAll('#deliveryTable tbody tr');
    rows.forEach(function(tr) {
        var text = tr.textContent || tr.innerText;
        tr.style.display = text.toUpperCase().includes(f) ? '' : 'none';
    });
}
</script>
</body>
</html>