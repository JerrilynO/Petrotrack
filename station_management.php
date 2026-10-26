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

// 1. HANDLE DELETE STATION
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];

    $check = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE station_id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $linked_users = $check->get_result()->fetch_assoc()['c'];

    $check2 = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE station_id = ?");
    $check2->bind_param("i", $id);
    $check2->execute();
    $linked_deliveries = $check2->get_result()->fetch_assoc()['c'];

    if ($linked_users > 0 || $linked_deliveries > 0) {
        $message = "Cannot delete — this station has $linked_users supervisor(s) and $linked_deliveries delivery/deliveries linked to it.";
        $msgType = "error";
    } else {
        $stmt = $conn->prepare("DELETE FROM stations WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Station deleted successfully.";
            $msgType = "success";
        } else {
            $message = "Error deleting station.";
            $msgType = "error";
        }
    }
    echo "<script>window.location.href='station_management.php';</script>";
    exit();
}

// 2. HANDLE ADD STATION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_station'])) {
    $name = trim($_POST['name']);
    $location = trim($_POST['location']);

    if (empty($name) || empty($location)) {
        $message = "Station name and location are required.";
        $msgType = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO stations (name, location) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $location);
        if ($stmt->execute()) {
            $message = "Station added successfully!";
            $msgType = "success";
        } else {
            $message = "Error: " . $stmt->error;
            $msgType = "error";
        }
        $stmt->close();
    }
    echo "<script>window.location.href='station_management.php';</script>";
    exit();
}

// 3. HANDLE EDIT STATION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_station'])) {
    $id = $_POST['station_id'];
    $name = trim($_POST['name']);
    $location = trim($_POST['location']);

    $stmt = $conn->prepare("UPDATE stations SET name = ?, location = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $location, $id);
    if ($stmt->execute()) {
        $message = "Station updated successfully!";
        $msgType = "success";
    } else {
        $message = "Error updating station.";
        $msgType = "error";
    }
    $stmt->close();
    echo "<script>window.location.href='station_management.php';</script>";
    exit();
}

// 4. FETCH ALL STATIONS WITH COUNTS
 $sql = "SELECT s.*, 
        (SELECT COUNT(*) FROM users WHERE station_id = s.id) as supervisor_count,
        (SELECT COUNT(*) FROM deliveries WHERE station_id = s.id) as delivery_count,
        (SELECT COUNT(*) FROM deliveries WHERE station_id = s.id AND delivery_status = 'pending') as pending_count
        FROM stations s 
        ORDER BY s.name ASC";
 $result = $conn->query($sql);

 $total_stations = $conn->query("SELECT COUNT(*) as c FROM stations")->fetch_assoc()['c'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Station Management - PetroTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #0986B8; 
            --primary-light: #7c2d9e;
            --secondary: #0f172a;
            --bg-body: #f8fafc;
            --bg-sidebar: #1e293b; /* Only for mobile toggle if needed */
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #0986B8;
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius: 12px;
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR (Original Look) --- */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(135deg, #fff 0%, #faf5ff 100%);
            height: 100vh;
            position: fixed;
            left: 0; top: 0;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid var(--border); }
        .brand-logo {
            font-size: 1.2rem;
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
            transition: all 0.3s; gap: 12px; font-size: 0.9rem;
        }
        .nav-links li a:hover, .nav-links li a.active {
            color: var(--primary);
            background: #faf5ff;
            border-right: 4px solid var(--primary);
        }

        .user-profile { padding: 20px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px; flex-shrink: 0;
        }
        
        .user-info div:first-child { font-weight: 700; font-size: 13px; color: var(--secondary); }
        .user-info div:last-child { font-size: 11px; color: var(--text-muted); }

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
            padding: 32px;
            width: calc(100% - var(--sidebar-width));
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .page-title { font-size: 24px; font-weight: 700; color: var(--secondary); }

        /* --- BUTTONS --- */
        .btn-primary {
            background: var(--primary);
            color: white; border: none; padding: 10px 20px; border-radius: 8px;
            cursor: pointer; font-weight: 600; font-size: 0.9rem;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(79, 4, 112, 0.2);
            transition: all 0.2s; text-decoration: none;
        }
        .btn-primary:hover { transform: translateY(-1px); background: var(--primary-light); }

        /* --- STATS GRID --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: var(--bg-card);
            padding: 18px; /* Reduced from 24px */
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .stat-icon {
            width: 40px; /* Reduced from 48px */
            height: 40px;
            border-radius: 10px;
            background: #f3e8ff;
            color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; /* Reduced from 20px */
        }
        .stat-info h4 { font-size: 0.8rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-info .value { font-size: 1.5rem; font-weight: 800; color: var(--secondary); margin-top: 4px; }

        /* --- STATIONS GRID --- */
        .stations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); /* Slightly smaller min-width */
            gap: 20px;
        }

        .station-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 18px; /* Reduced from 24px */
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }
        .station-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); border-color: #e2e8f0; }

        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; /* Reduced from 16px */ }
        .station-icon-bg {
            width: 32px; /* Reduced from 40px */
            height: 32px;
            border-radius: 50%;
            background: #eff6ff; color: #3b82f6;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        
        .station-name { font-size: 1.05rem; font-weight: 700; color: var(--secondary); margin-bottom: 2px; }
        .station-location { font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }

        .stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin: 12px 0; /* Reduced from 20px */
            padding: 10px 0; /* Reduced from 16px */
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .stat-item { text-align: center; }
        .stat-item .num { font-size: 1.1rem; font-weight: 700; color: var(--secondary); display: block; }
        .stat-item .label { font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-top: 2px; display: block; }

        .card-actions { display: flex; gap: 10px; margin-top: auto; }
        .btn-action {
            flex: 1; padding: 6px; border-radius: 6px; border: 1px solid var(--border);
            background: white; color: var(--text-main); font-size: 0.8rem; font-weight: 500;
            cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-action:hover { background: #f9fafb; border-color: #d1d5db; }
        .btn-action.delete:hover { background: #fef2f2; color: var(--danger); border-color: #fca5a5; }

        /* --- MODAL --- */
        .modal {
            display: none;
            position: fixed; z-index: 100;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background: var(--bg-card);
            padding: 24px; /* Reduced from 32px */
            border-radius: 12px;
            width: 400px; /* Reduced from 450px */
            max-width: 90%;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }
        .modal-header { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;}
        .modal-header h3 { font-size: 1.1rem; font-weight: 700; color: var(--secondary); }
        
        .form-group { margin-bottom: 16px; } /* Reduced from 20px */
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem; color: var(--secondary); }
        .form-group input {
            width: 100%; padding: 8px 12px; /* Reduced vertical padding */
            border: 1px solid var(--border); border-radius: 6px;
            font-size: 0.9rem; transition: 0.2s;
        }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 4, 112, 0.1); }

        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); }
        .close-modal:hover { color: var(--danger); }

        /* --- ALERTS --- */
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 0.85rem; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #d1fae5; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .empty-state {
            text-align: center; padding: 60px 20px;
            background: white; border-radius: var(--radius);
            border: 1px dashed var(--border);
        }
        .empty-state i { font-size: 48px; color: #cbd5e1; margin-bottom: 16px; }
        .empty-state h3 { color: var(--secondary); margin-bottom: 8px; }
        .empty-state p { color: var(--text-muted); font-size: 0.9rem; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; width: 100%; padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="admin_dashboard.php" class="brand-logo">
                <span>🛢️</span> PetroTrack
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="admin_dashboard.php"></i> Dashboard</a></li>
            <li><a href="vehicle_management.php"></i> Vehicle Management</a></li>
            <li><a href="delivery_management.php"></i> Delivery Orders</a></li>
            <li><a href="user_management.php"></i> User Management</a></li>
            <li><a href="station_management.php" class="active"></i> Station Management</a></li>
            <li><a href="tracking_map.php"></i> Simulated Tracking</a></li>
            <li><a href="messages.php"></i> Messages
                <?php
                $unread = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id = ? AND is_read = 0");
                $unread->bind_param("i", $_SESSION['user_id']);
                $unread->execute();
                $uc = $unread->get_result()->fetch_assoc()['c'];
                if ($uc > 0) echo '<span style="background:#ef4444;color:white;font-size:10px;padding:2px 6px;border-radius:10px;margin-left:auto;">'.$uc.'</span>';
                ?>
            </a></li>
            <li><a href="reports.php"></i> Reports</a></li>
            <li><a href="profile.php"></i> My Profile</a></li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
            <div class="user-info">
                <div><?php echo htmlspecialchars($admin_name); ?></div>
                <div><?php echo getRoleLabel($_SESSION['role'] ?? ''); ?></div>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">

        <div class="page-header">
            <div class="page-title">Station Management</div>
            <button class="btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Station
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert <?php echo ($msgType == 'success') ? 'alert-success' : 'alert-error'; ?>">
                <i class="fas fa-<?php echo ($msgType == 'success') ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class=></i></div>
                <div class="stat-info">
                    <h4>Total Stations</h4>
                    <div class="value"><?php echo $total_stations; ?></div>
                </div>
            </div>
        </div>

        <!-- Stations Grid -->
        <?php if ($result->num_rows > 0): ?>
        <div class="stations-grid">
            <?php while ($row = $result->fetch_assoc()): ?>
            <div class="station-card">
                <div class="card-header">
                    <div>
                        <div class="station-name"><?php echo htmlspecialchars($row['name']); ?></div>
                        <div class="station-location"></i> <?php echo htmlspecialchars($row['location']); ?></div>
                    </div>
                    <div class=></i></div>
                </div>

                <div class="stats-row">
                    <div class="stat-item">
                        <span class="num"><?php echo $row['supervisor_count']; ?></span>
                        <span class="label">Supervisors</span>
                    </div>
                    <div class="stat-item">
                        <span class="num"><?php echo $row['delivery_count']; ?></span>
                        <span class="label">Deliveries</span>
                    </div>
                    <div class="stat-item">
                        <span class="num"><?php echo $row['pending_count']; ?></span>
                        <span class="label" style="color:var(--warning)">Pending</span>
                    </div>
                </div>

                <div class="card-actions">
                    <button class="btn-action" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['location'], ENT_QUOTES); ?>')">
                        <i class="fas fa-pencil-alt"></i> Edit
                    </button>
                    <a href="station_management.php?delete=<?php echo $row['id']; ?>" 
                       class="btn-action delete"
                       onclick="return confirm('Delete <?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>? This cannot be undone.')">
                        <i class="fas fa-trash-alt"></i> Delete
                    </a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-gas-pump"></i>
                <h3>No Stations Found</h3>
                <p>You haven't added any stations yet. Click "Add New Station" to get started.</p>
            </div>
        <?php endif; ?>

    </main>

    <!-- Add Station Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeAddModal()">&times;</button>
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle" style="color: var(--primary);"></i> Add New Station</h3>
            </div>
            <form method="POST" action="station_management.php">
                <div class="form-group">
                    <label>Station Name</label>
                    <input type="text" name="name" placeholder="e.g. Total Ikeja" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g. Ikeja, Lagos" required>
                </div>
                <button type="submit" name="add_station" class="btn-primary" style="width:100%; justify-content:center;">
                    <i class="fas fa-save"></i> Save Station
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Station Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeEditModal()">&times;</button>
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color: var(--primary);"></i> Edit Station</h3>
            </div>
            <form method="POST" action="station_management.php">
                <input type="hidden" name="station_id" id="editStationId">
                <div class="form-group">
                    <label>Station Name</label>
                    <input type="text" name="name" id="editStationName" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" id="editStationLocation" required>
                </div>
                <button type="submit" name="edit_station" class="btn-primary" style="width:100%; justify-content:center;">
                    <i class="fas fa-save"></i> Update Station
                </button>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
        function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

        function openEditModal(id, name, location) {
            document.getElementById('editStationId').value = id;
            document.getElementById('editStationName').value = name;
            document.getElementById('editStationLocation').value = location;
            document.getElementById('editModal').style.display = 'flex';
        }
        function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }

        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) closeAddModal();
            if (event.target == document.getElementById('editModal')) closeEditModal();
        }
    </script>

</body>
</html>