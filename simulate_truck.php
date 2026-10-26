<?php
session_start();
include 'config/db_connect.php';

$required_role = 'admin';
include 'auth_check.php';

// 1. Try to find a truck
$sql = "SELECT id, latitude, longitude FROM vehicles LIMIT 1";
$result = $conn->query($sql);

if ($row = $result->fetch_assoc()) {
    $id = $row['id'];
    
    // 2. If the truck has no coordinates yet, give it a starting point (Lagos)
    if (empty($row['latitude'])) {
        $new_lat = 6.5244;
        $new_lng = 3.3792;
    } else {
        // 3. If it does have coordinates, "nudge" them
        $new_lat = $row['latitude'] + (mt_rand(-20, 20) / 10000);
        $new_lng = $row['longitude'] + (mt_rand(-20, 20) / 10000);
    }

    $update_sql = "UPDATE vehicles SET latitude = ?, longitude = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ddi", $new_lat, $new_lng, $id);
    
    if ($stmt->execute()) {
        echo "<h3>Simulation Successful!</h3>";
        echo "Truck #$id is now at <strong>$new_lat, $new_lng</strong><br>";
        echo "<a href='tracking_map.php'>Go back to Map</a>";
    }
    $stmt->close();
} else {
    echo "Error: No trucks found in the 'vehicles' table. Please add a truck first!";
}
?>