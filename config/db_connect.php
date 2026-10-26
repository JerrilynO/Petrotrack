<?php
$servername = "localhost";
$username = "root"; 
$password = ""; 
$dbname = "petroleum_logistics_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Error: " . $conn->connect_error);
}

// Set charset to utf8mb4 to match your SQL schema
$conn->set_charset("utf8mb4");

// Role label helper function
function getRoleLabel($role) {
    $labels = [
        'admin'      => 'Logistics Manager',
        'driver'     => 'Tanker Driver',
        'supervisor' => 'Station Supervisor'
    ];
    return $labels[$role] ?? ucfirst($role);
}
?>