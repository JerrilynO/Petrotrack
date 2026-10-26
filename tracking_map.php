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

// FETCH ADMIN NAME (To display in the bottom user profile like the other pages)
$admin_name = 'Admin User'; // Default fallback
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user_sql = "SELECT name FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_data = $user_result->fetch_assoc()) {
        $admin_name = $user_data['name'];
    }
    $user_stmt->close();
}

// 1. FETCH ALL TRUCKS WITH GPS COORDINATES (Admin sees all vehicles)
$sql = "SELECT v.id, v.plate_number, v.latitude, v.longitude, v.status as v_status, u.name as driver_name 
        FROM vehicles v 
        LEFT JOIN users u ON v.driver_id = u.id 
        WHERE v.latitude IS NOT NULL 
        AND v.longitude IS NOT NULL";
        
$map_stmt = $conn->prepare($sql);
$map_stmt->execute();
$result = $map_stmt->get_result();
$trucks = [];

while ($row = $result->fetch_assoc()) {
    $trucks[] = $row;
}
$map_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking - PetroTrack</title>
    
    <!-- 1. LEAFLET CSS (For the Map) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    
    <!-- 2. FONT AWESOME (For Icons) & Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* --- CORE VARIABLES (Matching Admin Dashboard with #4f0470) --- */
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f3e8ff 50%, #f8fafc 100%);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow: hidden;
        }

        /* --- SIDEBAR (Matching Admin Dashboard) --- */
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

        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .brand-logo {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .brand-logo span { color: var(--primary); }

        .nav-links {
            list-style: none;
            padding: 20px 0;
            flex: 1;
        }

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

        .user-profile {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .profile-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .btn-logout {
            width: 100%;
            background: var(--secondary);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-logout:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }

        /* --- MAIN CONTENT & MAP --- */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            position: relative;
            width: calc(100% - var(--sidebar-width));
            height: 100vh;
        }

        #map {
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        /* --- FLOATING INFO PANEL (Techy Look with Purple theme) --- */
        .info-panel {
            position: absolute;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(250, 245, 255, 0.98) 100%);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 320px;
            z-index: 999;
            border: 1px solid rgba(79, 4, 112, 0.2);
        }

        .info-panel h3 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-panel h3 i { color: var(--primary); }
        
        .truck-list {
            max-height: 320px;
            overflow-y: auto;
        }
        
        .truck-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 8px;
            margin-bottom: 2px;
        }
        .truck-item:hover {
            background: #faf5ff;
            transform: translateX(-3px);
        }
        
        .truck-info strong {
            display: block;
            font-size: 0.9rem;
            color: var(--secondary);
        }
        .truck-info small {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-active { background: var(--success); box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2); }
        .status-inactive { background: var(--danger); box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2); }

        .live-badge {
            margin-top: 15px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            font-size: 0.7rem;
            color: var(--text-muted);
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .live-badge i { color: var(--success); font-size: 0.6rem; }

        /* Custom scrollbar */
        .truck-list::-webkit-scrollbar {
            width: 4px;
        }
        .truck-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .truck-list::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 1000; }
            .main-content { margin-left: 0; width: 100%; }
            .info-panel { width: auto; left: 15px; right: 15px; bottom: 15px; top: auto; max-height: 280px; overflow-y: auto; }
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
            <li><a href="station_management.php"></i> Station Management</a></li>
            <li><a href="tracking_map.php" class="active"></i> Simulated Tracking</a></li>
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
                <div class="avatar">
                    <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 700; font-size: 14px;"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div style="font-size: 11px; color: var(--text-muted);"><?php echo getRoleLabel($_SESSION['role'] ?? ''); ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Map Area -->
    <main class="main-content">
        
        <!-- This is the div where the map will appear -->
        <div id="map"></div>

        <!-- Floating Techy Panel with Purple Theme -->
        <div class="info-panel">
            <h3><i class="fas fa-satellite-dish"></i> Active Fleet Tracking</h3>
            <div class="truck-list">
                <?php if (count($trucks) > 0): ?>
                    <?php foreach ($trucks as $t): ?>
                        <div class="truck-item" onclick="focusTruck(<?php echo $t['latitude']; ?>, <?php echo $t['longitude']; ?>)">
                            <div class="truck-info">
                                <strong><i  style="color: var(--primary); font-size: 0.8rem;"></i> <?php echo htmlspecialchars($t['plate_number']); ?></strong>
                                <small><?php echo htmlspecialchars($t['driver_name'] ?? 'Unassigned'); ?></small>
                            </div>
                            <div class="status-dot <?php echo ($t['v_status'] == 'active') ? 'status-active' : 'status-inactive'; ?>"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding:20px; text-align:center; color: var(--text-muted);">
                        <i  style="margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                        No trucks transmitting GPS data.
                    </div>
                <?php endif; ?>
            </div>
            <div class="live-badge">
                <i class="fas fa-circle fa-fw" style="color: var(--success); font-size: 0.6rem;"></i>
                <span>Live Data Stream | Auto-refresh every 10s</span>
            </div>
        </div>

    </main>

    <!-- LEAFLET JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <script>
        // 1. Initialize the map (Centered on Lagos)
        var map = L.map('map').setView([6.5244, 3.3792], 11);

        // 2. Add OpenStreetMap Tiles with custom attribution style
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors | PetroTrack Logistics'
        }).addTo(map);

        // 3. Pass PHP Data to JavaScript
        var trucks = <?php echo json_encode($trucks); ?>;

        // 4. Custom Truck Icon (Purple themed)
        var truckIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/1067/1067566.png',
            iconSize: [40, 40],
            iconAnchor: [20, 40],
            popupAnchor: [0, -40],
            className: 'custom-truck-icon'
        });

        // 5. Loop through trucks and add markers
        var markers = [];
        if (trucks.length > 0) {
            trucks.forEach(function(truck) {
                var marker = L.marker([truck.latitude, truck.longitude], {icon: truckIcon})
                    .addTo(map)
                    .bindPopup(`
                        <div style="font-family: 'Inter', sans-serif; min-width: 180px;">
                            <strong style="color: #4f0470; font-size: 14px;">🚛 ${truck.plate_number}</strong><br>
                            <span style="font-size: 12px;">👤 Driver: ${truck.driver_name || 'N/A'}</span><br>
                            <span style="font-size: 12px;">📊 Status: <span style="color: ${truck.v_status === 'active' ? '#10b981' : '#ef4444'}; font-weight: 600;">${truck.v_status.toUpperCase()}</span></span><br>
                            <span style="font-size: 11px; color: #64748b;">📍 ${truck.latitude.toFixed(6)}, ${truck.longitude.toFixed(6)}</span>
                        </div>
                    `, { className: 'custom-popup' });
                
                markers.push(marker);
                truck.marker = marker;
            });
        } else {
            L.popup()
                .setLatLng([6.5244, 3.3792])
                .setContent(`
                    <div style="font-family: 'Inter', sans-serif; text-align: center; padding: 10px;">
                        <i style="font-size: 24px; color: #64748b; margin-bottom: 8px; display: block;"></i>
                        <strong>No active trucks found</strong><br>
                        <small style="color: #64748b;">Add GPS coordinates to vehicles to see them on map.</small>
                    </div>
                `)
                .openOn(map);
        }

        // 6. Function to pan to a truck when clicked in the list
        function focusTruck(lat, lng) {
            map.flyTo([lat, lng], 15, {
                animate: true,
                duration: 1.2
            });
        }
        
        // 7. Auto-refresh the page every 10 seconds to show movement
        setTimeout(function(){
            window.location.reload(1);
        }, 10000);
        
        // 8. Add a custom control for refresh
        var refreshControl = L.control({position: 'bottomright'});
        refreshControl.onAdd = function(map) {
            var div = L.DomUtil.create('div', 'refresh-control');
            div.innerHTML = '<button onclick="window.location.reload()" style="background: #4f0470; color: white; border: none; border-radius: 8px; padding: 8px 12px; cursor: pointer; font-size: 12px; font-weight: 600; box-shadow: 0 2px 8px rgba(0,0,0,0.2);"><i class="fas fa-sync-alt"></i> Refresh</button>';
            return div;
        };
        refreshControl.addTo(map);
    </script>
    
    <style>
        /* Custom popup styling to match theme */
        .custom-popup .leaflet-popup-content-wrapper {
            border-radius: 12px;
            border-left: 4px solid #4f0470;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .custom-popup .leaflet-popup-tip {
            border-top-color: #4f0470;
        }
        .refresh-control {
            margin-bottom: 20px;
            margin-right: 20px;
        }
        .refresh-control button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 4, 112, 0.3);
        }
    </style>

</body>
</html>