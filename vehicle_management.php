<?php
session_start();
include 'config/db_connect.php';

$required_role = 'admin';
include 'auth_check.php';

// Helper function for role label
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

$message = "";
$msgType = "";

// HANDLE ASSIGN DRIVER
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_driver'])) {
    $vehicle_id = $_POST['vehicle_id'];
    $driver_id  = $_POST['driver_id'];

    // Verify this vehicle belongs to this manager
    $chk = $conn->prepare("SELECT id FROM vehicles WHERE id = ? AND managed_by = ?");
    $chk->bind_param("ii", $vehicle_id, $_SESSION['user_id']);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $message = "You do not have permission to assign a driver to this vehicle.";
        $msgType  = "error";
        $chk->close();
    } else {
        $chk->close();
        // Unassign this driver from any other truck first
        $unassign = $conn->prepare("UPDATE vehicles SET driver_id = NULL WHERE driver_id = ?");
        $unassign->bind_param("i", $driver_id);
        $unassign->execute();
        $unassign->close();

        // Assign driver to selected truck
        $assign = $conn->prepare("UPDATE vehicles SET driver_id = ? WHERE id = ? AND managed_by = ?");
        $assign->bind_param("iii", $driver_id, $vehicle_id, $_SESSION['user_id']);
        if ($assign->execute()) {
            $message = "Driver assigned successfully!";
            $msgType  = "success";
        } else {
            $message = "Error assigning driver: " . $assign->error;
            $msgType  = "error";
        }
        $assign->close();
    }
    echo "<script>window.location.href='vehicle_management.php';</script>";
    exit();
}

// HANDLE UNASSIGN DRIVER
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['unassign_driver'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $unassign = $conn->prepare("UPDATE vehicles SET driver_id = NULL WHERE id = ?");
    $unassign->bind_param("i", $vehicle_id);
    if ($unassign->execute()) {
        $message = "Driver unassigned successfully.";
        $msgType  = "success";
    } else {
        $message = "Error unassigning driver.";
        $msgType  = "error";
    }
    $unassign->close();
    echo "<script>window.location.href='vehicle_management.php';</script>";
    exit();
}

// 1. HANDLE DELETE VEHICLE (POST only)
// Trucks with delivery history are set to inactive instead, because deleting
// a truck would cascade-delete its delivery records.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_vehicle'])) {
    $id = (int) $_POST['vehicle_id'];

    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM deliveries WHERE truck_id = ?");
    $chk->bind_param("i", $id);
    $chk->execute();
    $has_history = $chk->get_result()->fetch_assoc()['c'] > 0;
    $chk->close();

    if ($has_history) {
        $del_stmt = $conn->prepare("UPDATE vehicles SET status = 'inactive', driver_id = NULL WHERE id = ?");
        $ok_message = "This vehicle has delivery history, so it was set to inactive instead of deleted.";
    } else {
        $del_stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ?");
        $ok_message = "Vehicle deleted successfully.";
    }
    $del_stmt->bind_param("i", $id);
    if ($del_stmt->execute()) {
        $message = $ok_message;
        $msgType = "success";
    } else {
        $message = "Error removing vehicle.";
        $msgType = "error";
    }
    $del_stmt->close();
    echo "<script>window.location.href='vehicle_management.php';</script>";
    exit();
}

// 2. HANDLE ADD VEHICLE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_vehicle'])) {
    $plate = $_POST['plate_number'];
    $capacity = $_POST['capacity'];
    $status = $_POST['status'];
    
    $sql = "INSERT INTO vehicles (plate_number, capacity, status) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("sis", $plate, $capacity, $status);
        if ($stmt->execute()) {
            $message = "New vehicle added to fleet!";
            $msgType = "success";
        } else {
            $message = "Error: " . $stmt->error;
            $msgType = "error";
        }
        $stmt->close();
    }
}

// 3. FETCH ALL VEHICLES
$sql = "SELECT v.*, u.name as driver_name 
        FROM vehicles v 
        LEFT JOIN users u ON v.driver_id = u.id 
        ORDER BY v.id DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Management - PetroTrack</title>
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

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(135deg, #ffffff 0%, #faf5ff 100%);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
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
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            gap: 12px;
            font-size: 0.95rem;
        }
        .nav-links li a:hover, .nav-links li a.active {
            color: var(--primary);
            background: #faf5ff;
            border-right: 4px solid var(--primary);
        }

        .user-profile { padding: 20px; border-top: 1px solid #f0f0f0; }
        .profile-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px;
        }
        .btn-logout {
            width: 100%; background: var(--secondary); color: white; border: none; padding: 10px;
            border-radius: 50px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
            text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s;
        }
        .btn-logout:hover { background: var(--primary); transform: translateY(-2px); }

        /* --- MAIN CONTENT --- */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; width: calc(100% - var(--sidebar-width)); }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { font-size: 28px; font-weight: 700; color: var(--secondary); }

        /* --- BUTTONS --- */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-bright) 100%);
            color: white; border: none; padding: 12px 24px; border-radius: var(--radius);
            cursor: pointer; font-weight: 600; font-size: 0.9rem;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);
            transition: all 0.3s;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 15px -5px rgba(139, 92, 246, 0.4); }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: white; border: none; padding: 6px 12px; border-radius: 8px;
            cursor: pointer; font-size: 0.75rem; font-weight: 500;
            transition: all 0.2s;
        }
        .btn-danger:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3); }

        /* --- DATA TABLE --- */
        .table-container {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(139, 92, 246, 0.1);
            overflow-x: auto;
        }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--primary);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #faf5ff; }

        .status-badge { padding: 4px 12px; border-radius: 50px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-maintenance { background: #fed7aa; color: #92400e; }
        .status-inactive { background: #fee2e2; color: #991b1b; }

        .action-btn {
            background: none; border: none; cursor: pointer; font-size: 1.1rem;
            margin-right: 12px; transition: all 0.2s; padding: 6px;
            border-radius: 8px;
        }
        .action-btn.assign { color: var(--primary); }
        .action-btn.assign:hover { background: #f3e8ff; transform: scale(1.05); }
        .action-btn.delete { color: var(--danger); }
        .action-btn.delete:hover { background: #fee2e2; transform: scale(1.05); }

        /* --- MODAL (POPUP) --- */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            padding: 30px; border-radius: var(--radius-lg);
            width: 450px; max-width: 90%;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(139, 92, 246, 0.2);
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { font-size: 1.3rem; font-weight: 700; color: var(--secondary); }
        .close-modal { font-size: 28px; cursor: pointer; color: var(--text-muted); transition: 0.2s; }
        .close-modal:hover { color: var(--danger); }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0;
            border-radius: var(--radius); font-size: 0.9rem; font-family: 'Inter', sans-serif;
            transition: 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .success-msg, .error-msg {
            padding: 12px 18px; border-radius: var(--radius); margin-bottom: 20px;
            font-size: 0.9rem; display: flex; align-items: center; gap: 10px;
        }
        .success-msg { background: #ecfdf5; color: #065f46; border-left: 4px solid var(--success); }
        .error-msg { background: #fef2f2; color: #991b1b; border-left: 4px solid var(--danger); }


        /* --- ACTION DROPDOWN --- */
        .action-dropdown { position: relative; display: inline-block; }
        .dropdown-toggle {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-bright) 100%);
            color: white; border: none; padding: 7px 14px; border-radius: 8px;
            cursor: pointer; font-size: 0.8rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.2s;
        }
        .dropdown-toggle:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(139,92,246,0.3); }
        .dropdown-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px);
            background: white; border: 1px solid #e2e8f0; border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 170px;
            z-index: 999; overflow: hidden;
        }
        .dropdown-menu.open { display: block; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
        .dropdown-item {
            display: flex; align-items: center; gap: 9px;
            width: 100%; padding: 10px 16px; border: none; background: none;
            font-size: 0.85rem; font-weight: 500; cursor: pointer;
            text-decoration: none; color: var(--text-main);
            transition: background 0.15s; font-family: 'Inter', sans-serif;
        }
        .dropdown-item:hover { background: #f8fafc; }
        .dropdown-item.assign-item { color: var(--primary); }
        .dropdown-item.assign-item:hover { background: #faf5ff; }
        .dropdown-item.unassign-item { color: var(--warning); }
        .dropdown-item.unassign-item:hover { background: #fffbeb; }
        .dropdown-item.delete-item { color: var(--danger); }
        .dropdown-item.delete-item:hover { background: #fef2f2; }
        .dropdown-item + .dropdown-item { border-top: 1px solid #f1f5f9; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 1000; }
            .main-content { margin-left: 0; width: 100%; padding: 20px; }
            .page-header { flex-direction: column; text-align: center; }
            .btn-primary { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="brand-logo">
                <span>🛢️</span> PetroTrack
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="admin_dashboard.php"></i> Dashboard</a></li>
            <li><a href="vehicle_management.php" class="active"></i> Vehicle Management</a></li>
            <li><a href="delivery_management.php"></i> Delivery Orders</a></li>
            <li><a href="user_management.php"></i> User Management</a></li>
            <li><a href="station_management.php"></i> Station Management</a></li>
            <li><a href="tracking_map.php"></i> Simulated Tracking</a></li>
            <li><a href="messages.php"></i> Messages
                <?php
                $unread = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id = ? AND is_read = 0");
                $unread->bind_param("i", $_SESSION['user_id']);
                $unread->execute();
                $uc = $unread->get_result()->fetch_assoc()['c'];
                if ($uc > 0) echo '<span style="background:#ef4444;color:white;font-size:10px;padding:2px 7px;border-radius:10px;margin-left:8px;">'.$uc.'</span>';
                ?>
            </a></li>
            <li><a href="reports.php"></i> Reports</a></li>
            <li><a href="profile.php"></i> My Profile</a></li>
        </ul>
        <div class="user-profile">
            <div class="profile-info">
                <div class="avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div>
                    <div style="font-weight: 700; font-size: 14px;"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div style="font-size: 11px; color: var(--text-muted);"><?php echo getRoleLabel($_SESSION['role'] ?? ''); ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        
        <div class="page-header">
            <h2></i> Fleet Management</h2>
            <button class="btn-primary" onclick="openModal()">
                <i class="fas fa-plus"></i> Add Vehicle
            </button>
        </div>

        <?php if ($message): ?>
            <div class="<?php echo ($msgType == 'success') ? 'success-msg' : 'error-msg'; ?>">
                <i class="fas <?php echo ($msgType == 'success') ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Plate Number</th>
                        <th>Capacity</th>
                        <th>Assigned Driver</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['plate_number']); ?></strong></td>
                                <td><?php echo number_format($row['capacity']); ?> L</td>
                                <td>
                                    <?php 
                                        if ($row['driver_name']) {
                                            echo '<i class="fas fa-user-check" style="color: var(--success);"></i> ' . htmlspecialchars($row['driver_name']);
                                        } else {
                                            echo '<span style="color:#aaa; font-style:italic;"><i class="fas fa-user-slash"></i> Unassigned</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                        $statusClass = 'status-inactive';
                                        if ($row['status'] == 'active') $statusClass = 'status-active';
                                        if ($row['status'] == 'maintenance') $statusClass = 'status-maintenance';
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-dropdown">
                                        <button class="dropdown-toggle" onclick="toggleDropdown(this); event.stopPropagation();">
                                            Actions <i class="fas fa-chevron-down" style="font-size:10px;"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php if (!$row['driver_name']): ?>
                                            <button class="dropdown-item assign-item"
                                                onclick="openAssignModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['plate_number']); ?>'); closeAllDropdowns();">
                                                <i class="fas fa-user-plus"></i> Assign Driver
                                            </button>
                                            <?php else: ?>
                                            <form method="POST" action="vehicle_management.php" style="margin:0;">
                                                <input type="hidden" name="vehicle_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="unassign_driver" class="dropdown-item unassign-item"
                                                    onclick="return confirm('Unassign driver from this vehicle?');">
                                                    <i class="fas fa-user-slash"></i> Unassign Driver
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" action="vehicle_management.php" style="margin:0;">
                                                <input type="hidden" name="vehicle_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="delete_vehicle" class="dropdown-item delete-item"
                                                    onclick="return confirm('Remove this vehicle? If it has delivery history it will be set to inactive instead.');">
                                                    <i class="fas fa-trash-alt"></i> Remove Vehicle
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted);">
                                <i  style="margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                No vehicles found. Click "Add Vehicle" to start.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Add Vehicle Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle" style="color: var(--primary);"></i> Add New Vehicle</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" action="vehicle_management.php">
                <div class="form-group">
                    <label>Plate Number</label>
                    <input type="text" name="plate_number" placeholder="e.g., ABC-123-XJ" required>
                </div>
                <div class="form-group">
                    <label>Capacity (Litres)</label>
                    <input type="number" name="capacity" placeholder="33000" required>
                </div>
                <div class="form-group">
                    <label>Initial Status</label>
                    <select name="status">
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <button type="submit" name="add_vehicle" class="btn-primary" style="width:100%; justify-content:center;">
                    <i class="fas fa-save"></i> Save Vehicle
                </button>
            </form>
        </div>
    </div>

    <!-- Assign Driver Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus" style="color: var(--primary);"></i> Assign Driver</h3>
                <span class="close-modal" onclick="closeAssignModal()">&times;</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:15px;">
                Assigning driver to truck: <strong id="assignTruckLabel" style="color: var(--primary);"></strong>
            </p>
            <form method="POST" action="vehicle_management.php">
                <input type="hidden" name="vehicle_id" id="assignVehicleId">
                <div class="form-group">
                    <label>Select Driver</label>
                    <select name="driver_id" required>
                        <option value="">-- Select a Driver --</option>
                        <?php
                        $drivers_res = $conn->query("SELECT id, name, phone FROM users WHERE role = 'driver' AND status = 'active' ORDER BY name");
                        while($d = $drivers_res->fetch_assoc()):
                        ?>
                            <option value="<?php echo $d['id']; ?>">
                                <?php echo htmlspecialchars($d['name']); ?> — <?php echo htmlspecialchars($d['phone'] ?? 'No phone'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" name="assign_driver" class="btn-primary" style="width:100%; justify-content:center;">
                    <i class="fas fa-check-circle"></i> Assign Driver
                </button>
            </form>
        </div>
    </div>

    <script>
        var modal = document.getElementById("addModal");
        var assignModal = document.getElementById("assignModal");
        
        function openModal() {
            modal.style.display = "flex";
        }

        function closeModal() {
            modal.style.display = "none";
        }

        function openAssignModal(vehicleId, plateNumber) {
            document.getElementById("assignVehicleId").value = vehicleId;
            document.getElementById("assignTruckLabel").textContent = plateNumber;
            assignModal.style.display = "flex";
        }

        function closeAssignModal() {
            assignModal.style.display = "none";
        }

        function toggleDropdown(btn) {
            var menu = btn.nextElementSibling;
            var isOpen = menu.classList.contains("open");
            closeAllDropdowns();
            if (!isOpen) menu.classList.add("open");
        }

        function closeAllDropdowns() {
            document.querySelectorAll(".dropdown-menu.open").forEach(function(m) {
                m.classList.remove("open");
            });
        }

        document.addEventListener("click", function() { closeAllDropdowns(); });

        window.onclick = function(event) {
            if (event.target == modal) closeModal();
            if (event.target == assignModal) closeAssignModal();
        }
    </script>
    
</body>
</html>