<?php
// ADMIN SIDEBAR COMPONENT
// Reusable sidebar for all admin pages

// Fetch logged-in admin's actual name
$admin_name = 'Admin User'; // Default fallback
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user_sql = "SELECT name FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    if ($user_stmt) {
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_data = $user_result->fetch_assoc()) {
            $admin_name = $user_data['name'];
        }
        $user_stmt->close();
    }
}

// Determine current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
/* Sidebar Styles */
.sidebar {
    width: 260px;
    background: #ffffff;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    border-right: 1px solid #eee;
    display: flex;
    flex-direction: column;
    z-index: 100;
    box-shadow: 2px 0 10px rgba(0,0,0,0.05);
}

.sidebar-header {
    padding: 25px 20px;
    border-bottom: 1px solid #f0f0f0;
}

.brand-logo {
    font-size: 24px;
    font-weight: 700;
    color: #667eea;
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
}

.nav-links {
    list-style: none;
    padding: 20px 0;
    flex: 1;
    overflow-y: auto;
}

.nav-links li a {
    display: flex;
    align-items: center;
    padding: 12px 25px;
    color: #666;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s;
    gap: 12px;
}

.nav-links li a:hover,
.nav-links li a.active {
    color: #667eea;
    background: #f4f6fc;
    border-right: 4px solid #667eea;
}

.user-profile {
    padding: 20px;
    border-top: 1px solid #f0f0f0;
    background: #fafbfc;
}

.profile-info {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
}

.avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 18px;
}

.btn-logout {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px;
    background: #fee;
    color: #c33;
    border: 1px solid #fcc;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-logout:hover {
    background: #fcc;
    transform: translateY(-2px);
}
</style>

<!-- Sidebar HTML -->
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="admin_dashboard.php" class="brand-logo">
            <span>🛢️</span> PetroTrack
        </a>
    </div>
    
    <ul class="nav-links">
        <li><a href="admin_dashboard.php" class="<?php echo ($current_page == 'admin_dashboard.php') ? 'active' : ''; ?>">📊 Dashboard</a></li>
        <li><a href="vehicle_management.php" class="<?php echo ($current_page == 'vehicle_management.php') ? 'active' : ''; ?>">🚚 Vehicle Management</a></li>
        <li><a href="drivers_list.php" class="<?php echo ($current_page == 'drivers_list.php') ? 'active' : ''; ?>">👨‍✈️ Drivers</a></li>
        <li><a href="delivery_management.php" class="<?php echo ($current_page == 'delivery_management.php') ? 'active' : ''; ?>">📦 Delivery Orders</a></li>
        <li><a href="tracking_map.php" class="<?php echo ($current_page == 'tracking_map.php') ? 'active' : ''; ?>">📍 Live Tracking</a></li>
        <li><a href="reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>">📈 Reports</a></li>
    </ul>

    <div class="user-profile">
        <div class="profile-info">
            <div class="avatar">
                <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 14px; color: #333;">
                    <?php echo htmlspecialchars($admin_name); ?>
                </div>
                <div style="font-size: 12px; color: #888;">Logistics Manager</div>
            </div>
        </div>
        <a href="logout.php" class="btn-logout">
            <span>🚪</span> Logout
        </a>
    </div>
</aside>