<?php
session_start();
include 'config/db_connect.php';

$required_role = 'admin';
include 'auth_check.php';

// Fetch all drivers with their assigned vehicle info and current delivery status
$sql = "SELECT 
            users.id,
            users.name,
            users.email,
            users.phone,
            users.status as user_status,
            vehicles.plate_number,
            vehicles.id as vehicle_id,
            deliveries.id as delivery_id,
            deliveries.delivery_status,
            deliveries.destination
        FROM users
        LEFT JOIN vehicles ON users.id = vehicles.driver_id
        LEFT JOIN deliveries ON vehicles.id = deliveries.truck_id 
            AND deliveries.delivery_status IN ('pending', 'in_transit')
        WHERE users.role = 'driver'
        ORDER BY users.name ASC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drivers List - PetroTrack</title>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f9;
            color: #333;
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 30px;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #666;
            font-size: 14px;
        }

        .drivers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .driver-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .driver-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .driver-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .driver-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
        }

        .driver-name {
            font-size: 18px;
            font-weight: 700;
            color: #333;
        }

        .driver-email {
            font-size: 13px;
            color: #888;
        }

        .driver-info {
            display: grid;
            gap: 12px;
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        .info-label {
            color: #666;
            font-weight: 500;
        }

        .info-value {
            color: #333;
            font-weight: 600;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-available {
            background: #e3fcef;
            color: #006644;
        }

        .status-on-route {
            background: #fff4e5;
            color: #b36b00;
        }

        .status-inactive {
            background: #fee;
            color: #c33;
        }

        .truck-info {
            background: #f8f9fc;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            margin-top: 10px;
        }

        .no-truck {
            color: #999;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .drivers-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <?php include 'includes/admin_sidebar.php'; ?>

    <main class="main-content">
        
        <div class="page-header">
            <h1>👨‍✈️ Drivers Management</h1>
            <p>View all drivers and their current status</p>
        </div>

        <?php if ($result->num_rows > 0): ?>
            <div class="drivers-grid">
                <?php while($driver = $result->fetch_assoc()): ?>
                    <div class="driver-card">
                        
                        <div class="driver-header">
                            <div class="driver-avatar">
                                <?php echo strtoupper(substr($driver['name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="driver-name"><?php echo htmlspecialchars($driver['name']); ?></div>
                                <div class="driver-email"><?php echo htmlspecialchars($driver['email']); ?></div>
                            </div>
                        </div>

                        <div class="driver-info">
                            <div class="info-row">
                                <span class="info-label">📞 Phone:</span>
                                <span class="info-value"><?php echo htmlspecialchars($driver['phone'] ?? 'N/A'); ?></span>
                            </div>

                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span>
                                    <?php 
                                    if ($driver['delivery_status'] == 'in_transit' || $driver['delivery_status'] == 'pending') {
                                        echo '<span class="status-badge status-on-route">On Route</span>';
                                    } elseif ($driver['user_status'] == 'active') {
                                        echo '<span class="status-badge status-available">Available</span>';
                                    } else {
                                        echo '<span class="status-badge status-inactive">Inactive</span>';
                                    }
                                    ?>
                                </span>
                            </div>

                            <?php if ($driver['plate_number']): ?>
                                <div class="truck-info">
                                    <strong>🚛 Assigned Truck:</strong> <?php echo htmlspecialchars($driver['plate_number']); ?>
                                    <?php if ($driver['delivery_id']): ?>
                                        <br><strong>📦 Current Delivery:</strong> To <?php echo htmlspecialchars($driver['destination']); ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="truck-info no-truck">
                                    No truck currently assigned
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
                <h3 style="color: #999;">No drivers registered yet</h3>
                <p style="color: #aaa; margin-top: 10px;">Drivers can register via the registration page</p>
            </div>
        <?php endif; ?>

    </main>

</body>
</html>